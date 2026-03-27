<?php
/**
 * Encryption functionality
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Encryption class. */
class Encryption {

    /** Encryption method. */
    private const METHOD = 'AES-256-CBC';

    /**
     * Get the encryption key.
     *
     * @return string
     */
    private static function get_key(): string {
        /** Combine WordPress authentication salts to derive the encryption key seed. */
        $salt = AUTH_KEY . SECURE_AUTH_KEY;

        /** Return the first 32 bytes of the SHA-256 hash as the AES-256 key. */
        return substr( hash( 'sha256', $salt, true ), 0, 32 );
    }

    /**
     * Encrypt a value.
     *
     * @param string $value The plain text value to encrypt.
     * @return string
     */
    public static function encrypt( string $value ): string {
        /** Return an empty string when the input value is empty. */
        if ( empty( $value ) ) {
            return '';
        }

        /** Get the IV length required by the configured cipher method. */
        $iv_length = openssl_cipher_iv_length( self::METHOD );

        /** Generate a random initialization vector. */
        $iv = openssl_random_pseudo_bytes( $iv_length );

        /** Encrypt the plain text value using the configured cipher and derived key. */
        $encrypted = openssl_encrypt(
            $value,
            self::METHOD,
            self::get_key(),
            OPENSSL_RAW_DATA,
            $iv
        );

        /** Return an empty string when encryption fails. */
        if ( false === $encrypted ) {
            return '';
        }

        /** Return the base64-encoded IV and encrypted payload. */
        return base64_encode( $iv . $encrypted );
    }

    /**
     * Decrypt a value.
     *
     * @param string $encrypted The encrypted value.
     * @return string
     */
    public static function decrypt( string $encrypted ): string {
        /** Return an empty string when the encrypted input is empty. */
        if ( empty( $encrypted ) ) {
            return '';
        }

        /** Decode the base64-encoded encrypted payload. */
        $data = base64_decode( $encrypted, true );

        /** Return an empty string when base64 decoding fails. */
        if ( false === $data ) {
            return '';
        }

        /** Get the IV length required by the configured cipher method. */
        $iv_length = openssl_cipher_iv_length( self::METHOD );

        /** Extract the initialization vector from the decoded payload. */
        $iv = substr( $data, 0, $iv_length );

        /** Extract the encrypted value after the IV bytes. */
        $encrypted_value = substr( $data, $iv_length );

        /** Decrypt the encrypted payload using the configured cipher and derived key. */
        $decrypted = openssl_decrypt(
            $encrypted_value,
            self::METHOD,
            self::get_key(),
            OPENSSL_RAW_DATA,
            $iv
        );

        /** Return an empty string when decryption fails. */
        if ( false === $decrypted ) {
            return '';
        }

        /** Return the decrypted plain text value. */
        return $decrypted;
    }

    /**
     * Check whether a value appears to be encrypted.
     *
     * @param string $value The value to inspect.
     * @return bool
     */
    public static function is_encrypted( string $value ): bool {
        /** Return false when the value is empty. */
        if ( empty( $value ) ) {
            return false;
        }

        /** Attempt to base64-decode the provided value. */
        $decoded = base64_decode( $value, true );

        /** Return false when base64 decoding fails. */
        if ( false === $decoded ) {
            return false;
        }

        /** Get the IV length required by the configured cipher method. */
        $iv_length = openssl_cipher_iv_length( self::METHOD );

        /** Return whether the decoded payload is longer than the IV length. */
        return strlen( $decoded ) > $iv_length;
    }

    /**
     * Mask a value for display by showing only the last visible characters.
     *
     * @param string $value The value to mask.
     * @param int    $visible_chars The number of visible trailing characters.
     * @return string
     */
    public static function mask( string $value, int $visible_chars = 4 ): string {
        /** Return an empty string when the input value is empty. */
        if ( empty( $value ) ) {
            return '';
        }

        /** Get the total string length. */
        $length = strlen( $value );

        /** Return a fully masked string when the value is shorter than or equal to the visible length. */
        if ( $length <= $visible_chars ) {
            return str_repeat( '•', $length );
        }

        /** Calculate how many characters should be masked. */
        $masked_length = $length - $visible_chars;

        /** Build the masked portion of the string. */
        $masked = str_repeat( '•', $masked_length );

        /** Extract the visible trailing characters. */
        $visible = substr( $value, -$visible_chars );

        /** Return the masked value combined with the visible suffix. */
        return $masked . $visible;
    }
}
