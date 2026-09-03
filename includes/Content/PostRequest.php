<?php

namespace Webactueel\ElementorJsonBridge\Content;

use RuntimeException;
use Webactueel\ElementorJsonBridge\Backup\Snapshots;
use Webactueel\ElementorJsonBridge\Elementor\Documents;
use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

defined( 'ABSPATH' ) || exit;

final class PostRequest {
	public const FORMAT  = 'elementor-json-bridge/manage-post';
	public const VERSION = 1;

	public function __construct(
		private readonly WordPressDocument $content,
		private readonly Documents $elementor,
		private readonly PayloadValidator $elementor_validator,
		private readonly Snapshots $snapshots
	) {}

	public function execute( array $request ): array {
		$this->validate_request( $request );
		$action = (string) $request['action'];
		if ( 'create' === $action ) {
			$request_post = (array) $request['post'];
			if ( 'product' === (string) $request['post_type'] ) {
				throw new RuntimeException( 'WooCommerce products must use the manage-product request.' );
			}
			$id = array_key_exists( 'elementor', $request )
				? $this->create_elementor_draft( $request, $request_post )
				: $this->create_wordpress_draft( $request, $request_post );
			return [ 'status' => 'created', 'post_id' => $id, 'state_token' => $this->content->state_token( $id ) ];
		}

		$id = (int) ( $request['post_id'] ?? 0 );
		if ( ! $this->content->supports( $id ) || 'product' === get_post_type( $id ) ) {
			throw new RuntimeException( 'The requested WordPress content item is not managed by this request type.' );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			throw new RuntimeException( 'You are not allowed to edit this WordPress content item.' );
		}
		if ( 'read' === $action ) {
			return [
				'status'      => 'read',
				'post_id'     => $id,
				'data'        => $this->content->payload( $id ),
				'state_token' => $this->content->state_token( $id ),
			];
		}

		$this->content->assert_state_token( $id, $request['expected_state_token'] ?? null );
		if ( 'delete' === $action ) {
			if ( true !== ( $request['confirm_destructive'] ?? false ) ) {
				throw new RuntimeException( 'Deleting WordPress content requires confirm_destructive=true.' );
			}
			if ( ! current_user_can( 'delete_post', $id ) ) {
				throw new RuntimeException( 'You are not allowed to delete this WordPress content item.' );
			}
			if ( ! wp_trash_post( $id ) || 'trash' !== get_post_status( $id ) ) {
				throw new RuntimeException( 'WordPress content trash failed readback verification.' );
			}
			return [ 'status' => 'deleted', 'post_id' => $id ];
		}

		$post            = is_array( $request['post'] ?? null ) ? $request['post'] : [];
		$before_content  = $this->content->payload( $id );
		$before_extended = PostState::read( $id );
		$desired         = $this->desired_content( $id, $before_content, $post, $request, false );
		$extended        = $this->extended_request_state( $post );
		$this->validate_post_fields( $id, $post );
		$snapshot_id = $this->snapshots->create( $id, $before_content, 'before_request_update', '', $before_extended );

		try {
			$this->content->apply( $id, $desired );
			PostState::apply( $id, $extended );
			$this->verify_state( $id, $desired, $extended );
		} catch ( \Throwable $apply_error ) {
			try {
				$restore_content  = $this->snapshots->payload( $snapshot_id, $id );
				$restore_extended = $this->snapshots->extra_state( $snapshot_id, $id );
				$this->content->apply( $id, $restore_content );
				PostState::apply( $id, $restore_extended );
				$this->verify_state( $id, $restore_content, $restore_extended );
			} catch ( \Throwable $rollback_error ) {
				throw new RuntimeException( 'WordPress content update failed and rollback could not be verified: ' . $rollback_error->getMessage(), 0, $apply_error );
			}
			throw new RuntimeException( 'WordPress content update failed. The previous content state was restored.', 0, $apply_error );
		}
		return [
			'status'      => 'updated',
			'post_id'     => $id,
			'snapshot_id' => $snapshot_id,
			'state_token' => $this->content->state_token( $id ),
		];
	}

	private function create_wordpress_draft( array $request, array $request_post ): int {
		$create_post = array_intersect_key(
			$request_post,
			array_flip( [ 'title', 'slug', 'content', 'excerpt' ] )
		);
		$create = [
			'format'     => WordPressDocument::CREATE_FORMAT,
			'version'    => WordPressDocument::VERSION,
			'request_id' => (string) $request['request_id'],
			'post_type'  => (string) $request['post_type'],
			'post'       => $create_post,
		];
		foreach ( [ 'taxonomies', 'acf', 'yoast', 'registered_meta' ] as $key ) {
			if ( array_key_exists( $key, $request ) ) {
				$create[ $key ] = $request[ $key ];
			}
		}
		$id = $this->content->create_draft( $create );
		try {
			$current = $this->content->payload( $id );
			$desired = $this->desired_content( $id, $current, $request_post, $request, true );
			$extended = $this->extended_request_state( $request_post );
			$this->validate_post_fields( $id, $request_post );
			$this->content->apply( $id, $desired );
			PostState::apply( $id, $extended );
			$this->verify_state( $id, $desired, $extended );
		} catch ( \Throwable $throwable ) {
			wp_delete_post( $id, true );
			throw $throwable;
		}
		return $id;
	}

