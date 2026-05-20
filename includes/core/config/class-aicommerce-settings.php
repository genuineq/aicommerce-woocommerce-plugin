<?php
/**
 * Settings functionality
 *
 * @package AICommerce
 */

namespace AICommerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Settings Class
 */
class Settings {
    
    /**
     * Get API Key
     */
    public static function get_api_key(): string {
        $encrypted = get_option( 'aicommerce_api_key', '' );
        
        if ( empty( $encrypted ) ) {
            return '';
        }
        
        return Encryption::decrypt( $encrypted );
    }
    
    /**
     * Get API Secret
     */
    public static function get_api_secret(): string {
        $encrypted = get_option( 'aicommerce_api_secret', '' );
        
        if ( empty( $encrypted ) ) {
            return '';
        }
        
        return Encryption::decrypt( $encrypted );
    }
    
    /**
     * Check if API credentials are configured
     */
    public static function has_credentials(): bool {
        return ! empty( self::get_api_key() ) && ! empty( self::get_api_secret() );
    }
    
    /**
     * Update API Key
     */
    public static function update_api_key( string $value ): bool {
        if ( empty( $value ) ) {
            return delete_option( 'aicommerce_api_key' );
        }
        
        return update_option( 'aicommerce_api_key', Encryption::encrypt( $value ) );
    }
    
    /**
     * Update API Secret
     */
    public static function update_api_secret( string $value ): bool {
        if ( empty( $value ) ) {
            return delete_option( 'aicommerce_api_secret' );
        }
        
        return update_option( 'aicommerce_api_secret', Encryption::encrypt( $value ) );
    }
}
