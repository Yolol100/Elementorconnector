<?php

namespace Webactueel\ElementorJsonBridge\Sync;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class Lock {
	public const OPTION_PREFIX = 'ejb_lock_';
	private const TTL          = 300;

	public function acquire( int $post_id ): string {
		$option = self::option_name( $post_id );
		$token  = wp_generate_uuid4();
		$value  = wp_json_encode(
			[
				'token'      => $token,
				'expires_at' => time() + self::TTL,
			]
		);
		if ( ! is_string( $value ) ) {
			throw new RuntimeException( 'Unable to create a document lock.' );
		}

		if ( add_option( $option, $value, '', false ) ) {
			return $token;
		}

		$existing = get_option( $option, '' );
		$data     = is_string( $existing ) ? json_decode( $existing, true ) : null;
		if ( is_array( $data ) && (int) ( $data['expires_at'] ?? 0 ) < time() && $this->delete_if_unchanged( $option, $existing ) ) {
			if ( add_option( $option, $value, '', false ) ) {
				return $token;
			}
		}

		throw new RuntimeException( 'This document is already being synchronized.' );
	}

	public function release( int $post_id, string $token ): void {
		$option   = self::option_name( $post_id );
		$existing = get_option( $option, '' );
		$data     = is_string( $existing ) ? json_decode( $existing, true ) : null;
		if ( is_array( $data ) && hash_equals( (string) ( $data['token'] ?? '' ), $token ) ) {
			$this->delete_if_unchanged( $option, $existing );
		}
	}

	public static function option_name( int $post_id ): string {
		return self::OPTION_PREFIX . max( 0, $post_id );
	}

	private function delete_if_unchanged( string $option, string $expected ): bool {
		global $wpdb;

		// Atomic compare-and-delete prevents stale-lock recovery from deleting a lock acquired by another request.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress has no conditional delete_option API; this CAS is the lock's concurrency primitive.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$option,
				$expected
			)
		);

		if ( 1 === $deleted ) {
			wp_cache_delete( $option, 'options' );
			return true;
		}
		return false;
	}
}
