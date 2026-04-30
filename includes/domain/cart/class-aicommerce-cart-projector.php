<?php
/**
 * Cart rebuild helpers.
 *
 * @package AICommerce
 */

namespace AICommerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Projects canonical cart storage into WooCommerce cart state and back.
 */
class CartProjector {
    /**
     * Compute a stable fingerprint for normalized cart items.
     *
     * @param array<int, array<string, mixed>> $items Cart items.
     * @return string
     */
    public static function compute_items_fingerprint( array $items ): string {
        /** Build a normalized signature payload that is stable across key ordering differences. */
        $normalized = array();

        foreach ( $items as $item ) {
            /** Extract only the fields that matter for cart identity and quantity tracking. */
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
     * Remove invalid storage lines before they can break cart reads or sync.
     *
     * Invalid means:
     * - missing or invalid product ID
     * - missing or invalid quantity
     * - deleted parent product
     * - deleted variation
     * - variation that no longer belongs to the stored parent product
     *
     * @param array<int, array<string, mixed>> $storage_items Stored cart items.
     * @return array{items: array<int, array<string, mixed>>, changed: bool, removed: int, errors: array<int, string>}
     */
    public static function sanitize_storage_items( array $storage_items ): array {
        /** Start from an empty normalized result payload. */
        $result = array(
            'items'   => array(),
            'changed' => false,
            'removed' => 0,
            'errors'  => array(),
        );

        /** Validate each stored line independently so one broken item does not poison the whole cart. */
        foreach ( $storage_items as $item ) {
            /** Read the stored product identity. */
            $product_id     = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
            $quantity       = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;
            $variation_data = isset( $item['variation_data'] ) && is_array( $item['variation_data'] ) ? $item['variation_data'] : array();
            $variation_id   = ! empty( $variation_data['variation_id'] ) ? absint( $variation_data['variation_id'] ) : 0;

            /** Drop structurally invalid lines immediately. */
            if ( $product_id <= 0 || $quantity <= 0 ) {
                $result['changed']  = true;
                $result['removed'] += 1;
                $result['errors'][] = __( 'Removed invalid cart line.', 'aicommerce' );
                continue;
            }

            /** Drop lines whose parent product was deleted or hidden from the storefront. */
            $parent_product = wc_get_product( $product_id );
            if ( ! $parent_product ) {
                $result['changed']  = true;
                $result['removed'] += 1;
                $result['errors'][] = sprintf(
                    __( 'Removed deleted product ID %d from cart.', 'aicommerce' ),
                    $product_id
                );
                continue;
            }

            if ( 'publish' !== $parent_product->get_status() ) {
                $result['changed']  = true;
                $result['removed'] += 1;
                $result['errors'][] = sprintf(
                    __( 'Removed unpublished product ID %d from cart.', 'aicommerce' ),
                    $product_id
                );
                continue;
            }

            if ( ! $parent_product->is_purchasable() ) {
                $result['changed']  = true;
                $result['removed'] += 1;
                $result['errors'][] = sprintf(
                    __( 'Removed non-purchasable product ID %d from cart.', 'aicommerce' ),
                    $product_id
                );
                continue;
            }

            if ( ! $parent_product->is_in_stock() ) {
                $result['changed']  = true;
                $result['removed'] += 1;
                $result['errors'][] = sprintf(
                    __( 'Removed out-of-stock product ID %d from cart.', 'aicommerce' ),
                    $product_id
                );
                continue;
            }

            /** When a variation is stored, verify that it still exists and still belongs to the parent product. */
            if ( $variation_id > 0 ) {
                $variation_product = wc_get_product( $variation_id );
                if ( ! $variation_product || ! $variation_product->is_type( 'variation' ) || (int) $variation_product->get_parent_id() !== $product_id ) {
                    $result['changed']  = true;
                    $result['removed'] += 1;
                    $result['errors'][] = sprintf(
                        __( 'Removed invalid variation ID %d from cart.', 'aicommerce' ),
                        $variation_id
                    );
                    continue;
                }

                if ( 'publish' !== $variation_product->get_status() || ! $variation_product->is_purchasable() || ! $variation_product->is_in_stock() ) {
                    $result['changed']  = true;
                    $result['removed'] += 1;
                    $result['errors'][] = sprintf(
                        __( 'Removed unavailable variation ID %d from cart.', 'aicommerce' ),
                        $variation_id
                    );
                    continue;
                }
            }

            /** Keep valid lines untouched. */
            $result['items'][] = $item;
        }

        return $result;
    }

    /**
     * Replace the current WooCommerce cart with the provided persistent cart items.
     *
     * @param array    $persistent_items Persistent cart items.
     * @param \WC_Cart $cart             WooCommerce cart instance.
     * @return array<string, mixed>
     */
    public static function rebuild_wc_cart_from_storage( array $storage_items, \WC_Cart $cart ): array {
        /** Sanitize the source cart first so deleted products stop reappearing forever. */
        $sanitized     = self::sanitize_storage_items( $storage_items );
        $storage_items = $sanitized['items'];

        /** Start from a clean result payload for callers that want simple sync stats. */
        $stats = array(
            'changed'      => true,
            'added'        => 0,
            'removed'      => count( $cart->get_cart() ),
            'synced_count' => 0,
            'errors'       => $sanitized['errors'],
        );

        /** Clear the current WooCommerce cart so the rebuild starts from canonical storage only. */
        $cart->empty_cart();

        /** Re-add every valid storage line back into WooCommerce cart. */
        foreach ( $storage_items as $item ) {
            /** Read the basic line identity from canonical storage. */
            $product_id     = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
            $quantity       = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 1;
            $variation_data = isset( $item['variation_data'] ) && is_array( $item['variation_data'] ) ? $item['variation_data'] : array();

            /** Ignore invalid or empty lines instead of trying to project them into WooCommerce. */
            if ( $product_id <= 0 || $quantity <= 0 ) {
                continue;
            }

            /** Load the WooCommerce product to confirm it can still be purchased. */
            $product = wc_get_product( $product_id );
            if ( ! $product || ! $product->is_purchasable() ) {
                /** Keep a human-readable error so the caller can inspect skipped lines. */
                $stats['errors'][] = sprintf(
                    __( 'Product ID %d is not available.', 'aicommerce' ),
                    $product_id
                );
                continue;
            }

            /** Extract and normalize variation information before calling WooCommerce APIs. */
            $variation_id   = ! empty( $variation_data['variation_id'] ) ? absint( $variation_data['variation_id'] ) : 0;
            $variation_data = CartAPI::normalize_variation_data_for_wc( $product_id, $variation_data );
            $variation_attrs = CartAPI::get_variation_attributes_for_add_to_cart( $variation_data, $product_id );

            try {
                /** Recreate the cart line inside WooCommerce. */
                $new_cart_item_key = $cart->add_to_cart( $product_id, $quantity, $variation_id, $variation_attrs );
            } catch ( \Exception $e ) {
                /** Swallow WooCommerce exceptions and expose them through the structured error list instead. */
                $new_cart_item_key = false;
            }

            if ( $new_cart_item_key ) {
                /** Count successful projected lines for logging and API responses. */
                $stats['added']++;
            } else {
                /** Record a generic add failure when WooCommerce refuses the line without throwing. */
                $stats['errors'][] = sprintf(
                    __( 'Failed to add product ID %d to cart.', 'aicommerce' ),
                    $product_id
                );
            }
        }

        /** In the full-rebuild model, "synced" means "lines successfully recreated". */
        $stats['synced_count'] = $stats['added'];

        return $stats;
    }

    /**
     * Convert a WooCommerce cart into persistent storage items.
     *
     * @param \WC_Cart $cart WooCommerce cart.
     * @return array<int, array<string, mixed>>
     */
    public static function storage_items_from_wc_cart( \WC_Cart $cart ): array {
        /** Start the normalized storage payload that mirrors the current WooCommerce cart. */
        $items = array();

        /** Flatten every Woo cart line into the canonical storage structure. */
        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            /** Preserve variation attributes exactly as WooCommerce resolved them. */
            $variation_data = isset( $cart_item['variation'] ) ? $cart_item['variation'] : array();

            /** Keep the concrete variation ID alongside the resolved variation attributes. */
            if ( ! empty( $cart_item['variation_id'] ) ) {
                $variation_data = array_merge( array( 'variation_id' => (int) $cart_item['variation_id'] ), $variation_data );
            }

            /** Persist only the fields needed to rebuild the line later. */
            $items[] = array(
                'key'            => $cart_item_key,
                'product_id'     => $cart_item['product_id'],
                'quantity'       => $cart_item['quantity'],
                'variation_data' => $variation_data,
                'added_at'       => time(),
            );
        }

        return $items;
    }

    /**
     * Compute a fingerprint for the current WooCommerce cart.
     *
     * @param \WC_Cart $cart WooCommerce cart instance.
     * @return string
     */
    public static function compute_wc_cart_fingerprint( \WC_Cart $cart ): string {
        return self::compute_items_fingerprint( self::storage_items_from_wc_cart( $cart ) );
    }
}
