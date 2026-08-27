<?php

namespace Webactueel\ElementorJsonBridge\GitHub;

use RuntimeException;
use Webactueel\ElementorJsonBridge\Security\SecretBox;
use Webactueel\ElementorJsonBridge\Settings;

defined( 'ABSPATH' ) || exit;

final class DeviceAuth {
	private const DEVICE_URL = 'https://github.com/login/device/code';
	private const TOKEN_URL  = 'https://github.com/login/oauth/access_token';
	private const STATE_META = '_ejb_device_flow';

	public function __construct( private readonly SecretBox $secrets ) {}

	public function is_connected(): bool {
		try {
			$tokens = $this->load_tokens();
			return ! empty( $tokens['access_token'] ) || ! empty( $tokens['refresh_token'] );
		} catch ( RuntimeException ) {
			return false;
		}
	}

	public function start( int $user_id ): array {
		$client_id = $this->client_id();
		$data      = $this->post_form( self::DEVICE_URL, [ 'client_id' => $client_id ] );
		foreach ( [ 'device_code', 'user_code', 'verification_uri', 'expires_in', 'interval' ] as $key ) {
			if ( ! isset( $data[ $key ] ) ) {
				throw new RuntimeException( 'GitHub returned an incomplete Device Flow response.' );
			}
		}

		$verify_url = (string) $data['verification_uri'];
		if ( ! str_starts_with( $verify_url, 'https://github.com/' ) ) {
			throw new RuntimeException( 'GitHub returned an unexpected verification URL.' );
		}

		$state = [
			'device_code'      => (string) $data['device_code'],
			'user_code'        => (string) $data['user_code'],
			'verification_uri' => $verify_url,
			'expires_at'       => time() + max( 1, (int) $data['expires_in'] ),
			'interval'         => max( 5, (int) $data['interval'] ),
			'next_poll_at'     => time(),
		];
		$this->store_state( $user_id, $state );

		return [
			'user_code'        => $state['user_code'],
			'verification_uri' => $verify_url,
			'expires_in'       => max( 0, $state['expires_at'] - time() ),
			'interval'         => $state['interval'],
		];
	}

	public function poll( int $user_id ): array {
		$state = $this->load_state( $user_id );
		if ( empty( $state['device_code'] ) ) {
			throw new RuntimeException( 'No active GitHub Device Flow was found.' );
		}
		if ( time() >= (int) ( $state['expires_at'] ?? 0 ) ) {
			$this->clear_state( $user_id );
			throw new RuntimeException( 'The GitHub verification code expired. Start again.' );
		}
		if ( time() < (int) ( $state['next_poll_at'] ?? 0 ) ) {
			return [ 'status' => 'pending', 'retry_after' => max( 1, (int) $state['next_poll_at'] - time() ) ];
		}

		$data = $this->post_form(
			self::TOKEN_URL,
			[
				'client_id'   => $this->client_id(),
				'device_code' => (string) $state['device_code'],
				'grant_type'  => 'urn:ietf:params:oauth:grant-type:device_code',
			]
		);

		if ( ! empty( $data['access_token'] ) ) {
			$this->store_tokens( $this->normalize_tokens( $data ) );
			$this->clear_state( $user_id );
			return [ 'status' => 'connected' ];
		}

		$error = (string) ( $data['error'] ?? 'unknown_error' );
		if ( 'slow_down' === $error ) {
			$state['interval'] = (int) $state['interval'] + 5;
		}
		if ( in_array( $error, [ 'authorization_pending', 'slow_down' ], true ) ) {
			$state['next_poll_at'] = time() + (int) $state['interval'];
			$this->store_state( $user_id, $state );
			return [ 'status' => 'pending', 'retry_after' => (int) $state['interval'] ];
		}

		$this->clear_state( $user_id );
		$messages = [
			'expired_token'        => 'The GitHub verification code expired.',
			'access_denied'        => 'GitHub authorization was cancelled.',
			'device_flow_disabled' => 'Device Flow is not enabled for this GitHub App.',
		];
		throw new RuntimeException( esc_html( $messages[ $error ] ?? 'GitHub authorization failed.' ) );
	}

