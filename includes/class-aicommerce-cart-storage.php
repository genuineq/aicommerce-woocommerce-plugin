<?php
/**
 * Cart Storage by Guest Token
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Cart storage class. Manages cart storage by guest_token or user_id. */
class CartStorage {

    /** Option prefix for guest cart storage. */
    private const OPTION_PREFIX = 'aicommerce_guest_cart_';

    /** Option prefix for user cart storage. */
    private const USER_CART_PREFIX = 'aicommerce_user_cart_';

    /** Cart expiration time in seconds (30 days). */
    private const CART_EXPIRATION = 30 * DAY_IN_SECONDS;

    /**
     * Normalize the guest cart data structure.
     *
     * @param mixed $cart_data The raw option value.
     * @return array{items:array,updated_at:int,expires_at:int,version:int,count:int}
     */
    private static function normalize_guest_cart_data( $cart_data ): array {
        /** Get the current timestamp. */
        $now = time();

        /** Return a default guest cart structure when the stored value is invalid. */
        if ( ! is_array( $cart_data ) ) {
            return array(
                /** Store an empty cart item list. */
                'items'      => array(),

                /** Store the current update timestamp. */
                'updated_at' => $now,

                /** Store the expiration timestamp. */
                'expires_at' => $now + self::CART_EXPIRATION,

                /** Start the version at 1. */
                'version'    => 1,

                /** Start the item count at 0. */
                'count'      => 0,
            );
        }

        /** Extract the items array from the stored cart data. */
        $items = isset( $cart_data['items'] ) && is_array( $cart_data['items'] ) ? $cart_data['items'] : array();

        /** Initialize the cart item count. */
        $count = 0;

        /** Sum all item quantities to compute the cart count. */
        foreach ( $items as $item ) {
            $count += isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
        }

        /** Extract the cart version from the stored cart data. */
        $version = isset( $cart_data['version'] ) ? (int) $cart_data['version'] : 1;

        /** Enforce a minimum version of 1. */
        if ( $version < 1 ) {
            $version = 1;
        }

        /** Return the normalized guest cart structure. */
        return array(
            /** Store normalized cart items. */
            'items'      => $items,

            /** Store the last update timestamp. */
            'updated_at' => isset( $cart_data['updated_at'] ) ? (int) $cart_data['updated_at'] : $now,

            /** Store the expiration timestamp. */
            'expires_at' => isset( $cart_data['expires_at'] ) ? (int) $cart_data['expires_at'] : ( $now + self::CART_EXPIRATION ),

            /** Store the normalized cart version. */
            'version'    => $version,

            /** Store the computed cart count. */
            'count'      => $count,
        );
    }

    /**
     * Compute a stable signature for cart items to detect changes.
     *
     * @param array $items The cart items.
     * @return string
     */
    private static function compute_items_signature( array $items ): string {
        /** Initialize the normalized cart item list. */
        $normalized = array();

        /** Normalize each cart item into a stable structure for hashing. */
        foreach ( $items as $item ) {
            /** Extract the product ID. */
            $product_id   = (int) ( $item['product_id'] ?? 0 );

            /** Extract the quantity. */
            $quantity     = (int) ( $item['quantity'] ?? 0 );

            /** Extract the variation ID when present. */
            $variation_id = isset( $item['variation_data']['variation_id'] ) ? (int) $item['variation_data']['variation_id'] : 0;

            /** Extract the variation data array. */
            $variation    = isset( $item['variation_data'] ) && is_array( $item['variation_data'] ) ? $item['variation_data'] : array();

            /** Normalize the variation_id inside the variation data. */
            if ( isset( $variation['variation_id'] ) ) {
                $variation['variation_id'] = (int) $variation['variation_id'];
            }

            /** Sort variation keys for stable hashing. */
            ksort( $variation );

            /** Append the normalized item structure. */
            $normalized[] = array(
                /** Store the product ID. */
                'product_id'   => $product_id,

                /** Store the variation ID. */
                'variation_id' => $variation_id,

                /** Store the quantity. */
                'quantity'     => $quantity,

                /** Store normalized variation data. */
                'variation'    => $variation,
            );
        }

        /** Sort normalized items for deterministic hashing. */
        usort(
            $normalized,
            static function ( $a, $b ) {
                /** Compare by product ID first. */
                if ( $a['product_id'] !== $b['product_id'] ) {
                    return $a['product_id'] <=> $b['product_id'];
                }

                /** Compare by variation ID second. */
                if ( $a['variation_id'] !== $b['variation_id'] ) {
                    return $a['variation_id'] <=> $b['variation_id'];
                }

                /** Compare by quantity last. */
                return $a['quantity'] <=> $b['quantity'];
            }
        );

        /** Return the MD5 hash of the normalized item structure. */
        return md5( wp_json_encode( $normalized ) );
    }

