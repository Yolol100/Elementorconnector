<?php

namespace Webactueel\ElementorJsonBridge;

use Webactueel\ElementorJsonBridge\Admin\AdminPage;
use Webactueel\ElementorJsonBridge\Admin\LocalExportController;
use Webactueel\ElementorJsonBridge\Admin\PostExport;
use Webactueel\ElementorJsonBridge\Admin\RestController;
use Webactueel\ElementorJsonBridge\Backup\Snapshots;
use Webactueel\ElementorJsonBridge\Cron\Scheduler;
use Webactueel\ElementorJsonBridge\Elementor\Documents;
use Webactueel\ElementorJsonBridge\Elementor\LocalExport;
use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;
use Webactueel\ElementorJsonBridge\Elementor\SiteParts;
use Webactueel\ElementorJsonBridge\GitHub\Client;
use Webactueel\ElementorJsonBridge\GitHub\DeviceAuth;
use Webactueel\ElementorJsonBridge\Security\SecretBox;
use Webactueel\ElementorJsonBridge\Sync\AutoApply;
use Webactueel\ElementorJsonBridge\Sync\Lock;
use Webactueel\ElementorJsonBridge\Sync\Manager;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		$secret_box   = new SecretBox();
		$auth         = new DeviceAuth( $secret_box );
		$github       = new Client( $auth );
		$documents    = new Documents();
		$validator    = new PayloadValidator();
		$snapshots    = new Snapshots();
		$sync         = new Manager( $documents, $validator, $github, $snapshots, new Lock() );
		$local_export = new LocalExport( $documents, new SiteParts( $documents ) );

		add_action( 'init', [ $snapshots, 'register' ] );
		( new AdminPage( $auth, $documents, $sync, $snapshots ) )->register();
		( new RestController( $auth, $github, $documents, $sync ) )->register();
		( new PostExport( $documents ) )->register();
		( new LocalExportController( $local_export ) )->register();
		( new Scheduler( $sync ) )->register();
		( new AutoApply( $sync ) )->register();
		add_action( 'save_post', [ $sync, 'on_wordpress_save' ], 20, 3 );

		if ( class_exists( '\\Elementor\\Plugin' ) ) {
			add_action( 'elementor/document/after_save', [ $sync, 'on_elementor_save' ], 20, 2 );
		}
	}
}
