<?php

namespace Webactueel\ElementorJsonBridge\Elementor;

use JsonException;
use RuntimeException;
use Throwable;
use Webactueel\ElementorJsonBridge\Backup\Snapshots;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;
use Webactueel\ElementorJsonBridge\Sync\Lock;

defined( 'ABSPATH' ) || exit;

final class TemplateImporter {
	private const DESTINATIONS        = [ 'page', 'post' ];
	private const PAGE_SOURCE_TYPES   = [ 'page', 'wp-page', 'wp-post' ];
	private const CORE_FIELDS         = [ 'title', 'type', 'version', 'page_settings', 'content' ];
	private const NATIVE_EXTRA_FIELDS = [ 'global_classes', 'global_variables' ];

	public function __construct(
		private readonly Documents $documents,
		private readonly PayloadValidator $validator,
		private readonly Snapshots $snapshots,
		private readonly Lock $lock
	) {}

	public function analyze( string $json, string $filename, string $destination ): array {
		$this->assert_destination( $destination );
		$parsed = $this->parse( $json, $filename );
		if ( ! self::is_page_source_type( (string) $parsed['payload']['type'] ) ) {
			throw new RuntimeException( 'Only Page-style Elementor JSON can be imported from the Pages or Posts overview. Use Elementor Templates for other template types.' );
		}

		$recognized = $this->recognize_target( $parsed, $destination );

		return [
			'source'            => [
				'title'    => (string) $parsed['payload']['title'],
				'type'     => (string) $parsed['payload']['type'],
				'format'   => (string) $parsed['format'],
				'filename' => (string) $parsed['filename'],
			],
			'destination'       => $destination,
			'recognized_target' => $recognized['target'],
			'recognition'       => [
				'confidence' => $recognized['confidence'],
				'reason'     => $recognized['reason'],
			],
			'warnings'          => $parsed['warnings'],
		];
	}

	public function execute(
		string $json,
		string $filename,
		string $destination,
		bool $replace_existing = false,
		int $expected_target_id = 0
	): array {
		$this->assert_destination( $destination );
		$parsed = $this->parse( $json, $filename );
		if ( ! self::is_page_source_type( (string) $parsed['payload']['type'] ) ) {
			throw new RuntimeException( 'Only Page-style Elementor JSON can be imported from the Pages or Posts overview.' );
		}

		if ( ! $replace_existing ) {
			return $this->create_document( $parsed['payload'], $destination );
		}

		$recognized = $this->recognize_target( $parsed, $destination );
		$target     = $recognized['target'];
		if ( ! is_array( $target ) || (int) ( $target['id'] ?? 0 ) < 1 ) {
			throw new RuntimeException( 'No unique existing Elementor item can be safely replaced. Import it as a new item instead.' );
		}
		if ( $expected_target_id < 1 || (int) $target['id'] !== $expected_target_id ) {
			throw new RuntimeException( 'The detected existing item changed after analysis. Check the JSON again before replacing anything.' );
		}

		return $this->replace( $parsed['payload'], $destination, $expected_target_id );
	}

	public static function is_page_source_type( string $type ): bool {
		return in_array( $type, self::PAGE_SOURCE_TYPES, true );
	}

	private function assert_destination( string $destination ): void {
		if ( ! in_array( $destination, self::DESTINATIONS, true ) ) {
			throw new RuntimeException( 'Choose the Pages or Posts overview as the import destination.' );
		}
	}

	private function parse( string $json, string $filename ): array {
		if ( '' === trim( $json ) ) {
			throw new RuntimeException( 'Choose a non-empty Elementor JSON file.' );
		}
		if ( strlen( $json ) > PayloadValidator::MAX_BYTES ) {
			throw new RuntimeException( 'The Elementor JSON file is larger than 5 MB.' );
		}

		try {
			$data = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new RuntimeException( 'The selected file is not valid JSON.' );
		}
		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			throw new RuntimeException( 'The Elementor JSON root must be an object.' );
		}

		$warnings    = [];
		$source_meta = [];
		$format      = 'elementor-document';

