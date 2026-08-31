<?php

namespace Webactueel\ElementorJsonBridge\Sync;

use Throwable;
use Webactueel\ElementorJsonBridge\Content\WordPressDocument;
use Webactueel\ElementorJsonBridge\GitHub\Client;
use Webactueel\ElementorJsonBridge\Lifecycle\Hooks;
use Webactueel\ElementorJsonBridge\Settings;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

defined( 'ABSPATH' ) || exit;

final class ContentRequests {
	private const PROCESSED_OPTION = 'ejb_processed_content_requests';
	private const MAX_PER_RUN      = 5;
	private const RETENTION        = 200;

	public function __construct(
		private readonly WordPressDocument $content,
		private readonly Client $github,
		private readonly Manager $manager
	) {}

	public function register(): void {
		add_action( 'ejb_poll_remote', [ $this, 'process' ], 5 );
	}

	public function process(): void {
		if ( ! Settings::repo_is_configured() || ! get_option( Settings::AUTH_OPTION, '' ) ) {
			return;
		}
		$actor_id = (int) Settings::get( 'auto_apply_actor', 0 );
		if ( $actor_id < 1 || ! user_can( $actor_id, Hooks::CAPABILITY ) ) {
			return;
		}

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
	}

	private function ensure_manifest( string $root ): void {
		$path = trim( $root . '/bridge.json', '/' );
		$manifest = [
			'format'                      => 'elementor-json-bridge/repository-manifest',
			'version'                     => 1,
			'site_index'                  => trim( $root . '/site-index.json', '/' ),
			'content_path_pattern'        => trim( $root . '/content/{kind}/{id}.json', '/' ),
			'create_request_path_pattern' => trim( $root . '/requests/{request-id}.json', '/' ),
			'create_request_format'       => WordPressDocument::CREATE_FORMAT,
			'create_request_version'      => WordPressDocument::VERSION,
			'new_content_status'          => 'draft',
			'editable_sections'           => [ 'post', 'taxonomies', 'acf', 'yoast', 'registered_meta', 'elementor' ],
			'rules'                       => [
				'Edit existing items only through the path listed in site-index.json.',
				'Do not change source.id or source.post_type in an existing content file.',
				'Use a unique request_id to create new content. The site writes the result back into the request file.',
				'New content is always created as a draft.',
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
		try {
			$file = $this->github->get_file( $path );
			if ( ! $file ) {
				return;
			}
			$request = json_decode( (string) $file['content'], true );
			if ( ! is_array( $request ) || array_is_list( $request ) || WordPressDocument::CREATE_FORMAT !== ( $request['format'] ?? null ) ) {
				return;
			}
			$request_id = sanitize_key( (string) ( $request['request_id'] ?? '' ) );
			if ( '' === $request_id ) {
				$this->write_result( $path, $file, $request, [ 'status' => 'error', 'message' => 'A request_id is required.' ] );
				return;
			}

			$processed = get_option( self::PROCESSED_OPTION, [] );
			$processed = is_array( $processed ) ? $processed : [];
			if ( isset( $processed[ $request_id ] ) ) {
				$post_id = (int) $processed[ $request_id ];
				$this->write_result(
					$path,
					$file,
					$request,
					[
						'status'  => 'created',
						'post_id' => $post_id,
						'path'    => $post_id > 0 && $this->manager->is_enabled( $post_id ) ? $this->manager->path_for( $post_id ) : '',
					]
				);
				return;
			}
			if ( is_array( $request['result'] ?? null ) && in_array( (string) ( $request['result']['status'] ?? '' ), [ 'created', 'error' ], true ) ) {
				return;
			}

			$previous_user = get_current_user_id();
			wp_set_current_user( $actor_id );
			try {
				$post_id = $this->content->create_draft( $request );
			} finally {
				wp_set_current_user( $previous_user );
			}

			$processed[ $request_id ] = $post_id;
			if ( count( $processed ) > self::RETENTION ) {
				$processed = array_slice( $processed, -self::RETENTION, null, true );
			}
			update_option( self::PROCESSED_OPTION, $processed, false );

			$this->write_result(
				$path,
				$file,
				$request,
				[
					'status'  => 'created',
					'post_id' => $post_id,
					'path'    => $this->manager->path_for( $post_id ),
				]
			);
		} catch ( Throwable $throwable ) {
			try {
				$file    = isset( $file ) && is_array( $file ) ? $file : $this->github->get_file( $path );
				$request = isset( $request ) && is_array( $request ) ? $request : [];
				if ( $file && $request ) {
					$this->write_result(
						$path,
						$file,
						$request,
						[
							'status'  => 'error',
							'message' => substr( sanitize_text_field( $throwable->getMessage() ), 0, 300 ),
						]
					);
				}
			} catch ( Throwable ) {
				return;
			}
		}
	}

	private function write_result( string $path, array $file, array $request, array $result ): void {
		$request['result'] = $result;
		$this->github->put_file(
			$path,
			CanonicalJson::encode( $request, true ),
			(string) $file['sha'],
			'Process WordPress content request'
		);
	}
}
