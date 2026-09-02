<?php

namespace Webactueel\ElementorJsonBridge\Media;

defined( 'ABSPATH' ) || exit;

final class References {
	public static function assert_featured_image( int $attachment_id ): void {
		if ( 0 >= $attachment_id ) {
			return;
		}
		Inventory::assert_attachment_id( $attachment_id );
	}

	public static function assert_elementor_payload( array $payload ): void {
		foreach ( [ 'page_settings', 'content' ] as $key ) {
			if ( array_key_exists( $key, $payload ) ) {
				self::walk( $payload[ $key ] );
			}
		}
	}

	private static function walk( mixed $value ): void {
		if ( ! is_array( $value ) ) {
			return;
		}

		if ( ! array_is_list( $value ) && array_key_exists( 'id', $value ) && array_key_exists( 'url', $value ) ) {
			$id  = $value['id'];
			$url = $value['url'];
			if ( ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) && 0 < (int) $id && is_string( $url ) && '' !== $url ) {
				Inventory::assert_id_url( (int) $id, $url );
			}
		}

		foreach ( $value as $child ) {
			self::walk( $child );
		}
	}
}
