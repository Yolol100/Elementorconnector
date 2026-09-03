<?php

namespace Webactueel\ElementorJsonBridge\Sync;

use Throwable;
use Webactueel\ElementorJsonBridge\Content\AbilityBridge;
use Webactueel\ElementorJsonBridge\Content\PostRequest;
use Webactueel\ElementorJsonBridge\Content\ProductRequest;
use Webactueel\ElementorJsonBridge\Content\ProductVariation;
use Webactueel\ElementorJsonBridge\Content\TaxonomyTerm;
use Webactueel\ElementorJsonBridge\Content\WordPressDocument;
use Webactueel\ElementorJsonBridge\GitHub\Client;
use Webactueel\ElementorJsonBridge\Lifecycle\Hooks;
use Webactueel\ElementorJsonBridge\Settings;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

defined( 'ABSPATH' ) || exit;

final class ContentRequests {
	private const PROCESSED_OPTION    = 'ejb_processed_content_requests';
	private const PROCESS_LOCK_OPTION = 'ejb_content_requests_lock';
	private const PROCESS_LOCK_TTL    = 600;
	private const MAX_PER_RUN         = 5;
	private const RETENTION           = 200;
	private const MAX_REQUEST_BYTES   = 1000000;
	private const TERMINAL_STATUSES   = [ 'created', 'updated', 'deleted', 'executed', 'error' ];

	public function __construct(
		private readonly WordPressDocument $content,
		private readonly PostRequest $posts,
		private readonly ProductRequest $products,
		private readonly TaxonomyTerm $terms,
		private readonly ProductVariation $variations,
		private readonly AbilityBridge $abilities,
		private readonly Client $github,
		private readonly Manager $manager
	) {}

	public function register(): void {
		add_action( 'ejb_poll_remote', [ $this, 'process' ], 5 );
	}

	public function process(): void {
		if ( 1 !== (int) Settings::get( 'auto_apply', 0 ) ) {
			return;
		}
		if ( ! Settings::repo_is_configured() || ! get_option( Settings::AUTH_OPTION, '' ) ) {
			return;
		}
		$actor_id = (int) Settings::get( 'auto_apply_actor', 0 );
		if ( $actor_id < 1 || ! user_can( $actor_id, Hooks::CAPABILITY ) ) {
			return;
		}
		$lock_token = $this->acquire_process_lock();
		if ( '' === $lock_token ) {
			return;
		}
		try {
			$root      = (string) Settings::get( 'repo_root', 'site-data' );
			$directory = trim( $root . '/requests', '/' );
			try {
				$this->github->assert_private_repository();
				$this->ensure_manifest( $root );
				$entries = $this->github->list_directory( $directory );
			} catch ( Throwable ) {
				return;
			}

			$processed_count = 0;
			foreach ( $entries as $entry ) {
				if ( $processed_count >= self::MAX_PER_RUN ) {
					break;
				}
				if ( 'file' !== ( $entry['type'] ?? '' ) ) {
					continue;
				}
				$name = (string) ( $entry['name'] ?? '' );
				if ( ! str_ends_with( strtolower( $name ), '.json' ) ) {
					continue;
				}
				$path = (string) ( $entry['path'] ?? trim( $directory . '/' . $name, '/' ) );
				$this->process_file( $path, $actor_id );
				++$processed_count;
			}
		} finally {
			$this->release_process_lock( $lock_token );
		}
	}

