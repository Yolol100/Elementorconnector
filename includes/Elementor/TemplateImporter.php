<?php

namespace Webactueel\ElementorJsonBridge\Elementor;

use JsonException;
use RuntimeException;
use Throwable;
use Webactueel\ElementorJsonBridge\Backup\Snapshots;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

defined( 'ABSPATH' ) || exit;

final class TemplateImporter {
	private const TARGET_POST_TYPES = [ 'page', 'post', 'elementor_library' ];
	private const PAGE_SOURCE_TYPES = [ 'page', 'wp-page', 'wp-post' ];
	private const CORE_FIELDS       = [ 'title', 'type', 'version', 'page_settings', 'content' ];
	private const NATIVE_EXTRA_FIELDS = [ 'global_classes', 'global_variables' ];

	public function __construct(
		private readonly Documents $documents,
		private readonly PayloadValidator $validator,
		private readonly Snapshots $snapshots
	) {}

	public function analyze( string $json, string $filename ): array {
		$parsed     = $this->parse( $json, $filename );
		$recognized = $this->recognize_target( $parsed );

		return [
			'source'            => [
				'title'    => (string) $parsed['payload']['title'],
				'type'     => (string) $parsed['payload']['type'],
				'format'   => (string) $parsed['format'],
				'filename' => (string) $parsed['filename'],
			],
			'recognized_target' => $recognized['target'],
			'recognition'       => [
				'confidence' => $recognized['confidence'],
				'reason'     => $recognized['reason'],
			],
			'available_actions' => [
				'replace'      => true,
				'new_page'     => self::is_page_source_type( (string) $parsed['payload']['type'] ),
				'new_post'     => self::is_page_source_type( (string) $parsed['payload']['type'] ),
				'new_template' => true,
			],
			'warnings'          => $parsed['warnings'],
		];
	}

	public function search_targets( string $search, string $source_type ): array {
		$search = trim( $search );
		$posts  = [];

		if ( ctype_digit( $search ) && (int) $search > 0 ) {
			$post = get_post( (int) $search );
			if ( $post instanceof \WP_Post ) {
				$posts[] = $post;
			}
		}

		$query = new \WP_Query(
			[
				'post_type'              => self::TARGET_POST_TYPES,
				'post_status'            => [ 'publish', 'draft', 'private', 'pending', 'future' ],
				'posts_per_page'         => 20,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				's'                      => $search,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$posts[] = $post;
			}
		}

		$targets = [];
		$seen    = [];
		foreach ( $posts as $post ) {
			if ( isset( $seen[ $post->ID ] ) ) {
				continue;
			}
			$seen[ $post->ID ] = true;
			$target            = $this->target_descriptor( $post, $source_type );
			if ( null !== $target && true === $target['compatible'] ) {
				$targets[] = $target;
			}
		}

		return array_slice( $targets, 0, 20 );
	}

	public function execute( string $json, string $filename, string $action, int $target_id = 0 ): array {
		$parsed = $this->parse( $json, $filename );

		return match ( $action ) {
			'replace'      => $this->replace( $parsed['payload'], $target_id ),
			'new_page'     => $this->create_document( $parsed['payload'], 'page' ),
			'new_post'     => $this->create_document( $parsed['payload'], 'post' ),
			'new_template' => $this->create_template( $parsed ),
			default        => throw new RuntimeException( 'Choose a valid Elementor JSON import action.' ),
		};
	}

	public static function is_page_source_type( string $type ): bool {
		return in_array( $type, self::PAGE_SOURCE_TYPES, true );
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

		$warnings   = [];
		$source_meta = [];
		$format     = 'elementor-document';
		$raw        = $data;

		if ( 'elementor-json-bridge/site-parts-bundle' === ( $data['format'] ?? null ) ) {
			if ( ! isset( $data['document'] ) || ! is_array( $data['document'] ) || array_is_list( $data['document'] ) ) {
				throw new RuntimeException( 'The Elementor JSON bundle does not contain a valid source document.' );
			}
			$payload     = $this->validator->validate_array( $data['document'] );
			$source_meta = is_array( $data['source'] ?? null ) ? $data['source'] : [];
			$format      = 'elementor-json-bridge/site-parts-bundle';
			$warnings[]  = 'This bundle also contains site parts. Smart import uses the main document only; header and footer entries are not overwritten by this action.';
			$raw         = $data['document'];
		} else {
			$allowed = array_merge( self::CORE_FIELDS, self::NATIVE_EXTRA_FIELDS );
			$unknown = array_diff( array_keys( $data ), $allowed );
			if ( $unknown ) {
				throw new RuntimeException( 'This JSON contains unsupported top-level fields: ' . implode( ', ', $unknown ) . '.' );
			}

			$core = array_intersect_key( $data, array_fill_keys( self::CORE_FIELDS, true ) );
			$payload = $this->validator->validate_array( $core );

			foreach ( self::NATIVE_EXTRA_FIELDS as $field ) {
				if ( array_key_exists( $field, $data ) && ! is_array( $data[ $field ] ) ) {
					throw new RuntimeException( 'The Elementor global style data is malformed.' );
				}
			}
			if ( isset( $data['global_classes'] ) || isset( $data['global_variables'] ) ) {
				$warnings[] = 'This file contains Elementor global classes or variables. Smart Page/Post replacement preserves their references but does not import missing global definitions; use standard Elementor import when moving this JSON between different sites.';
			}
		}

		return [
			'payload'     => $payload,
			'raw'         => $raw,
			'filename'    => sanitize_file_name( basename( $filename ) ),
			'format'      => $format,
			'source_meta' => $source_meta,
			'warnings'    => $warnings,
		];
	}

