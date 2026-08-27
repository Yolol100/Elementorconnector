<?php

namespace Webactueel\ElementorJsonBridge\GitHub;

use RuntimeException;
use Webactueel\ElementorJsonBridge\Security\SecretBox;
use Webactueel\ElementorJsonBridge\Settings;
use Webactueel\ElementorJsonBridge\Support\BridgeException;

defined( 'ABSPATH' ) || exit;

final class DeviceAuth {
	private const DEVICE_ENDPOINT = 'https://github.com/login/device/code';
	private const TOKEN_ENDPOINT  = 'https://github.com/login/oauth/access_token';
	private const USER_META_KEY   = '_ejb_device_flow';

	public function __construct( private readonly SecretBox $secret_box ) {}

	public function is_connected(): bool {
		try {
			$tokens = $this->load_tokens();
			return ! empty( $tokens['access_token'] ) || ! empty( $tokens['refresh_token'] );
		} catch ( RuntimeException ) {
			return false;
		}
	}

	public function start( int $user_id ): array {
		$data = $this->post_form(
			self::DEVICE_ENDPOINT,
			[ 'client_id' => $this->client_id() ],
			'Unable to start GitHub authorization.'
		);

		foreach ( [ 'device_code', 'user_code', 'verification_uri', 'expires_in', 'interval' ] as $key ) {
			if ( ! isset( $data[ $key ] ) ) {
				throw new BridgeException( 'ejb_github_invalid_response', 'GitHub returned an incomplete authorization response.', 502 );
			}
		}

		$verification_uri = (string) $data['verification_uri'];
		if ( ! str_starts_with( $verification_uri, 'https://github.com/' ) ) {
			throw new BridgeException( 'ejb_github_invalid_response', 'GitHub returned an unexpected verification URL.', 502 );
		}

		$state = [
			'device_code'      => (string) $data['device_code'],
			'user_code'        => (string) $data['user_code'],
			'verification_uri' => $verification_uri,
			'expires_at'       => time() + max( 1, (int) $data['expires_in'] ),
			'interval'         => max( 5, (int) $data['interval'] ),
			'next_poll_at'     => time(),
		];
		$this->store_device_state( $user_id, $state );

		return [
			'user_code'        => $state['user_code'],
			'verification_uri' => $state['verification_uri'],
			'expires_in'       => max( 0, $state['expires_at'] - time() ),
			'interval'         => $state['interval'],
		];
	}

	public function poll( int $user_id ): array {
		$state = $this->load_device_state( $user_id );
		if ( empty( $state['device_code'] ) ) {
			throw new BridgeException( 'ejb_github_no_device_flow', 'No active GitHub authorization was found.', 409 );
		}
		if ( time() >= (int) ( $state['expires_at'] ?? 0 ) ) {
			$this->clear_device_state( $user_id );
			throw new BridgeException( 'ejb_github_device_expired', 'The GitHub verification code expired. Start again.', 409 );
		}
		if ( time() < (int) ( $state['next_poll_at'] ?? 0 ) ) {
			return [ 'status' => 'pending', 'retry_after' => max( 1, (int) $state['next_poll_at'] - time() ) ];
		}

		$data = $this->post_form(
			self::TOKEN_ENDPOINT,
			[
				'client_id'   => $this->client_id(),
				'device_code' => (string) $state['device_code'],
				'grant_type'  => 'urn:ietf:params:oauth:grant-type:device_code',
			],
			'Unable to complete GitHub authorization.'
		);

		if ( ! empty( $data['access_token'] ) ) {
			$this->store_tokens( $this->normalize_token_response( $data ) );
			$this->clear_device_state( $user_id );
			return [ 'status' => 'connected' ];
		}

		$error = (string) ( $data['error'] ?? 'unknown_error' );
		if ( 'slow_down' === $error ) {
			$state['interval'] = (int) $state['interval'] + 5;
		}
		if ( in_array( $error, [ 'authorization_pending', 'slow_down' ], true ) ) {
			$state['next_poll_at'] = time() + (int) $state['interval'];
			$this->store_device_state( $user_id, $state );
			return [ 'status' => 'pending', 'retry_after' => (int) $state['interval'] ];
		}

		$this->clear_device_state( $user_id );
		$messages = [
			'expired_token'        => 'The GitHub verification code expired.',
			'access_denied'        => 'GitHub authorization was cancelled.',
			'device_flow_disabled' => 'Device Flow is not enabled for this GitHub App.',
		];
		throw new BridgeException( 'ejb_github_authorization_failed', $messages[ $error ] ?? 'GitHub authorization failed.', 400 );
	}

