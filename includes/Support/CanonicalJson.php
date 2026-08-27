<?php

namespace Webactueel\ElementorJsonBridge\Support;

use JsonException;

defined( 'ABSPATH' ) || exit;

final class CanonicalJson {
	/** @throws JsonException */
	public static function encode( array $value, bool $pretty = false ): string {
		$normalized = self::normalize( $value );
		$flags      = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( $pretty ) {
			$flags |= JSON_PRETTY_PRINT;
		}
		return json_encode( $normalized, $flags ) . ( $pretty ? "\n" : '' );
	}

	/** @throws JsonException */
	public static function hash( array $value ): string {
		return hash( 'sha256', self::encode( $value ) );
	}

	private static function normalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( array_is_list( $value ) ) {
			return array_map( [ self::class, 'normalize' ], $value );
		}

		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::normalize( $item );
		}
		return $value;
	}
}
