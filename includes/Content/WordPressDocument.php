<?php

namespace Webactueel\ElementorJsonBridge\Content;

use RuntimeException;
use Webactueel\ElementorJsonBridge\Backup\Snapshots;
use Webactueel\ElementorJsonBridge\Elementor\Documents;
use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;

defined( 'ABSPATH' ) || exit;

final class WordPressDocument {
	public const FORMAT        = 'elementor-json-bridge/wordpress-content';
	public const CREATE_FORMAT = 'elementor-json-bridge/create-content';
	public const VERSION       = 1;

	private const MAX_BYTES = 5_000_000;

	private const BLOCKED_POST_TYPES = [
		'attachment',
		'revision',
		'nav_menu_item',
		Snapshots::POST_TYPE,
		'user_request',
	];

	private const YOAST_FIELDS = [
		'focuskw',
		'title',
		'metadesc',
		'is_cornerstone',
		'meta-robots-noindex',
		'meta-robots-nofollow',
		'meta-robots-adv',
		'bctitle',
		'canonical',
		'redirect',
		'schema_page_type',
		'schema_article_type',
		'opengraph-title',
		'opengraph-description',
		'opengraph-image',
		'opengraph-image-id',
		'twitter-title',
		'twitter-description',
		'twitter-image',
		'twitter-image-id',
	];

	public function __construct(
		private readonly Documents $elementor,
		private readonly PayloadValidator $elementor_validator
	) {}

	public function post_types(): array {
		$allowed = [];
		foreach ( get_post_types( [ 'show_ui' => true ], 'objects' ) as $name => $object ) {
			$name = (string) $name;
			if ( in_array( $name, self::BLOCKED_POST_TYPES, true ) || ! is_object( $object ) || ! isset( $object->cap ) || empty( $object->cap->edit_posts ) ) {
				continue;
			}
			$allowed[] = $name;
		}
		sort( $allowed, SORT_STRING );
		return $allowed;
	}

	public function supports( int $post_id ): bool {
		$post = get_post( $post_id );
		return $post instanceof \WP_Post
			&& ! in_array( $post->post_type, self::BLOCKED_POST_TYPES, true )
			&& ! in_array( $post->post_status, [ 'auto-draft', 'trash' ], true )
			&& in_array( $post->post_type, $this->post_types(), true );
	}

	public function payload( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! $this->supports( $post_id ) ) {
			throw new RuntimeException( 'This WordPress content item is not managed by the bridge.' );
		}

		$payload = [
			'format'          => self::FORMAT,
			'version'         => self::VERSION,
			'source'          => [
				'id'        => (int) $post->ID,
				'post_type' => (string) $post->post_type,
			],
			'post'            => [
				'title'          => (string) $post->post_title,
				'slug'           => (string) $post->post_name,
				'status'         => (string) $post->post_status,
				'content'        => (string) $post->post_content,
				'excerpt'        => (string) $post->post_excerpt,
				'parent'         => (int) $post->post_parent,
				'menu_order'     => (int) $post->menu_order,
				'comment_status' => (string) $post->comment_status,
				'ping_status'    => (string) $post->ping_status,
				'page_template'  => (string) get_page_template_slug( $post_id ),
				'featured_image' => (int) get_post_thumbnail_id( $post_id ),
			],
			'taxonomies'      => $this->taxonomies( $post_id, $post->post_type ),
			'acf'             => $this->acf( $post_id ),
			'yoast'           => $this->yoast( $post_id ),
			'registered_meta' => $this->registered_meta( $post_id, $post->post_type ),
			'elementor'       => null,
		];

		if ( $this->elementor->is_elementor_document( $post_id ) ) {
			$payload['elementor']          = $this->elementor_validator->validate_array( $this->elementor->payload( $post_id ), $this->elementor->document_type( $post_id ) );
			$payload['elementor']['title'] = (string) $post->post_title;
		}

