<?php
/**
 * Encryption functionality
 *
 * @package AICommerce
 */

namespace AICommerce;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Encryption Class
 */
class Encryption {
    
    /**
     * Encryption method
     */
    private const METHOD = 'AES-256-CBC';
    
    /**
     * Get encryption key
     */
    private static function get_key(): string {
        $salt = AUTH_KEY . SECURE_AUTH_KEY;
        
        return substr( hash( 'sha256', $salt, true ), 0, 32 );
    }
    
    /**
     * Encrypt a value
     */
    public static function encrypt( string $value ): string {
        if ( empty( $value ) ) {
            return '';
        }
        
        $iv_length = openssl_cipher_iv_length( self::METHOD );
        $iv = openssl_random_pseudo_bytes( $iv_length );
        
        $encrypted = openssl_encrypt(
            $value,
            self::METHOD,
            self::get_key(),
            OPENSSL_RAW_DATA,
            $iv
        );
        
        if ( false === $encrypted ) {
            return '';
        }
        
        return base64_encode( $iv . $encrypted );
    }
    
    /**
     * Decrypt a value
     */
    public static function decrypt( string $encrypted ): string {
        if ( empty( $encrypted ) ) {
            return '';
        }
        
        $data = base64_decode( $encrypted, true );
        
        if ( false === $data ) {
            return '';
        }
        
        $iv_length = openssl_cipher_iv_length( self::METHOD );
        $iv = substr( $data, 0, $iv_length );
        $encrypted_value = substr( $data, $iv_length );
        
        $decrypted = openssl_decrypt(
            $encrypted_value,
            self::METHOD,
            self::get_key(),
            OPENSSL_RAW_DATA,
            $iv
        );
        
        if ( false === $decrypted ) {
            return '';
        }
        
        return $decrypted;
    }
    
    /**
     * Check if a value is encrypted
     */
    public static function is_encrypted( string $value ): bool {
        if ( empty( $value ) ) {
            return false;
        }
        
        $decoded = base64_decode( $value, true );
        
        if ( false === $decoded ) {
            return false;
        }
        
        $iv_length = openssl_cipher_iv_length( self::METHOD );
        
        return strlen( $decoded ) > $iv_length;
    }
    
    /**
     * Mask a value for display (show only last 4 characters)
     */
    public static function mask( string $value, int $visible_chars = 4 ): string {
        if ( empty( $value ) ) {
            return '';
        }
        
        $length = strlen( $value );
        
        if ( $length <= $visible_chars ) {
            return str_repeat( '•', $length );
        }
        
        $masked_length = $length - $visible_chars;
        $masked = str_repeat( '•', $masked_length );
        $visible = substr( $value, -$visible_chars );
        
        return $masked . $visible;
    }
}
