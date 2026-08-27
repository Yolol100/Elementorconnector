<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/Sync/LegacyRepositoryMigration.php';

use Webactueel\ElementorJsonBridge\Sync\LegacyRepositoryMigration;

$method = new ReflectionMethod( LegacyRepositoryMigration::class, 'legacy_state_matches' );
$base   = hash( 'sha256', 'trusted-base' );
$sha    = '0123456789abcdef0123456789abcdef01234567';

if ( true !== $method->invoke( null, $base, $sha, $sha, $base ) ) {
	throw new RuntimeException( 'Matching legacy state was rejected.' );
}
if ( false !== $method->invoke( null, $base, $sha, str_repeat( 'f', 40 ), $base ) ) {
	throw new RuntimeException( 'Changed remote SHA was accepted.' );
}
if ( false !== $method->invoke( null, $base, $sha, $sha, hash( 'sha256', 'changed' ) ) ) {
	throw new RuntimeException( 'Changed remote payload was accepted.' );
}
if ( false !== $method->invoke( null, '', $sha, $sha, $base ) ) {
	throw new RuntimeException( 'Incomplete legacy state was accepted.' );
}

$source = file_get_contents( dirname( __DIR__ ) . '/includes/Sync/LegacyRepositoryMigration.php' );
if ( false === $source || ! str_contains( $source, "add_action( 'admin_init'" ) || ! str_contains( $source, "add_action( 'rest_api_init'" ) || ! str_contains( $source, "add_action( 'wp_loaded'" ) ) {
	throw new RuntimeException( 'Migration execution hooks are incomplete.' );
}

echo "PASS legacy-repository-identity-migration\n";
