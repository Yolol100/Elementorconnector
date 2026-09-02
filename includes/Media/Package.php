<?php

namespace Webactueel\ElementorJsonBridge\Media;

use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

defined( 'ABSPATH' ) || exit;

final class Package {
	public static function build( array $inventory, string $root ): array {
		$root = trim( $root, '/' );
		$base = ( '' !== $root ? $root . '/' : '' ) . 'media';
		$items = isset( $inventory['items'] ) && is_array( $inventory['items'] ) ? $inventory['items'] : [];
		$shards = [];
		$manifest_items = [];

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) || empty( $item['fact_fingerprint'] ) ) {
				continue;
			}
			$id          = (int) $item['id'];
			$fingerprint = (string) $item['fact_fingerprint'];
			$path        = sprintf( '%s/items/%d-%s.json', $base, $id, $fingerprint );
			$shards[ $path ] = CanonicalJson::encode(
				[
					'schema_version'  => Inventory::SCHEMA_VERSION,
					'wordpress_facts' => $item,
				],
				true
			);
			$manifest_items[] = [
				'id'               => $id,
				'url'              => (string) ( $item['url'] ?? '' ),
				'mime_type'        => (string) ( $item['mime_type'] ?? '' ),
				'width'            => (int) ( $item['width'] ?? 0 ),
				'height'           => (int) ( $item['height'] ?? 0 ),
				'aspect_ratio'     => $item['aspect_ratio'] ?? null,
				'modified_gmt'     => (string) ( $item['modified_gmt'] ?? '' ),
				'fact_fingerprint' => $fingerprint,
				'shard'            => $path,
			];
		}

		usort( $manifest_items, static fn( array $a, array $b ): int => $a['id'] <=> $b['id'] );
		ksort( $shards, SORT_STRING );
		$manifest = [
			'schema_version' => Inventory::SCHEMA_VERSION,
			'inventory_hash' => (string) ( $inventory['inventory_hash'] ?? CanonicalJson::hash( $items ) ),
			'item_count'     => count( $manifest_items ),
			'items'          => $manifest_items,
			'analysis'       => [
				'provider'              => 'external',
				'identity_authority'    => 'wordpress',
				'plugin_ai_dependency'  => false,
				'live_revalidation'     => true,
			],
		];

		return [
			'manifest_path'    => $base . '/media-inventory.json',
			'manifest_content' => CanonicalJson::encode( $manifest, true ),
			'shards'           => $shards,
		];
	}
}
