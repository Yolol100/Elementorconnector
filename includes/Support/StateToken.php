<?php

namespace Webactueel\ElementorJsonBridge\Support;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class StateToken {
	public static function issue( array $state ): string {
		return hash_hmac( 'sha256', CanonicalJson::encode( $state ), wp_salt( 'auth' ) );
	}

	public static function assert_matches( mixed $expected, array $state ): void {
		if ( ! is_string( $expected ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected ) ) {
			throw new RuntimeException( 'A valid expected_state_token is required for this request.' );
		}
		if ( ! hash_equals( self::issue( $state ), $expected ) ) {
			throw new RuntimeException( 'The target changed after this request was prepared. Read the current state and create a fresh request.' );
		}
	}
}
