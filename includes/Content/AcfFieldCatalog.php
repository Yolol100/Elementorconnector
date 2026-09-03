<?php

namespace Webactueel\ElementorJsonBridge\Content;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class AcfFieldCatalog {
	public static function available(): bool {
		return function_exists( 'acf_get_field_groups' )
			&& function_exists( 'acf_get_fields' )
			&& function_exists( 'get_field' )
			&& function_exists( 'update_field' );
	}

	public static function for_post( int $post_id, string $post_type ): array {
		if ( ! self::available() ) {
			return [];
		}
		$groups = acf_get_field_groups(
			[
				'post_id'   => $post_id,
				'post_type' => $post_type,
			]
		);
		return self::collect( is_array( $groups ) ? $groups : [], $post_id );
	}

	public static function for_term( int $term_id, string $taxonomy ): array {
		if ( ! self::available() ) {
			return [];
		}
		$groups = acf_get_field_groups( [ 'taxonomy' => $taxonomy ] );
		return self::collect( is_array( $groups ) ? $groups : [], 'term_' . $term_id );
	}

	public static function validate( array $requested, array $current, string $message ): void {
		foreach ( $requested as $name => $field ) {
			$keys = is_array( $field ) ? array_keys( $field ) : [];
			sort( $keys, SORT_STRING );
			if (
				! is_string( $name )
				|| '' === $name
				|| [ 'key', 'type', 'value' ] !== $keys
				|| ! isset( $current[ $name ] )
				|| $field['key'] !== $current[ $name ]['key']
				|| $field['type'] !== $current[ $name ]['type']
			) {
				throw new RuntimeException( $message );
			}
		}
	}

	public static function apply( array $fields, int|string $object_id ): void {
		foreach ( $fields as $field ) {
			update_field( (string) $field['key'], $field['value'], $object_id );
		}
	}

	private static function collect( array $groups, int|string $object_id ): array {
		$result = [];
		foreach ( $groups as $group ) {
			$fields = is_array( $group ) ? acf_get_fields( $group ) : false;
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) || empty( $field['key'] ) || empty( $field['name'] ) || empty( $field['type'] ) ) {
					continue;
				}
				$name = (string) $field['name'];
				$key  = (string) $field['key'];
				$type = (string) $field['type'];
				if ( isset( $result[ $name ] ) && ( $key !== $result[ $name ]['key'] || $type !== $result[ $name ]['type'] ) ) {
					throw new RuntimeException( 'Multiple applicable ACF fields use the same field name with different identities.' );
				}
				$result[ $name ] = [
					'key'   => $key,
					'type'  => $type,
					'value' => get_field( $key, $object_id, false ),
				];
			}
		}
		ksort( $result, SORT_STRING );
		return $result;
	}
}
