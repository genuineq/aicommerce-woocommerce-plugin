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
        
        return isset( $cart_data['items'] ) ? $cart_data['items'] : array();
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
        
        // Generate unique cart item key
        $cart_item_key = self::generate_cart_item_key( $product_id, $variation_data );
        
        // Check if item already exists
        $existing_index = self::find_item_index( $cart, $cart_item_key );
        
        if ( $existing_index !== false ) {
            // Update quantity
            $cart[ $existing_index ]['quantity'] += $quantity;
        } else {
            // Add new item
            $cart[] = array(
                'key'            => $cart_item_key,
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
     * Find item index in cart by cart item key
     *
     * @param array  $cart Cart items
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
        
        $cart_item_key = self::generate_cart_item_key( $product_id, $variation_data );
        
        $existing_index = self::find_item_index( $cart, $cart_item_key );
        
        if ( $existing_index !== false ) {
            $cart[ $existing_index ]['quantity'] += $quantity;
        } else {
            $cart[] = array(
                'key'            => $cart_item_key,
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
}
