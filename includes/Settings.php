<?php

namespace Webactueel\ElementorJsonBridge;

defined( 'ABSPATH' ) || exit;

final class Settings {
	public const OPTION      = 'ejb_settings';
	public const AUTH_OPTION = 'ejb_github_auth';

	private const DEFAULTS = [
		'github_client_id'         => '',
		'repo_owner'               => '',
		'repo_name'                => '',
		'repo_branch'              => 'main',
		'repo_root'                => 'site-data',
		'auto_export'              => 1,
		'auto_apply'               => 0,
		'auto_apply_actor'         => 0,
		'delete_data_on_uninstall' => 0,
	];

	public static function all(): array {
		$stored = get_option( self::OPTION, [] );
		return wp_parse_args( is_array( $stored ) ? $stored : [], self::DEFAULTS );
	}

	public static function get( string $key, mixed $fallback = null ): mixed {
		$settings = self::all();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
	}

	public static function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : [];

		$client_id = isset( $input['github_client_id'] ) ? sanitize_text_field( wp_unslash( $input['github_client_id'] ) ) : '';
		$owner     = isset( $input['repo_owner'] ) ? sanitize_text_field( wp_unslash( $input['repo_owner'] ) ) : '';
		$repo      = isset( $input['repo_name'] ) ? sanitize_text_field( wp_unslash( $input['repo_name'] ) ) : '';
		$branch    = isset( $input['repo_branch'] ) ? sanitize_text_field( wp_unslash( $input['repo_branch'] ) ) : 'main';
		$root      = isset( $input['repo_root'] ) ? sanitize_text_field( wp_unslash( $input['repo_root'] ) ) : 'site-data';

		$client_id = preg_replace( '/[^A-Za-z0-9._-]/', '', $client_id ) ?? '';
		$owner     = preg_replace( '/[^A-Za-z0-9_.-]/', '', $owner ) ?? '';
		$repo      = preg_replace( '/[^A-Za-z0-9_.-]/', '', $repo ) ?? '';
		$branch    = preg_replace( '#[^A-Za-z0-9._/-]#', '', $branch ) ?? 'main';
		$branch    = trim( $branch, '/' );
		$root      = self::sanitize_repo_path( $root );

		if ( str_contains( $branch, '..' ) || '' === $branch ) {
			$branch = 'main';
		}

		$previous = get_option( self::OPTION, [] );
		$previous_client_id = is_array( $previous ) ? (string) ( $previous['github_client_id'] ?? '' ) : '';
		if ( '' !== $previous_client_id && ! hash_equals( $previous_client_id, $client_id ) ) {
			delete_option( self::AUTH_OPTION );
		}

		$previous_actor = is_array( $previous ) ? (int) ( $previous['auto_apply_actor'] ?? 0 ) : 0;
		$previous_apply = is_array( $previous ) ? (int) ( $previous['auto_apply'] ?? 0 ) : 0;

		return [
			'github_client_id'         => $client_id,
			'repo_owner'               => $owner,
			'repo_name'                => $repo,
			'repo_branch'              => $branch,
			'repo_root'                => '' !== $root ? $root : 'site-data',
			'auto_export'              => 1,
			'auto_apply'               => $previous_apply,
			'auto_apply_actor'         => $previous_actor,
			'delete_data_on_uninstall' => empty( $input['delete_data_on_uninstall'] ) ? 0 : 1,
		];
	}

	public static function mark_connected_actor( int $user_id ): void {
		if ( $user_id < 1 ) {
			return;
		}
		$settings = self::all();
		$settings['auto_export']      = 1;
		$settings['auto_apply']       = 1;
		$settings['auto_apply_actor'] = $user_id;
		update_option( self::OPTION, $settings, false );
	}

	public static function repo_is_configured(): bool {
		return '' !== self::get( 'repo_owner', '' )
			&& '' !== self::get( 'repo_name', '' )
			&& '' !== self::get( 'repo_branch', '' );
	}

	public static function sanitize_repo_path( string $path ): string {
		$path = str_replace( '\\', '/', trim( $path ) );
		$path = trim( $path, '/' );

		if ( '' === $path ) {
			return '';
		}

		$segments = array_filter( explode( '/', $path ), static fn ( string $part ): bool => '' !== $part );
		$clean    = [];

		foreach ( $segments as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				continue;
			}

			$segment = preg_replace( '/[^A-Za-z0-9._-]/', '-', $segment ) ?? '';
			$segment = trim( $segment, '.-' );
			if ( '' !== $segment ) {
				$clean[] = $segment;
			}
		}

		return implode( '/', $clean );
	}
}
