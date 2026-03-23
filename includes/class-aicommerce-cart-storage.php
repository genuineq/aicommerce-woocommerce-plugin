<?php
/**
 * Cart Storage by Guest Token
 *
 * @package AICommerce
 */

namespace AICommerce;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cart Storage Class
 * Manages cart storage by guest_token or user_id
 */
class CartStorage {
    
    /**
     * Option prefix for guest cart storage
     */
    private const OPTION_PREFIX = 'aicommerce_guest_cart_';
    
    /**
     * Option prefix for user cart storage
     */
    private const USER_CART_PREFIX = 'aicommerce_user_cart_';
    
    /**
     * Cart expiration time in seconds (30 days)
     */
    private const CART_EXPIRATION = 30 * DAY_IN_SECONDS;
    
    /**
     * Get cart storage key for guest token
     */
    private static function get_storage_key( string $guest_token ): string {
        $token_hash = hash( 'sha256', $guest_token );
        return self::OPTION_PREFIX . $token_hash;
    }
    
    /**
     * Get cart storage key for user ID
     */
    private static function get_user_storage_key( int $user_id ): string {
        return self::USER_CART_PREFIX . $user_id;
    }
    
    /**
     * Get cart for guest token
     *
     * @param string $guest_token Guest token
     * @return array Cart items array
     */
    public static function get_cart( string $guest_token ): array {
        if ( empty( $guest_token ) ) {
            return array();
        }

        $key = self::get_storage_key( $guest_token );
        $cart_data = get_option( $key, null );

        if ( $cart_data === null ) {
            return array();
        }

        // Check expiration
        if ( isset( $cart_data['expires_at'] ) && $cart_data['expires_at'] < time() ) {
            self::delete_cart( $guest_token );
            return array();
        }

        $items = isset( $cart_data['items'] ) ? $cart_data['items'] : array();

        // Deduplicate items that share the same product_id + variation_id
        // (can accumulate if different key formats were stored over time)
        $deduped = self::deduplicate_items( $items );
        if ( count( $deduped ) !== count( $items ) ) {
            self::save_cart( $guest_token, $deduped );
        }

        return $deduped;
    }
    
    /**
     * Save cart for guest token
     *
     * @param string $guest_token Guest token
     * @param array  $items Cart items array
     * @return bool Success status
     */
    public static function save_cart( string $guest_token, array $items ): bool {
        if ( empty( $guest_token ) ) {
            return false;
        }
        
        $key = self::get_storage_key( $guest_token );
        
        $cart_data = array(
            'items'      => $items,
            'updated_at' => time(),
            'expires_at' => time() + self::CART_EXPIRATION,
        );
        
        return update_option( $key, $cart_data, false );
    }
    
    /**
     * Add item to cart
     *
     * @param string $guest_token Guest token
     * @param int    $product_id Product ID
     * @param int    $quantity Quantity
     * @param array  $variation_data Variation data (optional)
     * @return array|false Updated cart items or false on failure
     */
    public static function add_item( string $guest_token, int $product_id, int $quantity = 1, array $variation_data = array() ) {
        if ( empty( $guest_token ) || $product_id <= 0 || $quantity <= 0 ) {
            return false;
        }

        $cart = self::get_cart( $guest_token );

        // Match by product_id + variation_id to avoid duplicates from different key formats
        $existing_index = self::find_item_by_product( $cart, $product_id, $variation_data );

        if ( $existing_index !== false ) {
            $cart[ $existing_index ]['quantity'] += $quantity;
        } else {
            $cart[] = array(
                'key'            => self::generate_cart_item_key( $product_id, $variation_data ),
                'product_id'     => $product_id,
                'quantity'       => $quantity,
                'variation_data' => $variation_data,
                'added_at'       => time(),
            );
        }

        if ( self::save_cart( $guest_token, $cart ) ) {
            return $cart;
        }

        return false;
    }
    
    /**
     * Generate cart item key
     *
     * @param int   $product_id Product ID
     * @param array $variation_data Variation data
     * @return string Cart item key
     */
    private static function generate_cart_item_key( int $product_id, array $variation_data = array() ): string {
        if ( empty( $variation_data ) ) {
            return 'simple_' . $product_id;
        }

        ksort( $variation_data );
        $variation_string = md5( wp_json_encode( $variation_data ) );
        return 'variation_' . $product_id . '_' . $variation_string;
    }

