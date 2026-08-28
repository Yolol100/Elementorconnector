<?php
/**
 * Plugin Name: Elementor JSON Bridge
 * Description: Safely synchronizes Elementor document JSON with a private GitHub repository, with conflict detection, snapshots, validation, and rollback.
 * Version: 0.1.2
 * Requires at least: 6.8
 * Requires PHP: 8.1
 * Update URI: false
 * Requires Plugins: elementor
 * Author: Webactueel
 * Text Domain: elementor-json-bridge
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace Webactueel\ElementorJsonBridge;

defined( 'ABSPATH' ) || exit;

define( 'EJB_VERSION', '0.1.2' );
define( 'EJB_FILE', __FILE__ );
define( 'EJB_DIR', __DIR__ );

require_once __DIR__ . '/includes/Autoloader.php';

Autoloader::register( __DIR__ . '/includes' );

register_activation_hook( __FILE__, [ Lifecycle\Hooks::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Lifecycle\Hooks::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->register();
	},
	20
);
