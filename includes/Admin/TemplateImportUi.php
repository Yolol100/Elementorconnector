<?php

namespace Webactueel\ElementorJsonBridge\Admin;

use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;
use Webactueel\ElementorJsonBridge\Lifecycle\Hooks;

defined( 'ABSPATH' ) || exit;

final class TemplateImportUi {
	private const POST_TYPES = [ 'page', 'post' ];

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'restrict_manage_posts', [ $this, 'button' ], 20, 2 );
	}

	public function button( string $post_type, string $which ): void {
		if ( 'top' !== $which || ! in_array( $post_type, self::POST_TYPES, true ) || ! current_user_can( Hooks::CAPABILITY ) ) {
			return;
		}

		printf(
			'<button type="button" class="button ejb-template-import-trigger" aria-haspopup="dialog">%s</button>',
			esc_html__( 'Import Elementor template', 'elementor-json-bridge' )
		);
	}

	public function assets( string $hook_suffix ): void {
		if ( 'edit.php' !== $hook_suffix || ! current_user_can( Hooks::CAPABILITY ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( (string) $screen->post_type, self::POST_TYPES, true ) ) {
			return;
		}

		$destination = (string) $screen->post_type;
		$type_object = get_post_type_object( $destination );
		$label       = $type_object?->labels->singular_name ?? ( 'page' === $destination ? __( 'Page', 'elementor-json-bridge' ) : __( 'Post', 'elementor-json-bridge' ) );

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
				'restUrl'          => esc_url_raw( rest_url( 'ejb/v1' ) ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'maxBytes'         => PayloadValidator::MAX_BYTES,
				'destination'      => $destination,
				'destinationLabel' => $label,
				'strings'          => [
					'title'                => __( 'Import Elementor template', 'elementor-json-bridge' ),
					'intro'                => sprintf(
						/* translators: %s: WordPress post type singular label, such as Page or Post. */
						__( 'Choose one Elementor JSON file. By default a new %s is created as a draft.', 'elementor-json-bridge' ),
						$label
					),
					'chooseFile'           => __( 'Choose JSON file', 'elementor-json-bridge' ),
					'fileHelp'             => __( 'One .json file up to 5 MB. Elementor\'s own Templates import remains unchanged for normal template and ZIP imports.', 'elementor-json-bridge' ),
					'analyzing'            => __( 'Checking JSON…', 'elementor-json-bridge' ),
					'source'               => __( 'Detected JSON', 'elementor-json-bridge' ),
					'match'                => __( 'Detected existing item', 'elementor-json-bridge' ),
					'highConfidence'       => __( 'Strong match', 'elementor-json-bridge' ),
					'mediumConfidence'     => __( 'Unique title match', 'elementor-json-bridge' ),
					'replaceExisting'      => sprintf(
						/* translators: %s: WordPress post type singular label, such as Page or Post. */
						__( 'Replace this existing %s', 'elementor-json-bridge' ),
						$label
					),
					'replaceHelp'          => sprintf(
						/* translators: %s: WordPress post type singular label, such as Page or Post. */
						__( 'Checked: replace the detected %s. Unchecked: leave it unchanged and create a new draft.', 'elementor-json-bridge' ),
						$label
					),
					'noMatch'              => sprintf(
						/* translators: %s: WordPress post type singular label, such as Page or Post. */
						__( 'No unique existing Elementor %s was found. Import will create a new draft.', 'elementor-json-bridge' ),
						$label
					),
					'cancel'               => __( 'Cancel', 'elementor-json-bridge' ),
					'import'               => __( 'Import template', 'elementor-json-bridge' ),
					'importing'            => __( 'Importing…', 'elementor-json-bridge' ),
					'created'              => sprintf(
						/* translators: %s: WordPress post type singular label, such as Page or Post. */
						__( 'New %s created as a draft.', 'elementor-json-bridge' ),
						$label
					),
					'replaced'             => sprintf(
						/* translators: %s: WordPress post type singular label, such as Page or Post. */
						__( 'Existing %s replaced with the imported Elementor content.', 'elementor-json-bridge' ),
						$label
					),
					'failed'               => __( 'The Elementor JSON import failed.', 'elementor-json-bridge' ),
					'invalidFile'          => __( 'Choose a single .json file no larger than 5 MB.', 'elementor-json-bridge' ),
					'matchChanged'         => __( 'The detected existing item changed. Analyze the JSON again before replacing it.', 'elementor-json-bridge' ),
				],
			]
		);
	}
}
