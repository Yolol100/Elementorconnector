<?php

namespace Webactueel\ElementorJsonBridge\Elementor;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class LocalExport {
	private const BUNDLE_FORMAT  = 'elementor-json-bridge/site-parts-bundle';
	private const BUNDLE_VERSION = 1;
	private const POST_TYPES     = [ 'page', 'post' ];

	public function __construct(
		private readonly Documents $documents,
		private readonly SiteParts $site_parts
	) {}

	public function export( int $post_id, bool $include_site_parts = false ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new RuntimeException( 'The WordPress document does not exist.' );
		}
		if ( ! self::supports_post_type( (string) $post->post_type ) ) {
			throw new RuntimeException( 'Local Elementor JSON export is available only for pages and posts.' );
		}
		if ( 'builder' !== (string) get_post_meta( $post_id, '_elementor_edit_mode', true ) || ! $this->documents->is_elementor_document( $post_id ) ) {
			throw new RuntimeException( 'This page or post is not an editable Elementor document.' );
		}

		$document = $this->documents->payload( $post_id );
		$basename = '' !== (string) $post->post_name ? (string) $post->post_name : (string) $post->post_type . '-' . $post_id;

		if ( ! $include_site_parts ) {
			return [
				'filename'            => sanitize_file_name( $basename . '-elementor.json' ),
				'format'              => 'elementor-document',
				'export'              => $document,
				'included_site_parts' => [
					'header' => false,
					'footer' => false,
				],
				'warnings'            => [],
			];
		}

		$parts  = $this->site_parts->for_post( $post_id );
		$header = is_array( $parts['header'] ?? null ) ? $parts['header'] : null;
		$footer = is_array( $parts['footer'] ?? null ) ? $parts['footer'] : null;
		$bundle = [
			'format'         => self::BUNDLE_FORMAT,
			'bundle_version' => self::BUNDLE_VERSION,
			'source'         => [
				'post_id'   => $post_id,
				'post_type' => (string) $post->post_type,
				'title'     => (string) $post->post_title,
			],
			'document'       => $document,
			'header'         => $header['payload'] ?? null,
			'footer'         => $footer['payload'] ?? null,
			'site_part_meta' => [
				'header' => $this->site_part_meta( $header ),
				'footer' => $this->site_part_meta( $footer ),
			],
		];

		return [
			'filename'            => sanitize_file_name( $basename . '-elementor-with-site-parts.json' ),
			'format'              => self::BUNDLE_FORMAT,
			'export'              => $bundle,
			'included_site_parts' => [
				'header' => null !== $header,
				'footer' => null !== $footer,
			],
			'warnings'            => array_values( array_filter( (array) ( $parts['warnings'] ?? [] ), 'is_string' ) ),
		];
	}

	public static function supports_post_type( string $post_type ): bool {
		return in_array( $post_type, self::POST_TYPES, true );
	}

	private function site_part_meta( ?array $part ): ?array {
		if ( null === $part ) {
			return null;
		}

		return [
			'id'    => (int) ( $part['id'] ?? 0 ),
			'title' => (string) ( $part['title'] ?? '' ),
		];
	}
}
