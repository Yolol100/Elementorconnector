<?php

declare(strict_types=1);

use Webactueel\ElementorJsonBridge\Backup\Snapshots;
use Webactueel\ElementorJsonBridge\Elementor\Documents;
use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'This smoke test must run inside WordPress.' );
}

if ( ! class_exists( '\\Elementor\\Plugin' ) || ! defined( 'ELEMENTOR_VERSION' ) ) {
	throw new RuntimeException( 'Elementor is not active in the smoke environment.' );
}

if ( '7.1' !== get_bloginfo( 'version' ) ) {
	throw new RuntimeException( 'The smoke environment is not running the pinned WordPress 7.1 release.' );
}
if ( '4.2.3' !== ELEMENTOR_VERSION ) {
	throw new RuntimeException( 'The smoke environment is not running the pinned Elementor 4.2.3 release.' );
}
if ( 0 !== strpos( PHP_VERSION, '8.3.' ) ) {
	throw new RuntimeException( 'The smoke environment is not running the pinned PHP 8.3 runtime.' );
}
if ( ! current_user_can( 'manage_options' ) ) {
	throw new RuntimeException( 'Run the smoke test as a WordPress administrator.' );
}

$post_id     = 0;
$snapshot_id = 0;

try {
	$post_id = wp_insert_post(
		[
			'post_type'   => 'page',
			'post_status' => 'draft',
			'post_title'  => 'EJB Smoke Source',
		],
		true
	);
	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( 'Unable to create the smoke-test page.' );
	}
	$post_id = (int) $post_id;
	update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

	$documents = new Documents();
	$validator = new PayloadValidator();
	$snapshots = new Snapshots();
	$type      = $documents->document_type( $post_id );

	$payload = [
		'title'         => 'EJB Smoke Verified',
		'type'          => $type,
		'version'       => PayloadValidator::FORMAT_VERSION,
		'page_settings' => [],
		'content'       => [
			[
				'id'       => 'ejbsmoke',
				'elType'   => 'container',
				'settings' => [],
				'elements' => [],
			],
		],
	];

	$payload = $validator->validate_array( $payload, $type );
	$documents->save_payload( $post_id, $payload );
	$readback = $validator->validate_array( $documents->payload( $post_id ), $type );

	if ( ! hash_equals( CanonicalJson::hash( $payload ), CanonicalJson::hash( $readback ) ) ) {
		throw new RuntimeException( 'Elementor changed the smoke payload during the save/readback roundtrip.' );
	}
	if ( 'EJB Smoke Verified' !== get_the_title( $post_id ) ) {
		throw new RuntimeException( 'The WordPress document title did not roundtrip.' );
	}

	$snapshot_id = $snapshots->create( $post_id, $readback, 'smoke' );
	$snapshot    = $snapshots->payload( $snapshot_id, $post_id );
	if ( ! hash_equals( CanonicalJson::hash( $readback ), CanonicalJson::hash( $snapshot ) ) ) {
		throw new RuntimeException( 'The local rollback snapshot did not roundtrip.' );
	}

	WP_CLI::success(
		sprintf(
			'Elementor JSON Bridge smoke test passed on WordPress %s, Elementor %s, PHP %s.',
			get_bloginfo( 'version' ),
			ELEMENTOR_VERSION,
			PHP_VERSION
		)
	);
} finally {
	if ( $snapshot_id > 0 ) {
		wp_delete_post( $snapshot_id, true );
	}
	if ( $post_id > 0 ) {
		wp_delete_post( $post_id, true );
	}
}