    /**
     * Normalize the user cart data structure.
     *
     * @param mixed $cart_data The raw user meta value.
     * @return array{items:array,updated_at:int,version:int,count:int}
     */
    private static function normalize_user_cart_data( $cart_data ): array {
        /** Get the current timestamp. */
        $now = time();

        /** Return a default user cart structure when the stored value is invalid. */
        if ( ! is_array( $cart_data ) ) {
            return array(
                /** Store an empty cart item list. */
                'items'      => array(),

                /** Store the current update timestamp. */
                'updated_at' => $now,

                /** Start the version at 1. */
                'version'    => 1,

                /** Start the item count at 0. */
                'count'      => 0,
            );
        }

        /** Extract the items array from the stored cart data. */
        $items = isset( $cart_data['items'] ) && is_array( $cart_data['items'] ) ? $cart_data['items'] : array();

        /** Initialize the cart item count. */
        $count = 0;

        /** Sum all item quantities to compute the cart count. */
        foreach ( $items as $item ) {
            $count += isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
        }

        /** Extract the cart version from the stored cart data. */
        $version = isset( $cart_data['version'] ) ? (int) $cart_data['version'] : 1;

        /** Enforce a minimum version of 1. */
        if ( $version < 1 ) {
            $version = 1;
        }

        /** Return the normalized user cart structure. */
        return array(
            /** Store normalized cart items. */
            'items'      => $items,

            /** Store the last update timestamp. */
            'updated_at' => isset( $cart_data['updated_at'] ) ? (int) $cart_data['updated_at'] : $now,

            /** Store the normalized cart version. */
            'version'    => $version,

            /** Store the computed cart count. */
            'count'      => $count,
        );
    }

    /**
     * Persist guest cart data including items and metadata.
     *
     * @param string $guest_token The guest token.
     * @param array  $cart_data The normalized cart data.
     * @return bool
     */
    private static function save_guest_cart_data( string $guest_token, array $cart_data ): bool {
        /** Return false when the guest token is empty. */
        if ( empty( $guest_token ) ) {
            return false;
        }

        /** Resolve the option key used for this guest cart. */
        $key = self::get_storage_key( $guest_token );

        /** Persist the guest cart data in wp_options without autoload. */
        return update_option( $key, $cart_data, false );
    }

    /**
     * Get the cart storage key for a guest token.
     *
     * @param string $guest_token The guest token.
     * @return string
     */
    private static function get_storage_key( string $guest_token ): string {
        /** Hash the guest token for safe storage key generation. */
        $token_hash = hash( 'sha256', $guest_token );

        /** Return the final guest cart option key. */
        return self::OPTION_PREFIX . $token_hash;
    }

    /**
     * Get the cart storage key for a user ID.
     *
     * @param int $user_id The user ID.
     * @return string
     */
    private static function get_user_storage_key( int $user_id ): string {
        /** Return the final user cart storage key. */
        return self::USER_CART_PREFIX . $user_id;
    }

    /**
     * Get the cart for a guest token.
     *
     * @param string $guest_token The guest token.
     * @return array
     */
    public static function get_cart( string $guest_token ): array {
        /** Return an empty cart when the guest token is empty. */
        if ( empty( $guest_token ) ) {
            return array();
        }

        /** Resolve the guest cart option key. */
        $key = self::get_storage_key( $guest_token );

        /** Load the guest cart data from storage. */
        $cart_data = get_option( $key, null );

        /** Return an empty cart when no guest cart exists. */
        if ( $cart_data === null ) {
            return array();
        }

        /** Normalize the loaded guest cart data. */
        $cart_data = self::normalize_guest_cart_data( $cart_data );

        /** Delete and return an empty cart when the guest cart has expired. */
        if ( isset( $cart_data['expires_at'] ) && $cart_data['expires_at'] < time() ) {
            self::delete_cart( $guest_token );
            return array();
        }

        /** Extract the cart items from normalized cart data. */
        $items = $cart_data['items'];

        /** Deduplicate cart items that represent the same product and variation. */
        $deduped = self::deduplicate_items( $items );

        /** Save deduplicated cart items when normalization changed the item count. */
        if ( count( $deduped ) !== count( $items ) ) {
            /** Replace items with the deduplicated list. */
            $cart_data['items'] = $deduped;

            /** Re-normalize the guest cart data after item replacement. */
            $cart_data          = self::normalize_guest_cart_data( $cart_data );

            /** Persist the normalized guest cart without intentionally bumping the version. */
            self::save_guest_cart_data( $guest_token, $cart_data );
        }

        /** Return the deduplicated guest cart items. */
        return $deduped;
    }