	private function recognize_target( array $parsed ): array {
		$title       = (string) $parsed['payload']['title'];
		$source_type = (string) $parsed['payload']['type'];
		$source_meta = $parsed['source_meta'];
		$filename    = (string) $parsed['filename'];

		$source_id   = absint( $source_meta['post_id'] ?? 0 );
		$source_kind = sanitize_key( (string) ( $source_meta['post_type'] ?? '' ) );
		$source_title = (string) ( $source_meta['title'] ?? '' );
		if ( $source_id > 0 && in_array( $source_kind, [ 'page', 'post' ], true ) && $source_title === $title ) {
			$post = get_post( $source_id );
			if ( $post instanceof \WP_Post && $post->post_type === $source_kind && (string) $post->post_title === $title ) {
				$target = $this->target_descriptor( $post, $source_type );
				if ( null !== $target && true === $target['compatible'] ) {
					return [ 'target' => $target, 'confidence' => 'high', 'reason' => 'bridge_source' ];
				}
			}
		}

		if ( preg_match( '/^elementor-(\d+)-\d{4}-\d{2}-\d{2}\.json$/i', $filename, $match ) ) {
			$post = get_post( (int) $match[1] );
			if ( $post instanceof \WP_Post && 'elementor_library' === $post->post_type && (string) $post->post_title === $title ) {
				$target = $this->target_descriptor( $post, $source_type );
				if ( null !== $target && true === $target['compatible'] ) {
					return [ 'target' => $target, 'confidence' => 'high', 'reason' => 'native_template_id' ];
				}
			}
		}

		$slug = preg_replace( '/-elementor(?:-with-site-parts)?\.json$/i', '', $filename );
		if ( is_string( $slug ) && $slug !== $filename && '' !== $slug ) {
			$matches = get_posts(
				[
					'post_type'      => [ 'page', 'post' ],
					'post_status'    => [ 'publish', 'draft', 'private', 'pending', 'future' ],
					'name'           => sanitize_title( $slug ),
					'posts_per_page' => 3,
					'no_found_rows'  => true,
				]
			);
			$matches = array_values(
				array_filter(
					$matches,
					static fn ( $post ): bool => $post instanceof \WP_Post && (string) $post->post_title === $title
				)
			);
			if ( 1 === count( $matches ) ) {
				$target = $this->target_descriptor( $matches[0], $source_type );
				if ( null !== $target && true === $target['compatible'] ) {
					return [ 'target' => $target, 'confidence' => 'high', 'reason' => 'bridge_slug' ];
				}
			}
		}

		$title_matches = get_posts(
			[
				'post_type'      => self::TARGET_POST_TYPES,
				'post_status'    => [ 'publish', 'draft', 'private', 'pending', 'future' ],
				's'              => $title,
				'posts_per_page' => 25,
				'no_found_rows'  => true,
			]
		);
		$compatible = [];
		foreach ( $title_matches as $post ) {
			if ( ! $post instanceof \WP_Post || (string) $post->post_title !== $title ) {
				continue;
			}
			$target = $this->target_descriptor( $post, $source_type );
			if ( null !== $target && true === $target['compatible'] ) {
				$compatible[] = $target;
			}
		}
		if ( 1 === count( $compatible ) ) {
			return [ 'target' => $compatible[0], 'confidence' => 'medium', 'reason' => 'unique_exact_title' ];
		}

		return [ 'target' => null, 'confidence' => 'none', 'reason' => 'no_unique_match' ];
	}

	private function target_descriptor( \WP_Post $post, string $source_type ): ?array {
		if ( ! in_array( $post->post_type, self::TARGET_POST_TYPES, true ) || ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return null;
		}
		if ( in_array( $post->post_type, [ 'page', 'post' ], true ) && 'builder' !== (string) get_post_meta( (int) $post->ID, '_elementor_edit_mode', true ) ) {
			return null;
		}
		if ( ! $this->documents->is_elementor_document( (int) $post->ID ) ) {
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
			'kind'          => $this->kind_label( (string) $post->post_type ),
			'title'         => (string) $post->post_title,
			'document_type' => $document_type,
			'compatible'    => $this->is_compatible( $source_type, (string) $post->post_type, $document_type ),
			'edit_url'      => (string) get_edit_post_link( (int) $post->ID, 'raw' ),
		];
	}

