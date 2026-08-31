<?php

namespace Webactueel\ElementorJsonBridge\Sync;

use RuntimeException;
use Throwable;
use Webactueel\ElementorJsonBridge\Backup\Snapshots;
use Webactueel\ElementorJsonBridge\Content\WordPressDocument;
use Webactueel\ElementorJsonBridge\GitHub\Client;
use Webactueel\ElementorJsonBridge\Settings;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

defined( 'ABSPATH' ) || exit;

final class Manager {
	private const POLL_PAGE_OPTION = 'ejb_poll_page';
	private const POLL_BATCH_SIZE  = 20;

	private static bool $applying = false;

	public function __construct(
		private readonly WordPressDocument $content,
		private readonly Client $github,
		private readonly Snapshots $snapshots,
		private readonly Lock $lock
	) {}

	public function on_elementor_save( object $document, array $data = [] ): void {
		unset( $data );
		if ( self::$applying || ! method_exists( $document, 'get_main_id' ) ) {
			return;
		}
		$this->mark_local_dirty( (int) $document->get_main_id() );
	}

	public function on_wordpress_save( int $post_id, \WP_Post $post, bool $update ): void {
		unset( $post, $update );
		if ( self::$applying || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! $this->is_enabled( $post_id ) ) {
			return;
		}
		$this->mark_local_dirty( $post_id );
	}

	public function toggle( int $post_id ): bool {
		if ( ! $this->content->supports( $post_id ) ) {
			throw new RuntimeException( 'This WordPress content item cannot be managed by the bridge.' );
		}
		$enabled = ! $this->is_enabled( $post_id );
		if ( $enabled ) {
			delete_post_meta( $post_id, State::META_EXCLUDED );
			$this->set_status( $post_id, State::LOCAL_DIRTY );
		} else {
			update_post_meta( $post_id, State::META_EXCLUDED, '1' );
			delete_post_meta( $post_id, State::META_PENDING_SHA );
			delete_post_meta( $post_id, State::META_PENDING_HASH );
			wp_clear_scheduled_hook( 'ejb_export_document', [ $post_id ] );
		}
		return $enabled;
	}

	public function export( int $post_id ): array {
		$token = $this->lock->acquire( $post_id );
		try {
			$this->assert_enabled( $post_id );
			$this->github->assert_private_repository();
			$payload    = $this->content->payload( $post_id );
			$local_hash = CanonicalJson::hash( $payload );
			$path       = $this->path_for( $post_id );
			$this->migrate_legacy_state( $post_id, $path );
			$remote     = $this->github->get_file( $path );
			$known_path = (string) get_post_meta( $post_id, State::META_REMOTE_PATH, true );
			$known_sha  = (string) get_post_meta( $post_id, State::META_REMOTE_SHA, true );

			if ( ! $remote && '' !== $known_sha ) {
				$this->set_error( $post_id, State::CONFLICT, 'The known GitHub file was deleted. Nothing was recreated automatically.' );
				throw new RuntimeException( 'The known GitHub file was deleted. Reset the sync base only if this was intentional.' );
			}

			if ( $remote && '' === $known_sha ) {
				$remote_payload = $this->content->decode( (string) $remote['content'], $post_id );
				if ( hash_equals( $local_hash, CanonicalJson::hash( $remote_payload ) ) ) {
					$this->mark_synced( $post_id, $path, $local_hash, (string) $remote['sha'], State::CLEAN );
					return [ 'status' => State::CLEAN, 'sha' => (string) $remote['sha'], 'path' => $path, 'adopted' => true ];
				}
				$this->set_error( $post_id, State::CONFLICT, 'GitHub already contains different WordPress content with unknown history. Nothing was overwritten.' );
				throw new RuntimeException( 'GitHub already contains a different WordPress content version. Nothing was overwritten.' );
			}

			if ( $remote && '' !== $known_sha && ! hash_equals( $known_sha, (string) $remote['sha'] ) ) {
				if ( $known_path !== $path ) {
					$this->set_error( $post_id, State::CONFLICT, 'The configured GitHub path no longer matches the known synchronization base.' );
					throw new RuntimeException( 'The GitHub path changed. Reset the synchronization base before continuing.' );
				}
				$remote_payload = $this->content->decode( (string) $remote['content'], $post_id );
				if ( hash_equals( $local_hash, CanonicalJson::hash( $remote_payload ) ) ) {
					$this->mark_synced( $post_id, $path, $local_hash, (string) $remote['sha'], State::CLEAN );
					return [ 'status' => State::CLEAN, 'sha' => (string) $remote['sha'], 'path' => $path, 'reconciled' => true ];
				}
				$this->set_error( $post_id, State::CONFLICT, 'The GitHub file changed from the known base. Nothing was overwritten.' );
				throw new RuntimeException( 'GitHub contains a version that differs from the known synchronization base.' );
			}

			$result = $this->github->put_file(
				$path,
				CanonicalJson::encode( $payload, true ),
				$remote ? (string) $remote['sha'] : null,
				sprintf( 'Sync WordPress content %d', $post_id )
			);

			$this->mark_synced( $post_id, $path, $local_hash, (string) $result['sha'], State::CLEAN );
			return [ 'status' => State::CLEAN, 'sha' => (string) $result['sha'], 'path' => $path ];
		} catch ( Throwable $throwable ) {
			if ( State::CONFLICT !== get_post_meta( $post_id, State::META_STATUS, true ) ) {
				$this->set_error( $post_id, State::ERROR, $throwable->getMessage() );
			}
			throw $throwable;
		} finally {
			$this->lock->release( $post_id, $token );
		}
	}

