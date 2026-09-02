<?php

namespace Webactueel\ElementorJsonBridge\Media;

use RuntimeException;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

defined( 'ABSPATH' ) || exit;

final class Inventory {
	public const SCHEMA_VERSION = '1.0';
	private const BATCH_SIZE    = 200;

	public static function collect(): array {
		$items = [];
		$page  = 1;

		do {
			$query = new \WP_Query(
				[
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					'post_mime_type'         => 'image',
					'posts_per_page'         => self::BATCH_SIZE,
					'paged'                  => $page,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				]
			);
			$ids = is_array( $query->posts ) ? $query->posts : [];
			foreach ( $ids as $id ) {
				$item = self::item( (int) $id );
				if ( null !== $item ) {
					$items[] = $item;
				}
			}
			++$page;
		} while ( count( $ids ) === self::BATCH_SIZE );

		$inventory_hash = CanonicalJson::hash( $items );
		return [
			'schema_version' => self::SCHEMA_VERSION,
			'inventory_hash' => $inventory_hash,
			'item_count'     => count( $items ),
			'items'          => $items,
		];
	}

	public static function item( int $attachment_id ): ?array {
		$post = get_post( $attachment_id );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type || ! wp_attachment_is_image( $attachment_id ) ) {
			return null;
		}

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		$metadata = is_array( $metadata ) ? $metadata : [];
		$file     = get_attached_file( $attachment_id );
		$file     = is_string( $file ) ? $file : '';
		$width    = isset( $metadata['width'] ) ? max( 0, (int) $metadata['width'] ) : 0;
		$height   = isset( $metadata['height'] ) ? max( 0, (int) $metadata['height'] ) : 0;
		$filesize = isset( $metadata['filesize'] ) ? max( 0, (int) $metadata['filesize'] ) : 0;
		if ( 0 === $filesize && '' !== $file && function_exists( 'wp_filesize' ) ) {
			$measured = wp_filesize( $file );
			$filesize = is_int( $measured ) ? max( 0, $measured ) : 0;
		}

		$item = [
			'id'           => $attachment_id,
			'url'          => esc_url_raw( $url ),
			'filename'     => '' !== $file ? wp_basename( $file ) : wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ),
			'title'        => (string) $post->post_title,
			'alt'          => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'caption'      => (string) $post->post_excerpt,
			'description'  => (string) $post->post_content,
			'mime_type'    => (string) $post->post_mime_type,
			'filesize'     => $filesize,
			'width'        => $width,
			'height'       => $height,
			'aspect_ratio' => 0 < $width && 0 < $height ? round( $width / $height, 6 ) : null,
			'sizes'        => self::sizes( $attachment_id, $metadata ),
			'modified_gmt' => self::modified_gmt( (string) $post->post_modified_gmt ),
		];
		$item['fact_fingerprint'] = CanonicalJson::hash( $item );
		return $item;
	}

	public static function assert_attachment_id( int $attachment_id ): array {
		$item = self::item( $attachment_id );
		if ( null === $item ) {
			throw new RuntimeException( 'The requested media attachment is not an existing WordPress image.' );
		}
		return $item;
	}

	public static function assert_id_url( int $attachment_id, string $url ): array {
		$item      = self::assert_attachment_id( $attachment_id );
		$candidate = esc_url_raw( $url );
		$allowed   = [ (string) $item['url'] ];
		foreach ( $item['sizes'] as $size ) {
			if ( is_array( $size ) && isset( $size['url'] ) && is_string( $size['url'] ) ) {
				$allowed[] = $size['url'];
			}
		}
		if ( '' === $candidate || ! in_array( $candidate, array_values( array_unique( $allowed ) ), true ) ) {
			throw new RuntimeException( 'The media URL does not match the live WordPress attachment or one of its generated image sizes.' );
		}
		return $item;
	}

	private static function sizes( int $attachment_id, array $metadata ): array {
		$sizes = [];
		$known = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : [];
		ksort( $known, SORT_STRING );
		foreach ( $known as $name => $value ) {
			if ( ! is_string( $name ) || ! is_array( $value ) ) {
				continue;
			}
			$source = wp_get_attachment_image_src( $attachment_id, $name );
			if ( ! is_array( $source ) || empty( $source[0] ) ) {
				continue;
			}
			$sizes[ $name ] = [
				'url'       => esc_url_raw( (string) $source[0] ),
				'width'     => max( 0, (int) ( $source[1] ?? 0 ) ),
				'height'    => max( 0, (int) ( $source[2] ?? 0 ) ),
				'mime_type' => isset( $value['mime-type'] ) ? (string) $value['mime-type'] : '',
			];
		}
		return $sizes;
	}

	private static function modified_gmt( string $value ): string {
		if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
			return '';
		}
		return str_replace( ' ', 'T', $value ) . 'Z';
	}
}
