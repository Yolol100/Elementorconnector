<?php

namespace Webactueel\ElementorJsonBridge\Elementor;

use Throwable;

defined( 'ABSPATH' ) || exit;

final class AtomicCapabilities {
	private const ATOMIC_CONFIG_KEYS = [
		'atomic_controls',
		'atomic_props_schema',
		'atomic_style_states',
		'atomic_pseudo_states',
		'dependencies_per_target_mapping',
		'base_styles',
		'base_settings',
		'version',
		'show_in_panel',
		'default_children',
		'initial_attributes',
		'default_html_tag',
		'meta',
		'allowed_child_types',
	];

	public static function collect( object $elementor, array &$warnings ): array {
		return [
			'experiments'         => self::collect_experiments( $elementor, $warnings ),
			'atomic_style_schema' => self::collect_atomic_style_schema( $warnings ),
			'atomic_dynamic_tags' => self::collect_atomic_dynamic_tags( $warnings ),
			'global_classes'      => self::collect_global_classes( $elementor, $warnings ),
			'variables'           => self::collect_variables( $elementor, $warnings ),
			'components'          => self::collect_components( $warnings ),
			'interactions'        => self::collect_interactions( $elementor, $warnings ),
		];
	}

	public static function describe_component( object $component, array &$warnings ): array {
		$atomic = self::is_atomic( $component );
		$record = [ 'atomic' => $atomic ];

		if ( true !== $atomic ) {
			return $record;
		}

		$record['atomic_config'] = self::collect_atomic_config( $component, $warnings );
		return $record;
	}

	private static function is_atomic( object $component ): ?bool {
		$atomic_utils = '\\Elementor\\Modules\\AtomicWidgets\\Utils\\Utils';
		if ( ! class_exists( $atomic_utils ) || ! method_exists( $atomic_utils, 'is_atomic' ) ) {
			return null;
		}

		try {
			return (bool) $atomic_utils::is_atomic( $component );
		} catch ( Throwable ) {
			return null;
		}
	}

	private static function collect_atomic_config( object $component, array &$warnings ): array {
		if ( ! method_exists( $component, 'get_config' ) ) {
			$warnings[] = 'atomic_config_unavailable:' . sanitize_key( get_class( $component ) );
			return [];
		}

		try {
			$config = (array) $component->get_config();
		} catch ( Throwable ) {
			$warnings[] = 'atomic_config_failed:' . sanitize_key( get_class( $component ) );
			return [];
		}

		$result = [];
		foreach ( self::ATOMIC_CONFIG_KEYS as $key ) {
			if ( ! array_key_exists( $key, $config ) ) {
				continue;
			}

			$normalized = self::normalize_json_value( $config[ $key ], $warnings, 'atomic_config_' . $key );
			if ( null !== $normalized ) {
				$result[ $key ] = $normalized;
			}
		}

		return $result;
	}

	private static function collect_experiments( object $elementor, array &$warnings ): array {
		if ( ! isset( $elementor->experiments ) || ! is_object( $elementor->experiments ) || ! method_exists( $elementor->experiments, 'get_features' ) ) {
			return [];
		}

		try {
			$features = (array) $elementor->experiments->get_features();
		} catch ( Throwable ) {
			$warnings[] = 'experiments_inventory_failed';
			return [];
		}

		$result = [];
		foreach ( $features as $name => $feature ) {
			if ( ! is_array( $feature ) ) {
				continue;
			}

			$key = (string) $name;
			$record = [
				'name' => $key,
			];

			if ( method_exists( $elementor->experiments, 'is_feature_active' ) ) {
				try {
					$record['active'] = (bool) $elementor->experiments->is_feature_active( $key );
				} catch ( Throwable ) {
					$record['active'] = null;
				}
			}

			foreach ( [ 'default', 'release_status', 'hidden', 'new_site' ] as $field ) {
				if ( ! array_key_exists( $field, $feature ) ) {
					continue;
				}
				$normalized = self::normalize_json_value( $feature[ $field ], $warnings, 'experiment_' . $key . '_' . $field );
				if ( null !== $normalized ) {
					$record[ $field ] = $normalized;
				}
			}

			$result[ $key ] = $record;
		}
		ksort( $result );
		return $result;
	}

	private static function collect_atomic_style_schema( array &$warnings ): array {
		$style_schema = '\\Elementor\\Modules\\AtomicWidgets\\Styles\\Style_Schema';
		if ( ! class_exists( $style_schema ) || ! method_exists( $style_schema, 'get' ) ) {
			return [];
		}

		try {
			$schema = $style_schema::get();
		} catch ( Throwable ) {
			$warnings[] = 'atomic_style_schema_failed';
			return [];
		}

		$normalized = self::normalize_json_value( $schema, $warnings, 'atomic_style_schema' );
		return is_array( $normalized ) ? $normalized : [];
	}

	private static function collect_atomic_dynamic_tags( array &$warnings ): array {
		$module_class = '\\Elementor\\Modules\\AtomicWidgets\\DynamicTags\\Dynamic_Tags_Module';
		if ( ! class_exists( $module_class ) || ! method_exists( $module_class, 'instance' ) ) {
			return [];
		}

		try {
			$module = $module_class::instance();
			if ( ! isset( $module->registry ) || ! is_object( $module->registry ) || ! method_exists( $module->registry, 'get_tags' ) ) {
				return [];
			}
			$tags = $module->registry->get_tags();
		} catch ( Throwable ) {
			$warnings[] = 'atomic_dynamic_tags_failed';
			return [];
		}

		$normalized = self::normalize_json_value( $tags, $warnings, 'atomic_dynamic_tags' );
		return is_array( $normalized ) ? $normalized : [];
	}

