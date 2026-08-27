<?php

namespace Webactueel\ElementorJsonBridge\Sync;

use RuntimeException;
use Throwable;
use Webactueel\ElementorJsonBridge\Backup\Snapshots;
use Webactueel\ElementorJsonBridge\Elementor\Documents;
use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;
use Webactueel\ElementorJsonBridge\GitHub\Client;
use Webactueel\ElementorJsonBridge\Settings;
use Webactueel\ElementorJsonBridge\Support\BridgeException;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

defined( 'ABSPATH' ) || exit;

final class Manager {
	private const POLL_PAGE_OPTION = 'ejb_poll_page';
	private const POLL_BATCH_SIZE  = 20;
	private static bool $applying  = false;

	public function __construct(
		private readonly Documents $documents,
		private readonly PayloadValidator $validator,
		private readonly Client $github,
		private readonly Snapshots $snapshots,
		private readonly Lock $lock
	) {}

	public function on_elementor_save( object $document, array $data = [] ): void {
		unset( $data );
		if ( ! self::$applying && method_exists( $document, 'get_main_id' ) ) {
			$this->mark_local_dirty( (int) $document->get_main_id() );
		}
	}

	public function on_wordpress_save( int $post_id, \WP_Post $post, bool $update ): void {
		unset( $post, $update );
		if ( self::$applying || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! $this->is_enabled( $post_id ) ) {
			return;
		}
		if ( $this->documents->is_elementor_document( $post_id ) ) {
			$this->mark_local_dirty( $post_id );
		}
	}

	public function toggle( int $post_id ): bool {
		$enabled = ! $this->is_enabled( $post_id );
		update_post_meta( $post_id, State::META_ENABLED, $enabled ? '1' : '0' );
		$this->clear_pending( $post_id );
		if ( $enabled ) {
			$this->set_status( $post_id, State::LOCAL_DIRTY );
		} else {
			wp_clear_scheduled_hook( 'ejb_export_document', [ $post_id ] );
		}
		return $enabled;
	}

	public function export( int $post_id ): array {
		return $this->locked(
			$post_id,
			function () use ( $post_id ): array {
				$this->require_sync_ready( $post_id );
				$local      = $this->live_payload( $post_id );
				$local_hash = CanonicalJson::hash( $local );
				$path       = $this->path_for( $post_id );
				$remote     = $this->github->get_file( $path );
				$known_sha  = (string) get_post_meta( $post_id, State::META_REMOTE_SHA, true );
				$known_path = (string) get_post_meta( $post_id, State::META_REMOTE_PATH, true );

				if ( ! $remote && '' !== $known_sha ) {
					$this->conflict( $post_id, 'The known GitHub file was deleted.' );
				}

				if ( $remote && '' === $known_sha ) {
					$remote_hash = CanonicalJson::hash( $this->remote_payload( $post_id, $remote, $local ) );
					if ( ! hash_equals( $local_hash, $remote_hash ) ) {
						$this->conflict( $post_id, 'GitHub already contains different JSON with unknown history.' );
					}
					$this->mark_synced( $post_id, $path, $local_hash, (string) $remote['sha'], State::CLEAN );
					return [ 'status' => State::CLEAN, 'sha' => (string) $remote['sha'], 'path' => $path, 'adopted' => true ];
				}

				if ( $remote && '' !== $known_sha && ! hash_equals( $known_sha, (string) $remote['sha'] ) ) {
					if ( $known_path !== $path ) {
						$this->conflict( $post_id, 'The GitHub path changed from the trusted synchronization base.' );
					}
					$remote_hash = CanonicalJson::hash( $this->remote_payload( $post_id, $remote, $local ) );
					if ( ! hash_equals( $local_hash, $remote_hash ) ) {
						$this->conflict( $post_id, 'GitHub changed from the trusted synchronization base.' );
					}
					$this->mark_synced( $post_id, $path, $local_hash, (string) $remote['sha'], State::CLEAN );
					return [ 'status' => State::CLEAN, 'sha' => (string) $remote['sha'], 'path' => $path, 'reconciled' => true ];
				}

				$result = $this->github->put_file( $path, CanonicalJson::encode( $local, true ), $remote ? (string) $remote['sha'] : null, sprintf( 'Sync Elementor document %d', $post_id ) );
				$this->mark_synced( $post_id, $path, $local_hash, (string) $result['sha'], State::CLEAN );
				return [ 'status' => State::CLEAN, 'sha' => (string) $result['sha'], 'path' => $path ];
			}
		);
	}

