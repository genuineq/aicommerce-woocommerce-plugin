<?php
/**
 * Admin functionality
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Admin class. */
class Admin {

    /**
     * Constructor.
     */
    public function __construct() {
        /** Register the top-level admin menu page. */
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

        /** Register plugin settings on admin initialization. */
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        /** Enqueue admin styles and scripts for the plugin page. */
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
    }

    /**
     * Add the plugin admin menu page.
     *
     * @return void
     */
    public function add_admin_menu(): void {
        /** Register the main AICommerce admin page. */
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
     * Get a decrypted option value.
     *
     * @param string $option_name The option name to retrieve.
     * @return string
     */
    private function get_decrypted_option( string $option_name ): string {
        /** Get the stored option value. */
        $encrypted = get_option( $option_name, '' );

        /** Return an empty string if the option value is empty. */
        if ( empty( $encrypted ) ) {
            return '';
        }

        /** Decrypt and return the value if it is already encrypted. */
        if ( Encryption::is_encrypted( $encrypted ) ) {
            return Encryption::decrypt( $encrypted );
        }

        /** Encrypt and save legacy plain-text values. */
        if ( ! empty( $encrypted ) ) {
            update_option( $option_name, Encryption::encrypt( $encrypted ) );
        }

        /** Return the original value when it was not encrypted yet. */
        return $encrypted;
    }

    /**
     * Register plugin settings.
     *
     * @return void
     */
    public function register_settings(): void {
        /** Register the API key setting. */
        register_setting(
            'aicommerce_settings',
            'aicommerce_api_key',
            array(
                /** Define the expected setting type. */
                'type'              => 'string',

                /** Define the sanitization callback. */
                'sanitize_callback' => 'sanitize_text_field',

                /** Define the default setting value. */
                'default'           => '',
            )
        );

        /** Register the API secret setting. */
        register_setting(
            'aicommerce_settings',
            'aicommerce_api_secret',
            array(
                /** Define the expected setting type. */
                'type'              => 'string',

                /** Define the sanitization callback. */
                'sanitize_callback' => 'sanitize_text_field',

                /** Define the default setting value. */
                'default'           => '',
            )
        );

        /** Register iframe-related settings. */
        register_setting(
            'aicommerce_iframe_settings',
            'aicommerce_iframe_enabled',
            array(
                /** Define the expected setting type. */
                'type'              => 'boolean',

                /** Define the sanitization callback. */
                'sanitize_callback' => 'rest_sanitize_boolean',

                /** Define the default setting value. */
                'default'           => false,
            )
        );

        /** Register the iframe position setting. */
        register_setting(
            'aicommerce_iframe_settings',
            'aicommerce_iframe_position',
            array(
                /** Define the expected setting type. */
                'type'              => 'string',

                /** Define the sanitization callback. */
                'sanitize_callback' => 'sanitize_text_field',

                /** Define the default setting value. */
                'default'           => 'bottom-right',
            )
        );

        /** Register the iframe button color setting. */
        register_setting(
            'aicommerce_iframe_settings',
            'aicommerce_iframe_button_color',
            array(
                /** Define the expected setting type. */
                'type'              => 'string',

                /** Define the sanitization callback. */
                'sanitize_callback' => 'sanitize_hex_color',

                /** Define the default setting value. */
                'default'           => '#0073aa',
            )
        );

        /** Register the iframe button label setting. */
        register_setting(
            'aicommerce_iframe_settings',
            'aicommerce_iframe_button_label',
            array(
                /** Define the expected setting type. */
                'type'              => 'string',

                /** Define the sanitization callback. */
                'sanitize_callback' => 'sanitize_text_field',

                /** Define the default setting value. */
                'default'           => '',
            )
        );
    }

    /**
     * Enqueue admin styles and scripts.
     *
     * @param string $hook The current admin page hook suffix.
     * @return void
     */
    public function enqueue_admin_styles( string $hook ): void {
        /** Stop if the current page is not the plugin admin page. */
        if ( 'toplevel_page_aicommerce' !== $hook ) {
            return;
        }

        /** Use non-minified assets in debug mode and minified assets otherwise. */
        $suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

        /** Enqueue the admin stylesheet. */
        wp_enqueue_style(
            'aicommerce-admin',
            AICOMMERCE_PLUGIN_URL . 'assets/css/admin' . $suffix . '.css',
            array(),
            AICOMMERCE_VERSION
        );

        /** Enqueue the admin JavaScript file. */
        wp_enqueue_script(
            'aicommerce-admin',
            AICOMMERCE_PLUGIN_URL . 'assets/js/admin' . $suffix . '.js',
            array(),
            AICOMMERCE_VERSION,
            true
        );
    }

    /**
     * Render the main admin page.
     *
     * @return void
     */
    public function render_admin_page(): void {
        /** Block access for users without the required capability. */
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'aicommerce' ) );
        }

        /** Get the current selected tab from the request. */
        $current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'api';

        /** Handle API settings form submission. */
        if ( isset( $_POST['aicommerce_save_settings'] ) && 'api' === $current_tab ) {
            /** Verify the API settings nonce. */
            check_admin_referer( 'aicommerce_settings_nonce' );

            /** Sanitize the submitted API key. */
            $api_key = sanitize_text_field( $_POST['aicommerce_api_key'] ?? '' );

            /** Sanitize the submitted API secret. */
            $api_secret = sanitize_text_field( $_POST['aicommerce_api_secret'] ?? '' );

            /** Save the encrypted API key or delete the option if empty. */
            if ( ! empty( $api_key ) ) {
                update_option( 'aicommerce_api_key', Encryption::encrypt( $api_key ) );
            } else {
                delete_option( 'aicommerce_api_key' );
            }

            /** Save the encrypted API secret or delete the option if empty. */
            if ( ! empty( $api_secret ) ) {
                update_option( 'aicommerce_api_secret', Encryption::encrypt( $api_secret ) );
            } else {
                delete_option( 'aicommerce_api_secret' );
            }

            /** Display a success notice after saving API settings. */
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved successfully.', 'aicommerce' ) . '</p></div>';
        }

        /** Handle iframe settings form submission. */
        if ( isset( $_POST['aicommerce_save_iframe_settings'] ) && 'iframe' === $current_tab ) {
            /** Verify the iframe settings nonce. */
            check_admin_referer( 'aicommerce_iframe_settings_nonce' );

            /** Convert iframe enabled checkbox state to integer. */
            $iframe_enabled = isset( $_POST['aicommerce_iframe_enabled'] ) ? 1 : 0;

            /** Sanitize the selected iframe position. */
            $iframe_position = sanitize_text_field( $_POST['aicommerce_iframe_position'] ?? 'bottom-right' );

            /** Sanitize the selected iframe button color. */
            $iframe_button_color = sanitize_hex_color( $_POST['aicommerce_iframe_button_color'] ?? '#0073aa' );

            /** Sanitize the submitted iframe button label. */
            $iframe_button_label = sanitize_text_field( $_POST['aicommerce_iframe_button_label'] ?? '' );

            /** Save the iframe enabled setting. */
            update_option( 'aicommerce_iframe_enabled', $iframe_enabled );

            /** Save the iframe position setting. */
            update_option( 'aicommerce_iframe_position', $iframe_position );

            /** Save the iframe button color setting. */
            update_option( 'aicommerce_iframe_button_color', $iframe_button_color );

            /** Save the iframe button label setting. */
            update_option( 'aicommerce_iframe_button_label', $iframe_button_label );

            /** Display a success notice after saving iframe settings. */
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Iframe settings saved successfully.', 'aicommerce' ) . '</p></div>';
        }

        /** Load the decrypted API key for display in the form. */
        $api_key = $this->get_decrypted_option( 'aicommerce_api_key' );

        /** Load the decrypted API secret for display in the form. */
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
                        <li class="<?php echo $current_tab === 'iframe' ? 'active' : ''; ?>">
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=aicommerce&tab=iframe' ) ); ?>">
                                <span class="dashicons dashicons-external"></span>
                                <?php esc_html_e( 'Iframe Settings', 'aicommerce' ); ?>
                            </a>
                        </li>
                    </ul>
                </div>

                <div class="aicommerce-content">
                    <?php
                    /** Render the selected tab content. */
                    switch ( $current_tab ) {
                        /** Render the API settings tab. */
                        case 'api':
                            $this->render_api_tab( $api_key, $api_secret );
                            break;

                        /** Render the iframe settings tab. */
                        case 'iframe':
                            $this->render_iframe_tab();
                            break;

                        /** Fallback to the API settings tab. */
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
     * Render the API settings tab.
     *
     * @param string $api_key The decrypted API key.
     * @param string $api_secret The decrypted API secret.
     * @return void
     */
    private function render_api_tab( string $api_key, string $api_secret ): void {
        ?>
        <div class="aicommerce-tab-content">
            <h2><?php esc_html_e( 'API Configuration', 'aicommerce' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Enter your API credentials from the external AI platform to enable integration.', 'aicommerce' ); ?>
            </p>

            <form method="post" action="" class="aicommerce-form">
                <?php /** Output nonce field for API settings form security. */ wp_nonce_field( 'aicommerce_settings_nonce' ); ?>

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

    /**
     * Render the iframe settings tab.
     *
     * @return void
     */
    private function render_iframe_tab(): void {
        /** Get whether the iframe button is enabled. */
        $iframe_enabled = get_option( 'aicommerce_iframe_enabled', false );

        /** Get the saved iframe button position. */
        $iframe_position = get_option( 'aicommerce_iframe_position', 'bottom-right' );

        /** Get the saved iframe button color. */
        $iframe_button_color = get_option( 'aicommerce_iframe_button_color', '#0073aa' );

        /** Get the saved iframe button label. */
        $iframe_button_label = get_option( 'aicommerce_iframe_button_label', '' );

        /** Define the available iframe button positions. */
        $positions = array(
            'top-left'      => __( 'Top Left', 'aicommerce' ),
            'top-center'    => __( 'Top Center', 'aicommerce' ),
            'top-right'     => __( 'Top Right', 'aicommerce' ),
            'middle-left'   => __( 'Middle Left', 'aicommerce' ),
            'middle-right'  => __( 'Middle Right', 'aicommerce' ),
            'bottom-left'   => __( 'Bottom Left', 'aicommerce' ),
            'bottom-center' => __( 'Bottom Center', 'aicommerce' ),
            'bottom-right'  => __( 'Bottom Right', 'aicommerce' ),
        );

        /** Keep the positions array initialized for available UI options. */
        $positions = $positions;

        ?>
        <div class="aicommerce-tab-content">
            <h2><?php esc_html_e( 'Iframe Button Settings', 'aicommerce' ); ?></h2>

            <form method="post" action="" class="aicommerce-form">
                <?php /** Output nonce field for iframe settings form security. */ wp_nonce_field( 'aicommerce_iframe_settings_nonce' ); ?>

                <div class="aicommerce-form-fields">
                    <div class="aicommerce-form-field">
                        <label for="aicommerce_iframe_enabled" class="aicommerce-label">
                            <input
                                type="checkbox"
                                name="aicommerce_iframe_enabled"
                                id="aicommerce_iframe_enabled"
                                value="1"
                                <?php checked( $iframe_enabled, true ); ?>
                            />
                            <?php esc_html_e( 'Enable Iframe Button', 'aicommerce' ); ?>
                        </label>
                    </div>

                    <div class="aicommerce-form-field">
                        <label for="aicommerce_iframe_position" class="aicommerce-label">
                            <?php esc_html_e( 'Button Position', 'aicommerce' ); ?>
                        </label>
                        <div class="aicommerce-input-wrapper">
                            <input
                                type="hidden"
                                name="aicommerce_iframe_position"
                                id="aicommerce_iframe_position"
                                value="<?php echo esc_attr( $iframe_position ); ?>"
                            />
                            <div class="aicommerce-position-selector" id="aicommerce-position-selector">
                                <div class="aicommerce-position-grid">
                                    <div class="aicommerce-position-cell" data-position="top-left" title="<?php esc_attr_e( 'Top Left', 'aicommerce' ); ?>">
                                        <span class="aicommerce-position-indicator"></span>
                                    </div>
                                    <div class="aicommerce-position-cell" data-position="top-center" title="<?php esc_attr_e( 'Top Center', 'aicommerce' ); ?>">
                                        <span class="aicommerce-position-indicator"></span>
                                    </div>
                                    <div class="aicommerce-position-cell" data-position="top-right" title="<?php esc_attr_e( 'Top Right', 'aicommerce' ); ?>">
                                        <span class="aicommerce-position-indicator"></span>
                                    </div>
                                    <div class="aicommerce-position-cell" data-position="middle-left" title="<?php esc_attr_e( 'Middle Left', 'aicommerce' ); ?>">
                                        <span class="aicommerce-position-indicator"></span>
                                    </div>
                                    <div class="aicommerce-position-cell aicommerce-position-cell-empty"></div>
                                    <div class="aicommerce-position-cell" data-position="middle-right" title="<?php esc_attr_e( 'Middle Right', 'aicommerce' ); ?>">
                                        <span class="aicommerce-position-indicator"></span>
                                    </div>
                                    <div class="aicommerce-position-cell" data-position="bottom-left" title="<?php esc_attr_e( 'Bottom Left', 'aicommerce' ); ?>">
                                        <span class="aicommerce-position-indicator"></span>
                                    </div>
                                    <div class="aicommerce-position-cell" data-position="bottom-center" title="<?php esc_attr_e( 'Bottom Center', 'aicommerce' ); ?>">
                                        <span class="aicommerce-position-indicator"></span>
                                    </div>
                                    <div class="aicommerce-position-cell" data-position="bottom-right" title="<?php esc_attr_e( 'Bottom Right', 'aicommerce' ); ?>">
                                        <span class="aicommerce-position-indicator"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="aicommerce-form-field">
                        <label for="aicommerce_iframe_button_label" class="aicommerce-label">
                            <?php esc_html_e( 'Button Label', 'aicommerce' ); ?>
                        </label>
                        <div class="aicommerce-input-wrapper">
                            <input
                                type="text"
                                name="aicommerce_iframe_button_label"
                                id="aicommerce_iframe_button_label"
                                value="<?php echo esc_attr( $iframe_button_label ); ?>"
                                class="aicommerce-input"
                                placeholder="<?php esc_attr_e( 'e.g. Ask AI', 'aicommerce' ); ?>"
                            />
                            <p class="description"><?php esc_html_e( 'Optional text displayed to the right of the button. Leave empty to show the button only.', 'aicommerce' ); ?></p>
                        </div>
                    </div>

                    <div class="aicommerce-form-field">
                        <label for="aicommerce_iframe_button_color" class="aicommerce-label">
                            <?php esc_html_e( 'Button Background Color', 'aicommerce' ); ?>
                        </label>
                        <div class="aicommerce-input-wrapper">
                            <input
                                type="color"
                                name="aicommerce_iframe_button_color"
                                id="aicommerce_iframe_button_color"
                                value="<?php echo esc_attr( $iframe_button_color ); ?>"
                                class="aicommerce-color-input"
                            />
                            <label for="aicommerce_iframe_button_color" class="aicommerce-color-picker-label">
                                <span class="aicommerce-color-picker-circle" style="background-color: <?php echo esc_attr( $iframe_button_color ); ?>;"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="aicommerce-form-submit">
                    <button type="submit" name="aicommerce_save_iframe_settings" class="button button-primary aicommerce-button">
                        <?php esc_html_e( 'Save Settings', 'aicommerce' ); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }
}
