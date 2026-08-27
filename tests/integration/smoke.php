<?php

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

	// Seed Elementor once with a minimal document. Elementor is allowed to normalize
	// this seed because real bridge workflows always start from a subsequent export.
	$seed = $validator->validate_array(
		[
			'title'         => 'EJB Smoke Baseline',
			'type'          => $type,
			'version'       => PayloadValidator::FORMAT_VERSION,
			'page_settings' => [],
			'content'       => [
				[
					'id'       => 'ejbcontainer',
					'elType'   => 'container',
					'settings' => [],
					'elements' => [
						[
							'id'         => 'ejbheading',
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => [ 'title' => 'Bridge baseline heading' ],
							'elements'   => [],
						],
					],
				],
			],
		],
		$type
	);
	$documents->save_payload( $post_id, $seed );

	// The actual synchronization contract starts here: use Elementor's own exported,
	// persisted representation as the trusted base and then edit that JSON.
	$baseline      = $validator->validate_array( $documents->payload( $post_id ), $type );
	$baseline_hash = CanonicalJson::hash( $baseline );
	$documents->save_payload( $post_id, $baseline );
	$stable_baseline = $validator->validate_array( $documents->payload( $post_id ), $type );
	if ( ! hash_equals( $baseline_hash, CanonicalJson::hash( $stable_baseline ) ) ) {
		throw new RuntimeException( 'An Elementor-exported document is not stable across an unchanged save/readback.' );
	}

	$snapshot_id = $snapshots->create( $post_id, $baseline, 'smoke' );
	$snapshot    = $snapshots->payload( $snapshot_id, $post_id );
	if ( ! hash_equals( $baseline_hash, CanonicalJson::hash( $snapshot ) ) ) {
		throw new RuntimeException( 'The local rollback snapshot did not preserve the exported baseline.' );
	}

	$edited          = $baseline;
	$edited['title'] = 'EJB Smoke Verified';
	$heading_changed = false;
	$edit_heading    = static function ( array &$elements ) use ( &$edit_heading, &$heading_changed ): void {
		foreach ( $elements as &$element ) {
			if ( 'ejbheading' === ( $element['id'] ?? '' ) ) {
				$element['settings']['title'] = 'Bridge remote edit verified';
				$heading_changed              = true;
				return;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$edit_heading( $element['elements'] );
				if ( $heading_changed ) {
					return;
				}
			}
		}
	};
	$edit_heading( $edited['content'] );
	if ( ! $heading_changed ) {
		throw new RuntimeException( 'The seeded heading was not present in Elementor export data.' );
	}
	$edited = $validator->validate_array( $edited, $type );

	$documents->save_payload( $post_id, $edited );
	$readback = $validator->validate_array( $documents->payload( $post_id ), $type );
	if ( ! hash_equals( CanonicalJson::hash( $edited ), CanonicalJson::hash( $readback ) ) ) {
		throw new RuntimeException( 'An edited Elementor-exported document changed during save/readback.' );
	}
	if ( 'EJB Smoke Verified' !== get_the_title( $post_id ) ) {
		throw new RuntimeException( 'The WordPress document title did not roundtrip.' );
	}

	// Exercise the same persisted snapshot payload used by rollback and verify exact restore.
	$documents->save_payload( $post_id, $snapshot );
	$restored = $validator->validate_array( $documents->payload( $post_id ), $type );
	if ( ! hash_equals( $baseline_hash, CanonicalJson::hash( $restored ) ) ) {
		throw new RuntimeException( 'The Elementor rollback snapshot did not restore exactly.' );
	}

	WP_CLI::success(
		sprintf(
			'Elementor JSON Bridge export-edit-readback-rollback smoke passed on WordPress %s, Elementor %s, PHP %s.',
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