	public function check_remote( int $post_id ): array {
		return $this->locked(
			$post_id,
			function () use ( $post_id ): array {
				$this->require_sync_ready( $post_id );
				$path       = $this->path_for( $post_id );
				$remote     = $this->github->get_file( $path );
				$known_sha  = (string) get_post_meta( $post_id, State::META_REMOTE_SHA, true );
				$base_hash  = (string) get_post_meta( $post_id, State::META_BASE_HASH, true );
				$local      = $this->live_payload( $post_id );
				$local_hash = CanonicalJson::hash( $local );

				if ( ! $remote ) {
					if ( '' !== $known_sha ) {
						$this->set_error( $post_id, State::CONFLICT, 'The known GitHub file was deleted.' );
						return [ 'status' => State::CONFLICT ];
					}
					$this->set_status( $post_id, State::LOCAL_DIRTY );
					return [ 'status' => State::LOCAL_DIRTY ];
				}

				if ( '' !== $known_sha && hash_equals( $known_sha, (string) $remote['sha'] ) ) {
					$status = '' !== $base_hash && hash_equals( $base_hash, $local_hash ) ? State::CLEAN : State::LOCAL_DIRTY;
					$this->set_status( $post_id, $status );
					return [ 'status' => $status ];
				}

				if ( '' === $base_hash || ! hash_equals( $base_hash, $local_hash ) ) {
					$this->set_error( $post_id, State::CONFLICT, 'Both the live Elementor document and GitHub differ from the trusted base.' );
					return [ 'status' => State::CONFLICT ];
				}

				$remote_hash = CanonicalJson::hash( $this->remote_payload( $post_id, $remote, $local ) );
				if ( hash_equals( $local_hash, $remote_hash ) ) {
					$this->mark_synced( $post_id, $path, $local_hash, (string) $remote['sha'], State::CLEAN );
					return [ 'status' => State::CLEAN, 'sha' => (string) $remote['sha'], 'reconciled' => true ];
				}

				update_post_meta( $post_id, State::META_PENDING_SHA, (string) $remote['sha'] );
				update_post_meta( $post_id, State::META_PENDING_HASH, $remote_hash );
				update_post_meta( $post_id, State::META_REMOTE_PATH, $path );
				update_post_meta( $post_id, State::META_STATUS, State::REMOTE_PENDING );
				return [ 'status' => State::REMOTE_PENDING, 'sha' => (string) $remote['sha'] ];
			}
		);
	}

	public function apply_remote( int $post_id ): array {
		return $this->locked(
			$post_id,
			function () use ( $post_id ): array {
				$this->require_sync_ready( $post_id );
				$path         = $this->path_for( $post_id );
				$remote       = $this->github->get_file( $path );
				$base_hash    = (string) get_post_meta( $post_id, State::META_BASE_HASH, true );
				$pending_sha  = (string) get_post_meta( $post_id, State::META_PENDING_SHA, true );
				$pending_hash = (string) get_post_meta( $post_id, State::META_PENDING_HASH, true );
				if ( ! $remote || State::REMOTE_PENDING !== $this->status( $post_id ) || '' === $pending_sha || '' === $pending_hash ) {
					throw new BridgeException( 'ejb_remote_not_pending', 'Check GitHub immediately before applying a remote change.', 409 );
				}
				if ( ! hash_equals( $pending_sha, (string) $remote['sha'] ) ) {
					throw new BridgeException( 'ejb_remote_changed', 'GitHub changed again. Check GitHub before applying.', 409 );
				}

				$current      = $this->live_payload( $post_id );
				$current_hash = CanonicalJson::hash( $current );
				if ( '' === $base_hash || ! hash_equals( $base_hash, $current_hash ) ) {
					$this->conflict( $post_id, 'The live Elementor document changed after the last synchronization.' );
				}
				$incoming      = $this->remote_payload( $post_id, $remote, $current );
				$incoming_hash = CanonicalJson::hash( $incoming );
				if ( ! hash_equals( $pending_hash, $incoming_hash ) ) {
					throw new BridgeException( 'ejb_remote_changed', 'The checked GitHub content no longer matches the pending fingerprint.', 409 );
				}

				$snapshot_id = $this->snapshots->create( $post_id, $current, 'before_remote_apply', (string) $remote['sha'] );
				$this->set_status( $post_id, State::APPLYING );
				self::$applying = true;
				try {
					$this->documents->save_payload( $post_id, $incoming );
					$readback = $this->live_payload( $post_id );
					if ( ! hash_equals( $incoming_hash, CanonicalJson::hash( $readback ) ) ) {
						throw new RuntimeException( 'Elementor roundtrip verification failed.' );
					}
				} catch ( Throwable $apply_error ) {
					$this->rollback( $post_id, $snapshot_id, $current_hash, $apply_error );
				} finally {
					self::$applying = false;
				}

				$this->mark_synced( $post_id, $path, $incoming_hash, (string) $remote['sha'], State::VERIFIED );
				return [ 'status' => State::VERIFIED, 'snapshot_id' => $snapshot_id, 'sha' => (string) $remote['sha'] ];
			}
		);
	}

