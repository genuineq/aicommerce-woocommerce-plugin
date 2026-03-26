<?php
/**
 * Guest Token Management
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Guest Token Class
 *
 * Manages guest user identification through cookies and frontend script data.
 */
class GuestToken {

    /** Guest token cookie name. */
    private const COOKIE_NAME = 'aicommerce_guest_token';

    /** Customer ID cookie name. */
    private const CUSTOMER_ID_COOKIE_NAME = 'aicommerce_customer_id';

    /** Cookie lifetime duration. */
    private const COOKIE_EXPIRATION = YEAR_IN_SECONDS;

    /**
     * Constructor.
     */
    public function __construct() {
        /** Register frontend guest token script. */
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        /** Ensure guest token cookie exists for guest visitors. */
        add_action( 'init', array( $this, 'set_cookie_if_needed' ) );

        /** Ensure customer ID cookie exists for logged-in users. */
        add_action( 'init', array( $this, 'set_customer_id_if_logged_in' ) );

        /** Refresh customer ID cookie on login. */
        add_action( 'wp_login', array( $this, 'on_user_login' ), 10, 2 );

        /** Clear customer ID cookie on logout. */
        add_action( 'wp_logout', array( $this, 'on_user_logout' ) );
    }

    /**
     * Generate a unique guest token.
     *
     * @return string Generated guest token.
     */
    private function generate_token(): string {
        /** Current timestamp used as part of the token. */
        $timestamp = time();

        /** Random alphanumeric segment used for uniqueness. */
        $random = wp_generate_password( 16, false );

        /** Short site-specific hash used to namespace the token. */
        $site_hash = substr( md5( home_url() ), 0, 8 );

        /** Build final token using timestamp, random string, and site hash. */
        return sprintf( 'guest_%s_%s_%s', $timestamp, $random, $site_hash );
    }

    /**
     * Get guest token from cookie.
     *
     * @return string Guest token from cookie or empty string.
     */
    public function get_token_from_cookie(): string {
        /** Return sanitized guest token when cookie exists. */
        if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            return sanitize_text_field( $_COOKIE[ self::COOKIE_NAME ] );
        }