		return $this->validate_array( $payload, $post_id );
	}

	public function decode( string $json, int $post_id ): array {
		if ( self::MAX_BYTES < strlen( $json ) ) {
			throw new RuntimeException( 'The WordPress content JSON is larger than 5 MB.' );
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			throw new RuntimeException( 'The GitHub file is not a valid WordPress content object.' );
		}
		return $this->validate_array( $data, $post_id );
	}

	public function validate_array( array $payload, int $post_id ): array {
		$allowed_root = [ 'format', 'version', 'source', 'post', 'taxonomies', 'acf', 'yoast', 'registered_meta', 'elementor' ];
		if ( array_diff( array_keys( $payload ), $allowed_root ) ) {
			throw new RuntimeException( 'The WordPress content JSON contains unsupported root fields.' );
		}
		if ( self::FORMAT !== ( $payload['format'] ?? null ) || self::VERSION !== (int) ( $payload['version'] ?? 0 ) ) {
			throw new RuntimeException( 'The WordPress content JSON format or version is unsupported.' );
		}
		if ( ! is_array( $payload['source'] ?? null ) || ! is_array( $payload['post'] ?? null ) ) {
			throw new RuntimeException( 'The WordPress content JSON is missing source or post data.' );
		}

		$target = get_post( $post_id );
		if ( ! $target instanceof \WP_Post || ! $this->supports( $post_id ) ) {
			throw new RuntimeException( 'The target WordPress content item is not managed by the bridge.' );
		}
		if ( (int) ( $payload['source']['id'] ?? 0 ) !== $post_id || (string) ( $payload['source']['post_type'] ?? '' ) !== $target->post_type ) {
			throw new RuntimeException( 'The GitHub file identity does not match the target WordPress item.' );
		}

		$post = $payload['post'];
		foreach ( [ 'title', 'slug', 'status', 'content', 'excerpt', 'comment_status', 'ping_status', 'page_template' ] as $key ) {
			if ( ! array_key_exists( $key, $post ) || ! is_string( $post[ $key ] ) ) {
				throw new RuntimeException( 'The WordPress content JSON contains an invalid post field.' );
			}
		}
		foreach ( [ 'parent', 'menu_order', 'featured_image' ] as $key ) {
			if ( ! array_key_exists( $key, $post ) || ! is_int( $post[ $key ] ) ) {
				throw new RuntimeException( 'The WordPress content JSON contains an invalid numeric post field.' );
			}
		}

		$statuses = get_post_stati( [], 'names' );
		if ( ! in_array( $post['status'], $statuses, true ) || in_array( $post['status'], [ 'auto-draft', 'trash' ], true ) ) {
			throw new RuntimeException( 'The requested WordPress post status is not allowed.' );
		}
		if ( ! in_array( $post['comment_status'], [ 'open', 'closed' ], true ) || ! in_array( $post['ping_status'], [ 'open', 'closed' ], true ) ) {
			throw new RuntimeException( 'The requested comment or ping status is invalid.' );
		}
		if ( 0 < $post['parent'] ) {
			$parent = get_post( $post['parent'] );
			if ( ! $parent instanceof \WP_Post || $target->post_type !== $parent->post_type || $target->ID === $parent->ID ) {
				throw new RuntimeException( 'The requested parent is not valid for this WordPress content item.' );
			}
		}
		if ( 0 < $post['featured_image'] ) {
			$attachment = get_post( $post['featured_image'] );
			if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
				throw new RuntimeException( 'The requested featured image does not exist.' );
			}
		}

		foreach ( [ 'taxonomies', 'acf', 'yoast', 'registered_meta' ] as $section ) {
			if ( ! isset( $payload[ $section ] ) || ! is_array( $payload[ $section ] ) || array_is_list( $payload[ $section ] ) ) {
				throw new RuntimeException( 'The WordPress content JSON contains an invalid metadata section.' );
			}
		}

		$this->validate_taxonomies( $payload['taxonomies'], $target->post_type );
		$this->validate_acf( $payload['acf'], $post_id );
		$this->validate_yoast( $payload['yoast'] );
		$this->validate_registered_meta( $payload['registered_meta'], $target->post_type );

		if ( null !== ( $payload['elementor'] ?? null ) ) {
			if ( ! is_array( $payload['elementor'] ) || ! $this->elementor->is_elementor_document( $post_id ) ) {
				throw new RuntimeException( 'Elementor data can only be applied to an existing Elementor document.' );
			}
			$payload['elementor']          = $this->elementor_validator->validate_array( $payload['elementor'], $this->elementor->document_type( $post_id ) );
			$payload['elementor']['title'] = $post['title'];
		}

		$json = wp_json_encode( $payload );
		if ( ! is_string( $json ) || self::MAX_BYTES < strlen( $json ) ) {
			throw new RuntimeException( 'The WordPress content JSON is larger than 5 MB.' );
		}
		return $payload;
	}

	public function apply( int $post_id, array $payload ): void {
		$payload = $this->validate_array( $payload, $post_id );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new RuntimeException( 'You are not allowed to edit this WordPress content item.' );
		}

		$current = get_post( $post_id );
		if ( ! $current instanceof \WP_Post ) {
			throw new RuntimeException( 'The WordPress content item no longer exists.' );
		}
		$post_type_object = get_post_type_object( $current->post_type );
		$publish_cap      = $post_type_object?->cap->publish_posts ?? 'publish_posts';
		if ( in_array( $payload['post']['status'], [ 'publish', 'future' ], true ) && ! current_user_can( $publish_cap ) ) {
			throw new RuntimeException( 'You are not allowed to publish this WordPress content item.' );
		}

		$post   = $payload['post'];
		$result = wp_update_post(
			wp_slash(
				[
					'ID'             => $post_id,
					'post_title'     => $post['title'],
					'post_name'      => $post['slug'],
					'post_status'    => $post['status'],
					'post_content'   => $post['content'],
					'post_excerpt'   => $post['excerpt'],
					'post_parent'    => $post['parent'],
					'menu_order'     => $post['menu_order'],
					'comment_status' => $post['comment_status'],
					'ping_status'    => $post['ping_status'],
				]
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( 'WordPress rejected the content update.' );
		}

		if ( '' === $post['page_template'] ) {
			delete_post_meta( $post_id, '_wp_page_template' );
		} else {
			update_post_meta( $post_id, '_wp_page_template', $post['page_template'] );
		}
		if ( 0 < $post['featured_image'] ) {
			set_post_thumbnail( $post_id, $post['featured_image'] );
		} else {
			delete_post_thumbnail( $post_id );
		}

		$this->apply_taxonomies( $post_id, $payload['taxonomies'], $current->post_type );
		$this->apply_registered_meta( $post_id, $payload['registered_meta'], $current->post_type );
		$this->apply_acf( $post_id, $payload['acf'] );
		$this->apply_yoast( $post_id, $payload['yoast'] );

		if ( is_array( $payload['elementor'] ) ) {
			$this->elementor->save_payload( $post_id, $payload['elementor'] );
		}
		clean_post_cache( $post_id );
	}

	public function create_draft( array $request ): int {
		if ( self::CREATE_FORMAT !== ( $request['format'] ?? null ) || self::VERSION !== (int) ( $request['version'] ?? 0 ) ) {
			throw new RuntimeException( 'The create-content request format is invalid.' );
		}

		$post_type = sanitize_key( (string) ( $request['post_type'] ?? '' ) );
		$object    = get_post_type_object( $post_type );
		if ( ! in_array( $post_type, $this->post_types(), true ) || ! $object ) {
			throw new RuntimeException( 'The requested WordPress post type is not managed by the bridge.' );
		}
		$create_cap = $object->cap->create_posts ?? $object->cap->edit_posts ?? 'edit_posts';
		if ( ! current_user_can( $create_cap ) ) {
			throw new RuntimeException( 'You are not allowed to create this WordPress content type.' );
		}

		$post  = is_array( $request['post'] ?? null ) ? $request['post'] : [];
		$title = isset( $post['title'] ) && is_string( $post['title'] ) ? $post['title'] : '';
		if ( '' === trim( $title ) ) {
			throw new RuntimeException( 'A new WordPress content item requires a title.' );
		}

		$id = wp_insert_post(
			wp_slash(
				[
					'post_type'    => $post_type,
					'post_status'  => 'draft',
					'post_title'   => $title,
					'post_name'    => isset( $post['slug'] ) && is_string( $post['slug'] ) ? $post['slug'] : '',
					'post_content' => isset( $post['content'] ) && is_string( $post['content'] ) ? $post['content'] : '',
					'post_excerpt' => isset( $post['excerpt'] ) && is_string( $post['excerpt'] ) ? $post['excerpt'] : '',
				]
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( 'WordPress could not create the requested draft.' );
		}
		$id = (int) $id;

		try {
			$payload = $this->payload( $id );
			foreach ( [ 'taxonomies', 'acf', 'yoast', 'registered_meta' ] as $section ) {
				if ( isset( $request[ $section ] ) && is_array( $request[ $section ] ) ) {
					$payload[ $section ] = $request[ $section ];
				}
			}
			$payload['post']['title']   = $title;
			$payload['post']['slug']    = isset( $post['slug'] ) && is_string( $post['slug'] ) ? $post['slug'] : $payload['post']['slug'];
			$payload['post']['content'] = isset( $post['content'] ) && is_string( $post['content'] ) ? $post['content'] : '';
			$payload['post']['excerpt'] = isset( $post['excerpt'] ) && is_string( $post['excerpt'] ) ? $post['excerpt'] : '';
			$payload['post']['status']  = 'draft';
			$this->apply( $id, $payload );
		} catch ( \Throwable $throwable ) {
			wp_delete_post( $id, true );
			throw $throwable;
		}
		return $id;
	}

	public function index_descriptor( int $post_id, string $path ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! $this->supports( $post_id ) ) {
			throw new RuntimeException( 'The WordPress content item cannot be indexed.' );
		}
		return [
			'id'        => (int) $post->ID,
			'post_type' => (string) $post->post_type,
			'title'     => (string) $post->post_title,
			'slug'      => (string) $post->post_name,
			'status'    => (string) $post->post_status,
			'path'      => $path,
			'elementor' => $this->elementor->is_elementor_document( $post_id ),
			'acf'       => function_exists( 'get_field_objects' ),
			'yoast'     => class_exists( '\\WPSEO_Meta' ),
		];
	}

	private function taxonomies( int $post_id, string $post_type ): array {
		$result = [];
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy => $object ) {
			if ( ! is_object( $object ) || empty( $object->show_ui ) ) {
				continue;
			}
			$terms = wp_get_object_terms( $post_id, (string) $taxonomy, [ 'fields' => 'slugs' ] );
			if ( is_wp_error( $terms ) ) {
				throw new RuntimeException( 'WordPress could not read the content taxonomies.' );
			}
			$terms = array_values( array_map( 'strval', $terms ) );
			sort( $terms, SORT_STRING );
			$result[ (string) $taxonomy ] = $terms;
		}
		ksort( $result, SORT_STRING );
		return $result;
	}

	private function validate_taxonomies( array $taxonomies, string $post_type ): void {
		$objects = get_object_taxonomies( $post_type, 'objects' );
		foreach ( $taxonomies as $taxonomy => $slugs ) {
			$object = $objects[ $taxonomy ] ?? null;
			if ( ! is_object( $object ) || empty( $object->show_ui ) || ! is_array( $slugs ) || ! array_is_list( $slugs ) ) {
				throw new RuntimeException( 'The WordPress content JSON contains an invalid taxonomy.' );
			}
			foreach ( $slugs as $slug ) {
				if ( ! is_string( $slug ) || '' === $slug || ! get_term_by( 'slug', $slug, (string) $taxonomy ) instanceof \WP_Term ) {
					throw new RuntimeException( 'A requested taxonomy term does not exist on this site.' );
				}
			}
		}
	}

	private function apply_taxonomies( int $post_id, array $taxonomies, string $post_type ): void {
		$objects = get_object_taxonomies( $post_type, 'objects' );
		foreach ( $taxonomies as $taxonomy => $slugs ) {
			$object = $objects[ $taxonomy ] ?? null;
			if ( ! is_object( $object ) || ! current_user_can( $object->cap->assign_terms ) ) {
				throw new RuntimeException( 'You are not allowed to assign one of the requested taxonomies.' );
			}
			$ids = [];
			foreach ( $slugs as $slug ) {
				$term  = get_term_by( 'slug', $slug, (string) $taxonomy );
				$ids[] = (int) $term->term_id;
			}
			if ( is_wp_error( wp_set_object_terms( $post_id, $ids, (string) $taxonomy, false ) ) ) {
				throw new RuntimeException( 'WordPress rejected a taxonomy update.' );
			}
		}
	}

	private function acf( int $post_id ): array {
		if ( ! function_exists( 'get_field_objects' ) ) {
			return [];
		}
		$objects = get_field_objects( $post_id, false, true, false );
		if ( ! is_array( $objects ) ) {
			return [];
		}
		$result = [];
		foreach ( $objects as $name => $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) || empty( $field['name'] ) ) {
				continue;
			}
			$result[ (string) $name ] = [
				'key'   => (string) $field['key'],
				'type'  => (string) ( $field['type'] ?? '' ),
				'value' => $field['value'] ?? null,
			];
		}
		ksort( $result, SORT_STRING );
		return $result;
	}

	private function validate_acf( array $acf, int $post_id ): void {
		if ( [] === $acf ) {
			return;
		}
		if ( ! function_exists( 'get_field_objects' ) || ! function_exists( 'update_field' ) ) {
			throw new RuntimeException( 'ACF content is present but Advanced Custom Fields is not active.' );
		}
		$current = $this->acf( $post_id );
		foreach ( $acf as $name => $field ) {
			$keys = is_array( $field ) ? array_keys( $field ) : [];
			sort( $keys, SORT_STRING );
			if ( ! isset( $current[ $name ] ) || [ 'key', 'type', 'value' ] !== $keys ) {
				throw new RuntimeException( 'The ACF field set no longer matches this WordPress item.' );
			}
			if ( $field['key'] !== $current[ $name ]['key'] || $field['type'] !== $current[ $name ]['type'] ) {
				throw new RuntimeException( 'An ACF field identity changed after export.' );
			}
		}
	}

	private function apply_acf( int $post_id, array $acf ): void {
		foreach ( $acf as $field ) {
			update_field( (string) $field['key'], $field['value'], $post_id );
		}
	}

	private function yoast( int $post_id ): array {
		if ( ! class_exists( '\\WPSEO_Meta' ) ) {
			return [];
		}
		$result = [];
		foreach ( self::YOAST_FIELDS as $field ) {
			$key              = '_yoast_wpseo_' . $field;
			$result[ $field ] = metadata_exists( 'post', $post_id, $key ) ? get_post_meta( $post_id, $key, true ) : null;
		}
		return $result;
	}

	private function validate_yoast( array $yoast ): void {
		if ( [] === $yoast ) {
			return;
		}
		if ( ! class_exists( '\\WPSEO_Meta' ) ) {
			throw new RuntimeException( 'Yoast SEO content is present but Yoast SEO is not active.' );
		}
		if ( array_diff( array_keys( $yoast ), self::YOAST_FIELDS ) ) {
			throw new RuntimeException( 'The WordPress content JSON contains an unsupported Yoast field.' );
		}
	}

	private function apply_yoast( int $post_id, array $yoast ): void {
		foreach ( $yoast as $field => $value ) {
			if ( null === $value ) {
				delete_post_meta( $post_id, '_yoast_wpseo_' . $field );
				continue;
			}
			\WPSEO_Meta::set_value( (string) $field, $value, $post_id );
		}
	}

	private function registered_meta( int $post_id, string $post_type ): array {
		if ( ! function_exists( 'get_registered_meta_keys' ) ) {
			return [];
		}
		$result = [];
		foreach ( get_registered_meta_keys( 'post', $post_type ) as $key => $args ) {
			$key = (string) $key;
			if ( str_starts_with( $key, '_' ) || empty( $args['show_in_rest'] ) ) {
				continue;
			}
			$result[ $key ] = ! empty( $args['single'] ) ? get_post_meta( $post_id, $key, true ) : get_post_meta( $post_id, $key, false );
		}
		ksort( $result, SORT_STRING );
		return $result;
	}

	private function validate_registered_meta( array $meta, string $post_type ): void {
		$definitions = function_exists( 'get_registered_meta_keys' ) ? get_registered_meta_keys( 'post', $post_type ) : [];
		foreach ( array_keys( $meta ) as $key ) {
			$args = $definitions[ $key ] ?? null;
			if ( ! is_array( $args ) || str_starts_with( (string) $key, '_' ) || empty( $args['show_in_rest'] ) ) {
				throw new RuntimeException( 'The WordPress content JSON contains unsupported registered metadata.' );
			}
		}
	}

	private function apply_registered_meta( int $post_id, array $meta, string $post_type ): void {
		$definitions = function_exists( 'get_registered_meta_keys' ) ? get_registered_meta_keys( 'post', $post_type ) : [];
		foreach ( $meta as $key => $value ) {
			if ( ! current_user_can( 'edit_post_meta', $post_id, (string) $key ) ) {
				throw new RuntimeException( 'You are not allowed to edit one of the requested registered metadata fields.' );
			}
			$args = $definitions[ $key ] ?? [];
			if ( ! empty( $args['single'] ) ) {
				update_post_meta( $post_id, (string) $key, $value );
				continue;
			}
			delete_post_meta( $post_id, (string) $key );
			foreach ( is_array( $value ) ? $value : [] as $item ) {
				add_post_meta( $post_id, (string) $key, $item, false );
			}
		}
	}
}