    /**
     * Find item index in cart by product_id and variation_id.
     * More reliable than key-based lookup because keys may differ between
     * the internal storage format and WooCommerce-generated MD5 hashes.
     *
     * @param array $cart           Cart items
     * @param int   $product_id     Product ID
     * @param array $variation_data Variation data
     * @return int|false Item index or false if not found
     */
    private static function find_item_by_product( array $cart, int $product_id, array $variation_data = array() ) {
        $variation_id = isset( $variation_data['variation_id'] ) ? (int) $variation_data['variation_id'] : 0;

        foreach ( $cart as $index => $item ) {
            if ( (int) ( $item['product_id'] ?? 0 ) !== $product_id ) {
                continue;
            }
            $item_variation_id = isset( $item['variation_data']['variation_id'] )
                ? (int) $item['variation_data']['variation_id']
                : 0;
            if ( $item_variation_id === $variation_id ) {
                return $index;
            }
        }

        return false;
    }

    /**
     * Merge cart entries that share the same product_id + variation_id.
     * Quantities are summed; the first encountered item's data is kept as base.
     *
     * @param array $items Raw cart items (may contain duplicates)
     * @return array Deduplicated cart items
     */
    private static function deduplicate_items( array $items ): array {
        $seen   = array(); // "product_id:variation_id" => index in $result
        $result = array();

        foreach ( $items as $item ) {
            $product_id   = (int) ( $item['product_id'] ?? 0 );
            $variation_id = isset( $item['variation_data']['variation_id'] )
                ? (int) $item['variation_data']['variation_id']
                : 0;
            $sig = $product_id . ':' . $variation_id;

            if ( isset( $seen[ $sig ] ) ) {
                $result[ $seen[ $sig ] ]['quantity'] += (int) ( $item['quantity'] ?? 0 );
            } else {
                $seen[ $sig ]  = count( $result );
                $result[]      = $item;
            }
        }

        return array_values( $result );
    }

    /**
     * Find item index in cart by cart item key (legacy helper, kept for internal use).
     *
     * @param array  $cart         Cart items
     * @param string $cart_item_key Cart item key
     * @return int|false Item index or false if not found
     */
    private static function find_item_index( array $cart, string $cart_item_key ) {
        foreach ( $cart as $index => $item ) {
            if ( isset( $item['key'] ) && $item['key'] === $cart_item_key ) {
                return $index;
            }
        }

        return false;
    }
    
    /**
     * Get cart total count
     *
     * @param string $guest_token Guest token
     * @return int Total items count
     */
    public static function get_cart_count( string $guest_token ): int {
        $cart = self::get_cart( $guest_token );
        $count = 0;
        
        foreach ( $cart as $item ) {
            $count += isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
        }
        
        return $count;
    }
    
    /**
     * Remove one item from guest cart by product_id and optional variation_data
     *
     * @param string $guest_token   Guest token
     * @param int    $product_id    Product ID
     * @param array  $variation_data Variation data (optional, for variable products)
     * @return array|false Updated cart items or false on failure
     */
    public static function remove_item( string $guest_token, int $product_id, array $variation_data = array() ) {
        if ( empty( $guest_token ) || $product_id <= 0 ) {
            return false;
        }
        $cart  = self::get_cart( $guest_token );
        $index = self::find_item_by_product( $cart, $product_id, $variation_data );
        if ( $index === false ) {
            return $cart;
        }
        array_splice( $cart, $index, 1 );
        if ( self::save_cart( $guest_token, $cart ) ) {
            return $cart;
        }
        return false;
    }
    
    /**
     * Delete cart for guest token
     *
     * @param string $guest_token Guest token
     * @return bool Success status
     */
    public static function delete_cart( string $guest_token ): bool {
        if ( empty( $guest_token ) ) {
            return false;
        }
        
        $key = self::get_storage_key( $guest_token );
        return delete_option( $key );
    }
    
    /**
     * Get cart for user ID
     *
     * @param int $user_id User ID
     * @return array Cart items array
     */
    public static function get_user_cart( int $user_id ): array {
        if ( $user_id <= 0 ) {
            return array();
        }
        
        $key = self::get_user_storage_key( $user_id );
        $cart_data = get_user_meta( $user_id, 'aicommerce_cart', true );
        
        if ( ! is_array( $cart_data ) || empty( $cart_data ) ) {
            return array();
        }
        
        return isset( $cart_data['items'] ) ? $cart_data['items'] : array();
    }
    
