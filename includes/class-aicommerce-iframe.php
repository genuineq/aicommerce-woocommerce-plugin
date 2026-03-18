<?php
/**
 * Iframe Frontend Functionality
 *
 * @package AICommerce
 */

namespace AICommerce;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Iframe Class
 * Handles frontend floating button and iframe modal
 */
class Iframe {

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_footer', array( $this, 'render_button' ) );
    }

    /**
     * Check if iframe is enabled
     */
    private function is_enabled(): bool {
        return (bool) get_option( 'aicommerce_iframe_enabled', false );
    }

    /**
     * Get iframe URL
     * Auto-generates URL with API key, guest token, and customer ID
     */
    private function get_iframe_url(): string {
        $api_key = Settings::get_api_key();

        /** Set iframe base url for staging / production. */
        $base_url = ( ! empty( $api_key ) && (0 === strpos( $api_key, 'staging_' )) ) ? 'https://client.ai.staging.genuineq.com' : 'https://client.ai.genuineq.com';

        $guest_token = GuestToken::get_token();

        $params = array();

        if ( ! empty( $api_key ) ) {
            $params['s'] = $api_key;
        }

        if ( is_user_logged_in() ) {
            // Authenticated user: pass customer ID, no guest token needed
            $params['c'] = (string) get_current_user_id();
        } elseif ( ! empty( $guest_token ) ) {
            // Guest: pass guest token only
            $params['g'] = $guest_token;
        }

        if ( empty( $params ) ) {
            return '';
        }

        return $base_url . '?' . http_build_query( $params );
    }

    /**
     * Get button position
     */
    private function get_button_position(): string {
        return get_option( 'aicommerce_iframe_position', 'bottom-right' );
    }

    /**
     * Get button color
     */
    private function get_button_color(): string {
        return get_option( 'aicommerce_iframe_button_color', '#0073aa' );
    }

    /**
     * Get button label
     */
    private function get_button_label(): string {
        return get_option( 'aicommerce_iframe_button_label', '' );
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_scripts(): void {
        if ( ! $this->is_enabled() ) {
            return;
        }

        wp_enqueue_style(
            'aicommerce-iframe',
            AICOMMERCE_PLUGIN_URL . 'assets/css/iframe.css',
            array(),
            AICOMMERCE_VERSION
        );

        wp_enqueue_script(
            'aicommerce-iframe',
            AICOMMERCE_PLUGIN_URL . 'assets/js/iframe.js',
            array( 'aicommerce-guest-token' ),
            AICOMMERCE_VERSION,
            true
        );

        wp_localize_script(
            'aicommerce-iframe',
            'aicommerceIframe',
            array(
                'position' => $this->get_button_position(),
                'color'    => $this->get_button_color(),
                'label'    => $this->get_button_label(),
                'url'      => $this->get_iframe_url(),
                'api_key'  => Settings::get_api_key(),
            )
        );
    }

    /**
     * Render floating button and modal
     */
    public function render_button(): void {
        if ( ! $this->is_enabled() ) {
            return;
        }

        $position = $this->get_button_position();
        $color = $this->get_button_color();
        $label = $this->get_button_label();
        $url = $this->get_iframe_url();

        $url = ! empty( $url ) ? esc_url( $url ) : '';
        ?>
        <div id="aicommerce-iframe-wrapper" class="aicommerce-iframe-wrapper aicommerce-iframe-position-<?php echo esc_attr( $position ); ?>">
            <button
                id="aicommerce-iframe-button"
                class="aicommerce-iframe-button"
                style="background-color: <?php echo esc_attr( $color ); ?>;"
                aria-label="<?php esc_attr_e( 'Open AI Assistant', 'aicommerce' ); ?>"
            >
                <span class="aicommerce-iframe-icon">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><g id="SVGRepo_bgCarrier" stroke-width="0">
                        <path d="M12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2C6.47715 2 2 6.47715 2 12C2 13.5997 2.37562 15.1116 3.04346 16.4525C3.22094 16.8088 3.28001 17.2161 3.17712 17.6006L2.58151 19.8267C2.32295 20.793 3.20701 21.677 4.17335 21.4185L6.39939 20.8229C6.78393 20.72 7.19121 20.7791 7.54753 20.9565C8.88837 21.6244 10.4003 22 12 22Z" stroke="#FFFFFF" stroke-width="1.5">
                        </path>
                    </svg>
                </span>
            </button>
            <?php if ( ! empty( $label ) ) : ?>
                <span
                    id="aicommerce-iframe-label"
                    class="aicommerce-iframe-label"
                    style="background-color: <?php echo esc_attr( $color ); ?>;"
                    role="button"
                    tabindex="0"
                    aria-label="<?php esc_attr_e( 'Open AI Assistant', 'aicommerce' ); ?>"
                ><?php echo esc_html( $label ); ?></span>
            <?php endif; ?>
        </div>

        <div id="aicommerce-iframe-modal" class="aicommerce-iframe-modal" style="display: none;">
            <div class="aicommerce-iframe-modal-overlay"></div>
            <div class="aicommerce-iframe-modal-content">
                <button
                    id="aicommerce-iframe-close"
                    class="aicommerce-iframe-close"
                    aria-label="<?php esc_attr_e( 'Close', 'aicommerce' ); ?>"
                >
                    ×
                </button>
                <?php if ( ! empty( $url ) ) : ?>
                    <iframe
                        id="aicommerce-iframe"
                        src="<?php echo esc_url( $url ); ?>"
                        frameborder="0"
                        allowfullscreen
                    ></iframe>
                <?php else : ?>
                    <div class="aicommerce-iframe-placeholder">
                        <p><?php esc_html_e( 'Iframe URL will be configured from external platform.', 'aicommerce' ); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