	public function restore_snapshot( int $post_id, int $snapshot_id ): array {
		return $this->locked(
			$post_id,
			function () use ( $post_id, $snapshot_id ): array {
				$current = $this->live_payload( $post_id );
				$restore = $this->validator->validate_array( $this->snapshots->payload( $snapshot_id, $post_id ), $this->documents->document_type( $post_id ) );
				$this->snapshots->create( $post_id, $current, 'before_manual_restore', (string) get_post_meta( $post_id, State::META_REMOTE_SHA, true ) );
				self::$applying = true;
				try {
					$this->documents->save_payload( $post_id, $restore );
					if ( ! hash_equals( CanonicalJson::hash( $restore ), CanonicalJson::hash( $this->live_payload( $post_id ) ) ) ) {
						throw new RuntimeException( 'Restored snapshot failed roundtrip verification.' );
					}
				} catch ( Throwable $restore_error ) {
					$this->documents->save_payload( $post_id, $current );
					if ( ! hash_equals( CanonicalJson::hash( $current ), CanonicalJson::hash( $this->live_payload( $post_id ) ) ) ) {
						throw new RuntimeException( 'Snapshot restore failed and the pre-restore version could not be verified.', 0, $restore_error );
					}
					throw new RuntimeException( 'Snapshot restore failed. The pre-restore version was restored.', 0, $restore_error );
				} finally {
					self::$applying = false;
				}
				$this->clear_pending( $post_id );
				$this->set_status( $post_id, State::LOCAL_DIRTY );
				return [ 'status' => State::LOCAL_DIRTY ];
			}
		);
	}

	public function reset_base( int $post_id ): array {
		return $this->locked(
			$post_id,
			function () use ( $post_id ): array {
				foreach ( [ State::META_BASE_HASH, State::META_REMOTE_SHA, State::META_REMOTE_PATH, State::META_REPO_ID, State::META_PENDING_SHA, State::META_PENDING_HASH, State::META_LAST_ERROR, State::META_LAST_SYNC_AT ] as $key ) {
					delete_post_meta( $post_id, $key );
				}
				$this->set_status( $post_id, State::LOCAL_DIRTY );
				return [ 'status' => State::LOCAL_DIRTY ];
			}
		);
	}

	public function poll_enabled_documents(): void {
		if ( ! Settings::repo_is_configured() || ! get_option( Settings::AUTH_OPTION, '' ) ) {
			return;
		}
		$page = max( 1, (int) get_option( self::POLL_PAGE_OPTION, 1 ) );
		$query = new \WP_Query( [ 'post_type' => array_values( get_post_types( [], 'names' ) ), 'post_status' => 'any', 'posts_per_page' => self::POLL_BATCH_SIZE, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'meta_key' => State::META_ENABLED, 'meta_value' => '1' ] );
		$max_pages = max( 1, (int) $query->max_num_pages );
		foreach ( $query->posts as $id ) {
			try { $this->check_remote( (int) $id ); } catch ( Throwable ) { continue; }
		}
		update_option( self::POLL_PAGE_OPTION, $page < $max_pages ? $page + 1 : 1, false );
	}

	public function status( int $post_id ): string {
		$status = (string) get_post_meta( $post_id, State::META_STATUS, true );
		return '' !== $status ? $status : State::LOCAL_DIRTY;
	}