    /**
     * Get guest cart metadata without loading products.
     *
     * @param string $guest_token The guest token.
     * @return array{version:int,count:int}
     */
    public static function get_cart_meta( string $guest_token ): array {
        /** Return empty metadata when the guest token is empty. */
        if ( empty( $guest_token ) ) {
            return array( 'version' => 0, 'count' => 0 );
        }

        /** Resolve the guest cart option key. */
        $key       = self::get_storage_key( $guest_token );

        /** Load the guest cart data from storage. */
        $cart_data = get_option( $key, null );

        /** Return empty metadata when no guest cart exists. */
        if ( $cart_data === null ) {
            return array( 'version' => 0, 'count' => 0 );
        }

        /** Normalize the loaded guest cart data. */
        $cart_data = self::normalize_guest_cart_data( $cart_data );

        /** Delete and return empty metadata when the guest cart has expired. */
        if ( isset( $cart_data['expires_at'] ) && $cart_data['expires_at'] < time() ) {
            self::delete_cart( $guest_token );
            return array( 'version' => 0, 'count' => 0 );
        }

        /** Return the guest cart version and item count. */
        return array(
            /** Include the normalized cart version. */
            'version' => (int) $cart_data['version'],

            /** Include the normalized cart count. */
            'count'   => (int) $cart_data['count'],
        );
    }

    /**
     * Save the cart for a guest token.
     *
     * @param string $guest_token The guest token.
     * @param array  $items The cart items array.
     * @return bool
     */
    public static function save_cart( string $guest_token, array $items ): bool {
        /** Return false when the guest token is empty. */
        if ( empty( $guest_token ) ) {
            return false;
        }

        /** Resolve the guest cart option key. */
        $key       = self::get_storage_key( $guest_token );

        /** Load the existing guest cart data. */
        $existing  = get_option( $key, null );

        /** Normalize the existing guest cart data. */
        $cart_data = self::normalize_guest_cart_data( $existing );

        /** Compute the signature of the incoming cart items. */
        $incoming_sig = self::compute_items_signature( $items );

        /** Compute or reuse the existing cart item signature. */
        $existing_sig = isset( $existing['items_sig'] ) ? (string) $existing['items_sig'] : self::compute_items_signature( (array) $cart_data['items'] );

        /** Replace the cart items with the incoming list. */
        $cart_data['items']      = $items;

        /** Update the cart timestamp. */
        $cart_data['updated_at'] = time();

        /** Refresh the guest cart expiration timestamp. */
        $cart_data['expires_at'] = time() + self::CART_EXPIRATION;

        /** Increment the cart version only when the item signature changed. */
        if ( $incoming_sig !== $existing_sig ) {
            $cart_data['version'] = (int) $cart_data['version'] + 1;
        }

        /** Store the new cart item signature. */
        $cart_data['items_sig']  = $incoming_sig;

        /** Normalize the updated guest cart data. */
        $cart_data               = self::normalize_guest_cart_data( $cart_data );

        /** Persist the updated guest cart data. */
        return self::save_guest_cart_data( $guest_token, $cart_data );
    }

