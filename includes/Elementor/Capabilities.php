<?php

namespace Webactueel\ElementorJsonBridge\Elementor;

use Throwable;

defined( 'ABSPATH' ) || exit;

final class Capabilities {
	public static function collect(): array {
		$environment = [
			'wordpress'      => get_bloginfo( 'version' ),
			'php'            => PHP_VERSION,
			'elementor'      => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'elementor_pro'  => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
			'theme'          => wp_get_theme()->get( 'Name' ),
			'theme_version'  => wp_get_theme()->get( 'Version' ),
			'active_plugins' => self::active_plugins(),
		];

		if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\\Elementor\\Plugin' ) ) {
			return [
				'format'              => 'elementor-json-bridge/elementor-capabilities',
				'version'             => 1,
				'elementor_available' => false,
				'environment'         => $environment,
				'widgets'             => [],
				'elements'            => [],
				'document_types'      => [],
				'dynamic_tags'        => [],
				'warnings'            => [],
			];
		}

		$elementor = \Elementor\Plugin::instance();
		$environment['active_devices'] = self::active_devices( $elementor );
		$environment['active_breakpoints'] = self::active_breakpoints( $elementor );
		$warnings = [];

		return [
			'format'              => 'elementor-json-bridge/elementor-capabilities',
			'version'             => 1,
			'elementor_available' => true,
			'environment'         => $environment,
			'widgets'             => self::collect_widgets( $elementor, $warnings ),
			'elements'            => self::collect_elements( $elementor, $warnings ),
			'document_types'      => self::collect_document_types( $elementor, $warnings ),
			'dynamic_tags'        => self::collect_dynamic_tags( $elementor, $warnings ),
			'warnings'            => array_values( array_unique( $warnings ) ),
		];
	}

	private static function collect_widgets( object $elementor, array &$warnings ): array {
		if ( ! isset( $elementor->widgets_manager ) || ! method_exists( $elementor->widgets_manager, 'get_widget_types' ) ) {
			$warnings[] = 'widgets_manager_unavailable';
			return [];
		}

		$result = [];
		try {
			$widgets = $elementor->widgets_manager->get_widget_types();
		} catch ( Throwable ) {
			$warnings[] = 'widgets_inventory_failed';
			return [];
		}

		foreach ( (array) $widgets as $name => $widget ) {
			if ( ! is_object( $widget ) ) {
				continue;
			}
			try {
				$result[ (string) $name ] = self::component_record( $widget, (string) $name, $warnings );
			} catch ( Throwable ) {
				$warnings[] = 'widget_skipped:' . sanitize_key( (string) $name );
			}
		}
		ksort( $result );
		return $result;
	}

	private static function collect_elements( object $elementor, array &$warnings ): array {
		if ( ! isset( $elementor->elements_manager ) || ! method_exists( $elementor->elements_manager, 'get_element_types' ) ) {
			$warnings[] = 'elements_manager_unavailable';
			return [];
		}

		$result = [];
		try {
			$elements = $elementor->elements_manager->get_element_types();
		} catch ( Throwable ) {
			$warnings[] = 'elements_inventory_failed';
			return [];
		}

		$atomic_utils = '\\Elementor\\Modules\\AtomicWidgets\\Utils\\Utils';
		foreach ( (array) $elements as $name => $element ) {
			if ( ! is_object( $element ) ) {
				continue;
			}
			try {
				$record = self::component_record( $element, (string) $name, $warnings );
				$record['atomic'] = class_exists( $atomic_utils ) && method_exists( $atomic_utils, 'is_atomic' )
					? (bool) $atomic_utils::is_atomic( $element )
					: null;
				$result[ (string) $name ] = $record;
			} catch ( Throwable ) {
				$warnings[] = 'element_skipped:' . sanitize_key( (string) $name );
			}
		}
		ksort( $result );
		return $result;
	}

	private static function collect_document_types( object $elementor, array &$warnings ): array {
		if ( ! isset( $elementor->documents ) || ! method_exists( $elementor->documents, 'get_document_types' ) ) {
			$warnings[] = 'documents_manager_unavailable';
			return [];
		}

		try {
			$types = $elementor->documents->get_document_types();
		} catch ( Throwable ) {
			$warnings[] = 'document_types_inventory_failed';
			return [];
		}

		$result = [];
		foreach ( (array) $types as $type => $class_name ) {
			if ( ! is_string( $class_name ) || ! class_exists( $class_name ) ) {
				continue;
			}
			try {
				$properties = method_exists( $class_name, 'get_properties' ) ? (array) $class_name::get_properties() : [];
				$result[ (string) $type ] = [
					'title'           => method_exists( $class_name, 'get_title' ) ? wp_strip_all_tags( (string) $class_name::get_title() ) : (string) $type,
					'plural_title'    => method_exists( $class_name, 'get_plural_title' ) ? wp_strip_all_tags( (string) $class_name::get_plural_title() ) : null,
					'cpt'             => isset( $properties['cpt'] ) ? array_values( array_map( 'strval', (array) $properties['cpt'] ) ) : [],
					'show_in_library' => isset( $properties['show_in_library'] ) ? (bool) $properties['show_in_library'] : null,
					'register_type'   => isset( $properties['register_type'] ) ? (bool) $properties['register_type'] : null,
					'is_editable'     => isset( $properties['is_editable'] ) ? (bool) $properties['is_editable'] : null,
				];
			} catch ( Throwable ) {
				$warnings[] = 'document_type_skipped:' . sanitize_key( (string) $type );
			}
		}
		ksort( $result );
		return $result;
	}

	private static function collect_dynamic_tags( object $elementor, array &$warnings ): array {
		if ( ! isset( $elementor->dynamic_tags ) || ! method_exists( $elementor->dynamic_tags, 'get_tags' ) ) {
			$warnings[] = 'dynamic_tags_manager_unavailable';
			return [];
		}

		try {
			$tags = $elementor->dynamic_tags->get_tags();
		} catch ( Throwable ) {
			$warnings[] = 'dynamic_tags_inventory_failed';
			return [];
		}

		$result = [];
		foreach ( (array) $tags as $name => $tag_info ) {
			$tag = is_array( $tag_info ) && isset( $tag_info['instance'] ) && is_object( $tag_info['instance'] )
				? $tag_info['instance']
				: null;
			if ( ! $tag ) {
				continue;
			}
			try {
				$owner = self::detect_owner( $tag );
				$result[ (string) $name ] = [
					'name'        => method_exists( $tag, 'get_name' ) ? (string) $tag->get_name() : (string) $name,
					'title'       => method_exists( $tag, 'get_title' ) ? wp_strip_all_tags( (string) $tag->get_title() ) : (string) $name,
					'owner'       => $owner['owner'],
					'plugin_slug' => $owner['plugin_slug'],
					'group'       => method_exists( $tag, 'get_group' ) && is_scalar( $tag->get_group() ) ? (string) $tag->get_group() : null,
					'categories'  => method_exists( $tag, 'get_categories' ) ? array_values( array_map( 'strval', (array) $tag->get_categories() ) ) : [],
					'controls'    => self::collect_controls( $tag, $warnings ),
				];
			} catch ( Throwable ) {
				$warnings[] = 'dynamic_tag_skipped:' . sanitize_key( (string) $name );
			}
		}
		ksort( $result );
		return $result;
	}

	private static function component_record( object $component, string $fallback_name, array &$warnings ): array {
		$owner = self::detect_owner( $component );
		return [
			'name'        => method_exists( $component, 'get_name' ) ? (string) $component->get_name() : $fallback_name,
			'title'       => method_exists( $component, 'get_title' ) ? wp_strip_all_tags( (string) $component->get_title() ) : $fallback_name,
			'owner'       => $owner['owner'],
			'plugin_slug' => $owner['plugin_slug'],
			'categories'  => method_exists( $component, 'get_categories' ) ? array_values( array_map( 'strval', (array) $component->get_categories() ) ) : [],
			'controls'    => self::collect_controls( $component, $warnings ),
		];
	}

	private static function collect_controls( object $component, array &$warnings ): array {
		if ( ! method_exists( $component, 'get_controls' ) ) {
			return [];
		}

		try {
			$raw_controls = $component->get_controls();
		} catch ( Throwable ) {
			$warnings[] = 'controls_inventory_failed:' . sanitize_key( get_class( $component ) );
			return [];
		}

		$controls = [];
		foreach ( (array) $raw_controls as $control_name => $control ) {
			if ( ! is_array( $control ) ) {
				continue;
			}
			$responsive_devices = [];
			if ( isset( $control['responsive']['devices'] ) && is_array( $control['responsive']['devices'] ) ) {
				$responsive_devices = array_values( array_filter( array_map( 'strval', $control['responsive']['devices'] ) ) );
			}
			$record = [
				'name'               => (string) $control_name,
				'type'               => isset( $control['type'] ) && is_scalar( $control['type'] ) ? (string) $control['type'] : null,
				'responsive'         => ! empty( $control['responsive'] ) || ! empty( $control['is_responsive'] ),
				'responsive_devices' => $responsive_devices,
				'dynamic_active'     => ! empty( $control['dynamic']['active'] ),
			];
			if ( 'submit_actions' === (string) $control_name && isset( $control['options'] ) && is_array( $control['options'] ) ) {
				$record['choice_keys'] = array_slice( array_values( array_map( 'strval', array_keys( $control['options'] ) ) ), 0, 100 );
			}
			$controls[] = $record;
		}
		usort(
			$controls,
			static function ( array $left, array $right ): int {
				return strcmp( $left['name'], $right['name'] );
			}
		);
		return $controls;
	}

	private static function active_devices( object $elementor ): array {
		if ( ! isset( $elementor->breakpoints ) || ! is_object( $elementor->breakpoints ) || ! method_exists( $elementor->breakpoints, 'get_active_devices_list' ) ) {
			return [];
		}
		try {
			$devices = $elementor->breakpoints->get_active_devices_list( [ 'add_desktop' => true, 'desktop_first' => true ] );
		} catch ( Throwable ) {
			return [];
		}
		return array_values( array_filter( array_map( 'strval', is_array( $devices ) ? $devices : [] ) ) );
	}

	private static function active_breakpoints( object $elementor ): array {
		if ( ! isset( $elementor->breakpoints ) || ! is_object( $elementor->breakpoints ) || ! method_exists( $elementor->breakpoints, 'get_active_breakpoints' ) ) {
			return [];
		}
		try {
			$breakpoints = $elementor->breakpoints->get_active_breakpoints();
		} catch ( Throwable ) {
			return [];
		}
		$result = [];
		foreach ( is_array( $breakpoints ) ? $breakpoints : [] as $name => $breakpoint ) {
			if ( ! is_object( $breakpoint ) ) {
				continue;
			}
			$result[ (string) $name ] = [
				'label'     => method_exists( $breakpoint, 'get_label' ) ? (string) $breakpoint->get_label() : (string) $name,
				'value'     => method_exists( $breakpoint, 'get_value' ) ? (int) $breakpoint->get_value() : null,
				'direction' => method_exists( $breakpoint, 'get_direction' ) ? (string) $breakpoint->get_direction() : null,
			];
		}
		ksort( $result );
		return $result;
	}

	private static function detect_owner( object $component ): array {
		try {
			$file = ( new \ReflectionClass( $component ) )->getFileName();
		} catch ( \ReflectionException ) {
			$file = false;
		}
		if ( ! $file ) {
			return [ 'owner' => 'unknown', 'plugin_slug' => null ];
		}
		$plugin_dir = wp_normalize_path( WP_PLUGIN_DIR );
		$file_path = wp_normalize_path( $file );
		if ( 0 !== strpos( $file_path, $plugin_dir . '/' ) ) {
			return [ 'owner' => 'unknown', 'plugin_slug' => null ];
		}
		$relative = ltrim( substr( $file_path, strlen( $plugin_dir ) ), '/' );
		$parts = explode( '/', $relative );
		$plugin_slug = sanitize_key( $parts[0] ?? '' );
		$owner = match ( $plugin_slug ) {
			'elementor'     => 'elementor-core',
			'elementor-pro' => 'elementor-pro',
			''              => 'unknown',
			default         => 'third-party',
		};
		return [ 'owner' => $owner, 'plugin_slug' => '' !== $plugin_slug ? $plugin_slug : null ];
	}

	private static function active_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins = get_plugins();
		$active = (array) get_option( 'active_plugins', [] );
		$result = [];
		foreach ( $active as $basename ) {
			$data = $all_plugins[ $basename ] ?? [];
			$result[] = [
				'basename' => (string) $basename,
				'name'     => isset( $data['Name'] ) ? wp_strip_all_tags( (string) $data['Name'] ) : (string) $basename,
				'version'  => isset( $data['Version'] ) ? (string) $data['Version'] : null,
			];
		}
		usort(
			$result,
			static function ( array $left, array $right ): int {
				return strcmp( $left['basename'], $right['basename'] );
			}
		);
		return $result;
	}
}