	public function check_remote( int $post_id ): array {
		$token = $this->lock->acquire( $post_id );
		try {
			$this->assert_enabled( $post_id );
			$this->github->assert_private_repository();
			$path = $this->path_for( $post_id );
			$this->migrate_legacy_state( $post_id, $path );
			$remote     = $this->github->get_file( $path );
			$known_sha  = (string) get_post_meta( $post_id, State::META_REMOTE_SHA, true );
			$base_hash  = (string) get_post_meta( $post_id, State::META_BASE_HASH, true );
			$local      = $this->content->payload( $post_id );
			$local_hash = CanonicalJson::hash( $local );

			if ( ! $remote ) {
				if ( '' !== $known_sha ) {
					$this->set_error( $post_id, State::CONFLICT, 'The known GitHub file was deleted.' );
					return [ 'status' => State::CONFLICT, 'message' => 'The known GitHub file was deleted.' ];
				}
				$this->set_status( $post_id, State::LOCAL_DIRTY );
				return [ 'status' => State::LOCAL_DIRTY, 'message' => 'No GitHub file exists yet.' ];
			}

			if ( '' !== $known_sha && hash_equals( $known_sha, (string) $remote['sha'] ) ) {
				$status = '' !== $base_hash && hash_equals( $base_hash, $local_hash ) ? State::CLEAN : State::LOCAL_DIRTY;
				$this->set_status( $post_id, $status );
				return [ 'status' => $status ];
			}

			if ( '' === $base_hash || ! hash_equals( $base_hash, $local_hash ) ) {
				$this->set_error( $post_id, State::CONFLICT, 'Both the live WordPress content and GitHub differ from the known base.' );
				return [ 'status' => State::CONFLICT ];
			}

			$remote_payload = $this->content->decode( (string) $remote['content'], $post_id );
			$remote_hash    = CanonicalJson::hash( $remote_payload );
			if ( hash_equals( $local_hash, $remote_hash ) ) {
				$this->mark_synced( $post_id, $path, $local_hash, (string) $remote['sha'], State::CLEAN );
				return [ 'status' => State::CLEAN, 'sha' => (string) $remote['sha'], 'reconciled' => true ];
			}
			update_post_meta( $post_id, State::META_PENDING_SHA, (string) $remote['sha'] );
			update_post_meta( $post_id, State::META_PENDING_HASH, $remote_hash );
			update_post_meta( $post_id, State::META_REMOTE_PATH, $path );
			$this->set_status( $post_id, State::REMOTE_PENDING );
			return [ 'status' => State::REMOTE_PENDING, 'sha' => (string) $remote['sha'] ];
		} catch ( Throwable $throwable ) {
			if ( State::CONFLICT !== get_post_meta( $post_id, State::META_STATUS, true ) ) {
				$this->set_error( $post_id, State::ERROR, $throwable->getMessage() );
			}
			throw $throwable;
		} finally {
			$this->lock->release( $post_id, $token );
		}
	}

