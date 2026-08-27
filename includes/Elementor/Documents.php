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

		clean_post_cache( $post_id );
	}

	public function document_type( int $post_id ): string {
		return (string) $this->document( $post_id )->get_name();
	}

	public function is_elementor_document( int $post_id ): bool {
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
