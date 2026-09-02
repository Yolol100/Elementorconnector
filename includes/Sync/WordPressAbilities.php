<?php

namespace Webactueel\ElementorJsonBridge\Sync;

use Throwable;
use Webactueel\ElementorJsonBridge\Content\AbilityBridge;
use Webactueel\ElementorJsonBridge\GitHub\Client;
use Webactueel\ElementorJsonBridge\Settings;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

defined( 'ABSPATH' ) || exit;

final class WordPressAbilities {
	private const CHECK_TRANSIENT = 'ejb_wordpress_abilities_checked';
	private const MAX_BYTES       = 1000000;

	public function __construct(
		private readonly AbilityBridge $abilities,
		private readonly Client $github
	) {}

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
			$root    = (string) Settings::get( 'repo_root', 'site-data' );
			$path    = trim( $root . '/abilities.json', '/' );
			$catalog = $this->abilities->catalog();
			$payload = [
				'format'                  => 'elementor-json-bridge/ability-catalog',
				'version'                 => 1,
				'wordpress_version'       => (string) get_bloginfo( 'version' ),
				'abilities_api_available' => (bool) ( $catalog['available'] ?? false ),
				'abilities'               => is_array( $catalog['abilities'] ?? null ) ? $catalog['abilities'] : [],
			];
			$content = CanonicalJson::encode( $payload, true );
			if ( self::MAX_BYTES < strlen( $content ) ) {
				throw new \RuntimeException( 'The WordPress ability catalog is larger than 1 MB.' );
			}
			$remote = $this->github->get_file( $path );
			if ( $remote && hash_equals( hash( 'sha256', $content ), hash( 'sha256', (string) $remote['content'] ) ) ) {
				set_transient( self::CHECK_TRANSIENT, '1', HOUR_IN_SECONDS );
				return;
			}
			$this->github->put_file( $path, $content, $remote ? (string) $remote['sha'] : null, 'Refresh WordPress ability catalog' );
			set_transient( self::CHECK_TRANSIENT, '1', HOUR_IN_SECONDS );
		} catch ( Throwable ) {
			set_transient( self::CHECK_TRANSIENT, '1', 5 * MINUTE_IN_SECONDS );
		}
	}
}
