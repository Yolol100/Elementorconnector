<?php

namespace Webactueel\ElementorJsonBridge\Content;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class ProductVariation {
	public const FORMAT  = 'elementor-json-bridge/manage-product-variation';
	public const VERSION = 1;

	private const BOOLEAN_FIELDS = [ 'enabled', 'virtual', 'downloadable', 'manage_stock' ];
	private const STRING_FIELDS  = [ 'sku', 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to', 'stock_status', 'backorders', 'tax_class', 'weight', 'length', 'width', 'height', 'description' ];
	private const INTEGER_FIELDS = [ 'download_limit', 'download_expiry', 'shipping_class_id', 'image_id', 'menu_order' ];

	public function execute( array $request ): array {
		$this->validate_request( $request );
		$action     = (string) $request['action'];
		$product_id = (int) $request['product_id'];
		$parent     = $this->parent( $product_id );

		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			throw new RuntimeException( 'You are not allowed to edit this variable product.' );
		}

		if ( 'create' === $action ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $product_id );
			$variation->set_status( 'publish' );
			$this->apply_data( $variation, (array) ( $request['data'] ?? [] ) );
			$id = (int) $variation->save();
			if ( $id < 1 ) {
				throw new RuntimeException( 'WooCommerce did not return a variation ID.' );
			}
			return [ 'status' => 'created', 'product_id' => $product_id, 'variation_id' => $id, 'data' => $this->payload( $variation ) ];
		}

		$variation_id = (int) ( $request['variation_id'] ?? 0 );
		$variation    = wc_get_product( $variation_id );
		if ( ! $variation instanceof \WC_Product_Variation || (int) $variation->get_parent_id() !== $product_id ) {
			throw new RuntimeException( 'The requested WooCommerce variation does not belong to this product.' );
		}
		if ( ! current_user_can( 'edit_post', $variation_id ) ) {
			throw new RuntimeException( 'You are not allowed to edit this WooCommerce variation.' );
		}

		if ( 'delete' === $action ) {
			if ( true !== ( $request['confirm_destructive'] ?? false ) ) {
				throw new RuntimeException( 'Deleting a WooCommerce variation requires confirm_destructive=true.' );
			}
			$variation->delete( true );
			return [ 'status' => 'deleted', 'product_id' => $product_id, 'variation_id' => $variation_id ];
		}

