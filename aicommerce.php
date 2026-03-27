<?php
/**
 * Plugin Name: AICommerce
 * Description: AI-powered commerce plugin for WooCommerce
 * Version: 1.5.2
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

/** Prevent direct access to the file for security reasons. If ABSPATH is not defined, WordPress is not loaded. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Define plugin version constant. */
define( 'AICOMMERCE_VERSION', '1.5.2' );

/** Define plugin directory path (absolute server path). */
define( 'AICOMMERCE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/** Define plugin URL (used for assets like scripts and styles). */
define( 'AICOMMERCE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/** Define plugin main file path. */
define( 'AICOMMERCE_PLUGIN_FILE', __FILE__ );

/** Main AICommerce class. Implements singleton pattern to ensure a single instance. */
class AICommerce {

    /** @var AICommerce|null Holds the single instance of the class. */
    private static $instance = null;

    /**
     * Retrieve the singleton instance of the class.
     *
     * @return AICommerce
     */
    public static function get_instance() {
        /** Create the instance if it does not already exist. */
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        /** Return the singleton instance. */
        return self::$instance;
    }

    /** Private constructor to prevent direct instantiation and initialize plugin hooks. */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Register all WordPress hooks used by the plugin.
     *
     * @return void
     */
    private function init_hooks() {
        /** Register plugin activation hook. */
        register_activation_hook( __FILE__, array( $this, 'activate' ) );

        /** Register plugin deactivation hook. */
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        /** Declare WooCommerce compatibility before WooCommerce initialization. */
        add_action( 'before_woocommerce_init', array( $this, 'declare_woocommerce_compatibility' ) );

        /** Check whether WooCommerce is active after all plugins are loaded. */
        add_action( 'plugins_loaded', array( $this, 'check_woocommerce' ) );

        /** Initialize the plugin after WooCommerce check has run. */
        add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
    }

    /**
     * Run plugin activation logic.
     *
     * @return void
     */
    public function activate() {
        /** Run database and option migrations if needed. */
        $this->run_migrations();

        /** Flush rewrite rules to refresh permalink structures. */
        flush_rewrite_rules();
    }

    /**
     * Run plugin migrations based on the currently stored version.
     *
     * @return void
     */
    private function run_migrations() {
        /** Get the currently stored plugin version, defaulting to 0.0.0 if not set. */
        $current_version = get_option( 'aicommerce_version', '0.0.0' );

        /** Run migration to version 1.1.0 for API credential encryption. */
        if ( version_compare( $current_version, '1.1.0', '<' ) ) {
            $this->migrate_to_1_1_0();
        }

        /** Run migration to version 1.2.0 for JWT authentication support. */
        if ( version_compare( $current_version, '1.2.0', '<' ) ) {
            $this->migrate_to_1_2_0();
        }

        /** Update the stored plugin version after migrations complete. */
        update_option( 'aicommerce_version', AICOMMERCE_VERSION );
    }

    /**
     * Migrate plugin data to version 1.1.0 by encrypting stored API credentials.
     *
     * @return void
     */
    private function migrate_to_1_1_0() {
        /** Load the encryption class required for credential migration. */
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-encryption.php';

        /** Get the stored API key. */
        $api_key = get_option( 'aicommerce_api_key', '' );

        /** Encrypt and save the API key if it exists and is not already encrypted. */
        if ( ! empty( $api_key ) && ! \AICommerce\Encryption::is_encrypted( $api_key ) ) {
            update_option( 'aicommerce_api_key', \AICommerce\Encryption::encrypt( $api_key ) );
        }

        /** Get the stored API secret. */
        $api_secret = get_option( 'aicommerce_api_secret', '' );

        /** Encrypt and save the API secret if it exists and is not already encrypted. */
        if ( ! empty( $api_secret ) && ! \AICommerce\Encryption::is_encrypted( $api_secret ) ) {
            update_option( 'aicommerce_api_secret', \AICommerce\Encryption::encrypt( $api_secret ) );
        }
    }

    /**
     * Migrate plugin data to version 1.2.0 by adding JWT authentication support.
     *
     * @return void
     */
    private function migrate_to_1_2_0() {
        /** Load the encryption class. */
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-encryption.php';

        /** Load the JWT class. */
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-jwt.php';

        /** Generate a JWT secret if one does not already exist. */
        if ( ! get_option( 'aicommerce_jwt_secret' ) ) {
            \AICommerce\JWT::generate_secret();
        }
    }

    /**
     * Run plugin deactivation logic.
     *
     * @return void
     */
    public function deactivate() {
        /** Flush rewrite rules on deactivation to clean up permalink state. */
        flush_rewrite_rules();
    }

    /**
     * Declare compatibility with supported WooCommerce features.
     *
     * @return void
     */
    public function declare_woocommerce_compatibility() {
        /** Check whether WooCommerce FeaturesUtil is available before declaring compatibility. */
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            /** Declare compatibility with WooCommerce custom order tables. */
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                AICOMMERCE_PLUGIN_FILE,
                true
            );

            /** Declare compatibility with WooCommerce cart and checkout blocks. */
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'cart_checkout_blocks',
                AICOMMERCE_PLUGIN_FILE,
                true
            );
        }
    }

    /**
     * Check whether WooCommerce is active.
     *
     * @return void
     */
    public function check_woocommerce() {
        /** Show an admin notice if WooCommerce is not loaded. */
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return;
        }
    }

    /**
     * Display an admin notice when WooCommerce is missing.
     *
     * @return void
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p>
                <?php
                /** Output a translated message informing the administrator that WooCommerce is required. */
                echo esc_html__( 'AICommerce requires WooCommerce to be installed and active.', 'aicommerce' );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Initialize the plugin.
     *
     * @return void
     */
    public function init() {
        /** Stop initialization if WooCommerce is not active. */
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        /** Load plugin text domain for translations. */
        load_plugin_textdomain( 'aicommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

        /** Load classes shared across multiple execution contexts. */
        $this->load_shared_classes();

        /** Load REST API classes only for REST requests. */
        if ( $this->is_rest_request() ) {
            $this->load_rest_api();
        }

        /** Load frontend-only classes for frontend requests. */
        if ( $this->is_frontend_request() ) {
            $this->load_frontend();
        }

        /** Load admin classes only in the WordPress admin area. */
        if ( is_admin() ) {
            $this->load_admin();
        }

        /** Load webhook and updater modules in relevant contexts. */
        $this->load_webhook_modules();
    }

    /** Load classes shared across multiple contexts. */
    private function load_shared_classes() {
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-encryption.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-settings.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-jwt.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-rate-limiter.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-api-validator.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-cart-storage.php';

        /** Register guest cart cleanup routine. */
        \AICommerce\CartStorage::register_cleanup();
    }

    /** Load REST API classes only for REST requests. */
    private function load_rest_api() {
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-auth-api.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-product-api.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-product-full-api.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-user-api.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-cart-api.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-sse.php';

        /** Initialize authentication API endpoints. */
        new \AICommerce\AuthAPI();

        /** Initialize product API endpoints. */
        new \AICommerce\ProductAPI();

        /** Initialize full product API endpoints. */
        new \AICommerce\ProductFullAPI();

        /** Initialize user API endpoints. */
        new \AICommerce\UserAPI();

        /** Initialize cart API endpoints. */
        new \AICommerce\CartAPI();

        /** Initialize server-sent events support. */
        new \AICommerce\SSE();
    }

    /** Load frontend-only classes. */
    private function load_frontend() {
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-cart-api.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-cart-sync.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-iframe.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-guest-token.php';

        /** Initialize iframe integration. */
        new \AICommerce\Iframe();

        /** Initialize guest token handling. */
        new \AICommerce\GuestToken();

        /** Initialize cart synchronization. */
        new \AICommerce\CartSync();
    }

    /** Load webhook and updater classes only in relevant execution contexts. */
    private function load_webhook_modules() {
        /** Determine whether the current execution context is WP-Cron. */
        $is_cron = defined( 'DOING_CRON' ) && DOING_CRON;

        /** Determine whether the current execution context is WP-CLI. */
        $is_cli  = defined( 'WP_CLI' ) && WP_CLI;

        /** Load updater in admin and cron contexts where update checks are relevant. */
        if ( is_admin() || $is_cron ) {
            require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-updater.php';
            new \AICommerce\Updater();
        }

        /** Load product webhook handling in admin, REST, cron, and CLI contexts. */
        if ( is_admin() || $this->is_rest_request() || $is_cron || $is_cli ) {
            require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-product-webhook.php';
            new \AICommerce\ProductWebhook();
        }

        /** Load order webhook handling in frontend, admin, cron, and CLI contexts. */
        if ( $this->is_frontend_request() || is_admin() || $is_cron || $is_cli ) {
            require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-order-webhook.php';
            new \AICommerce\OrderWebhook();
        }
    }

    /**
     * Determine whether the current request is a REST request.
     *
     * @return bool
     */
    private function is_rest_request(): bool {
        /** Return true immediately if the REST_REQUEST constant is defined and true. */
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return true;
        }

        /** Check the request URI for the REST API prefix when available. */
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            /** Get the REST API URL prefix with a trailing slash. */
            $rest_prefix = trailingslashit( rest_get_url_prefix() );

            /** Get and unslash the current request URI. */
            $request_uri = wp_unslash( $_SERVER['REQUEST_URI'] );

            /** Return whether the request URI contains the REST prefix. */
            return false !== strpos( $request_uri, $rest_prefix );
        }

        /** Return false when the request does not appear to be a REST request. */
        return false;
    }

    /**
     * Determine whether the current request is a frontend page request.
     *
     * @return bool
     */
    private function is_frontend_request(): bool {
        /** Exclude WordPress admin requests. */
        if ( is_admin() ) {
            return false;
        }

        /** Exclude AJAX requests. */
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return false;
        }

        /** Exclude REST API requests. */
        if ( $this->is_rest_request() ) {
            return false;
        }

        /** Exclude cron requests. */
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return false;
        }

        /** Treat the request as a frontend request if none of the exclusions matched. */
        return true;
    }

    /** Load admin classes. */
    private function load_admin() {
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-admin.php';
        new \AICommerce\Admin();
    }
}

/**
 * Initialize the plugin and return its singleton instance.
 *
 * @return AICommerce
 */
function aicommerce_init() {
    return AICommerce::get_instance();
}

/** Start the plugin. */
aicommerce_init();
