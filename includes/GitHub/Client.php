<?php

namespace Webactueel\ElementorJsonBridge\GitHub;

use RuntimeException;
use Webactueel\ElementorJsonBridge\Settings;
use Webactueel\ElementorJsonBridge\Support\BridgeException;

defined( 'ABSPATH' ) || exit;

final class Client {
	private const API_ROOT          = 'https://api.github.com';
	private const API_VERSION       = '2026-03-10';
	private const RATE_LIMIT_OPTION = 'ejb_github_rate_limit_until';

	private ?array $repository_cache = null;

	public function __construct( private readonly DeviceAuth $auth ) {}

	public function repository(): array {
		if ( null !== $this->repository_cache ) {
			return $this->repository_cache;
		}
		[ $owner, $repo ] = $this->repository_parts();
		$this->repository_cache = $this->request( 'GET', '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo ) );
		return $this->repository_cache;
	}

	public function assert_private_repository(): void {
		$repository = $this->repository();
		if ( empty( $repository['private'] ) ) {
			throw new BridgeException( 'ejb_public_repository', 'For safety, Elementor JSON Bridge only writes to private GitHub repositories.', 409 );
		}
	}

	public function get_file( string $path ): ?array {
		[ $owner, $repo ] = $this->repository_parts();
		$branch = (string) Settings::get( 'repo_branch', 'main' );
		$route  = '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo ) . '/contents/' . $this->encode_path( $path );

		$response = $this->request(
			'GET',
			$route,
			null,
			[ 'ref' => $branch ],
			[ 404 ],
			'application/vnd.github.object+json'
		);
		if ( 404 === (int) ( $response['_status'] ?? 200 ) ) {
			return null;
		}
		if ( 'file' !== ( $response['type'] ?? '' ) || empty( $response['sha'] ) ) {
			throw new BridgeException( 'ejb_github_invalid_response', 'GitHub returned an unexpected file response.', 502 );
		}

		$sha = (string) $response['sha'];
		if ( 'base64' === ( $response['encoding'] ?? '' ) && isset( $response['content'] ) ) {
			$encoded = preg_replace( '/\s+/', '', (string) $response['content'] );
		} else {
			$blob    = $this->get_blob( $sha );
			$encoded = preg_replace( '/\s+/', '', (string) ( $blob['content'] ?? '' ) );
		}
		$content = is_string( $encoded ) ? base64_decode( $encoded, true ) : false;
		if ( false === $content ) {
			throw new BridgeException( 'ejb_github_invalid_response', 'GitHub returned invalid file content.', 502 );
		}

		return [
			'sha'     => $sha,
			'content' => $content,
			'path'    => (string) ( $response['path'] ?? $path ),
		];
	}

	public function put_file( string $path, string $content, ?string $sha, string $message ): array {
		[ $owner, $repo ] = $this->repository_parts();
		$route = '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo ) . '/contents/' . $this->encode_path( $path );
		$body  = [
			'message' => $message,
			'content' => base64_encode( $content ),
			'branch'  => (string) Settings::get( 'repo_branch', 'main' ),
		];
		if ( null !== $sha && '' !== $sha ) {
			$body['sha'] = $sha;
		}

		$response = $this->request( 'PUT', $route, $body );
		$new_sha  = $response['content']['sha'] ?? '';
		if ( ! is_string( $new_sha ) || '' === $new_sha ) {
			throw new BridgeException( 'ejb_github_invalid_response', 'GitHub did not return the updated file fingerprint.', 502 );
		}
		return [ 'sha' => $new_sha ];
	}

	private function get_blob( string $sha ): array {
		[ $owner, $repo ] = $this->repository_parts();
		$route = '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo ) . '/git/blobs/' . rawurlencode( $sha );
		$blob = $this->request( 'GET', $route );
		if ( 'base64' !== ( $blob['encoding'] ?? '' ) || ! isset( $blob['content'] ) ) {
			throw new BridgeException( 'ejb_github_invalid_response', 'GitHub returned an unexpected blob response.', 502 );
		}
		return $blob;
	}

	private function request( string $method, string $route, ?array $json = null, array $query = [], array $allowed_statuses = [], string $accept = 'application/vnd.github+json' ): array {
		$this->assert_rate_limit_window();

		$url = self::API_ROOT . $route;
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}

		$args = [
			'method'              => $method,
			'timeout'             => 20,
			'redirection'         => 0,
			'limit_response_size' => 8_000_000,
			'headers'             => [
				'Accept'               => $accept,
				'Authorization'        => 'Bearer ' . $this->auth->access_token(),
				'X-GitHub-Api-Version' => self::API_VERSION,
				'User-Agent'           => 'Elementor-JSON-Bridge/' . ( defined( 'EJB_VERSION' ) ? EJB_VERSION : 'unknown' ),
			],
		];
		if ( null !== $json ) {
			$encoded = wp_json_encode( $json );
			if ( ! is_string( $encoded ) ) {
				throw new RuntimeException( 'Unable to encode the GitHub request.' );
			}
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = $encoded;
		}

		$response = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new BridgeException( 'ejb_github_unavailable', 'GitHub could not be reached. Try again.', 503 );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$this->capture_rate_limit( $response, $status );
		$body = wp_remote_retrieve_body( $response );
		$data = '' === $body ? [] : json_decode( $body, true );

		if ( in_array( $status, $allowed_statuses, true ) ) {
			return is_array( $data ) ? [ '_status' => $status ] + $data : [ '_status' => $status ];
		}
		if ( $status >= 200 && $status < 300 ) {
			if ( ! is_array( $data ) ) {
				throw new BridgeException( 'ejb_github_invalid_response', 'GitHub returned malformed JSON.', 502 );
			}
			return $data;
		}

		if ( 429 === $status || ( 403 === $status && '0' === (string) wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ) ) ) {
			throw new BridgeException( 'ejb_github_rate_limited', 'GitHub rate limiting is active. Try again after the cooldown.', 429 );
		}
		if ( in_array( $status, [ 409, 422 ], true ) ) {
			throw new BridgeException( 'ejb_github_conflict', 'GitHub changed before this operation completed. Check GitHub again.', 409 );
		}
		if ( in_array( $status, [ 401, 403 ], true ) ) {
			throw new BridgeException( 'ejb_github_forbidden', 'GitHub authorization is invalid or does not have access to this repository.', 403 );
		}
		if ( 404 === $status ) {
			throw new BridgeException( 'ejb_github_not_found', 'The configured GitHub repository or branch could not be found.', 404 );
		}
		if ( $status >= 500 ) {
			throw new BridgeException( 'ejb_github_unavailable', 'GitHub is temporarily unavailable. Try again.', 503 );
		}
		throw new BridgeException( 'ejb_github_request_rejected', 'GitHub rejected the request.', 502 );
	}

	private function assert_rate_limit_window(): void {
		$until = (int) get_option( self::RATE_LIMIT_OPTION, 0 );
		if ( $until <= time() ) {
			if ( $until > 0 ) {
				delete_option( self::RATE_LIMIT_OPTION );
			}
			return;
		}
		throw new BridgeException( 'ejb_github_rate_limited', 'GitHub rate limiting is active. Try again after the cooldown.', 429 );
	}

	private function capture_rate_limit( array $response, int $status ): void {
		$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
		$remaining   = (string) wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
		$reset_at    = (int) wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );

		if ( 429 !== $status && $retry_after < 1 && ! ( 403 === $status && '0' === $remaining ) ) {
			return;
		}

		$until = time() + max( 60, $retry_after );
		if ( $reset_at > $until ) {
			$until = $reset_at;
		}
		update_option( self::RATE_LIMIT_OPTION, $until, false );
	}

	private function repository_parts(): array {
		$owner = (string) Settings::get( 'repo_owner', '' );
		$repo  = (string) Settings::get( 'repo_name', '' );
		if ( '' === $owner || '' === $repo ) {
			throw new BridgeException( 'ejb_repository_missing', 'Configure the GitHub repository first.', 400 );
		}
		return [ $owner, $repo ];
	}

	private function encode_path( string $path ): string {
		$path = Settings::sanitize_repo_path( $path );
		if ( '' === $path ) {
			throw new RuntimeException( 'The GitHub file path is invalid.' );
		}
		return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	}
}
