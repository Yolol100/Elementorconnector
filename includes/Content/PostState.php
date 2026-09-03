<?php

namespace Webactueel\ElementorJsonBridge\Content;

use DateTimeImmutable;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class PostState {
	public static function read( int $id ): array {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			throw new RuntimeException( 'The WordPress content item no longer exists.' );
		}
		$format = get_post_format( $id );
		$state  = [
			'author'   => (int) $post->post_author,
			'date'     => (string) $post->post_date,
			'password' => (string) $post->post_password,
			'format'   => false === $format ? '' : (string) $format,
		];
		if ( 'post' === $post->post_type ) {
			$state['sticky'] = is_sticky( $id );
		}
		return $state;
	}

	public static function validate( int $id, array $state ): void {
		$allowed = [ 'author', 'date', 'password', 'format', 'sticky' ];
		if ( array_diff( array_keys( $state ), $allowed ) ) {
			throw new RuntimeException( 'The WordPress extended content state contains unsupported fields.' );
		}
		if ( array_key_exists( 'author', $state ) ) {
			if ( ! is_int( $state['author'] ) || ! get_user_by( 'id', $state['author'] ) ) {
				throw new RuntimeException( 'The requested WordPress author is invalid.' );
			}
			$object = get_post_type_object( (string) get_post_type( $id ) );
			if ( ! current_user_can( $object?->cap->edit_others_posts ?? 'edit_others_posts' ) ) {
				throw new RuntimeException( 'You are not allowed to change this content author.' );
			}
		}
		if ( array_key_exists( 'date', $state ) ) {
			if ( ! is_string( $state['date'] ) ) {
				throw new RuntimeException( 'The requested WordPress date is invalid.' );
			}
			$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $state['date'] );
			if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $state['date'] ) {
				throw new RuntimeException( 'The requested WordPress date must use Y-m-d H:i:s.' );
			}
		}
		if ( array_key_exists( 'password', $state ) && ! is_string( $state['password'] ) ) {
			throw new RuntimeException( 'The requested post password is invalid.' );
		}
		if ( array_key_exists( 'format', $state ) && ( ! is_string( $state['format'] ) || ( '' !== $state['format'] && ! in_array( $state['format'], array_keys( get_post_format_strings() ), true ) ) ) ) {
			throw new RuntimeException( 'The requested post format is invalid.' );
		}
		if ( array_key_exists( 'sticky', $state ) && ( 'post' !== get_post_type( $id ) || ! is_bool( $state['sticky'] ) ) ) {
			throw new RuntimeException( 'Sticky can only be changed on normal posts.' );
		}
	}

	public static function apply( int $id, array $state ): void {
		self::validate( $id, $state );
		$update = [ 'ID' => $id ];
		if ( array_key_exists( 'author', $state ) ) {
			$update['post_author'] = $state['author'];
		}
		if ( array_key_exists( 'date', $state ) ) {
			$update['post_date']     = $state['date'];
			$update['post_date_gmt'] = get_gmt_from_date( $state['date'] );
		}
		if ( array_key_exists( 'password', $state ) ) {
			$update['post_password'] = $state['password'];
		}
		if ( count( $update ) > 1 ) {
			$result = wp_update_post( wp_slash( $update ), true );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( 'WordPress rejected the extended content update.' );
			}
		}
		if ( array_key_exists( 'format', $state ) ) {
			set_post_format( $id, '' === $state['format'] ? false : $state['format'] );
		}
		if ( array_key_exists( 'sticky', $state ) ) {
			$state['sticky'] ? stick_post( $id ) : unstick_post( $id );
		}
	}
}
