<?php

namespace Webactueel\ElementorJsonBridge\Content;

use RuntimeException;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

defined( 'ABSPATH' ) || exit;

final class ProductRequest {
	public const FORMAT  = 'elementor-json-bridge/manage-product';
	public const VERSION = 1;

	public function __construct(
		private readonly WooCommerceProduct $products,
		private readonly WooCommerceProductExtras $product_extras,
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
			$id   = $this->products->create( $type, $title );
			try {
				$this->apply_create( $id, $core, $woo, $request );
			} catch ( \Throwable $throwable ) {
				$product = wc_get_product( $id );
				if ( $product instanceof \WC_Product ) {
					$product->delete( true );
				}
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
			return $this->delete_product( $id, $request );
		}

		$this->apply_update( $id, $core, $woo, $request );
		return $this->result( 'updated', $id );
	}

	private function apply_create( int $id, array $core, array $woo, array $request ): void {
		$current_content = $this->content->payload( $id );
		$current_woo     = $this->woo_payload( $id );
		$desired_content = $this->desired_content( $id, $current_content, $core, $request, true );
		$desired_woo     = $this->desired_woo( $id, $current_woo, $woo );
		$desired_content = $this->align_product_brand_taxonomy( $id, $desired_content, $desired_woo );

		$this->validate_core_extras( $core, true );
		$this->apply_core( $id, $core, true );
		$this->apply_woo( $id, $desired_woo );
		$this->content->apply( $id, $desired_content );
		$this->verify_state( $id, $desired_content, $desired_woo, $core );
	}

	private function apply_update( int $id, array $core, array $woo, array $request ): void {
		$before_content  = $this->content->payload( $id );
		$before_woo      = $this->woo_payload( $id );
		$before_password = (string) get_post_field( 'post_password', $id, 'raw' );
		$desired_content = $this->desired_content( $id, $before_content, $core, $request, false );
		$desired_woo     = $this->desired_woo( $id, $before_woo, $woo );
		$desired_content = $this->align_product_brand_taxonomy( $id, $desired_content, $desired_woo );

		$this->validate_core_extras( $core, false );
		try {
			$this->apply_core( $id, $core, false );
			$this->apply_woo( $id, $desired_woo );
			$this->content->apply( $id, $desired_content );
			$this->verify_state( $id, $desired_content, $desired_woo, $core );
		} catch ( \Throwable $apply_error ) {
			try {
				$this->content->apply( $id, $before_content );
				$this->apply_woo( $id, $before_woo );
				$this->restore_password( $id, $before_password );
				$this->verify_state( $id, $before_content, $before_woo, [ 'password' => $before_password ] );
			} catch ( \Throwable $rollback_error ) {
				throw new RuntimeException( 'WooCommerce product update failed and rollback could not be verified: ' . $rollback_error->getMessage(), 0, $apply_error );
			}
			throw new RuntimeException( 'WooCommerce product update failed. The previous product state was restored.', 0, $apply_error );
		}
	}

	private function desired_content( int $id, array $current, array $core, array $request, bool $creating ): array {
		$desired = $current;
		foreach ( [ 'taxonomies', 'acf', 'yoast', 'registered_meta' ] as $section ) {
			if ( array_key_exists( $section, $request ) ) {
				$desired[ $section ] = array_replace( $desired[ $section ], $request[ $section ] );
			}
		}
		if ( array_key_exists( 'elementor', $request ) ) {
			$desired['elementor'] = $request['elementor'];
		}
		$map = [
			'title'          => 'title',
			'slug'           => 'slug',
			'status'         => 'status',
			'content'        => 'content',
			'excerpt'        => 'excerpt',
			'featured_image' => 'featured_image',
			'menu_order'     => 'menu_order',
			'comment_status' => 'comment_status',
		];
		foreach ( $map as $request_key => $post_key ) {
			if ( array_key_exists( $request_key, $core ) ) {
				if ( 'slug' === $request_key ) {
					if ( ! is_string( $core[ $request_key ] ) ) {
						throw new RuntimeException( 'The requested WooCommerce product slug is invalid.' );
					}
					$desired['post'][ $post_key ] = sanitize_title( $core[ $request_key ] );
				} else {
					$desired['post'][ $post_key ] = $core[ $request_key ];
				}
			}
		}
		if ( $creating ) {
			$desired['post']['status'] = 'draft';
		}
		return $this->content->validate_array( $desired, $id );
	}

	private function desired_woo( int $id, array $current, array $woo ): array {
		$desired                  = array_replace( $current, $woo );
		$desired['type']          = $current['type'];
		$desired['variation_ids'] = $current['variation_ids'];
		[ $base, $extras ]        = $this->product_extras->split( $desired );
		$base                     = $this->products->validate( $base, $id );
		$extras                   = $this->product_extras->validate( $extras, $id );
		return array_merge( $base, $extras );
	}

	private function align_product_brand_taxonomy( int $id, array $content, array $woo ): array {
		if ( ! array_key_exists( 'brand_ids', $woo ) || ! isset( $content['taxonomies']['product_brand'] ) ) {
			return $content;
		}
		$slugs = [];
		foreach ( $woo['brand_ids'] as $brand_id ) {
			$term = get_term( (int) $brand_id, 'product_brand' );
			if ( ! $term instanceof \WP_Term ) {
				throw new RuntimeException( 'A WooCommerce brand disappeared before the product update could be applied.' );
			}
			$slugs[] = (string) $term->slug;
		}
		sort( $slugs, SORT_STRING );
		$content['taxonomies']['product_brand'] = $slugs;
		return $this->content->validate_array( $content, $id );
	}

	private function apply_woo( int $id, array $woo ): void {
		[ $base, $extras ] = $this->product_extras->split( $woo );
		$this->products->apply( $id, $base );
		$this->product_extras->apply( $id, $extras );
	}

