<?php

namespace Webactueel\ElementorJsonBridge\Elementor;

use RuntimeException;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

defined( 'ABSPATH' ) || exit;

final class CapabilityPackage {
	private const MAX_SHARD_BYTES  = 500000;
	private const MAX_MANIFEST_BYTES = 900000;
	private const SURFACES = [
		'widgets',
		'elements',
		'document_types',
		'dynamic_tags',
	];

	public static function build( array $inventory, string $root ): array {
		$root           = trim( $root, '/' );
		$inventory_json = CanonicalJson::encode( $inventory );
		$inventory_hash = hash( 'sha256', $inventory_json );
		$version_root   = $root . '/elementor-capabilities/' . substr( $inventory_hash, 0, 16 );
		$manifest       = $inventory;
		$manifest['inventory_sha256'] = $inventory_hash;
		$manifest['capability_shards'] = [];
		$shards = [];

		foreach ( self::SURFACES as $surface ) {
			$records = is_array( $inventory[ $surface ] ?? null ) ? $inventory[ $surface ] : [];
			ksort( $records, SORT_STRING );
			$manifest[ $surface ] = [];
			$manifest['capability_shards'][ $surface ] = [];

			foreach ( self::split_surface( $surface, $records, $version_root, $inventory_hash ) as $shard ) {
				$path = $shard['path'];
				$shards[ $path ] = $shard['content'];
				$manifest['capability_shards'][ $surface ][] = [
					'path'    => $path,
					'sha256'  => hash( 'sha256', $shard['content'] ),
					'records' => count( $shard['records'] ),
				];

				foreach ( array_keys( $shard['records'] ) as $name ) {
					$record = $records[ $name ];
					if ( is_array( $record ) ) {
						unset( $record['controls'] );
						$record['shard'] = $path;
					}
					$manifest[ $surface ][ $name ] = $record;
				}
			}
		}

		$manifest_content = CanonicalJson::encode( $manifest, true );
		if ( strlen( $manifest_content ) > self::MAX_MANIFEST_BYTES ) {
			throw new RuntimeException( 'Elementor capability manifest exceeds the safe GitHub size boundary.' );
		}

		return [
			'inventory_sha256' => $inventory_hash,
			'manifest_path'    => $root . '/elementor-capabilities.json',
			'manifest_content' => $manifest_content,
			'shards'           => $shards,
		];
	}

	private static function split_surface( string $surface, array $records, string $version_root, string $inventory_hash ): array {
		if ( [] === $records ) {
			return [];
		}

		$shards  = [];
		$current = [];
		$index   = 0;

		foreach ( $records as $name => $record ) {
			$candidate = $current;
			$candidate[ (string) $name ] = $record;
			$candidate_content = self::encode_shard( $surface, $candidate, $inventory_hash );

			if ( [] !== $current && strlen( $candidate_content ) > self::MAX_SHARD_BYTES ) {
				$shards[] = self::finalize_shard( $surface, $current, $version_root, $inventory_hash, $index );
				++$index;
				$current = [ (string) $name => $record ];
				if ( strlen( self::encode_shard( $surface, $current, $inventory_hash ) ) > self::MAX_SHARD_BYTES ) {
					throw new RuntimeException( 'One Elementor capability record exceeds the safe shard size boundary.' );
				}
				continue;
			}

			$current = $candidate;
		}

		if ( [] !== $current ) {
			$shards[] = self::finalize_shard( $surface, $current, $version_root, $inventory_hash, $index );
		}

		return $shards;
	}

	private static function finalize_shard( string $surface, array $records, string $version_root, string $inventory_hash, int $index ): array {
		return [
			'path'    => sprintf( '%s/%s-%03d.json', $version_root, $surface, $index ),
			'content' => self::encode_shard( $surface, $records, $inventory_hash ),
			'records' => $records,
		];
	}

	private static function encode_shard( string $surface, array $records, string $inventory_hash ): string {
		return CanonicalJson::encode(
			[
				'format'           => 'elementor-json-bridge/elementor-capability-shard',
				'version'          => 1,
				'inventory_sha256' => $inventory_hash,
				'surface'          => $surface,
				'records'          => $records,
			]
		);
	}
}