	public function apply_remote( int $post_id ): array {
		$token = $this->lock->acquire( $post_id );
		try {
			$this->assert_enabled( $post_id );
			$this->github->assert_private_repository();
			$path = $this->path_for( $post_id );
			$this->migrate_legacy_state( $post_id, $path );
			$remote       = $this->github->get_file( $path );
			$base_hash    = (string) get_post_meta( $post_id, State::META_BASE_HASH, true );
			$known_sha    = (string) get_post_meta( $post_id, State::META_REMOTE_SHA, true );
			$pending_sha  = (string) get_post_meta( $post_id, State::META_PENDING_SHA, true );
			$pending_hash = (string) get_post_meta( $post_id, State::META_PENDING_HASH, true );

			if ( ! $remote ) {
				throw new RuntimeException( 'The GitHub file no longer exists.' );
			}
			if ( State::REMOTE_PENDING !== $this->status( $post_id ) || '' === $pending_sha || '' === $pending_hash ) {
				throw new RuntimeException( 'Check GitHub immediately before applying a remote change.' );
			}
			if ( ! hash_equals( $pending_sha, (string) $remote['sha'] ) ) {
				throw new RuntimeException( 'The GitHub file changed again. Check remote before applying.' );
			}
			if ( '' !== $known_sha && hash_equals( $known_sha, (string) $remote['sha'] ) ) {
				return [ 'status' => State::CLEAN, 'message' => 'The live content already matches the known GitHub version.' ];
			}

			$current      = $this->content->payload( $post_id );
			$current_hash = CanonicalJson::hash( $current );
			if ( '' === $base_hash || ! hash_equals( $base_hash, $current_hash ) ) {
				$this->set_error( $post_id, State::CONFLICT, 'The live WordPress content changed after the last synchronization.' );
				throw new RuntimeException( 'The live WordPress content changed. The GitHub version was not applied.' );
			}

			$incoming      = $this->content->decode( (string) $remote['content'], $post_id );
			$incoming_hash = CanonicalJson::hash( $incoming );
			if ( ! hash_equals( $pending_hash, $incoming_hash ) ) {
				throw new RuntimeException( 'The checked GitHub content no longer matches the pending fingerprint.' );
			}
			$snapshot_id = $this->snapshots->create( $post_id, $current, 'before_remote_apply', (string) $remote['sha'] );

			$this->set_status( $post_id, State::APPLYING );
			self::$applying = true;
			try {
				$this->content->apply( $post_id, $incoming );
				$readback = $this->content->payload( $post_id );
				if ( ! hash_equals( $incoming_hash, CanonicalJson::hash( $readback ) ) ) {
					throw new RuntimeException( 'WordPress changed the imported content during save; roundtrip verification failed.' );
				}
			} catch ( Throwable $apply_error ) {
				try {
					$rollback = $this->snapshots->payload( $snapshot_id, $post_id );
					$this->content->apply( $post_id, $rollback );
					$restored = $this->content->payload( $post_id );
					if ( ! hash_equals( $current_hash, CanonicalJson::hash( $restored ) ) ) {
						throw new RuntimeException( 'Rollback verification failed.' );
					}
				} catch ( Throwable $rollback_error ) {
					$this->set_error( $post_id, State::ERROR, 'Apply failed and rollback could not be verified: ' . $rollback_error->getMessage() );
					throw new RuntimeException( 'Apply failed and rollback could not be verified.', 0, $apply_error );
				}
				$this->set_error( $post_id, State::ERROR, 'Apply failed; the local snapshot was restored. ' . $apply_error->getMessage() );
				throw new RuntimeException( 'Apply failed. The previous WordPress content was restored.', 0, $apply_error );
			} finally {
				self::$applying = false;
			}

			$this->mark_synced( $post_id, $path, $incoming_hash, (string) $remote['sha'], State::VERIFIED );
			return [ 'status' => State::VERIFIED, 'snapshot_id' => $snapshot_id, 'sha' => (string) $remote['sha'] ];
		} catch ( Throwable $throwable ) {
			if ( ! in_array( get_post_meta( $post_id, State::META_STATUS, true ), [ State::CONFLICT, State::ERROR ], true ) ) {
				$this->set_error( $post_id, State::ERROR, $throwable->getMessage() );
			}
			throw $throwable;
		} finally {
			$this->lock->release( $post_id, $token );
		}
	}

