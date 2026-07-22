<?php
/**
 * Cart sync frontend assets.
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small frontend loader for explicit cart bridge sync.
 *
 * The actual WooCommerce <-> AICommerce transfer logic now lives in
 * {@see WooCartBridge}. This class only controls when the frontend helper
 * script should be loaded.
 */
class CartSync {
	private const USER_CART_TOKEN_COOKIE = 'aicommerce_user_cart_token';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue the lightweight frontend bridge sync script.
	 *
	 * @return void
	 */
	public function enqueue_scripts(): void {
		/** Do not enqueue assets in admin area. */
		if ( is_admin() ) {
			return;
		}

		/** Stop if WooCommerce is not available. */
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		/** Load on Woo pages and anywhere the iframe popup can be opened. */
		$is_wc_context   = function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() );
		$widget_available = '' !== trim( Settings::get_api_key() );
		$default_enqueue  = ( $is_wc_context || $widget_available );
		$should_enqueue  = apply_filters( 'aicommerce_should_enqueue_cart_sync', $default_enqueue );

		if ( ! $should_enqueue ) {
			return;
		}

		wp_enqueue_script(
			'aicommerce-cart-sync',
			AICOMMERCE_PLUGIN_URL . 'assets/js/cart-sync.js',
			array( 'aicommerce-guest-token' ),
			AICOMMERCE_VERSION,
			true
		);

		if ( function_exists( 'wp_script_add_data' ) ) {
			wp_script_add_data( 'aicommerce-cart-sync', 'strategy', 'defer' );
		}

		wp_localize_script(
			'aicommerce-cart-sync',
			'aicommerceCartSyncConfig',
			array(
				/** Auto-sync immediately where the iframe can restore a guest cart into WooCommerce. */
				'auto_sync_on_load' => (bool) ( is_cart() || is_checkout() || $widget_available ),
				'logged_in'         => is_user_logged_in(),
				'user_id'           => is_user_logged_in() ? (int) get_current_user_id() : 0,
				'cart_token'        => is_user_logged_in() ? $this->get_user_cart_token() : '',
				'nonce'             => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			)
		);
	}

	/**
	 * Return a browser-scoped cart token for logged-in user cart sync.
	 *
	 * @return string
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
	 * Validate a user cart token.
	 *
	 * @param string $token   Token.
	 * @param int    $user_id User ID.
	 * @return bool
	 */
	private function is_valid_user_cart_token( string $token, int $user_id ): bool {
		if ( '' === $token || $user_id <= 0 ) {
			return false;
		}

		return $user_id === (int) get_transient( $this->get_user_cart_token_key( $token ) );
	}

	/**
	 * Build transient key for user cart token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private function get_user_cart_token_key( string $token ): string {
		return 'aicommerce_user_cart_token_' . hash( 'sha256', $token );
	}
}
