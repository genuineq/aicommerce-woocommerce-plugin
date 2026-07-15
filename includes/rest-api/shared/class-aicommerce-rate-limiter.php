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
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

        if ( self::is_trusted_proxy_request( $ip ) ) {
            if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
            } elseif ( isset( $_SERVER['HTTP_CLIENT_IP'] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
            }
        }
        
        if ( strpos( $ip, ',' ) !== false ) {
            $ips = explode( ',', $ip );
            $ip = trim( $ips[0] );
        }
        
        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
    }

    /**
     * Check whether forwarded headers should be trusted for this request.
     *
     * @param string $remote_addr Direct client/proxy IP from REMOTE_ADDR.
     * @return bool True when forwarded headers may be used.
     */
    private static function is_trusted_proxy_request( string $remote_addr ): bool {
        $trusted_proxies = apply_filters( 'aicommerce_trusted_proxy_ips', array() );

        if ( empty( $trusted_proxies ) || empty( $remote_addr ) ) {
            return false;
        }

        foreach ( (array) $trusted_proxies as $trusted_proxy ) {
            if ( hash_equals( (string) $trusted_proxy, $remote_addr ) ) {
                return true;
            }
        }

        return false;
    }
}
