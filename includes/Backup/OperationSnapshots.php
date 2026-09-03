<?php

namespace Webactueel\ElementorJsonBridge\Backup;

use RuntimeException;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

defined( 'ABSPATH' ) || exit;

final class OperationSnapshots {
	private const RETENTION = 10;
	private const META_SOURCE_TYPE = '_ejb_operation_source_type';
	private const META_SOURCE_KEY  = '_ejb_operation_source_key';

	public function create( string $source_type, string $source_key, array $payload, string $reason ): int {
		[ $source_type, $source_key ] = $this->identity( $source_type, $source_key );
		$json = CanonicalJson::encode( $payload, true );
		$id   = wp_insert_post(
			[
				'post_type'    => Snapshots::POST_TYPE,
				'post_status'  => 'private',
				'post_parent'  => 0,
				'post_title'   => sprintf( 'Operation snapshot %s - %s', $source_key, gmdate( 'Y-m-d H:i:s' ) ),
				'post_content' => wp_slash( $json ),
			],
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( 'Could not create a durable request snapshot.' );
		}

		update_post_meta( $id, '_ejb_snapshot_hash', CanonicalJson::hash( $payload ) );
		update_post_meta( $id, '_ejb_snapshot_reason', sanitize_key( $reason ) );
		update_post_meta( $id, '_ejb_snapshot_user', get_current_user_id() );
		update_post_meta( $id, self::META_SOURCE_TYPE, $source_type );
		update_post_meta( $id, self::META_SOURCE_KEY, $source_key );
		$this->prune( $source_type, $source_key );

		return (int) $id;
	}

	public function payload( int $snapshot_id, string $source_type, string $source_key ): array {
		[ $source_type, $source_key ] = $this->identity( $source_type, $source_key );
		$post = get_post( $snapshot_id );
		if ( ! $post || Snapshots::POST_TYPE !== $post->post_type || 0 !== (int) $post->post_parent ) {
			throw new RuntimeException( 'The request snapshot does not exist.' );
		}
		if (
			$source_type !== (string) get_post_meta( $snapshot_id, self::META_SOURCE_TYPE, true )
			|| $source_key !== (string) get_post_meta( $snapshot_id, self::META_SOURCE_KEY, true )
		) {
			throw new RuntimeException( 'The request snapshot belongs to a different resource.' );
		}

		$data = json_decode( (string) $post->post_content, true );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'The request snapshot is damaged.' );
		}
		$stored_hash = get_post_meta( $snapshot_id, '_ejb_snapshot_hash', true );
		if (
			! is_string( $stored_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $stored_hash )
			|| ! hash_equals( $stored_hash, CanonicalJson::hash( $data ) )
		) {
			throw new RuntimeException( 'The request snapshot failed integrity verification.' );
		}
		return $data;
	}

	private function identity( string $source_type, string $source_key ): array {
		$source_type = sanitize_key( $source_type );
		$source_key  = sanitize_text_field( $source_key );
		if ( '' === $source_type || '' === $source_key || strlen( $source_key ) > 190 ) {
			throw new RuntimeException( 'The request snapshot resource identity is invalid.' );
		}
		return [ $source_type, $source_key ];
	}

	private function prune( string $source_type, string $source_key ): void {
		$ids = get_posts(
			[
				'post_type'      => Snapshots::POST_TYPE,
				'post_status'    => 'private',
				'post_parent'    => 0,
				'posts_per_page' => -1,
				'orderby'        => 'date ID',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => [
					'relation' => 'AND',
					[
						'key'   => self::META_SOURCE_TYPE,
						'value' => $source_type,
					],
					[
						'key'   => self::META_SOURCE_KEY,
						'value' => $source_key,
					],
				],
			]
		);
		foreach ( array_slice( $ids, self::RETENTION ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}
}
