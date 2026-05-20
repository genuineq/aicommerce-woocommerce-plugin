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
     * Fallback cart expiration time in seconds, matching WooCommerce defaults.
     */
    private const DEFAULT_CART_EXPIRATION = 48 * HOUR_IN_SECONDS;

    /**
     * Get AICommerce guest cart expiration duration.
     *
     * @return int Expiration duration in seconds.
     */
    private static function get_cart_expiration(): int {
        $expiration = (int) get_option(
            defined( 'AICOMMERCE_CART_EXPIRATION_OPTION' ) ? AICOMMERCE_CART_EXPIRATION_OPTION : 'aicommerce_cart_expiration_seconds',
            self::DEFAULT_CART_EXPIRATION
        );

        return $expiration > 0 ? $expiration : self::DEFAULT_CART_EXPIRATION;
    }

    /**
     * Debug logging is intentionally disabled in production builds.
     */
    private static function log_debug( string $event, array $context = array() ): void {
        return;
    }

    /**
     * Normalize guest cart data structure.
     *
     * @param mixed $cart_data Raw option value
     * @return array{items:array,updated_at:int,expires_at:int,version:int,count:int}
     */
    private static function normalize_guest_cart_data( $cart_data ): array {
        /** Capture the current timestamp once so every default field uses the same baseline. */
        $now = time();

        /** Return a fully initialized empty guest cart when storage is missing or malformed. */
        if ( ! is_array( $cart_data ) ) {
            return array(
                'items'      => array(),
                'updated_at' => $now,
                'expires_at' => $now + self::get_cart_expiration(),
                'version'    => 1,
                'count'      => 0,
            );
        }

        /** Normalize the stored line items into an array even if storage was partially corrupted. */
        $items = isset( $cart_data['items'] ) && is_array( $cart_data['items'] ) ? $cart_data['items'] : array();

        /** Recompute item count from quantities so count stays trustworthy even for legacy payloads. */
        $count = 0;
        foreach ( $items as $item ) {
            $count += isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
        }

        /** Normalize version to a positive integer because version zero means "not initialized". */
        $version = isset( $cart_data['version'] ) ? (int) $cart_data['version'] : 1;
        if ( $version < 1 ) {
            $version = 1;
        }

        return array(
            'items'      => $items,
            'updated_at' => isset( $cart_data['updated_at'] ) ? (int) $cart_data['updated_at'] : $now,
            'expires_at' => isset( $cart_data['expires_at'] ) ? (int) $cart_data['expires_at'] : ( $now + self::get_cart_expiration() ),
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
        /** Build a normalized signature payload that is stable across key ordering differences. */
        $normalized = array();

        foreach ( $items as $item ) {
            /** Extract only the fields that matter for cart identity and quantity tracking. */
            $product_id   = (int) ( $item['product_id'] ?? 0 );
            $quantity     = (int) ( $item['quantity'] ?? 0 );
            $variation_id = isset( $item['variation_data']['variation_id'] ) ? (int) $item['variation_data']['variation_id'] : 0;
            $variation    = isset( $item['variation_data'] ) && is_array( $item['variation_data'] ) ? $item['variation_data'] : array();

            /** Normalize variation_id inside the variation array so equivalent lines hash the same. */
            if ( isset( $variation['variation_id'] ) ) {
                $variation['variation_id'] = (int) $variation['variation_id'];
            }

            /** Sort variation keys so attribute ordering differences do not change the signature. */
            ksort( $variation );

            $normalized[] = array(
                'product_id'   => $product_id,
                'variation_id' => $variation_id,
                'quantity'     => $quantity,
                'variation'    => $variation,
            );
        }

        /** Sort the normalized lines so signature generation is independent of item order. */
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

        /** Hash the normalized payload into a compact change-detection signature. */
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
        /** Reject empty guest tokens because they cannot map to a stable option row. */
        if ( empty( $guest_token ) ) {
            return false;
        }

        /** Persist the full normalized guest cart payload into the guest option row. */
        $key = self::get_storage_key( $guest_token );
        return update_option( $key, $cart_data, false );
    }
    
    /**
     * Get cart storage key for guest token
     */
    private static function get_storage_key( string $guest_token ): string {
        $guest_token = trim( $guest_token );

        /** Hash the token before storing it in option names so raw tokens do not appear in the database key. */
        $token_hash = hash( 'sha256', $guest_token );
        return self::OPTION_PREFIX . $token_hash;
    }

    /**
     * Get the guest cart option name for diagnostics.
     *
     * @param string $guest_token Guest token.
     * @return string Guest cart option name.
     */
    public static function get_guest_cart_option_name( string $guest_token ): string {
        if ( empty( trim( $guest_token ) ) ) {
            return '';
        }

        return self::get_storage_key( $guest_token );
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
        /** Empty tokens have no cart identity, so return an empty cart immediately. */
        if ( empty( $guest_token ) ) {
            return array();
        }

        /** Load the raw guest cart row from wp_options. */
        $key = self::get_storage_key( $guest_token );
        $cart_data = get_option( $key, null );

        /** Missing storage means the guest cart simply does not exist yet. */
        if ( $cart_data === null ) {
            self::log_debug(
                'get_cart:missing_storage',
                array(
                    'storage_key' => $key,
                )
            );
            return array();
        }

        /** Normalize the payload before any expiration or deduplication logic runs. */
        $cart_data = self::normalize_guest_cart_data( $cart_data );

        /** Delete expired guest carts eagerly so stale carts cannot be resurrected later. */
        if ( isset( $cart_data['expires_at'] ) && $cart_data['expires_at'] < time() ) {
            self::log_debug(
                'get_cart:expired_storage',
                array(
                    'storage_key'        => $key,
                    'expires_at'         => (int) $cart_data['expires_at'],
                    'now'                => time(),
                    'expiration_seconds' => self::get_cart_expiration(),
                )
            );
            self::delete_cart( $guest_token );
            return array();
        }

        /** Read the normalized line items from storage. */
        $items = $cart_data['items'];

        /** Deduplicate legacy duplicates that may have accumulated under older key formats. */
        $deduped = self::deduplicate_items( $items );
        if ( count( $deduped ) !== count( $items ) ) {
            /** Save the normalized payload back without bumping version because content did not semantically change. */
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
     * @return array{version:int,count:int,fingerprint:string}
     */
    public static function get_cart_meta( string $guest_token ): array {
        /** Empty guest identities always resolve to an empty meta snapshot. */
        if ( empty( $guest_token ) ) {
            return array( 'version' => 0, 'count' => 0, 'fingerprint' => '' );
        }

        /** Load the raw guest cart payload to avoid loading products or rebuilding lines. */
        $key       = self::get_storage_key( $guest_token );
        $cart_data = get_option( $key, null );
        if ( $cart_data === null ) {
            return array( 'version' => 0, 'count' => 0, 'fingerprint' => '' );
        }

        /** Normalize before reading version/count so legacy rows remain compatible. */
        $cart_data = self::normalize_guest_cart_data( $cart_data );

        /** Expired carts report empty meta and are deleted immediately. */
        if ( isset( $cart_data['expires_at'] ) && $cart_data['expires_at'] < time() ) {
            self::delete_cart( $guest_token );
            return array( 'version' => 0, 'count' => 0, 'fingerprint' => '' );
        }

        return array(
            'version'     => (int) $cart_data['version'],
            'count'       => (int) $cart_data['count'],
            'fingerprint' => isset( $cart_data['items_sig'] ) ? (string) $cart_data['items_sig'] : self::compute_items_signature( (array) $cart_data['items'] ),
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
        /** Reject writes for empty guest identities. */
        if ( empty( $guest_token ) ) {
            return false;
        }

        /** Load the existing payload so versioning can be based on semantic item changes only. */
        $key       = self::get_storage_key( $guest_token );
        $existing  = get_option( $key, null );
        $cart_data = self::normalize_guest_cart_data( $existing );

        /** Compare normalized item signatures to decide whether version should be bumped. */
        $incoming_sig = self::compute_items_signature( $items );
        $existing_sig = isset( $existing['items_sig'] ) ? (string) $existing['items_sig'] : self::compute_items_signature( (array) $cart_data['items'] );

        /** Replace the item list and refresh guest cart timestamps. */
        $cart_data['items']      = $items;
        $cart_data['updated_at'] = time();
        $cart_data['expires_at'] = time() + self::get_cart_expiration();

        /** Bump version only when the semantic cart content actually changed. */
        if ( $incoming_sig !== $existing_sig ) {
            $cart_data['version'] = (int) $cart_data['version'] + 1;
        }

        /** Store the new signature so future saves can detect no-op writes cheaply. */
        $cart_data['items_sig']  = $incoming_sig;
        $cart_data               = self::normalize_guest_cart_data( $cart_data );

        /** Persist the normalized guest cart payload. */
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
        /** Reject incomplete or invalid add requests before touching storage. */
        if ( empty( $guest_token ) || $product_id <= 0 || $quantity <= 0 ) {
            return false;
        }

        /** Load and normalize the current guest cart payload. */
        $key       = self::get_storage_key( $guest_token );
        $existing  = get_option( $key, null );
        $cart_data = self::normalize_guest_cart_data( $existing );

        /** Expired carts are reset instead of being silently resurrected. */
        if ( isset( $cart_data['expires_at'] ) && $cart_data['expires_at'] < time() ) {
            self::delete_cart( $guest_token );
            $cart_data = self::normalize_guest_cart_data( null );
        }

        /** Work on the normalized in-memory line items. */
        $cart = $cart_data['items'];

        /** Match by product and variation identity instead of stored key format. */
        $existing_index = self::find_item_by_product( $cart, $product_id, $variation_data );

        if ( $existing_index !== false ) {
            /** Increase quantity when the same logical cart line already exists. */
            $cart[ $existing_index ]['quantity'] += $quantity;
        } else {
            /** Append a brand-new logical cart line for the requested product and variation. */
            $cart[] = array(
                'key'            => self::generate_cart_item_key( $product_id, $variation_data ),
                'product_id'     => $product_id,
                'quantity'       => $quantity,
                'variation_data' => $variation_data,
                'added_at'       => time(),
            );
        }

        /** Persist the updated guest cart and force a version bump because content changed. */
        $cart_data['items']      = $cart;
        $cart_data['updated_at'] = time();
        $cart_data['expires_at'] = time() + self::get_cart_expiration();
        $cart_data['version']    = (int) $cart_data['version'] + 1;
        $cart_data['items_sig']  = self::compute_items_signature( $cart );
        $cart_data               = self::normalize_guest_cart_data( $cart_data );

        /** Return the updated in-memory cart when persistence succeeds. */
        if ( self::save_guest_cart_data( $guest_token, $cart_data ) ) {
            return $cart;
        }

        /** Signal persistence failure to the caller. */
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
        /** Simple products use a compact key that depends only on product ID. */
        if ( empty( $variation_data ) ) {
            return 'simple_' . $product_id;
        }

        /** Variable lines hash normalized variation data so equivalent combinations share the same key. */
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
        /** Reduce matching to product identity plus concrete variation ID. */
        $variation_id = isset( $variation_data['variation_id'] ) ? (int) $variation_data['variation_id'] : 0;

        foreach ( $cart as $index => $item ) {
            /** Skip lines that belong to a different product. */
            if ( (int) ( $item['product_id'] ?? 0 ) !== $product_id ) {
                continue;
            }

            /** Compare variation IDs only after product IDs already match. */
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
        /** Keep a lookup from logical line identity to its position in the deduplicated result. */
        $seen   = array(); // "product_id:variation_id" => index in $result
        $result = array();

        foreach ( $items as $item ) {
            /** Build the logical identity used to merge duplicate legacy rows. */
            $product_id   = (int) ( $item['product_id'] ?? 0 );
            $variation_id = isset( $item['variation_data']['variation_id'] )
                ? (int) $item['variation_data']['variation_id']
                : 0;
            $sig = $product_id . ':' . $variation_id;

            if ( isset( $seen[ $sig ] ) ) {
                /** Merge quantity into the first canonical occurrence of the same logical line. */
                $result[ $seen[ $sig ] ]['quantity'] += (int) ( $item['quantity'] ?? 0 );
            } else {
                /** Record the first occurrence of the logical line as the canonical row. */
                $seen[ $sig ]  = count( $result );
                $result[]      = $item;
            }
        }

        return array_values( $result );
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
        /** Reject invalid remove requests before loading storage. */
        if ( empty( $guest_token ) || $product_id <= 0 ) {
            return false;
        }

        /** Load and normalize the current guest cart payload. */
        $key       = self::get_storage_key( $guest_token );
        $existing  = get_option( $key, null );
        $cart_data = self::normalize_guest_cart_data( $existing );

        /** Expired carts are deleted and treated as already empty. */
        if ( isset( $cart_data['expires_at'] ) && $cart_data['expires_at'] < time() ) {
            self::delete_cart( $guest_token );
            return array();
        }

        /** Try to locate the logical line targeted by the remove request. */
        $cart  = $cart_data['items'];
        $index = self::find_item_by_product( $cart, $product_id, $variation_data );
        if ( $index === false ) {
            /** Removing a missing line is a no-op and returns the current cart unchanged. */
            return $cart;
        }

        /** Remove the matched line from the in-memory cart payload. */
        array_splice( $cart, $index, 1 );

        /** Persist the updated cart and bump version because content changed. */
        $cart_data['items']      = $cart;
        $cart_data['updated_at'] = time();
        $cart_data['expires_at'] = time() + self::get_cart_expiration();
        $cart_data['version']    = (int) $cart_data['version'] + 1;
        $cart_data['items_sig']  = self::compute_items_signature( $cart );
        $cart_data               = self::normalize_guest_cart_data( $cart_data );

        /** Return the updated cart only when persistence succeeds. */
        if ( self::save_guest_cart_data( $guest_token, $cart_data ) ) {
            return $cart;
        }

        /** Signal persistence failure to the caller. */
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
     * @return array{version:int,count:int,fingerprint:string}
     */
    public static function get_user_cart_meta( int $user_id ): array {
        if ( $user_id <= 0 ) {
            return array( 'version' => 0, 'count' => 0, 'fingerprint' => '' );
        }

        $cart_data = get_user_meta( $user_id, 'aicommerce_cart', true );
        if ( ! is_array( $cart_data ) ) {
            return array( 'version' => 0, 'count' => 0, 'fingerprint' => '' );
        }

        $cart_data = self::normalize_user_cart_data( $cart_data );
        return array(
            'version'     => (int) $cart_data['version'],
            'count'       => (int) $cart_data['count'],
            'fingerprint' => isset( $cart_data['items_sig'] ) ? (string) $cart_data['items_sig'] : self::compute_items_signature( (array) $cart_data['items'] ),
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
    public static function mark_as_ai_cart( string $guest_token ): void {
        if ( empty( $guest_token ) ) {
            return;
        }
        $key       = self::get_storage_key( $guest_token );
        $cart_data = get_option( $key, null );
        if ( is_array( $cart_data ) && empty( $cart_data['ai_cart'] ) ) {
            $cart_data['ai_cart'] = true;
            update_option( $key, $cart_data, false );
        }
    }

    public static function has_ai_flag( string $guest_token ): bool {
        if ( empty( $guest_token ) ) {
            return false;
        }
        $key       = self::get_storage_key( $guest_token );
        $cart_data = get_option( $key, null );
        return is_array( $cart_data ) && ! empty( $cart_data['ai_cart'] );
    }

    public static function mark_as_ai_user_cart( int $user_id ): void {
        if ( $user_id <= 0 ) {
            return;
        }
        $cart_data = get_user_meta( $user_id, 'aicommerce_cart', true );
        if ( is_array( $cart_data ) && empty( $cart_data['ai_cart'] ) ) {
            $cart_data['ai_cart'] = true;
            update_user_meta( $user_id, 'aicommerce_cart', $cart_data );
        }
    }

    public static function has_ai_user_flag( int $user_id ): bool {
        if ( $user_id <= 0 ) {
            return false;
        }
        $cart_data = get_user_meta( $user_id, 'aicommerce_cart', true );
        return is_array( $cart_data ) && ! empty( $cart_data['ai_cart'] );
    }

    public static function register_cleanup(): void {
        add_action( 'aicommerce_cleanup_guest_carts', array( static::class, 'cleanup_expired_carts' ) );

        add_action( 'init', function () {
            if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( 'aicommerce_cleanup_guest_carts', array(), 'aicommerce' ) ) {
                as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, 'aicommerce_cleanup_guest_carts', array(), 'aicommerce' );
            }
        } );
    }

    /**
     * Delete all expired guest cart rows from wp_options.
     * Triggered by Action Scheduler daily.
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
