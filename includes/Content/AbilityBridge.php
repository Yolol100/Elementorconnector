<?php

namespace Webactueel\ElementorJsonBridge\Content;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class AbilityBridge {
	private const MAX_OUTPUT_BYTES = 1000000;

	public const FORMAT  = 'elementor-json-bridge/run-ability';
	public const VERSION = 1;

	private const PREFIXES = [ 'acf/', 'yoast-seo/' ];

	public function available(): bool {
		return function_exists( 'wp_get_ability' ) && function_exists( 'wp_get_abilities' );
	}

	public function catalog(): array {
		if ( ! $this->available() ) {
			return [ 'available' => false, 'abilities' => [] ];
		}
		$result = [];
		foreach ( wp_get_abilities() as $name => $ability ) {
			$name = is_string( $name ) ? $name : ( method_exists( $ability, 'get_name' ) ? (string) $ability->get_name() : '' );
			if ( ! $this->allowed_name( $name ) || ! is_object( $ability ) ) {
				continue;
			}
			$result[ $name ] = $this->descriptor( $name, $ability );
		}
		ksort( $result, SORT_STRING );
		return [ 'available' => true, 'abilities' => $result ];
	}

	public function execute( array $request ): array {
		$allowed = [ 'format', 'version', 'request_id', 'ability', 'input', 'confirm_destructive', 'result' ];
		if ( array_diff( array_keys( $request ), $allowed ) ) {
			throw new RuntimeException( 'The ability request contains unsupported fields.' );
		}
		if ( self::FORMAT !== ( $request['format'] ?? null ) || self::VERSION !== (int) ( $request['version'] ?? 0 ) ) {
			throw new RuntimeException( 'The ability request format or version is invalid.' );
		}
		$name = (string) ( $request['ability'] ?? '' );
		if ( ! $this->allowed_name( $name ) ) {
			throw new RuntimeException( 'Only ACF and Yoast abilities are exposed through this bridge.' );
		}
		if ( ! $this->available() ) {
			throw new RuntimeException( 'The WordPress Abilities API is not available on this site.' );
		}
		$ability = wp_get_ability( $name );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'execute' ) ) {
			throw new RuntimeException( 'The requested ACF or Yoast ability is not registered on this site.' );
		}
		$descriptor = $this->descriptor( $name, $ability );
		if ( ! empty( $descriptor['annotations']['destructive'] ) && true !== ( $request['confirm_destructive'] ?? false ) ) {
			throw new RuntimeException( 'This ability is marked destructive and requires confirm_destructive=true.' );
		}
		$input = $request['input'] ?? null;
		try {
			$output = $ability->execute( $input );
		} catch ( \Throwable $throwable ) {
			throw new RuntimeException( 'The requested ACF or Yoast ability failed: ' . $throwable->getMessage(), 0, $throwable );
		}
		if ( is_wp_error( $output ) ) {
			throw new RuntimeException( 'The requested ACF or Yoast ability was rejected: ' . $output->get_error_message() );
		}
		$output = $this->json_safe_output( $output );
		return [ 'status' => 'executed', 'ability' => $name, 'output' => $output ];
	}


	private function json_safe_output( mixed $output ): mixed {
		$encoded = wp_json_encode( $output );
		if ( ! is_string( $encoded ) ) {
			throw new RuntimeException( 'The ACF or Yoast ability returned output that cannot be represented as JSON.' );
		}
		if ( self::MAX_OUTPUT_BYTES < strlen( $encoded ) ) {
			throw new RuntimeException( 'The ACF or Yoast ability output is too large for the GitHub bridge.' );
		}
		$decoded = json_decode( $encoded, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new RuntimeException( 'The ACF or Yoast ability returned invalid JSON output.' );
		}
		return $decoded;
	}

	private function allowed_name( string $name ): bool {
		foreach ( self::PREFIXES as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	private function descriptor( string $name, object $ability ): array {
		$meta        = method_exists( $ability, 'get_meta' ) ? $ability->get_meta() : [];
		$annotations = is_array( $meta ) && is_array( $meta['annotations'] ?? null ) ? $meta['annotations'] : [];
		return [
			'name'          => $name,
			'label'         => method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : $name,
			'description'   => method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '',
			'category'      => method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '',
			'input_schema'  => method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null,
			'output_schema' => method_exists( $ability, 'get_output_schema' ) ? $ability->get_output_schema() : null,
			'annotations'   => $annotations,
		];
	}
}
