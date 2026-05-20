<?php
/**
 * Rate Limiting functionality
 *
 * @package AICommerce
 */

namespace AICommerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Rate Limiter Class
 */
class RateLimiter {
    
    /**
     * Check if request is allowed
     */
    public static function is_allowed( string $key, int $max_attempts = 5, int $window = 60 ): bool {
        $transient_key = 'aicommerce_rate_limit_' . md5( $key );
        $attempts = get_transient( $transient_key );
        
        if ( false === $attempts ) {
            set_transient( $transient_key, 1, $window );
            return true;
        }
        
        if ( $attempts >= $max_attempts ) {
            return false;
        }
        
        set_transient( $transient_key, $attempts + 1, $window );
        return true;
    }
    
    /**
     * Get remaining attempts
     */
    public static function get_remaining_attempts( string $key, int $max_attempts = 5 ): int {
        $transient_key = 'aicommerce_rate_limit_' . md5( $key );
        $attempts = get_transient( $transient_key );
        
        if ( false === $attempts ) {
            return $max_attempts;
        }
        
        return max( 0, $max_attempts - $attempts );
    }
    
    /**
     * Reset rate limit for key
     */
    public static function reset( string $key ): bool {
        $transient_key = 'aicommerce_rate_limit_' . md5( $key );
        return delete_transient( $transient_key );
    }
    
    /**
     * Get client identifier (IP + User Agent)
     */
    public static function get_client_id(): string {
        $ip = self::get_client_ip();
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
        
        return md5( $ip . $user_agent );
    }
    
    /**
     * Get client IP address
     */
    public static function get_client_ip(): string {
        $ip = '';
        
        if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif ( isset( $_SERVER['HTTP_CLIENT_IP'] ) ) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        if ( strpos( $ip, ',' ) !== false ) {
            $ips = explode( ',', $ip );
            $ip = trim( $ips[0] );
        }
        
        return $ip;
    }
}
