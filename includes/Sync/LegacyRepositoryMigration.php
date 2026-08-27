<?php

namespace Webactueel\ElementorJsonBridge\Sync;

use Throwable;
use Webactueel\ElementorJsonBridge\Elementor\Documents;
use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;
use Webactueel\ElementorJsonBridge\GitHub\Client;
use Webactueel\ElementorJsonBridge\Settings;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

defined( 'ABSPATH' ) || exit;

final class LegacyRepositoryMigration {
	private const BATCH_SIZE              = 10;
	private const META_ATTEMPTED_IDENTITY = '_ejb_repo_identity_migration_attempted';

	private bool $ran = false;

	public function __construct(
		private readonly Documents $documents,
		private readonly PayloadValidator $validator,
		private readonly Client $github,
		private readonly Manager $sync
	) {}

	public function register(): void {
		add_action( 'admin_init', [ $this, 'run' ], 1 );
		add_action( 'rest_api_init', [ $this, 'run' ], 1 );
		add_action( 'wp_loaded', [ $this, 'run_for_cron' ], 1 );
	}

	public function run_for_cron(): void {
		if ( wp_doing_cron() ) {
			$this->run();
		}
	}

	public function run(): void {
		if ( $this->ran || ! Settings::repo_is_configured() || ! get_option( Settings::AUTH_OPTION, '' ) ) {
			return;
		}

		$current_identity = Settings::repository_identity();
		if ( '' === $current_identity ) {
			return;
		}

		$this->ran = true;

		try {
			$this->github->assert_private_repository();
		} catch ( Throwable ) {
			return;
		}

		$query = new \WP_Query(
			[
				'post_type'      => array_values( get_post_types( [], 'names' ) ),
				'post_status'    => 'any',
				'posts_per_page' => self::BATCH_SIZE,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => [
					'relation' => 'AND',
					[
						'key'     => State::META_BASE_HASH,
						'compare' => 'EXISTS',
					],
					[
						'relation' => 'OR',
						[
							'key'     => State::META_REPO_ID,
							'compare' => 'NOT EXISTS',
						],
						[
							'key'   => State::META_REPO_ID,
							'value' => '',
						],
					],
					[
						'relation' => 'OR',
						[
							'key'     => self::META_ATTEMPTED_IDENTITY,
							'compare' => 'NOT EXISTS',
						],
						[
							'key'     => self::META_ATTEMPTED_IDENTITY,
							'value'   => $current_identity,
							'compare' => '!=',
						],
					],
				],
			]
		);

		foreach ( $query->posts as $post_id ) {
			try {
				$this->migrate_document( (int) $post_id, $current_identity );
			} catch ( Throwable ) {
				// A transport/runtime failure remains retryable on a later request.
				continue;
			}
		}
	}

	private function migrate_document( int $post_id, string $current_identity ): void {
		$base_hash  = (string) get_post_meta( $post_id, State::META_BASE_HASH, true );
		$known_sha  = (string) get_post_meta( $post_id, State::META_REMOTE_SHA, true );
		$known_path = (string) get_post_meta( $post_id, State::META_REMOTE_PATH, true );

		if ( '' === $base_hash || '' === $known_sha || '' === $known_path ) {
			$this->block_for_identity( $post_id, $current_identity );
			return;
		}

		$current_path = $this->sync->path_for( $post_id );
		if ( ! hash_equals( $known_path, $current_path ) ) {
			$this->block_for_identity( $post_id, $current_identity );
			return;
		}

		$remote = $this->github->get_file( $current_path );
		if ( ! $remote ) {
			$this->block_for_identity( $post_id, $current_identity );
			return;
		}

		try {
			$remote_payload = $this->validator->decode(
				(string) $remote['content'],
				$this->documents->document_type( $post_id )
			);
		} catch ( Throwable ) {
			$this->block_for_identity( $post_id, $current_identity );
			return;
		}

		$remote_hash = CanonicalJson::hash( $remote_payload );
		if ( ! self::legacy_state_matches( $base_hash, $known_sha, (string) $remote['sha'], $remote_hash ) ) {
			$this->block_for_identity( $post_id, $current_identity );
			return;
		}

		update_post_meta( $post_id, State::META_REPO_ID, $current_identity );
		delete_post_meta( $post_id, self::META_ATTEMPTED_IDENTITY );
	}

	private static function legacy_state_matches( string $base_hash, string $known_sha, string $remote_sha, string $remote_hash ): bool {
		if ( '' === $base_hash || '' === $known_sha || '' === $remote_sha || '' === $remote_hash ) {
			return false;
		}

		return hash_equals( $known_sha, $remote_sha ) && hash_equals( $base_hash, $remote_hash );
	}

	private function block_for_identity( int $post_id, string $current_identity ): void {
		update_post_meta( $post_id, self::META_ATTEMPTED_IDENTITY, $current_identity );
	}
}