	public function restore_snapshot( int $post_id, int $snapshot_id ): array {
		$token = $this->lock->acquire( $post_id );
		try {
			$current = $this->content->payload( $post_id );
			$restore = $this->content->validate_array( $this->snapshots->payload( $snapshot_id, $post_id ), $post_id );
			$this->snapshots->create( $post_id, $current, 'before_manual_restore', (string) get_post_meta( $post_id, State::META_REMOTE_SHA, true ) );

			self::$applying = true;
			try {
				try {
					$this->content->apply( $post_id, $restore );
					$readback = $this->content->payload( $post_id );
					if ( ! hash_equals( CanonicalJson::hash( $restore ), CanonicalJson::hash( $readback ) ) ) {
						throw new RuntimeException( 'Restored snapshot failed roundtrip verification.' );
					}
				} catch ( Throwable $restore_error ) {
					$this->content->apply( $post_id, $current );
					$rollback = $this->content->payload( $post_id );
					if ( ! hash_equals( CanonicalJson::hash( $current ), CanonicalJson::hash( $rollback ) ) ) {
						throw new RuntimeException( 'Snapshot restore failed and the pre-restore version could not be verified.', 0, $restore_error );
					}
					throw new RuntimeException( 'Snapshot restore failed. The pre-restore version was restored.', 0, $restore_error );
				}
			} finally {
				self::$applying = false;
			}

			delete_post_meta( $post_id, State::META_PENDING_SHA );
			delete_post_meta( $post_id, State::META_PENDING_HASH );
			$this->set_status( $post_id, State::LOCAL_DIRTY );
			return [ 'status' => State::LOCAL_DIRTY ];
		} catch ( Throwable $throwable ) {
			$this->set_error( $post_id, State::ERROR, $throwable->getMessage() );
			throw $throwable;
		} finally {
			$this->lock->release( $post_id, $token );
		}
	}

	public function reset_base( int $post_id ): array {
		$token = $this->lock->acquire( $post_id );
		try {
			foreach ( [ State::META_BASE_HASH, State::META_REMOTE_SHA, State::META_REMOTE_PATH, State::META_PENDING_SHA, State::META_PENDING_HASH, State::META_LAST_ERROR, State::META_LAST_SYNC_AT ] as $meta_key ) {
				delete_post_meta( $post_id, $meta_key );
			}
			$this->set_status( $post_id, State::LOCAL_DIRTY );
			return [ 'status' => State::LOCAL_DIRTY ];
		} finally {
			$this->lock->release( $post_id, $token );
		}
	}

	public function poll_enabled_documents(): void {
		if ( ! Settings::repo_is_configured() || ! get_option( Settings::AUTH_OPTION, '' ) ) {
			return;
		}

		$page = max( 1, (int) get_option( self::POLL_PAGE_OPTION, 1 ) );
		$query = new \WP_Query(
			[
				'post_type'      => $this->content->post_types(),
				'post_status'    => 'any',
				'posts_per_page' => self::POLL_BATCH_SIZE,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => false,
			]
		);

		$max_pages = max( 1, (int) $query->max_num_pages );
		if ( $page > $max_pages ) {
			update_option( self::POLL_PAGE_OPTION, 1, false );
			return;
		}

		foreach ( $query->posts as $id ) {
			$id = (int) $id;
			if ( ! $this->is_enabled( $id ) ) {
				continue;
			}
			try {
				if ( '' === (string) get_post_meta( $id, State::META_REMOTE_SHA, true ) ) {
					if ( Settings::get( 'auto_export', 1 ) ) {
						$this->export( $id );
					}
					continue;
				}
				$this->check_remote( $id );
			} catch ( Throwable ) {
				continue;
			}
		}

		if ( $page >= $max_pages ) {
			$this->sync_index();
		}
		update_option( self::POLL_PAGE_OPTION, $page < $max_pages ? $page + 1 : 1, false );
	}

	public function status( int $post_id ): string {
		$status = (string) get_post_meta( $post_id, State::META_STATUS, true );
		return '' !== $status ? $status : State::LOCAL_DIRTY;
	}

	public function is_enabled( int $post_id ): bool {
		return $this->content->supports( $post_id ) && '1' !== (string) get_post_meta( $post_id, State::META_EXCLUDED, true );
	}

	public function path_for( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! $this->content->supports( $post_id ) ) {
			throw new RuntimeException( 'The WordPress content item does not exist or is not managed.' );
		}

