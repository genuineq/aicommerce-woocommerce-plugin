<?php
/**
 * Cart Sync Frontend
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cart Sync Class
 *
 * Handles frontend cart synchronization.
 */
class CartSync {
    /**
     * Guards against persisting Woo cart changes while we intentionally rebuild it.
     *
     * @var bool
     */
    private static bool $is_rebuilding_wc_cart = false;

    /**
     * Constructor.
     */
    public function __construct() {
        /** Register frontend assets. */
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        /** Sync logged-in user cart after WooCommerce restores cart session. */
        add_action( 'woocommerce_load_cart_from_session', array( $this, 'sync_user_cart_after_wc_load' ), 20 );

        /** Sync guest cart from persistent storage into WooCommerce session. */
        add_action( 'woocommerce_load_cart_from_session', array( $this, 'sync_guest_cart_on_page_load' ), 25 );

        /** Keep user_meta updated when WooCommerce cart changes. */
        add_action( 'woocommerce_add_to_cart', array( $this, 'sync_wc_cart_to_user_meta' ), 20, 6 );
        add_action( 'woocommerce_cart_item_set_quantity', array( $this, 'sync_wc_cart_to_user_meta_on_quantity_change' ), 20, 2 );
        add_action( 'woocommerce_cart_item_removed', array( $this, 'sync_wc_cart_to_user_meta_on_remove' ), 20, 2 );
        add_action( 'woocommerce_cart_item_restored', array( $this, 'sync_wc_cart_to_user_meta' ), 20, 1 );
    }

    /**
     * Sync guest cart from cookie to WooCommerce session on every frontend page load.
     *
     * Reads the guest token from the aicommerce_guest_token cookie, loads the cart
     * stored in wp_options, and merges any missing items into the active WooCommerce
     * session before the page is rendered.
     *
     * This allows the cart to appear immediately without a client-side AJAX request.
     */
    public function sync_guest_cart_on_page_load(): void {
        /** Do not run in admin area. */
        if ( is_admin() ) {
            return;
        }

        /** Do not run during REST requests. */
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return;
        }

