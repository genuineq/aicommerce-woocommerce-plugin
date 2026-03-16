<?php
/**
 * Plugin Name: AICommerce
 * Description: AI-powered commerce plugin for WooCommerce
 * Version: 1.4.4
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
define( 'AICOMMERCE_VERSION', '1.4.4' );
define( 'AICOMMERCE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AICOMMERCE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AICOMMERCE_PLUGIN_FILE', __FILE__ );

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
        
        // Migrate to 1.2.0 - Add JWT authentication
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
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-encryption.php';
        
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
     * Migration to version 1.2.0 - Add JWT authentication
     */
    private function migrate_to_1_2_0() {
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-encryption.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-jwt.php';
        
        // Generate JWT Secret if not exists
        if ( ! get_option( 'aicommerce_jwt_secret' ) ) {
            \AICommerce\JWT::generate_secret();
        }
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
        
        // Load text domain for translations
        load_plugin_textdomain( 'aicommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
        
        // Load core classes
        $this->load_core_classes();
        
        // Load admin functionality
        if ( is_admin() ) {
            $this->load_admin();
        }
    }
    
    /**
     * Load core classes
     */
    private function load_core_classes() {
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-encryption.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-settings.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-jwt.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-rate-limiter.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-api-validator.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-auth-api.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-product-api.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-product-full-api.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-user-api.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-cart-storage.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-cart-api.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-cart-sync.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-iframe.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-guest-token.php';
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-updater.php';

        // Initialize APIs
        new \AICommerce\AuthAPI();
        new \AICommerce\ProductAPI();
        new \AICommerce\ProductFullAPI();
        new \AICommerce\UserAPI();
        new \AICommerce\CartAPI();

        // Initialize frontend features
        new \AICommerce\Iframe();
        new \AICommerce\GuestToken();
        new \AICommerce\CartSync();

        // Auto-updater
        new \AICommerce\Updater();
    }
    
    /**
     * Load admin classes
     */
    private function load_admin() {
        require_once AICOMMERCE_PLUGIN_DIR . 'includes/class-aicommerce-admin.php';
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
