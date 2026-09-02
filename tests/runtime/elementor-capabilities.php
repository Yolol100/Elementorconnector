<?php

use Webactueel\ElementorJsonBridge\Elementor\Capabilities;

if ( ! class_exists( Capabilities::class ) ) {
	WP_CLI::error( 'Elementor capability collector is unavailable.' );
}

$inventory = Capabilities::collect();
if ( true !== ( $inventory['elementor_available'] ?? false ) ) {
	WP_CLI::error( 'Elementor was not detected by the target capability collector.' );
}
if ( ( $inventory['environment']['elementor'] ?? null ) !== ELEMENTOR_VERSION ) {
	WP_CLI::error( 'The capability inventory Elementor version does not match the runtime.' );
}
if ( empty( $inventory['widgets'] ) || ! is_array( $inventory['widgets'] ) ) {
	WP_CLI::error( 'The target capability inventory contains no registered widgets.' );
}
if ( empty( $inventory['elements'] ) || ! is_array( $inventory['elements'] ) ) {
	WP_CLI::error( 'The target capability inventory contains no registered elements.' );
}
if ( empty( $inventory['document_types'] ) || ! is_array( $inventory['document_types'] ) ) {
	WP_CLI::error( 'The target capability inventory contains no registered document types.' );
}
if ( ! isset( $inventory['dynamic_tags'] ) || ! is_array( $inventory['dynamic_tags'] ) ) {
	WP_CLI::error( 'The target capability inventory must expose a Dynamic Tags surface.' );
}
if ( ! isset( $inventory['widgets']['heading'] ) ) {
	WP_CLI::error( 'The known Core Heading widget is missing from the target inventory.' );
}
if ( 'elementor-core' !== ( $inventory['widgets']['heading']['owner'] ?? null ) ) {
	WP_CLI::error( 'The Core Heading widget owner was not identified correctly.' );
}
if ( empty( $inventory['widgets']['heading']['controls'] ) ) {
	WP_CLI::error( 'The Core Heading widget exposes no control inventory.' );
}

foreach ( [
	'experiments',
	'atomic_style_schema',
	'atomic_dynamic_tags',
	'global_classes',
	'variables',
	'components',
	'interactions',
] as $surface ) {
	if ( ! isset( $inventory[ $surface ] ) || ! is_array( $inventory[ $surface ] ) ) {
		WP_CLI::error( 'The target capability inventory is missing the ' . $surface . ' surface.' );
	}
}

if ( empty( $inventory['experiments'] ) ) {
	WP_CLI::error( 'The target inventory contains no registered Elementor experiment/capability states.' );
}
if ( empty( $inventory['atomic_style_schema'] ) ) {
	WP_CLI::error( 'The Atomic style schema is missing from the target inventory.' );
}

$atomic_record = null;
foreach ( array_merge( $inventory['widgets'], $inventory['elements'] ) as $record ) {
	if ( true !== ( $record['atomic'] ?? false ) ) {
		continue;
	}
	if ( empty( $record['atomic_config']['atomic_props_schema'] ) || empty( $record['atomic_config']['atomic_controls'] ) ) {
		continue;
	}
	$atomic_record = $record;
	break;
}
if ( null === $atomic_record ) {
	WP_CLI::error( 'No registered Atomic component exposed both its props schema and Atomic controls.' );
}
if ( ! array_key_exists( 'allowed_child_types', $atomic_record['atomic_config'] ) ) {
	WP_CLI::error( 'Atomic child-type capability evidence is missing.' );
}

$atomic_feature_seen = false;
foreach ( $inventory['experiments'] as $name => $feature ) {
	if ( str_contains( (string) $name, 'atomic' ) && true === ( $feature['active'] ?? false ) ) {
		$atomic_feature_seen = true;
		break;
	}
}
if ( ! $atomic_feature_seen ) {
	WP_CLI::error( 'No active Atomic experiment/capability was recorded.' );
}

WP_CLI::success(
	sprintf(
		'Elementor capability inventory verified: %d widgets, %d elements, %d document types, %d Dynamic Tags, %d Atomic style props, %d Classes, %d Variables, %d Components.',
		count( $inventory['widgets'] ),
		count( $inventory['elements'] ),
		count( $inventory['document_types'] ),
		count( $inventory['dynamic_tags'] ),
		count( $inventory['atomic_style_schema'] ),
		count( $inventory['global_classes'] ),
		count( $inventory['variables'] ),
		count( $inventory['components'] )
	)
);
