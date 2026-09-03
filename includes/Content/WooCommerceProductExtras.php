<?php

namespace Webactueel\ElementorJsonBridge\Content;

use RuntimeException;
defined( 'ABSPATH' ) || exit;

final class WooCommerceProductExtras {
	private const FIELDS = [ 'global_unique_id', 'low_stock_amount', 'brand_ids' ];

	public function payload( int $product_id ): array {
		$product = $this->product( $product_id );
		$data    = [];

		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			$data['global_unique_id'] = (string) $product->get_global_unique_id( 'edit' );
		}
		if ( method_exists( $product, 'get_low_stock_amount' ) ) {
			$amount = $product->get_low_stock_amount( 'edit' );
			$data['low_stock_amount'] = '' === $amount ? '' : (int) $amount;
		}
		if ( method_exists( $product, 'get_brand_ids' ) ) {
			$brand_ids = array_values( array_map( 'intval', $product->get_brand_ids( 'edit' ) ) );
			sort( $brand_ids, SORT_NUMERIC );
			$data['brand_ids'] = $brand_ids;
		}

		return $data;
	}

	public function split( array $data ): array {
		$base   = $data;
		$extras = [];
		foreach ( self::FIELDS as $field ) {
			if ( array_key_exists( $field, $base ) ) {
				$extras[ $field ] = $base[ $field ];
				unset( $base[ $field ] );
			}
		}
		return [ $base, $extras ];
	}

	public function validate( array $data, int $product_id ): array {
		if ( array_diff( array_keys( $data ), self::FIELDS ) ) {
			throw new RuntimeException( 'The WooCommerce product extras contain unsupported fields.' );
		}
		$product = $this->product( $product_id );

		if ( array_key_exists( 'global_unique_id', $data ) ) {
			if ( ! is_string( $data['global_unique_id'] ) || ! method_exists( $product, 'set_global_unique_id' ) ) {
				throw new RuntimeException( 'This WooCommerce version does not support global product identifiers.' );
			}
		}

		if ( array_key_exists( 'low_stock_amount', $data ) ) {
			$value = $data['low_stock_amount'];
			if ( ( '' !== $value && ( ! is_int( $value ) || $value < 0 ) ) || ! method_exists( $product, 'set_low_stock_amount' ) ) {
				throw new RuntimeException( 'The WooCommerce low stock amount is invalid or unsupported.' );
			}
		}

		if ( array_key_exists( 'brand_ids', $data ) ) {
			if ( ! method_exists( $product, 'set_brand_ids' ) ) {
				throw new RuntimeException( 'This WooCommerce version does not support product brands through its product model.' );
			}
			$this->validate_brand_ids( $data['brand_ids'] );
			$data['brand_ids'] = array_values( array_map( 'intval', $data['brand_ids'] ) );
			sort( $data['brand_ids'], SORT_NUMERIC );
		}

		return $data;
	}

	public function apply( int $product_id, array $data ): void {
		$data = $this->validate( $data, $product_id );
		if ( [] === $data ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			throw new RuntimeException( 'You are not allowed to edit this WooCommerce product.' );
		}
		$product = $this->product( $product_id );
		try {
			if ( array_key_exists( 'global_unique_id', $data ) ) {
				$product->set_global_unique_id( $data['global_unique_id'] );
			}
			if ( array_key_exists( 'low_stock_amount', $data ) ) {
				$product->set_low_stock_amount( $data['low_stock_amount'] );
			}
			if ( array_key_exists( 'brand_ids', $data ) ) {
				$product->set_brand_ids( $data['brand_ids'] );
			}
			$product->save();
		} catch ( \Throwable $throwable ) {
			throw new RuntimeException( 'WooCommerce rejected one of the current product model fields: ' . $throwable->getMessage(), 0, $throwable );
		}
	}

	private function product( int $product_id ): \WC_Product {
		if ( ! function_exists( 'wc_get_product' ) ) {
			throw new RuntimeException( 'WooCommerce is not active.' );
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			throw new RuntimeException( 'The requested WooCommerce product does not exist.' );
		}
		return $product;
	}

	private function validate_brand_ids( mixed $brand_ids ): void {
		if ( ! is_array( $brand_ids ) || ! array_is_list( $brand_ids ) ) {
			throw new RuntimeException( 'WooCommerce brand_ids must be a list.' );
		}
		if ( [] !== $brand_ids && ! taxonomy_exists( 'product_brand' ) ) {
			throw new RuntimeException( 'WooCommerce product brands are not registered on this site.' );
		}
		foreach ( $brand_ids as $brand_id ) {
			if ( ! is_int( $brand_id ) || $brand_id < 1 || ! get_term( $brand_id, 'product_brand' ) instanceof \WP_Term ) {
				throw new RuntimeException( 'WooCommerce brand_ids contains a brand that does not exist.' );
			}
		}
	}
}