	public function disconnect( int $user_id = 0 ): void {
		delete_option( Settings::AUTH_OPTION );
		delete_option( 'ejb_github_rate_limit_until' );
		if ( $user_id > 0 ) {
			$this->clear_device_state( $user_id );
		}
	}

	public function access_token(): string {
		$tokens = $this->load_tokens();
		if ( empty( $tokens['access_token'] ) ) {
			throw new BridgeException( 'ejb_github_not_connected', 'GitHub is not connected.', 409 );
		}

		$expires_at = (int) ( $tokens['expires_at'] ?? 0 );
		if ( $expires_at > 0 && $expires_at <= time() + 300 ) {
			$tokens = $this->refresh( $tokens );
		}

		return (string) $tokens['access_token'];
	}

	private function refresh( array $tokens ): array {
		if ( empty( $tokens['refresh_token'] ) || ( ! empty( $tokens['refresh_expires_at'] ) && (int) $tokens['refresh_expires_at'] <= time() ) ) {
			$this->disconnect();
			throw new BridgeException( 'ejb_github_reconnect_required', 'The GitHub session expired. Connect GitHub again.', 409 );
		}

		$data = $this->post_form(
			self::TOKEN_ENDPOINT,
			[
				'client_id'     => $this->client_id(),
				'grant_type'    => 'refresh_token',
				'refresh_token' => (string) $tokens['refresh_token'],
			],
			'Unable to refresh the GitHub session.'
		);
		if ( empty( $data['access_token'] ) ) {
			$this->disconnect();
			throw new BridgeException( 'ejb_github_reconnect_required', 'The GitHub session could not be refreshed. Connect GitHub again.', 409 );
		}

		$tokens = $this->normalize_token_response( $data );
		$this->store_tokens( $tokens );
		return $tokens;
	}

	private function client_id(): string {
		$client_id = (string) Settings::get( 'github_client_id', '' );
		if ( '' === $client_id ) {
			throw new BridgeException( 'ejb_github_client_id_missing', 'Add the GitHub App Client ID first.', 400 );
		}
		return $client_id;
	}

	private function load_device_state( int $user_id ): array {
		$encrypted = get_user_meta( $user_id, self::USER_META_KEY, true );
		if ( ! is_string( $encrypted ) || '' === $encrypted ) {
			return [];
		}
		try {
			return $this->secret_box->decrypt( $encrypted );
		} catch ( RuntimeException ) {
			$this->clear_device_state( $user_id );
			return [];
		}
	}

	private function store_device_state( int $user_id, array $state ): void {
		update_user_meta( $user_id, self::USER_META_KEY, $this->secret_box->encrypt( $state ) );
	}

	private function clear_device_state( int $user_id ): void {
		delete_user_meta( $user_id, self::USER_META_KEY );
	}

	private function load_tokens(): array {
		$encrypted = get_option( Settings::AUTH_OPTION, '' );
		if ( ! is_string( $encrypted ) || '' === $encrypted ) {
			throw new BridgeException( 'ejb_github_not_connected', 'GitHub is not connected.', 409 );
		}

		try {
			return $this->secret_box->decrypt( $encrypted );
		} catch ( RuntimeException $exception ) {
			delete_option( Settings::AUTH_OPTION );
			delete_option( 'ejb_github_rate_limit_until' );
			throw new BridgeException(
				'ejb_github_reconnect_required',
				'Stored GitHub credentials can no longer be decrypted. Connect GitHub again.',
				409,
				$exception
			);
		}
	}

	private function store_tokens( array $tokens ): void {
		$encrypted = $this->secret_box->encrypt( $tokens );
		delete_option( Settings::AUTH_OPTION );
		add_option( Settings::AUTH_OPTION, $encrypted, '', false );
	}

	private function normalize_token_response( array $data ): array {
		return [
			'access_token'       => (string) $data['access_token'],
			'expires_at'         => ! empty( $data['expires_in'] ) ? time() + (int) $data['expires_in'] : 0,
			'refresh_token'      => (string) ( $data['refresh_token'] ?? '' ),
			'refresh_expires_at' => ! empty( $data['refresh_token_expires_in'] ) ? time() + (int) $data['refresh_token_expires_in'] : 0,
			'token_type'         => (string) ( $data['token_type'] ?? 'bearer' ),
		];
	}

	private function post_form( string $url, array $body, string $fallback ): array {
		$response = wp_safe_remote_post(
			$url,
			[
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => [ 'Accept' => 'application/json' ],
				'body'        => $body,
			]
		);
		if ( is_wp_error( $response ) ) {
			throw new BridgeException( 'ejb_github_unavailable', $fallback . ' Try again.', 503 );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			throw new BridgeException( 'ejb_github_invalid_response', $fallback, 502 );
		}
		return $data;
	}
}
