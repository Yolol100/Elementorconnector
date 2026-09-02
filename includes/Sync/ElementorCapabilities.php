<?php

namespace Webactueel\ElementorJsonBridge\Sync;

use Throwable;
use Webactueel\ElementorJsonBridge\Elementor\Capabilities;
use Webactueel\ElementorJsonBridge\Elementor\CapabilityPackage;
use Webactueel\ElementorJsonBridge\GitHub\Client;
use Webactueel\ElementorJsonBridge\Settings;

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

		try {
			$this->github->assert_private_repository();
			$root = (string) Settings::get( 'repo_root', 'site-data' );
			$package = CapabilityPackage::build( Capabilities::collect(), $root );
			$manifest_path = (string) $package['manifest_path'];
			$manifest_content = (string) $package['manifest_content'];
			$remote_manifest = $this->github->get_file( $manifest_path );

			if ( self::same_content( $remote_manifest, $manifest_content ) ) {
				set_transient( self::CHECK_TRANSIENT, '1', HOUR_IN_SECONDS );
				return;
			}

			foreach ( $package['shards'] as $path => $content ) {
				$remote = $this->github->get_file( (string) $path );
				if ( self::same_content( $remote, (string) $content ) ) {
					continue;
				}
				$this->github->put_file(
					(string) $path,
					(string) $content,
					$remote ? (string) $remote['sha'] : null,
					'Refresh Elementor capability shard'
				);
			}

			$this->github->put_file(
				$manifest_path,
				$manifest_content,
				$remote_manifest ? (string) $remote_manifest['sha'] : null,
				'Refresh Elementor capability manifest'
			);
			set_transient( self::CHECK_TRANSIENT, '1', HOUR_IN_SECONDS );
		} catch ( Throwable ) {
			set_transient( self::CHECK_TRANSIENT, '1', 5 * MINUTE_IN_SECONDS );
		}
	}

	private static function same_content( ?array $remote, string $content ): bool {
		return null !== $remote
			&& hash_equals( hash( 'sha256', $content ), hash( 'sha256', (string) $remote['content'] ) );
	}
}