	private function woo_payload( int $id ): array {
		return array_merge( $this->products->payload( $id ), $this->product_extras->payload( $id ) );
	}

	private function verify_state( int $id, array $content, array $woo, array $core ): void {
		$read_content = $this->content->payload( $id );
		if ( ! hash_equals( CanonicalJson::hash( $content ), CanonicalJson::hash( $read_content ) ) ) {
			throw new RuntimeException( 'WooCommerce product content failed exact readback verification.' );
		}
		$read_woo = $this->woo_payload( $id );
		if ( ! hash_equals( CanonicalJson::hash( $woo ), CanonicalJson::hash( $read_woo ) ) ) {
			throw new RuntimeException( 'WooCommerce product data failed exact readback verification.' );
		}
		if ( array_key_exists( 'password', $core ) && (string) get_post_field( 'post_password', $id, 'raw' ) !== (string) $core['password'] ) {
			throw new RuntimeException( 'WooCommerce product password failed readback verification.' );
		}
	}

	private function restore_password( int $id, string $password ): void {
		$product = wc_get_product( $id );
		if ( ! $product instanceof \WC_Product || ! method_exists( $product, 'set_post_password' ) ) {
			if ( '' !== $password ) {
				throw new RuntimeException( 'WooCommerce cannot restore the product password on this version.' );
			}
			return;
		}
		$product->set_post_password( $password );
		$product->save();
	}

	private function delete_product( int $id, array $request ): array {
		if ( true !== ( $request['confirm_destructive'] ?? false ) ) {
			throw new RuntimeException( 'Deleting a WooCommerce product requires confirm_destructive=true.' );
		}
		if ( ! current_user_can( 'delete_post', $id ) ) {
			throw new RuntimeException( 'You are not allowed to delete this WooCommerce product.' );
		}
		$force   = (bool) ( $request['force'] ?? false );
		$product = wc_get_product( $id );
		if ( ! $product instanceof \WC_Product ) {
			throw new RuntimeException( 'The requested WooCommerce product no longer exists.' );
		}
		if ( ! $force ) {
			$supports_trash = apply_filters( 'woocommerce_product_object_trashable', EMPTY_TRASH_DAYS > 0, $product );
			if ( ! $supports_trash ) {
				throw new RuntimeException( 'WooCommerce trash is disabled for this product. Use force=true only when permanent deletion is intended.' );
			}
		}
		$deleted = $product->delete( $force );
		if ( ! $deleted ) {
			throw new RuntimeException( 'WooCommerce could not delete the requested product.' );
		}
		if ( $force ) {
			if ( null !== get_post( $id ) ) {
				throw new RuntimeException( 'WooCommerce permanent product deletion failed readback verification.' );
			}
		} elseif ( 'trash' !== get_post_status( $id ) ) {
			throw new RuntimeException( 'WooCommerce product trash failed readback verification.' );
		}
		return [ 'status' => 'deleted', 'product_id' => $id, 'force' => $force ];
	}

	private function result( string $status, int $id ): array {
		$product = wc_get_product( $id );
		return [
			'status'     => $status,
			'product_id' => $id,
			'post'       => [
				'title'          => $product instanceof \WC_Product ? (string) $product->get_name( 'edit' ) : '',
				'status'         => $product instanceof \WC_Product ? (string) $product->get_status( 'edit' ) : '',
				'content'        => $product instanceof \WC_Product ? (string) $product->get_description( 'edit' ) : '',
				'excerpt'        => $product instanceof \WC_Product ? (string) $product->get_short_description( 'edit' ) : '',
				'featured_image' => $product instanceof \WC_Product ? (int) $product->get_image_id( 'edit' ) : 0,
			],
			'woocommerce' => $this->woo_payload( $id ),
		];
	}

	private function validate_request( array $request ): void {
		$allowed = [ 'format', 'version', 'request_id', 'action', 'product_id', 'post', 'woocommerce', 'taxonomies', 'acf', 'yoast', 'registered_meta', 'elementor', 'confirm_destructive', 'force', 'result' ];
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
		if ( array_key_exists( 'force', $request ) && ! is_bool( $request['force'] ) ) {
			throw new RuntimeException( 'Product force must be a boolean.' );
		}
	}

	private function validate_core_extras( array $data, bool $creating ): void {
		$allowed = [ 'title', 'slug', 'status', 'content', 'excerpt', 'featured_image', 'menu_order', 'comment_status', 'password' ];
		if ( array_diff( array_keys( $data ), $allowed ) ) {
			throw new RuntimeException( 'The product request contains unsupported core post fields.' );
		}
		if ( $creating && array_key_exists( 'status', $data ) && 'draft' !== $data['status'] ) {
			throw new RuntimeException( 'New WooCommerce products are always created as drafts.' );
		}
		if ( array_key_exists( 'password', $data ) && ! is_string( $data['password'] ) ) {
			throw new RuntimeException( 'A product password must be a string.' );
		}
	}

	private function apply_core( int $id, array $data, bool $creating ): void {
		$this->validate_core_extras( $data, $creating );
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
		if ( array_key_exists( 'title', $data ) ) {
			$product->set_name( $data['title'] );
		}
		if ( array_key_exists( 'slug', $data ) ) {
			$product->set_slug( sanitize_title( $data['slug'] ) );
		}
		if ( array_key_exists( 'content', $data ) ) {
			$product->set_description( $data['content'] );
		}
		if ( array_key_exists( 'excerpt', $data ) ) {
			$product->set_short_description( $data['excerpt'] );
		}
		if ( array_key_exists( 'menu_order', $data ) ) {
			$product->set_menu_order( $data['menu_order'] );
		}
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
}