	private function ensure_manifest( string $root ): void {
		$path = trim( $root . '/bridge.json', '/' );
		$manifest = [
			'format'                => 'elementor-json-bridge/repository-manifest',
			'version'               => 5,
			'site_index'            => trim( $root . '/site-index.json', '/' ),
			'ability_catalog'       => trim( $root . '/abilities.json', '/' ),
			'content_path_pattern'  => trim( $root . '/content/{kind}/{id}.json', '/' ),
			'request_path_pattern'  => trim( $root . '/requests/{request-id}.json', '/' ),
			'new_content_status'    => 'draft',
			'editable_sections'     => [ 'post', 'taxonomies', 'acf', 'yoast', 'registered_meta', 'elementor' ],
			'request_only_sections' => [ 'woocommerce' ],
			'request_formats'       => [
				WordPressDocument::CREATE_FORMAT => WordPressDocument::VERSION,
				PostRequest::FORMAT               => PostRequest::VERSION,
				ProductRequest::FORMAT            => ProductRequest::VERSION,
				TaxonomyTerm::FORMAT              => TaxonomyTerm::VERSION,
				ProductVariation::FORMAT          => ProductVariation::VERSION,
				AbilityBridge::FORMAT             => AbilityBridge::VERSION,
			],
			'rules'                 => [
				'Edit the common WordPress/ACF/Yoast/Elementor envelope only through the exact path listed in site-index.json.',
				'Use manage-product requests for WooCommerce catalog fields so product writes use WooCommerce CRUD and exact readback.',
				'Do not change source.id or source.post_type in an existing content file.',
				'Use a globally unique request_id for each request. Reusing an ID with different input is rejected.',
				'Use manage-post version 2. For update/delete, copy base_hash from the exact canonical content JSON you read; stale hashes fail closed. Version-1 manage-post requests must be regenerated.',
				'New pages, posts and products are always created as drafts; publishing requires an explicit later update with publish capability.',
				'When creating Elementor content, use manage-post with an elementor document payload so the item is created through Elementor document management.',
				'Create, update or delete categories, tags and product categories through manage-term requests using exact term IDs for update/delete.',
				'Create, update or delete variable-product variations through manage-product-variation requests using exact product and variation IDs.',
				'Product delete moves the product to trash by default. Permanent deletion additionally requires force=true.',
				'Only abilities listed in abilities.json can be executed through run-ability requests; supported namespaces are core/*, acf/*, yoast-seo/* and WooCommerce product abilities.',
				'Destructive term, product, variation or ability operations require confirm_destructive=true.',
				'Only one request-processing poll may execute at a time; stale process locks expire after ten minutes.',
			],
		];
		$encoded = CanonicalJson::encode( $manifest, true );
		$remote  = $this->github->get_file( $path );
		if ( $remote && hash_equals( hash( 'sha256', $encoded ), hash( 'sha256', (string) $remote['content'] ) ) ) {
			return;
		}
		$this->github->put_file( $path, $encoded, $remote ? (string) $remote['sha'] : null, 'Refresh WordPress bridge manifest' );
	}

	private function process_file( string $path, int $actor_id ): void {
		$file    = null;
		$request = [];
		try {
			$file = $this->github->get_file( $path );
			if ( ! $file ) {
				return;
			}
			if ( self::MAX_REQUEST_BYTES < strlen( (string) $file['content'] ) ) {
				throw new \RuntimeException( 'The WordPress bridge request is larger than 1 MB.' );
			}
			$request = json_decode( (string) $file['content'], true );
			if ( ! is_array( $request ) || array_is_list( $request ) || ! $this->supported_format( (string) ( $request['format'] ?? '' ) ) ) {
				return;
			}
			$request_id = sanitize_key( (string) ( $request['request_id'] ?? '' ) );
			if ( '' === $request_id ) {
				$this->write_result( $path, $file, $request, [ 'status' => 'error', 'message' => 'A request_id is required.' ] );
				return;
			}
			if ( is_array( $request['result'] ?? null ) && in_array( (string) ( $request['result']['status'] ?? '' ), self::TERMINAL_STATUSES, true ) ) {
				return;
			}

			$fingerprint_request = $request;
			unset( $fingerprint_request['result'] );
			$fingerprint = hash( 'sha256', CanonicalJson::encode( $fingerprint_request, false ) );
			$processed   = get_option( self::PROCESSED_OPTION, [] );
			$processed   = is_array( $processed ) ? $processed : [];
			if ( isset( $processed[ $request_id ] ) ) {
				$entry = $processed[ $request_id ];
				if ( is_int( $entry ) && WordPressDocument::CREATE_FORMAT === ( $request['format'] ?? null ) ) {
					$post_id = $entry;
					$this->write_result( $path, $file, $request, $this->created_result( $post_id ) );
					return;
				}
				if ( is_array( $entry ) && hash_equals( (string) ( $entry['fingerprint'] ?? '' ), $fingerprint ) && is_array( $entry['result'] ?? null ) ) {
					$this->write_result( $path, $file, $request, $entry['result'] );
					return;
				}
				$this->write_result( $path, $file, $request, [ 'status' => 'error', 'message' => 'This request_id was already used for different input.' ] );
				return;
			}

			$previous_user = get_current_user_id();
			wp_set_current_user( $actor_id );
			try {
				$result = $this->execute_request( $request );
			} finally {
				wp_set_current_user( $previous_user );
			}

			$processed[ $request_id ] = [ 'fingerprint' => $fingerprint, 'result' => $result, 'processed_at' => time() ];
			if ( count( $processed ) > self::RETENTION ) {
				$processed = array_slice( $processed, -self::RETENTION, null, true );
			}
			update_option( self::PROCESSED_OPTION, $processed, false );
			$this->write_result( $path, $file, $request, $result );
		} catch ( Throwable $throwable ) {
			try {
				$file = is_array( $file ) ? $file : $this->github->get_file( $path );
				if ( $file && $request ) {
					$this->write_result( $path, $file, $request, [ 'status' => 'error', 'message' => substr( sanitize_text_field( $throwable->getMessage() ), 0, 300 ) ] );
				}
			} catch ( Throwable ) {
				return;
			}
		}
	}

