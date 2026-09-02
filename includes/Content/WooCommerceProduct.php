<?php

namespace Webactueel\ElementorJsonBridge\Content;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class WooCommerceProduct {
	private const BOOLEAN_FIELDS = [
		'featured',
		'reviews_allowed',
		'virtual',
		'downloadable',
		'manage_stock',
		'sold_individually',
	];

	private const STRING_FIELDS = [
		'catalog_visibility',
		'sku',
		'regular_price',
		'sale_price',
		'date_on_sale_from',
		'date_on_sale_to',
		'purchase_note',
		'tax_status',
		'tax_class',
		'stock_status',
		'backorders',
		'weight',
		'length',
		'width',
		'height',
	];

	private const INTEGER_FIELDS = [
		'download_limit',
		'download_expiry',
		'shipping_class_id',
	];

	private const ID_LIST_FIELDS = [
		'gallery_image_ids',
		'upsell_ids',
		'cross_sell_ids',
	];

	public function available(): bool {
		return function_exists( 'wc_get_product' ) && class_exists( '\\WC_Product' );
	}

	public function supports( int $post_id ): bool {
		if ( ! $this->available() || 'product' !== get_post_type( $post_id ) ) {
			return false;
		}
		return wc_get_product( $post_id ) instanceof \WC_Product;
	}

	public function payload( int $post_id ): array {
		$product = $this->product( $post_id );
		$data    = [
			'type'                => (string) $product->get_type(),
			'featured'            => (bool) $product->get_featured( 'edit' ),
			'catalog_visibility'  => (string) $product->get_catalog_visibility( 'edit' ),
			'sku'                 => (string) $product->get_sku( 'edit' ),
			'regular_price'       => (string) $product->get_regular_price( 'edit' ),
			'sale_price'          => (string) $product->get_sale_price( 'edit' ),
			'date_on_sale_from'   => $this->date_value( $product->get_date_on_sale_from( 'edit' ) ),
			'date_on_sale_to'     => $this->date_value( $product->get_date_on_sale_to( 'edit' ) ),
			'purchase_note'       => (string) $product->get_purchase_note( 'edit' ),
			'reviews_allowed'     => (bool) $product->get_reviews_allowed( 'edit' ),
			'virtual'             => (bool) $product->get_virtual( 'edit' ),
			'downloadable'        => (bool) $product->get_downloadable( 'edit' ),
			'download_limit'      => (int) $product->get_download_limit( 'edit' ),
			'download_expiry'     => (int) $product->get_download_expiry( 'edit' ),
			'tax_status'          => (string) $product->get_tax_status( 'edit' ),
			'tax_class'           => (string) $product->get_tax_class( 'edit' ),
			'manage_stock'        => (bool) $product->get_manage_stock( 'edit' ),
			'stock_quantity'      => $product->get_stock_quantity( 'edit' ),
			'stock_status'        => (string) $product->get_stock_status( 'edit' ),
			'backorders'          => (string) $product->get_backorders( 'edit' ),
			'sold_individually'   => (bool) $product->get_sold_individually( 'edit' ),
			'weight'              => (string) $product->get_weight( 'edit' ),
			'length'              => (string) $product->get_length( 'edit' ),
			'width'               => (string) $product->get_width( 'edit' ),
			'height'              => (string) $product->get_height( 'edit' ),
			'shipping_class_id'   => (int) $product->get_shipping_class_id( 'edit' ),
			'gallery_image_ids'   => array_values( array_map( 'intval', $product->get_gallery_image_ids( 'edit' ) ) ),
			'upsell_ids'          => array_values( array_map( 'intval', $product->get_upsell_ids( 'edit' ) ) ),
			'cross_sell_ids'      => array_values( array_map( 'intval', $product->get_cross_sell_ids( 'edit' ) ) ),
			'attributes'          => $this->attributes( $product ),
			'default_attributes'  => $this->default_attributes( $product ),
			'downloads'           => $this->downloads( $product ),
			'variation_ids'       => $product instanceof \WC_Product_Variable ? array_values( array_map( 'intval', $product->get_children() ) ) : [],
		];

		if ( method_exists( $product, 'get_product_url' ) ) {
			$data['product_url'] = (string) $product->get_product_url();
		}
		if ( method_exists( $product, 'get_button_text' ) ) {
			$data['button_text'] = (string) $product->get_button_text();
		}
		if ( $product instanceof \WC_Product_Grouped ) {
			$data['children'] = array_values( array_map( 'intval', $product->get_children() ) );
		}

		return $data;
	}

