<?php

namespace Webactueel\ElementorJsonBridge\Elementor;

use RuntimeException;
defined( 'ABSPATH' ) || exit;

final class Documents {
	public function payload( int $post_id ): array {
		$document = $this->document( $post_id );
		$post     = get_post( $post_id );
		if ( ! $post ) {
			throw new RuntimeException( 'The WordPress document does not exist.' );
		}

		$payload = [
			'title'         => (string) $post->post_title,
			'type'          => (string) $document->get_name(),
			'version'       => PayloadValidator::FORMAT_VERSION,
			'page_settings' => $document->get_db_document_settings(),
			'content'       => $document->get_elements_data(),
		];
		$payload['page_settings'] = is_array( $payload['page_settings'] ) ? $payload['page_settings'] : [];
		$payload['content']       = is_array( $payload['content'] ) ? $payload['content'] : [];

		return $payload;
	}

	public function create_payload( string $post_type, array $payload ): int {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			throw new RuntimeException( 'Elementor is not active.' );
		}
		$manager = \Elementor\Plugin::$instance->documents ?? null;
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'create' ) || ! method_exists( $manager, 'get_document_type' ) ) {
			throw new RuntimeException( 'The Elementor document manager is unavailable.' );
		}
		$type = (string) ( $payload['type'] ?? '' );
		$class = $manager->get_document_type( $type, false );
		if ( ! is_string( $class ) || ! class_exists( $class ) || ! method_exists( $class, 'get_property' ) ) {
			throw new RuntimeException( 'The requested Elementor document type is not registered.' );
		}
		$post_types = $class::get_property( 'cpt' );
		if ( ! is_array( $post_types ) || ! in_array( $post_type, $post_types, true ) ) {
			throw new RuntimeException( 'The requested Elementor document type does not support this WordPress post type.' );
		}
		$document = $manager->create(
			$type,
			[
				'post_type'   => $post_type,
				'post_status' => 'draft',
				'post_title'  => (string) ( $payload['title'] ?? '' ),
			]
		);
		if ( is_wp_error( $document ) ) {
			throw new RuntimeException( 'Elementor rejected the requested document type.' );
		}
		if ( ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) ) {
			throw new RuntimeException( 'Elementor did not return a document.' );
		}
		$post_id = (int) $document->get_main_id();
		if ( $post_id < 1 || get_post_type( $post_id ) !== $post_type ) {
			if ( $post_id > 0 ) {
				wp_delete_post( $post_id, true );
			}
			throw new RuntimeException( 'Elementor created an unexpected WordPress content type.' );
		}
		try {
			$this->save_payload( $post_id, $payload );
		} catch ( \Throwable $throwable ) {
			wp_delete_post( $post_id, true );
			throw $throwable;
		}
		return $post_id;
	}

	public function save_payload( int $post_id, array $payload ): void {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new RuntimeException( 'You are not allowed to edit this document.' );
		}

		$document = $this->document( $post_id );
		$result   = $document->save(
			[
				'elements' => $payload['content'],
				'settings' => $payload['page_settings'],
			]
		);
		if ( true !== $result ) {
			throw new RuntimeException( 'Elementor rejected the document save.' );
		}

		$post = get_post( $post_id );
		if ( $post && (string) $post->post_title !== (string) $payload['title'] ) {
			$updated = wp_update_post(
				[
					'ID'         => $post_id,
					'post_title' => (string) $payload['title'],
				],
				true
			);
			if ( is_wp_error( $updated ) ) {
				throw new RuntimeException( 'WordPress could not update the document title.' );
			}
		}

		clean_post_cache( $post_id );
	}

	public function document_type( int $post_id ): string {
		return (string) $this->document( $post_id )->get_name();
	}

	public function is_elementor_document( int $post_id ): bool {
		if ( 'builder' !== (string) get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			return false;
		}
		try {
			$this->document( $post_id );
			return true;
		} catch ( RuntimeException ) {
			return false;
		}
	}

	private function document( int $post_id ): object {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			throw new RuntimeException( 'Elementor is not active.' );
		}
		$document = \Elementor\Plugin::$instance->documents->get( $post_id );
		if ( ! is_object( $document ) || ! method_exists( $document, 'get_elements_data' ) || ! method_exists( $document, 'save' ) ) {
			throw new RuntimeException( 'This item is not an editable Elementor document.' );
		}
		return $document;
	}
}
