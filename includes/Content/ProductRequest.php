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
			if ( ! current_user_can( 'delete_post', $id ) ) {
				throw new RuntimeException( 'You are not allowed to delete this WooCommerce product.' );
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
		$product = wc_get_product( $id );
		return [
			'status' => $status,
			'product_id' => $id,
			'post' => [
				'title' => $product instanceof \WC_Product ? (string) $product->get_name( 'edit' ) : '',
				'status' => $product instanceof \WC_Product ? (string) $product->get_status( 'edit' ) : '',
				'content' => $product instanceof \WC_Product ? (string) $product->get_description( 'edit' ) : '',
				'excerpt' => $product instanceof \WC_Product ? (string) $product->get_short_description( 'edit' ) : '',
				'featured_image' => $product instanceof \WC_Product ? (int) $product->get_image_id( 'edit' ) : 0,
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
		$allowed = [ 'title', 'slug', 'status', 'content', 'excerpt', 'featured_image', 'menu_order', 'comment_status', 'password' ];
		if ( array_diff( array_keys( $data ), $allowed ) ) {
			throw new RuntimeException( 'The product request contains unsupported core post fields.' );
		}
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
		$product = wc_get_product( $id );
		if ( ! $product instanceof \WC_Product ) {
			throw new RuntimeException( 'The requested WooCommerce product no longer exists.' );
		}
		foreach ( [ 'title', 'slug', 'content', 'excerpt', 'comment_status', 'password' ] as $field ) {
			if ( array_key_exists( $field, $data ) && ! is_string( $data[ $field ] ) ) {
				throw new RuntimeException( 'A product core text field is invalid.' );
			}
		}
		if ( array_key_exists( 'menu_order', $data ) && ! is_int( $data['menu_order'] ) ) {
			throw new RuntimeException( 'Product menu_order must be an integer.' );
		}
		if ( array_key_exists( 'featured_image', $data ) && ( ! is_int( $data['featured_image'] ) || $data['featured_image'] < 0 ) ) {
			throw new RuntimeException( 'Product featured_image must be an attachment ID or 0.' );
		}
		if ( array_key_exists( 'title', $data ) ) { $product->set_name( $data['title'] ); }
		if ( array_key_exists( 'slug', $data ) ) { $product->set_slug( $data['slug'] ); }
		if ( array_key_exists( 'content', $data ) ) { $product->set_description( $data['content'] ); }
		if ( array_key_exists( 'excerpt', $data ) ) { $product->set_short_description( $data['excerpt'] ); }
		if ( array_key_exists( 'menu_order', $data ) ) { $product->set_menu_order( $data['menu_order'] ); }
		if ( array_key_exists( 'comment_status', $data ) ) {
			if ( ! in_array( $data['comment_status'], [ 'open', 'closed' ], true ) ) {
				throw new RuntimeException( 'Product comment_status must be open or closed.' );
			}
			$product->set_reviews_allowed( 'open' === $data['comment_status'] );
		}
		if ( array_key_exists( 'password', $data ) ) {
			if ( ! method_exists( $product, 'set_post_password' ) ) {
				throw new RuntimeException( 'This WooCommerce version does not support product passwords.' );
			}
			$product->set_post_password( $data['password'] );
		}
		if ( array_key_exists( 'featured_image', $data ) ) {
			if ( 0 !== $data['featured_image'] && ! wp_attachment_is_image( $data['featured_image'] ) ) {
				throw new RuntimeException( 'The requested product featured image does not exist.' );
			}
			$product->set_image_id( $data['featured_image'] );
		}
		$status = $creating ? 'draft' : ( $data['status'] ?? null );
		if ( null !== $status ) {
			$product->set_status( $status );
		}
		try {
			$product->save();
		} catch ( \Throwable $throwable ) {
			throw new RuntimeException( 'WooCommerce rejected the core product update: ' . $throwable->getMessage(), 0, $throwable );
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
