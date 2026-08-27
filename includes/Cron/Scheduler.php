<?php

namespace Webactueel\ElementorJsonBridge\Cron;

use Throwable;
use Webactueel\ElementorJsonBridge\Sync\Manager;

defined( 'ABSPATH' ) || exit;

final class Scheduler {
	private const POLL_HOOK = 'ejb_poll_remote';
	private const INTERVAL  = 'ejb_ten_minutes';

	public function __construct( private readonly Manager $manager ) {}

	public function register(): void {
		add_filter( 'cron_schedules', [ $this, 'schedules' ] );
		add_action( 'init', [ $this, 'ensure_scheduled' ] );
		add_action( self::POLL_HOOK, [ $this->manager, 'poll_enabled_documents' ] );
		add_action( 'ejb_export_document', [ $this, 'export_document' ], 10, 1 );
	}

	public function schedules( array $schedules ): array {
		$schedules[ self::INTERVAL ] = [
			'interval' => 600,
			'display'  => 'Every 10 minutes',
		];
		return $schedules;
	}

	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::POLL_HOOK ) ) {
			wp_schedule_event( time() + 120, self::INTERVAL, self::POLL_HOOK );
		}
	}

	public function export_document( int $post_id ): void {
		try {
			$this->manager->export( $post_id );
		} catch ( Throwable ) {
			return;
		}
	}

	public static function clear(): void {
		wp_unschedule_hook( self::POLL_HOOK );
		wp_unschedule_hook( 'ejb_export_document' );
	}
}