    /**
     * Add an item to a guest cart.
     *
     * @param string $guest_token The guest token.
     * @param int    $product_id The product ID.
     * @param int    $quantity The quantity.
     * @param array  $variation_data Optional variation data.
     * @return array|false
     */
    public static function add_item( string $guest_token, int $product_id, int $quantity = 1, array $variation_data = array() ) {
        /** Return false when required add-item inputs are invalid. */
        if ( empty( $guest_token ) || $product_id <= 0 || $quantity <= 0 ) {
            return false;
        }

        /** Resolve the guest cart option key. */
        $key       = self::get_storage_key( $guest_token );

        /** Load the existing guest cart data. */
        $existing  = get_option( $key, null );

        /** Normalize the existing guest cart data. */
        $cart_data = self::normalize_guest_cart_data( $existing );

        /** Delete and reset the cart when the existing guest cart has expired. */
        if ( isset( $cart_data['expires_at'] ) && $cart_data['expires_at'] < time() ) {
            self::delete_cart( $guest_token );
            $cart_data = self::normalize_guest_cart_data( null );
        }

        /** Extract the current cart items. */
        $cart = $cart_data['items'];

        /** Find an existing item matching the product and variation. */
        $existing_index = self::find_item_by_product( $cart, $product_id, $variation_data );

        /** Increase quantity when the item already exists in the cart. */
        if ( $existing_index !== false ) {
            $cart[ $existing_index ]['quantity'] += $quantity;
        } else {
            /** Append a new cart item when no matching item exists. */
            $cart[] = array(
                /** Store the generated cart item key. */
                'key'            => self::generate_cart_item_key( $product_id, $variation_data ),

                /** Store the product ID. */
                'product_id'     => $product_id,

                /** Store the quantity. */
                'quantity'       => $quantity,

                /** Store variation data. */
                'variation_data' => $variation_data,

                /** Store the timestamp when the item was added. */
                'added_at'       => time(),
            );
        }

        /** Store the updated item list. */
        $cart_data['items']      = $cart;

        /** Update the cart timestamp. */
        $cart_data['updated_at'] = time();

        /** Refresh the guest cart expiration timestamp. */
        $cart_data['expires_at'] = time() + self::CART_EXPIRATION;

        /** Increment the cart version after a cart mutation. */
        $cart_data['version']    = (int) $cart_data['version'] + 1;

        /** Recompute the cart item signature. */
        $cart_data['items_sig']  = self::compute_items_signature( $cart );

        /** Normalize the updated guest cart data. */
        $cart_data               = self::normalize_guest_cart_data( $cart_data );

        /** Return the updated cart when the save succeeds. */
        if ( self::save_guest_cart_data( $guest_token, $cart_data ) ) {
            return $cart;
        }

        /** Return false when the save fails. */
        return false;
    }

    /**
     * Generate a cart item key.
     *
     * @param int   $product_id The product ID.
     * @param array $variation_data The variation data.
     * @return string
     */
    private static function generate_cart_item_key( int $product_id, array $variation_data = array() ): string {
        /** Return a simple key for non-variable products. */
        if ( empty( $variation_data ) ) {
            return 'simple_' . $product_id;
        }

        /** Sort variation data keys for deterministic hashing. */
        ksort( $variation_data );

        /** Hash the variation data payload. */
        $variation_string = md5( wp_json_encode( $variation_data ) );

        /** Return the variation-based cart item key. */
        return 'variation_' . $product_id . '_' . $variation_string;
    }

    /**
     * Find the index of a cart item by product_id and variation_id.
     *
     * @param array $cart The cart items.
     * @param int   $product_id The product ID.
     * @param array $variation_data The variation data.
     * @return int|false
     */
    private static function find_item_by_product( array $cart, int $product_id, array $variation_data = array() ) {
        /** Extract the requested variation ID. */
        $variation_id = isset( $variation_data['variation_id'] ) ? (int) $variation_data['variation_id'] : 0;

        /** Search for a matching item in the cart. */
        foreach ( $cart as $index => $item ) {
            /** Skip items whose product ID does not match. */
            if ( (int) ( $item['product_id'] ?? 0 ) !== $product_id ) {
                continue;
            }

            /** Extract the item's variation ID. */
            $item_variation_id = isset( $item['variation_data']['variation_id'] )
                ? (int) $item['variation_data']['variation_id']
                : 0;

            /** Return the item index when product and variation both match. */
            if ( $item_variation_id === $variation_id ) {
                return $index;
            }
        }

        /** Return false when no matching item was found. */
        return false;
    }