	private function acquire_process_lock(): string {
		$token = wp_generate_uuid4();
		$value = wp_json_encode( [ 'token' => $token, 'created_at' => time() ] );
		if ( ! is_string( $value ) ) {
			return '';
		}
		if ( add_option( self::PROCESS_LOCK_OPTION, $value, '', false ) ) {
			return $token;
		}
		$existing = get_option( self::PROCESS_LOCK_OPTION, '' );
		$data     = is_string( $existing ) ? json_decode( $existing, true ) : null;
		if ( is_array( $data ) && time() - (int) ( $data['created_at'] ?? time() ) > self::PROCESS_LOCK_TTL ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-swap is required so a contender cannot delete a freshly acquired lock.
			$updated = $wpdb->update(
				$wpdb->options,
				[ 'option_value' => $value ],
				[ 'option_name' => self::PROCESS_LOCK_OPTION, 'option_value' => $existing ],
				[ '%s' ],
				[ '%s', '%s' ]
			);
			if ( 1 === $updated ) {
				wp_cache_delete( self::PROCESS_LOCK_OPTION, 'options' );
				return $token;
			}
		}
		return '';
	}

	private function release_process_lock( string $token ): void {
		$existing = get_option( self::PROCESS_LOCK_OPTION, '' );
		$data     = is_string( $existing ) ? json_decode( $existing, true ) : null;
		if ( is_array( $data ) && hash_equals( (string) ( $data['token'] ?? '' ), $token ) ) {
			delete_option( self::PROCESS_LOCK_OPTION );
		}
	}

	private function execute_request( array $request ): array {
		return match ( (string) $request['format'] ) {
			WordPressDocument::CREATE_FORMAT => $this->created_result( $this->content->create_draft( $request ) ),
			PostRequest::FORMAT               => $this->posts->execute( $request ),
			ProductRequest::FORMAT            => $this->products->execute( $request ),
			TaxonomyTerm::FORMAT              => $this->terms->execute( $request ),
			ProductVariation::FORMAT          => $this->variations->execute( $request ),
			AbilityBridge::FORMAT             => $this->abilities->execute( $request ),
			default                           => throw new \RuntimeException( 'Unsupported WordPress bridge request format.' ),
		};
	}

	private function supported_format( string $format ): bool {
		return in_array( $format, [ WordPressDocument::CREATE_FORMAT, PostRequest::FORMAT, ProductRequest::FORMAT, TaxonomyTerm::FORMAT, ProductVariation::FORMAT, AbilityBridge::FORMAT ], true );
	}

	private function created_result( int $post_id ): array {
		return [
			'status'  => 'created',
			'post_id' => $post_id,
			'path'    => $post_id > 0 && $this->manager->is_enabled( $post_id ) ? $this->manager->path_for( $post_id ) : '',
		];
	}

	private function write_result( string $path, array $file, array $request, array $result ): void {
		$request['result'] = $result;
		$this->github->put_file( $path, CanonicalJson::encode( $request, true ), (string) $file['sha'], 'Process WordPress content request' );
	}
}