	private function is_compatible( string $source_type, string $target_post_type, string $target_document_type ): bool {
		if ( in_array( $target_post_type, [ 'page', 'post' ], true ) ) {
			return self::is_page_source_type( $source_type );
		}
		if ( 'elementor_library' !== $target_post_type ) {
			return false;
		}
		if ( $source_type === $target_document_type ) {
			return true;
		}
		return self::is_page_source_type( $source_type ) && 'page' === $target_document_type;
	}

	private function replace( array $payload, int $target_id ): array {
		$post = get_post( $target_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new RuntimeException( 'Choose an existing Page, Post, or Elementor Template to replace.' );
		}
		$target = $this->target_descriptor( $post, (string) $payload['type'] );
		if ( null === $target ) {
			throw new RuntimeException( 'You are not allowed to replace this Elementor document.' );
		}
		if ( true !== $target['compatible'] ) {
			throw new RuntimeException( 'The selected JSON type is not compatible with this Elementor document.' );
		}

		$target_type = (string) $target['document_type'];
		$current     = $this->validator->validate_array( $this->documents->payload( $target_id ), $target_type );
		$incoming    = $this->adapt_payload( $payload, $target_type, (string) $post->post_title );
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
			'post_type'   => (string) $post->post_type,
			'title'       => (string) $post->post_title,
			'snapshot_id' => $snapshot_id,
			'edit_url'    => (string) get_edit_post_link( $target_id, 'raw' ),
		];
	}

	private function create_document( array $payload, string $post_type ): array {
		if ( ! self::is_page_source_type( (string) $payload['type'] ) || ! in_array( $post_type, [ 'page', 'post' ], true ) ) {
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

	private function create_template( array $parsed ): array {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			throw new RuntimeException( 'Elementor is not active.' );
		}
		if ( class_exists( '\\Elementor\\User' ) && method_exists( '\\Elementor\\User', 'is_current_user_can_upload_json' ) && ! \Elementor\User::is_current_user_can_upload_json() ) {
			throw new RuntimeException( 'You are not allowed to import Elementor JSON templates.' );
		}

		$source = \Elementor\Plugin::$instance->templates_manager->get_source( 'local' );
		if ( ! is_object( $source ) || ! method_exists( $source, 'import_template' ) ) {
			throw new RuntimeException( 'The Elementor local template importer is unavailable.' );
		}

		$native_data = $parsed['raw'];
		if ( 'elementor-json-bridge/site-parts-bundle' === $parsed['format'] ) {
			$native_data = $parsed['payload'];
		}
		if ( self::is_page_source_type( (string) $parsed['payload']['type'] ) ) {
			$native_data['type'] = 'page';
		}

		try {
			$encoded = wp_json_encode( $native_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new RuntimeException( 'The Elementor JSON could not be prepared for native template import.' );
		}
		$tmp_path = wp_tempnam( 'ejb-template-import.json' );
		if ( ! is_string( $tmp_path ) || '' === $tmp_path || false === file_put_contents( $tmp_path, $encoded ) ) {
			throw new RuntimeException( 'A temporary Elementor import file could not be created.' );
		}

		try {
			$items = $source->import_template( 'ejb-template-import.json', $tmp_path );
		} finally {
			wp_delete_file( $tmp_path );
		}
		if ( is_wp_error( $items ) ) {
			throw new RuntimeException( 'Elementor could not import this JSON as a new template: ' . $items->get_error_message() );
		}
		if ( ! is_array( $items ) || 1 !== count( $items ) || ! isset( $items[0]['template_id'] ) ) {
			if ( is_array( $items ) ) {
				foreach ( $items as $item ) {
					if ( isset( $item['template_id'] ) ) {
						wp_delete_post( (int) $item['template_id'], true );
					}
				}
			}
			throw new RuntimeException( 'Elementor did not return exactly one imported template.' );
		}

		$template_id = (int) $items[0]['template_id'];
		$post        = get_post( $template_id );
		if ( ! $post instanceof \WP_Post || 'elementor_library' !== $post->post_type ) {
			throw new RuntimeException( 'Elementor returned an invalid imported template.' );
		}

		return [
			'action'    => 'created',
			'id'        => $template_id,
			'post_type' => 'elementor_library',
			'title'     => (string) $post->post_title,
			'status'    => (string) $post->post_status,
			'edit_url'  => (string) get_edit_post_link( $template_id, 'raw' ),
		];
	}

	private function adapt_payload( array $payload, string $target_type, string $target_title ): array {
		$adapted          = $payload;
		$adapted['type']  = $target_type;
		$adapted['title'] = $target_title;
		return $this->validator->validate_array( $adapted, $target_type );
	}

	private function kind_label( string $post_type ): string {
		return match ( $post_type ) {
			'page'              => 'Page',
			'post'              => 'Post',
			'elementor_library' => 'Template',
			default             => 'Document',
		};
	}
}
