<?php

namespace Webactueel\ElementorJsonBridge;

use Webactueel\ElementorJsonBridge\Admin\AdminPage;
use Webactueel\ElementorJsonBridge\Admin\RestController;
use Webactueel\ElementorJsonBridge\Backup\Snapshots;
use Webactueel\ElementorJsonBridge\Cron\Scheduler;
use Webactueel\ElementorJsonBridge\Elementor\Documents;
use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;
use Webactueel\ElementorJsonBridge\GitHub\Client;
use Webactueel\ElementorJsonBridge\GitHub\DeviceAuth;
use Webactueel\ElementorJsonBridge\Security\SecretBox;
use Webactueel\ElementorJsonBridge\Sync\Lock;
use Webactueel\ElementorJsonBridge\Sync\Manager;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	public const MIN_WORDPRESS_VERSION = '6.8';
	public const MIN_PHP_VERSION       = '8.3';
	public const MIN_ELEMENTOR_VERSION = '4.2.3';

	private static ?self $instance = null;
	private string $compatibility_error = '';

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		if ( ! $this->is_compatible() ) {
			add_action( 'admin_notices', [ $this, 'compatibility_notice' ] );
			return;
		}

		$secret_box = new SecretBox();
		$auth       = new DeviceAuth( $secret_box );
		$github     = new Client( $auth );
		$documents  = new Documents();
		$validator  = new PayloadValidator();
		$snapshots  = new Snapshots();
		$sync       = new Manager( $documents, $validator, $github, $snapshots, new Lock() );

		add_action( 'init', [ $snapshots, 'register' ] );
		( new AdminPage( $auth, $documents, $sync, $snapshots ) )->register();
		( new RestController( $auth, $github, $documents, $sync ) )->register();
		( new Scheduler( $sync ) )->register();
		add_action( 'save_post', [ $sync, 'on_wordpress_save' ], 20, 3 );
		add_action( 'elementor/document/after_save', [ $sync, 'on_elementor_save' ], 20, 2 );
	}

	public function compatibility_notice(): void {
		if ( '' === $this->compatibility_error || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( $this->compatibility_error )
		);
	}

	private function is_compatible(): bool {
		global $wp_version;

		if ( version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '<' ) ) {
			$this->compatibility_error = sprintf( 'Elementor JSON Bridge requires PHP %s or newer.', self::MIN_PHP_VERSION );
			return false;
		}
		if ( ! is_string( $wp_version ) || version_compare( $wp_version, self::MIN_WORDPRESS_VERSION, '<' ) ) {
			$this->compatibility_error = sprintf( 'Elementor JSON Bridge requires WordPress %s or newer.', self::MIN_WORDPRESS_VERSION );
			return false;
		}
		if ( ! did_action( 'elementor/loaded' ) || ! defined( 'ELEMENTOR_VERSION' ) ) {
			$this->compatibility_error = 'Elementor JSON Bridge requires Elementor to be active.';
			return false;
		}
		if ( version_compare( (string) ELEMENTOR_VERSION, self::MIN_ELEMENTOR_VERSION, '<' ) ) {
			$this->compatibility_error = sprintf( 'Elementor JSON Bridge requires Elementor %s or newer.', self::MIN_ELEMENTOR_VERSION );
			return false;
		}
		return true;
	}
}
