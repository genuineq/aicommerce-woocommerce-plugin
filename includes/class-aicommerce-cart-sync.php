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
     * Constructor.
     */
    public function __construct() {
        /** Register frontend assets. */
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        /** Mark user cart for sync after login. */
        add_action( 'wp_login', array( $this, 'set_sync_flag_on_login' ), 10, 2 );

        /** Sync logged-in user cart after WooCommerce restores cart session. */
        add_action( 'woocommerce_load_cart_from_session', array( $this, 'sync_user_cart_after_wc_load' ), 20 );

        /** Sync guest cart from persistent storage into WooCommerce session. */
        add_action( 'woocommerce_load_cart_from_session', array( $this, 'sync_guest_cart_on_page_load' ), 25 );

        /** Sync logged-in user cart on normal frontend page loads. */
        add_action( 'wp_loaded', array( $this, 'sync_user_cart_on_page_load' ), 30 );

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

        /** Nothing to sync if stored cart is empty. */
        if ( empty( $stored_cart ) ) {
            return;
        }

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

        /** Track whether at least one item was added to the cart. */
        $added = false;

        /** Iterate over all stored guest cart items. */
        foreach ( $stored_cart as $item ) {
            /** Extract product ID from stored item. */
            $product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;

            /** Extract quantity, defaulting to 1. */
            $quantity = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 1;

            /** Extract variation data if present. */
            $variation_data = isset( $item['variation_data'] ) ? $item['variation_data'] : array();

            /** Skip invalid products. */
            if ( $product_id <= 0 ) {
                continue;
            }

            /** Load WooCommerce product object. */
            $product = wc_get_product( $product_id );

            /** Skip missing or non-purchasable products. */
            if ( ! $product || ! $product->is_purchasable() ) {
                continue;
            }

            /** Default variation ID. */
            $variation_id = 0;

            /** Extract variation ID from variation data when available. */
            if ( ! empty( $variation_data ) && isset( $variation_data['variation_id'] ) ) {
                $variation_id = absint( $variation_data['variation_id'] );
            }

            /** Normalize variation data for WooCommerce compatibility. */
            $variation_data = CartAPI::normalize_variation_data_for_wc( $product_id, $variation_data );

            /** Build variation attributes for add_to_cart(). */
            $variation_attrs = CartAPI::get_variation_attributes_for_add_to_cart( $variation_data, $product_id );

            /** Check whether the item already exists in the WooCommerce cart. */
            $found = false;
            foreach ( $wc_cart->get_cart() as $cart_item ) {
                if ( (int) $cart_item['product_id'] === $product_id &&
                    (int) $cart_item['variation_id'] === $variation_id ) {
                    $found = true;
                    break;
                }
            }

            /** Skip items that already exist in cart. */
            if ( $found ) {
                continue;
            }

            /** Try to add the missing item to the WooCommerce cart. */
            try {
                $wc_cart->add_to_cart( $product_id, $quantity, $variation_id, $variation_attrs );
                $added = true;
            } catch ( \Exception $e ) {
                /** Log exceptions only when debug logging is enabled. */
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                    error_log( '[AICOM] guest sync add_to_cart exception: ' . $e->getMessage() . ' product_id=' . $product_id );
                }
            }
        }

        /** Recalculate totals and persist session cart if items were added. */
        if ( $added ) {
            $wc_cart->calculate_totals();

            /** Save updated cart into WooCommerce session. */
            if ( WC()->session ) {
                WC()->session->set( 'cart', $wc_cart->get_cart_for_session() );
            }
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
     * Sync user cart on page load for already logged-in users.
     */
    public function sync_user_cart_on_page_load(): void {
        /** Do not run in admin area. */
        if ( is_admin() ) {
            return;
        }

        /** This sync applies only to logged-in users. */
        if ( ! is_user_logged_in() ) {
            return;
        }

        /** Resolve current user ID. */
        $user_id = get_current_user_id();

        /** Stop if current user ID is invalid. */
        if ( ! $user_id ) {
            return;
        }

        /** Prevent duplicate sync during the same request. */
        static $synced = false;
        if ( $synced ) {
            return;
        }

        /** Ensure WooCommerce is loaded. */
        if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
            return;
        }

        /** Ensure WooCommerce already restored cart from session. */
        if ( ! did_action( 'woocommerce_load_cart_from_session' ) ) {
            return;
        }

        /** Load persistent cart stored for the user. */
        $user_cart = CartStorage::get_user_cart( $user_id );

        /** Nothing to sync if stored cart is empty. */
        if ( empty( $user_cart ) ) {
            return;
        }

        /** Get current WooCommerce cart instance. */
        $wc_cart = WC()->cart;

        /** Stop if cart instance is missing or invalid. */
        if ( ! $wc_cart || ! is_a( $wc_cart, 'WC_Cart' ) ) {
            return;
        }

        /** Flag showing whether user_meta cart must be pushed into WooCommerce cart. */
        $needs_sync_to_wc = false;

        /** Compare stored user cart with the current WooCommerce cart. */
        foreach ( $user_cart as $item ) {
            /** Extract product ID. */
            $product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;

            /** Extract quantity. */
            $quantity = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 1;

            /** Extract variation data. */
            $variation_data = isset( $item['variation_data'] ) ? $item['variation_data'] : array();

            /** Default variation ID. */
            $variation_id = 0;

            /** Extract variation ID if present. */
            if ( ! empty( $variation_data ) && isset( $variation_data['variation_id'] ) ) {
                $variation_id = absint( $variation_data['variation_id'] );
            }

            /** Track whether the current stored item exists in WooCommerce cart. */
            $found = false;

            /** Search for the current stored item in the WooCommerce cart. */
            foreach ( $wc_cart->get_cart() as $cart_item ) {
                if ( $cart_item['product_id'] == $product_id &&
                    $cart_item['variation_id'] == $variation_id &&
                    ( empty( $variation_data ) || $cart_item['variation'] == $variation_data ) ) {
                    /** Sync is needed if WooCommerce quantity is smaller. */
                    if ( $cart_item['quantity'] < $quantity ) {
                        $needs_sync_to_wc = true;
                        break 2;
                    }

                    $found = true;
                    break;
                }
            }

            /** Sync is needed if the stored item is missing from WooCommerce cart. */
            if ( ! $found ) {
                $needs_sync_to_wc = true;
                break;
            }
        }

        /** Push missing persistent items into WooCommerce cart when required. */
        if ( $needs_sync_to_wc ) {
            /**
             * Add missing user_meta items into WooCommerce cart and then
             * refresh user_meta from the final WooCommerce cart state.
             */
            $this->perform_cart_sync( $user_id, $user_cart, $wc_cart );
            $synced = true;
        } else {
            /**
             * WooCommerce cart already contains the stored user cart.
             *
             * Refresh user_meta to also include items added from the frontend,
             * including classic cart flows and block-based Store API flows.
             */
            $this->update_user_cart_from_wc( $user_id, $wc_cart );
            $synced = true;
        }

        /** Mark stored user cart version as synced to avoid re-processing. */
        if ( function_exists( 'WC' ) && WC() && WC()->session ) {
            $meta           = CartStorage::get_user_cart_meta( $user_id );
            $stored_version = (int) ( $meta['version'] ?? 0 );
            if ( $stored_version > 0 ) {
                WC()->session->set( 'aicommerce_user_cart_version', $stored_version );
            }
        }
    }

    /**
     * Perform cart synchronization.
     *
     * @param int     $user_id   User ID.
     * @param array   $user_cart Cart from user_meta passed by reference.
     * @param WC_Cart $wc_cart   WooCommerce cart instance.
     */
    private function perform_cart_sync( int $user_id, array &$user_cart, \WC_Cart $wc_cart ): void {
        /** Track whether WooCommerce cart changed during sync. */
        $changed = false;

        /** Iterate through stored user cart items. */
        foreach ( $user_cart as $idx => $item ) {
            /** Extract product ID. */
            $product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;

            /** Extract quantity. */
            $quantity = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 1;

            /** Extract variation data. */
            $variation_data = isset( $item['variation_data'] ) ? $item['variation_data'] : array();

            /** Skip invalid products. */
            if ( $product_id <= 0 ) {
                continue;
            }

            /** Load WooCommerce product object. */
            $product = wc_get_product( $product_id );

            /** Skip missing or non-purchasable products. */
            if ( ! $product || ! $product->is_purchasable() ) {
                continue;
            }

            /** Default variation ID. */
            $variation_id = 0;

            /** Extract variation ID if available. */
            if ( ! empty( $variation_data ) && isset( $variation_data['variation_id'] ) ) {
                $variation_id = absint( $variation_data['variation_id'] );
            }

            /** Normalize variation data before comparing or adding to cart. */
            $variation_data = CartAPI::normalize_variation_data_for_wc( $product_id, $variation_data );

            /** Build variation attributes for WooCommerce add_to_cart(). */
            $variation_attrs = CartAPI::get_variation_attributes_for_add_to_cart( $variation_data, $product_id );

            /** Existing WooCommerce cart item key if found. */
            $cart_item_key = null;

            /** Existing quantity in WooCommerce cart. */
            $existing_quantity = 0;

            /** Search for matching item already present in WooCommerce cart. */
            foreach ( $wc_cart->get_cart() as $key => $cart_item ) {
                if ( $cart_item['product_id'] == $product_id &&
                    $cart_item['variation_id'] == $variation_id &&
                    ( empty( $variation_attrs ) || $cart_item['variation'] == $variation_attrs ) ) {
                    $cart_item_key = $key;
                    $existing_quantity = $cart_item['quantity'];
                    break;
                }
            }

            /** Add item to WooCommerce cart when missing. */
            if ( ! $cart_item_key ) {
                try {
                    /** Add stored item into WooCommerce cart. */
                    $new_cart_item_key = $wc_cart->add_to_cart( $product_id, $quantity, $variation_id, $variation_attrs );
                } catch ( \Exception $e ) {
                    /** Log add_to_cart exceptions in debug mode. */
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                        error_log( '[AICOM] sync add_to_cart exception: ' . $e->getMessage() . ' product_id=' . $product_id . ' variation_id=' . $variation_id );
                    }

                    $new_cart_item_key = false;
                }

                /** Update stored key when WooCommerce created the item successfully. */
                if ( $new_cart_item_key ) {
                    $changed = true;

                    if ( $new_cart_item_key != $item['key'] ) {
                        $user_cart[ $idx ]['key'] = $new_cart_item_key;
                    }
                }
            } else {
                /** Keep stored cart key aligned with WooCommerce cart key. */
                if ( $cart_item_key != $item['key'] ) {
                    $user_cart[ $idx ]['key'] = $cart_item_key;
                }

                /** Increase WooCommerce quantity if stored quantity is larger. */
                if ( $existing_quantity < $quantity ) {
                    $wc_cart->set_quantity( $cart_item_key, $quantity );
                    $changed = true;
                }
            }
        }

        /** Recalculate totals and persist session data if cart changed. */
        if ( $changed ) {
            $wc_cart->calculate_totals();

            /** Store updated cart in session. */
            if ( WC()->session ) {
                WC()->session->set( 'cart', $wc_cart->get_cart_for_session() );
            }
        }

        /** Save updated user cart keys back to persistent storage. */
        CartStorage::save_user_cart( $user_id, $user_cart );

        /** Refresh persistent cart from final WooCommerce cart state. */
        $this->update_user_cart_from_wc( $user_id, $wc_cart );
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
        $wc_cart_items = array();

        /** Convert WooCommerce cart items into persistent storage format. */
        foreach ( $wc_cart->get_cart() as $cart_item_key => $cart_item ) {
            /** Read variation attributes from WooCommerce item. */
            $variation_data = isset( $cart_item['variation'] ) ? $cart_item['variation'] : array();

            /** Include variation ID inside saved variation data. */
            if ( ! empty( $cart_item['variation_id'] ) ) {
                $variation_data = array_merge( array( 'variation_id' => (int) $cart_item['variation_id'] ), $variation_data );
            }

            /** Append normalized cart item to storage array. */
            $wc_cart_items[] = array(
                'key'            => $cart_item_key,
                'product_id'     => $cart_item['product_id'],
                'quantity'       => $cart_item['quantity'],
                'variation_data' => $variation_data,
                'added_at'       => time(),
            );
        }

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

        $wc_cart_items = array();
        foreach ( $wc_cart->get_cart() as $cart_item_key => $cart_item ) {
            $variation_data = isset( $cart_item['variation'] ) ? $cart_item['variation'] : array();
            if ( ! empty( $cart_item['variation_id'] ) ) {
                $variation_data = array_merge( array( 'variation_id' => (int) $cart_item['variation_id'] ), $variation_data );
            }

            $wc_cart_items[] = array(
                'key'            => $cart_item_key,
                'product_id'     => $cart_item['product_id'],
                'quantity'       => $cart_item['quantity'],
                'variation_data' => $variation_data,
                'added_at'       => time(),
            );
        }

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
     * Set flag to sync user cart after WooCommerce loads its cart.
     *
     * @param string  $user_login User login name.
     * @param WP_User $user       User object.
     */
    public function set_sync_flag_on_login( string $user_login, \WP_User $user ): void {
        /** Extract user ID from user object. */
        $user_id = $user->ID;

        /** Load stored user cart. */
        $user_cart = CartStorage::get_user_cart( $user_id );

        /** Set deferred sync flag only when stored cart exists. */
        if ( ! empty( $user_cart ) ) {
            update_user_meta( $user_id, '_aicommerce_sync_cart_on_load', true );
        }
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

        /** Read deferred sync flag set during login. */
        $should_sync = get_user_meta( $user_id, '_aicommerce_sync_cart_on_load', true );

        /** Stop if login sync is not required. */
        if ( ! $should_sync ) {
            return;
        }

        /** Remove sync flag so it runs only once. */
        delete_user_meta( $user_id, '_aicommerce_sync_cart_on_load' );

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

        /** Load persistent user cart. */
        $user_cart = CartStorage::get_user_cart( $user_id );

        /** Stop if user cart is empty. */
        if ( empty( $user_cart ) ) {
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

        /** Perform actual synchronization. */
        $this->perform_cart_sync( $user_id, $user_cart, $wc_cart );

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

        $suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

        /** Enqueue cart synchronization script. */
        wp_enqueue_script(
            'aicommerce-cart-sync',
            AICOMMERCE_PLUGIN_URL . 'assets/js/cart-sync' . $suffix . '.js',
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
