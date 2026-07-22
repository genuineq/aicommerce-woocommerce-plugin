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

    /** Browser-scoped cart token cookie name for logged-in users. */
    private const USER_CART_TOKEN_COOKIE = 'aicommerce_user_cart_token';

    /** Cookie lifetime duration. */
    private const COOKIE_EXPIRATION = YEAR_IN_SECONDS;

    /**
     * Constructor.
     */
    public function __construct() {
        /** Register frontend guest token script. */
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        /** Ensure guest token cookie exists for guest visitors. */
        if ( did_action( 'init' ) ) {
            $this->set_cookie_if_needed();
        } else {
            add_action( 'init', array( $this, 'set_cookie_if_needed' ) );
        }
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
     * Persist the guest token cookie using one broad storefront scope.
     *
     * @param string $token Guest token.
     * @return void
     */
    private function set_guest_cookie( string $token ): void {
        /** Stop if the token format is not trusted. */
        if ( ! $this->is_valid_token_format( $token ) ) {
            return;
        }

        /** Mark cookie as secure only on HTTPS connections. */
        $secure = is_ssl();

        /** Compute cookie expiration timestamp. */
        $expire = time() + self::COOKIE_EXPIRATION;

        /** Keep the cookie visible across cart/product/account paths. */
        $options = array(
            'expires'  => $expire,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => false,
            'samesite' => 'Lax',
        );

        if ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) {
            $options['domain'] = COOKIE_DOMAIN;
        }

        setcookie( self::COOKIE_NAME, $token, $options );

        /** Mirror cookie value inside current request. */
        $_COOKIE[ self::COOKIE_NAME ] = $token;

        $this->persist_token_to_wc_session( $token );
    }

    /**
     * Store the guest token in WooCommerce's browser session as a recovery path.
     *
     * @param string $token Guest token.
     * @return void
     */
    private function persist_token_to_wc_session( string $token ): void {
        if ( ! $this->is_valid_token_format( $token ) ) {
            return;
        }

        if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
            return;
        }

        WC()->session->set( self::COOKIE_NAME, $token );
    }

    /**
     * Read a previously associated guest token from WooCommerce's session.
     *
     * @return string Guest token or empty string.
     */
    private function get_token_from_wc_session(): string {
        if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
            return '';
        }

        $token = WC()->session->get( self::COOKIE_NAME, '' );
        $token = is_string( $token ) ? sanitize_text_field( $token ) : '';

        return $this->is_valid_token_format( $token ) ? $token : '';
    }

    /**
     * Return a browser-scoped cart token for logged-in tracking identity.
     *
     * @return string User cart token or empty string.
     */
    private function get_user_cart_token(): string {
        if ( ! is_user_logged_in() ) {
            return '';
        }

        $user_id = (int) get_current_user_id();
        $token   = isset( $_COOKIE[ self::USER_CART_TOKEN_COOKIE ] )
            ? sanitize_text_field( wp_unslash( $_COOKIE[ self::USER_CART_TOKEN_COOKIE ] ) )
            : '';

        if ( $this->is_valid_user_cart_token( $token, $user_id ) ) {
            return $token;
        }

        $token = wp_generate_password( 48, false, false );
        set_transient( $this->get_user_cart_token_key( $token ), $user_id, DAY_IN_SECONDS );

        $options = array(
            'expires'  => time() + DAY_IN_SECONDS,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        );

        if ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) {
            $options['domain'] = COOKIE_DOMAIN;
        }

        setcookie( self::USER_CART_TOKEN_COOKIE, $token, $options );
        $_COOKIE[ self::USER_CART_TOKEN_COOKIE ] = $token;

        return $token;
    }

    /**
     * Validate a logged-in user cart token.
     *
     * @param string $token   Token.
     * @param int    $user_id User ID.
     * @return bool True when token belongs to the user.
     */
    private function is_valid_user_cart_token( string $token, int $user_id ): bool {
        if ( '' === $token || $user_id <= 0 ) {
            return false;
        }

        return $user_id === (int) get_transient( $this->get_user_cart_token_key( $token ) );
    }

    /**
     * Build transient key for logged-in user cart token.
     *
     * @param string $token Token.
     * @return string Transient key.
     */
    private function get_user_cart_token_key( string $token ): string {
        return 'aicommerce_user_cart_token_' . hash( 'sha256', $token );
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
                /** Persist guest token into browser cookie. */
                $this->set_guest_cookie( $url_token );

                return;
            }
        }

        /** Load existing guest token from cookie. */
        $existing_token = $this->get_token_from_cookie();

        /** Stop if guest token already exists. */
        if ( $this->is_valid_token_format( $existing_token ) ) {
            $this->persist_token_to_wc_session( $existing_token );
            return;
        }

        /** Recover from WooCommerce session if the storefront cookie was missing. */
        $session_token = $this->get_token_from_wc_session();
        if ( ! empty( $session_token ) ) {
            $this->set_guest_cookie( $session_token );
            return;
        }

        /** Generate a new guest token for the current guest visitor. */
        $token = $this->generate_token();

        /** Store generated token in cookie. */
        $this->set_guest_cookie( $token );
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

        /** Enqueue short-lived visit tracking token script. */
        wp_enqueue_script(
            'aicommerce-tracking-token',
            AICOMMERCE_PLUGIN_URL . 'assets/js/tracking-token.js',
            array( 'aicommerce-guest-token' ),
            (string) filemtime( AICOMMERCE_PLUGIN_DIR . 'assets/js/tracking-token.js' ),
            true
        );

        wp_localize_script(
            'aicommerce-guest-token',
            'aicommerceGuestTokenConfig',
            array(
                'token' => $this->get_token_from_cookie(),
            )
        );

        wp_localize_script(
            'aicommerce-tracking-token',
            'aicommerceTrackingTokenConfig',
            array(
                'logged_in'  => is_user_logged_in(),
                'user_id'    => is_user_logged_in() ? (int) get_current_user_id() : 0,
                'cart_token' => is_user_logged_in() ? $this->get_user_cart_token() : '',
                'nonce'      => wp_create_nonce( 'aicommerce_tracking' ),
                'endpoints' => array(
                    'new_session' => esc_url_raw( rest_url( 'aicommerce/v1/tracking/new-session' ) ),
                    'chat_opened' => esc_url_raw( rest_url( 'aicommerce/v1/tracking/chat-opened' ) ),
                ),
            )
        );

        /** Tracking can be deferred; the guest helper must run before the widget runtime. */
        if ( function_exists( 'wp_script_add_data' ) ) {
            wp_script_add_data( 'aicommerce-tracking-token', 'strategy', 'defer' );
        }
    }

    /**
     * Get guest token for use in other classes.
     *
     * @return string Guest token.
     */
    public static function get_token(): string {
        $token = isset( $_COOKIE[ self::COOKIE_NAME ] )
            ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) )
            : '';

        return preg_match( '/^guest_\d+_[a-zA-Z0-9]+_[a-f0-9]{8}$/', $token ) ? $token : '';
    }
}
