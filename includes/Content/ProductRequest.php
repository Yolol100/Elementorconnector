<?php

namespace Webactueel\ElementorJsonBridge\Content;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class ProductRequest {
	public const FORMAT = 'elementor-json-bridge/manage-product';
	public const VERSION = 1;

	public function __construct(
		private readonly WooCommerceProduct $products,
		private readonly WordPressDocument $content
	) {}

	public function execute( array $request ): array {
		$this->validate_request( $request );
		$action = (string) $request['action'];
		$core   = is_array( $request['post'] ?? null ) ? $request['post'] : [];
		$woo    = is_array( $request['woocommerce'] ?? null ) ? $request['woocommerce'] : [];

		if ( 'create' === $action ) {
			if ( ! current_user_can( 'edit_products' ) ) {
				throw new RuntimeException( 'You are not allowed to create WooCommerce products.' );
			}
			$title = isset( $core['title'] ) && is_string( $core['title'] ) ? trim( $core['title'] ) : '';
			if ( '' === $title ) {
				throw new RuntimeException( 'Creating a WooCommerce product requires post.title.' );
			}
			$type = isset( $woo['type'] ) && is_string( $woo['type'] ) ? $woo['type'] : 'simple';
			$id = $this->products->create( $type, $title );
			try {
				$this->apply_core( $id, $core, true );
				$current = $this->products->payload( $id );
				$merged = array_replace( $current, $woo );
				$merged['type'] = $type;
				$merged['variation_ids'] = $current['variation_ids'];
				$this->products->apply( $id, $merged );
				$this->apply_content_extensions( $id, $request );
				$this->apply_taxonomies( $id, $request['taxonomies'] ?? [] );
			} catch ( \Throwable $throwable ) {
				$product = wc_get_product( $id );
				if ( $product instanceof \WC_Product ) { $product->delete( true ); }
				throw $throwable;
			}
			return $this->result( 'created', $id );
		}

		$id = (int) ( $request['product_id'] ?? 0 );
		if ( ! $this->products->supports( $id ) ) {
			throw new RuntimeException( 'The requested WooCommerce product does not exist.' );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			throw new RuntimeException( 'You are not allowed to edit this WooCommerce product.' );
		}
		if ( 'delete' === $action ) {
			if ( true !== ( $request['confirm_destructive'] ?? false ) ) {
				throw new RuntimeException( 'Deleting a WooCommerce product requires confirm_destructive=true.' );
			}
			$product = wc_get_product( $id );
			$product->delete( true );
			return [ 'status' => 'deleted', 'product_id' => $id ];
		}

		$this->apply_core( $id, $core, false );
		if ( $woo ) {
			$current = $this->products->payload( $id );
			$merged = array_replace( $current, $woo );
			$merged['variation_ids'] = $current['variation_ids'];
			$this->products->apply( $id, $merged );
		}
		$this->apply_content_extensions( $id, $request );
		if ( array_key_exists( 'taxonomies', $request ) ) {
			$this->apply_taxonomies( $id, $request['taxonomies'] );
		}
		return $this->result( 'updated', $id );
	}

	private function result( string $status, int $id ): array {
		$post = get_post( $id );
		return [
			'status' => $status,
			'product_id' => $id,
			'post' => [
				'title' => $post instanceof \WP_Post ? (string) $post->post_title : '',
				'status' => $post instanceof \WP_Post ? (string) $post->post_status : '',
				'content' => $post instanceof \WP_Post ? (string) $post->post_content : '',
				'excerpt' => $post instanceof \WP_Post ? (string) $post->post_excerpt : '',
				'featured_image' => (int) get_post_thumbnail_id( $id ),
			],
			'woocommerce' => $this->products->payload( $id ),
		];
	}

	private function validate_request( array $request ): void {
		$allowed = [ 'format', 'version', 'request_id', 'action', 'product_id', 'post', 'woocommerce', 'taxonomies', 'acf', 'yoast', 'registered_meta', 'elementor', 'confirm_destructive', 'result' ];
		if ( array_diff( array_keys( $request ), $allowed ) ) {
			throw new RuntimeException( 'The product request contains unsupported fields.' );
		}
		if ( self::FORMAT !== ( $request['format'] ?? null ) || self::VERSION !== (int) ( $request['version'] ?? 0 ) ) {
			throw new RuntimeException( 'The product request format or version is invalid.' );
		}
		if ( ! in_array( (string) ( $request['action'] ?? '' ), [ 'create', 'update', 'delete' ], true ) ) {
			throw new RuntimeException( 'The product request action is invalid.' );
		}
		if ( 'create' !== (string) $request['action'] && (int) ( $request['product_id'] ?? 0 ) < 1 ) {
			throw new RuntimeException( 'Updating or deleting a product requires an exact product_id.' );
		}
		foreach ( [ 'post', 'woocommerce', 'taxonomies', 'acf', 'yoast', 'registered_meta' ] as $field ) {
			if ( isset( $request[ $field ] ) && ( ! is_array( $request[ $field ] ) || ( [] !== $request[ $field ] && array_is_list( $request[ $field ] ) ) ) ) {
				throw new RuntimeException( 'Product request objects must use named fields.' );
			}
		}
	}

