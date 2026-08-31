<?php

namespace Webactueel\ElementorJsonBridge\Admin;

use RuntimeException;
use Throwable;
use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;
use Webactueel\ElementorJsonBridge\Elementor\TemplateImporter;
use Webactueel\ElementorJsonBridge\Lifecycle\Hooks;

defined( 'ABSPATH' ) || exit;

final class TemplateImportController {
	private const NS = 'ejb/v1';

	public function __construct( private readonly TemplateImporter $importer ) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			self::NS,
			'/template-import/analyze',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'analyze' ],
				'permission_callback' => [ $this, 'permission' ],
				'args'                => [
					'destination' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => [ $this, 'validate_destination' ],
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/template-import/execute',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'execute' ],
				'permission_callback' => [ $this, 'permission' ],
				'args'                => [
					'destination'        => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => [ $this, 'validate_destination' ],
					],
					'replace_existing'   => [
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					],
					'expected_target_id' => [
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	public function permission(): bool {
		return current_user_can( Hooks::CAPABILITY );
	}

	public function validate_destination( mixed $value ): bool {
		return is_string( $value ) && in_array( $value, [ 'page', 'post' ], true );
	}

	public function analyze( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$file        = $this->json_file( $request );
			$destination = sanitize_key( (string) $request->get_param( 'destination' ) );
			$result      = $this->importer->analyze( $file['json'], $file['name'], $destination );
			return new \WP_REST_Response( [ 'ok' => true ] + $result );
		} catch ( RuntimeException $exception ) {
			return new \WP_Error( 'ejb_template_import_invalid', $exception->getMessage(), [ 'status' => 400 ] );
		} catch ( Throwable ) {
			return new \WP_Error( 'ejb_template_import_error', 'The Elementor JSON file could not be analyzed.', [ 'status' => 500 ] );
		}
	}

	public function execute( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$file               = $this->json_file( $request );
			$destination        = sanitize_key( (string) $request->get_param( 'destination' ) );
			$replace_existing   = rest_sanitize_boolean( $request->get_param( 'replace_existing' ) );
			$expected_target_id = absint( $request->get_param( 'expected_target_id' ) );
			$result             = $this->importer->execute(
				$file['json'],
				$file['name'],
				$destination,
				$replace_existing,
				$expected_target_id
			);
			return new \WP_REST_Response( [ 'ok' => true, 'result' => $result ] );
		} catch ( RuntimeException $exception ) {
			return new \WP_Error( 'ejb_template_import_failed', $exception->getMessage(), [ 'status' => 400 ] );
		} catch ( Throwable ) {
			return new \WP_Error( 'ejb_template_import_failed', 'The Elementor JSON import could not be completed.', [ 'status' => 500 ] );
		}
	}

	private function json_file( \WP_REST_Request $request ): array {
		$files = $request->get_file_params();
		$file  = $files['file'] ?? null;
		if ( ! is_array( $file ) ) {
			throw new RuntimeException( 'Choose an Elementor JSON file.' );
		}

		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $error ) {
			throw new RuntimeException( 'The Elementor JSON upload did not complete.' );
		}

		$name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		if ( 'json' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			throw new RuntimeException( 'Page/Post import accepts one .json file. Use Elementor Templates for ZIP archives and other template imports.' );
		}

		$tmp_name = (string) ( $file['tmp_name'] ?? '' );
		$size     = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( '' === $tmp_name || ! is_readable( $tmp_name ) || $size < 1 || $size > PayloadValidator::MAX_BYTES ) {
			throw new RuntimeException( 'The Elementor JSON file must be readable and no larger than 5 MB.' );
		}

		$json = file_get_contents( $tmp_name );
		if ( ! is_string( $json ) || '' === trim( $json ) || strlen( $json ) > PayloadValidator::MAX_BYTES ) {
			throw new RuntimeException( 'The Elementor JSON file could not be read safely.' );
		}

		return [ 'name' => $name, 'json' => $json ];
	}
}
