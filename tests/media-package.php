<?php

define( 'ABSPATH', __DIR__ . '/' );

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

require_once dirname( __DIR__ ) . '/includes/Support/CanonicalJson.php';
require_once dirname( __DIR__ ) . '/includes/Media/Inventory.php';
require_once dirname( __DIR__ ) . '/includes/Media/Package.php';

$base = [
	'schema_version' => '1.0',
	'inventory_hash' => str_repeat( 'a', 64 ),
	'items'          => [
		[
			'id'               => 10,
			'url'              => 'https://example.test/uploads/a.jpg',
			'filename'         => 'a.jpg',
			'title'            => 'A',
			'alt'              => 'A image',
			'caption'          => '',
			'description'      => '',
			'mime_type'        => 'image/jpeg',
			'filesize'         => 1000,
			'width'            => 1600,
			'height'           => 900,
			'aspect_ratio'     => 1.777778,
			'sizes'            => [],
			'modified_gmt'     => '2026-09-01T10:00:00Z',
			'fact_fingerprint' => str_repeat( 'b', 64 ),
		],
		[
			'id'               => 11,
			'url'              => 'https://example.test/uploads/b.jpg',
			'filename'         => 'b.jpg',
			'title'            => 'B',
			'alt'              => '',
			'caption'          => '',
			'description'      => '',
			'mime_type'        => 'image/jpeg',
			'filesize'         => 2000,
			'width'            => 1200,
			'height'           => 1200,
			'aspect_ratio'     => 1.0,
			'sizes'            => [],
			'modified_gmt'     => '2026-09-01T11:00:00Z',
			'fact_fingerprint' => str_repeat( 'c', 64 ),
		],
	],
];

$package = \Webactueel\ElementorJsonBridge\Media\Package::build( $base, 'site-data' );
$repeat  = \Webactueel\ElementorJsonBridge\Media\Package::build( $base, 'site-data' );
assert_true( $package === $repeat, 'Media package generation must be deterministic.' );
assert_true( 2 === count( $package['shards'] ), 'Each media fact record must have one immutable shard.' );

$manifest = json_decode( $package['manifest_content'], true, 512, JSON_THROW_ON_ERROR );
assert_true( 'site-data/media/media-inventory.json' === $package['manifest_path'], 'Unexpected media manifest path.' );
assert_true( 2 === $manifest['item_count'], 'Media manifest count is incorrect.' );
assert_true( false === $manifest['analysis']['plugin_ai_dependency'], 'The WordPress plugin must not depend on an AI provider.' );
assert_true( true === $manifest['analysis']['live_revalidation'], 'The media contract must require live revalidation.' );

$first_path = $manifest['items'][0]['shard'];
assert_true( isset( $package['shards'][ $first_path ] ), 'Media manifest shard reference must resolve.' );
$first = json_decode( $package['shards'][ $first_path ], true, 512, JSON_THROW_ON_ERROR );
assert_true( 10 === $first['wordpress_facts']['id'], 'Media shard must preserve WordPress attachment identity.' );

$changed = $base;
$changed['items'][0]['title']            = 'A changed';
$changed['items'][0]['fact_fingerprint'] = str_repeat( 'd', 64 );
$changed['inventory_hash']               = str_repeat( 'e', 64 );
$changed_package = \Webactueel\ElementorJsonBridge\Media\Package::build( $changed, 'site-data' );
$changed_manifest = json_decode( $changed_package['manifest_content'], true, 512, JSON_THROW_ON_ERROR );
assert_true( $changed_manifest['items'][0]['shard'] !== $manifest['items'][0]['shard'], 'Changed media must get a new immutable shard path.' );
assert_true( $changed_manifest['items'][1]['shard'] === $manifest['items'][1]['shard'], 'Unchanged media must retain its shard path.' );

$deleted = $base;
array_pop( $deleted['items'] );
$deleted['inventory_hash'] = str_repeat( 'f', 64 );
$deleted_package  = \Webactueel\ElementorJsonBridge\Media\Package::build( $deleted, 'site-data' );
$deleted_manifest = json_decode( $deleted_package['manifest_content'], true, 512, JSON_THROW_ON_ERROR );
assert_true( 1 === $deleted_manifest['item_count'], 'Deleted media must disappear from the current manifest.' );

echo "media-package: ok\n";
