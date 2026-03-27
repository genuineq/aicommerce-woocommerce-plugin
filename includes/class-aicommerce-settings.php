<?php
/**
 * Settings functionality
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Settings Class
 */
class Settings {

    /**
     * Get API Key.
     *
     * Retrieves the encrypted API key from WordPress options
     * and decrypts it before returning.
     *
     * @return string
     */
    public static function get_api_key(): string {
        /** Retrieve encrypted API key from database. */
        $encrypted = get_option( 'aicommerce_api_key', '' );

        /** Return empty string if no value is stored. */
        if ( empty( $encrypted ) ) {
            return '';
        }

        /** Decrypt and return API key. */
        return Encryption::decrypt( $encrypted );
    }

    /**
     * Get API Secret.
     *
     * Retrieves the encrypted API secret from WordPress options
     * and decrypts it before returning.
     *
     * @return string
     */
    public static function get_api_secret(): string {
        /** Retrieve encrypted API secret from database. */
        $encrypted = get_option( 'aicommerce_api_secret', '' );

        /** Return empty string if no value is stored. */
        if ( empty( $encrypted ) ) {
            return '';
        }

        /** Decrypt and return API secret. */
        return Encryption::decrypt( $encrypted );
    }

    /**
     * Check if API credentials are configured.
     *
     * Ensures both API key and API secret are present.
     *
     * @return bool
     */
    public static function has_credentials(): bool {
        /** Return true only if both key and secret are non-empty. */
        return ! empty( self::get_api_key() ) && ! empty( self::get_api_secret() );
    }

    /**
     * Update API Key.
     *
     * Encrypts and stores the API key in WordPress options.
     * Deletes the option if the value is empty.
     *
     * @param string $value API key value.
     * @return bool
     */
    public static function update_api_key( string $value ): bool {
        /** Delete option if value is empty. */
        if ( empty( $value ) ) {
            return delete_option( 'aicommerce_api_key' );
        }

        /** Encrypt and store API key. */
        return update_option( 'aicommerce_api_key', Encryption::encrypt( $value ) );
    }

    /**
     * Update API Secret.
     *
     * Encrypts and stores the API secret in WordPress options.
     * Deletes the option if the value is empty.
     *
     * @param string $value API secret value.
     * @return bool
     */
    public static function update_api_secret( string $value ): bool {
        /** Delete option if value is empty. */
        if ( empty( $value ) ) {
            return delete_option( 'aicommerce_api_secret' );
        }

        /** Encrypt and store API secret. */
        return update_option( 'aicommerce_api_secret', Encryption::encrypt( $value ) );
    }
}
