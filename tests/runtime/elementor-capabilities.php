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

WP_CLI::success(
	sprintf(
		'Elementor capability inventory verified: %d widgets, %d elements, %d document types, %d dynamic tags.',
		count( $inventory['widgets'] ),
		count( $inventory['elements'] ),
		count( $inventory['document_types'] ),
		count( $inventory['dynamic_tags'] )
	)
);
