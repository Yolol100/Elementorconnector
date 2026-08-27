<?php

namespace Webactueel\ElementorJsonBridge\Sync;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class Lock {
	private const META_KEY = '_ejb_lock';
	private const TTL      = 120;

	public function acquire( int $post_id ): string {
		$token = wp_generate_uuid4();
		$value = wp_json_encode( [ 'token' => $token, 'created_at' => time() ] );
		if ( ! is_string( $value ) ) {
			throw new RuntimeException( 'Unable to create a document lock.' );
		}

		if ( add_post_meta( $post_id, self::META_KEY, $value, true ) ) {
			return $token;
		}

		$existing = get_post_meta( $post_id, self::META_KEY, true );
		$data     = is_string( $existing ) ? json_decode( $existing, true ) : null;
		if ( is_array( $data ) && time() - (int) ( $data['created_at'] ?? time() ) > self::TTL ) {
			delete_post_meta( $post_id, self::META_KEY, $existing );
			if ( add_post_meta( $post_id, self::META_KEY, $value, true ) ) {
				return $token;
			}
		}

		throw new RuntimeException( 'This document is already being synchronized.' );
	}

	public function release( int $post_id, string $token ): void {
		$existing = get_post_meta( $post_id, self::META_KEY, true );
		$data     = is_string( $existing ) ? json_decode( $existing, true ) : null;
		if ( is_array( $data ) && hash_equals( (string) ( $data['token'] ?? '' ), $token ) ) {
			delete_post_meta( $post_id, self::META_KEY, $existing );
		}
	}
}