		if ( 'elementor-json-bridge/site-parts-bundle' === ( $data['format'] ?? null ) ) {
			if ( ! isset( $data['document'] ) || ! is_array( $data['document'] ) || array_is_list( $data['document'] ) ) {
				throw new RuntimeException( 'The Elementor JSON bundle does not contain a valid source document.' );
			}
			$payload     = $this->validator->validate_array( $data['document'] );
			$source_meta = is_array( $data['source'] ?? null ) ? $data['source'] : [];
			$format      = 'elementor-json-bridge/site-parts-bundle';
			$warnings[]  = 'This bundle also contains site parts. Page/Post import uses only the main document; header and footer entries are not changed.';
		} else {
			$allowed = array_merge( self::CORE_FIELDS, self::NATIVE_EXTRA_FIELDS );
			$unknown = array_diff( array_keys( $data ), $allowed );
			if ( $unknown ) {
				throw new RuntimeException( 'This JSON contains unsupported top-level fields: ' . implode( ', ', $unknown ) . '.' );
			}

			$core    = array_intersect_key( $data, array_fill_keys( self::CORE_FIELDS, true ) );
			$payload = $this->validator->validate_array( $core );

			foreach ( self::NATIVE_EXTRA_FIELDS as $field ) {
				if ( array_key_exists( $field, $data ) && ! is_array( $data[ $field ] ) ) {
					throw new RuntimeException( 'The Elementor global style data is malformed.' );
				}
			}
			if ( isset( $data['global_classes'] ) || isset( $data['global_variables'] ) ) {
				$warnings[] = 'This file contains Elementor global classes or variables. Page/Post import preserves document references but does not import missing global definitions; use Elementor\'s existing Templates import when moving this JSON between different sites.';
			}
		}

