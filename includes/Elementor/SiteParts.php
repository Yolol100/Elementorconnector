<?php

namespace Webactueel\ElementorJsonBridge\Elementor;

use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class SiteParts {
	public function __construct( private readonly Documents $documents ) {}

	public function for_post( int $post_id ): array {
		$source_post = get_post( $post_id );
		if ( ! $source_post ) {
			throw new RuntimeException( 'The WordPress document does not exist.' );
		}

		if ( ! class_exists( '\\ElementorPro\\Modules\\ThemeBuilder\\Module' ) ) {
			return [
				'supported' => false,
				'header'    => null,
				'footer'    => null,
				'warnings'  => [ 'Elementor Pro Theme Builder is not active, so no Elementor header or footer was included.' ],
			];
		}

		try {
			$module = \ElementorPro\Modules\ThemeBuilder\Module::instance();
			if ( ! is_object( $module ) || ! method_exists( $module, 'get_conditions_manager' ) ) {
				return $this->unsupported_result();
			}

			$conditions = $module->get_conditions_manager();
			if ( ! is_object( $conditions ) || ! method_exists( $conditions, 'get_documents_for_location' ) ) {
				return $this->unsupported_result();
			}

			$matches = $this->with_singular_query(
				$source_post,
				static fn (): array => [
					'header' => $conditions->get_documents_for_location( 'header' ),
					'footer' => $conditions->get_documents_for_location( 'footer' ),
				]
			);

			$header = $this->first_site_part( $matches['header'] ?? [], 'header' );
			$footer = $this->first_site_part( $matches['footer'] ?? [], 'footer' );
		} catch ( Throwable ) {
			return $this->unsupported_result(
				'Elementor Pro Theme Builder could not resolve the matching header or footer for this document, so only the source document was exported.'
			);
		}

		$warnings = [];

		if ( null === $header ) {
			$warnings[] = 'No matching Elementor Theme Builder header was found for this document.';
		}
		if ( null === $footer ) {
			$warnings[] = 'No matching Elementor Theme Builder footer was found for this document.';
		}

		return [
			'supported' => true,
			'header'    => $header,
			'footer'    => $footer,
			'warnings'  => $warnings,
		];
	}

	private function unsupported_result( string $warning = 'The active Elementor Pro version does not expose the Theme Builder condition API required for site-part export.' ): array {
		return [
			'supported' => false,
			'header'    => null,
			'footer'    => null,
			'warnings'  => [ $warning ],
		];
	}

	private function first_site_part( mixed $matches, string $location ): ?array {
		if ( ! is_array( $matches ) || [] === $matches ) {
			return null;
		}

		foreach ( $matches as $key => $document ) {
			$template_id = 0;
			$template    = null;

			if ( is_object( $document ) && method_exists( $document, 'get_post' ) ) {
				$template = $document->get_post();
				if ( $template instanceof \WP_Post ) {
					$template_id = (int) $template->ID;
				}
			} elseif ( is_numeric( $key ) ) {
				$template_id = (int) $key;
			} elseif ( is_numeric( $document ) ) {
				$template_id = (int) $document;
			}

			if ( $template_id < 1 ) {
				continue;
			}

			$template ??= get_post( $template_id );
			if ( ! $template instanceof \WP_Post ) {
				continue;
			}

			$payload = $this->documents->payload( $template_id );
			if ( (string) ( $payload['type'] ?? '' ) !== $location ) {
				continue;
			}

			return [
				'id'      => $template_id,
				'title'   => (string) $template->post_title,
				'payload' => $payload,
			];
		}

		return null;
	}

	private function with_singular_query( \WP_Post $source_post, callable $callback ): mixed {
		global $post, $wp_query, $wp_the_query;

		$previous_post      = $post ?? null;
		$previous_query     = $wp_query ?? null;
		$previous_the_query = $wp_the_query ?? null;
		$query_args         = [
			'post_type'           => (string) $source_post->post_type,
			'post_status'         => 'any',
			'posts_per_page'      => 1,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		];

		if ( 'page' === (string) $source_post->post_type ) {
			$query_args['page_id'] = (int) $source_post->ID;
		} else {
			$query_args['p'] = (int) $source_post->ID;
		}

		$query = new \WP_Query( $query_args );

		if ( ! $query->have_posts() ) {
			throw new RuntimeException( 'The document context could not be prepared for Theme Builder conditions.' );
		}

		$wp_query     = $query;
		$wp_the_query = $query;
		$query->the_post();

		try {
			return $callback();
		} finally {
			$query->reset_postdata();
			$wp_query     = $previous_query;
			$wp_the_query = $previous_the_query;
			$post         = $previous_post;

			if ( $previous_post instanceof \WP_Post ) {
				setup_postdata( $previous_post );
			}
		}
	}
}
