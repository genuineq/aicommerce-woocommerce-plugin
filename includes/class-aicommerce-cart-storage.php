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
     * Normalize guest cart data structure.
     *
     * @param mixed $cart_data Raw option value
     * @return array{items:array,updated_at:int,expires_at:int,version:int,count:int}
     */
    private static function normalize_guest_cart_data( $cart_data ): array {
        $now = time();

        if ( ! is_array( $cart_data ) ) {
            return array(
                'items'      => array(),
                'updated_at' => $now,
                'expires_at' => $now + self::CART_EXPIRATION,
                'version'    => 1,
                'count'      => 0,
            );
        }

        $items = isset( $cart_data['items'] ) && is_array( $cart_data['items'] ) ? $cart_data['items'] : array();
        $count = 0;
        foreach ( $items as $item ) {
            $count += isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
        }

        $version = isset( $cart_data['version'] ) ? (int) $cart_data['version'] : 1;
        if ( $version < 1 ) {
            $version = 1;
        }

        return array(
            'items'      => $items,
            'updated_at' => isset( $cart_data['updated_at'] ) ? (int) $cart_data['updated_at'] : $now,
            'expires_at' => isset( $cart_data['expires_at'] ) ? (int) $cart_data['expires_at'] : ( $now + self::CART_EXPIRATION ),
            'version'    => $version,
            'count'      => $count,
        );
    }

    /**
     * Compute a stable signature for cart items to detect changes.
     *
     * @param array $items Cart items
     * @return string
     */
    private static function compute_items_signature( array $items ): string {
        $normalized = array();

        foreach ( $items as $item ) {
            $product_id   = (int) ( $item['product_id'] ?? 0 );
            $quantity     = (int) ( $item['quantity'] ?? 0 );
            $variation_id = isset( $item['variation_data']['variation_id'] ) ? (int) $item['variation_data']['variation_id'] : 0;
            $variation    = isset( $item['variation_data'] ) && is_array( $item['variation_data'] ) ? $item['variation_data'] : array();

            if ( isset( $variation['variation_id'] ) ) {
                $variation['variation_id'] = (int) $variation['variation_id'];
            }
            ksort( $variation );

            $normalized[] = array(
                'product_id'   => $product_id,
                'variation_id' => $variation_id,
                'quantity'     => $quantity,
                'variation'    => $variation,
            );
        }

        usort(
            $normalized,
            static function ( $a, $b ) {
                if ( $a['product_id'] !== $b['product_id'] ) {
                    return $a['product_id'] <=> $b['product_id'];
                }
                if ( $a['variation_id'] !== $b['variation_id'] ) {
                    return $a['variation_id'] <=> $b['variation_id'];
                }
                return $a['quantity'] <=> $b['quantity'];
            }
        );

        return md5( wp_json_encode( $normalized ) );
    }

    /**
     * Normalize user cart data structure.
     *
     * @param mixed $cart_data Raw user_meta value
     * @return array{items:array,updated_at:int,version:int,count:int}
     */
    private static function normalize_user_cart_data( $cart_data ): array {
        $now = time();

        if ( ! is_array( $cart_data ) ) {
            return array(
                'items'      => array(),
                'updated_at' => $now,
                'version'    => 1,
                'count'      => 0,
            );
        }

        $items = isset( $cart_data['items'] ) && is_array( $cart_data['items'] ) ? $cart_data['items'] : array();
        $count = 0;
        foreach ( $items as $item ) {
            $count += isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
        }

        $version = isset( $cart_data['version'] ) ? (int) $cart_data['version'] : 1;
        if ( $version < 1 ) {
            $version = 1;
        }

        return array(
            'items'      => $items,
            'updated_at' => isset( $cart_data['updated_at'] ) ? (int) $cart_data['updated_at'] : $now,
            'version'    => $version,
            'count'      => $count,
        );
    }

    /**
     * Persist guest cart data (items + meta).
     *
     * @param string $guest_token Guest token
     * @param array  $cart_data   Normalized cart data
     * @return bool
     */
    private static function save_guest_cart_data( string $guest_token, array $cart_data ): bool {
        if ( empty( $guest_token ) ) {
            return false;
        }

        $key = self::get_storage_key( $guest_token );
        return update_option( $key, $cart_data, false );
    }
    
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

        $cart_data = self::normalize_guest_cart_data( $cart_data );

        // Check expiration
        if ( isset( $cart_data['expires_at'] ) && $cart_data['expires_at'] < time() ) {
            self::delete_cart( $guest_token );
            return array();
        }

        $items = $cart_data['items'];

        // Deduplicate items that share the same product_id + variation_id
        // (can accumulate if different key formats were stored over time)
        $deduped = self::deduplicate_items( $items );
        if ( count( $deduped ) !== count( $items ) ) {
            // Save without bumping the version; this is a normalization step.
            $cart_data['items'] = $deduped;
            $cart_data          = self::normalize_guest_cart_data( $cart_data );
            self::save_guest_cart_data( $guest_token, $cart_data );
        }

        return $deduped;
    }

    /**
     * Get guest cart meta (version + count) without loading products.
     *
     * @param string $guest_token Guest token
     * @return array{version:int,count:int}
     */
    public static function get_cart_meta( string $guest_token ): array {
        if ( empty( $guest_token ) ) {
            return array( 'version' => 0, 'count' => 0 );
        }

        $key       = self::get_storage_key( $guest_token );
        $cart_data = get_option( $key, null );
        if ( $cart_data === null ) {
            return array( 'version' => 0, 'count' => 0 );
        }

        $cart_data = self::normalize_guest_cart_data( $cart_data );

        if ( isset( $cart_data['expires_at'] ) && $cart_data['expires_at'] < time() ) {
            self::delete_cart( $guest_token );
            return array( 'version' => 0, 'count' => 0 );
        }

        return array(
            'version' => (int) $cart_data['version'],
            'count'   => (int) $cart_data['count'],
        );
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

        $key       = self::get_storage_key( $guest_token );
        $existing  = get_option( $key, null );
        $cart_data = self::normalize_guest_cart_data( $existing );

        $incoming_sig = self::compute_items_signature( $items );
        $existing_sig = isset( $existing['items_sig'] ) ? (string) $existing['items_sig'] : self::compute_items_signature( (array) $cart_data['items'] );

        $cart_data['items']      = $items;
        $cart_data['updated_at'] = time();
        $cart_data['expires_at'] = time() + self::CART_EXPIRATION;
        if ( $incoming_sig !== $existing_sig ) {
            $cart_data['version'] = (int) $cart_data['version'] + 1;
        }
        $cart_data['items_sig']  = $incoming_sig;
        $cart_data               = self::normalize_guest_cart_data( $cart_data );

        return self::save_guest_cart_data( $guest_token, $cart_data );
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

        $key       = self::get_storage_key( $guest_token );
        $existing  = get_option( $key, null );
        $cart_data = self::normalize_guest_cart_data( $existing );

        // Check expiration (avoid resurrecting expired carts)
        if ( isset( $cart_data['expires_at'] ) && $cart_data['expires_at'] < time() ) {
            self::delete_cart( $guest_token );
            $cart_data = self::normalize_guest_cart_data( null );
        }

        $cart = $cart_data['items'];

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

        $cart_data['items']      = $cart;
        $cart_data['updated_at'] = time();
        $cart_data['expires_at'] = time() + self::CART_EXPIRATION;
        $cart_data['version']    = (int) $cart_data['version'] + 1;
        $cart_data['items_sig']  = self::compute_items_signature( $cart );
        $cart_data               = self::normalize_guest_cart_data( $cart_data );

        if ( self::save_guest_cart_data( $guest_token, $cart_data ) ) {
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
        $meta = self::get_cart_meta( $guest_token );
        return (int) $meta['count'];
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
        $key       = self::get_storage_key( $guest_token );
        $existing  = get_option( $key, null );
        $cart_data = self::normalize_guest_cart_data( $existing );

        if ( isset( $cart_data['expires_at'] ) && $cart_data['expires_at'] < time() ) {
            self::delete_cart( $guest_token );
            return array();
        }

        $cart  = $cart_data['items'];
        $index = self::find_item_by_product( $cart, $product_id, $variation_data );
        if ( $index === false ) {
            return $cart;
        }
        array_splice( $cart, $index, 1 );

        $cart_data['items']      = $cart;
        $cart_data['updated_at'] = time();
        $cart_data['expires_at'] = time() + self::CART_EXPIRATION;
        $cart_data['version']    = (int) $cart_data['version'] + 1;
        $cart_data['items_sig']  = self::compute_items_signature( $cart );
        $cart_data               = self::normalize_guest_cart_data( $cart_data );

        if ( self::save_guest_cart_data( $guest_token, $cart_data ) ) {
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

        $cart_data = self::normalize_user_cart_data( $cart_data );
        return $cart_data['items'];
    }

    /**
     * Get user cart meta (version + count).
     *
     * @param int $user_id User ID
     * @return array{version:int,count:int}
     */
    public static function get_user_cart_meta( int $user_id ): array {
        if ( $user_id <= 0 ) {
            return array( 'version' => 0, 'count' => 0 );
        }

        $cart_data = get_user_meta( $user_id, 'aicommerce_cart', true );
        if ( ! is_array( $cart_data ) ) {
            return array( 'version' => 0, 'count' => 0 );
        }

        $cart_data = self::normalize_user_cart_data( $cart_data );
        return array(
            'version' => (int) $cart_data['version'],
            'count'   => (int) $cart_data['count'],
        );
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

        $existing  = get_user_meta( $user_id, 'aicommerce_cart', true );
        $cart_data = self::normalize_user_cart_data( $existing );

        $incoming_sig = self::compute_items_signature( $items );
        $existing_sig = isset( $existing['items_sig'] ) ? (string) $existing['items_sig'] : self::compute_items_signature( (array) $cart_data['items'] );

        $cart_data['items']      = $items;
        $cart_data['updated_at'] = time();
        if ( $incoming_sig !== $existing_sig ) {
            $cart_data['version'] = (int) $cart_data['version'] + 1;
        }
        $cart_data['items_sig']  = $incoming_sig;
        $cart_data               = self::normalize_user_cart_data( $cart_data );

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

        $existing  = get_user_meta( $user_id, 'aicommerce_cart', true );
        $cart_data = self::normalize_user_cart_data( $existing );
        $cart      = $cart_data['items'];

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

        $cart_data['items']      = $cart;
        $cart_data['updated_at'] = time();
        $cart_data['version']    = (int) $cart_data['version'] + 1;
        $cart_data['items_sig']  = self::compute_items_signature( $cart );
        $cart_data               = self::normalize_user_cart_data( $cart_data );

        if ( update_user_meta( $user_id, 'aicommerce_cart', $cart_data ) ) {
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
        $existing  = get_user_meta( $user_id, 'aicommerce_cart', true );
        $cart_data = self::normalize_user_cart_data( $existing );
        $cart      = $cart_data['items'];
        $index = self::find_item_by_product( $cart, $product_id, $variation_data );
        if ( $index === false ) {
            return $cart;
        }
        array_splice( $cart, $index, 1 );
        $cart_data['items']      = $cart;
        $cart_data['updated_at'] = time();
        $cart_data['version']    = (int) $cart_data['version'] + 1;
        $cart_data['items_sig']  = self::compute_items_signature( $cart );
        $cart_data               = self::normalize_user_cart_data( $cart_data );

        if ( update_user_meta( $user_id, 'aicommerce_cart', $cart_data ) ) {
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
        $meta = self::get_user_cart_meta( $user_id );
        return (int) $meta['count'];
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

        add_action( 'init', function () {
            if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( 'aicommerce_cleanup_guest_carts', array(), 'aicommerce' ) ) {
                as_schedule_recurring_action( time() + 30 * DAY_IN_SECONDS, 30 * DAY_IN_SECONDS, 'aicommerce_cleanup_guest_carts', array(), 'aicommerce' );
            }
        } );
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
