<?php

namespace Webactueel\ElementorJsonBridge;

use Webactueel\ElementorJsonBridge\Admin\AdminPage;
use Webactueel\ElementorJsonBridge\Admin\LocalExportController;
use Webactueel\ElementorJsonBridge\Admin\PostExport;
use Webactueel\ElementorJsonBridge\Admin\RestController;
use Webactueel\ElementorJsonBridge\Admin\TemplateImportController;
use Webactueel\ElementorJsonBridge\Admin\TemplateImportUi;
use Webactueel\ElementorJsonBridge\Backup\Snapshots;
use Webactueel\ElementorJsonBridge\Content\WordPressDocument;
use Webactueel\ElementorJsonBridge\Cron\Scheduler;
use Webactueel\ElementorJsonBridge\Elementor\Documents;
use Webactueel\ElementorJsonBridge\Elementor\LocalExport;
use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;
use Webactueel\ElementorJsonBridge\Elementor\SiteParts;
use Webactueel\ElementorJsonBridge\Elementor\TemplateImporter;
use Webactueel\ElementorJsonBridge\GitHub\Client;
use Webactueel\ElementorJsonBridge\GitHub\DeviceAuth;
use Webactueel\ElementorJsonBridge\Security\SecretBox;
use Webactueel\ElementorJsonBridge\Sync\AutoApply;
use Webactueel\ElementorJsonBridge\Sync\ContentRequests;
use Webactueel\ElementorJsonBridge\Sync\ElementorCapabilities;
use Webactueel\ElementorJsonBridge\Sync\Lock;
use Webactueel\ElementorJsonBridge\Sync\Manager;
use Webactueel\ElementorJsonBridge\Sync\MediaInventory;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		$secret_box        = new SecretBox();
		$auth              = new DeviceAuth( $secret_box );
		$github            = new Client( $auth );
		$documents         = new Documents();
		$validator         = new PayloadValidator();
		$content           = new WordPressDocument( $documents, $validator );
		$snapshots         = new Snapshots();
		$lock              = new Lock();
		$sync              = new Manager( $content, $github, $snapshots, $lock );
		$capability_sync   = new ElementorCapabilities( $github );
		$media_sync        = new MediaInventory( $github );
		$local_export      = new LocalExport( $documents, new SiteParts( $documents ) );
		$template_importer = new TemplateImporter( $documents, $validator, $snapshots, $lock );

		add_action( 'init', [ $snapshots, 'register' ] );
		( new AdminPage( $auth, $content, $sync, $snapshots ) )->register();
		( new RestController( $auth, $github, $content, $sync ) )->register();
		( new PostExport( $documents ) )->register();
		( new LocalExportController( $local_export ) )->register();
		( new TemplateImportUi() )->register();
		( new TemplateImportController( $template_importer ) )->register();
		( new Scheduler( $sync ) )->register();
		$capability_sync->register();
		$media_sync->register();
		( new ContentRequests( $content, $github, $sync ) )->register();
		( new AutoApply( $sync, $content ) )->register();
		add_action( 'save_post', [ $sync, 'on_wordpress_save' ], 100, 3 );

		if ( class_exists( '\\Elementor\\Plugin' ) ) {
			add_action( 'elementor/document/after_save', [ $sync, 'on_elementor_save' ], 100, 2 );
		}
	}
}