        /** Return empty string when cookie is missing. */
        return '';
    }

    /**
     * Validate guest token format.
     *
     * @param string $token Token to validate.
     *
     * @return bool True when token format is valid.
     */
    private function is_valid_token_format( string $token ): bool {
        /** Validate token against expected guest token structure. */
        return (bool) preg_match( '/^guest_\d+_[a-zA-Z0-9]+_[a-f0-9]{8}$/', $token );
    }

    /**
     * Set guest token cookie if needed.
     *
     * If a valid guest_token is passed through the URL parameter, it takes precedence
     * over the existing cookie so the browser can attach to a cart created externally.
     */
    public function set_cookie_if_needed(): void {
        /** Do not run in admin area. */
        if ( is_admin() ) {
            return;
        }

        /** Logged-in users do not need a guest token. */
        if ( is_user_logged_in() ) {
            return;
        }

        /**
         * Allow overriding the guest token via URL parameter.
         *
         * This allows external API consumers to direct the browser to a cart created
         * with the same guest token used in API requests.
         */
        if ( isset( $_GET['guest_token'] ) ) {
            /** Read and sanitize token from query string. */
            $url_token = sanitize_text_field( wp_unslash( $_GET['guest_token'] ) );

            /** Accept URL token only if its format is valid. */
            if ( $this->is_valid_token_format( $url_token ) ) {
                /** Mark cookie as secure only on HTTPS connections. */
                $secure = is_ssl();

                /** Compute cookie expiration timestamp. */
                $expire = time() + self::COOKIE_EXPIRATION;

                /** Persist guest token into browser cookie. */
                setcookie( self::COOKIE_NAME, $url_token, $expire, COOKIEPATH, COOKIE_DOMAIN, $secure, false );

                /** Mirror cookie value inside current request. */
                $_COOKIE[ self::COOKIE_NAME ] = $url_token;

                return;
            }
        }

        /** Load existing guest token from cookie. */
        $existing_token = $this->get_token_from_cookie();

        /** Stop if guest token already exists. */
        if ( ! empty( $existing_token ) ) {
            return;
        }

        /** Generate a new guest token for the current guest visitor. */
        $token = $this->generate_token();

        /** Mark cookie as secure only on HTTPS connections. */
        $secure = is_ssl();

        /** Compute cookie expiration timestamp. */
        $expire = time() + self::COOKIE_EXPIRATION;

        /** Store generated token in cookie. */
        setcookie( self::COOKIE_NAME, $token, $expire, COOKIEPATH, COOKIE_DOMAIN, $secure, false );

        /** Mirror cookie value inside current request. */
        $_COOKIE[ self::COOKIE_NAME ] = $token;
    }

    /**
     * Set customer ID cookie if user is logged in.
     */
    public function set_customer_id_if_logged_in(): void {
        /** Do not run in admin area. */
        if ( is_admin() ) {
            return;
        }

        /** Run only for logged-in users. */
        if ( ! is_user_logged_in() ) {
            return;
        }

        /** Resolve current user ID. */
        $user_id = get_current_user_id();

        /** Stop if current user ID is invalid. */
        if ( empty( $user_id ) ) {
            return;
        }

        /** Read current customer ID from cookie. */
        $existing_customer_id = $this->get_customer_id_from_cookie();

        /** Skip update when cookie already matches current user ID. */
        if ( (string) $user_id === $existing_customer_id ) {
            return;
        }

        /** Mark cookie as secure only on HTTPS connections. */
        $secure = is_ssl();

        /** Compute cookie expiration timestamp. */
        $expire = time() + self::COOKIE_EXPIRATION;

        /** Store current logged-in user ID in cookie. */
        setcookie(
            self::CUSTOMER_ID_COOKIE_NAME,
            (string) $user_id,
            $expire,
            COOKIEPATH,
            COOKIE_DOMAIN,
            $secure,
            false
        );

        /** Mirror cookie value inside current request. */
        $_COOKIE[ self::CUSTOMER_ID_COOKIE_NAME ] = (string) $user_id;
    }

    /**
     * Handle user login.
     *
     * @param string  $user_login User login name.
     * @param WP_User $user       User object.
     */
    public function on_user_login( string $user_login, \WP_User $user ): void {
        /** Mark cookie as secure only on HTTPS connections. */
        $secure = is_ssl();

        /** Compute cookie expiration timestamp. */
        $expire = time() + self::COOKIE_EXPIRATION;

        /** Store logged-in user ID in customer ID cookie. */
        setcookie(
            self::CUSTOMER_ID_COOKIE_NAME,
            (string) $user->ID,
            $expire,
            COOKIEPATH,
            COOKIE_DOMAIN,
            $secure,
            false
        );

        /** Mirror cookie value inside current request. */
        $_COOKIE[ self::CUSTOMER_ID_COOKIE_NAME ] = (string) $user->ID;
    }

    /**
     * Handle user logout.
     */
    public function on_user_logout(): void {
        /** Expire customer ID cookie in the browser. */
        setcookie( self::CUSTOMER_ID_COOKIE_NAME, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false );

        /** Remove cookie value from current request. */
        unset( $_COOKIE[ self::CUSTOMER_ID_COOKIE_NAME ] );
    }

    /**
     * Get customer ID from cookie.
     *
     * @return string Customer ID from cookie or empty string.
     */
    public function get_customer_id_from_cookie(): string {
        /** Return sanitized customer ID when cookie exists. */
        if ( isset( $_COOKIE[ self::CUSTOMER_ID_COOKIE_NAME ] ) ) {
            return sanitize_text_field( $_COOKIE[ self::CUSTOMER_ID_COOKIE_NAME ] );
        }

        /** Return empty string when cookie is missing. */
        return '';
    }

    /**
     * Enqueue frontend scripts.
     */
    public function enqueue_scripts(): void {
        /** Do not enqueue scripts in admin area. */
        if ( is_admin() ) {
            return;
        }

        /** Enqueue guest token frontend script. */
        wp_enqueue_script(
            'aicommerce-guest-token',
            AICOMMERCE_PLUGIN_URL . 'assets/js/guest-token.js',
            array(),
            AICOMMERCE_VERSION,
            true
        );

        /** Use deferred loading when supported by WordPress. */
        if ( function_exists( 'wp_script_add_data' ) ) {
            wp_script_add_data( 'aicommerce-guest-token', 'strategy', 'defer' );
        }
    }

    /**
     * Get guest token for use in other classes.
     *
     * @return string Guest token.
     */
    public static function get_token(): string {
        /** Instantiate helper to reuse cookie accessor logic. */
        $instance = new self();

        /** Return guest token from cookie. */
        return $instance->get_token_from_cookie();
    }

    /**
     * Get customer ID for use in other classes.
     *
     * @return string Customer ID.
     */
    public static function get_customer_id(): string {
        /** Prefer current logged-in user ID when available. */
        if ( is_user_logged_in() ) {
            return (string) get_current_user_id();
        }

        /** Instantiate helper to reuse cookie accessor logic. */
        $instance = new self();

        /** Return customer ID from cookie for guest context. */
        return $instance->get_customer_id_from_cookie();
    }
}
