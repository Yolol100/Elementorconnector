<?php

namespace Webactueel\ElementorJsonBridge\Content;

use RuntimeException;
use Webactueel\ElementorJsonBridge\Backup\OperationSnapshots;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

defined( 'ABSPATH' ) || exit;

final class TaxonomyTerm {
	public const FORMAT  = 'elementor-json-bridge/manage-term';
	public const VERSION = 2;

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
			return $this->result( 'created', $taxonomy, $term_id, $readback );
		}

		$term_id = (int) ( $request['term_id'] ?? 0 );
		$term    = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			throw new RuntimeException( 'The requested taxonomy term does not exist.' );
		}
		$before = $this->payload( $term_id, $taxonomy );
		if ( 'read' === $action ) {
			if ( ! current_user_can( $object->cap->edit_terms ) ) {
				throw new RuntimeException( 'You are not allowed to read managed term state in this taxonomy.' );
			}
			return $this->result( 'read', $taxonomy, $term_id, $before );
		}
		$this->assert_base_hash( $before, $request );
		if ( 'delete' === $action ) {
			if ( ! current_user_can( $object->cap->delete_terms ) ) {
				throw new RuntimeException( 'You are not allowed to delete terms in this taxonomy.' );
			}
			if ( true !== ( $request['confirm_destructive'] ?? false ) ) {
				throw new RuntimeException( 'Deleting a taxonomy term requires confirm_destructive=true.' );
			}
			$snapshot_id = $this->operation_snapshots()->create( 'taxonomy_term', $taxonomy . ':' . $term_id, $before, 'before_term_delete' );
			$result = wp_delete_term( $term_id, $taxonomy );
			if ( true !== $result || get_term( $term_id, $taxonomy ) instanceof \WP_Term ) {
				throw new RuntimeException( 'WordPress could not verify deletion of the requested taxonomy term.' );
			}
			return [ 'status' => 'deleted', 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'snapshot_id' => $snapshot_id ];
		}

		if ( ! current_user_can( $object->cap->edit_terms ) ) {
			throw new RuntimeException( 'You are not allowed to edit terms in this taxonomy.' );
		}
		$snapshot_id = $this->operation_snapshots()->create( 'taxonomy_term', $taxonomy . ':' . $term_id, $before, 'before_term_update' );
		try {
			$this->validate_extensions( $term_id, $taxonomy, $data );
			$core = $this->core_args( $data, false );
			if ( isset( $data['name'] ) ) {
				if ( ! is_string( $data['name'] ) || '' === trim( $data['name'] ) ) {
					throw new RuntimeException( 'A taxonomy term name cannot be empty.' );
				}
				$core['name'] = $data['name'];
			}
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
				$rollback = $this->operation_snapshots()->payload( $snapshot_id, 'taxonomy_term', $taxonomy . ':' . $term_id );
				$this->restore_state( $term_id, $taxonomy, $rollback );
			} catch ( \Throwable $rollback_error ) {
				throw new RuntimeException( 'Taxonomy update failed and rollback could not be verified: ' . $rollback_error->getMessage(), 0, $apply_error );
			}
			throw new RuntimeException( 'Taxonomy update failed. The durable previous term state was restored.', 0, $apply_error );
		}
		$result = $this->result( 'updated', $taxonomy, $term_id, $readback );
		$result['snapshot_id'] = $snapshot_id;
		return $result;
	}

	private function result( string $status, string $taxonomy, int $term_id, array $data ): array {
		return [ 'status' => $status, 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'base_hash' => CanonicalJson::hash( $data ), 'data' => $data ];
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

	private function assert_base_hash( array $state, array $request ): void {
		$base_hash = (string) ( $request['base_hash'] ?? '' );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $base_hash ) ) {
			throw new RuntimeException( 'Updating or deleting a taxonomy term requires a valid base_hash.' );
		}
		if ( ! hash_equals( $base_hash, CanonicalJson::hash( $state ) ) ) {
			throw new RuntimeException( 'The taxonomy term changed after this request was authored. Read the term again and create a new request.' );
		}
	}

	private function restore_state( int $term_id, string $taxonomy, array $state ): void {
		$result = wp_update_term(
			$term_id,
			$taxonomy,
			[
				'name'        => $state['name'],
				'slug'        => $state['slug'],
				'description' => $state['description'],
				'parent'      => $state['parent'],
			]
		);
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( 'WordPress rejected taxonomy rollback.' );
		}
		$this->apply_acf( $term_id, $taxonomy, $state['acf'] );
		$this->apply_yoast( $term_id, $taxonomy, $state['yoast'] );
		if ( ! hash_equals( CanonicalJson::hash( $state ), CanonicalJson::hash( $this->payload( $term_id, $taxonomy ) ) ) ) {
			throw new RuntimeException( 'Taxonomy rollback failed exact readback verification.' );
		}
	}

	private function operation_snapshots(): OperationSnapshots {
		return new OperationSnapshots();
	}

	private function validate_request( array $request ): void {
		$allowed = [ 'format', 'version', 'request_id', 'action', 'taxonomy', 'term_id', 'data', 'base_hash', 'confirm_destructive', 'result' ];
		if ( array_diff( array_keys( $request ), $allowed ) ) {
			throw new RuntimeException( 'The taxonomy term request contains unsupported fields.' );
		}
		if ( self::FORMAT !== ( $request['format'] ?? null ) || self::VERSION !== (int) ( $request['version'] ?? 0 ) ) {
			throw new RuntimeException( 'The taxonomy term request format or version is invalid. Regenerate legacy version-1 manage-term requests as version 2.' );
		}
		$action = (string) ( $request['action'] ?? '' );
		if ( ! in_array( $action, [ 'create', 'read', 'update', 'delete' ], true ) || '' === sanitize_key( (string) ( $request['taxonomy'] ?? '' ) ) ) {
			throw new RuntimeException( 'The taxonomy term request action or taxonomy is invalid.' );
		}
		if ( 'create' !== $action && (int) ( $request['term_id'] ?? 0 ) < 1 ) {
			throw new RuntimeException( 'Reading, updating or deleting a term requires an exact term_id.' );
		}
		if ( in_array( $action, [ 'update', 'delete' ], true ) && ( ! is_string( $request['base_hash'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $request['base_hash'] ) ) ) {
			throw new RuntimeException( 'Updating or deleting a term requires a valid base_hash from a version-2 read result.' );
		}
		if ( isset( $request['data'] ) && ( ! is_array( $request['data'] ) || ( [] !== $request['data'] && array_is_list( $request['data'] ) ) ) ) {
			throw new RuntimeException( 'Taxonomy term data must be an object.' );
		}
		if ( 'read' === $action && ( array_key_exists( 'data', $request ) || array_key_exists( 'confirm_destructive', $request ) ) ) {
			throw new RuntimeException( 'A taxonomy term read request cannot contain mutation fields.' );
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
			$this->validate_acf( $term_id, $taxonomy, $data['acf'] );
		}
		if ( array_key_exists( 'yoast', $data ) ) {
			$this->validate_yoast( $term_id, $taxonomy, $data['yoast'] );
		}
	}

	private function apply_extensions( int $term_id, string $taxonomy, array $data ): void {
		if ( array_key_exists( 'acf', $data ) ) {
			$this->apply_acf( $term_id, $taxonomy, $data['acf'] );
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
		$objects = get_field_objects( 'term_' . $term_id, false, true, false );
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

	private function validate_acf( int $term_id, string $taxonomy, mixed $acf ): void {
		if ( ! is_array( $acf ) || ( [] !== $acf && array_is_list( $acf ) ) ) {
			throw new RuntimeException( 'Taxonomy ACF data must be an object.' );
		}
		if ( [] === $acf ) {
			return;
		}
		if ( ! function_exists( 'get_field_objects' ) || ! function_exists( 'update_field' ) ) {
			throw new RuntimeException( 'ACF taxonomy data is present but Advanced Custom Fields is not active.' );
		}
		$current            = $this->acf( $term_id );
		$allowed_group_keys = [];
		if ( function_exists( 'acf_get_field_groups' ) ) {
			$groups = acf_get_field_groups( [ 'taxonomy' => $taxonomy ] );
			foreach ( is_array( $groups ) ? $groups : [] as $group ) {
				if ( is_array( $group ) && is_string( $group['key'] ?? null ) ) {
					$allowed_group_keys[] = $group['key'];
				}
			}
		}
		foreach ( $acf as $name => $field ) {
			$keys = is_array( $field ) ? array_keys( $field ) : [];
			sort( $keys, SORT_STRING );
			$identity = $current[ $name ] ?? null;
			if ( null === $identity && function_exists( 'get_field_object' ) && is_array( $field ) ) {
				$candidate = get_field_object( (string) ( $field['key'] ?? '' ), 'term_' . $term_id, false, false );
				if (
					is_array( $candidate )
					&& (string) ( $candidate['name'] ?? '' ) === (string) $name
					&& in_array( (string) ( $candidate['parent'] ?? '' ), $allowed_group_keys, true )
				) {
					$identity = [ 'key' => (string) $candidate['key'], 'type' => (string) ( $candidate['type'] ?? '' ), 'value' => null ];
				}
			}
			if ( ! is_array( $identity ) || [ 'key', 'type', 'value' ] !== $keys || $field['key'] !== $identity['key'] || $field['type'] !== $identity['type'] ) {
				throw new RuntimeException( 'The ACF taxonomy field identity no longer matches the site.' );
			}
		}
	}

	private function apply_acf( int $term_id, string $taxonomy, mixed $acf ): void {
		$this->validate_acf( $term_id, $taxonomy, $acf );
		foreach ( $acf as $field ) {
			update_field( (string) $field['key'], $field['value'], 'term_' . $term_id );
		}
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
