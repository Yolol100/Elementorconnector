<?php

namespace Webactueel\ElementorJsonBridge\GitHub;

use RuntimeException;
use Webactueel\ElementorJsonBridge\Settings;

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
			throw new RuntimeException( 'For safety, Elementor JSON Bridge only writes to private GitHub repositories.' );
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
			throw new RuntimeException( 'GitHub returned an unexpected file response.' );
		}

		$sha = (string) $response['sha'];
		if ( 'base64' === ( $response['encoding'] ?? '' ) && isset( $response['content'] ) ) {
			$encoded = preg_replace( '/\s+/', '', (string) $response['content'] );
			if ( ! is_string( $encoded ) ) {
				$encoded = '';
			}
			$content = base64_decode( $encoded, true );
		} else {
			$blob    = $this->get_blob( $sha );
			$encoded = preg_replace( '/\s+/', '', (string) ( $blob['content'] ?? '' ) );
			if ( ! is_string( $encoded ) ) {
				$encoded = '';
			}
			$content = base64_decode( $encoded, true );
		}
		if ( false === $content ) {
			throw new RuntimeException( 'GitHub returned invalid base64 file content.' );
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
			throw new RuntimeException( 'GitHub did not return the updated file SHA.' );
		}
		return [ 'sha' => $new_sha ];
	}

	private function get_blob( string $sha ): array {
		[ $owner, $repo ] = $this->repository_parts();
		$route = '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo ) . '/git/blobs/' . rawurlencode( $sha );
		$blob  = $this->request( 'GET', $route );
		if ( 'base64' !== ( $blob['encoding'] ?? '' ) || ! isset( $blob['content'] ) ) {
			throw new RuntimeException( 'GitHub returned an unexpected blob response.' );
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
			$args['body']                    = $encoded;
		}

		$response = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'GitHub request failed.' );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$this->capture_rate_limit( $response, $status );
		$body = wp_remote_retrieve_body( $response );
		$data = '' === $body ? [] : json_decode( $body, true );
		if ( in_array( $status, $allowed_statuses, true ) ) {
			return is_array( $data ) ? [ '_status' => $status ] + $data : [ '_status' => $status ];
		}
		if ( $status < 200 || $status >= 300 ) {
			$this->throw_http_error( $status );
		}
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'GitHub returned malformed JSON.' );
		}
		return $data;
	}

	private function throw_http_error( int $status ): never {
		if ( 401 === $status ) {
			throw new RuntimeException( 'GitHub rejected the authentication token.' );
		}
		if ( 403 === $status ) {
			throw new RuntimeException( 'GitHub denied this request.' );
		}
		if ( 409 === $status ) {
			throw new RuntimeException( 'GitHub reported a repository state conflict.' );
		}
		if ( 422 === $status ) {
			throw new RuntimeException( 'GitHub rejected the repository update.' );
		}
		if ( 429 === $status ) {
			throw new RuntimeException( 'GitHub rate limiting is active.' );
		}
		throw new RuntimeException( 'GitHub returned an unexpected HTTP error.' );
	}

	private function assert_rate_limit_window(): void {
		$until = (int) get_option( self::RATE_LIMIT_OPTION, 0 );
		if ( $until <= time() ) {
			if ( $until > 0 ) {
				delete_option( self::RATE_LIMIT_OPTION );
			}
			return;
		}
		throw new RuntimeException( 'GitHub rate limiting is active. Try again after the current cooldown.' );
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
			throw new RuntimeException( 'Configure the GitHub repository first.' );
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
