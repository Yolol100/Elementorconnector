<?php

namespace Webactueel\ElementorJsonBridge\Content;

use DateTimeImmutable;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class PostRequest {
	public const FORMAT = 'elementor-json-bridge/manage-post';
	public const VERSION = 1;

	public function __construct( private readonly WordPressDocument $content ) {}

	public function execute( array $request ): array {
		$this->validate_request( $request );
		$action = (string) $request['action'];
		if ( 'create' === $action ) {
			$create = [
				'format' => WordPressDocument::CREATE_FORMAT,
				'version' => WordPressDocument::VERSION,
				'request_id' => (string) $request['request_id'],
				'post_type' => (string) $request['post_type'],
				'post' => (array) $request['post'],
			];
			foreach ( [ 'taxonomies', 'acf', 'yoast', 'registered_meta', 'elementor' ] as $key ) {
				if ( array_key_exists( $key, $request ) ) { $create[ $key ] = $request[ $key ]; }
			}
			$id = $this->content->create_draft( $create );
			$this->apply_extended_post_fields( $id, (array) $request['post'] );
			return [ 'status' => 'created', 'post_id' => $id ];
		}

		$id = (int) ( $request['post_id'] ?? 0 );
		if ( ! $this->content->supports( $id ) || 'product' === get_post_type( $id ) ) { throw new RuntimeException( 'The requested WordPress content item is not managed by this request type.' ); }
		if ( ! current_user_can( 'edit_post', $id ) ) { throw new RuntimeException( 'You are not allowed to edit this WordPress content item.' ); }
		if ( 'delete' === $action ) {
			if ( true !== ( $request['confirm_destructive'] ?? false ) ) { throw new RuntimeException( 'Deleting WordPress content requires confirm_destructive=true.' ); }
			if ( ! current_user_can( 'delete_post', $id ) ) { throw new RuntimeException( 'You are not allowed to delete this WordPress content item.' ); }
			wp_trash_post( $id );
			return [ 'status' => 'deleted', 'post_id' => $id ];
		}

		$payload = $this->content->payload( $id );
		foreach ( [ 'taxonomies', 'acf', 'yoast', 'registered_meta', 'elementor' ] as $section ) {
			if ( array_key_exists( $section, $request ) ) { $payload[ $section ] = $request[ $section ]; }
		}
		$post = (array) $request['post'];
		foreach ( [ 'title', 'slug', 'status', 'content', 'excerpt', 'parent', 'menu_order', 'comment_status', 'ping_status', 'page_template', 'featured_image' ] as $field ) {
			if ( array_key_exists( $field, $post ) ) { $payload['post'][ $field ] = $post[ $field ]; }
		}
		$this->content->apply( $id, $payload );
		$this->apply_extended_post_fields( $id, $post );
		return [ 'status' => 'updated', 'post_id' => $id ];
	}

	private function validate_request( array $request ): void {
		$allowed = [ 'format', 'version', 'request_id', 'action', 'post_id', 'post_type', 'post', 'taxonomies', 'acf', 'yoast', 'registered_meta', 'elementor', 'confirm_destructive', 'result' ];
		if ( array_diff( array_keys( $request ), $allowed ) ) { throw new RuntimeException( 'The post request contains unsupported fields.' ); }
		if ( self::FORMAT !== ( $request['format'] ?? null ) || self::VERSION !== (int) ( $request['version'] ?? 0 ) ) { throw new RuntimeException( 'The post request format or version is invalid.' ); }
		if ( ! in_array( (string) ( $request['action'] ?? '' ), [ 'create', 'update', 'delete' ], true ) ) { throw new RuntimeException( 'The post request action is invalid.' ); }
		if ( 'create' === (string) $request['action'] ) {
			if ( ! is_string( $request['post_type'] ?? null ) || ! is_array( $request['post'] ?? null ) ) { throw new RuntimeException( 'Creating content requires post_type and post.' ); }
		} elseif ( (int) ( $request['post_id'] ?? 0 ) < 1 ) { throw new RuntimeException( 'Updating or deleting content requires an exact post_id.' ); }
		if ( isset( $request['post'] ) && ( ! is_array( $request['post'] ) || ( [] !== $request['post'] && array_is_list( $request['post'] ) ) ) ) { throw new RuntimeException( 'The post request post field must be an object.' ); }
	}

	private function apply_extended_post_fields( int $id, array $post ): void {
		$allowed = [ 'title', 'slug', 'status', 'content', 'excerpt', 'parent', 'menu_order', 'comment_status', 'ping_status', 'page_template', 'featured_image', 'author', 'date', 'password', 'format', 'sticky' ];
		if ( array_diff( array_keys( $post ), $allowed ) ) { throw new RuntimeException( 'The post request contains unsupported post fields.' ); }
		$update = [ 'ID' => $id ];
		if ( array_key_exists( 'author', $post ) ) {
			if ( ! is_int( $post['author'] ) || ! get_user_by( 'id', $post['author'] ) ) { throw new RuntimeException( 'The requested WordPress author is invalid.' ); }
			$object = get_post_type_object( (string) get_post_type( $id ) );
			if ( ! current_user_can( $object?->cap->edit_others_posts ?? 'edit_others_posts' ) ) { throw new RuntimeException( 'You are not allowed to change this content author.' ); }
			$update['post_author'] = $post['author'];
		}
		if ( array_key_exists( 'date', $post ) ) {
			if ( ! is_string( $post['date'] ) ) { throw new RuntimeException( 'The requested WordPress date is invalid.' ); }
			$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $post['date'] );
			if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $post['date'] ) { throw new RuntimeException( 'The requested WordPress date must use Y-m-d H:i:s.' ); }
			$update['post_date'] = $post['date'];
			$update['post_date_gmt'] = get_gmt_from_date( $post['date'] );
		}
		if ( array_key_exists( 'password', $post ) ) {
			if ( ! is_string( $post['password'] ) ) { throw new RuntimeException( 'The requested post password is invalid.' ); }
			$update['post_password'] = $post['password'];
		}
		if ( count( $update ) > 1 ) {
			$result = wp_update_post( wp_slash( $update ), true );
			if ( is_wp_error( $result ) ) { throw new RuntimeException( 'WordPress rejected the extended content update.' ); }
		}
		if ( array_key_exists( 'format', $post ) ) {
			if ( ! is_string( $post['format'] ) || ( '' !== $post['format'] && ! in_array( $post['format'], array_keys( get_post_format_strings() ), true ) ) ) { throw new RuntimeException( 'The requested post format is invalid.' ); }
			set_post_format( $id, '' === $post['format'] ? false : $post['format'] );
		}
		if ( array_key_exists( 'sticky', $post ) ) {
			if ( 'post' !== get_post_type( $id ) || ! is_bool( $post['sticky'] ) ) { throw new RuntimeException( 'Sticky can only be changed on normal posts.' ); }
			$post['sticky'] ? stick_post( $id ) : unstick_post( $id );
		}
	}
}
