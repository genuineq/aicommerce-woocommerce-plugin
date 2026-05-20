<?php
/**
 * Plugin Name: AICommerce
 * Description: AI-powered commerce plugin for WooCommerce
 * Version: 1.4.8
 * Author: Genuineq
 * Author URI: https://genuineq.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: aicommerce
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'AICOMMERCE_VERSION', '1.4.8' );
define( 'AICOMMERCE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AICOMMERCE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AICOMMERCE_PLUGIN_FILE', __FILE__ );
define( 'AICOMMERCE_CART_EXPIRATION_OPTION', 'aicommerce_cart_expiration_seconds' );
define( 'AICOMMERCE_CART_EXPIRATION_CHECKED_OPTION', 'aicommerce_cart_expiration_checked_at' );

/**
 * Main AICommerce Class
 */
class AICommerce {

    /**
     * Instance of this class
     */
    private static $instance = null;

    /**
     * Get the singleton instance
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Add activation hook
        register_activation_hook( __FILE__, array( $this, 'activate' ) );

        // Add deactivation hook
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        // Declare WooCommerce compatibility
        add_action( 'before_woocommerce_init', array( $this, 'declare_woocommerce_compatibility' ) );

        // Check if WooCommerce is active
        add_action( 'plugins_loaded', array( $this, 'check_woocommerce' ) );

        // Initialize plugin
        add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Check and run migrations
        $this->run_migrations();

        $this->sync_cart_expiration_from_woocommerce();

        flush_rewrite_rules();
    }

    /**
     * Run database migrations
     */
    private function run_migrations() {
        $current_version = get_option( 'aicommerce_version', '0.0.0' );

        // Migrate to 1.1.0 - Encrypt existing API keys
        if ( version_compare( $current_version, '1.1.0', '<' ) ) {
            $this->migrate_to_1_1_0();
        }

        // Migrate to 1.2.0 - Legacy iframe auth cleanup placeholder
        if ( version_compare( $current_version, '1.2.0', '<' ) ) {
            $this->migrate_to_1_2_0();
        }

        // Update version
        update_option( 'aicommerce_version', AICOMMERCE_VERSION );
    }

    /**
     * Migration to version 1.1.0 - Encrypt API credentials
     */
    private function migrate_to_1_1_0() {
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/core/security/class-aicommerce-encryption.php';

        // Migrate API Key
        $api_key = get_option( 'aicommerce_api_key', '' );
        if ( ! empty( $api_key ) && ! \AICommerce\Encryption::is_encrypted( $api_key ) ) {
            update_option( 'aicommerce_api_key', \AICommerce\Encryption::encrypt( $api_key ) );
        }

        // Migrate API Secret
        $api_secret = get_option( 'aicommerce_api_secret', '' );
        if ( ! empty( $api_secret ) && ! \AICommerce\Encryption::is_encrypted( $api_secret ) ) {
            update_option( 'aicommerce_api_secret', \AICommerce\Encryption::encrypt( $api_secret ) );
        }
    }

    /**
     * Legacy migration placeholder kept for version continuity.
     */
    private function migrate_to_1_2_0() {
        // JWT-based iframe authentication has been retired.
    }

    /**
     * Read WooCommerce's effective cart/session expiration and store it for AICommerce carts.
     */
    private function sync_cart_expiration_from_woocommerce(): void {
        $expiration = (int) apply_filters( 'wc_session_expiration', 48 * HOUR_IN_SECONDS );

        if ( $expiration <= 0 ) {
            $expiration = 48 * HOUR_IN_SECONDS;
        }

        update_option( AICOMMERCE_CART_EXPIRATION_OPTION, $expiration, false );
        update_option( AICOMMERCE_CART_EXPIRATION_CHECKED_OPTION, time(), false );
    }

