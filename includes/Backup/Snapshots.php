<?php

namespace Webactueel\ElementorJsonBridge\Backup;

use RuntimeException;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

defined( 'ABSPATH' ) || exit;

final class Snapshots {
	public const POST_TYPE = 'ejb_snapshot';
	private const RETENTION = 10;

	public function register(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'label'        => 'Elementor JSON Bridge snapshots',
				'public'       => false,
				'show_ui'      => false,
				'show_in_rest' => false,
				'supports'     => [ 'title', 'editor' ],
			]
		);
	}

	public function create( int $source_id, array $payload, string $reason, string $remote_sha = '' ): int {
		$json = CanonicalJson::encode( $payload, true );
		$id   = wp_insert_post(
			[
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'private',
				'post_parent'  => $source_id,
				'post_title'   => sprintf( 'Snapshot %d - %s', $source_id, gmdate( 'Y-m-d H:i:s' ) ),
				'post_content' => wp_slash( $json ),
			],
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( 'Could not create a local rollback snapshot.' );
		}

		update_post_meta( $id, '_ejb_snapshot_hash', CanonicalJson::hash( $payload ) );
		update_post_meta( $id, '_ejb_snapshot_reason', sanitize_key( $reason ) );
		update_post_meta( $id, '_ejb_snapshot_remote_sha', sanitize_text_field( $remote_sha ) );
		update_post_meta( $id, '_ejb_snapshot_user', get_current_user_id() );
		$this->prune( $source_id );

		return (int) $id;
	}

	public function payload( int $snapshot_id, int $source_id ): array {
		$post = get_post( $snapshot_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type || $source_id !== (int) $post->post_parent ) {
			throw new RuntimeException( 'The rollback snapshot does not belong to this document.' );
		}
		$data = json_decode( (string) $post->post_content, true );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'The rollback snapshot is damaged.' );
		}

		$stored_hash = get_post_meta( $snapshot_id, '_ejb_snapshot_hash', true );
		if ( ! is_string( $stored_hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $stored_hash ) ) {
			throw new RuntimeException( 'The rollback snapshot has no valid integrity fingerprint.' );
		}
		if ( ! hash_equals( $stored_hash, CanonicalJson::hash( $data ) ) ) {
			throw new RuntimeException( 'The rollback snapshot failed its integrity check.' );
		}

		return $data;
	}

	public function latest_id( int $source_id ): int {
		$ids = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'post_parent'    => $source_id,
				'posts_per_page' => 1,
				'orderby'        => 'date ID',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
		return $ids ? (int) $ids[0] : 0;
	}

	private function prune( int $source_id ): void {
		$ids = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'post_parent'    => $source_id,
				'posts_per_page' => -1,
				'orderby'        => 'date ID',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
		foreach ( array_slice( $ids, self::RETENTION ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}
}
