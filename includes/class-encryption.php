<?php
/**
 * AES-256-CBC encryption for tokens and app secrets.
 *
 * @package Cohort_Instagram
 */

defined( 'ABSPATH' ) || exit;

/**
 * Encrypt / decrypt helpers.
 */
class Cohort_Instagram_Encryption {

	/**
	 * Encryption key (32 bytes hex-encoded in storage).
	 *
	 * @return string Binary key material.
	 */
	private static function key() {
		if ( defined( 'COHORT_INSTAGRAM_ENCRYPTION_KEY' ) && COHORT_INSTAGRAM_ENCRYPTION_KEY ) {
			return hash( 'sha256', (string) COHORT_INSTAGRAM_ENCRYPTION_KEY, true );
		}

		$stored = get_option( 'cohort_instagram_enc_key', '' );
		if ( ! is_string( $stored ) || strlen( $stored ) < 64 ) {
			$stored = bin2hex( random_bytes( 32 ) );
			update_option( 'cohort_instagram_enc_key', $stored, false );
		}

		return hash( 'sha256', $stored . ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'cohort-ig' ), true );
	}

	/**
	 * @param string $plaintext Value to encrypt.
	 * @return string Base64 payload (iv + ciphertext) or empty on failure.
	 */
	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}

		$iv = random_bytes( 16 );
		$cipher = openssl_encrypt( $plaintext, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return '';
		}

		return base64_encode( $iv . $cipher );
	}

	/**
	 * @param string $payload Encrypted payload from encrypt().
	 * @return string Plaintext or empty on failure.
	 */
	public static function decrypt( $payload ) {
		$payload = (string) $payload;
		if ( '' === $payload || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$raw = base64_decode( $payload, true );
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}

		$iv     = substr( $raw, 0, 16 );
		$cipher = substr( $raw, 16 );
		$plain  = openssl_decrypt( $cipher, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv );

		return false === $plain ? '' : $plain;
	}
}
