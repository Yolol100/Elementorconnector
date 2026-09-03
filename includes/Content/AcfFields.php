<?php

namespace Webactueel\ElementorJsonBridge\Content;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class AcfFields {
	public static function validate_post( int $post_id, array $requested, array $current ): void {
		self::validate( $requested, $current, static fn (): array => self::post_definitions( $post_id ) );
	}

	public static function validate_taxonomy( string $taxonomy, array $requested, array $current ): void {
		self::validate( $requested, $current, static fn (): array => self::taxonomy_definitions( $taxonomy ) );
	}

	public static function apply_authoritative( int|string $target, array $desired, array $current ): void {
		$deletions = [];
		foreach ( $current as $name => $field ) {
			if ( ! array_key_exists( $name, $desired ) && is_array( $field ) && ! empty( $field['key'] ) ) {
				$deletions[] = (string) $field['key'];
			}
		}
		if ( $deletions && ! function_exists( 'delete_field' ) ) {
			throw new RuntimeException( 'ACF field removal is unavailable on this site.' );
		}
		if ( $desired && ! function_exists( 'update_field' ) ) {
			throw new RuntimeException( 'ACF field updates are unavailable on this site.' );
		}
		foreach ( $deletions as $field_key ) {
			delete_field( $field_key, $target );
		}
		foreach ( $desired as $field ) {
			update_field( (string) $field['key'], $field['value'], $target );
		}
	}

	private static function validate( array $requested, array $current, callable $definitions_callback ): void {
		$needs_definition = false;
		foreach ( $requested as $name => $field ) {
			$keys = is_array( $field ) ? array_keys( $field ) : [];
			sort( $keys, SORT_STRING );
			if ( ! is_string( $name ) || '' === $name || [ 'key', 'type', 'value' ] !== $keys || ! is_string( $field['key'] ) || '' === $field['key'] || ! is_string( $field['type'] ) || '' === $field['type'] ) {
				throw new RuntimeException( 'The ACF field payload is invalid.' );
			}
			if ( isset( $current[ $name ] ) ) {
				if ( $field['key'] !== $current[ $name ]['key'] || $field['type'] !== $current[ $name ]['type'] ) {
					throw new RuntimeException( 'An ACF field identity changed after the request was prepared.' );
				}
				continue;
			}
			$needs_definition = true;
		}
		if ( ! $needs_definition ) {
			return;
		}
		$definitions = $definitions_callback();
		foreach ( $requested as $name => $field ) {
			if ( isset( $current[ $name ] ) ) {
				continue;
			}
			$definition = $definitions[ $field['key'] ] ?? null;
			if ( ! is_array( $definition ) || $name !== $definition['name'] || $field['type'] !== $definition['type'] ) {
				throw new RuntimeException( 'The requested ACF field is not assigned to this WordPress target.' );
			}
		}
	}

	private static function post_definitions( int $post_id ): array {
		return self::definitions(
			[
				'post_id'   => $post_id,
				'post_type' => (string) get_post_type( $post_id ),
			]
		);
	}

	private static function taxonomy_definitions( string $taxonomy ): array {
		return self::definitions( [ 'taxonomy' => $taxonomy ] );
	}

	private static function definitions( array $filter ): array {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			throw new RuntimeException( 'ACF field definitions are unavailable on this site.' );
		}
		$result = [];
		foreach ( acf_get_field_groups( $filter ) as $group ) {
			if ( ! is_array( $group ) || empty( $group['key'] ) ) {
				continue;
			}
			$fields = acf_get_fields( (string) $group['key'] );
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) || empty( $field['key'] ) || empty( $field['name'] ) || empty( $field['type'] ) ) {
					continue;
				}
				$result[ (string) $field['key'] ] = [
					'name' => (string) $field['name'],
					'type' => (string) $field['type'],
				];
			}
		}
		return $result;
	}
}
