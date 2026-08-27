<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/Sync/Manager.php';

use Webactueel\ElementorJsonBridge\Sync\Manager;

$method = new ReflectionMethod( Manager::class, 'legacy_repository_state_matches' );
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

$source = file_get_contents( dirname( __DIR__ ) . '/includes/Sync/Manager.php' );
if ( false === $source || ! str_contains( $source, 'migrate_legacy_repository_identity' ) || ! str_contains( $source, 'State::META_REPO_ID, $current_identity' ) ) {
	throw new RuntimeException( 'Legacy repository migration hook is missing.' );
}

echo "PASS legacy-repository-identity-migration\n";
