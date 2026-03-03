<?php
/**
 * Encryption helper for secure password storage.
 *
 * Uses WordPress AUTH_KEY and AUTH_SALT as encryption keys via OpenSSL.
 * Falls back to base64 encoding if OpenSSL is not available.
 *
 * @package CF7_SMTP_Bridge
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF7_SMTP_Encryption {

	/**
	 * Cipher method used for OpenSSL encryption.
	 *
	 * @var string
	 */
	private const CIPHER = 'aes-256-cbc';

	/**
	 * Derive a 32-byte encryption key from WordPress salts.
	 *
	 * @return string
	 */
	private static function get_key(): string {
		$secret = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'cf7-smtp-default-key';
		return hash( 'sha256', $secret, true );
	}

	/**
	 * Check whether OpenSSL is available and supports the cipher.
	 *
	 * @return bool
	 */
	public static function is_openssl_available(): bool {
		return function_exists( 'openssl_encrypt' )
			&& in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	/**
	 * Encrypt a plaintext value.
	 *
	 * @param string $plaintext The value to encrypt.
	 * @return string Base64-encoded ciphertext with IV prepended.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		if ( ! self::is_openssl_available() ) {
			// Fallback: base64 only (not truly secure, but obfuscated).
			return 'base64:' . base64_encode( $plaintext );
		}

		$key    = self::get_key();
		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		$iv     = openssl_random_pseudo_bytes( $iv_len );

		$encrypted = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $encrypted ) {
			return 'base64:' . base64_encode( $plaintext );
		}

		return 'enc:' . base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt a previously encrypted value.
	 *
	 * @param string $data The encrypted data string.
	 * @return string The decrypted plaintext.
	 */
	public static function decrypt( string $data ): string {
		if ( '' === $data ) {
			return '';
		}

		// Handle base64 fallback format.
		if ( str_starts_with( $data, 'base64:' ) ) {
			$decoded = base64_decode( substr( $data, 7 ), true );
			return false !== $decoded ? $decoded : '';
		}

		// Handle encrypted format.
		if ( str_starts_with( $data, 'enc:' ) ) {
			if ( ! self::is_openssl_available() ) {
				return '';
			}

			$key    = self::get_key();
			$raw    = base64_decode( substr( $data, 4 ), true );
			$iv_len = openssl_cipher_iv_length( self::CIPHER );

			if ( false === $raw || strlen( $raw ) <= $iv_len ) {
				return '';
			}

			$iv        = substr( $raw, 0, $iv_len );
			$encrypted = substr( $raw, $iv_len );

			$decrypted = openssl_decrypt( $encrypted, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

			return false !== $decrypted ? $decrypted : '';
		}

		// Legacy: plain text stored before encryption was enabled.
		return $data;
	}
}
