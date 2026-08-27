<?php

namespace Webactueel\ElementorJsonBridge\Lifecycle;

use Webactueel\ElementorJsonBridge\Cron\Scheduler;

defined( 'ABSPATH' ) || exit;

final class Hooks {
	public const CAPABILITY = 'manage_elementor_json_bridge';

	public static function activate(): void {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( self::CAPABILITY );
		}
	}

	public static function deactivate(): void {
		Scheduler::clear();
	}
}
