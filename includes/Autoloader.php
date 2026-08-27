<?php

namespace Webactueel\ElementorJsonBridge;

defined( 'ABSPATH' ) || exit;

final class Autoloader {
	private const PREFIX = 'Webactueel\\ElementorJsonBridge\\';

	private static string $base_dir = '';

	public static function register( string $base_dir ): void {
		self::$base_dir = rtrim( $base_dir, '/\\' ) . DIRECTORY_SEPARATOR;
		spl_autoload_register( [ self::class, 'load' ] );
	}

	private static function load( string $class ): void {
		if ( ! str_starts_with( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$file     = self::$base_dir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