	public function is_enabled( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, State::META_ENABLED, true );
	}

	public function path_for( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) throw new RuntimeException( 'The WordPress document does not exist.' );
		$folder = match ( $post->post_type ) { 'page' => 'pages', 'post' => 'posts', 'elementor_library' => 'templates', default => 'custom/' . sanitize_key( $post->post_type ) };
		return trim( (string) Settings::get( 'repo_root', 'elementor' ) . '/' . $folder . '/' . $post_id . '.json', '/' );
	}

	private function locked( int $post_id, callable $callback ): array {
		$token = $this->lock->acquire( $post_id );
		try {
			return $callback();
		} catch ( Throwable $error ) {
			if ( ! in_array( $this->status( $post_id ), [ State::CONFLICT, State::ERROR ], true ) ) {
				$this->set_error( $post_id, State::ERROR, $error->getMessage() );
			}
			throw $error;
		} finally {
			$this->lock->release( $post_id, $token );
		}
	}

	private function require_sync_ready( int $post_id ): void {
		if ( ! $this->is_enabled( $post_id ) ) throw new BridgeException( 'ejb_sync_disabled', 'Enable synchronization for this document first.', 409 );
		$this->github->assert_private_repository();
		$this->assert_repository_identity( $post_id );
	}

	private function live_payload( int $post_id ): array {
		return $this->validator->validate_array( $this->documents->payload( $post_id ), $this->documents->document_type( $post_id ) );
	}

	private function remote_payload( int $post_id, array $remote, array $live ): array {
		$payload = $this->validator->decode( (string) $remote['content'], $this->documents->document_type( $post_id ) );
		$payload['title'] = (string) $live['title'];
		return $payload;
	}

	private function rollback( int $post_id, int $snapshot_id, string $expected_hash, Throwable $cause ): never {
		try {
			$this->documents->save_payload( $post_id, $this->snapshots->payload( $snapshot_id, $post_id ) );
			if ( ! hash_equals( $expected_hash, CanonicalJson::hash( $this->live_payload( $post_id ) ) ) ) {
				throw new RuntimeException( 'Rollback verification failed.' );
			}
		} catch ( Throwable $rollback_error ) {
			$this->set_error( $post_id, State::ERROR, 'Apply failed and rollback could not be verified.' );
			throw new RuntimeException( 'Apply failed and rollback could not be verified.', 0, $rollback_error );
		}
		$this->set_error( $post_id, State::ERROR, 'Apply failed; the local snapshot was restored.' );
		throw new RuntimeException( 'Apply failed. The previous Elementor version was restored.', 0, $cause );
	}

	private function assert_repository_identity( int $post_id ): void {
		if ( '' === (string) get_post_meta( $post_id, State::META_BASE_HASH, true ) ) return;
		$known = (string) get_post_meta( $post_id, State::META_REPO_ID, true );
		$current = Settings::repository_identity();
		if ( '' === $known || '' === $current || ! hash_equals( $known, $current ) ) {
			$this->conflict( $post_id, 'The GitHub repository, branch, or JSON root changed from the trusted synchronization base.' );
		}
	}

	private function conflict( int $post_id, string $message ): never {
		$this->set_error( $post_id, State::CONFLICT, $message );
		throw new BridgeException( 'ejb_sync_conflict', $message . ' Reset or re-check the synchronization base before continuing.', 409 );
	}

	private function mark_synced( int $post_id, string $path, string $hash, string $sha, string $status ): void {
		update_post_meta( $post_id, State::META_REMOTE_PATH, $path );
		update_post_meta( $post_id, State::META_REPO_ID, Settings::repository_identity() );
		update_post_meta( $post_id, State::META_BASE_HASH, $hash );
		update_post_meta( $post_id, State::META_REMOTE_SHA, $sha );
		update_post_meta( $post_id, State::META_LAST_SYNC_AT, gmdate( 'c' ) );
		$this->clear_pending( $post_id );
		delete_post_meta( $post_id, State::META_LAST_ERROR );
		$this->set_status( $post_id, $status );
	}

	private function mark_local_dirty( int $post_id ): void {
		if ( $post_id < 1 || ! $this->is_enabled( $post_id ) ) return;
		$this->set_status( $post_id, State::LOCAL_DIRTY );
		if ( Settings::get( 'auto_export', 1 ) && Settings::repo_is_configured() && get_option( Settings::AUTH_OPTION, '' ) && ! wp_next_scheduled( 'ejb_export_document', [ $post_id ] ) ) {
			wp_schedule_single_event( time() + 15, 'ejb_export_document', [ $post_id ] );
		}
	}

	private function clear_pending( int $post_id ): void {
		delete_post_meta( $post_id, State::META_PENDING_SHA );
		delete_post_meta( $post_id, State::META_PENDING_HASH );
	}

	private function set_status( int $post_id, string $status ): void {
		update_post_meta( $post_id, State::META_STATUS, $status );
		if ( ! in_array( $status, [ State::REMOTE_PENDING, State::APPLYING ], true ) ) $this->clear_pending( $post_id );
		if ( ! in_array( $status, [ State::ERROR, State::CONFLICT ], true ) ) delete_post_meta( $post_id, State::META_LAST_ERROR );
	}

	private function set_error( int $post_id, string $status, string $message ): void {
		$this->clear_pending( $post_id );
		update_post_meta( $post_id, State::META_STATUS, $status );
		update_post_meta( $post_id, State::META_LAST_ERROR, substr( sanitize_text_field( $message ), 0, 500 ) );
	}
}