    /**
     * Merge cart entries that share the same product_id and variation_id.
     *
     * @param array $items The raw cart items.
     * @return array
     */
    private static function deduplicate_items( array $items ): array {
        /** Initialize the signature-to-index lookup table. */
        $seen   = array();

        /** Initialize the deduplicated result list. */
        $result = array();

        /** Iterate through each cart item. */
        foreach ( $items as $item ) {
            /** Extract the product ID. */
            $product_id   = (int) ( $item['product_id'] ?? 0 );

            /** Extract the variation ID when present. */
            $variation_id = isset( $item['variation_data']['variation_id'] )
                ? (int) $item['variation_data']['variation_id']
                : 0;

            /** Build the deduplication signature. */
            $sig = $product_id . ':' . $variation_id;

            /** Merge quantity into the first matching item when the signature already exists. */
            if ( isset( $seen[ $sig ] ) ) {
                $result[ $seen[ $sig ] ]['quantity'] += (int) ( $item['quantity'] ?? 0 );
            } else {
                /** Store the index of the first occurrence for this signature. */
                $seen[ $sig ]  = count( $result );

                /** Append the new unique item to the result list. */
                $result[]      = $item;
            }
        }

        /** Return the deduplicated item list with normalized array indexes. */
        return array_values( $result );
    }

    /**
     * Find the index of a cart item by cart item key.
     *
     * @param array  $cart The cart items.
     * @param string $cart_item_key The cart item key.
     * @return int|false
     */
    private static function find_item_index( array $cart, string $cart_item_key ) {
        /** Search for a cart item with the requested key. */
        foreach ( $cart as $index => $item ) {
            /** Return the index when the cart key matches. */
            if ( isset( $item['key'] ) && $item['key'] === $cart_item_key ) {
                return $index;
            }
        }

        /** Return false when no matching cart item key was found. */
        return false;
    }

    /**
     * Get the total cart count for a guest token.
     *
     * @param string $guest_token The guest token.
     * @return int
     */
    public static function get_cart_count( string $guest_token ): int {
        /** Load cart metadata for the guest token. */
        $meta = self::get_cart_meta( $guest_token );

        /** Return the cart count from metadata. */
        return (int) $meta['count'];
    }

    /**
     * Remove one item from a guest cart by product_id and optional variation_data.
     *
     * @param string $guest_token The guest token.
     * @param int    $product_id The product ID.
     * @param array  $variation_data Optional variation data.
     * @return array|false
     */
    public static function remove_item( string $guest_token, int $product_id, array $variation_data = array() ) {
        /** Return false when required remove-item inputs are invalid. */
        if ( empty( $guest_token ) || $product_id <= 0 ) {
            return false;
        }

        /** Resolve the guest cart option key. */
        $key       = self::get_storage_key( $guest_token );

        /** Load the existing guest cart data. */
        $existing  = get_option( $key, null );

        /** Normalize the existing guest cart data. */
        $cart_data = self::normalize_guest_cart_data( $existing );

        /** Delete and return an empty cart when the guest cart has expired. */
        if ( isset( $cart_data['expires_at'] ) && $cart_data['expires_at'] < time() ) {
            self::delete_cart( $guest_token );
            return array();
        }

        /** Extract the current cart items. */
        $cart  = $cart_data['items'];

        /** Find the matching item in the cart. */
        $index = self::find_item_by_product( $cart, $product_id, $variation_data );

        /** Return the unchanged cart when the item does not exist. */
        if ( $index === false ) {
            return $cart;
        }

        /** Remove the matching item from the cart. */
        array_splice( $cart, $index, 1 );

        /** Store the updated item list. */
        $cart_data['items']      = $cart;

        /** Update the cart timestamp. */
        $cart_data['updated_at'] = time();

        /** Refresh the guest cart expiration timestamp. */
        $cart_data['expires_at'] = time() + self::CART_EXPIRATION;

        /** Increment the cart version after a cart mutation. */
        $cart_data['version']    = (int) $cart_data['version'] + 1;

        /** Recompute the cart item signature. */
        $cart_data['items_sig']  = self::compute_items_signature( $cart );

        /** Normalize the updated guest cart data. */
        $cart_data               = self::normalize_guest_cart_data( $cart_data );

        /** Return the updated cart when the save succeeds. */
        if ( self::save_guest_cart_data( $guest_token, $cart_data ) ) {
            return $cart;
        }

        /** Return false when the save fails. */
        return false;
    }