	public function disconnect( int $user_id = 0 ): void {
		delete_option( Settings::AUTH_OPTION );
		delete_option( 'ejb_github_rate_limit_until' );
		if ( $user_id > 0 ) {
			$this->clear_state( $user_id );
		}
	}

	public function access_token(): string {
		$tokens = $this->load_tokens();
		if ( empty( $tokens['access_token'] ) ) {
			throw new RuntimeException( 'GitHub is not connected.' );
		}
		if ( ! empty( $tokens['expires_at'] ) && (int) $tokens['expires_at'] <= time() + 300 ) {
			$tokens = $this->refresh( $tokens );
		}
		return (string) $tokens['access_token'];
	}

	private function refresh( array $tokens ): array {
		if ( empty( $tokens['refresh_token'] ) || ( ! empty( $tokens['refresh_expires_at'] ) && (int) $tokens['refresh_expires_at'] <= time() ) ) {
			throw new RuntimeException( 'The GitHub session expired. Connect GitHub again.' );
		}
		$data = $this->post_form(
			self::TOKEN_URL,
			[
				'client_id'     => $this->client_id(),
				'grant_type'    => 'refresh_token',
				'refresh_token' => (string) $tokens['refresh_token'],
			]
		);
		if ( empty( $data['access_token'] ) ) {
			throw new RuntimeException( 'GitHub did not return a refreshed access token.' );
		}
		$tokens = $this->normalize_tokens( $data );
		$this->store_tokens( $tokens );
		return $tokens;
	}

	private function client_id(): string {
		$client_id = (string) Settings::get( 'github_client_id', '' );
		if ( '' === $client_id ) {
			throw new RuntimeException( 'Add the GitHub App Client ID first.' );
		}
		return $client_id;
	}

	private function post_form( string $url, array $body ): array {
		$response = wp_safe_remote_post( $url, [ 'timeout' => 15, 'headers' => [ 'Accept' => 'application/json' ], 'body' => $body ] );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'GitHub authorization request failed: ' . esc_html( $response->get_error_message() ) );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			throw new RuntimeException( 'GitHub returned an invalid authorization response.' );
		}
		return $data;
	}

	private function normalize_tokens( array $data ): array {
		return [
			'access_token'       => (string) $data['access_token'],
			'expires_at'         => ! empty( $data['expires_in'] ) ? time() + (int) $data['expires_in'] : 0,
			'refresh_token'      => (string) ( $data['refresh_token'] ?? '' ),
			'refresh_expires_at' => ! empty( $data['refresh_token_expires_in'] ) ? time() + (int) $data['refresh_token_expires_in'] : 0,
			'token_type'         => (string) ( $data['token_type'] ?? 'bearer' ),
		];
	}

	private function load_state( int $user_id ): array {
		$encrypted = get_user_meta( $user_id, self::STATE_META, true );
		if ( ! is_string( $encrypted ) || '' === $encrypted ) {
			return [];
		}
		try {
			return $this->secrets->decrypt( $encrypted );
		} catch ( RuntimeException ) {
			$this->clear_state( $user_id );
			return [];
		}
	}

	private function store_state( int $user_id, array $state ): void {
		update_user_meta( $user_id, self::STATE_META, $this->secrets->encrypt( $state ) );
	}

	private function clear_state( int $user_id ): void {
		delete_user_meta( $user_id, self::STATE_META );
	}

	private function load_tokens(): array {
		$encrypted = get_option( Settings::AUTH_OPTION, '' );
		if ( ! is_string( $encrypted ) || '' === $encrypted ) {
			throw new RuntimeException( 'GitHub is not connected.' );
		}
		return $this->secrets->decrypt( $encrypted );
	}

	private function store_tokens( array $tokens ): void {
		$encrypted = $this->secrets->encrypt( $tokens );
		delete_option( Settings::AUTH_OPTION );
		add_option( Settings::AUTH_OPTION, $encrypted, '', false );
	}
}