    /**
     * Save cart for user ID
     *
     * @param int   $user_id User ID
     * @param array $items Cart items array
     * @return bool Success status
     */
    public static function save_user_cart( int $user_id, array $items ): bool {
        if ( $user_id <= 0 ) {
            return false;
        }
        
        $cart_data = array(
            'items'      => $items,
            'updated_at' => time(),
        );
        
        return update_user_meta( $user_id, 'aicommerce_cart', $cart_data );
    }
    
    /**
     * Add item to user cart
     *
     * @param int   $user_id User ID
     * @param int   $product_id Product ID
     * @param int   $quantity Quantity
     * @param array $variation_data Variation data (optional)
     * @return array|false Updated cart items or false on failure
     */
    public static function add_item_to_user_cart( int $user_id, int $product_id, int $quantity = 1, array $variation_data = array() ) {
        if ( $user_id <= 0 || $product_id <= 0 || $quantity <= 0 ) {
            return false;
        }

        $cart = self::get_user_cart( $user_id );

        $existing_index = self::find_item_by_product( $cart, $product_id, $variation_data );

        if ( $existing_index !== false ) {
            $cart[ $existing_index ]['quantity'] += $quantity;
        } else {
            $cart[] = array(
                'key'            => self::generate_cart_item_key( $product_id, $variation_data ),
                'product_id'     => $product_id,
                'quantity'       => $quantity,
                'variation_data' => $variation_data,
                'added_at'       => time(),
            );
        }

        if ( self::save_user_cart( $user_id, $cart ) ) {
            return $cart;
        }

        return false;
    }
    
    /**
     * Remove one item from user cart by product_id and optional variation_data
     *
     * @param int   $user_id        User ID
     * @param int   $product_id     Product ID
     * @param array $variation_data Variation data (optional, for variable products)
     * @return array|false Updated cart items or false on failure
     */
    public static function remove_item_from_user_cart( int $user_id, int $product_id, array $variation_data = array() ) {
        if ( $user_id <= 0 || $product_id <= 0 ) {
            return false;
        }
        $cart  = self::get_user_cart( $user_id );
        $index = self::find_item_by_product( $cart, $product_id, $variation_data );
        if ( $index === false ) {
            return $cart;
        }
        array_splice( $cart, $index, 1 );
        if ( self::save_user_cart( $user_id, $cart ) ) {
            return $cart;
        }
        return false;
    }
    
    /**
     * Get user cart total count
     *
     * @param int $user_id User ID
     * @return int Total items count
     */
    public static function get_user_cart_count( int $user_id ): int {
        $cart = self::get_user_cart( $user_id );
        $count = 0;
        
        foreach ( $cart as $item ) {
            $count += isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
        }
        
        return $count;
    }
    
    /**
     * Delete cart for user ID
     *
     * @param int $user_id User ID
     * @return bool Success status
     */
    public static function delete_user_cart( int $user_id ): bool {
        if ( $user_id <= 0 ) {
            return false;
        }

        return delete_user_meta( $user_id, 'aicommerce_cart' );
    }

    /**
     * Register the recurring cleanup action.
     * Called once on plugin init. Safe to call multiple times — AS deduplicates.
     */
    public static function register_cleanup(): void {
        add_action( 'aicommerce_cleanup_guest_carts', array( static::class, 'cleanup_expired_carts' ) );

        if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( 'aicommerce_cleanup_guest_carts', array(), 'aicommerce' ) ) {
            as_schedule_recurring_action( time() + 30 * DAY_IN_SECONDS, 30 * DAY_IN_SECONDS, 'aicommerce_cleanup_guest_carts', array(), 'aicommerce' );
        }
    }

    /**
     * Delete all expired guest cart rows from wp_options.
     * Triggered by Action Scheduler every 30 days.
     */
    public static function cleanup_expired_carts(): void {
        global $wpdb;

        $option_names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( self::OPTION_PREFIX ) . '%'
            )
        );

        if ( empty( $option_names ) ) {
            return;
        }

        $now = time();
        foreach ( $option_names as $option_name ) {
            $cart_data = get_option( $option_name );
            if ( isset( $cart_data['expires_at'] ) && $cart_data['expires_at'] < $now ) {
                delete_option( $option_name );
            }
        }
    }
}