		$this->apply_data( $variation, (array) ( $request['data'] ?? [] ) );
		$variation->save();
		return [ 'status' => 'updated', 'product_id' => $product_id, 'variation_id' => $variation_id, 'data' => $this->payload( $variation ) ];
	}

	private function validate_request( array $request ): void {
		$allowed = [ 'format', 'version', 'request_id', 'action', 'product_id', 'variation_id', 'data', 'confirm_destructive', 'result' ];
		if ( array_diff( array_keys( $request ), $allowed ) ) {
			throw new RuntimeException( 'The variation request contains unsupported fields.' );
		}
		if ( self::FORMAT !== ( $request['format'] ?? null ) || self::VERSION !== (int) ( $request['version'] ?? 0 ) ) {
			throw new RuntimeException( 'The variation request format or version is invalid.' );
		}
		if ( ! in_array( (string) ( $request['action'] ?? '' ), [ 'create', 'update', 'delete' ], true ) || (int) ( $request['product_id'] ?? 0 ) < 1 ) {
			throw new RuntimeException( 'The variation request action or product ID is invalid.' );
		}
		if ( 'create' !== (string) $request['action'] && (int) ( $request['variation_id'] ?? 0 ) < 1 ) {
			throw new RuntimeException( 'Updating or deleting a variation requires an exact variation_id.' );
		}
		if ( isset( $request['data'] ) && ( ! is_array( $request['data'] ) || ( [] !== $request['data'] && array_is_list( $request['data'] ) ) ) ) {
			throw new RuntimeException( 'The variation data must be an object.' );
		}
	}

	private function parent( int $product_id ): \WC_Product_Variable {
		if ( ! function_exists( 'wc_get_product' ) || ! class_exists( '\\WC_Product_Variable' ) ) {
			throw new RuntimeException( 'WooCommerce is not active.' );
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product_Variable ) {
			throw new RuntimeException( 'Product variation requests require a variable WooCommerce product.' );
		}
		return $product;
	}

	private function apply_data( \WC_Product_Variation $variation, array $data ): void {
		$allowed = array_merge( [ 'attributes', 'stock_quantity' ], self::BOOLEAN_FIELDS, self::STRING_FIELDS, self::INTEGER_FIELDS );
		if ( array_diff( array_keys( $data ), $allowed ) ) {
			throw new RuntimeException( 'The variation request contains unsupported product fields.' );
		}

		foreach ( self::BOOLEAN_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}
			if ( ! is_bool( $data[ $field ] ) ) {
				throw new RuntimeException( 'A WooCommerce variation boolean field is invalid.' );
			}
			if ( 'enabled' === $field ) {
				$variation->set_status( $data[ $field ] ? 'publish' : 'private' );
				continue;
			}
			$method = 'set_' . $field;
			$variation->{$method}( $data[ $field ] );
		}
		foreach ( self::STRING_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}
			if ( ! is_string( $data[ $field ] ) ) {
				throw new RuntimeException( 'A WooCommerce variation string field is invalid.' );
			}
			$method = 'set_' . $field;
			if ( in_array( $field, [ 'date_on_sale_from', 'date_on_sale_to' ], true ) ) {
				$variation->{$method}( '' === $data[ $field ] ? null : $data[ $field ] );
			} else {
				$variation->{$method}( $data[ $field ] );
			}
		}
		foreach ( self::INTEGER_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}
			if ( ! is_int( $data[ $field ] ) || $data[ $field ] < 0 ) {
				throw new RuntimeException( 'A WooCommerce variation integer field is invalid.' );
			}
			$method = 'set_' . $field;
			$variation->{$method}( $data[ $field ] );
		}
		if ( array_key_exists( 'stock_quantity', $data ) ) {
			if ( null !== $data['stock_quantity'] && ! is_int( $data['stock_quantity'] ) && ! is_float( $data['stock_quantity'] ) ) {
				throw new RuntimeException( 'The WooCommerce variation stock quantity is invalid.' );
			}
			$variation->set_stock_quantity( $data['stock_quantity'] );
		}
		if ( array_key_exists( 'attributes', $data ) ) {
			if ( ! is_array( $data['attributes'] ) || ( [] !== $data['attributes'] && array_is_list( $data['attributes'] ) ) ) {
				throw new RuntimeException( 'Variation attributes must be an object.' );
			}
			$attributes = [];
			foreach ( $data['attributes'] as $name => $value ) {
				if ( ! is_string( $name ) || '' === $name || ! is_string( $value ) ) {
					throw new RuntimeException( 'A WooCommerce variation attribute is invalid.' );
				}
				$attributes[ $name ] = $value;
			}
			$variation->set_attributes( $attributes );
		}
	}

	private function payload( \WC_Product_Variation $variation ): array {
		$from = $variation->get_date_on_sale_from( 'edit' );
		$to   = $variation->get_date_on_sale_to( 'edit' );
		return [
			'enabled'           => 'publish' === $variation->get_status( 'edit' ),
			'sku'               => (string) $variation->get_sku( 'edit' ),
			'regular_price'     => (string) $variation->get_regular_price( 'edit' ),
			'sale_price'        => (string) $variation->get_sale_price( 'edit' ),
			'date_on_sale_from' => $from instanceof \WC_DateTime ? $from->date( 'Y-m-d H:i:s' ) : '',
			'date_on_sale_to'   => $to instanceof \WC_DateTime ? $to->date( 'Y-m-d H:i:s' ) : '',
			'virtual'           => (bool) $variation->get_virtual( 'edit' ),
			'downloadable'      => (bool) $variation->get_downloadable( 'edit' ),
			'download_limit'    => (int) $variation->get_download_limit( 'edit' ),
			'download_expiry'   => (int) $variation->get_download_expiry( 'edit' ),
			'manage_stock'      => (bool) $variation->get_manage_stock( 'edit' ),
			'stock_quantity'    => $variation->get_stock_quantity( 'edit' ),
			'stock_status'      => (string) $variation->get_stock_status( 'edit' ),
			'backorders'        => (string) $variation->get_backorders( 'edit' ),
			'tax_class'         => (string) $variation->get_tax_class( 'edit' ),
			'weight'            => (string) $variation->get_weight( 'edit' ),
			'length'            => (string) $variation->get_length( 'edit' ),
			'width'             => (string) $variation->get_width( 'edit' ),
			'height'            => (string) $variation->get_height( 'edit' ),
			'shipping_class_id' => (int) $variation->get_shipping_class_id( 'edit' ),
			'image_id'          => (int) $variation->get_image_id( 'edit' ),
			'menu_order'        => (int) $variation->get_menu_order( 'edit' ),
			'description'       => (string) $variation->get_description( 'edit' ),
			'attributes'        => array_map( 'strval', $variation->get_attributes( 'edit' ) ),
		];
	}
}