	private static function collect_global_classes( object $elementor, array &$warnings ): array {
		$repository_class = '\\Elementor\\Modules\\GlobalClasses\\Global_Classes_Repository';
		$kit = self::active_kit( $elementor );
		if ( ! $kit || ! class_exists( $repository_class ) || ! method_exists( $repository_class, 'make' ) ) {
			return [];
		}

		$result = [];
		try {
			$repository = $repository_class::make( $kit );
			if ( ! method_exists( $repository, 'each_item' ) ) {
				return [];
			}
			$repository->each_item(
				static function ( array $item ) use ( &$result, &$warnings ): void {
					$id = isset( $item['id'] ) && is_scalar( $item['id'] ) ? (string) $item['id'] : '';
					if ( '' === $id ) {
						$warnings[] = 'global_class_without_id';
						return;
					}
					$normalized = self::normalize_json_value( $item, $warnings, 'global_class_' . $id );
					if ( is_array( $normalized ) ) {
						$result[ $id ] = $normalized;
					}
				},
				true
			);
		} catch ( Throwable ) {
			$warnings[] = 'global_classes_inventory_failed';
			return [];
		}

		ksort( $result );
		return $result;
	}

	private static function collect_variables( object $elementor, array &$warnings ): array {
		$repository_class = '\\Elementor\\Modules\\Variables\\Storage\\Variables_Repository';
		$kit = self::active_kit( $elementor );
		if ( ! $kit || ! class_exists( $repository_class ) ) {
			return [];
		}

		try {
			$repository = new $repository_class( $kit );
			$collection = $repository->load();
			if ( ! is_object( $collection ) || ! method_exists( $collection, 'serialize' ) ) {
				return [];
			}
			$record = (array) $collection->serialize( true );
			$data = is_array( $record['data'] ?? null ) ? $record['data'] : [];
		} catch ( Throwable ) {
			$warnings[] = 'variables_inventory_failed';
			return [];
		}

		$result = [];
		foreach ( $data as $id => $variable ) {
			$key = (string) $id;
			$normalized = self::normalize_json_value( $variable, $warnings, 'variable_' . $key );
			if ( is_array( $normalized ) ) {
				$normalized['id'] = $normalized['id'] ?? $key;
				$result[ $key ] = $normalized;
			}
		}
		ksort( $result );
		return $result;
	}

	private static function collect_components( array &$warnings ): array {
		$repository_class = '\\Elementor\\Modules\\Components\\Components_Repository';
		if ( ! class_exists( $repository_class ) || ! method_exists( $repository_class, 'make' ) ) {
			return [];
		}

		try {
			$collection = $repository_class::make()->all();
			$items = is_object( $collection ) && method_exists( $collection, 'all' ) ? $collection->all() : [];
		} catch ( Throwable ) {
			$warnings[] = 'components_inventory_failed';
			return [];
		}

		$result = [];
		foreach ( is_array( $items ) ? $items : [] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$key = isset( $item['uid'] ) && is_scalar( $item['uid'] ) && '' !== (string) $item['uid']
				? (string) $item['uid']
				: ( isset( $item['id'] ) && is_scalar( $item['id'] ) ? (string) $item['id'] : '' );
			if ( '' === $key ) {
				$warnings[] = 'component_without_identity';
				continue;
			}
			$normalized = self::normalize_json_value( $item, $warnings, 'component_' . $key );
			if ( is_array( $normalized ) ) {
				$result[ $key ] = $normalized;
			}
		}
		ksort( $result );
		return $result;
	}

	private static function collect_interactions( object $elementor, array &$warnings ): array {
		if ( ! isset( $elementor->modules_manager ) || ! is_object( $elementor->modules_manager ) || ! method_exists( $elementor->modules_manager, 'get_modules' ) ) {
			return [];
		}

		try {
			$module = $elementor->modules_manager->get_modules( 'interactions' );
			if ( ! is_object( $module ) || ! method_exists( $module, 'get_config' ) ) {
				return [];
			}
			$config = $module->get_config();
		} catch ( Throwable ) {
			$warnings[] = 'interactions_inventory_failed';
			return [];
		}

		$normalized = self::normalize_json_value( $config, $warnings, 'interactions' );
		return is_array( $normalized ) ? $normalized : [];
	}

	private static function active_kit( object $elementor ): ?object {
		if ( ! isset( $elementor->kits_manager ) || ! is_object( $elementor->kits_manager ) || ! method_exists( $elementor->kits_manager, 'get_active_kit' ) ) {
			return null;
		}

		try {
			$kit = $elementor->kits_manager->get_active_kit();
			return is_object( $kit ) ? $kit : null;
		} catch ( Throwable ) {
			return null;
		}
	}

	private static function normalize_json_value( mixed $value, array &$warnings, string $context ): mixed {
		try {
			$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} catch ( Throwable ) {
			$warnings[] = 'json_normalization_failed:' . sanitize_key( $context );
			return null;
		}

		if ( false === $encoded ) {
			$warnings[] = 'json_normalization_failed:' . sanitize_key( $context );
			return null;
		}

		$decoded = json_decode( $encoded, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$warnings[] = 'json_normalization_failed:' . sanitize_key( $context );
			return null;
		}

		return $decoded;
	}
}
