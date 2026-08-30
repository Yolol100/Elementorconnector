<?php

namespace Webactueel\ElementorJsonBridge\Admin;

use RuntimeException;
use Throwable;
use Webactueel\ElementorJsonBridge\Elementor\LocalExport;
use Webactueel\ElementorJsonBridge\Lifecycle\Hooks;

defined( 'ABSPATH' ) || exit;

final class LocalExportController {
	private const NS = 'ejb/v1';

	public function __construct( private readonly LocalExport $exporter ) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			self::NS,
			'/local-export/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'export' ],
				'permission_callback' => [ $this, 'permission' ],
				'args'                => [
					'id'                 => [
						'validate_callback' => [ $this, 'validate_positive_int' ],
					],
					'include_site_parts' => [
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					],
				],
			]
		);
	}

	public function permission(): bool {
		return current_user_can( Hooks::CAPABILITY );
	}

	public function validate_positive_int( mixed $value ): bool {
		return is_numeric( $value ) && (int) $value > 0;
	}

	public function export( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = absint( $request['id'] );
		if ( $post_id < 1 || ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'ejb_export_forbidden', 'You are not allowed to export this document.', [ 'status' => 403 ] );
		}

		try {
			$result = $this->exporter->export( $post_id, rest_sanitize_boolean( $request->get_param( 'include_site_parts' ) ) );
			return new \WP_REST_Response( [ 'ok' => true ] + $result );
		} catch ( RuntimeException $exception ) {
			return new \WP_Error( 'ejb_export_error', $exception->getMessage(), [ 'status' => 400 ] );
		} catch ( Throwable ) {
			return new \WP_Error( 'ejb_export_error', 'The Elementor JSON export could not be completed.', [ 'status' => 500 ] );
		}
	}
}