    /**
     * Delete the cart for a guest token.
     *
     * @param string $guest_token The guest token.
     * @return bool
     */
    public static function delete_cart( string $guest_token ): bool {
        /** Return false when the guest token is empty. */
        if ( empty( $guest_token ) ) {
            return false;
        }

        /** Resolve the guest cart option key. */
        $key = self::get_storage_key( $guest_token );

        /** Delete the stored guest cart option. */
        return delete_option( $key );
    }

    /**
     * Get the cart for a user ID.
     *
     * @param int $user_id The user ID.
     * @return array
     */
    public static function get_user_cart( int $user_id ): array {
        /** Return an empty cart when the user ID is invalid. */
        if ( $user_id <= 0 ) {
            return array();
        }

        /** Resolve the user cart storage key. */
        $key = self::get_user_storage_key( $user_id );

        /** Load the stored user cart data from user meta. */
        $cart_data = get_user_meta( $user_id, 'aicommerce_cart', true );

        /** Return an empty cart when the stored value is missing or invalid. */
        if ( ! is_array( $cart_data ) || empty( $cart_data ) ) {
            return array();
        }

        /** Normalize the loaded user cart data. */
        $cart_data = self::normalize_user_cart_data( $cart_data );

        /** Return the normalized user cart items. */
        return $cart_data['items'];
    }

    /**
     * Get user cart metadata including version and count.
     *
     * @param int $user_id The user ID.
     * @return array{version:int,count:int}
     */
    public static function get_user_cart_meta( int $user_id ): array {
        /** Return empty metadata when the user ID is invalid. */
        if ( $user_id <= 0 ) {
            return array( 'version' => 0, 'count' => 0 );
        }

        /** Load the stored user cart data from user meta. */
        $cart_data = get_user_meta( $user_id, 'aicommerce_cart', true );

        /** Return empty metadata when the stored value is invalid. */
        if ( ! is_array( $cart_data ) ) {
            return array( 'version' => 0, 'count' => 0 );
        }

        /** Normalize the loaded user cart data. */
        $cart_data = self::normalize_user_cart_data( $cart_data );

        /** Return the user cart version and item count. */
        return array(
            /** Include the normalized cart version. */
            'version' => (int) $cart_data['version'],

            /** Include the normalized cart count. */
            'count'   => (int) $cart_data['count'],
        );
    }

    /**
     * Save the cart for a user ID.
     *
     * @param int   $user_id The user ID.
     * @param array $items The cart items array.
     * @return bool
     */
    public static function save_user_cart( int $user_id, array $items ): bool {
        /** Return false when the user ID is invalid. */
        if ( $user_id <= 0 ) {
            return false;
        }

        /** Load the existing user cart data. */
        $existing  = get_user_meta( $user_id, 'aicommerce_cart', true );

        /** Normalize the existing user cart data. */
        $cart_data = self::normalize_user_cart_data( $existing );

        /** Compute the signature of the incoming cart items. */
        $incoming_sig = self::compute_items_signature( $items );

        /** Compute or reuse the existing cart item signature. */
        $existing_sig = isset( $existing['items_sig'] ) ? (string) $existing['items_sig'] : self::compute_items_signature( (array) $cart_data['items'] );

        /** Replace the cart items with the incoming list. */
        $cart_data['items']      = $items;

        /** Update the cart timestamp. */
        $cart_data['updated_at'] = time();

        /** Increment the cart version only when the item signature changed. */
        if ( $incoming_sig !== $existing_sig ) {
            $cart_data['version'] = (int) $cart_data['version'] + 1;
        }

        /** Store the new cart item signature. */
        $cart_data['items_sig']  = $incoming_sig;

        /** Normalize the updated user cart data. */
        $cart_data               = self::normalize_user_cart_data( $cart_data );

        /** Persist the updated user cart in user meta. */
        return update_user_meta( $user_id, 'aicommerce_cart', $cart_data );
    }

