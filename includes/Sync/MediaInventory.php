<?php

namespace Webactueel\ElementorJsonBridge\Sync;

use Throwable;
use Webactueel\ElementorJsonBridge\GitHub\Client;
use Webactueel\ElementorJsonBridge\Media\Inventory;
use Webactueel\ElementorJsonBridge\Media\Package;
use Webactueel\ElementorJsonBridge\Settings;

defined( 'ABSPATH' ) || exit;

final class MediaInventory {
	private const CHECK_TRANSIENT = 'ejb_media_inventory_checked';

	public function __construct( private readonly Client $github ) {}

	public function register(): void {
		add_action( 'ejb_poll_remote', [ $this, 'sync' ], 25 );
		add_action( 'add_attachment', [ $this, 'invalidate' ] );
		add_action( 'edit_attachment', [ $this, 'invalidate' ] );
		add_action( 'delete_attachment', [ $this, 'invalidate' ] );
		add_filter( 'wp_update_attachment_metadata', [ $this, 'invalidate_metadata' ], 10, 2 );
	}

	public function invalidate(): void {
		delete_transient( self::CHECK_TRANSIENT );
	}

	public function invalidate_metadata( array $data, int $attachment_id ): array {
		unset( $attachment_id );
		$this->invalidate();
		return $data;
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
			$root            = (string) Settings::get( 'repo_root', 'site-data' );
			$package         = Package::build( Inventory::collect(), $root );
			$manifest_path   = (string) $package['manifest_path'];
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
					'Refresh WordPress media inventory shard'
				);
			}

			$this->github->put_file(
				$manifest_path,
				$manifest_content,
				$remote_manifest ? (string) $remote_manifest['sha'] : null,
				'Refresh WordPress media inventory manifest'
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