		return [
			'payload'     => $payload,
			'filename'    => sanitize_file_name( basename( $filename ) ),
			'format'      => $format,
			'source_meta' => $source_meta,
			'warnings'    => $warnings,
		];
	}

	private function recognize_target( array $parsed, string $destination ): array {
		$title       = (string) $parsed['payload']['title'];
		$source_type = (string) $parsed['payload']['type'];
		$source_meta = $parsed['source_meta'];
		$filename    = (string) $parsed['filename'];

		$source_id    = absint( $source_meta['post_id'] ?? 0 );
		$source_kind  = sanitize_key( (string) ( $source_meta['post_type'] ?? '' ) );
		$source_title = (string) ( $source_meta['title'] ?? '' );
		if ( $source_id > 0 && $source_kind === $destination && $source_title === $title ) {
			$post = get_post( $source_id );
			if ( $post instanceof \WP_Post && $post->post_type === $destination && (string) $post->post_title === $title ) {
				$target = $this->target_descriptor( $post, $source_type, $destination );
				if ( null !== $target ) {
					return [ 'target' => $target, 'confidence' => 'high', 'reason' => 'bridge_source' ];
				}
			}
		}

		$slug = preg_replace( '/-elementor(?:-with-site-parts)?\.json$/i', '', $filename );
		if ( is_string( $slug ) && $slug !== $filename && '' !== $slug ) {
			$matches = get_posts(
				[
					'post_type'      => $destination,
					'post_status'    => [ 'publish', 'draft', 'private', 'pending', 'future' ],
					'name'           => sanitize_title( $slug ),
					'posts_per_page' => 2,
					'no_found_rows'  => true,
				]
			);
			if ( 1 === count( $matches ) && $matches[0] instanceof \WP_Post && (string) $matches[0]->post_title === $title ) {
				$target = $this->target_descriptor( $matches[0], $source_type, $destination );
				if ( null !== $target ) {
					return [ 'target' => $target, 'confidence' => 'high', 'reason' => 'bridge_slug' ];
				}
			}
		}

		$title_matches = get_posts(
			[
				'post_type'      => $destination,
				'post_status'    => [ 'publish', 'draft', 'private', 'pending', 'future' ],
				'title'          => $title,
				'posts_per_page' => 2,
				'no_found_rows'  => true,
			]
		);
		if ( 1 === count( $title_matches ) && $title_matches[0] instanceof \WP_Post && (string) $title_matches[0]->post_title === $title ) {
			$target = $this->target_descriptor( $title_matches[0], $source_type, $destination );
			if ( null !== $target ) {
				return [ 'target' => $target, 'confidence' => 'medium', 'reason' => 'unique_exact_title' ];
			}
		}

		return [ 'target' => null, 'confidence' => 'none', 'reason' => 'no_unique_match' ];
	}

	private function target_descriptor( \WP_Post $post, string $source_type, string $destination ): ?array {
		if ( $post->post_type !== $destination || ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return null;
		}
		if ( ! self::is_page_source_type( $source_type ) ) {
			return null;
		}
		if ( 'builder' !== (string) get_post_meta( (int) $post->ID, '_elementor_edit_mode', true ) || ! $this->documents->is_elementor_document( (int) $post->ID ) ) {
			return null;
		}

		try {
			$document_type = $this->documents->document_type( (int) $post->ID );
		} catch ( RuntimeException ) {
			return null;
		}

		return [
			'id'            => (int) $post->ID,
			'post_type'     => (string) $post->post_type,
			'kind'          => 'page' === $post->post_type ? 'Page' : 'Post',
			'title'         => (string) $post->post_title,
			'document_type' => $document_type,
			'edit_url'      => (string) get_edit_post_link( (int) $post->ID, 'raw' ),
		];
	}

	private function replace( array $payload, string $destination, int $target_id ): array {
		$post = get_post( $target_id );
		if ( ! $post instanceof \WP_Post || $post->post_type !== $destination ) {
			throw new RuntimeException( 'The detected existing Page or Post no longer exists.' );
		}

		$token = $this->lock->acquire( $target_id );
		try {
			$post   = get_post( $target_id );
			$target = $post instanceof \WP_Post ? $this->target_descriptor( $post, (string) $payload['type'], $destination ) : null;
			if ( null === $target ) {
				throw new RuntimeException( 'The detected existing Elementor item is no longer safe to replace.' );
			}

			$target_type   = (string) $target['document_type'];
			$current       = $this->validator->validate_array( $this->documents->payload( $target_id ), $target_type );
			$incoming      = $this->adapt_payload( $payload, $target_type, (string) $post->post_title );
			$current_hash  = CanonicalJson::hash( $current );
			$incoming_hash = CanonicalJson::hash( $incoming );
			$snapshot_id   = $this->snapshots->create( $target_id, $current, 'before_json_import' );

			try {
				$this->documents->save_payload( $target_id, $incoming );
				$readback = $this->validator->validate_array( $this->documents->payload( $target_id ), $target_type );
				if ( ! hash_equals( $incoming_hash, CanonicalJson::hash( $readback ) ) ) {
					throw new RuntimeException( 'Elementor changed the imported JSON during save; readback verification failed.' );
				}
			} catch ( Throwable $apply_error ) {
				try {
					$rollback = $this->snapshots->payload( $snapshot_id, $target_id );
					$this->documents->save_payload( $target_id, $rollback );
					$restored = $this->validator->validate_array( $this->documents->payload( $target_id ), $target_type );
					if ( ! hash_equals( $current_hash, CanonicalJson::hash( $restored ) ) ) {
						throw new RuntimeException( 'Rollback verification failed.' );
					}
				} catch ( Throwable $rollback_error ) {
					throw new RuntimeException( 'The import failed and the previous Elementor document could not be verified after rollback.', 0, $rollback_error );
				}
				throw new RuntimeException( 'The import failed. The previous Elementor document was restored.', 0, $apply_error );
			}

			return [
				'action'      => 'replaced',
				'id'          => $target_id,
				'post_type'   => $destination,
				'title'       => (string) $post->post_title,
				'snapshot_id' => $snapshot_id,
				'edit_url'    => (string) get_edit_post_link( $target_id, 'raw' ),
			];
		} finally {
			$this->lock->release( $target_id, $token );
		}
	}

	private function create_document( array $payload, string $post_type ): array {
		if ( ! self::is_page_source_type( (string) $payload['type'] ) || ! in_array( $post_type, self::DESTINATIONS, true ) ) {
			throw new RuntimeException( 'Only Page-style Elementor JSON can create a new Page or Post.' );
		}

		$post_type_object = get_post_type_object( $post_type );
		$create_cap       = $post_type_object?->cap->create_posts ?? $post_type_object?->cap->edit_posts ?? 'edit_posts';
		if ( ! current_user_can( $create_cap ) ) {
			throw new RuntimeException( 'You are not allowed to create this WordPress content type.' );
		}

		$id = wp_insert_post(
			[
				'post_type'    => $post_type,
				'post_status'  => 'draft',
				'post_title'   => (string) $payload['title'],
				'post_content' => '',
			],
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( 'WordPress could not create the new draft document.' );
		}
		$id = (int) $id;
		update_post_meta( $id, '_elementor_edit_mode', 'builder' );

		try {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				throw new RuntimeException( 'You are not allowed to edit the new draft document.' );
			}
			$target_type = $this->documents->document_type( $id );
			$incoming    = $this->adapt_payload( $payload, $target_type, (string) $payload['title'] );
			$hash        = CanonicalJson::hash( $incoming );
			$this->documents->save_payload( $id, $incoming );
			$readback = $this->validator->validate_array( $this->documents->payload( $id ), $target_type );
			if ( ! hash_equals( $hash, CanonicalJson::hash( $readback ) ) ) {
				throw new RuntimeException( 'Elementor changed the imported JSON during save; readback verification failed.' );
			}
		} catch ( Throwable $throwable ) {
			wp_delete_post( $id, true );
			throw $throwable;
		}

		return [
			'action'    => 'created',
			'id'        => $id,
			'post_type' => $post_type,
			'title'     => (string) $payload['title'],
			'status'    => 'draft',
			'edit_url'  => (string) get_edit_post_link( $id, 'raw' ),
		];
	}

	private function adapt_payload( array $payload, string $target_type, string $target_title ): array {
		$adapted          = $payload;
		$adapted['type']  = $target_type;
		$adapted['title'] = $target_title;
		return $this->validator->validate_array( $adapted, $target_type );
	}
}