        /** Do not run during AJAX requests. */
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return;
        }

        /** Do not run during cron jobs. */
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return;
        }

        /** This sync is only for guests. */
        if ( is_user_logged_in() ) {
            return;
        }

        /** Stop if the guest token cookie does not exist. */
        if ( ! isset( $_COOKIE['aicommerce_guest_token'] ) ) {
            return;
        }

        /** Read and sanitize the guest token from the cookie. */
        $guest_token = sanitize_text_field( wp_unslash( $_COOKIE['aicommerce_guest_token'] ) );

        /** Stop if the token is empty after sanitization. */
        if ( empty( $guest_token ) ) {
            return;
        }

        /** Validate guest token format before using it. */
        if ( ! preg_match( '/^guest_\d+_[a-zA-Z0-9]+_[a-f0-9]{8}$/', $guest_token ) ) {
            return;
        }

        /**
         * Prevent concurrent guest cart sync during the same timeframe.
         *
         * This avoids a race where two requests both see an item missing in WC
         * and both call add_to_cart(), doubling quantities.
         */
        $lockTtlSeconds = 6;
        $lockKey = 'aicommerce_guest_cart_page_sync_lock_' . md5( (string) $guest_token );
        if ( function_exists( 'get_transient' ) && function_exists( 'set_transient' ) ) {
            if ( (bool) get_transient( $lockKey ) ) {
                return;
            }
            set_transient( $lockKey, 1, $lockTtlSeconds );
        }

        /**
         * Short-circuit heavy merge when nothing changed.
         * We store the last synced guest cart version in WC session.
         */
        if ( function_exists( 'WC' ) && WC() && WC()->session ) {
            $meta              = CartStorage::get_cart_meta( $guest_token );
            $stored_version    = (int) ( $meta['version'] ?? 0 );
            $session_key       = 'aicommerce_guest_cart_version_' . md5( $guest_token );
            $last_synced       = (int) WC()->session->get( $session_key, 0 );

            if ( $stored_version > 0 && $last_synced === $stored_version ) {
                return;
            }
        }

        /** Prevent duplicate sync during the same request. */
        static $synced = false;
        if ( $synced ) {
            return;
        }
        $synced = true;

        /** Load stored guest cart from persistent storage. */
        $stored_cart = CartStorage::get_cart( $guest_token );

        /** Ensure WooCommerce is available before accessing the cart. */
        if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
            return;
        }

        /** Get the current WooCommerce cart instance. */
        $wc_cart = WC()->cart;

        /** Stop if cart instance is missing or invalid. */
        if ( ! $wc_cart || ! is_a( $wc_cart, 'WC_Cart' ) ) {
            return;
        }

        /**
         * If the persistent guest cart is empty, we must also clear
         * WooCommerce session cart; otherwise stale items can remain in UI
         * after refresh while AI (persistent storage) reports an empty cart.
         */
        if ( empty( $stored_cart ) ) {
            if ( ! empty( $wc_cart->get_cart() ) ) {
                $wc_cart->empty_cart();
                $wc_cart->calculate_totals();

                if ( WC()->session ) {
                    WC()->session->set( 'cart', $wc_cart->get_cart_for_session() );
                }
            }

            return;
        }

        self::$is_rebuilding_wc_cart = true;
        CartReconciler::replace_wc_cart_with_persistent( $stored_cart, $wc_cart );
        $wc_cart->calculate_totals();
        self::$is_rebuilding_wc_cart = false;

        /** Save updated cart into WooCommerce session. */
        if ( WC()->session ) {
            WC()->session->set( 'cart', $wc_cart->get_cart_for_session() );
        }

        /**
         * Mark this guest cart version as synced even if nothing was added,
         * to avoid re-running merge logic on every page load.
         */
        if ( WC()->session ) {
            $meta           = CartStorage::get_cart_meta( $guest_token );
            $stored_version = (int) ( $meta['version'] ?? 0 );
            if ( $stored_version > 0 ) {
                $session_key = 'aicommerce_guest_cart_version_' . md5( $guest_token );
                WC()->session->set( $session_key, $stored_version );
            }
        }
    }

    /**
     * Update user_meta cart from WooCommerce cart to keep them in sync.
     *
     * @param int     $user_id User ID.
     * @param WC_Cart $wc_cart WooCommerce cart instance.
     */
    private function update_user_cart_from_wc( int $user_id, \WC_Cart $wc_cart ): void {
        /** Prevent recursive updates during the same request. */
        static $updating = false;
        if ( $updating ) {
            return;
        }
        $updating = true;

        /** Final normalized cart items array to be saved in user_meta. */
        $wc_cart_items = CartReconciler::persistent_items_from_wc_cart( $wc_cart );

        /** Persist normalized WooCommerce cart into user storage. */
        CartStorage::save_user_cart( $user_id, $wc_cart_items );

        /** Release recursion lock. */
        $updating = false;
    }

    /**
     * Sync WooCommerce cart to user_meta when item is added via frontend.
     *
     * @param string $cart_item_key  Cart item key.
     * @param int    $product_id     Product ID.
     * @param int    $quantity       Quantity.
     * @param int    $variation_id   Variation ID.
     * @param array  $variation      Variation data.
     * @param array  $cart_item_data Additional cart item data.
     */
    public function sync_wc_cart_to_user_meta( string $cart_item_key = '', int $product_id = 0, int $quantity = 0, int $variation_id = 0, array $variation = array(), array $cart_item_data = array() ): void {
        /** Ensure WooCommerce is available. */
        if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
            return;
        }

        /** Get WooCommerce cart instance. */
        $wc_cart = WC()->cart;

        /** Stop if cart is missing or invalid. */
        if ( ! $wc_cart || ! is_a( $wc_cart, 'WC_Cart' ) ) {
            return;
        }

        /** Skip write-back while we are rebuilding WC cart from persistent storage. */
        if ( self::$is_rebuilding_wc_cart ) {
            return;
        }

        /**
         * Skip AICommerce REST API calls.
         *
         * Those requests already update persistent storage directly.
         */
        if ( doing_action( 'woocommerce_add_to_cart' ) && isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], '/wp-json/aicommerce/v1/' ) !== false ) {
            return;
        }

        if ( is_user_logged_in() ) {
            /** Resolve current user ID. */
            $user_id = get_current_user_id();

            /** Stop if user ID is invalid. */
            if ( ! $user_id ) {
                return;
            }

            /** Persist current WooCommerce cart into user storage. */
            $this->update_user_cart_from_wc( $user_id, $wc_cart );
            return;
        }

        /** Guest flow: keep guest persistent cart aligned with WooCommerce cart. */
        $guest_token = '';
        if ( isset( $_COOKIE['aicommerce_guest_token'] ) ) {
            $guest_token = sanitize_text_field( wp_unslash( $_COOKIE['aicommerce_guest_token'] ) );
        }

        if ( empty( $guest_token ) || ! preg_match( '/^guest_\d+_[a-zA-Z0-9]+_[a-f0-9]{8}$/', $guest_token ) ) {
            return;
        }

        $this->update_guest_cart_from_wc( $guest_token, $wc_cart );
    }

    /**
     * Update guest cart storage from WooCommerce cart.
     *
     * @param string  $guest_token Guest token.
     * @param WC_Cart $wc_cart     WooCommerce cart instance.
     */
    private function update_guest_cart_from_wc( string $guest_token, \WC_Cart $wc_cart ): void {
        /** Prevent recursive updates during the same request. */
        static $updating_guest = false;
        if ( $updating_guest ) {
            return;
        }
        $updating_guest = true;

        $wc_cart_items = CartReconciler::persistent_items_from_wc_cart( $wc_cart );

        CartStorage::save_cart( $guest_token, $wc_cart_items );
        $updating_guest = false;
    }

    /**
     * Sync WooCommerce cart to user_meta when quantity changes.
     *
     * @param string $cart_item_key Cart item key.
     * @param int    $quantity      New quantity.
     */
    public function sync_wc_cart_to_user_meta_on_quantity_change( string $cart_item_key, int $quantity ): void {
        /** Reuse generic WooCommerce-to-user_meta sync. */
        $this->sync_wc_cart_to_user_meta();
    }

    /**
     * Sync WooCommerce cart to user_meta when item is removed.
     *
     * @param string  $cart_item_key Cart item key.
     * @param WC_Cart $cart          Cart instance.
     */
    public function sync_wc_cart_to_user_meta_on_remove( string $cart_item_key, \WC_Cart $cart ): void {
        /** Reuse generic WooCommerce-to-user_meta sync. */
        $this->sync_wc_cart_to_user_meta();
    }

    /**
     * Sync user cart to WooCommerce session after WooCommerce loads its cart.
     *
     * This runs after WooCommerce restores its persistent cart.
     */
    public function sync_user_cart_after_wc_load(): void {
        /** Sync only for logged-in users. */
        if ( ! is_user_logged_in() ) {
            return;
        }

        /** Resolve current user ID. */
        $user_id = get_current_user_id();

        /** Stop if current user ID is invalid. */
        if ( ! $user_id ) {
            return;
        }

        /** Prevent duplicate execution during the same request. */
        static $syncing = false;
        if ( $syncing ) {
            return;
        }
        $syncing = true;

        /** Ensure WooCommerce is loaded before using the cart. */
        if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
            $syncing = false;
            return;
        }

        /** Get current WooCommerce cart instance. */
        $wc_cart = WC()->cart;

        /** Stop if WooCommerce cart is unavailable. */
        if ( ! $wc_cart || ! is_a( $wc_cart, 'WC_Cart' ) ) {
            $syncing = false;
            return;
        }

        /** Load persistent user cart. */
        $user_cart = CartStorage::get_user_cart( $user_id );

        /** No-op if the cart version is already applied in the active session. */
        if ( WC()->session ) {
            $meta           = CartStorage::get_user_cart_meta( $user_id );
            $stored_version = (int) ( $meta['version'] ?? 0 );
            $last_applied   = (int) WC()->session->get( 'aicommerce_user_cart_version', 0 );

            if ( $stored_version > 0 && $stored_version === $last_applied ) {
                $syncing = false;
                return;
            }
        }

        /**
         * If AI persistent cart is empty but WC already restored a cart,
         * adopt the WC state as the persistent user cart so the two systems
         * converge without requiring another interaction.
         */
        if ( empty( $user_cart ) ) {
            if ( ! empty( $wc_cart->get_cart() ) ) {
                $this->update_user_cart_from_wc( $user_id, $wc_cart );

                if ( WC()->session ) {
                    $meta           = CartStorage::get_user_cart_meta( $user_id );
                    $stored_version = (int) ( $meta['version'] ?? 0 );
                    if ( $stored_version > 0 ) {
                        WC()->session->set( 'aicommerce_user_cart_version', $stored_version );
                    }
                }
            }

            $syncing = false;
            return;
        }

        /** Rebuild Woo cart from the persistent cart because version changed. */
        self::$is_rebuilding_wc_cart = true;
        CartReconciler::replace_wc_cart_with_persistent( $user_cart, $wc_cart );
        $wc_cart->calculate_totals();
        self::$is_rebuilding_wc_cart = false;

        if ( WC()->session ) {
            WC()->session->set( 'cart', $wc_cart->get_cart_for_session() );
        }

        $this->update_user_cart_from_wc( $user_id, $wc_cart );

        if ( WC()->session ) {
            $meta           = CartStorage::get_user_cart_meta( $user_id );
            $stored_version = (int) ( $meta['version'] ?? 0 );
            if ( $stored_version > 0 ) {
                WC()->session->set( 'aicommerce_user_cart_version', $stored_version );
            }
        }

        /** Release execution lock. */
        $syncing = false;
    }

    /**
     * Enqueue frontend scripts.
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

        /**
         * Build the default enqueue rule.
         *
         * On non-WooCommerce pages we avoid loading the sync script because it may
         * trigger cart sync or polling unnecessarily.
         *
         * Exception:
         * If iframe mode is enabled, the popup can open anywhere, so the listeners
         * inside cart-sync.js are still required.
         *
         * Themes can override this behavior through the filter below.
         */

        /** Detect standard WooCommerce page context. */
        $is_wc_context = function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() );

        /** Check whether iframe mode is enabled. */
        $iframe_enabled = (bool) get_option( 'aicommerce_iframe_enabled', false );

        /** Default decision for script loading. */
        $default_enqueue = ( $is_wc_context || $iframe_enabled );

        /** Allow themes or plugins to override enqueue logic. */
        $should_enqueue = apply_filters( 'aicommerce_should_enqueue_cart_sync', $default_enqueue );

        /** Stop if script should not be loaded on this page. */
        if ( ! $should_enqueue ) {
            return;
        }

        /** Enqueue cart synchronization script. */
        wp_enqueue_script(
            'aicommerce-cart-sync',
            AICOMMERCE_PLUGIN_URL . 'assets/js/cart-sync.js',
            array( 'aicommerce-guest-token' ),
            AICOMMERCE_VERSION,
            true
        );

        /** Use deferred loading when supported. */
        if ( function_exists( 'wp_script_add_data' ) ) {
            wp_script_add_data( 'aicommerce-cart-sync', 'strategy', 'defer' );
        }

        /** Pass runtime configuration to frontend script. */
        wp_localize_script(
            'aicommerce-cart-sync',
            'aicommerceCartSyncConfig',
            array(
                /** Only auto-sync on first load for cart and checkout pages. */
                'auto_sync_on_load' => (bool) ( is_cart() || is_checkout() ),
            )
        );
    }
}
