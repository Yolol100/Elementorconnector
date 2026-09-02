<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_PLUGIN_DIR', '/tmp/plugins' );

function get_bloginfo( string $field ): string {
	return 'version' === $field ? '7.1' : '';
}

function wp_get_theme(): object {
	return new class() {
		public function get( string $field ): string {
			return 'Name' === $field ? 'Test Theme' : ( 'Version' === $field ? '1.0.0' : '' );
		}
	};
}

function get_plugins(): array {
	return [
		'elementor/elementor.php' => [
			'Name'    => 'Elementor',
			'Version' => '4.2.4',
		],
	];
}

function get_option( string $name, mixed $default = false ): mixed {
	return 'active_plugins' === $name ? [ 'elementor/elementor.php' ] : $default;
}

function wp_strip_all_tags( string $value ): string {
	return strip_tags( $value );
}

function did_action( string $hook ): int {
	return 0;
}

function assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

function assert_contains( string $needle, string $haystack, string $message ): void {
	if ( ! str_contains( $haystack, $needle ) ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

require_once dirname( __DIR__ ) . '/includes/Elementor/Capabilities.php';

$inventory = \Webactueel\ElementorJsonBridge\Elementor\Capabilities::collect();
assert_same( 'elementor-json-bridge/elementor-capabilities', $inventory['format'] ?? null, 'Unexpected capability inventory format.' );
assert_same( 1, $inventory['version'] ?? null, 'Unexpected capability inventory version.' );
assert_same( false, $inventory['elementor_available'] ?? null, 'Elementor should be unavailable in the zero-dependency test.' );
assert_same( '4.2.4', $inventory['environment']['active_plugins'][0]['version'] ?? null, 'Active plugin versions must be exported.' );
assert_same( [], $inventory['widgets'] ?? null, 'Widgets must fail closed when Elementor is unavailable.' );
assert_same( [], $inventory['elements'] ?? null, 'Elements must fail closed when Elementor is unavailable.' );
assert_same( [], $inventory['document_types'] ?? null, 'Document types must fail closed when Elementor is unavailable.' );
assert_same( [], $inventory['dynamic_tags'] ?? null, 'Dynamic Tags must fail closed when Elementor is unavailable.' );

$sync_source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Sync/ElementorCapabilities.php' );
assert_contains( 'assert_private_repository', $sync_source, 'Capability synchronization must keep the private-repository gate.' );
assert_contains( 'CapabilityPackage::build', $sync_source, 'Capability synchronization must use the bounded package builder.' );
assert_contains( 'ejb_poll_remote', $sync_source, 'Capability synchronization must reuse the existing bounded polling hook.' );
assert_contains( 'HOUR_IN_SECONDS', $sync_source, 'Capability scans must be throttled between ordinary polling cycles.' );
assert_contains( 'activated_plugin', $sync_source, 'Plugin activation must invalidate the capability scan throttle.' );
assert_contains( 'deactivated_plugin', $sync_source, 'Plugin deactivation must invalidate the capability scan throttle.' );
assert_contains( 'upgrader_process_complete', $sync_source, 'Plugin updates must invalidate the capability scan throttle.' );

$package_source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Elementor/CapabilityPackage.php' );
assert_contains( '/elementor-capabilities.json', $package_source, 'Capability packaging must use the documented manifest path.' );
assert_contains( 'MAX_SHARD_BYTES', $package_source, 'Capability packaging must enforce a shard size boundary.' );
assert_contains( 'inventory_sha256', $package_source, 'Capability packaging must bind shards to the complete inventory fingerprint.' );

$plugin_source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Plugin.php' );
assert_contains( 'new ElementorCapabilities( $github )', $plugin_source, 'The capability sync service must be registered by the plugin bootstrap.' );
assert_contains( '$capability_sync->register()', $plugin_source, 'The capability sync service registration is missing.' );

echo "elementor-capabilities: ok\n";
