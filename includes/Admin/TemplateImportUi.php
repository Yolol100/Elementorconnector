<?php

namespace Webactueel\ElementorJsonBridge\Admin;

use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;
use Webactueel\ElementorJsonBridge\Lifecycle\Hooks;

defined( 'ABSPATH' ) || exit;

final class TemplateImportUi {
	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public function assets( string $hook_suffix ): void {
		if ( 'edit.php' !== $hook_suffix || ! current_user_can( Hooks::CAPABILITY ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'elementor_library' !== (string) $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'ejb-template-import',
			plugins_url( 'assets/css/template-import.css', EJB_FILE ),
			[ 'wp-components' ],
			EJB_VERSION
		);
		wp_enqueue_script(
			'ejb-template-import',
			plugins_url( 'assets/js/template-import.js', EJB_FILE ),
			[ 'wp-components', 'wp-element' ],
			EJB_VERSION,
			true
		);
		wp_localize_script(
			'ejb-template-import',
			'EJB_TEMPLATE_IMPORT',
			[
				'restUrl'  => esc_url_raw( rest_url( 'ejb/v1' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'maxBytes' => PayloadValidator::MAX_BYTES,
				'strings'  => [
					'title'                 => __( 'Import Elementor JSON', 'elementor-json-bridge' ),
					'intro'                 => __( 'Choose one JSON file. The bridge will inspect it before anything is changed.', 'elementor-json-bridge' ),
					'chooseFile'            => __( 'Choose JSON file', 'elementor-json-bridge' ),
					'fileHelp'              => __( 'Smart import accepts one JSON file up to 5 MB. ZIP files can still use Elementor’s standard import.', 'elementor-json-bridge' ),
					'analyzing'             => __( 'Checking JSON…', 'elementor-json-bridge' ),
					'source'                => __( 'Detected JSON', 'elementor-json-bridge' ),
					'recognized'            => __( 'Possible existing match', 'elementor-json-bridge' ),
					'highConfidence'        => __( 'Strong match', 'elementor-json-bridge' ),
					'mediumConfidence'      => __( 'Possible match', 'elementor-json-bridge' ),
					'useRecognized'         => __( 'Use this item', 'elementor-json-bridge' ),
					'chooseAction'          => __( 'What do you want to do?', 'elementor-json-bridge' ),
					'replace'               => __( 'Replace an existing item', 'elementor-json-bridge' ),
					'replaceHelp'           => __( 'Replace the Elementor content and page settings of an existing Page, Post, or compatible Template. Its WordPress title is kept.', 'elementor-json-bridge' ),
					'newPage'               => __( 'Create a new Page', 'elementor-json-bridge' ),
					'newPageHelp'           => __( 'Create a new WordPress Page as a draft.', 'elementor-json-bridge' ),
					'newPost'               => __( 'Create a new Post', 'elementor-json-bridge' ),
					'newPostHelp'           => __( 'Create a new WordPress Post as a draft.', 'elementor-json-bridge' ),
					'newTemplate'           => __( 'Create a new Elementor Template', 'elementor-json-bridge' ),
					'newTemplateHelp'       => __( 'Use Elementor’s own local template importer and keep the imported template in the Template Library.', 'elementor-json-bridge' ),
					'target'                => __( 'Item to replace', 'elementor-json-bridge' ),
					'searchTarget'          => __( 'Search by title or ID', 'elementor-json-bridge' ),
					'searching'             => __( 'Searching…', 'elementor-json-bridge' ),
					'noTargets'             => __( 'No compatible Elementor items found.', 'elementor-json-bridge' ),
					'confirmReplace'        => __( 'I understand that this replaces the selected item’s Elementor content. A rollback snapshot is created first.', 'elementor-json-bridge' ),
					'standardImport'        => __( 'Use standard Elementor import', 'elementor-json-bridge' ),
					'cancel'                => __( 'Cancel', 'elementor-json-bridge' ),
					'import'                => __( 'Import JSON', 'elementor-json-bridge' ),
					'importing'             => __( 'Importing…', 'elementor-json-bridge' ),
					'imported'              => __( 'Elementor JSON imported successfully.', 'elementor-json-bridge' ),
					'failed'                => __( 'The Elementor JSON import failed.', 'elementor-json-bridge' ),
					'invalidFile'           => __( 'Choose a single .json file no larger than 5 MB.', 'elementor-json-bridge' ),
					'targetRequired'        => __( 'Choose the existing item you want to replace.', 'elementor-json-bridge' ),
					'confirmationRequired'  => __( 'Confirm the replacement before importing.', 'elementor-json-bridge' ),
					'pageLikeRequired'      => __( 'This JSON type cannot be used to create a normal Page or Post.', 'elementor-json-bridge' ),
				],
			]
		);
	}
}
