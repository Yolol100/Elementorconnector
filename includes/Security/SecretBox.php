<?php

namespace Webactueel\ElementorJsonBridge\Security;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class SecretBox {
	private const CONTEXT = 'elementor-json-bridge/github-auth/v1';

	public function encrypt( array $value ): string {
		$plaintext = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $plaintext ) ) {
			throw new RuntimeException( 'Unable to encode GitHub credentials.' );
		}

		$key = $this->key();

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );

			return $this->encode_package(
				[
					'v'      => 1,
					'alg'    => 'sodium-secretbox',
					'nonce'  => base64_encode( $nonce ),
					'cipher' => base64_encode( $cipher ),
				]
			);
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv  = random_bytes( 12 );
			$tag = '';
			$cipher = openssl_encrypt(
				$plaintext,
				'aes-256-gcm',
				$key,
				OPENSSL_RAW_DATA,
				$iv,
				$tag,
				self::CONTEXT,
				16
			);

			if ( false === $cipher ) {
				throw new RuntimeException( 'Unable to encrypt GitHub credentials.' );
			}

			return $this->encode_package(
				[
					'v'      => 1,
					'alg'    => 'aes-256-gcm',
					'iv'     => base64_encode( $iv ),
					'tag'    => base64_encode( $tag ),
					'cipher' => base64_encode( $cipher ),
				]
			);
		}

		throw new RuntimeException( 'No supported encryption extension is available.' );
	}

	public function decrypt( string $package ): array {
		$data = json_decode( base64_decode( $package, true ) ?: '', true );
		if ( ! is_array( $data ) || 1 !== (int) ( $data['v'] ?? 0 ) ) {
			throw new RuntimeException( 'Stored GitHub credentials are invalid.' );
		}

		$key = $this->key();
		$plaintext = false;

		if ( 'sodium-secretbox' === ( $data['alg'] ?? '' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$nonce  = base64_decode( (string) ( $data['nonce'] ?? '' ), true );
			$cipher = base64_decode( (string) ( $data['cipher'] ?? '' ), true );
			if ( false !== $nonce && false !== $cipher ) {
				$plaintext = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			}
		} elseif ( 'aes-256-gcm' === ( $data['alg'] ?? '' ) && function_exists( 'openssl_decrypt' ) ) {
			$iv     = base64_decode( (string) ( $data['iv'] ?? '' ), true );
			$tag    = base64_decode( (string) ( $data['tag'] ?? '' ), true );
			$cipher = base64_decode( (string) ( $data['cipher'] ?? '' ), true );
			if ( false !== $iv && false !== $tag && false !== $cipher ) {
				$plaintext = openssl_decrypt(
					$cipher,
					'aes-256-gcm',
					$key,
					OPENSSL_RAW_DATA,
					$iv,
					$tag,
					self::CONTEXT
				);
			}
		}

		if ( ! is_string( $plaintext ) ) {
			throw new RuntimeException( 'Stored GitHub credentials cannot be decrypted.' );
		}

		$value = json_decode( $plaintext, true );
		if ( ! is_array( $value ) ) {
			throw new RuntimeException( 'Stored GitHub credentials are malformed.' );
		}

		return $value;
	}

	private function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) . self::CONTEXT, true );
	}

	private function encode_package( array $data ): string {
		$json = wp_json_encode( $data );
		if ( ! is_string( $json ) ) {
			throw new RuntimeException( 'Unable to encode encrypted credentials.' );
		}
		return base64_encode( $json );
	}
}
