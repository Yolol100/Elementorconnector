<?php

namespace Webactueel\ElementorJsonBridge\Content;

use RuntimeException;
use Webactueel\ElementorJsonBridge\Backup\OperationSnapshots;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

defined( 'ABSPATH' ) || exit;

final class ProductRequest {
	public const FORMAT  = 'elementor-json-bridge/manage-product';
	public const VERSION = 2;

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
		$before = $this->state( $id );
		if ( 'read' === $action ) {
			return $this->result( 'read', $id );
		}
		$this->assert_base_hash( $before, $request );
		$snapshot_id = $this->operation_snapshots()->create( 'woocommerce_product', 'product:' . $id, $before, 'before_product_' . $action );
		if ( 'delete' === $action ) {
			return $this->delete_product( $id, $request, $snapshot_id );
		}

		$this->apply_update( $id, $core, $woo, $request, $snapshot_id );
		$result = $this->result( 'updated', $id );
		$result['snapshot_id'] = $snapshot_id;
		return $result;
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
		$this->verify_state( $id, [ 'content' => $desired_content, 'woocommerce' => $desired_woo ] );
	}

	private function apply_update( int $id, array $core, array $woo, array $request, int $snapshot_id ): void {
		$before = $this->operation_snapshots()->payload( $snapshot_id, 'woocommerce_product', 'product:' . $id );
		try {
			$desired_content = $this->desired_content( $id, $before['content'], $core, $request, false );
			$desired_woo     = $this->desired_woo( $id, $before['woocommerce'], $woo );
			$desired_content = $this->align_product_brand_taxonomy( $id, $desired_content, $desired_woo );
			$desired         = [ 'content' => $desired_content, 'woocommerce' => $desired_woo ];

			$this->validate_core_extras( $core, false );
			$this->apply_core( $id, $core, false );
			$this->apply_woo( $id, $desired_woo );
			$this->content->apply( $id, $desired_content );
			$this->verify_state( $id, $desired );
		} catch ( \Throwable $apply_error ) {
			try {
				$rollback = $this->operation_snapshots()->payload( $snapshot_id, 'woocommerce_product', 'product:' . $id );
				$this->restore_state( $id, $rollback );
			} catch ( \Throwable $rollback_error ) {
				throw new RuntimeException( 'WooCommerce product update failed and rollback could not be verified: ' . $rollback_error->getMessage(), 0, $apply_error );
			}
			throw new RuntimeException( 'WooCommerce product update failed. The durable pre-update state was restored.', 0, $apply_error );
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

	private function state( int $id ): array {
		return [
			'content'     => $this->content->payload( $id ),
			'woocommerce' => $this->woo_payload( $id ),
		];
	}

	private function verify_state( int $id, array $expected ): void {
		if ( ! hash_equals( CanonicalJson::hash( $expected ), CanonicalJson::hash( $this->state( $id ) ) ) ) {
			throw new RuntimeException( 'WooCommerce product state failed exact readback verification.' );
		}
	}

	private function restore_state( int $id, array $state ): void {
		if ( ! isset( $state['content'], $state['woocommerce'] ) || ! is_array( $state['content'] ) || ! is_array( $state['woocommerce'] ) ) {
			throw new RuntimeException( 'The durable WooCommerce product snapshot is invalid.' );
		}
		$this->content->apply( $id, $state['content'] );
		$this->apply_woo( $id, $state['woocommerce'] );
		$this->verify_state( $id, $state );
	}

	private function assert_base_hash( array $state, array $request ): void {
		$base_hash = (string) ( $request['base_hash'] ?? '' );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $base_hash ) ) {
			throw new RuntimeException( 'Updating or deleting a product requires a valid base_hash.' );
		}
		if ( ! hash_equals( $base_hash, CanonicalJson::hash( $state ) ) ) {
			throw new RuntimeException( 'The WooCommerce product changed after this request was authored. Read the product again and create a new request.' );
		}
	}

	private function operation_snapshots(): OperationSnapshots {
		return new OperationSnapshots();
	}

	private function delete_product( int $id, array $request, int $snapshot_id ): array {
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
		return [ 'status' => 'deleted', 'product_id' => $id, 'force' => $force, 'snapshot_id' => $snapshot_id ];
	}

	private function result( string $status, int $id ): array {
		$product = wc_get_product( $id );
		$state   = $this->state( $id );
		return [
			'status'      => $status,
			'product_id'  => $id,
			'base_hash'   => CanonicalJson::hash( $state ),
			'post'        => [
				'title'          => $product instanceof \WC_Product ? (string) $product->get_name( 'edit' ) : '',
				'status'         => $product instanceof \WC_Product ? (string) $product->get_status( 'edit' ) : '',
				'content'        => $product instanceof \WC_Product ? (string) $product->get_description( 'edit' ) : '',
				'excerpt'        => $product instanceof \WC_Product ? (string) $product->get_short_description( 'edit' ) : '',
				'featured_image' => $product instanceof \WC_Product ? (int) $product->get_image_id( 'edit' ) : 0,
			],
			'woocommerce' => $state['woocommerce'],
		];
	}

	private function validate_request( array $request ): void {
		$allowed = [ 'format', 'version', 'request_id', 'action', 'product_id', 'post', 'woocommerce', 'taxonomies', 'acf', 'yoast', 'registered_meta', 'elementor', 'base_hash', 'confirm_destructive', 'force', 'result' ];
		if ( array_diff( array_keys( $request ), $allowed ) ) {
			throw new RuntimeException( 'The product request contains unsupported fields.' );
		}
		if ( self::FORMAT !== ( $request['format'] ?? null ) || self::VERSION !== (int) ( $request['version'] ?? 0 ) ) {
			throw new RuntimeException( 'The product request format or version is invalid. Regenerate legacy version-1 manage-product requests as version 2.' );
		}
		$action = (string) ( $request['action'] ?? '' );
		if ( ! in_array( $action, [ 'create', 'read', 'update', 'delete' ], true ) ) {
			throw new RuntimeException( 'The product request action is invalid.' );
		}
		if ( 'create' !== $action && (int) ( $request['product_id'] ?? 0 ) < 1 ) {
			throw new RuntimeException( 'Reading, updating or deleting a product requires an exact product_id.' );
		}
		if ( in_array( $action, [ 'update', 'delete' ], true ) && ( ! is_string( $request['base_hash'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $request['base_hash'] ) ) ) {
			throw new RuntimeException( 'Updating or deleting a product requires a valid base_hash from a version-2 read result.' );
		}
		if ( 'read' === $action && array_intersect( [ 'post', 'woocommerce', 'taxonomies', 'acf', 'yoast', 'registered_meta', 'elementor', 'force', 'confirm_destructive' ], array_keys( $request ) ) ) {
			throw new RuntimeException( 'A product read request cannot contain mutation fields.' );
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
		$allowed = [ 'title', 'slug', 'status', 'content', 'excerpt', 'featured_image', 'menu_order', 'comment_status' ];
		if ( array_diff( array_keys( $data ), $allowed ) ) {
			throw new RuntimeException( 'The product request contains unsupported core post fields.' );
		}
		if ( $creating && array_key_exists( 'status', $data ) && 'draft' !== $data['status'] ) {
			throw new RuntimeException( 'New WooCommerce products are always created as drafts.' );
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
		foreach ( [ 'title', 'slug', 'content', 'excerpt', 'comment_status' ] as $field ) {
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
