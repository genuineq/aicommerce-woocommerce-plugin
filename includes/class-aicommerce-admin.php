<?php
/**
 * Admin functionality
 *
 * @package AICommerce
 */

namespace AICommerce;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin Class
 */
class Admin {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu(): void {
        add_menu_page(
            __( 'AICommerce', 'aicommerce' ),
            __( 'AICommerce', 'aicommerce' ),
            'manage_options',
            'aicommerce',
            array( $this, 'render_admin_page' ),
            'dashicons-networking',
            56
        );
    }
    
    /**
     * Get decrypted option value
     */
    private function get_decrypted_option( string $option_name ): string {
        $encrypted = get_option( $option_name, '' );
        
        if ( empty( $encrypted ) ) {
            return '';
        }
        
        if ( Encryption::is_encrypted( $encrypted ) ) {
            return Encryption::decrypt( $encrypted );
        }
        
        if ( ! empty( $encrypted ) ) {
            update_option( $option_name, Encryption::encrypt( $encrypted ) );
        }
        
        return $encrypted;
    }
    
    /**
     * Register settings
     */
    public function register_settings(): void {
        register_setting(
            'aicommerce_settings',
            'aicommerce_api_key',
            array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            )
        );
        
        register_setting(
            'aicommerce_settings',
            'aicommerce_api_secret',
            array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            )
        );
    }
    
    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles( string $hook ): void {
        if ( 'toplevel_page_aicommerce' !== $hook ) {
            return;
        }
        
        wp_enqueue_style(
            'aicommerce-admin',
            AICOMMERCE_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            AICOMMERCE_VERSION
        );
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'aicommerce' ) );
        }
        
        $current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'api';
        
        if ( isset( $_POST['aicommerce_save_settings'] ) ) {
            check_admin_referer( 'aicommerce_settings_nonce' );
            
            $api_key = sanitize_text_field( $_POST['aicommerce_api_key'] ?? '' );
            $api_secret = sanitize_text_field( $_POST['aicommerce_api_secret'] ?? '' );
            
            if ( ! empty( $api_key ) ) {
                update_option( 'aicommerce_api_key', Encryption::encrypt( $api_key ) );
            } else {
                delete_option( 'aicommerce_api_key' );
            }
            
            if ( ! empty( $api_secret ) ) {
                update_option( 'aicommerce_api_secret', Encryption::encrypt( $api_secret ) );
            } else {
                delete_option( 'aicommerce_api_secret' );
            }
            
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved successfully.', 'aicommerce' ) . '</p></div>';
        }
        
        $api_key    = $this->get_decrypted_option( 'aicommerce_api_key' );
        $api_secret = $this->get_decrypted_option( 'aicommerce_api_secret' );
        
        ?>
        <div class="wrap aicommerce-admin-wrap">
            <h1><?php echo esc_html__( 'AICommerce Settings', 'aicommerce' ); ?></h1>
            
            <div class="aicommerce-admin-container">
                <div class="aicommerce-sidebar">
                    <ul class="aicommerce-tabs">
                        <li class="<?php echo $current_tab === 'api' ? 'active' : ''; ?>">
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=aicommerce&tab=api' ) ); ?>">
                                <span class="dashicons dashicons-admin-network"></span>
                                <?php esc_html_e( 'API Settings', 'aicommerce' ); ?>
                            </a>
                        </li>
                    </ul>
                </div>
                
                <div class="aicommerce-content">
                    <?php
                    switch ( $current_tab ) {
                        case 'api':
                            $this->render_api_tab( $api_key, $api_secret );
                            break;
                        default:
                            $this->render_api_tab( $api_key, $api_secret );
                            break;
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render API settings tab
     */
    private function render_api_tab( string $api_key, string $api_secret ): void {
        ?>
        <div class="aicommerce-tab-content">
            <h2><?php esc_html_e( 'API Configuration', 'aicommerce' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Enter your API credentials from the external AI platform to enable integration.', 'aicommerce' ); ?>
            </p>
            
            <form method="post" action="" class="aicommerce-form">
                <?php wp_nonce_field( 'aicommerce_settings_nonce' ); ?>
                
                <div class="aicommerce-form-fields">
                    <div class="aicommerce-form-field">
                        <label for="aicommerce_api_key" class="aicommerce-label">
                            <?php esc_html_e( 'API Key', 'aicommerce' ); ?>
                            <span class="required">*</span>
                        </label>
                        <div class="aicommerce-input-wrapper">
                            <input 
                                type="text" 
                                name="aicommerce_api_key" 
                                id="aicommerce_api_key" 
                                value="<?php echo esc_attr( $api_key ); ?>" 
                                class="aicommerce-input"
                                placeholder="<?php esc_attr_e( 'Enter your API Key', 'aicommerce' ); ?>"
                            />
                        </div>
                    </div>
                    
                    <div class="aicommerce-form-field">
                        <label for="aicommerce_api_secret" class="aicommerce-label">
                            <?php esc_html_e( 'API Secret', 'aicommerce' ); ?>
                            <span class="required">*</span>
                        </label>
                        <div class="aicommerce-input-wrapper">
                            <input 
                                type="password" 
                                name="aicommerce_api_secret" 
                                id="aicommerce_api_secret" 
                                value="<?php echo esc_attr( $api_secret ); ?>" 
                                class="aicommerce-input"
                                placeholder="<?php esc_attr_e( 'Enter your API Secret', 'aicommerce' ); ?>"
                            />
                        </div>
                    </div>
                </div>
                
                <div class="aicommerce-form-submit">
                    <button type="submit" name="aicommerce_save_settings" class="button button-primary aicommerce-button">
                        <?php esc_html_e( 'Save Settings', 'aicommerce' ); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }
}
