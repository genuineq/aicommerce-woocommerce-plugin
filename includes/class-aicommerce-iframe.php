<?php
/**
 * Iframe Frontend Functionality
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Iframe Class
 *
 * Handles frontend floating button and iframe modal.
 */
class Iframe {

	/**
	 * Constructor.
	 */
	public function __construct() {
		/** Register frontend styles and scripts. */
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		/** Render floating button and iframe modal in the footer. */
		add_action( 'wp_footer', array( $this, 'render_button' ) );
	}

	/**
	 * Check whether iframe integration is enabled.
	 *
	 * @return bool True when iframe feature is enabled.
	 */
	private function is_enabled(): bool {
		/** Read iframe enabled flag from plugin settings. */
		return (bool) get_option( 'aicommerce_iframe_enabled', false );
	}

	/**
	 * Get iframe URL.
	 *
	 * Automatically builds the iframe URL using API key and either
	 * the current customer ID or the guest token.
	 *
	 * @return string Generated iframe URL or empty string.
	 */
	private function get_iframe_url(): string {
		/** Read configured API key. */
		$api_key = Settings::get_api_key();

		/**
		 * Resolve iframe base URL based on API key environment.
		 *
		 * Use the staging client URL when the API key starts with "staging_".
		 * Otherwise, use the production client URL.
		 */
		$base_url = ( ! empty( $api_key ) && ( 0 === strpos( $api_key, 'staging_' ) ) )
			? 'https://client.ai.staging.genuineq.com'
			: 'https://client.ai.genuineq.com';

		/** Read the current guest token from the cookie helper. */
		$guest_token = GuestToken::get_token();

		/** Initialize iframe query parameters. */
		$params = array();

		/** Include API key parameter when available. */
		if ( ! empty( $api_key ) ) {
			$params['s'] = $api_key;
		}

		/** Use customer ID for authenticated users. */
		if ( is_user_logged_in() ) {
			/** Authenticated users do not need a guest token. */
			$params['c'] = (string) get_current_user_id();
		} elseif ( ! empty( $guest_token ) ) {
			/** Use guest token only for guest visitors. */
			$params['g'] = $guest_token;
		}

		/** Return an empty string when no usable parameters exist. */
		if ( empty( $params ) ) {
			return '';
		}

		/** Build the final iframe URL with query string. */
		return $base_url . '?' . http_build_query( $params );
	}

	/**
	 * Get button position.
	 *
	 * @return string Button position value.
	 */
	private function get_button_position(): string {
		/** Read configured iframe button position. */
		return get_option( 'aicommerce_iframe_position', 'bottom-right' );
	}

	/**
	 * Get button color.
	 *
	 * @return string Button color value.
	 */
	private function get_button_color(): string {
		/** Read configured iframe button color. */
		return get_option( 'aicommerce_iframe_button_color', '#0073aa' );
	}

	/**
	 * Get button label.
	 *
	 * @return string Button label text.
	 */
	private function get_button_label(): string {
		/** Read configured iframe button label. */
		return get_option( 'aicommerce_iframe_button_label', '' );
	}

	/**
	 * Enqueue frontend scripts and styles.
	 */
	public function enqueue_scripts(): void {
		/** Stop when iframe feature is disabled. */
		if ( ! $this->is_enabled() ) {
			return;
		}

		/** Enqueue iframe frontend stylesheet. */
		wp_enqueue_style(
			'aicommerce-iframe',
			AICOMMERCE_PLUGIN_URL . 'assets/css/iframe.css',
			array(),
			AICOMMERCE_VERSION
		);

		/** Enqueue iframe frontend script. */
		wp_enqueue_script(
			'aicommerce-iframe',
			AICOMMERCE_PLUGIN_URL . 'assets/js/iframe.js',
			array( 'aicommerce-guest-token' ),
			AICOMMERCE_VERSION,
			true
		);

		/** Use deferred loading when supported by WordPress. */
		if ( function_exists( 'wp_script_add_data' ) ) {
			wp_script_add_data( 'aicommerce-iframe', 'strategy', 'defer' );
		}

		/** Pass runtime iframe configuration to the frontend script. */
		wp_localize_script(
			'aicommerce-iframe',
			'aicommerceIframe',
			array(
				/** Button screen position. */
				'position' => $this->get_button_position(),

				/** Button background color. */
				'color'    => $this->get_button_color(),

				/** Optional button label text. */
				'label'    => $this->get_button_label(),

				/** Fully generated iframe URL. */
				'url'      => $this->get_iframe_url(),

				/** API key exposed for frontend integration needs. */
				'api_key'  => Settings::get_api_key(),
			)
		);
	}

