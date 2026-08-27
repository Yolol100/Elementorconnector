<?php

namespace Webactueel\ElementorJsonBridge\Admin;

use RuntimeException;
use Throwable;
use Webactueel\ElementorJsonBridge\Elementor\Documents;
use Webactueel\ElementorJsonBridge\GitHub\Client;
use Webactueel\ElementorJsonBridge\GitHub\DeviceAuth;
use Webactueel\ElementorJsonBridge\Lifecycle\Hooks;
use Webactueel\ElementorJsonBridge\Settings;
use Webactueel\ElementorJsonBridge\Sync\Manager;

defined( 'ABSPATH' ) || exit;

final class RestController {
	private const NS = 'ejb/v1';

	public function __construct(
		private readonly DeviceAuth $auth,
		private readonly Client $github,
		private readonly Documents $documents,
		private readonly Manager $sync
	) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		$this->post_route( '/auth/device', [ $this, 'device_start' ] );
		$this->post_route( '/auth/device/poll', [ $this, 'device_poll' ] );
		$this->post_route( '/auth/disconnect', [ $this, 'disconnect' ] );
		$this->post_route( '/repository/test', [ $this, 'repository_test' ] );
		$this->post_route( '/documents/(?P<id>\d+)/toggle', [ $this, 'toggle' ], [ 'id' => [ 'validate_callback' => [ $this, 'validate_positive_int' ] ] ] );
		$this->post_route( '/documents/(?P<id>\d+)/export', [ $this, 'export' ], [ 'id' => [ 'validate_callback' => [ $this, 'validate_positive_int' ] ] ] );
		$this->post_route( '/documents/(?P<id>\d+)/check', [ $this, 'check' ], [ 'id' => [ 'validate_callback' => [ $this, 'validate_positive_int' ] ] ] );
		$this->post_route( '/documents/(?P<id>\d+)/apply', [ $this, 'apply' ], [ 'id' => [ 'validate_callback' => [ $this, 'validate_positive_int' ] ] ] );
		$this->post_route( '/documents/(?P<id>\d+)/reset', [ $this, 'reset' ], [ 'id' => [ 'validate_callback' => [ $this, 'validate_positive_int' ] ] ] );
		$this->post_route(
			'/documents/(?P<id>\d+)/restore/(?P<snapshot>\d+)',
			[ $this, 'restore' ],
			[
				'id'       => [ 'validate_callback' => [ $this, 'validate_positive_int' ] ],
				'snapshot' => [ 'validate_callback' => [ $this, 'validate_positive_int' ] ],
			]
		);
	}

	public function validate_positive_int( mixed $value ): bool {
		return is_numeric( $value ) && (int) $value > 0;
	}

	public function permission(): bool {
		return current_user_can( Hooks::CAPABILITY );
	}

	public function device_start(): \WP_REST_Response|\WP_Error {
		return $this->respond( fn (): array => $this->auth->start( get_current_user_id() ) );
	}

	public function device_poll(): \WP_REST_Response|\WP_Error {
		return $this->respond( fn (): array => $this->auth->poll( get_current_user_id() ) );
	}

	public function disconnect(): \WP_REST_Response {
		$this->auth->disconnect( get_current_user_id() );
		return new \WP_REST_Response( [ 'ok' => true, 'status' => 'disconnected' ] );
	}

	public function repository_test(): \WP_REST_Response|\WP_Error {
		return $this->respond(
			function (): array {
				if ( ! Settings::repo_is_configured() ) {
					throw new RuntimeException( 'Save the repository settings first.' );
				}
				$this->github->assert_private_repository();
				$repo = $this->github->repository();
				return [
					'ok'             => true,
					'full_name'      => sanitize_text_field( (string) ( $repo['full_name'] ?? '' ) ),
					'default_branch' => sanitize_text_field( (string) ( $repo['default_branch'] ?? '' ) ),
					'private'        => ! empty( $repo['private'] ),
				];
			}
		);
	}

	public function toggle( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->document_action( $request, fn ( int $id ): array => [ 'enabled' => $this->sync->toggle( $id ) ] );
	}

	public function export( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->document_action( $request, fn ( int $id ): array => $this->sync->export( $id ) );
	}

	public function check( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->document_action( $request, fn ( int $id ): array => $this->sync->check_remote( $id ) );
	}

	public function apply( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->document_action( $request, fn ( int $id ): array => $this->sync->apply_remote( $id ) );
	}

	public function reset( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->document_action( $request, fn ( int $id ): array => $this->sync->reset_base( $id ) );
	}

	public function restore( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$snapshot = absint( $request['snapshot'] );
		return $this->document_action( $request, fn ( int $id ): array => $this->sync->restore_snapshot( $id, $snapshot ) );
	}

	private function document_action( \WP_REST_Request $request, callable $action ): \WP_REST_Response|\WP_Error {
		$id = absint( $request['id'] );
		return $this->respond(
			function () use ( $id, $action ): array {
				if ( $id < 1 || ! get_post( $id ) || ! current_user_can( 'edit_post', $id ) ) {
					throw new RuntimeException( 'You are not allowed to edit this document.' );
				}
				if ( ! $this->documents->is_elementor_document( $id ) ) {
					throw new RuntimeException( 'This is not an editable Elementor document.' );
				}
				$result = $action( $id );
				return [ 'ok' => true ] + $result;
			}
		);
	}

	private function post_route( string $route, callable $callback, array $args = [] ): void {
		register_rest_route(
			self::NS,
			$route,
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => $callback,
				'permission_callback' => [ $this, 'permission' ],
				'args'                => $args,
			]
		);
	}

	private function respond( callable $callback ): \WP_REST_Response|\WP_Error {
		try {
			$result = $callback();
			return new \WP_REST_Response( is_array( $result ) ? [ 'ok' => true ] + $result : [ 'ok' => true ] );
		} catch ( Throwable $throwable ) {
			return new \WP_Error( 'ejb_error', $throwable->getMessage(), [ 'status' => 400 ] );
		}
	}
}
