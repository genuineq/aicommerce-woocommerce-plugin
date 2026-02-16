<?php
/**
 * JWT Token handling
 *
 * @package AICommerce
 */

namespace AICommerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * JWT Class
 */
class JWT {
    
    /**
     * Algorithm
     */
    private const ALGORITHM = 'HS256';
    
    /**
     * Access token expiration (24 hours)
     */
    private const ACCESS_TOKEN_EXP = 86400;
    
    /**
     * Refresh token expiration (7 days)
     */
    private const REFRESH_TOKEN_EXP = 604800;
    
    /**
     * Get JWT secret
     */
    private static function get_secret(): string {
        $encrypted = get_option( 'aicommerce_jwt_secret', '' );
        
        if ( empty( $encrypted ) ) {
            self::generate_secret();
            $encrypted = get_option( 'aicommerce_jwt_secret', '' );
        }
        
        return Encryption::decrypt( $encrypted );
    }
    
    /**
     * Generate and save JWT secret
     */
    public static function generate_secret(): bool {
        $secret = bin2hex( random_bytes( 32 ) );
        
        return update_option( 'aicommerce_jwt_secret', Encryption::encrypt( $secret ) );
    }
    
    /**
     * Base64 URL encode
     */
    private static function base64url_encode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }
    
    /**
     * Base64 URL decode
     */
    private static function base64url_decode( string $data ): string {
        return base64_decode( strtr( $data, '-_', '+/' ) );
    }
    
    /**
     * Create JWT token
     */
    public static function create_token( int $user_id, string $type = 'access' ): string {
        $issued_at = time();
        $expiration = $issued_at + ( 'refresh' === $type ? self::REFRESH_TOKEN_EXP : self::ACCESS_TOKEN_EXP );
        
        $header = array(
            'typ' => 'JWT',
            'alg' => self::ALGORITHM,
        );
        
        $payload = array(
            'iat'     => $issued_at,
            'exp'     => $expiration,
            'user_id' => $user_id,
            'type'    => $type,
        );
        
        $header_encoded = self::base64url_encode( wp_json_encode( $header ) );
        $payload_encoded = self::base64url_encode( wp_json_encode( $payload ) );
        
        $signature = hash_hmac( 'sha256', "$header_encoded.$payload_encoded", self::get_secret(), true );
        $signature_encoded = self::base64url_encode( $signature );
        
        return "$header_encoded.$payload_encoded.$signature_encoded";
    }
    
    /**
     * Verify and decode JWT token
     */
    public static function verify_token( string $token ): ?array {
        $parts = explode( '.', $token );
        
        if ( 3 !== count( $parts ) ) {
            return null;
        }
        
        list( $header_encoded, $payload_encoded, $signature_encoded ) = $parts;
        
        $signature = self::base64url_decode( $signature_encoded );
        $expected_signature = hash_hmac( 'sha256', "$header_encoded.$payload_encoded", self::get_secret(), true );
        
        if ( ! hash_equals( $expected_signature, $signature ) ) {
            return null;
        }
        
        $payload = json_decode( self::base64url_decode( $payload_encoded ), true );
        
        if ( ! $payload ) {
            return null;
        }
        
        if ( isset( $payload['exp'] ) && $payload['exp'] < time() ) {
            return null;
        }
        
        return $payload;
    }
    
    /**
     * Extract token from Authorization header
     */
    public static function get_token_from_header(): ?string {
        $auth_header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
        
        if ( empty( $auth_header ) && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
            $auth_header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        
        if ( empty( $auth_header ) ) {
            return null;
        }
        
        if ( preg_match( '/Bearer\s+(.*)$/i', $auth_header, $matches ) ) {
            return $matches[1];
        }
        
        return null;
    }
    
    /**
     * Get user ID from token
     */
    public static function get_user_id_from_token( string $token ): ?int {
        $payload = self::verify_token( $token );
        
        if ( ! $payload || ! isset( $payload['user_id'] ) ) {
            return null;
        }
        
        return (int) $payload['user_id'];
    }
    
    /**
     * Check if token is expired
     */
    public static function is_token_expired( string $token ): bool {
        $parts = explode( '.', $token );
        
        if ( 3 !== count( $parts ) ) {
            return true;
        }
        
        $payload = json_decode( self::base64url_decode( $parts[1] ), true );
        
        if ( ! $payload || ! isset( $payload['exp'] ) ) {
            return true;
        }
        
        return $payload['exp'] < time();
    }
    
    /**
     * Get token expiration time
     */
    public static function get_token_expiration( string $token ): ?int {
        $parts = explode( '.', $token );
        
        if ( 3 !== count( $parts ) ) {
            return null;
        }
        
        $payload = json_decode( self::base64url_decode( $parts[1] ), true );
        
        if ( ! $payload || ! isset( $payload['exp'] ) ) {
            return null;
        }
        
        return (int) $payload['exp'];
    }
}