		$folder = match ( $post->post_type ) {
			'page'              => 'pages',
			'post'              => 'posts',
			'elementor_library' => 'templates',
			default             => 'custom/' . sanitize_key( $post->post_type ),
		};
		$root = (string) Settings::get( 'repo_root', 'site-data' );
		return trim( $root . '/content/' . $folder . '/' . $post_id . '.json', '/' );
	}

	private function sync_index(): void {
		try {
			$this->github->assert_private_repository();
			$ids = get_posts(
				[
					'post_type'      => $this->content->post_types(),
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
				]
			);
			$items = [];
			foreach ( $ids as $id ) {
				$id = (int) $id;
				if ( $this->is_enabled( $id ) ) {
					$items[] = $this->content->index_descriptor( $id, $this->path_for( $id ) );
				}
			}
			$index = [
				'format'  => 'elementor-json-bridge/site-index',
				'version' => 1,
				'items'   => $items,
			];
			$root = (string) Settings::get( 'repo_root', 'site-data' );
			$path = trim( $root . '/site-index.json', '/' );
			$remote = $this->github->get_file( $path );
			$encoded = CanonicalJson::encode( $index, true );
			if ( $remote && hash_equals( hash( 'sha256', $encoded ), hash( 'sha256', (string) $remote['content'] ) ) ) {
				return;
			}
			$this->github->put_file( $path, $encoded, $remote ? (string) $remote['sha'] : null, 'Refresh WordPress site content index' );
		} catch ( Throwable ) {
			return;
		}
	}

	private function assert_enabled( int $post_id ): void {
		if ( ! $this->is_enabled( $post_id ) ) {
			throw new RuntimeException( 'This WordPress content item is excluded from automatic synchronization.' );
		}
	}

	private function migrate_legacy_state( int $post_id, string $path ): void {
		$known_path = (string) get_post_meta( $post_id, State::META_REMOTE_PATH, true );
		if ( '' === $known_path || $known_path === $path ) {
			return;
		}
		if ( $known_path !== $this->legacy_path_for( $post_id ) ) {
			return;
		}
		foreach ( [ State::META_BASE_HASH, State::META_REMOTE_SHA, State::META_REMOTE_PATH, State::META_PENDING_SHA, State::META_PENDING_HASH, State::META_LAST_ERROR, State::META_LAST_SYNC_AT ] as $meta_key ) {
			delete_post_meta( $post_id, $meta_key );
		}
		$this->set_status( $post_id, State::LOCAL_DIRTY );
	}

	private function legacy_path_for( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		$folder = match ( $post->post_type ) {
			'page'              => 'pages',
			'post'              => 'posts',
			'elementor_library' => 'templates',
			default             => 'custom/' . sanitize_key( $post->post_type ),
		};
		$root = (string) Settings::get( 'repo_root', 'site-data' );
		return trim( $root . '/' . $folder . '/' . $post_id . '.json', '/' );
	}

	private function mark_synced( int $post_id, string $path, string $hash, string $sha, string $status ): void {
		update_post_meta( $post_id, State::META_REMOTE_PATH, $path );
		update_post_meta( $post_id, State::META_BASE_HASH, $hash );
		update_post_meta( $post_id, State::META_REMOTE_SHA, $sha );
		update_post_meta( $post_id, State::META_LAST_SYNC_AT, gmdate( 'c' ) );
		delete_post_meta( $post_id, State::META_PENDING_SHA );
		delete_post_meta( $post_id, State::META_PENDING_HASH );
		delete_post_meta( $post_id, State::META_LAST_ERROR );
		$this->set_status( $post_id, $status );
	}

	private function mark_local_dirty( int $post_id ): void {
		if ( $post_id < 1 || ! $this->is_enabled( $post_id ) ) {
			return;
		}
		$this->set_status( $post_id, State::LOCAL_DIRTY );
		if ( Settings::get( 'auto_export', 1 ) && Settings::repo_is_configured() && get_option( Settings::AUTH_OPTION, '' ) && ! wp_next_scheduled( 'ejb_export_document', [ $post_id ] ) ) {
			wp_schedule_single_event( time() + 15, 'ejb_export_document', [ $post_id ] );
		}
	}

	private function set_status( int $post_id, string $status ): void {
		update_post_meta( $post_id, State::META_STATUS, $status );
		if ( ! in_array( $status, [ State::REMOTE_PENDING, State::APPLYING ], true ) ) {
			delete_post_meta( $post_id, State::META_PENDING_SHA );
			delete_post_meta( $post_id, State::META_PENDING_HASH );
		}
		if ( State::ERROR !== $status && State::CONFLICT !== $status ) {
			delete_post_meta( $post_id, State::META_LAST_ERROR );
		}
	}

	private function set_error( int $post_id, string $status, string $message ): void {
		delete_post_meta( $post_id, State::META_PENDING_SHA );
		delete_post_meta( $post_id, State::META_PENDING_HASH );
		update_post_meta( $post_id, State::META_STATUS, $status );
		update_post_meta( $post_id, State::META_LAST_ERROR, substr( sanitize_text_field( $message ), 0, 500 ) );
	}
}