	private function create_elementor_draft( array $request, array $request_post ): int {
		$object = get_post_type_object( (string) $request['post_type'] );
		if ( ! $object ) {
			throw new RuntimeException( 'The requested WordPress post type does not exist.' );
		}
		$create_cap = $object->cap->create_posts ?? $object->cap->edit_posts ?? 'edit_posts';
		if ( ! current_user_can( $create_cap ) ) {
			throw new RuntimeException( 'You are not allowed to create this WordPress content type.' );
		}
		$title = isset( $request_post['title'] ) && is_string( $request_post['title'] ) ? trim( $request_post['title'] ) : '';
		if ( '' === $title ) {
			throw new RuntimeException( 'A new Elementor document requires post.title.' );
		}
		if ( ! is_array( $request['elementor'] ) || array_is_list( $request['elementor'] ) ) {
			throw new RuntimeException( 'Elementor create data must be a document object.' );
		}
		$elementor          = $request['elementor'];
		$elementor['title'] = $title;
		$elementor          = $this->elementor_validator->validate_array( $elementor );
		$id                 = $this->elementor->create_payload( (string) $request['post_type'], $elementor );
		try {
			$current = $this->content->payload( $id );
			$request['elementor'] = $elementor;
			$desired = $this->desired_content( $id, $current, $request_post, $request, true );
			$extended = $this->extended_request_state( $request_post );
			$this->validate_post_fields( $id, $request_post );
			$this->content->apply( $id, $desired );
			PostState::apply( $id, $extended );
			$this->verify_state( $id, $desired, $extended );
		} catch ( \Throwable $throwable ) {
			wp_delete_post( $id, true );
			throw $throwable;
		}
		return $id;
	}

	private function desired_content( int $id, array $current, array $post, array $request, bool $creating ): array {
		$desired = $current;
		foreach ( [ 'taxonomies', 'acf', 'yoast', 'registered_meta' ] as $section ) {
			if ( array_key_exists( $section, $request ) ) {
				$desired[ $section ] = array_replace( $desired[ $section ], $request[ $section ] );
			}
		}
		if ( array_key_exists( 'elementor', $request ) ) {
			$desired['elementor'] = $request['elementor'];
		}
		foreach ( [ 'title', 'slug', 'status', 'content', 'excerpt', 'parent', 'menu_order', 'comment_status', 'ping_status', 'page_template', 'featured_image' ] as $field ) {
			if ( array_key_exists( $field, $post ) ) {
				if ( 'slug' === $field ) {
					if ( ! is_string( $post[ $field ] ) ) {
						throw new RuntimeException( 'The requested WordPress slug is invalid.' );
					}
					$desired['post'][ $field ] = sanitize_title( $post[ $field ] );
				} else {
					$desired['post'][ $field ] = $post[ $field ];
				}
			}
		}
		if ( $creating ) {
			$desired['post']['status'] = 'draft';
		}
		return $this->content->validate_array( $desired, $id );
	}

	private function verify_state( int $id, array $expected_content, array $expected_extended ): void {
		$readback = $this->content->payload( $id );
		if ( ! hash_equals( CanonicalJson::hash( $expected_content ), CanonicalJson::hash( $readback ) ) ) {
			throw new RuntimeException( 'WordPress content failed exact readback verification.' );
		}
		$current = PostState::read( $id );
		foreach ( $expected_extended as $field => $value ) {
			if ( ! array_key_exists( $field, $current ) || $current[ $field ] !== $value ) {
				throw new RuntimeException( 'A WordPress extended content field failed readback verification.' );
			}
		}
	}

	private function validate_request( array $request ): void {
		$allowed = [ 'format', 'version', 'request_id', 'action', 'post_id', 'post_type', 'post', 'taxonomies', 'acf', 'yoast', 'registered_meta', 'elementor', 'expected_state_token', 'confirm_destructive', 'result' ];
		if ( array_diff( array_keys( $request ), $allowed ) ) {
			throw new RuntimeException( 'The post request contains unsupported fields.' );
		}
		if ( self::FORMAT !== ( $request['format'] ?? null ) || self::VERSION !== (int) ( $request['version'] ?? 0 ) ) {
			throw new RuntimeException( 'The post request format or version is invalid.' );
		}
		$action = (string) ( $request['action'] ?? '' );
		if ( ! in_array( $action, [ 'create', 'read', 'update', 'delete' ], true ) ) {
			throw new RuntimeException( 'The post request action is invalid.' );
		}
		if ( 'create' === $action ) {
			if ( ! is_string( $request['post_type'] ?? null ) || ! is_array( $request['post'] ?? null ) ) {
				throw new RuntimeException( 'Creating content requires post_type and post.' );
			}
		} elseif ( (int) ( $request['post_id'] ?? 0 ) < 1 ) {
			throw new RuntimeException( 'Reading, updating or deleting content requires an exact post_id.' );
		}
		if ( in_array( $action, [ 'update', 'delete' ], true ) && ( ! is_string( $request['expected_state_token'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $request['expected_state_token'] ) ) ) {
			throw new RuntimeException( 'Updating or deleting content requires a valid expected_state_token.' );
		}
		if ( isset( $request['post'] ) && ( ! is_array( $request['post'] ) || ( [] !== $request['post'] && array_is_list( $request['post'] ) ) ) ) {
			throw new RuntimeException( 'The post request post field must be an object.' );
		}
	}

	private function validate_post_fields( int $id, array $post ): void {
		$allowed = [ 'title', 'slug', 'status', 'content', 'excerpt', 'parent', 'menu_order', 'comment_status', 'ping_status', 'page_template', 'featured_image', 'author', 'date', 'password', 'format', 'sticky' ];
		if ( array_diff( array_keys( $post ), $allowed ) ) {
			throw new RuntimeException( 'The post request contains unsupported post fields.' );
		}
		PostState::validate( $id, $this->extended_request_state( $post ) );
	}

	private function extended_request_state( array $post ): array {
		return array_intersect_key( $post, array_flip( [ 'author', 'date', 'password', 'format', 'sticky' ] ) );
	}
}
