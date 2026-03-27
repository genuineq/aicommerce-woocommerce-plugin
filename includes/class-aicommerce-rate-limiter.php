<?php
/**
 * Rate Limiting functionality
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Rate Limiter Class
 */
class RateLimiter {

    /**
     * Check if request is allowed.
     *
     * Uses a transient-based counter to limit the number of attempts
     * within a given time window.
     *
     * @param string $key          Unique identifier for rate limiting (e.g. IP or user).
     * @param int    $max_attempts Maximum allowed attempts.
     * @param int    $window       Time window in seconds.
     * @return bool
     */
    public static function is_allowed( string $key, int $max_attempts = 5, int $window = 60 ): bool {
        /** Build transient key using hashed identifier. */
        $transient_key = 'aicommerce_rate_limit_' . md5( $key );

        /** Retrieve current attempt count. */
        $attempts = get_transient( $transient_key );

        /** Initialize attempts if none exist yet. */
        if ( false === $attempts ) {
            set_transient( $transient_key, 1, $window );
            return true;
        }

        /** Block request if maximum attempts reached. */
        if ( $attempts >= $max_attempts ) {
            return false;
        }

        /** Increment attempt count and update transient. */
        set_transient( $transient_key, $attempts + 1, $window );

        /** Allow request. */
        return true;
    }

    /**
     * Get remaining attempts.
     *
     * Calculates how many attempts are left before hitting the limit.
     *
     * @param string $key          Unique identifier for rate limiting.
     * @param int    $max_attempts Maximum allowed attempts.
     * @return int
     */
    public static function get_remaining_attempts( string $key, int $max_attempts = 5 ): int {
        /** Build transient key using hashed identifier. */
        $transient_key = 'aicommerce_rate_limit_' . md5( $key );

        /** Retrieve current attempt count. */
        $attempts = get_transient( $transient_key );

        /** If no attempts exist, full quota is available. */
        if ( false === $attempts ) {
            return $max_attempts;
        }

        /** Return remaining attempts, ensuring non-negative value. */
        return max( 0, $max_attempts - $attempts );
    }

    /**
     * Reset rate limit for a given key.
     *
     * Removes the stored transient, effectively resetting the counter.
     *
     * @param string $key Unique identifier for rate limiting.
     * @return bool
     */
    public static function reset( string $key ): bool {
        /** Build transient key using hashed identifier. */
        $transient_key = 'aicommerce_rate_limit_' . md5( $key );

        /** Delete the transient to reset attempts. */
        return delete_transient( $transient_key );
    }

    /**
     * Get client identifier.
     *
     * Combines client IP and User-Agent to create a unique identifier.
     *
     * @return string
     */
    public static function get_client_id(): string {
        /** Retrieve client IP address. */
        $ip = self::get_client_ip();

        /** Retrieve User-Agent header if available. */
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';

        /** Return hashed identifier for consistency and privacy. */
        return md5( $ip . $user_agent );
    }

    /**
     * Get client IP address.
     *
     * Attempts to detect the real client IP, including proxy headers.
     *
     * @return string
     */
    public static function get_client_ip(): string {
        /** Initialize IP variable. */
        $ip = '';

        /** Check for proxy-forwarded IP address. */
        if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];

        /** Fallback to client IP header. */
        } elseif ( isset( $_SERVER['HTTP_CLIENT_IP'] ) ) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];

        /** Fallback to remote address. */
        } elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        /** Handle multiple IPs (comma-separated list) and take the first one. */
        if ( strpos( $ip, ',' ) !== false ) {
            $ips = explode( ',', $ip );
            $ip = trim( $ips[0] );
        }

        /** Return detected IP address. */
        return $ip;
    }
}
