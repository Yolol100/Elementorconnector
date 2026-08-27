<?php

namespace Webactueel\ElementorJsonBridge\Elementor;

use JsonException;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class PayloadValidator {
	public const FORMAT_VERSION = '0.4';
	public const MAX_BYTES      = 5_242_880;
	public const MAX_NODES      = 25_000;
	public const MAX_DEPTH      = 64;

	private int $nodes = 0;
	private array $ids = [];

	/** @throws RuntimeException */
	public function decode( string $json, ?string $expected_type = null ): array {
		if ( '' === trim( $json ) ) {
			throw new RuntimeException( 'The Elementor JSON file is empty.' );
		}
		if ( strlen( $json ) > self::MAX_BYTES ) {
			throw new RuntimeException( 'The Elementor JSON file is larger than 5 MB.' );
		}

		try {
			$data = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new RuntimeException( 'Invalid Elementor JSON.' );
		}

		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			throw new RuntimeException( 'The Elementor JSON root must be an object.' );
		}

		$required = [ 'title', 'type', 'version', 'page_settings', 'content' ];
		$unknown  = array_diff( array_keys( $data ), $required );
		$missing  = array_diff( $required, array_keys( $data ) );
		if ( $missing ) {
			throw new RuntimeException( 'The Elementor JSON document is missing required fields.' );
		}
		if ( $unknown ) {
			throw new RuntimeException( 'The Elementor JSON document contains unsupported top-level fields.' );
		}

		if ( ! is_string( $data['title'] ) || strlen( $data['title'] ) > 1000 ) {
			throw new RuntimeException( 'The document title is invalid.' );
		}
		if ( ! is_string( $data['type'] ) || ! preg_match( '/^[A-Za-z0-9_-]{1,80}$/', $data['type'] ) ) {
			throw new RuntimeException( 'The document type is invalid.' );
		}
		if ( null !== $expected_type && $data['type'] !== $expected_type ) {
			throw new RuntimeException( 'The JSON document type does not match the live Elementor document.' );
		}
		if ( self::FORMAT_VERSION !== $data['version'] ) {
			throw new RuntimeException( 'Unsupported Elementor JSON format version.' );
		}
		if ( ! is_array( $data['page_settings'] ) ) {
			throw new RuntimeException( 'page_settings must be an array or object.' );
		}
		if ( ! is_array( $data['content'] ) || ! array_is_list( $data['content'] ) ) {
			throw new RuntimeException( 'content must be a JSON array.' );
		}

		$this->nodes = 0;
		$this->ids   = [];
		$this->validate_elements( $data['content'], 0 );

		return $data;
	}

	/** @throws RuntimeException */
	public function validate_array( array $data, ?string $expected_type = null ): array {
		try {
			$json = json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} catch ( JsonException ) {
			throw new RuntimeException( 'Unable to encode Elementor data for validation.' );
		}
		return $this->decode( $json, $expected_type );
	}

	private function validate_elements( array $elements, int $depth ): void {
		if ( $depth > self::MAX_DEPTH ) {
			throw new RuntimeException( 'Element nesting is too deep.' );
		}

		foreach ( $elements as $element ) {
			++$this->nodes;
			if ( $this->nodes > self::MAX_NODES ) {
				throw new RuntimeException( 'The document contains too many elements.' );
			}
			if ( ! is_array( $element ) || array_is_list( $element ) ) {
				throw new RuntimeException( 'Every Elementor element must be an object.' );
			}

			$id = $element['id'] ?? null;
			if ( ! is_string( $id ) || ! preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $id ) ) {
				throw new RuntimeException( 'An Elementor element has an invalid ID.' );
			}
			if ( isset( $this->ids[ $id ] ) ) {
				throw new RuntimeException( 'The Elementor JSON document contains duplicate element IDs.' );
			}
			$this->ids[ $id ] = true;

			$el_type = $element['elType'] ?? null;
			if ( ! is_string( $el_type ) || '' === $el_type ) {
				throw new RuntimeException( 'An Elementor element is missing elType.' );
			}
			if ( 'widget' === $el_type && ( ! isset( $element['widgetType'] ) || ! is_string( $element['widgetType'] ) || '' === $element['widgetType'] ) ) {
				throw new RuntimeException( 'An Elementor widget is missing widgetType.' );
			}
			if ( ! array_key_exists( 'settings', $element ) || ! is_array( $element['settings'] ) ) {
				throw new RuntimeException( 'An Elementor element has invalid settings.' );
			}
			if ( ! array_key_exists( 'elements', $element ) || ! is_array( $element['elements'] ) || ! array_is_list( $element['elements'] ) ) {
				throw new RuntimeException( 'An Elementor element has an invalid elements list.' );
			}

			$this->validate_elements( $element['elements'], $depth + 1 );
		}
	}
}
