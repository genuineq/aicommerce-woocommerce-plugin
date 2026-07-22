<?php
/**
 * Dynamic widget runtime integration.
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the Laravel-generated widget runtime on the storefront.
 *
 * Button, styles, modal and iframe markup are owned by the dynamic runtime.
 */
class Iframe {
	private const PRODUCTION_API_BASE_URL = 'https://api.ai.genuineq.com';
	private const STAGING_API_BASE_URL    = 'https://api.ai.staging.genuineq.com';

	/** Register frontend assets. */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue the dynamic runtime after defining its visitor configuration.
	 */
	public function enqueue_scripts(): void {
		$shop_key = trim( Settings::get_api_key() );
		if ( '' === $shop_key ) {
			return;
		}

		$api_base_url = 0 === strpos( $shop_key, 'staging_' )
			? self::STAGING_API_BASE_URL
			: self::PRODUCTION_API_BASE_URL;
		$loader_url   = sprintf(
			'%s/widget/%s/loader.js',
			untrailingslashit( $api_base_url ),
			rawurlencode( $shop_key )
		);
		$guest_token = GuestToken::get_token();

		wp_enqueue_script(
			'aicommerce-runtime',
			$loader_url,
			array( 'aicommerce-guest-token' ),
			AICOMMERCE_VERSION,
			true
		);

		$config = array(
			'platform' => 'woocommerce',
		);

		if ( is_user_logged_in() ) {
			$config['customerId'] = (string) get_current_user_id();
		}

		if ( '' !== $guest_token ) {
			$config['guestToken'] = sanitize_text_field( $guest_token );
		}

		wp_add_inline_script(
			'aicommerce-runtime',
			'window.AICommerceConfig = ' . wp_json_encode( $config ) . ';' .
			'if (!window.AICommerceConfig.guestToken && typeof window.getAicommerceGuestToken === "function") {' .
			'window.AICommerceConfig.guestToken = window.getAicommerceGuestToken() || undefined;' .
			'}',
			'before'
		);
	}
}