	public function validate( array $data, int $post_id ): array {
		$product = $this->product( $post_id );
		$allowed = array_merge(
			[ 'type', 'stock_quantity', 'attributes', 'default_attributes', 'downloads', 'variation_ids', 'product_url', 'button_text', 'children' ],
			self::BOOLEAN_FIELDS,
			self::STRING_FIELDS,
			self::INTEGER_FIELDS,
			self::ID_LIST_FIELDS
		);
		if ( array_diff( array_keys( $data ), $allowed ) ) {
			throw new RuntimeException( 'The WordPress content JSON contains unsupported WooCommerce product fields.' );
		}
		if ( ! isset( $data['type'] ) || ! is_string( $data['type'] ) || $data['type'] !== $product->get_type() ) {
			throw new RuntimeException( 'A WooCommerce product type cannot be changed through an existing content file.' );
		}

		foreach ( self::BOOLEAN_FIELDS as $field ) {
			if ( array_key_exists( $field, $data ) && ! is_bool( $data[ $field ] ) ) {
				throw new RuntimeException( 'The WooCommerce product JSON contains an invalid boolean field.' );
			}
		}
		foreach ( self::STRING_FIELDS as $field ) {
			if ( array_key_exists( $field, $data ) && ! is_string( $data[ $field ] ) ) {
				throw new RuntimeException( 'The WooCommerce product JSON contains an invalid string field.' );
			}
		}
		foreach ( self::INTEGER_FIELDS as $field ) {
			if ( array_key_exists( $field, $data ) && ! is_int( $data[ $field ] ) ) {
				throw new RuntimeException( 'The WooCommerce product JSON contains an invalid integer field.' );
			}
		}
		if ( array_key_exists( 'stock_quantity', $data ) && null !== $data['stock_quantity'] && ! is_int( $data['stock_quantity'] ) && ! is_float( $data['stock_quantity'] ) ) {
			throw new RuntimeException( 'The WooCommerce product stock quantity is invalid.' );
		}
		foreach ( self::ID_LIST_FIELDS as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$this->validate_id_list( $data[ $field ], $field );
			}
		}
		if ( array_key_exists( 'variation_ids', $data ) ) {
			$this->validate_id_list( $data['variation_ids'], 'variation_ids' );
			$current = $product instanceof \WC_Product_Variable ? array_values( array_map( 'intval', $product->get_children() ) ) : [];
			if ( $data['variation_ids'] !== $current ) {
				throw new RuntimeException( 'WooCommerce variation IDs are read-only in the product content file; use a variation request instead.' );
			}
		}
		if ( array_key_exists( 'attributes', $data ) ) {
			$this->validate_attributes( $data['attributes'] );
		}
		if ( array_key_exists( 'default_attributes', $data ) ) {
			if ( ! is_array( $data['default_attributes'] ) || ( [] !== $data['default_attributes'] && array_is_list( $data['default_attributes'] ) ) ) {
				throw new RuntimeException( 'The WooCommerce default attributes must be an object.' );
			}
			foreach ( $data['default_attributes'] as $name => $value ) {
				if ( ! is_string( $name ) || '' === $name || ! is_string( $value ) ) {
					throw new RuntimeException( 'A WooCommerce default attribute is invalid.' );
				}
			}
		}
		if ( array_key_exists( 'downloads', $data ) ) {
			$this->validate_downloads( $data['downloads'] );
		}
		foreach ( [ 'product_url', 'button_text' ] as $field ) {
			if ( array_key_exists( $field, $data ) && ! is_string( $data[ $field ] ) ) {
				throw new RuntimeException( 'The WooCommerce external product field is invalid.' );
			}
		}
		if ( array_key_exists( 'children', $data ) ) {
			if ( ! $product instanceof \WC_Product_Grouped ) {
				throw new RuntimeException( 'Grouped-product children can only be used on grouped products.' );
			}
			$this->validate_id_list( $data['children'], 'children' );
		}
		return $data;
	}

	public function apply( int $post_id, array $data ): void {
		$data    = $this->validate( $data, $post_id );
		$product = $this->product( $post_id );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new RuntimeException( 'You are not allowed to edit this WooCommerce product.' );
		}

		foreach ( self::BOOLEAN_FIELDS as $field ) {
			$this->call_setter( $product, $field, $data );
		}
		foreach ( self::STRING_FIELDS as $field ) {
			if ( in_array( $field, [ 'date_on_sale_from', 'date_on_sale_to' ], true ) ) {
				if ( array_key_exists( $field, $data ) ) {
					$method = 'set_' . $field;
					$product->{$method}( '' === $data[ $field ] ? null : $data[ $field ] );
				}
				continue;
			}
			$this->call_setter( $product, $field, $data );
		}
		foreach ( self::INTEGER_FIELDS as $field ) {
			$this->call_setter( $product, $field, $data );
		}
		if ( array_key_exists( 'stock_quantity', $data ) ) {
			$product->set_stock_quantity( $data['stock_quantity'] );
		}
		foreach ( self::ID_LIST_FIELDS as $field ) {
			$this->call_setter( $product, $field, $data );
		}
		if ( array_key_exists( 'attributes', $data ) ) {
			$product->set_attributes( $this->attribute_objects( $data['attributes'] ) );
		}
		if ( array_key_exists( 'default_attributes', $data ) ) {
			$product->set_default_attributes( $data['default_attributes'] );
		}
		if ( array_key_exists( 'downloads', $data ) ) {
			$product->set_downloads( $this->download_objects( $data['downloads'] ) );
		}
		if ( array_key_exists( 'product_url', $data ) ) {
			if ( ! method_exists( $product, 'set_product_url' ) ) {
				throw new RuntimeException( 'This WooCommerce product type does not support an external product URL.' );
			}
			$product->set_product_url( $data['product_url'] );
		}
		if ( array_key_exists( 'button_text', $data ) ) {
			if ( ! method_exists( $product, 'set_button_text' ) ) {
				throw new RuntimeException( 'This WooCommerce product type does not support external button text.' );
			}
			$product->set_button_text( $data['button_text'] );
		}
		if ( array_key_exists( 'children', $data ) && $product instanceof \WC_Product_Grouped ) {
			$product->set_children( $data['children'] );
		}

		try {
			$product->save();
		} catch ( \Throwable $throwable ) {
			throw new RuntimeException( 'WooCommerce rejected the product update: ' . $throwable->getMessage(), 0, $throwable );
		}
	}

	public function create( string $type, string $title, array $data = [] ): int {
		if ( ! $this->available() ) {
			throw new RuntimeException( 'WooCommerce is not active.' );
		}
		$class = match ( $type ) {
			'simple'   => '\\WC_Product_Simple',
			'variable' => '\\WC_Product_Variable',
			'grouped'  => '\\WC_Product_Grouped',
			'external' => '\\WC_Product_External',
			default    => '',
		};
		if ( '' === $class || ! class_exists( $class ) ) {
			throw new RuntimeException( 'The requested WooCommerce product type is not supported.' );
		}
		$product = new $class();
		$product->set_name( $title );
		$product->set_status( 'draft' );
		try {
			$id = (int) $product->save();
		} catch ( \Throwable $throwable ) {
			throw new RuntimeException( 'WooCommerce could not create the requested product draft.', 0, $throwable );
		}
		if ( $id < 1 ) {
			throw new RuntimeException( 'WooCommerce did not return a product ID.' );
		}
		if ( $data ) {
			$current         = $this->payload( $id );
			$data['type']    = $type;
			$data             = array_replace( $current, $data );
			$data['type']     = $type;
			$data['variation_ids'] = $current['variation_ids'];
			$this->apply( $id, $data );
		}
		return $id;
	}

	private function product( int $post_id ): \WC_Product {
		if ( ! $this->available() ) {
			throw new RuntimeException( 'WooCommerce product data is present but WooCommerce is not active.' );
		}
		$product = wc_get_product( $post_id );
		if ( ! $product instanceof \WC_Product ) {
			throw new RuntimeException( 'The requested WooCommerce product does not exist.' );
		}
		return $product;
	}

	private function call_setter( \WC_Product $product, string $field, array $data ): void {
		if ( ! array_key_exists( $field, $data ) ) {
			return;
		}
		$method = 'set_' . $field;
		if ( ! method_exists( $product, $method ) ) {
			throw new RuntimeException( 'This WooCommerce version does not support one of the requested product fields.' );
		}
		$product->{$method}( $data[ $field ] );
	}

	private function date_value( mixed $date ): string {
		if ( ! $date instanceof \WC_DateTime ) {
			return '';
		}
		return $date->date( 'Y-m-d H:i:s' );
	}

	private function validate_id_list( mixed $value, string $field ): void {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			throw new RuntimeException( 'The WooCommerce ' . $field . ' field must be a list.' );
		}
		foreach ( $value as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				throw new RuntimeException( 'The WooCommerce ' . $field . ' field contains an invalid ID.' );
			}
		}
	}

	private function attributes( \WC_Product $product ): array {
		$result = [];
		foreach ( $product->get_attributes( 'edit' ) as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}
			$result[] = [
				'id'        => (int) $attribute->get_id(),
				'name'      => (string) $attribute->get_name(),
				'options'   => array_values( $attribute->get_options() ),
				'position'  => (int) $attribute->get_position(),
				'visible'   => (bool) $attribute->get_visible(),
				'variation' => (bool) $attribute->get_variation(),
			];
		}
		return $result;
	}

	private function validate_attributes( mixed $attributes ): void {
		if ( ! is_array( $attributes ) || ! array_is_list( $attributes ) ) {
			throw new RuntimeException( 'WooCommerce attributes must be a list.' );
		}
		$expected = [ 'id', 'name', 'options', 'position', 'visible', 'variation' ];
		sort( $expected, SORT_STRING );
		foreach ( $attributes as $attribute ) {
			$keys = is_array( $attribute ) ? array_keys( $attribute ) : [];
			sort( $keys, SORT_STRING );
			if ( ! is_array( $attribute ) || $expected !== $keys ) {
				throw new RuntimeException( 'A WooCommerce product attribute is incomplete.' );
			}
			if ( ! is_int( $attribute['id'] ) || $attribute['id'] < 0 || ! is_string( $attribute['name'] ) || '' === trim( $attribute['name'] ) || ! is_int( $attribute['position'] ) || ! is_bool( $attribute['visible'] ) || ! is_bool( $attribute['variation'] ) ) {
				throw new RuntimeException( 'A WooCommerce product attribute contains invalid settings.' );
			}
			if ( ! is_array( $attribute['options'] ) || ! array_is_list( $attribute['options'] ) ) {
				throw new RuntimeException( 'WooCommerce product attribute options must be a list.' );
			}
			foreach ( $attribute['options'] as $option ) {
				if ( ! is_int( $option ) && ! is_string( $option ) ) {
					throw new RuntimeException( 'A WooCommerce product attribute option is invalid.' );
				}
			}
		}
	}

	private function attribute_objects( array $attributes ): array {
		$result = [];
		foreach ( $attributes as $item ) {
			$attribute = new \WC_Product_Attribute();
			$attribute->set_id( $item['id'] );
			$attribute->set_name( $item['name'] );
			$attribute->set_options( $item['options'] );
			$attribute->set_position( $item['position'] );
			$attribute->set_visible( $item['visible'] );
			$attribute->set_variation( $item['variation'] );
			$result[] = $attribute;
		}
		return $result;
	}

	private function default_attributes( \WC_Product $product ): array {
		$result = [];
		foreach ( $product->get_default_attributes( 'edit' ) as $name => $value ) {
			$result[ (string) $name ] = (string) $value;
		}
		ksort( $result, SORT_STRING );
		return $result;
	}

	private function downloads( \WC_Product $product ): array {
		$result = [];
		foreach ( $product->get_downloads( 'edit' ) as $download ) {
			if ( ! $download instanceof \WC_Product_Download ) {
				continue;
			}
			$result[] = [
				'id'   => (string) $download->get_id(),
				'name' => (string) $download->get_name(),
				'file' => (string) $download->get_file(),
			];
		}
		return $result;
	}

	private function validate_downloads( mixed $downloads ): void {
		if ( ! is_array( $downloads ) || ! array_is_list( $downloads ) ) {
			throw new RuntimeException( 'WooCommerce downloads must be a list.' );
		}
		$expected = [ 'file', 'id', 'name' ];
		foreach ( $downloads as $download ) {
			$keys = is_array( $download ) ? array_keys( $download ) : [];
			sort( $keys, SORT_STRING );
			if ( ! is_array( $download ) || $expected !== $keys || ! is_string( $download['id'] ) || ! is_string( $download['name'] ) || ! is_string( $download['file'] ) ) {
				throw new RuntimeException( 'A WooCommerce download is invalid.' );
			}
		}
	}

	private function download_objects( array $downloads ): array {
		$result = [];
		foreach ( $downloads as $item ) {
			$download = new \WC_Product_Download();
			if ( '' !== $item['id'] ) {
				$download->set_id( $item['id'] );
			}
			$download->set_name( $item['name'] );
			$download->set_file( $item['file'] );
			$id = (string) $download->get_id();
			if ( '' === $id ) {
				$id = md5( $download->get_file() );
				$download->set_id( $id );
			}
			$result[ $id ] = $download;
		}
		return $result;
	}
}
