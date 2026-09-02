<?php

define( 'ABSPATH', __DIR__ . '/' );

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

require_once dirname( __DIR__ ) . '/includes/Support/CanonicalJson.php';
require_once dirname( __DIR__ ) . '/includes/Elementor/CapabilityPackage.php';

$widgets = [];
for ( $widget_index = 0; $widget_index < 60; ++$widget_index ) {
	$controls = [];
	for ( $control_index = 0; $control_index < 100; ++$control_index ) {
		$controls[] = [
			'name'               => sprintf( 'control_%03d_%s', $control_index, str_repeat( 'x', 24 ) ),
			'type'               => 'text',
			'responsive'         => 0 === $control_index % 3,
			'responsive_devices' => 0 === $control_index % 3 ? [ 'tablet', 'mobile' ] : [],
			'dynamic_active'     => 0 === $control_index % 5,
		];
	}
	$widgets[ sprintf( 'widget-%03d', $widget_index ) ] = [
		'name'        => sprintf( 'widget-%03d', $widget_index ),
		'title'       => sprintf( 'Widget %03d', $widget_index ),
		'owner'       => 'elementor-core',
		'plugin_slug' => 'elementor',
		'categories'  => [ 'basic' ],
		'controls'    => $controls,
	];
}

$atomic_config = [
	'atomic_controls' => [
		[ 'type' => 'control', 'value' => [ 'type' => 'text', 'bind' => 'content' ] ],
	],
	'atomic_props_schema' => [
		'content' => [ 'type' => 'string', 'settings' => [ 'enum' => array_fill( 0, 100, str_repeat( 'p', 80 ) ) ] ],
	],
	'atomic_style_states' => [ 'normal', 'hover' ],
	'atomic_pseudo_states' => [ 'hover', 'focus' ],
	'allowed_child_types' => [ 'e-heading', 'e-paragraph' ],
];

$global_classes = [];
$variables = [];
$components = [];
for ( $index = 0; $index < 40; ++$index ) {
	$class_id = sprintf( 'e-gc-%03d', $index );
	$global_classes[ $class_id ] = [
		'id'     => $class_id,
		'label'  => 'Class ' . $index,
		'type'   => 'class',
		'styles' => [ 'base' => [ 'variants' => array_fill( 0, 30, [ 'props' => str_repeat( 's', 200 ) ] ) ] ],
	];
	$variable_id = sprintf( 'e-gv-%03d', $index );
	$variables[ $variable_id ] = [
		'id'    => $variable_id,
		'label' => 'Variable ' . $index,
		'type'  => 'global-variable',
		'value' => str_repeat( 'v', 300 ),
	];
	$component_id = sprintf( 'component-%03d', $index );
	$components[ $component_id ] = [
		'id'          => $index + 1,
		'uid'         => $component_id,
		'title'       => 'Component ' . $index,
		'is_archived' => false,
		'styles'      => array_fill( 0, 25, [ 'props' => str_repeat( 'c', 200 ) ] ),
	];
}

$inventory = [
	'format'              => 'elementor-json-bridge/elementor-capabilities',
	'version'             => 1,
	'elementor_available' => true,
	'environment'         => [ 'elementor' => '4.2.4' ],
	'widgets'             => $widgets,
	'elements'            => [
		'container' => [
			'name'        => 'container',
			'owner'       => 'elementor-core',
			'plugin_slug' => 'elementor',
			'atomic'      => false,
			'controls'    => [ [ 'name' => 'content_width', 'type' => 'select' ] ],
		],
		'e-heading' => [
			'name'          => 'e-heading',
			'owner'         => 'elementor-core',
			'plugin_slug'   => 'elementor',
			'atomic'        => true,
			'controls'      => [],
			'atomic_config' => $atomic_config,
		],
	],
	'document_types'      => [
		'page' => [ 'title' => 'Page' ],
	],
	'dynamic_tags'        => [],
	'experiments'         => [
		'e_atomic_elements' => [ 'name' => 'e_atomic_elements', 'active' => true ],
	],
	'atomic_style_schema' => [
		'width' => [ 'type' => 'size', 'settings' => [ 'units' => [ 'px', '%', 'rem' ] ] ],
	],
	'atomic_dynamic_tags' => [
		'post-title' => [ 'type' => 'string', 'categories' => [ 'text' ] ],
	],
	'global_classes'      => $global_classes,
	'variables'           => $variables,
	'components'          => $components,
	'interactions'        => [ 'constants' => [ 'effects' => array_fill( 0, 20, str_repeat( 'i', 100 ) ) ] ],
	'warnings'            => [],
];

