<?php

use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;
use Webactueel\ElementorJsonBridge\Media\Inventory;
use Webactueel\ElementorJsonBridge\Media\Package;
use Webactueel\ElementorJsonBridge\Media\References;

if ( ! class_exists( Inventory::class ) || ! class_exists( References::class ) || ! class_exists( Package::class ) ) {
	WP_CLI::error( 'Media intelligence classes are unavailable.' );
}

$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAQAAABFaP0WAAAADUlEQVR42mNk+M/wHwAEAQH/7i4XWQAAAABJRU5ErkJggg==', true );
if ( ! is_string( $png ) ) {
	WP_CLI::error( 'Unable to prepare the runtime image fixture.' );
}
$upload = wp_upload_bits( 'ejb-media-runtime.png', null, $png );
if ( ! empty( $upload['error'] ) ) {
	WP_CLI::error( 'Unable to create the runtime image fixture: ' . $upload['error'] );
}

$attachment_id = wp_insert_attachment(
	[
		'post_mime_type' => 'image/png',
		'post_title'     => 'Bridge media runtime',
		'post_excerpt'   => 'Runtime caption',
		'post_content'   => 'Runtime description',
		'post_status'    => 'inherit',
	],
	$upload['file'],
	0,
	true
);
if ( is_wp_error( $attachment_id ) ) {
	WP_CLI::error( 'Unable to insert the runtime attachment.' );
}
$attachment_id = (int) $attachment_id;
update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Runtime alternative text' );

require_once ABSPATH . 'wp-admin/includes/image.php';
$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
if ( is_array( $metadata ) ) {
	wp_update_attachment_metadata( $attachment_id, $metadata );
}

$item = Inventory::assert_attachment_id( $attachment_id );
if ( $attachment_id !== (int) $item['id'] || empty( $item['url'] ) || 'image/png' !== $item['mime_type'] ) {
	WP_CLI::error( 'The runtime media inventory did not preserve WordPress attachment facts.' );
}
if ( 'Runtime alternative text' !== $item['alt'] || 'Runtime caption' !== $item['caption'] || 'Runtime description' !== $item['description'] ) {
	WP_CLI::error( 'The runtime media inventory did not preserve attachment metadata.' );
}
if ( empty( $item['fact_fingerprint'] ) || empty( $item['modified_gmt'] ) ) {
	WP_CLI::error( 'The runtime media inventory did not expose incremental-processing evidence.' );
}

$inventory = Inventory::collect();
$ids       = array_map( static fn( array $record ): int => (int) $record['id'], $inventory['items'] );
if ( ! in_array( $attachment_id, $ids, true ) || empty( $inventory['inventory_hash'] ) ) {
	WP_CLI::error( 'The runtime media inventory is incomplete.' );
}
$package = Package::build( $inventory, 'site-data' );
$manifest = json_decode( $package['manifest_content'], true, 512, JSON_THROW_ON_ERROR );
$manifest_ids = array_map( static fn( array $record ): int => (int) $record['id'], $manifest['items'] );
if ( ! in_array( $attachment_id, $manifest_ids, true ) ) {
	WP_CLI::error( 'The media manifest does not reference the runtime attachment.' );
}

References::assert_id_url( $attachment_id, (string) $item['url'] );
$mismatch_rejected = false;
try {
	References::assert_id_url( $attachment_id, 'https://example.invalid/invented.png' );
} catch ( RuntimeException ) {
	$mismatch_rejected = true;
}
if ( ! $mismatch_rejected ) {
	WP_CLI::error( 'A mismatched media ID/URL pair was not rejected.' );
}

$payload = [
	'title'         => 'Media validation',
	'type'          => 'wp-page',
	'version'       => PayloadValidator::FORMAT_VERSION,
	'page_settings' => [],
	'content'       => [
		[
			'id'         => 'media123',
			'elType'     => 'widget',
			'widgetType' => 'image',
			'settings'   => [ 'image' => [ 'id' => $attachment_id, 'url' => (string) $item['url'] ] ],
			'elements'   => [],
		],
	],
];
$validator = new PayloadValidator();
$validator->validate_array( $payload, 'wp-page' );
$payload['content'][0]['settings']['image']['url'] = 'https://example.invalid/invented.png';
$payload_mismatch_rejected = false;
try {
	$validator->validate_array( $payload, 'wp-page' );
} catch ( RuntimeException ) {
	$payload_mismatch_rejected = true;
}
if ( ! $payload_mismatch_rejected ) {
	WP_CLI::error( 'The Elementor payload validator accepted an invented media URL for an existing ID.' );
}

$before_fingerprint = $item['fact_fingerprint'];
update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Changed runtime alt' );
$changed = Inventory::assert_attachment_id( $attachment_id );
if ( hash_equals( $before_fingerprint, (string) $changed['fact_fingerprint'] ) ) {
	WP_CLI::error( 'Changed media facts did not change the media fingerprint.' );
}

wp_delete_attachment( $attachment_id, true );
$after_delete = Inventory::collect();
$after_ids    = array_map( static fn( array $record ): int => (int) $record['id'], $after_delete['items'] );
if ( in_array( $attachment_id, $after_ids, true ) ) {
	WP_CLI::error( 'Deleted media remained in the current inventory.' );
}

WP_CLI::success(
	sprintf(
		'Media inventory verified: attachment %d, %d current items, mismatch rejection, changed fingerprint and deletion handling passed.',
		$attachment_id,
		count( $inventory['items'] )
	)
);
