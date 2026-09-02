<?php

namespace Webactueel\ElementorJsonBridge\Sync;

use Throwable;
use Webactueel\ElementorJsonBridge\Elementor\Capabilities;
use Webactueel\ElementorJsonBridge\GitHub\Client;
use Webactueel\ElementorJsonBridge\Settings;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

defined( 'ABSPATH' ) || exit;

final class ElementorCapabilities {
	private const CHECK_TRANSIENT = 'ejb_elementor_capabilities_checked';

	public function __construct( private readonly Client $github ) {}

	public function register(): void {
		add_action( 'ejb_poll_remote', [ $this, 'sync' ], 20 );
		add_action( 'activated_plugin', [ $this, 'invalidate' ] );
		add_action( 'deactivated_plugin', [ $this, 'invalidate' ] );
		add_action( 'upgrader_process_complete', [ $this, 'invalidate' ], 10, 2 );
	}

	public function invalidate(): void {
		delete_transient( self::CHECK_TRANSIENT );
	}

	public function sync(): void {
		if ( get_transient( self::CHECK_TRANSIENT ) ) {
			return;
		}
		if ( ! Settings::repo_is_configured() || ! get_option( Settings::AUTH_OPTION, '' ) ) {
			return;
		}

		set_transient( self::CHECK_TRANSIENT, '1', HOUR_IN_SECONDS );

		try {
			$this->github->assert_private_repository();
			$root = (string) Settings::get( 'repo_root', 'site-data' );
			$path = trim( $root . '/elementor-capabilities.json', '/' );
			$encoded = CanonicalJson::encode( Capabilities::collect(), true );
			$remote = $this->github->get_file( $path );

			if ( $remote && hash_equals( hash( 'sha256', $encoded ), hash( 'sha256', (string) $remote['content'] ) ) ) {
				return;
			}

			$this->github->put_file(
				$path,
				$encoded,
				$remote ? (string) $remote['sha'] : null,
				'Refresh Elementor capability inventory'
			);
		} catch ( Throwable ) {
			return;
		}
	}
}