	/**
	 * Render floating button and iframe modal.
	 */
	public function render_button(): void {
		/** Stop when iframe feature is disabled. */
		if ( ! $this->is_enabled() ) {
			return;
		}

		/** Resolve configured button position. */
		$position = $this->get_button_position();

		/** Resolve configured button color. */
		$color = $this->get_button_color();

		/** Resolve configured button label. */
		$label = $this->get_button_label();

		/** Build iframe URL for modal container. */
		$url = $this->get_iframe_url();

		/** Escape iframe URL for safe HTML output. */
		$url = ! empty( $url ) ? esc_url( $url ) : '';
		?>
		<div id="aicommerce-iframe-wrapper" class="aicommerce-iframe-wrapper aicommerce-iframe-position-<?php echo esc_attr( $position ); ?>">
			<button
				id="aicommerce-iframe-button"
				class="aicommerce-iframe-button <?php echo ! empty( $label ) ? 'aicommerce-iframe-button--with-label' : ''; ?>"
				style="background-color: <?php echo esc_attr( $color ); ?>;"
				aria-label="<?php esc_attr_e( 'Open AI Assistant', 'aicommerce' ); ?>"
			>
				<span class="aicommerce-iframe-icon">
					<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><g id="SVGRepo_bgCarrier" stroke-width="0">
						<path d="M12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2C6.47715 2 2 6.47715 2 12C2 13.5997 2.37562 15.1116 3.04346 16.4525C3.22094 16.8088 3.28001 17.2161 3.17712 17.6006L2.58151 19.8267C2.32295 20.793 3.20701 21.677 4.17335 21.4185L6.39939 20.8229C6.78393 20.72 7.19121 20.7791 7.54753 20.9565C8.88837 21.6244 10.4003 22 12 22Z" stroke="#FFFFFF" stroke-width="1.5">
						</path>
					</svg>
				</span>

				<?php /** Render label only when a custom label is configured. */ ?>
				<?php if ( ! empty( $label ) ) : ?>
					<span class="aicommerce-iframe-label"><?php echo esc_html( $label ); ?></span>
				<?php endif; ?>
			</button>
		</div>

		<div id="aicommerce-iframe-modal" class="aicommerce-iframe-modal" style="display: none;">
			<?php /** Modal backdrop used to close or visually isolate the iframe. */ ?>
			<div class="aicommerce-iframe-modal-overlay"></div>

			<div class="aicommerce-iframe-modal-content">
				<button
					id="aicommerce-iframe-close"
					class="aicommerce-iframe-close"
					aria-label="<?php esc_attr_e( 'Close', 'aicommerce' ); ?>"
				>
					×
				</button>

				<div
					id="aicommerce-iframe-container"
					class="aicommerce-iframe-container"
					data-src="<?php echo ! empty( $url ) ? esc_url( $url ) : ''; ?>"
				></div>

				<div id="aicommerce-iframe-placeholder" class="aicommerce-iframe-placeholder" style="display: <?php echo ! empty( $url ) ? 'none' : 'block'; ?>;">
					<p><?php esc_html_e( 'Iframe URL will be configured from external platform.', 'aicommerce' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}
}
