<?php

namespace Webactueel\ElementorJsonBridge\Content;

use RuntimeException;
defined( 'ABSPATH' ) || exit;

final class AbilityBridge {
	private const MAX_OUTPUT_BYTES = 1000000;

	public const FORMAT  = 'elementor-json-bridge/run-ability';
	public const VERSION = 2;

	private const PREFIXES = [ 'core/', 'acf/', 'yoast-seo/', 'woocommerce/product-', 'woocommerce/products-' ];

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
			if ( ! $this->allowed_name( $name ) || ! is_object( $ability ) || ! $this->is_exposed( $ability ) ) {
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
			throw new RuntimeException( 'Only supported WordPress, ACF, Yoast and WooCommerce product abilities are exposed through this bridge.' );
		}
		if ( ! $this->available() ) {
			throw new RuntimeException( 'The WordPress Abilities API is not available on this site.' );
		}
		$ability = wp_get_ability( $name );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'execute' ) || ! $this->is_exposed( $ability ) ) {
			throw new RuntimeException( 'The requested ability is not registered for agent access on this site.' );
		}
		$descriptor = $this->descriptor( $name, $ability );
		if ( ! $descriptor['executable'] ) {
			throw new RuntimeException( 'Only abilities explicitly annotated readonly are executable through the GitHub bridge. Use guarded versioned CRUD requests for mutations.' );
		}
		$input = $request['input'] ?? null;
		try {
			$output = $ability->execute( $input );
		} catch ( \Throwable $throwable ) {
			throw new RuntimeException( 'The requested ability failed: ' . $throwable->getMessage(), 0, $throwable );
		}
		if ( is_wp_error( $output ) ) {
			throw new RuntimeException( 'The requested ability was rejected: ' . $output->get_error_message() );
		}
		$output = $this->json_safe_output( $output );
		return [ 'status' => 'executed', 'ability' => $name, 'output' => $output ];
	}

	private function json_safe_output( mixed $output ): mixed {
		$encoded = wp_json_encode( $output );
		if ( ! is_string( $encoded ) ) {
			throw new RuntimeException( 'The ability returned output that cannot be represented as JSON.' );
		}
		if ( self::MAX_OUTPUT_BYTES < strlen( $encoded ) ) {
			throw new RuntimeException( 'The ability output is too large for the GitHub bridge.' );
		}
		$decoded = json_decode( $encoded, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new RuntimeException( 'The ability returned invalid JSON output.' );
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

	private function is_exposed( object $ability ): bool {
		$meta = method_exists( $ability, 'get_meta' ) ? $ability->get_meta() : [];
		if ( ! is_array( $meta ) ) {
			return false;
		}
		return true === ( $meta['public'] ?? false )
			|| true === ( $meta['show_in_rest'] ?? false )
			|| ( is_array( $meta['mcp'] ?? null ) && true === ( $meta['mcp']['public'] ?? false ) );
	}

	private function descriptor( string $name, object $ability ): array {
		$meta        = method_exists( $ability, 'get_meta' ) ? $ability->get_meta() : [];
		$annotations = is_array( $meta ) && is_array( $meta['annotations'] ?? null ) ? $meta['annotations'] : [];
		return [
			'name'          => $name,
			'executable'    => true === ( $annotations['readonly'] ?? false ),
			'label'         => method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : $name,
			'description'   => method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '',
			'category'      => method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '',
			'input_schema'  => method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null,
			'output_schema' => method_exists( $ability, 'get_output_schema' ) ? $ability->get_output_schema() : null,
			'annotations'   => $annotations,
		];
	}
}
