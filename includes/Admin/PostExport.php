<?php

namespace Webactueel\ElementorJsonBridge\Admin;

use Webactueel\ElementorJsonBridge\Elementor\Documents;
use Webactueel\ElementorJsonBridge\Elementor\LocalExport;
use Webactueel\ElementorJsonBridge\Lifecycle\Hooks;

defined( 'ABSPATH' ) || exit;

final class PostExport {
	public function __construct( private readonly Documents $documents ) {}

	public function register(): void {
		add_filter( 'page_row_actions', [ $this, 'row_actions' ], 10, 2 );
		add_filter( 'post_row_actions', [ $this, 'row_actions' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public function row_actions( array $actions, \WP_Post $post ): array {
		if ( ! LocalExport::supports_post_type( (string) $post->post_type ) ) {
			return $actions;
		}
		if ( ! current_user_can( Hooks::CAPABILITY ) || ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return $actions;
		}
		if ( 'builder' !== (string) get_post_meta( (int) $post->ID, '_elementor_edit_mode', true ) || ! $this->documents->is_elementor_document( (int) $post->ID ) ) {
			return $actions;
		}

		$actions['ejb_export_json'] = sprintf(
			'<a href="#ejb-export-%1$d" class="ejb-local-export" data-post-id="%1$d" aria-haspopup="dialog">%2$s</a>',
			(int) $post->ID,
			esc_html__( 'Export Elementor JSON', 'elementor-json-bridge' )
		);

		return $actions;
	}

	public function assets( string $hook_suffix ): void {
		if ( 'edit.php' !== $hook_suffix || ! current_user_can( Hooks::CAPABILITY ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! LocalExport::supports_post_type( (string) $screen->post_type ) ) {
			return;
		}

		wp_enqueue_style(
			'ejb-local-export',
			plugins_url( 'assets/css/local-export.css', EJB_FILE ),
			[ 'wp-components' ],
			EJB_VERSION
		);
		wp_enqueue_script(
			'ejb-local-export',
			plugins_url( 'assets/js/local-export.js', EJB_FILE ),
			[ 'wp-components', 'wp-element' ],
			EJB_VERSION,
			true
		);
		wp_localize_script(
			'ejb-local-export',
			'EJB_LOCAL_EXPORT',
			[
				'restUrl' => esc_url_raw( rest_url( 'ejb/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'strings' => [
					'title'             => __( 'Export Elementor JSON', 'elementor-json-bridge' ),
					'intro'             => __( 'Download the current Elementor document as JSON.', 'elementor-json-bridge' ),
					'includeSiteParts'  => __( 'Include header and footer', 'elementor-json-bridge' ),
					'includeSiteHelp'   => __( 'When Elementor Pro Theme Builder is active, the matching header and footer for this page or post are added to one bundle JSON.', 'elementor-json-bridge' ),
					'cancel'            => __( 'Cancel', 'elementor-json-bridge' ),
					'export'            => __( 'Export JSON', 'elementor-json-bridge' ),
					'exporting'         => __( 'Preparing JSON…', 'elementor-json-bridge' ),
					'downloaded'        => __( 'The JSON export has been downloaded.', 'elementor-json-bridge' ),
					'downloadedWarning' => __( 'The JSON was downloaded, but not every requested site part could be included.', 'elementor-json-bridge' ),
					'failed'            => __( 'The Elementor JSON export failed.', 'elementor-json-bridge' ),
				],
			]
		);
	}
}