	private function apply_core( int $id, array $data, bool $creating ): void {
		$allowed = [ 'title', 'slug', 'status', 'content', 'excerpt', 'featured_image', 'menu_order', 'comment_status' ];
		if ( array_diff( array_keys( $data ), $allowed ) ) {
			throw new RuntimeException( 'The product request contains unsupported core post fields.' );
		}
		$update = [ 'ID' => $id ];
		if ( array_key_exists( 'status', $data ) ) {
			if ( ! is_string( $data['status'] ) || ! in_array( $data['status'], get_post_stati( [], 'names' ), true ) || in_array( $data['status'], [ 'auto-draft', 'trash' ], true ) ) {
				throw new RuntimeException( 'The requested WooCommerce product status is invalid.' );
			}
			if ( ! $creating && in_array( $data['status'], [ 'publish', 'future' ], true ) ) {
				$object = get_post_type_object( 'product' );
				if ( ! current_user_can( $object?->cap->publish_posts ?? 'publish_products' ) ) {
					throw new RuntimeException( 'You are not allowed to publish WooCommerce products.' );
				}
			}
		}
		$map = [ 'title' => 'post_title', 'slug' => 'post_name', 'status' => 'post_status', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'menu_order' => 'menu_order', 'comment_status' => 'comment_status' ];
		foreach ( $map as $key => $wp_key ) {
			if ( ! array_key_exists( $key, $data ) ) { continue; }
			if ( 'menu_order' === $key ) {
				if ( ! is_int( $data[ $key ] ) ) { throw new RuntimeException( 'Product menu_order must be an integer.' ); }
			} elseif ( ! is_string( $data[ $key ] ) ) {
				throw new RuntimeException( 'A product core text field is invalid.' );
			}
			$update[ $wp_key ] = $data[ $key ];
		}
		if ( $creating ) { $update['post_status'] = 'draft'; }
		if ( count( $update ) > 1 ) {
			$result = wp_update_post( wp_slash( $update ), true );
			if ( is_wp_error( $result ) ) { throw new RuntimeException( 'WordPress rejected the product post update.' ); }
		}
		if ( array_key_exists( 'featured_image', $data ) ) {
			if ( ! is_int( $data['featured_image'] ) || $data['featured_image'] < 0 ) { throw new RuntimeException( 'Product featured_image must be an attachment ID or 0.' ); }
			if ( 0 === $data['featured_image'] ) { delete_post_thumbnail( $id ); }
			else {
				$attachment = get_post( $data['featured_image'] );
				if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) { throw new RuntimeException( 'The requested product featured image does not exist.' ); }
				set_post_thumbnail( $id, $data['featured_image'] );
			}
		}
	}


	private function apply_content_extensions( int $id, array $request ): void {
		$sections = [ 'acf', 'yoast', 'registered_meta', 'elementor' ];
		$has_extensions = false;
		foreach ( $sections as $section ) {
			if ( array_key_exists( $section, $request ) ) {
				$has_extensions = true;
				break;
			}
		}
		if ( ! $has_extensions ) {
			return;
		}
		$payload = $this->content->payload( $id );
		foreach ( $sections as $section ) {
			if ( array_key_exists( $section, $request ) ) {
				$payload[ $section ] = $request[ $section ];
			}
		}
		$this->content->apply( $id, $payload );
	}

	private function apply_taxonomies( int $id, mixed $taxonomies ): void {
		if ( ! is_array( $taxonomies ) || ( [] !== $taxonomies && array_is_list( $taxonomies ) ) ) {
			throw new RuntimeException( 'Product taxonomies must be an object.' );
		}
		$objects = get_object_taxonomies( 'product', 'objects' );
		foreach ( $taxonomies as $taxonomy => $slugs ) {
			$object = $objects[ $taxonomy ] ?? null;
			if ( ! is_object( $object ) || empty( $object->show_ui ) || ! current_user_can( $object->cap->assign_terms ) ) { throw new RuntimeException( 'A requested product taxonomy is unavailable or not assignable.' ); }
			if ( ! is_array( $slugs ) || ! array_is_list( $slugs ) ) { throw new RuntimeException( 'Product taxonomy values must be slug lists.' ); }
			$ids = [];
			foreach ( $slugs as $slug ) {
				$term = is_string( $slug ) ? get_term_by( 'slug', $slug, (string) $taxonomy ) : false;
				if ( ! $term instanceof \WP_Term ) { throw new RuntimeException( 'A requested product taxonomy term does not exist.' ); }
				$ids[] = (int) $term->term_id;
			}
			if ( is_wp_error( wp_set_object_terms( $id, $ids, (string) $taxonomy, false ) ) ) { throw new RuntimeException( 'WordPress rejected a product taxonomy update.' ); }
		}
	}
}
