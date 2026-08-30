<?php
/**
 * At-rest encryption for API keys stored in wp_options.
 *
 * Uses AES-256-CTR via OpenSSL with a key derived from the site's
 * authentication salts, so ciphertext is useless outside this install.
 *
 * @package Cubix_AI_Article_Generator
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CXAI_Encryption
 */
class CXAI_Encryption {

	/**
	 * Cipher method.
	 */
	const METHOD = 'aes-256-ctr';

	/**
	 * Marker prefix identifying an encrypted value.
	 */
	const PREFIX = 'cxai_enc::';

	/**
	 * Derive a stable 256-bit key from WordPress salts.
	 *
	 * @return string Raw 32-byte key.
	 */
	private static function get_key() {
		return hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
	}

	/**
	 * Whether OpenSSL encryption is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}

	/**
	 * Encrypt a plaintext string.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string Prefixed base64 ciphertext, or raw value if OpenSSL is missing.
	 */
	public static function encrypt( $plaintext ) {
		if ( '' === $plaintext || ! self::is_available() ) {
			return $plaintext;
		}

		// Never encrypt an already-encrypted value: settings sanitizers can
		// run more than once per save, and a double-encrypted key decrypts
		// to ciphertext instead of the key.
		if ( is_string( $plaintext ) && 0 === strpos( $plaintext, self::PREFIX ) ) {
			return $plaintext;
		}

		$iv         = random_bytes( openssl_cipher_iv_length( self::METHOD ) );
		$ciphertext = openssl_encrypt( $plaintext, self::METHOD, self::get_key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $ciphertext ) {
			return $plaintext;
		}

		return self::PREFIX . base64_encode( $iv . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a value produced by encrypt().
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	public static function decrypt( $value ) {
		if ( ! is_string( $value ) || 0 !== strpos( $value, self::PREFIX ) ) {
			return is_string( $value ) ? $value : '';
		}

		if ( ! self::is_available() ) {
			return '';
		}

		// Self-heal values that were double-encrypted by older versions:
		// peel every layer instead of only the outermost one.
		$peeled = self::decrypt_once( $value );
		$guard  = 0;

		while ( is_string( $peeled ) && 0 === strpos( $peeled, self::PREFIX ) && $guard < 5 ) {
			$peeled = self::decrypt_once( $peeled );
			$guard++;
		}

		return $peeled;
	}

	/**
	 * Decrypt exactly one layer.
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	private static function decrypt_once( $value ) {
		if ( ! is_string( $value ) || 0 !== strpos( $value, self::PREFIX ) ) {
			return is_string( $value ) ? $value : '';
		}

		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw ) {
			return '';
		}

		$iv_length = openssl_cipher_iv_length( self::METHOD );
		$plaintext = openssl_decrypt(
			substr( $raw, $iv_length ),
			self::METHOD,
			self::get_key(),
			OPENSSL_RAW_DATA,
			substr( $raw, 0, $iv_length )
		);

		return ( false === $plaintext ) ? '' : $plaintext;
	}

	/**
	 * Mask a key for display.
	 *
	 * @param string $key Plain key.
	 * @return string
	 */
	public static function mask( $key ) {
		$length = strlen( $key );

		if ( $length < 10 ) {
			return str_repeat( '*', max( 0, $length ) );
		}

		return substr( $key, 0, 3 ) . str_repeat( '*', 8 ) . substr( $key, -4 );
	}
}
