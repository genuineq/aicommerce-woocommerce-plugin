<?php
/**
 * Guest Token Management
 *
 * @package AICommerce
 */

namespace AICommerce;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Guest Token Class
 * Manages guest user identification via localStorage and cookies
 */
class GuestToken {
    
    private const COOKIE_NAME = 'aicommerce_guest_token';
    
    private const CUSTOMER_ID_COOKIE_NAME = 'aicommerce_customer_id';

    private const COOKIE_EXPIRATION = YEAR_IN_SECONDS;
    
    public function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'init', array( $this, 'set_cookie_if_needed' ) );
        add_action( 'init', array( $this, 'set_customer_id_if_logged_in' ) );
        add_action( 'wp_login', array( $this, 'on_user_login' ), 10, 2 );
        add_action( 'wp_logout', array( $this, 'on_user_logout' ) );
    }
    
    /**
     * Generate unique guest token
     */
    private function generate_token(): string {
        $timestamp = time();
        $random = wp_generate_password( 16, false );
        $site_hash = substr( md5( home_url() ), 0, 8 );
        
        return sprintf( 'guest_%s_%s_%s', $timestamp, $random, $site_hash );
    }
    
    /**
     * Get guest token from cookie
     */
    public function get_token_from_cookie(): string {
        if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            return sanitize_text_field( $_COOKIE[ self::COOKIE_NAME ] );
        }
        
        return '';
    }
    
    /**
     * Set guest token cookie
     */
    public function set_cookie_if_needed(): void {
        if ( is_admin() ) {
            return;
        }
        
        if ( is_user_logged_in() ) {
            return;
        }
        
        $existing_token = $this->get_token_from_cookie();
        
        if ( ! empty( $existing_token ) ) {
            return;
        }
        
        $token = $this->generate_token();
        
        $secure = is_ssl();
        $expire = time() + self::COOKIE_EXPIRATION;
        
        setcookie(self::COOKIE_NAME, $token, $expire, COOKIEPATH, COOKIE_DOMAIN, $secure, false);
        
        $_COOKIE[ self::COOKIE_NAME ] = $token;
    }
    
    /**
     * Set customer ID cookie if user is logged in
     */
    public function set_customer_id_if_logged_in(): void {
        if ( is_admin() ) {
            return;
        }
        
        if ( ! is_user_logged_in() ) {
            return;
        }
        
        $user_id = get_current_user_id();
        
        if ( empty( $user_id ) ) {
            return;
        }
        
        // Check if cookie already exists and matches
        $existing_customer_id = $this->get_customer_id_from_cookie();
        
        if ( (string) $user_id === $existing_customer_id ) {
            return;
        }
        
        // Set customer ID cookie
        $secure = is_ssl();
        $expire = time() + self::COOKIE_EXPIRATION;
        
        setcookie(
            self::CUSTOMER_ID_COOKIE_NAME,
            (string) $user_id,
            $expire,
            COOKIEPATH,
            COOKIE_DOMAIN,
            $secure,
            false // Allow JavaScript access
        );
        
        // Also set in $_COOKIE for current request
        $_COOKIE[ self::CUSTOMER_ID_COOKIE_NAME ] = (string) $user_id;
    }
    
    /**
     * Handle user login
     */
    public function on_user_login( string $user_login, \WP_User $user ): void {
        // Set customer ID cookie on login
        $secure = is_ssl();
        $expire = time() + self::COOKIE_EXPIRATION;
        
        setcookie(
            self::CUSTOMER_ID_COOKIE_NAME,
            (string) $user->ID,
            $expire,
            COOKIEPATH,
            COOKIE_DOMAIN,
            $secure,
            false
        );
        
        // Also set in $_COOKIE for current request
        $_COOKIE[ self::CUSTOMER_ID_COOKIE_NAME ] = (string) $user->ID;
    }
    
    /**
     * Handle user logout
     */
    public function on_user_logout(): void {
        setcookie(self::CUSTOMER_ID_COOKIE_NAME, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false);
        unset( $_COOKIE[ self::CUSTOMER_ID_COOKIE_NAME ] );
    }
    
    /**
     * Get customer ID from cookie
     */
    public function get_customer_id_from_cookie(): string {
        if ( isset( $_COOKIE[ self::CUSTOMER_ID_COOKIE_NAME ] ) ) {
            return sanitize_text_field( $_COOKIE[ self::CUSTOMER_ID_COOKIE_NAME ] );
        }
        
        return '';
    }
    
    /**
     * Enqueue frontend scripts
     */
    public function enqueue_scripts(): void {
        if ( is_admin() ) {
            return;
        }
        
        wp_enqueue_script(
            'aicommerce-guest-token',
            AICOMMERCE_PLUGIN_URL . 'assets/js/guest-token.js',
            array(),
            AICOMMERCE_VERSION,
            true
        );
        
        $token = $this->get_token_from_cookie();
        $customer_id = '';
        
        if ( is_user_logged_in() ) {
            $customer_id = (string) get_current_user_id();
        } else {
            $customer_id = $this->get_customer_id_from_cookie();
        }
        
        wp_localize_script(
            'aicommerce-guest-token',
            'aicommerceGuestToken',
            array(
                'token'       => $token,
                'customer_id' => $customer_id,
            )
        );
    }
    
    /**
     * Get guest token (for use in other classes)
     */
    public static function get_token(): string {
        $instance = new self();
        return $instance->get_token_from_cookie();
    }
    
    /**
     * Get customer ID (for use in other classes)
     */
    public static function get_customer_id(): string {
        if ( is_user_logged_in() ) {
            return (string) get_current_user_id();
        }
        
        $instance = new self();
        return $instance->get_customer_id_from_cookie();
    }
}
