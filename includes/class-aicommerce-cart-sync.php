<?php
/**
 * Cart Sync Frontend
 *
 * @package AICommerce
 */

namespace AICommerce;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cart Sync Class
 * Handles frontend cart synchronization
 */
class CartSync {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_login', array( $this, 'set_sync_flag_on_login' ), 10, 2 );
        add_action( 'woocommerce_load_cart_from_session', array( $this, 'sync_user_cart_after_wc_load' ), 20 );
        add_action( 'woocommerce_load_cart_from_session', array( $this, 'sync_guest_cart_on_page_load' ), 25 );
        add_action( 'wp_loaded', array( $this, 'sync_user_cart_on_page_load' ), 30 );
        
        // Sync WC cart changes to user_meta (bidirectional sync)
        add_action( 'woocommerce_add_to_cart', array( $this, 'sync_wc_cart_to_user_meta' ), 20, 6 );
        add_action( 'woocommerce_cart_item_set_quantity', array( $this, 'sync_wc_cart_to_user_meta_on_quantity_change' ), 20, 2 );
        add_action( 'woocommerce_cart_item_removed', array( $this, 'sync_wc_cart_to_user_meta_on_remove' ), 20, 2 );
        add_action( 'woocommerce_cart_item_restored', array( $this, 'sync_wc_cart_to_user_meta' ), 20, 1 );
    }
    
    /**
     * Sync guest cart from cookie to WooCommerce session on every frontend page load.
     *
     * Reads the guest_token from the aicommerce_guest_token cookie, loads the cart
     * stored in wp_options (populated by external API calls), and merges any missing
     * items into the active WC session before the page is rendered.
     * This makes the cart appear immediately without a client-side AJAX round-trip.
     */
    public function sync_guest_cart_on_page_load(): void {
        if ( is_admin() ) {
            return;
        }

        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return;
        }

        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return;
        }

        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return;
        }

        if ( is_user_logged_in() ) {
            return;
        }

        if ( ! isset( $_COOKIE['aicommerce_guest_token'] ) ) {
            return;
        }

        $guest_token = sanitize_text_field( wp_unslash( $_COOKIE['aicommerce_guest_token'] ) );
        if ( empty( $guest_token ) ) {
            return;
        }

        if ( ! preg_match( '/^guest_\d+_[a-zA-Z0-9]+_[a-f0-9]{8}$/', $guest_token ) ) {
            return;
        }

        static $synced = false;
        if ( $synced ) {
            return;
        }
        $synced = true;

        $stored_cart = CartStorage::get_cart( $guest_token );
        if ( empty( $stored_cart ) ) {
            return;
        }

        if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
            return;
        }

        $wc_cart = WC()->cart;
        if ( ! $wc_cart || ! is_a( $wc_cart, 'WC_Cart' ) ) {
            return;
        }

        $added = false;

        foreach ( $stored_cart as $item ) {
            $product_id     = isset( $item['product_id'] )    ? absint( $item['product_id'] )  : 0;
            $quantity       = isset( $item['quantity'] )       ? absint( $item['quantity'] )    : 1;
            $variation_data = isset( $item['variation_data'] ) ? $item['variation_data']        : array();

            if ( $product_id <= 0 ) {
                continue;
            }

            $product = wc_get_product( $product_id );
            if ( ! $product || ! $product->is_purchasable() ) {
                continue;
            }

            $variation_id = 0;
            if ( ! empty( $variation_data ) && isset( $variation_data['variation_id'] ) ) {
                $variation_id = absint( $variation_data['variation_id'] );
            }

            $variation_data  = CartAPI::normalize_variation_data_for_wc( $product_id, $variation_data );
            $variation_attrs = CartAPI::get_variation_attributes_for_add_to_cart( $variation_data, $product_id );

            // Skip items already present in the WC cart
            $found = false;
            foreach ( $wc_cart->get_cart() as $cart_item ) {
                if ( (int) $cart_item['product_id'] === $product_id &&
                     (int) $cart_item['variation_id'] === $variation_id ) {
                    $found = true;
                    break;
                }
            }

            if ( $found ) {
                continue;
            }

            try {
                $wc_cart->add_to_cart( $product_id, $quantity, $variation_id, $variation_attrs );
                $added = true;
            } catch ( \Exception $e ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                    error_log( '[AICOM] guest sync add_to_cart exception: ' . $e->getMessage() . ' product_id=' . $product_id );
                }
            }
        }

        if ( $added ) {
            $wc_cart->calculate_totals();

            if ( WC()->session ) {
                WC()->session->set( 'cart', $wc_cart->get_cart_for_session() );
            }
        }
    }

    /**
     * Sync user cart on page load (for already logged in users)
     */
    public function sync_user_cart_on_page_load(): void {
        if ( is_admin() ) {
            return;
        }
        
        if ( ! is_user_logged_in() ) {
            return;
        }
        
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return;
        }
        
        static $synced = false;
        if ( $synced ) {
            return;
        }
        
        if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
            return;
        }
        
        if ( ! did_action( 'woocommerce_load_cart_from_session' ) ) {
            return;
        }
        
        $user_cart = CartStorage::get_user_cart( $user_id );
        if ( empty( $user_cart ) ) {
            return;
        }
        
        $wc_cart = WC()->cart;
        if ( ! $wc_cart || ! is_a( $wc_cart, 'WC_Cart' ) ) {
            return;
        }
        
        $needs_sync_to_wc = false;
        foreach ( $user_cart as $item ) {
            $product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
            $quantity = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 1;
            $variation_data = isset( $item['variation_data'] ) ? $item['variation_data'] : array();
            $variation_id = 0;
            if ( ! empty( $variation_data ) && isset( $variation_data['variation_id'] ) ) {
                $variation_id = absint( $variation_data['variation_id'] );
            }
            
            $found = false;
            foreach ( $wc_cart->get_cart() as $cart_item ) {
                if ( $cart_item['product_id'] == $product_id && 
                     $cart_item['variation_id'] == $variation_id &&
                     ( empty( $variation_data ) || $cart_item['variation'] == $variation_data ) ) {
                    if ( $cart_item['quantity'] < $quantity ) {
                        $needs_sync_to_wc = true;
                        break 2;
                    }
                    $found = true;
                    break;
                }
            }
            
            if ( ! $found ) {
                $needs_sync_to_wc = true;
                break;
            }
        }
        
        // Collect WC cart keys for items that are NOT in user_meta.
        // user_meta is the source of truth for API-managed carts: instead of copying
        // those items back into user_meta (which would undo API removals), we remove
        // them from the WC session so the browser reflects the API state.
        $wc_keys_not_in_user_meta = array();
        foreach ( $wc_cart->get_cart() as $cart_item_key => $cart_item ) {
            $product_id     = isset( $cart_item['product_id'] )   ? absint( $cart_item['product_id'] )   : 0;
            $variation_id   = isset( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : 0;
            $variation_data = isset( $cart_item['variation'] )     ? $cart_item['variation']              : array();

            $found = false;
            foreach ( $user_cart as $item ) {
                if ( $item['product_id'] == $product_id &&
                     ( isset( $item['variation_data']['variation_id'] ) ? absint( $item['variation_data']['variation_id'] ) : 0 ) == $variation_id &&
                     ( empty( $variation_data ) || $item['variation_data'] == $variation_data ) ) {
                    $found = true;
                    break;
                }
            }

            if ( ! $found ) {
                $wc_keys_not_in_user_meta[] = $cart_item_key;
            }
        }

        if ( $needs_sync_to_wc ) {
            $this->perform_cart_sync( $user_id, $user_cart, $wc_cart );
            $synced = true;
        }

        if ( ! empty( $wc_keys_not_in_user_meta ) ) {
            foreach ( $wc_keys_not_in_user_meta as $key ) {
                $wc_cart->remove_cart_item( $key );
            }
            $wc_cart->calculate_totals();
            if ( WC()->session ) {
                WC()->session->set( 'cart', $wc_cart->get_cart_for_session() );
            }
            $synced = true;
        }
    }
    
    /**
     * Perform cart synchronization
     *
     * @param int     $user_id User ID
     * @param array   $user_cart Cart from user_meta (passed by reference to update keys)
     * @param WC_Cart $wc_cart WooCommerce cart instance
     */
    private function perform_cart_sync( int $user_id, array &$user_cart, \WC_Cart $wc_cart ): void {
        foreach ( $user_cart as $idx => $item ) {
            $product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
            $quantity = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 1;
            $variation_data = isset( $item['variation_data'] ) ? $item['variation_data'] : array();
            
            if ( $product_id <= 0 ) {
                continue;
            }
            
            $product = wc_get_product( $product_id );
            if ( ! $product || ! $product->is_purchasable() ) {
                continue;
            }
            
            $variation_id = 0;
            if ( ! empty( $variation_data ) && isset( $variation_data['variation_id'] ) ) {
                $variation_id = absint( $variation_data['variation_id'] );
            }
            
            $variation_data = CartAPI::normalize_variation_data_for_wc( $product_id, $variation_data );
            $variation_attrs = CartAPI::get_variation_attributes_for_add_to_cart( $variation_data, $product_id );
            
            $cart_item_key = null;
            $existing_quantity = 0;
            foreach ( $wc_cart->get_cart() as $key => $cart_item ) {
                if ( $cart_item['product_id'] == $product_id && 
                     $cart_item['variation_id'] == $variation_id &&
                     ( empty( $variation_attrs ) || $cart_item['variation'] == $variation_attrs ) ) {
                    $cart_item_key = $key;
                    $existing_quantity = $cart_item['quantity'];
                    break;
                }
            }
            
            if ( ! $cart_item_key ) {
                try {
                    $new_cart_item_key = $wc_cart->add_to_cart( $product_id, $quantity, $variation_id, $variation_attrs );
                } catch ( \Exception $e ) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                        error_log( '[AICOM] sync add_to_cart exception: ' . $e->getMessage() . ' product_id=' . $product_id . ' variation_id=' . $variation_id );
                    }
                    $new_cart_item_key = false;
                }
                
                if ( $new_cart_item_key && $new_cart_item_key != $item['key'] ) {
                    $user_cart[ $idx ]['key'] = $new_cart_item_key;
                }
            } else {
                if ( $cart_item_key != $item['key'] ) {
                    $user_cart[ $idx ]['key'] = $cart_item_key;
                }
                
                if ( $existing_quantity < $quantity ) {
                    $wc_cart->set_quantity( $cart_item_key, $quantity );
                }
            }
        }
        
        $wc_cart->calculate_totals();
        
        if ( WC()->session ) {
            WC()->session->set( 'cart', $wc_cart->get_cart_for_session() );
        }
        
        CartStorage::save_user_cart( $user_id, $user_cart );
        
        $this->update_user_cart_from_wc( $user_id, $wc_cart );
    }
    
    /**
     * Update user_meta cart from WC cart to keep them in sync
     *
     * @param int     $user_id User ID
     * @param WC_Cart $wc_cart WooCommerce cart instance
     */
    private function update_user_cart_from_wc( int $user_id, \WC_Cart $wc_cart ): void {
        static $updating = false;
        if ( $updating ) {
            return;
        }
        $updating = true;
        
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
        
        CartStorage::save_user_cart( $user_id, $wc_cart_items );
        
        $updating = false;
    }
    
    /**
     * Sync WC cart to user_meta when item is added via frontend
     *
     * @param string $cart_item_key Cart item key
     * @param int    $product_id Product ID
     * @param int    $quantity Quantity
     * @param int    $variation_id Variation ID
     * @param array  $variation Variation data
     * @param array  $cart_item_data Cart item data
     */
    public function sync_wc_cart_to_user_meta( string $cart_item_key = '', int $product_id = 0, int $quantity = 0, int $variation_id = 0, array $variation = array(), array $cart_item_data = array() ): void {
        if ( ! is_user_logged_in() ) {
            return;
        }
        
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return;
        }
        
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return;
        }
        
        if ( doing_action( 'woocommerce_add_to_cart' ) && isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], '/wp-json/aicommerce/v1/' ) !== false ) {
            return;
        }
        
        if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
            return;
        }
        
        $wc_cart = WC()->cart;
        if ( ! $wc_cart || ! is_a( $wc_cart, 'WC_Cart' ) ) {
            return;
        }
        
        $this->update_user_cart_from_wc( $user_id, $wc_cart );
    }
    
    /**
     * Sync WC cart to user_meta when quantity changes
     *
     * @param string $cart_item_key Cart item key
     * @param int    $quantity New quantity
     */
    public function sync_wc_cart_to_user_meta_on_quantity_change( string $cart_item_key, int $quantity ): void {
        $this->sync_wc_cart_to_user_meta();
    }
    
    /**
     * Sync WC cart to user_meta when item is removed
     *
     * @param string $cart_item_key Cart item key
     * @param WC_Cart $cart Cart instance
     */
    public function sync_wc_cart_to_user_meta_on_remove( string $cart_item_key, \WC_Cart $cart ): void {
        $this->sync_wc_cart_to_user_meta();
    }
    
    /**
     * Set flag to sync user cart after WooCommerce loads its cart
     *
     * @param string  $user_login User login name
     * @param WP_User $user User object
     */
    public function set_sync_flag_on_login( string $user_login, \WP_User $user ): void {
        $user_id = $user->ID;
        $user_cart = CartStorage::get_user_cart( $user_id );
        
        if ( ! empty( $user_cart ) ) {
            update_user_meta( $user_id, '_aicommerce_sync_cart_on_load', true );
        }
    }
    
    /**
     * Sync user cart to WooCommerce session after WooCommerce loads its cart
     * This runs after WooCommerce has loaded persistent cart from user_meta
     */
    public function sync_user_cart_after_wc_load(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }
        
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return;
        }
        
        $should_sync = get_user_meta( $user_id, '_aicommerce_sync_cart_on_load', true );
        if ( ! $should_sync ) {
            return;
        }
        
        delete_user_meta( $user_id, '_aicommerce_sync_cart_on_load' );
        
        static $syncing = false;
        if ( $syncing ) {
            return;
        }
        $syncing = true;
        
        if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
            $syncing = false;
            return;
        }
        
        $user_cart = CartStorage::get_user_cart( $user_id );
        if ( empty( $user_cart ) ) {
            $syncing = false;
            return;
        }
        
        $wc_cart = WC()->cart;
        if ( ! $wc_cart || ! is_a( $wc_cart, 'WC_Cart' ) ) {
            $syncing = false;
            return;
        }
        
        $this->perform_cart_sync( $user_id, $user_cart, $wc_cart );
        $syncing = false;
    }
    
    /**
     * Enqueue frontend scripts
     */
    public function enqueue_scripts(): void {
        if ( is_admin() ) {
            return;
        }
        
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }
        
        wp_enqueue_script(
            'aicommerce-cart-sync',
            AICOMMERCE_PLUGIN_URL . 'assets/js/cart-sync.js',
            array( 'aicommerce-guest-token' ),
            AICOMMERCE_VERSION,
            true
        );
        
        $user_id = is_user_logged_in() ? get_current_user_id() : null;
        
        wp_localize_script(
            'aicommerce-cart-sync',
            'aicommerceCartSyncConfig',
            array(
                'api_key' => Settings::get_api_key(),
                'user_id' => $user_id,
            )
        );
    }
}