    /**
     * Add an item to a user cart.
     *
     * @param int   $user_id The user ID.
     * @param int   $product_id The product ID.
     * @param int   $quantity The quantity.
     * @param array $variation_data Optional variation data.
     * @return array|false
     */
    public static function add_item_to_user_cart( int $user_id, int $product_id, int $quantity = 1, array $variation_data = array() ) {
        /** Return false when required add-item inputs are invalid. */
        if ( $user_id <= 0 || $product_id <= 0 || $quantity <= 0 ) {
            return false;
        }

        /** Load the existing user cart data. */
        $existing  = get_user_meta( $user_id, 'aicommerce_cart', true );

        /** Normalize the existing user cart data. */
        $cart_data = self::normalize_user_cart_data( $existing );

        /** Extract the current cart items. */
        $cart      = $cart_data['items'];

        /** Find an existing item matching the product and variation. */
        $existing_index = self::find_item_by_product( $cart, $product_id, $variation_data );

        /** Increase quantity when the item already exists in the cart. */
        if ( $existing_index !== false ) {
            $cart[ $existing_index ]['quantity'] += $quantity;
        } else {
            /** Append a new cart item when no matching item exists. */
            $cart[] = array(
                /** Store the generated cart item key. */
                'key'            => self::generate_cart_item_key( $product_id, $variation_data ),

                /** Store the product ID. */
                'product_id'     => $product_id,

                /** Store the quantity. */
                'quantity'       => $quantity,

                /** Store variation data. */
                'variation_data' => $variation_data,

                /** Store the timestamp when the item was added. */
                'added_at'       => time(),
            );
        }

        /** Store the updated item list. */
        $cart_data['items']      = $cart;

        /** Update the cart timestamp. */
        $cart_data['updated_at'] = time();

        /** Increment the cart version after a cart mutation. */
        $cart_data['version']    = (int) $cart_data['version'] + 1;

        /** Recompute the cart item signature. */
        $cart_data['items_sig']  = self::compute_items_signature( $cart );

        /** Normalize the updated user cart data. */
        $cart_data               = self::normalize_user_cart_data( $cart_data );

        /** Return the updated cart when the save succeeds. */
        if ( update_user_meta( $user_id, 'aicommerce_cart', $cart_data ) ) {
            return $cart;
        }

        /** Return false when the save fails. */
        return false;
    }

    /**
     * Remove one item from a user cart by product_id and optional variation_data.
     *
     * @param int   $user_id The user ID.
     * @param int   $product_id The product ID.
     * @param array $variation_data Optional variation data.
     * @return array|false
     */
    public static function remove_item_from_user_cart( int $user_id, int $product_id, array $variation_data = array() ) {
        /** Return false when required remove-item inputs are invalid. */
        if ( $user_id <= 0 || $product_id <= 0 ) {
            return false;
        }

        /** Load the existing user cart data. */
        $existing  = get_user_meta( $user_id, 'aicommerce_cart', true );

        /** Normalize the existing user cart data. */
        $cart_data = self::normalize_user_cart_data( $existing );

        /** Extract the current cart items. */
        $cart      = $cart_data['items'];

        /** Find the matching item in the cart. */
        $index = self::find_item_by_product( $cart, $product_id, $variation_data );

        /** Return the unchanged cart when the item does not exist. */
        if ( $index === false ) {
            return $cart;
        }

        /** Remove the matching item from the cart. */
        array_splice( $cart, $index, 1 );

        /** Store the updated item list. */
        $cart_data['items']      = $cart;

        /** Update the cart timestamp. */
        $cart_data['updated_at'] = time();

        /** Increment the cart version after a cart mutation. */
        $cart_data['version']    = (int) $cart_data['version'] + 1;

        /** Recompute the cart item signature. */
        $cart_data['items_sig']  = self::compute_items_signature( $cart );

        /** Normalize the updated user cart data. */
        $cart_data               = self::normalize_user_cart_data( $cart_data );

        /** Return the updated cart when the save succeeds. */
        if ( update_user_meta( $user_id, 'aicommerce_cart', $cart_data ) ) {
            return $cart;
        }

        /** Return false when the save fails. */
        return false;
    }

    /**
     * Get the total cart count for a user ID.
     *
     * @param int $user_id The user ID.
     * @return int
     */
    public static function get_user_cart_count( int $user_id ): int {
        /** Load cart metadata for the user. */
        $meta = self::get_user_cart_meta( $user_id );

        /** Return the cart count from metadata. */
        return (int) $meta['count'];
    }

    /**
     * Delete the cart for a user ID.
     *
     * @param int $user_id The user ID.
     * @return bool
     */
    public static function delete_user_cart( int $user_id ): bool {
        /** Return false when the user ID is invalid. */
        if ( $user_id <= 0 ) {
            return false;
        }

        /** Delete the stored user cart from user meta. */
        return delete_user_meta( $user_id, 'aicommerce_cart' );
    }

