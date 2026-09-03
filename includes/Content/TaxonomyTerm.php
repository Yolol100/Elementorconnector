<?php

namespace Webactueel\ElementorJsonBridge\Content;

use RuntimeException;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

defined( 'ABSPATH' ) || exit;

final class TaxonomyTerm {
	public const FORMAT  = 'elementor-json-bridge/manage-term';
	public const VERSION = 1;

	public function execute( array $request ): array {
		$this->validate_request( $request );
		$taxonomy = sanitize_key( (string) $request['taxonomy'] );
		$object   = get_taxonomy( $taxonomy );
		if ( ! $object || empty( $object->show_ui ) ) {
			throw new RuntimeException( 'The requested taxonomy is not managed by WordPress.' );
		}

		$action = (string) $request['action'];
		$data   = is_array( $request['data'] ?? null ) ? $request['data'] : [];
		if ( 'create' === $action ) {
			if ( ! current_user_can( $object->cap->manage_terms ) ) {
				throw new RuntimeException( 'You are not allowed to create terms in this taxonomy.' );
			}
			$name = isset( $data['name'] ) && is_string( $data['name'] ) ? trim( $data['name'] ) : '';
			if ( '' === $name ) {
				throw new RuntimeException( 'Creating a taxonomy term requires a name.' );
			}
			$args   = $this->core_args( $data, true );
			$result = wp_insert_term( $name, $taxonomy, $args );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( 'WordPress rejected the taxonomy term creation.' );
			}
			$term_id = (int) $result['term_id'];
			try {
				$this->apply_extensions( $term_id, $taxonomy, $data );
				$readback = $this->payload( $term_id, $taxonomy );
				$this->assert_requested_state( $readback, $data );
			} catch ( \Throwable $throwable ) {
				wp_delete_term( $term_id, $taxonomy );
				throw $throwable;
			}
			return [ 'status' => 'created', 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'data' => $readback ];
		}