    /**
     * Refresh the stored cart expiration occasionally so third-party Woo filters are respected.
     */
    private function maybe_sync_cart_expiration_from_woocommerce(): void {
        $checked_at = (int) get_option( AICOMMERCE_CART_EXPIRATION_CHECKED_OPTION, 0 );

        if ( $checked_at > 0 && ( time() - $checked_at ) < DAY_IN_SECONDS ) {
            return;
        }

        $this->sync_cart_expiration_from_woocommerce();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Deactivation code here
        flush_rewrite_rules();
    }

    /**
     * Declare compatibility with WooCommerce features
     */
    public function declare_woocommerce_compatibility() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                AICOMMERCE_PLUGIN_FILE,
                true
            );

            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'cart_checkout_blocks',
                AICOMMERCE_PLUGIN_FILE,
                true
            );
        }
    }

    /**
     * Check if WooCommerce is active
     */
    public function check_woocommerce() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return;
        }
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p>
                <?php
                echo esc_html__( 'AICommerce requires WooCommerce to be installed and active.', 'aicommerce' );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        // Check if WooCommerce is active
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        $this->maybe_sync_cart_expiration_from_woocommerce();

        // Load text domain for translations
        load_plugin_textdomain( 'aicommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

        // Load only the minimum classes needed by all contexts.
        $this->load_shared_classes();

        add_action( 'wp_login', array( $this, 'merge_guest_cart_into_user_after_login' ), 10, 2 );
        add_action( 'wp_logout', array( $this, 'preserve_user_cart_for_guest_after_logout' ), 10, 1 );

        new \AICommerce\WooCartBridge();

        // REST endpoints are needed only for REST requests.
        if ( $this->is_rest_request() ) {
            $this->load_rest_api();
        }

        // Frontend-only features.
        if ( $this->is_frontend_request() ) {
            $this->load_frontend();
        }

        // Admin UI is needed only in wp-admin.
        if ( is_admin() ) {
            $this->load_admin();
        }

        // Background/sync modules loaded only where their events are relevant.
        $this->load_webhook_modules();
    }

    /**
     * Load classes shared across multiple contexts.
     */
    private function load_shared_classes() {
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/core/security/class-aicommerce-encryption.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/core/config/class-aicommerce-settings.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/rest-api/shared/class-aicommerce-rate-limiter.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/rest-api/shared/class-aicommerce-api-validator.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/rest-api/shared/class-aicommerce-product-availability.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/domain/cart/class-aicommerce-cart-storage.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/domain/cart/class-aicommerce-cart-context-resolver.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/domain/cart/class-aicommerce-cart-projector.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/integration/woo/class-aicommerce-woo-cart-bridge.php';

        // Guest cart cleanup
        \AICommerce\CartStorage::register_cleanup();
    }

    /**
     * Merge the browser guest cart into the authenticated AICommerce user cart.
     *
     * WooCommerce and AICommerce keep separate cart identities for guest and
     * logged-in visitors. On login, combine them so a guest cart does not replace
     * a previously saved user cart.
     *
     * @param string   $user_login User login.
     * @param \WP_User $user       Authenticated user.
     */
    public function merge_guest_cart_into_user_after_login( $user_login, $user ): void {
        if ( ! $user instanceof \WP_User ) {
            return;
        }

        $user_id = (int) $user->ID;
        if ( $user_id <= 0 ) {
            return;
        }

        $this->clear_user_cart_token_cookie();

        $guest_token = '';
        if ( isset( $_COOKIE['aicommerce_guest_token'] ) ) {
            $guest_token = sanitize_text_field( wp_unslash( $_COOKIE['aicommerce_guest_token'] ) );
        }

        if ( empty( $guest_token ) || ! \AICommerce\CartContextResolver::is_valid_guest_token( $guest_token ) ) {
            return;
        }

        $this->mark_guest_token_claimed_after_login( $guest_token, $user_id );

        $guest_items = \AICommerce\CartStorage::get_cart( $guest_token );
        if ( empty( $guest_items ) ) {
            return;
        }

        $user_items           = \AICommerce\CartStorage::get_user_cart( $user_id );
        $persistent_wc_items  = $this->get_wc_persistent_cart_items( $user_id );
        $base_user_items      = $this->merge_user_cart_sources( $user_items, $persistent_wc_items );
        $merged               = $this->merge_cart_items( $base_user_items, $guest_items );
        $sanitized   = \AICommerce\CartProjector::sanitize_storage_items( $merged );

        \AICommerce\CartStorage::save_user_cart( $user_id, $sanitized['items'] );
        \AICommerce\WooCartBridge::import_storage_to_wc_cart( '', $user_id );

    }

    /**
     * Remember that this browser guest identity just authenticated as a user.
     *
     * This protects the first cached/no-nonce REST sync after login from falling
     * back to the stale guest cart identity.
     *
     * @param string $guest_token Guest token.
     * @param int    $user_id     User ID.
     * @return void
     */
    private function mark_guest_token_claimed_after_login( string $guest_token, int $user_id ): void {
        if ( empty( $guest_token ) || $user_id <= 0 || ! function_exists( 'set_transient' ) ) {
            return;
        }

        set_transient( 'aicommerce_guest_login_user_' . md5( $guest_token ), $user_id, 15 * MINUTE_IN_SECONDS );
    }

    /**
     * Read WooCommerce's saved persistent user cart as AICommerce storage items.
     *
     * @param int $user_id User ID.
     * @return array
     */
    private function get_wc_persistent_cart_items( int $user_id ): array {
        if ( $user_id <= 0 ) {
            return array();
        }

        $persistent_cart = get_user_meta( $user_id, '_woocommerce_persistent_cart_' . get_current_blog_id(), true );
        if ( ! is_array( $persistent_cart ) || empty( $persistent_cart['cart'] ) || ! is_array( $persistent_cart['cart'] ) ) {
            return array();
        }

        $items = array();
        foreach ( $persistent_cart['cart'] as $cart_item_key => $cart_item ) {
            if ( ! is_array( $cart_item ) ) {
                continue;
            }

            $product_id = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
            $quantity   = isset( $cart_item['quantity'] ) ? absint( $cart_item['quantity'] ) : 0;
            if ( $product_id <= 0 || $quantity <= 0 ) {
                continue;
            }

            $variation_data = isset( $cart_item['variation'] ) && is_array( $cart_item['variation'] )
                ? $cart_item['variation']
                : array();

            if ( ! empty( $cart_item['variation_id'] ) ) {
                $variation_data = array_merge(
                    array( 'variation_id' => absint( $cart_item['variation_id'] ) ),
                    $variation_data
                );
            }

            $items[] = array(
                'key'            => is_string( $cart_item_key ) ? $cart_item_key : '',
                'product_id'     => $product_id,
                'quantity'       => $quantity,
                'variation_data' => $variation_data,
                'added_at'       => time(),
            );
        }

        return $items;
    }

    /**
     * Merge two user-cart sources without doubling duplicated persisted lines.
     *
     * @param array $primary_items   AICommerce user cart items.
     * @param array $secondary_items WooCommerce persistent cart items.
     * @return array
     */
    private function merge_user_cart_sources( array $primary_items, array $secondary_items ): array {
        if ( empty( $primary_items ) ) {
            return array_values( $secondary_items );
        }

        if ( empty( $secondary_items ) ) {
            return array_values( $primary_items );
        }

        $merged = array_values( $primary_items );
        $index  = array();

        foreach ( $merged as $position => $item ) {
            $key = $this->get_cart_item_identity_key( $item );
            if ( '' !== $key ) {
                $index[ $key ] = $position;
            }
        }

        foreach ( $secondary_items as $item ) {
            $key      = $this->get_cart_item_identity_key( $item );
            $quantity = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;

            if ( '' === $key || $quantity <= 0 ) {
                continue;
            }

            if ( isset( $index[ $key ] ) ) {
                $existing_quantity = isset( $merged[ $index[ $key ] ]['quantity'] )
                    ? absint( $merged[ $index[ $key ] ]['quantity'] )
                    : 0;
                $merged[ $index[ $key ] ]['quantity'] = max( $existing_quantity, $quantity );
                continue;
            }

            $item['quantity'] = $quantity;
            $index[ $key ]    = count( $merged );
            $merged[]         = $item;
        }

        return array_values( $merged );
    }

    /**
     * Merge cart item arrays by product + variation identity.
     *
     * @param array $base_items  Existing destination cart items.
     * @param array $extra_items Incoming cart items to add.
     * @return array
     */
    private function merge_cart_items( array $base_items, array $extra_items ): array {
        $merged = array_values( $base_items );
        $index  = array();

        foreach ( $merged as $position => $item ) {
            $key = $this->get_cart_item_identity_key( $item );
            if ( '' !== $key ) {
                $index[ $key ] = $position;
            }
        }

        foreach ( $extra_items as $item ) {
            $key      = $this->get_cart_item_identity_key( $item );
            $quantity = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;

            if ( '' === $key || $quantity <= 0 ) {
                continue;
            }

            if ( isset( $index[ $key ] ) ) {
                $merged[ $index[ $key ] ]['quantity'] = isset( $merged[ $index[ $key ] ]['quantity'] )
                    ? max( absint( $merged[ $index[ $key ] ]['quantity'] ), $quantity )
                    : $quantity;
                continue;
            }

            $item['quantity'] = $quantity;
            $index[ $key ]    = count( $merged );
            $merged[]         = $item;
        }

        return array_values( $merged );
    }

    /**
     * Build a stable cart line identity for merge operations.
     *
     * @param array $item Cart item.
     * @return string
     */
    private function get_cart_item_identity_key( array $item ): string {
        $product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
        if ( $product_id <= 0 ) {
            return '';
        }

        $variation_data = isset( $item['variation_data'] ) && is_array( $item['variation_data'] )
            ? $item['variation_data']
            : array();

        $variation_id = isset( $variation_data['variation_id'] ) ? absint( $variation_data['variation_id'] ) : 0;
        if ( isset( $variation_data['variation_id'] ) ) {
            $variation_data['variation_id'] = $variation_id;
        }

        ksort( $variation_data );

        return md5( $product_id . ':' . $variation_id . ':' . wp_json_encode( $variation_data ) );
    }

    /**
     * Preserve the logged-in AICommerce cart under the browser guest identity on logout.
     *
     * WooCommerce switches back to a guest browser session after logout. Keeping
     * the latest user cart under the existing guest token prevents the next guest
     * sync from projecting an older/empty guest cart over the visible cart.
     *
     * @param int $user_id User ID that is being logged out.
     */
    public function preserve_user_cart_for_guest_after_logout( $user_id ): void {
        $user_id = absint( $user_id );
        if ( $user_id <= 0 ) {
            return;
        }

        $guest_token = '';
        if ( isset( $_COOKIE['aicommerce_guest_token'] ) ) {
            $guest_token = sanitize_text_field( wp_unslash( $_COOKIE['aicommerce_guest_token'] ) );
        }

        if ( empty( $guest_token ) || ! \AICommerce\CartContextResolver::is_valid_guest_token( $guest_token ) ) {
            return;
        }

        if ( function_exists( 'delete_transient' ) ) {
            delete_transient( 'aicommerce_guest_login_user_' . md5( $guest_token ) );
        }

        $items = \AICommerce\CartStorage::get_user_cart( $user_id );
        if ( empty( $items ) ) {
            return;
        }

        \AICommerce\CartStorage::save_cart( $guest_token, $items );
    }

    /**
     * Clear the browser-scoped user cart token on logout.
     *
     * @return void
     */
    private function clear_user_cart_token_cookie(): void {
        $cookie_name = 'aicommerce_user_cart_token';
        $token       = isset( $_COOKIE[ $cookie_name ] )
            ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) )
            : '';

        if ( '' !== $token && function_exists( 'delete_transient' ) ) {
            delete_transient( 'aicommerce_user_cart_token_' . hash( 'sha256', $token ) );
        }

        $options = array(
            'expires'  => time() - HOUR_IN_SECONDS,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        );

        if ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) {
            $options['domain'] = COOKIE_DOMAIN;
        }

        setcookie( $cookie_name, '', $options );
        unset( $_COOKIE[ $cookie_name ] );
    }

    /**
     * Load REST API classes only for REST requests.
     */
    private function load_rest_api() {
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/rest-api/iframe/class-aicommerce-product-api.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/rest-api/iframe/class-aicommerce-product-full-api.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/rest-api/iframe/class-aicommerce-user-api.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/rest-api/iframe/class-aicommerce-cart-api.php';

        new \AICommerce\ProductAPI();
        new \AICommerce\ProductFullAPI();
        new \AICommerce\UserAPI();
        new \AICommerce\CartAPI();
    }

    /**
     * Load frontend-only classes.
     */
    private function load_frontend() {
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/rest-api/iframe/class-aicommerce-cart-api.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/integration/woo/class-aicommerce-cart-sync.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/integration/iframe/class-aicommerce-iframe.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/integration/iframe/class-aicommerce-guest-token.php';

        new \AICommerce\Iframe();
        new \AICommerce\GuestToken();
        new \AICommerce\CartSync();
    }

    /**
     * Load webhook and updater classes only in relevant contexts.
     */
    private function load_webhook_modules() {
        $is_cron = defined( 'DOING_CRON' ) && DOING_CRON;
        $is_cli  = defined( 'WP_CLI' ) && WP_CLI;
        $needs_webhook_support = $this->is_frontend_request() || is_admin() || $this->is_rest_request() || $is_cron || $is_cli;

        if ( $needs_webhook_support ) {
            require_once AICOMMERCE_PLUGIN_DIR . 'includes/webhooks/class-aicommerce-webhook-delivery-log.php';
        }

        // Updater is relevant in wp-admin and scheduled update checks.
        if ( is_admin() || $is_cron ) {
            require_once AICOMMERCE_PLUGIN_DIR . 'includes/infrastructure/class-aicommerce-updater.php';
            new \AICommerce\Updater();
        }

        // Product webhooks are relevant anywhere WooCommerce can mutate products or stock.
        if ( $this->is_frontend_request() || is_admin() || $this->is_rest_request() || $is_cron || $is_cli ) {
            require_once AICOMMERCE_PLUGIN_DIR . 'includes/webhooks/class-aicommerce-product-webhook.php';
            new \AICommerce\ProductWebhook();
        }

        // Order webhooks can fire on checkout, admin status updates, and background tasks.
        if ( $this->is_frontend_request() || is_admin() || $this->is_rest_request() || $is_cron || $is_cli ) {
            require_once AICOMMERCE_PLUGIN_DIR . 'includes/webhooks/class-aicommerce-order-webhook.php';
            new \AICommerce\OrderWebhook();
        }
    }

    /**
     * Determine whether the current request is a REST request.
     */
    private function is_rest_request(): bool {
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return true;
        }

        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $rest_prefix = trailingslashit( rest_get_url_prefix() );
            $request_uri = wp_unslash( $_SERVER['REQUEST_URI'] );
            return false !== strpos( $request_uri, $rest_prefix );
        }

        return false;
    }

    /**
     * Determine whether the current request is a frontend page request.
     */
    private function is_frontend_request(): bool {
        if ( is_admin() ) {
            return false;
        }

        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return false;
        }

        if ( $this->is_rest_request() ) {
            return false;
        }

        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return false;
        }

        return true;
    }

    /**
     * Load admin classes
     */
    private function load_admin() {
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/admin/class-aicommerce-admin.php';
        new \AICommerce\Admin();
    }
}

/**
 * Initialize the plugin
 */
function aicommerce_init() {
    return AICommerce::get_instance();
}

// Start the plugin
aicommerce_init();