    /**
     * Mark a guest cart as managed by AICommerce.
     *
     * @param string $guest_token The guest token.
     * @return void
     */
    public static function mark_as_ai_cart( string $guest_token ): void {
        /** Return early when the guest token is empty. */
        if ( empty( $guest_token ) ) {
            return;
        }

        /** Resolve the guest cart option key. */
        $key       = self::get_storage_key( $guest_token );

        /** Load the existing guest cart data. */
        $cart_data = get_option( $key, null );

        /** Add the AI cart flag when the cart exists and has not been flagged yet. */
        if ( is_array( $cart_data ) && empty( $cart_data['ai_cart'] ) ) {
            $cart_data['ai_cart'] = true;
            update_option( $key, $cart_data, false );
        }
    }

    /**
     * Check whether a guest cart has the AICommerce flag.
     *
     * @param string $guest_token The guest token.
     * @return bool
     */
    public static function has_ai_flag( string $guest_token ): bool {
        /** Return false when the guest token is empty. */
        if ( empty( $guest_token ) ) {
            return false;
        }

        /** Resolve the guest cart option key. */
        $key       = self::get_storage_key( $guest_token );

        /** Load the existing guest cart data. */
        $cart_data = get_option( $key, null );

        /** Return whether the AI cart flag is present. */
        return is_array( $cart_data ) && ! empty( $cart_data['ai_cart'] );
    }

    /**
     * Mark a user cart as managed by AICommerce.
     *
     * @param int $user_id The user ID.
     * @return void
     */
    public static function mark_as_ai_user_cart( int $user_id ): void {
        /** Return early when the user ID is invalid. */
        if ( $user_id <= 0 ) {
            return;
        }

        /** Load the existing user cart data. */
        $cart_data = get_user_meta( $user_id, 'aicommerce_cart', true );

        /** Add the AI cart flag when the cart exists and has not been flagged yet. */
        if ( is_array( $cart_data ) && empty( $cart_data['ai_cart'] ) ) {
            $cart_data['ai_cart'] = true;
            update_user_meta( $user_id, 'aicommerce_cart', $cart_data );
        }
    }

    /**
     * Check whether a user cart has the AICommerce flag.
     *
     * @param int $user_id The user ID.
     * @return bool
     */
    public static function has_ai_user_flag( int $user_id ): bool {
        /** Return false when the user ID is invalid. */
        if ( $user_id <= 0 ) {
            return false;
        }

        /** Load the existing user cart data. */
        $cart_data = get_user_meta( $user_id, 'aicommerce_cart', true );

        /** Return whether the AI cart flag is present. */
        return is_array( $cart_data ) && ! empty( $cart_data['ai_cart'] );
    }

    /**
     * Register the recurring cleanup action.
     *
     * @return void
     */
    public static function register_cleanup(): void {
        /** Register the cleanup callback for expired guest carts. */
        add_action( 'aicommerce_cleanup_guest_carts', array( static::class, 'cleanup_expired_carts' ) );

        /** Schedule the recurring cleanup action when it does not already exist. */
        add_action( 'init', function () {
            /** Schedule the recurring cleanup action via Action Scheduler if needed. */
            if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( 'aicommerce_cleanup_guest_carts', array(), 'aicommerce' ) ) {
                as_schedule_recurring_action( time() + 30 * DAY_IN_SECONDS, 30 * DAY_IN_SECONDS, 'aicommerce_cleanup_guest_carts', array(), 'aicommerce' );
            }
        } );
    }

    /**
     * Delete all expired guest cart rows from wp_options.
     *
     * Triggered by Action Scheduler every 30 days.
     *
     * @return void
     */
    public static function cleanup_expired_carts(): void {
        /** Access the global WordPress database object. */
        global $wpdb;

        /** Query all option names that belong to guest cart storage. */
        $option_names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( self::OPTION_PREFIX ) . '%'
            )
        );

        /** Return early when no guest cart option names were found. */
        if ( empty( $option_names ) ) {
            return;
        }

        /** Get the current timestamp. */
        $now = time();

        /** Inspect each stored guest cart option. */
        foreach ( $option_names as $option_name ) {
            /** Load the cart data from the option row. */
            $cart_data = get_option( $option_name );

            /** Delete the option when the cart has expired. */
            if ( isset( $cart_data['expires_at'] ) && $cart_data['expires_at'] < $now ) {
                delete_option( $option_name );
            }
        }
    }
}
