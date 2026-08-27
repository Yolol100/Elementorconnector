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
					$this->conflict( $²È="25É¥¹œ€‘ÍÑ…ÑÕÌì($$‘ÍÑ…ÑÕÌ€ô€¡ÍÑÉ¥¹œ¤•Ñ}Á½ÍÑ}µ•Ñ„ €‘Á½ÍÑ}¥°MÑ…Ñ”èé5Q}MQQUL°ÑÉÕ”€¤ì($%É•ÑÕÉ¸€œœ€„ôô€‘ÍÑ…ÑÕÌ€ü€‘ÍÑ…ÑÕÌ€èMÑ…Ñ”èé1=1}%IQdì(%ô((%ÁÕ‰±¥Œ™Õ¹Ñ¥½¸¥Í}•¹…‰±• ¥¹Ð€‘Á½ÍÑ}¥€¤è‰½½°ì($%É•ÑÕÉ¸€œÄœ€ôôô€¡ÍÑÉ¥¹œ¤•Ñ}Á½ÍÑ}µ•Ñ„ €‘Á½ÍÑ}¥°MÑ…Ñ”èé5Q}9	1°ÑÉÕ”€¤ì(%ô((%ÁÕ‰±¥Œ™Õ¹Ñ¥½¸Á…Ñ¡}™½È ¥¹Ð€‘Á½ÍÑ}¥€¤èÍÑÉ¥¹œì($$‘Á½ÍÐ€ô•Ñ}Á½ÍÐ €‘Á½ÍÑ}¥€¤ì($%¥˜€ €„€‘Á½ÍÐ€¤Ñ¡É½Ü¹•ÜIÕ¹Ñ¥µ•á•ÁÑ¥½¸ €Q¡”]½É‘AÉ•ÍÌ‘½Õµ•¹Ð‘½•Ì¹½Ð•á¥ÍÐ¸œ€¤ì($$‘™½±‘•È€ôµ…Ñ € €‘Á½ÍÐ´ùÁ½ÍÑ}ÑåÁ”€¤ì€Á…”œ€ôø€Á…•Ìœ°€Á½ÍÐœ€ôø€Á½ÍÑÌœ°€•±•µ•¹Ñ½É}±¥‰É…Éäœ€ôø€Ñ•µÁ±…Ñ•Ìœ°‘•™…Õ±Ð€ôø€ÕÍÑ½´¼œ€¸Í…¹¥Ñ¥é•}­•ä €‘Á½ÍÐ´ùÁ½ÍÑ}ÑåÁ”€¤ôì($%É•ÑÕÉ¸ÑÉ¥´ €¡ÍÑÉ¥¹œ¤M•ÑÑ¥¹Ìèé•Ð €É•Á½}É½½Ðœ°€•±•µ•¹Ñ½Èœ€¤€¸€œ¼œ€¸€‘™½±‘•È€¸€œ¼œ€¸€‘Á½ÍÑ}¥€¸€œ¹©Í½¸œ°€œ¼œ€¤ì(%ô((%ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸±½­• ¥¹Ð€‘Á½ÍÑ}¥°…±±…‰±”€‘…±±‰…¬€¤è…ÉÉ…äì($$‘Ñ½­•¸€ô€‘Ñ¡¥Ì´ù±½¬´ù…ÅÕ¥É” €‘Á½ÍÑ}¥€¤ì($%ÑÉäì($$%É•ÑÕÉ¸€‘…±±‰…¬ ¤ì($%ô…Ñ € Q¡É½Ý…‰±”€‘•ÉÉ½È€¤ì($$%¥˜€ €„¥¹}…ÉÉ…ä €‘Ñ¡¥Ì´ùÍÑ…ÑÕÌ €‘Á½ÍÑ}¥€¤°lMÑ…Ñ”èé=91%P°MÑ…Ñ”èéII=Ht°ÑÉÕ”€¤€¤ì($$$$‘Ñ¡¥Ì´ùÍ•Ñ}•ÉÉ½È €‘Á½ÍÑ}¥°MÑ…Ñ”èéII=H°€‘•ÉÉ½È´ù•Ñ5•ÍÍ…” ¤€¤ì($$%ô($$%Ñ¡É½Ü€‘•ÉÉ½Èì($%ô™¥¹…±±äì($$$‘Ñ¡¥Ì´ù±½¬´ùÉ•±•…Í” €‘Á½ÍÑ}¥°€‘Ñ½­•¸€¤ì($%ô(%ô((%ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸É•ÅÕ¥É•}Íå¹}É•…‘ä ¥¹Ð€‘Á½ÍÑ}¥€¤èÙ½¥ì($%¥˜€ €„€‘Ñ¡¥Ì´ù¥Í}•¹…‰±• €‘Á½ÍÑ}¥€¤€¤Ñ¡É½Ü¹•Ü	É¥‘•á•ÁÑ¥½¸ €•©‰}Íå¹}‘¥Í…‰±•œ°€¹…‰±”Íå¹¡É½¹¥é…Ñ¥½¸™½ÈÑ¡¥Ì‘½Õµ•¹Ð™¥ÉÍÐ¸œ°€ÐÀä€¤ì($$‘Ñ¡¥Ì´ù¥Ñ¡Õˆ´ù…ÍÍ•ÉÑ}ÁÉ¥Ù…Ñ•}É•Á½Í¥Ñ½Éä ¤ì($$‘Ñ¡¥Ì´ù…ÍÍ•ÉÑ}É•Á½Í¥Ñ½Éå}¥‘•¹Ñ¥Ñä €‘Á½ÍÑ}¥€¤ì(%ô((%ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸±¥Ù•}Á…å±½… ¥¹Ð€‘Á½ÍÑ}¥€¤è…ÉÉ…äì($%É•ÑÕÉ¸€‘Ñ¡¥Ì´ùÙ…±¥‘…Ñ½È´ùÙ…±¥‘…Ñ•}…ÉÉ…ä €‘Ñ¡¥Ì´ù‘½Õµ•¹ÑÌ´ùÁ…å±½… €‘Á½ÍÑ}¥€¤°€‘Ñ¡¥Ì´ù‘½Õµ•¹ÑÌ´ù‘½Õµ•¹Ñ}ÑåÁ” €‘Á½ÍÑ}¥€¤€¤ì(%ô((%ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸É•µ½Ñ•}Á…å±½… ¥¹Ð€‘Á½ÍÑ}¥°…ÉÉ…ä€‘É•µ½Ñ”°…ÉÉ…ä€‘±¥Ù”€¤è…ÉÉ…äì($$‘Á…å±½…€ô€‘Ñ¡¥Ì´ùÙ…±¥‘…Ñ½È´ù‘•½‘” €¡ÍÑÉ¥¹œ¤€‘É•µ½Ñ•l½¹Ñ•¹Ðt°€‘Ñ¡¥Ì´ù‘½Õµ•¹ÑÌ´ù‘½Õµ•¹Ñ}ÑåÁ” €‘Á½ÍÑ}¥€¤€¤ì($$‘Á…å±½…‘lÑ¥Ñ±”t€ô€¡ÍÑÉ¥¹œ¤€‘±¥Ù•lÑ¥Ñ±”tì($%É•ÑÕÉ¸€‘Á…å±½…ì(%ô((%ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸É½±±‰…¬ ¥¹Ð€‘Á½ÍÑ}¥°¥¹Ð€‘Í¹…ÁÍ¡½Ñ}¥°ÍÑÉ¥¹œ€‘•áÁ•Ñ•‘}¡…Í °Q¡É½Ý…‰±”€‘…ÕÍ”€¤è¹•Ù•Èì($%ÑÉäì($$$‘Ñ¡¥Ì´ù‘½Õµ•¹ÑÌ´ùÍ…Ù•}Á…å±½… €‘Á½ÍÑ}¥°€‘Ñ¡¥Ì´ùÍ¹…ÁÍ¡½ÑÌ´ùÁ…å±½… €‘Í¹…ÁÍ¡½Ñ}¥°€‘Á½ÍÑ}¥€¤€¤ì($$%¥˜€ €„¡…Í¡}•ÅÕ…±Ì €‘•áÁ•Ñ•‘}¡…Í °…¹½¹¥…±)Í½¸èé¡…Í  €‘Ñ¡¥Ì´ù±¥Ù•}Á…å±½… €‘Á½ÍÑ}¥€¤€¤€¤€¤ì($$$%Ñ¡É½Ü¹•ÜIÕ¹Ñ¥µ•á•ÁÑ¥½¸ €I½±±‰…¬Ù•É¥™¥…Ñ¥½¸™…¥±•¸œ€¤ì($$%ô($%ô…Ñ € Q¡É½Ý…‰±”€‘É½±±‰…­}•ÉÉ½È€¤ì($$$‘Ñ¡¥Ì´ùÍ•Ñ}•ÉÉ½È €‘Á½ÍÑ}¥°MÑ…Ñ”èéII=H°€ÁÁ±ä™…¥±•…¹É½±±‰…¬½Õ±¹½Ð‰”Ù•É¥™¥•¸œ€¤ì($$%Ñ¡É½Ü¹•ÜIÕ¹Ñ¥µ•á•ÁÑ¥½¸ €ÁÁ±ä™…¥±•…¹É½±±‰…¬½Õ±¹½Ð‰”Ù•É¥™¥•¸œ°€À°€‘É½±±‰…­}•ÉÉ½È€¤ì($%ô($$‘Ñ¡¥Ì´ùÍ•Ñ}•ÉÉ½È €‘Á½ÍÑ}¥°MÑ…Ñ”èéII=H°€ÁÁ±ä™…¥±•ìÑ¡”±½…°Í¹…ÁÍ¡½ÐÝ…ÌÉ•ÍÑ½É•¸œ€¤ì($%Ñ¡É½Ü¹•ÜIÕ¹Ñ¥µ•á•ÁÑ¥½¸ €ÁÁ±ä™…¥±•¸Q¡”ÁÉ•Ù¥½ÕÌ±•µ•¹Ñ½ÈÙ•ÉÍ¥½¸Ý…ÌÉ•ÍÑ½É•¸œ°€À°€‘…ÕÍ”€¤ì(%ô((%ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸…ÍÍ•ÉÑ}É•Á½Í¥Ñ½Éå}¥‘•¹Ñ¥Ñä ¥¹Ð€‘Á½ÍÑ}¥€¤èÙ½¥ì($$‘‰…Í•}¡…Í €ô€¡ÍÑÉ¥¹œ¤•Ñ}Á½ÍÑ}µ•Ñ„ €‘Á½ÍÑ}¥°MÑ…Ñ”èé5Q}	M}!M °ÑÉÕ”€¤ì($%¥˜€ €œœ€ôôô€‘‰…Í•}¡…Í €¤ì($$%É•ÑÕÉ¸ì($%ô(($$‘­¹½Ý¸€€€ô€¡ÍÑÉ¥¹œ¤•Ñ}Á½ÍÑ}µ•Ñ„ €‘Á½ÍÑ}¥°MÑ…Ñ”èé5Q}IA=}%°ÑÉÕ”€¤ì($$‘ÕÉÉ•¹Ð€ôM•ÑÑ¥¹ÌèéÉ•Á½Í¥Ñ½Éå}¥‘•¹Ñ¥Ñä ¤ì(($%¥˜€ €œœ€ôôô€‘ÕÉÉ•¹Ð€¤ì($$$‘Ñ¡¥Ì´ù½¹™±¥Ð €‘Á½ÍÑ}¥°€Q¡”¥Ñ!ÕˆÉ•Á½Í¥Ñ½Éä°‰É…¹ °½È)M=8É½½Ð¡…¹•™É½´Ñ¡”ÑÉÕÍÑ•Íå¹¡É½¹¥é…Ñ¥½¸‰…Í”¸œ€¤ì($%ô(($%¥˜€ €œœ€ôôô€‘­¹½Ý¸€¤ì($$$‘Ñ¡¥Ì´ùµ¥É…Ñ•}±•…å}É•Á½Í¥Ñ½Éå}¥‘•¹Ñ¥Ñä €‘Á½ÍÑ}¥°€‘‰…Í•}¡…Í °€‘ÕÉÉ•¹Ð€¤ì($$%É•ÑÕÉ¸ì($%ô(($%¥˜€ €„¡…Í¡}•ÅÕ…±Ì €‘­¹½Ý¸°€‘ÕÉÉ•¹Ð€¤€¤ì($$$‘Ñ¡¥Ì´ù½¹™±¥Ð €‘Á½ÍÑ}¥°€Q¡”¥Ñ!ÕˆÉ•Á½Í¥Ñ½Éä°‰É…¹ °½È)M=8É½½Ð¡…¹•™É½´Ñ¡”ÑÉÕÍÑ•Íå¹¡É½¹¥é…Ñ¥½¸‰…Í”¸œ€¤ì($%ô(%ô((%ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸µ¥É…Ñ•}±•…å}É•Á½Í¥Ñ½Éå}¥‘•¹Ñ¥Ñä ¥¹Ð€‘Á½ÍÑ}¥°ÍÑÉ¥¹œ€‘‰…Í•}¡…Í °ÍÑÉ¥¹œ€‘ÕÉÉ•¹Ñ}¥‘•¹Ñ¥Ñä€¤èÙ½¥ì($$‘­¹½Ý¹}Í¡„€€€€ô€¡ÍÑÉ¥¹œ¤•Ñ}Á½ÍÑ}µ•Ñ„ €‘Á½ÍÑ}¥°MÑ…Ñ”èé5Q}I5=Q}M!°ÑÉÕ”€¤ì($$‘­¹½Ý¹}Á…Ñ €€€ô€¡ÍÑÉ¥¹œ¤•Ñ}Á½ÍÑ}µ•Ñ„ €‘Á½ÍÑ}¥°MÑ…Ñ”èé5Q}I5=Q}AQ °ÑÉÕ”€¤ì($$‘ÕÉÉ•¹Ñ}Á…Ñ €ô€‘Ñ¡¥Ì´ùÁ…Ñ¡}™½È €‘Á½ÍÑ}¥€¤ì(($%¥˜€ €œœ€ôôô€‘­¹½Ý¹}Í¡„ñð€œœ€ôôô€‘­¹½Ý¹}Á…Ñ ñð€‘­¹½Ý¹}Á…Ñ €„ôô€‘ÕÉÉ•¹Ñ}Á…Ñ €¤ì($$$‘Ñ¡¥Ì´ù½¹™±¥Ð €‘Á½ÍÑ}¥°€Q¡”±•…äÍå¹¡É½¹¥é…Ñ¥½¸‰…Í”…¹¹½Ð‰”Í…™•±ä…ÍÍ½¥…Ñ•Ý¥Ñ Ñ¡”½¹™¥ÕÉ•¥Ñ!ÕˆÁ…Ñ ¸œ€¤ì($%ô(($$‘É•µ½Ñ”€ô€‘Ñ¡¥Ì´ù¥Ñ¡Õˆ´ù•Ñ}™¥±” €‘ÕÉÉ•¹Ñ}Á…Ñ €¤ì($%¥˜€ €„€‘É•µ½Ñ”€¤ì($$$‘Ñ¡¥Ì´ù½¹™±¥Ð €‘Á½ÍÑ}¥°€Q¡”±•…äÍå¹¡É½¹¥é…Ñ¥½¸‰…Í”Á½¥¹ÑÌÑ¼„¥Ñ!Õˆ™¥±”Ñ¡…Ð¹¼±½¹•È•á¥ÍÑÌ¸œ€¤ì($%ô(($$‘É•µ½Ñ•}Á…å±½…€ô€‘Ñ¡¥Ì´ùÙ…±¥‘…Ñ½È´ù‘•½‘” €¡ÍÑÉ¥¹œ¤€‘É•µ½Ñ•l½¹Ñ•¹Ðt°€‘Ñ¡¥Ì´ù‘½Õµ•¹ÑÌ´ù‘½Õµ•¹Ñ}ÑåÁ” €‘Á½ÍÑ}¥€¤€¤ì($$‘É•µ½Ñ•}¡…Í €€€€ô…¹½¹¥…±)Í½¸èé¡…Í  €‘É•µ½Ñ•}Á…å±½…€¤ì(($%¥˜€ €„Í•±˜èé±•…å}É•Á½Í¥Ñ½Éå}ÍÑ…Ñ•}µ…Ñ¡•Ì €‘‰…Í•}¡…Í °€‘­¹½Ý¹}Í¡„°€¡ÍÑÉ¥¹œ¤€‘É•µ½Ñ•lÍ¡„t°€‘É•µ½Ñ•}¡…Í €¤€¤ì($$$‘Ñ¡¥Ì´ù½¹™±¥Ð €‘Á½ÍÑ}¥°€Q¡”±•…ä¥Ñ!Õˆ™¥±”¹¼±½¹•Èµ…Ñ¡•ÌÑ¡”ÑÉÕÍÑ•Íå¹¡É½¹¥é…Ñ¥½¸‰…Í”¸œ€¤ì($%ô(($%ÕÁ‘…Ñ•}Á½ÍÑ}µ•Ñ„ €‘Á½ÍÑ}¥°MÑ…Ñ”èé5Q}IA=}%°€‘ÕÉÉ•¹Ñ}¥‘•¹Ñ¥Ñä€¤ì(%ô((%ÁÉ¥Ù…Ñ”ÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸±•…å}É•Á½Í¥Ñ½Éå}ÍÑ…Ñ•}µ…Ñ¡•Ì ÍÑÉ¥¹œ€‘‰…Í•}¡…Í °ÍÑÉ¥¹œ€‘­¹½Ý¹}Í¡„°ÍÑÉ¥¹œ€‘É•µ½Ñ•}Í¡„°ÍÑÉ¥¹œ€‘É•µ½Ñ•}¡…Í €¤è‰½½°ì($%¥˜€ €œœ€ôôô€‘‰…Í•}¡…Í ñð€œœ€ôôô€‘­¹½Ý¹}Í¡„ñð€œœ€ôôô€‘É•µ½Ñ•}Í¡„ñð€œœ€ôôô€‘É•µ½Ñ•}¡…Í €¤ì($$%É•ÑÕÉ¸™…±Í”ì($%ô(($%É•ÑÕÉ¸¡…Í¡}•ÅÕ…±Ì €‘­¹½Ý¹}Í¡„°€‘É•µ½Ñ•}Í¡„€¤€˜˜¡…Í¡}•ÅÕ…±Ì €‘‰…Í•}¡…Í °€‘É•µ½Ñ•}¡…Í €¤ì(%ô((%ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸½¹™±¥Ð ¥¹Ð€‘Á½ÍÑ}¥°ÍÑÉ¥¹œ€‘µ•ÍÍ…”€¤è¹•Ù•Èì($$‘Ñ¡¥Ì´ùÍ•Ñ}•ÉÉ½È €‘Á½ÍÑ}¥°MÑ…Ñ”èé=91%P°€‘µ•ÍÍ…”€¤ì($%Ñ¡É½Ü¹•Ü	É¥‘•á•ÁÑ¥½¸ €•©‰}Íå¹}½¹™±¥Ðœ°€‘µ•ÍÍ…”€¸€œI•Í•Ð½ÈÉ”µ¡•¬Ñ¡”Íå¹¡É½¹¥é…Ñ¥½¸‰…Í”‰•™½É”½¹Ñ¥¹Õ¥¹œ¸œ°€ÐÀä€¤ì(%ô((%ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸µ…É­}Íå¹• ¥¹Ð€‘Á½ÍÑ}¥°ÍÑÉ¥¹œ€‘Á…Ñ °ÍÑÉ¥¹œ€‘¡…Í °ÍÑÉ¥¹œ€‘Í¡„°ÍÑÉ¥¹œ€‘ÍÑ…ÑÕÌ€¤èÙ½¥ì($%ÕÁ‘…Ñ•}Á½ÍÑ}µ•Ñ„ €‘Á½ÍÑ}¥°MÑ…Ñ”èé5Q}I5=Q}AQ °€‘Á…Ñ €¤ì($%ÕÁ‘…Ñ•}Á½ÍÑ}µ•Ñ„ €‘Á½ÍÑ}¥°MÑ…Ñ”èé5Q}IA=}%°M•ÑÑ¥¹ÌèéÉ•Á½Í¥Ñ½Éå}¥‘•¹Ñ¥Ñä ¤€¤ì($%ÕÁ‘…Ñ•}Á½ÍÑ}µ•Ñ„ €‘Á½ÍÑ}¥°MÑ…Ñ”èé5Q}	M}!M °€‘¡…Í €¤ì($%ÕÁ‘…Ñ•}Á½ÍÑ}µ•Ñ„ €‘Á½ÍÑ}¥°MÑ…Ñ”èé5Q}I5=Q}M!°€‘Í¡„€¤ì($%ÕÁ‘…Ñ•}Á½ÍÑ}µ•Ñ„ €‘Á½ÍÑ}¥°MÑ…Ñ”èé5Q}1MQ}Me9}P°µ‘…Ñ” €Œœ€¤€¤ì($$‘Ñ¡¥Ì´ù±•…É}Á•¹‘¥¹œ €‘Á½ÍÑ}¥€¤ì($%‘•±•Ñ•}Á½ÍÑ}µ•Ñ„ €‘Á½ÍÑ}¥°MÑ…Ñ”èé5Q}1MQ}II=H€¤ì($$‘Ñ¡¥Ì´ùÍ•Ñ}ÍÑ…ÑÕÌ €‘Á½ÍÑ}¥°€‘ÍÑ…ÑÕÌ€¤ì(%ô((%ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸µ…É­}±½…±}‘¥ÉÑä ¥¹Ð€‘Á½ÍÑ}¥€¤èÙ½¥ì($%¥˜€ €‘Á½ÍÑ}¥€ð€Äñð€„€‘Ñ¡¥Ì´ù¥Í}•¹…‰±• €‘Á½ÍÑ}¥€¤€¤É•ÑÕÉ¸ì($$‘Ñ¡¥Ì´ùÍ•Ñ}ÍÑ…ÑÕÌ €‘Á½ÍÑ}¥°MÑ…Ñ”èé1=1}%IQd€¤ì($%¥˜€ M•ÑÑ¥¹Ìèé•Ð €…ÕÑ½}•áÁ½ÉÐœ°€Ä€¤€˜˜M•ÑÑ¥¹ÌèéÉ•Á½}¥Í}½¹™¥ÕÉ• ¤€˜˜•Ñ}½ÁÑ¥½¸ M•ÑÑ¥¹ÌèéUQ!}=AQ%=8°€œœ€¤€˜˜€„ÝÁ}¹•áÑ}Í¡•‘Õ±• €•©‰}•áÁ½ÉÑ}‘½Õµ•¹Ðœ°l€‘Á½ÍÑ}¥t€¤€¤ì($$%ÝÁ}Í¡•‘Õ±•}Í¥¹±•}•Ù•¹Ð Ñ¥µ” ¤€¬€ÄÔ°€•©‰}•áÁ½ÉÑ}‘½Õµ•¹Ðœ°l€‘Á½ÍÑ}¥t€¤ì($%ô(%ô((%ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸±•…É}Á•¹‘¥¹œ ¥¹Ð€‘Á½ÍÑ}¥€¤èÙ½¥ì($%‘•±•Ñ•}Á½ÍÑ}µ•Ñ„ €‘Á½ÍÑ}¥°MÑ…Ñ”èé5Q}A9%9}M!€¤ì($%‘•±•Ñ•}Á½ÍÑ}µ•Ñ„ €‘Á½ÍÑ}¥°MÑ…Ñ”èé5Q}A9%9}!M €¤ì(%ô((%ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸Í•Ñ}ÍÑ…ÑÕÌ ¥¹Ð€‘Á½ÍÑ}¥°ÍÑÉ¥¹œ€‘ÍÑ…ÑÕÌ€¤èÙ½¥ì($%ÕÁ‘…Ñ•}Á½ÍÑ}µ•Ñ„ €‘Á½ÍÑ}¥°MÑ…Ñ”èé5Q}MQQUL°€‘ÍÑ…ÑÕÌ€¤ì($%¥˜€ €„¥¹}…ÉÉ…ä €‘ÍÑ…ÑÕÌ°lMÑ…Ñ”èéI5=Q}A9%9°MÑ…Ñ”èéAA1e%9t°ÑÉÕ”€¤€¤€‘Ñ¡¥Ì´ù±•…É}Á•¹‘¥¹œ €‘Á½ÍÑ}¥€¤ì($%¥˜€ €„¥¹}…ÉÉ…ä €‘ÍÑ…ÑÕÌ°lMÑ…Ñ”èéII=H°MÑ…Ñ”èé=91%Pt°ÑÉÕ”€¤€¤‘•±•Ñ•}Á½ÍÑ}µ•Ñ„ €‘Á½ÍÑ}¥°MÑ…Ñ”èé5Q}1MQ}II=H€¤ì(%ô((%ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸Í•Ñ}•ÉÉ½È ¥¹Ð€‘Á½ÍÑ}¥°ÍÑÉ¥¹œ€‘ÍÑ…ÑÕÌ°ÍÑÉ¥¹œ€‘µ•ÍÍ…”€¤èÙ½¥ì($$‘Ñ¡¥Ì´ù±•…É}Á•¹‘¥¹œ €‘Á½ÍÑ}¥€¤ì($%ÕÁ‘…Ñ•}Á½ÍÑ}µ•Ñ„ €‘Á½ÍÑ}¥°MÑ…Ñ”èé5Q}MQQUL°€‘ÍÑ…ÑÕÌ€¤ì($%ÕÁ‘…Ñ•}Á½ÍÑ}µ•Ñ„ €‘Á½ÍÑ}¥°MÑ…Ñ”èé5Q}1MQ}II=H°ÍÕ‰ÍÑÈ Í…¹¥Ñ¥é•}Ñ•áÑ}™¥•± €‘µ•ÍÍ…”€¤°€À°€ÔÀÀ€¤€¤ì(%ô)ô(