$package = \Webactueel\ElementorJsonBridge\Elementor\CapabilityPackage::build( $inventory, 'site-data' );
$repeat  = \Webactueel\ElementorJsonBridge\Elementor\CapabilityPackage::build( $inventory, 'site-data' );

assert_true( $package === $repeat, 'Capability package generation must be deterministic.' );
assert_true( strlen( $package['manifest_content'] ) < 900000, 'Capability manifest exceeds the safe boundary.' );
assert_true( count( $package['shards'] ) > 1, 'The large synthetic inventory should be split into multiple shards.' );

$manifest = json_decode( $package['manifest_content'], true, 512, JSON_THROW_ON_ERROR );
assert_true( isset( $manifest['inventory_sha256'] ), 'Capability manifest must include the inventory fingerprint.' );
assert_true( isset( $manifest['widgets']['widget-000']['shard'] ), 'Widget summary must point to its detail shard.' );
assert_true( ! isset( $manifest['widgets']['widget-000']['controls'] ), 'Large control details must not be duplicated into the manifest.' );
assert_true( isset( $manifest['elements']['container']['shard'] ), 'Element summary must point to its detail shard.' );
assert_true( isset( $manifest['elements']['e-heading']['shard'] ), 'Atomic element summary must point to its detail shard.' );
assert_true( ! isset( $manifest['elements']['e-heading']['atomic_config'] ), 'Atomic config details must not be duplicated into the manifest.' );
assert_true( isset( $manifest['global_classes']['e-gc-000']['shard'] ), 'Global Class summary must point to its detail shard.' );
assert_true( ! isset( $manifest['global_classes']['e-gc-000']['styles'] ), 'Global Class styles must remain in detail shards.' );
assert_true( isset( $manifest['variables']['e-gv-000']['shard'] ), 'Variable summary must point to its detail shard.' );
assert_true( isset( $manifest['components']['component-000']['shard'] ), 'Component summary must point to its detail shard.' );
assert_true( ! isset( $manifest['components']['component-000']['styles'] ), 'Component styles must remain in detail shards.' );

$atomic_shard_path = $manifest['elements']['e-heading']['shard'];
assert_true( isset( $package['shards'][ $atomic_shard_path ] ), 'Manifest Atomic shard reference must resolve.' );
$atomic_shard = json_decode( $package['shards'][ $atomic_shard_path ], true, 512, JSON_THROW_ON_ERROR );
assert_true( ! empty( $atomic_shard['records']['e-heading']['atomic_config']['atomic_props_schema'] ), 'Atomic shard must preserve the full props schema.' );

$class_shard_path = $manifest['global_classes']['e-gc-000']['shard'];
$class_shard = json_decode( $package['shards'][ $class_shard_path ], true, 512, JSON_THROW_ON_ERROR );
assert_true( ! empty( $class_shard['records']['e-gc-000']['styles'] ), 'Global Class shard must preserve styles.' );

$widget_shard_path = $manifest['widgets']['widget-000']['shard'];
assert_true( isset( $package['shards'][ $widget_shard_path ] ), 'Manifest widget shard reference must resolve.' );
$widget_shard = json_decode( $package['shards'][ $widget_shard_path ], true, 512, JSON_THROW_ON_ERROR );
assert_true( ! empty( $widget_shard['records']['widget-000']['controls'] ), 'Widget shard must preserve full control details.' );
assert_true( $widget_shard['inventory_sha256'] === $manifest['inventory_sha256'], 'Shard and manifest must bind to the same inventory fingerprint.' );

foreach ( $package['shards'] as $path => $content ) {
	assert_true( strlen( $content ) <= 500000, 'Capability shard exceeds the safe boundary: ' . $path );
	assert_true( str_contains( $path, substr( $manifest['inventory_sha256'], 0, 16 ) ), 'Shard path must be versioned by the inventory fingerprint.' );
}

echo "capability-package: ok\n";
