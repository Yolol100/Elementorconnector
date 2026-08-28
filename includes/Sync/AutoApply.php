<?php

namespace Webactueel\ElementorJsonBridge\Sync;

use Throwable;
use Webactueel\ElementorJsonBridge\Lifecycle\Hooks;
use Webactueel\ElementorJsonBridge\Settings;

defined( 'ABSPATH' ) || exit;

final class AutoApply {
	private const BATCH_SIZE = 20;

	public function __construct( private readonly Manager $manager ) {}

	public function register(): void {
		add_action( 'ejb_poll_remote', [ $this, 'apply_pending' ], 20 );
	}

	public function apply_pending(): void {
		if ( ! self::should_apply( Settings::get( 'auto_apply', 0 ), State::REMOTE_PENDING ) ) {
			return;
		}
		if ( ! class_exists( '\\Elementor\\Plugin' ) || ! Settings::repo_is_configured() || ! get_option( Settings::AUTH_OPTION, '' ) ) {
			return;
		}

		$actor_id = (int) Settings::get( 'auto_apply_actor', 0 );
		if ( $actor_id < 1 || ! user_can( $actor_id, Hooks::CAPABILITY ) ) {
			return;
		}

		$ids = get_posts(
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
						'key'   => State::META_ENABLED,
						'value' => '1',
					],
					[
						'key'   => State::META_STATUS,
						'value' => State::REMOTE_PENDING,
					],
				],
			]
		);

		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( ! user_can( $actor_id, 'edit_post', $id ) ) {
				continue;
			}

			$previous_user_id = get_current_user_id();
			wp_set_current_user( $actor_id );
			try {
				$result = $this->manager->check_remote( $id );
				if ( ! self::should_apply( Settings::get( 'auto_apply', 0 ), (string) ( $result['status'] ?? '' ) ) ) {
					continue;
				}
				$this->manager->apply_remote( $id );
			} catch ( Throwable ) {
				continue;
			} finally {
				wp_set_current_user( $previous_user_id );
			}
		}
	}

	public static function should_apply( mixed $setting, string $status ): bool {
		return 1 === (int) $setting && State::REMOTE_PENDING === $status;
	}
}