		$term_id = (int) ( $request['term_id'] ?? 0 );
		$term    = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			throw new RuntimeException( 'The requested taxonomy term does not exist.' );
		}

		if ( 'delete' === $action ) {
			if ( ! current_user_can( $object->cap->delete_terms ) ) {
				throw new RuntimeException( 'You are not allowed to delete terms in this taxonomy.' );
			}
			if ( true !== ( $request['confirm_destructive'] ?? false ) ) {
				throw new RuntimeException( 'Deleting a taxonomy term requires confirm_destructive=true.' );
			}
			$result = wp_delete_term( $term_id, $taxonomy );
			if ( true !== $result || get_term( $term_id, $taxonomy ) instanceof \WP_Term ) {
				throw new RuntimeException( 'WordPress could not verify deletion of the requested taxonomy term.' );
			}
			return [ 'status' => 'deleted', 'taxonomy' => $taxonomy, 'term_id' => $term_id ];
		}

		if ( ! current_user_can( $object->cap->edit_terms ) ) {
			throw new RuntimeException( 'You are not allowed to edit terms in this taxonomy.' );
		}
		$before = $this->payload( $term_id, $taxonomy );
		$this->validate_extensions( $term_id, $taxonomy, $data );
		$core = $this->core_args( $data, false );
		if ( isset( $data['name'] ) ) {
			if ( ! is_string( $data['name'] ) || '' === trim( $data['name'] ) ) {
				throw new RuntimeException( 'A taxonomy term name cannot be empty.' );
			}
			$core['name'] = $data['name'];
		}
		try {
			if ( $core ) {
				$result = wp_update_term( $term_id, $taxonomy, $core );
				if ( is_wp_error( $result ) ) {
					throw new RuntimeException( 'WordPress rejected the taxonomy term update.' );
				}
			}
			$this->apply_extensions( $term_id, $taxonomy, $data );
			$readback = $this->payload( $term_id, $taxonomy );
			$this->assert_requested_state( $readback, $data );
		} catch ( \Throwable $apply_error ) {
			try {
				$restored = wp_update_term(
					$term_id,
					$taxonomy,
					[
						'name'        => $before['name'],
						'slug'        => $before['slug'],
						'description' => $before['description'],
						'parent'      => $before['parent'],
					]
				);
				if ( is_wp_error( $restored ) ) {
					throw new RuntimeException( 'WordPress rejected taxonomy rollback.' );
				}
				$this->apply_acf( $term_id, $before['acf'] );
				$this->apply_yoast( $term_id, $taxonomy, $before['yoast'] );
				if ( ! hash_equals( CanonicalJson::hash( $before ), CanonicalJson::hash( $this->payload( $term_id, $taxonomy ) ) ) ) {
					throw new RuntimeException( 'Taxonomy rollback failed exact readback verification.' );
				}
			} catch ( \Throwable $rollback_error ) {
				throw new RuntimeException( 'Taxonomy update failed and rollback could not be verified: ' . $rollback_error->getMessage(), 0, $apply_error );
			}
			throw new RuntimeException( 'Taxonomy update failed. The previous term state was restored.', 0, $apply_error );
		}
		return [ 'status' => 'updated', 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'data' => $readback ];
	}

	public function inventory(): array {
		$result = [];
		foreach ( get_taxonomies( [ 'show_ui' => true ], 'objects' ) as $name => $object ) {
			if ( ! is_object( $object ) || ! isset( $object->cap ) ) {
				continue;
			}
			$terms = get_terms( [ 'taxonomy' => (string) $name, 'hide_empty' => false ] );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			$items = [];
			foreach ( $terms as $term ) {
				if ( $term instanceof \WP_Term ) {
					$items[] = [
						'id'          => (int) $term->term_id,
						'name'        => (string) $term->name,
						'slug'        => (string) $term->slug,
						'parent'      => (int) $term->parent,
						'description' => (string) $term->description,
					];
				}
			}
			$result[ (string) $name ] = [
				'label'        => (string) ( $object->label ?? $name ),
				'hierarchical' => ! empty( $object->hierarchical ),
				'terms'        => $items,
			];
		}
		ksort( $result, SORT_STRING );
		return $result;
	}

	private function validate_request( array $request ): void {
		$allowed = [ 'format', 'version', 'request_id', 'action', 'taxonomy', 'term_id', 'data', 'confirm_destructive', 'result' ];
		if ( array_diff( array_keys( $request ), $allowed ) ) {
			throw new RuntimeException( 'The taxonomy term request contains unsupported fields.' );
		}
		if ( self::FORMAT !== ( $request['format'] ?? null ) || self::VERSION !== (int) ( $request['version'] ?? 0 ) ) {
			throw new RuntimeException( 'The taxonomy term request format or version is invalid.' );
		}
		if ( ! in_array( (string) ( $request['action'] ?? '' ), [ 'create', 'update', 'delete' ], true ) || '' === sanitize_key( (string) ( $request['taxonomy'] ?? '' ) ) ) {
			throw new RuntimeException( 'The taxonomy term request action or taxonomy is invalid.' );
		}
		if ( 'create' !== (string) $request['action'] && (int) ( $request['term_id'] ?? 0 ) < 1 ) {
			throw new RuntimeException( 'Updating or deleting a term requires an exact term_id.' );
		}
		if ( isset( $request['data'] ) && ( ! is_array( $request['data'] ) || ( [] !== $request['data'] && array_is_list( $request['data'] ) ) ) ) {
			throw new RuntimeException( 'Taxonomy term data must be an object.' );
		}
		$data = is_array( $request['data'] ?? null ) ? $request['data'] : [];
		if ( array_diff( array_keys( $data ), [ 'name', 'slug', 'description', 'parent', 'acf', 'yoast' ] ) ) {
			throw new RuntimeException( 'The taxonomy term request contains unsupported data fields.' );
		}
	}

	private function core_args( array $data, bool $creating ): array {
		$args = [];
		foreach ( [ 'slug', 'description' ] as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				if ( ! is_string( $data[ $field ] ) ) {
					throw new RuntimeException( 'A taxonomy term text field is invalid.' );
				}
				$args[ $field ] = 'slug' === $field ? sanitize_title( $data[ $field ] ) : $data[ $field ];
			}
		}
		if ( array_key_exists( 'parent', $data ) ) {
			if ( ! is_int( $data['parent'] ) || $data['parent'] < 0 ) {
				throw new RuntimeException( 'The taxonomy term parent is invalid.' );
			}
			$args['parent'] = $data['parent'];
		}
		if ( $creating ) {
			unset( $args['name'] );
		}
		return $args;
	}

	private function payload( int $term_id, string $taxonomy ): array {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			throw new RuntimeException( 'The taxonomy term could not be read back.' );
		}
		return [
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => (string) $term->description,
			'parent'      => (int) $term->parent,
			'acf'         => $this->acf( $term_id ),
			'yoast'       => $this->yoast( $term, $taxonomy ),
		];
	}

	private function validate_extensions( int $term_id, string $taxonomy, array $data ): void {
		if ( array_key_exists( 'acf', $data ) ) {
			$this->validate_acf( $term_id, $data['acf'] );
		}
		if ( array_key_exists( 'yoast', $data ) ) {
			$this->validate_yoast( $term_id, $taxonomy, $data['yoast'] );
		}
	}

	private function apply_extensions( int $term_id, string $taxonomy, array $data ): void {
		if ( array_key_exists( 'acf', $data ) ) {
			$this->apply_acf( $term_id, $data['acf'] );
		}
		if ( array_key_exists( 'yoast', $data ) ) {
			$this->apply_yoast( $term_id, $taxonomy, $data['yoast'] );
		}
	}

	private function assert_requested_state( array $readback, array $requested ): void {
		foreach ( [ 'name', 'slug', 'description', 'parent' ] as $field ) {
			if ( ! array_key_exists( $field, $requested ) ) {
				continue;
			}
			$expected = 'slug' === $field ? sanitize_title( $requested[ $field ] ) : $requested[ $field ];
			if ( $readback[ $field ] !== $expected ) {
				throw new RuntimeException( 'Taxonomy term core data failed readback verification.' );
			}
		}
		foreach ( [ 'acf', 'yoast' ] as $section ) {
			if ( ! array_key_exists( $section, $requested ) ) {
				continue;
			}
			foreach ( $requested[ $section ] as $key => $value ) {
				if ( ! array_key_exists( $key, $readback[ $section ] ) || CanonicalJson::hash( [ 'value' => $readback[ $section ][ $key ] ] ) !== CanonicalJson::hash( [ 'value' => $value ] ) ) {
					throw new RuntimeException( 'Taxonomy extension data failed readback verification.' );
				}
			}
		}
	}

	private function acf( int $term_id ): array {
		if ( ! function_exists( 'get_field_objects' ) ) {
			return [];
		}
		$objects = get_field_objects( $this->acf_object_id( $term_id ), false, true, false );
		if ( ! is_array( $objects ) ) {
			return [];
		}
		$result = [];
		foreach ( $objects as $name => $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) || empty( $field['name'] ) ) {
				continue;
			}
			$result[ (string) $name ] = [ 'key' => (string) $field['key'], 'type' => (string) ( $field['type'] ?? '' ), 'value' => $field['value'] ?? null ];
		}
		ksort( $result, SORT_STRING );
		return $result;
	}

	private function validate_acf( int $term_id, mixed $acf ): void {
		if ( ! is_array( $acf ) || ( [] !== $acf && array_is_list( $acf ) ) ) {
			throw new RuntimeException( 'Taxonomy ACF data must be an object.' );
		}
		if ( [] === $acf ) {
			return;
		}
		if ( ! function_exists( 'get_field_objects' ) || ! function_exists( 'update_field' ) ) {
			throw new RuntimeException( 'ACF taxonomy data is present but Advanced Custom Fields is not active.' );
		}
		$current = $this->acf( $term_id );
		foreach ( $acf as $name => $field ) {
			$keys = is_array( $field ) ? array_keys( $field ) : [];
			sort( $keys, SORT_STRING );
			if ( ! isset( $current[ $name ] ) || [ 'key', 'type', 'value' ] !== $keys || $field['key'] !== $current[ $name ]['key'] || $field['type'] !== $current[ $name ]['type'] ) {
				throw new RuntimeException( 'The ACF taxonomy field identity no longer matches the site.' );
			}
		}
	}

	private function apply_acf( int $term_id, mixed $acf ): void {
		$this->validate_acf( $term_id, $acf );
		foreach ( $acf as $field ) {
			update_field( (string) $field['key'], $field['value'], $this->acf_object_id( $term_id ) );
		}
	}

	private function acf_object_id( int $term_id ): string {
		$term = get_term( $term_id );
		if ( ! $term instanceof \WP_Term ) {
			throw new RuntimeException( 'The taxonomy term no longer exists for ACF.' );
		}
		return $term->taxonomy . '_' . $term_id;
	}

	private function yoast( \WP_Term $term, string $taxonomy ): array {
		if ( ! class_exists( '\\WPSEO_Taxonomy_Meta' ) || ! method_exists( '\\WPSEO_Taxonomy_Meta', 'get_term_meta' ) ) {
			return [];
		}
		$values = \WPSEO_Taxonomy_Meta::get_term_meta( $term, $taxonomy );
		return is_array( $values ) ? $values : [];
	}

	private function validate_yoast( int $term_id, string $taxonomy, mixed $yoast ): void {
		if ( ! is_array( $yoast ) || ( [] !== $yoast && array_is_list( $yoast ) ) ) {
			throw new RuntimeException( 'Yoast taxonomy data must be an object.' );
		}
		if ( [] === $yoast ) {
			return;
		}
		if ( ! class_exists( '\\WPSEO_Taxonomy_Meta' ) || ! method_exists( '\\WPSEO_Taxonomy_Meta', 'get_term_meta' ) || ! method_exists( '\\WPSEO_Taxonomy_Meta', 'set_values' ) ) {
			throw new RuntimeException( 'Yoast taxonomy data is present but the supported Yoast taxonomy API is unavailable.' );
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			throw new RuntimeException( 'The taxonomy term no longer exists.' );
		}
		$current = \WPSEO_Taxonomy_Meta::get_term_meta( $term, $taxonomy );
		$current = is_array( $current ) ? $current : [];
		foreach ( $yoast as $key => $value ) {
			if ( ! is_string( $key ) || ! array_key_exists( $key, $current ) ) {
				throw new RuntimeException( 'The Yoast taxonomy request contains an unsupported field.' );
			}
		}
	}

	private function apply_yoast( int $term_id, string $taxonomy, mixed $yoast ): void {
		$this->validate_yoast( $term_id, $taxonomy, $yoast );
		if ( [] === $yoast ) {
			return;
		}
		$term    = get_term( $term_id, $taxonomy );
		$current = $term instanceof \WP_Term ? \WPSEO_Taxonomy_Meta::get_term_meta( $term, $taxonomy ) : [];
		$current = is_array( $current ) ? $current : [];
		foreach ( $yoast as $key => $value ) {
			$current[ $key ] = $value;
		}
		\WPSEO_Taxonomy_Meta::set_values( $term_id, $taxonomy, $current );
	}
}
