<?php

use Webactueel\ElementorJsonBridge\Content\AbilityBridge;

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress was not bootstrapped.' );
}
if ( ! defined( 'EJB_VERSION' ) ) {
	throw new RuntimeException( 'Elementor JSON Bridge is not active.' );
}
if ( ! defined( 'WPSEO_VERSION' ) ) {
	throw new RuntimeException( 'Yoast SEO is not active.' );
}
if ( ! function_exists( 'wp_get_abilities' ) ) {
	throw new RuntimeException( 'The WordPress Abilities API is unavailable.' );
}

$catalog    = ( new AbilityBridge() )->catalog();
$available  = is_array( $catalog['abilities'] ?? null ) ? $catalog['abilities'] : [];
$registered = [];

foreach ( wp_get_abilities() as $ability_name => $ability ) {
	$ability_name = is_string( $ability_name ) ? $ability_name : ( is_object( $ability ) && method_exists( $ability, 'get_name' ) ? (string) $ability->get_name() : '' );
	if ( ! str_starts_with( $ability_name, 'yoast-seo/' ) || ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) {
		continue;
	}
	$meta    = $ability->get_meta();
	$exposed = is_array( $meta ) && ( true === ( $meta['public'] ?? false ) || true === ( $meta['show_in_rest'] ?? false ) || ( is_array( $meta['mcp'] ?? null ) && true === ( $meta['mcp']['public'] ?? false ) ) );
	if ( $exposed ) {
		$registered[] = $ability_name;
	}
}

$catalogued = [];
foreach ( array_keys( $available ) as $ability_name ) {
	if ( str_starts_with( $ability_name, 'yoast-seo/' ) ) {
		$catalogued[] = $ability_name;
	}
}

sort( $registered, SORT_STRING );
sort( $catalogued, SORT_STRING );
if ( $registered !== $catalogued ) {
	throw new RuntimeException( 'The bridge Yoast ability catalog does not match the abilities registered by the live Yoast runtime.' );
}

fwrite( STDOUT, "PASS yoast-ability-parity\n" );
