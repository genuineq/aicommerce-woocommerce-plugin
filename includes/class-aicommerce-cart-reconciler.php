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
 * Rebuilds WooCommerce cart state from persistent AICommerce cart state.
 */
class CartReconciler {

    /**
     * Replace the current WooCommerce cart with the provided persistent cart items.
     *
     * @param array    $persistent_items Persistent cart items.
     * @param \WC_Cart $cart             WooCommerce cart instance.
     * @return array<string, mixed>
     */
    public static function replace_wc_cart_with_persistent( array $persistent_items, \WC_Cart $cart ): array {
        $stats = array(
            'changed'      => true,
            'added'        => 0,
            'removed'      => count( $cart->get_cart() ),
            'synced_count' => 0,
            'errors'       => array(),
        );

        $cart->empty_cart();

        foreach ( $persistent_items as $item ) {
            $product_id     = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
            $quantity       = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 1;
            $variation_data = isset( $item['variation_data'] ) && is_array( $item['variation_data'] ) ? $item['variation_data'] : array();

            if ( $product_id <= 0 || $quantity <= 0 ) {
                continue;
            }

            $product = wc_get_product( $product_id );
            if ( ! $product || ! $product->is_purchasable() ) {
                $stats['errors'][] = sprintf(
                    __( 'Product ID %d is not available.', 'aicommerce' ),
                    $product_id
                );
                continue;
            }

            $variation_id   = ! empty( $variation_data['variation_id'] ) ? absint( $variation_data['variation_id'] ) : 0;
            $variation_data = CartAPI::normalize_variation_data_for_wc( $product_id, $variation_data );
            $variation_attrs = CartAPI::get_variation_attributes_for_add_to_cart( $variation_data, $product_id );

            try {
                $new_cart_item_key = $cart->add_to_cart( $product_id, $quantity, $variation_id, $variation_attrs );
            } catch ( \Exception $e ) {
                $new_cart_item_key = false;
            }

            if ( $new_cart_item_key ) {
                $stats['added']++;
            } else {
                $stats['errors'][] = sprintf(
                    __( 'Failed to add product ID %d to cart.', 'aicommerce' ),
                    $product_id
                );
            }
        }

        $stats['synced_count'] = $stats['added'];

        return $stats;
    }

    /**
     * Convert a WooCommerce cart into persistent storage items.
     *
     * @param \WC_Cart $cart WooCommerce cart.
     * @return array<int, array<string, mixed>>
     */
    public static function persistent_items_from_wc_cart( \WC_Cart $cart ): array {
        $items = array();

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            $variation_data = isset( $cart_item['variation'] ) ? $cart_item['variation'] : array();

            if ( ! empty( $cart_item['variation_id'] ) ) {
                $variation_data = array_merge( array( 'variation_id' => (int) $cart_item['variation_id'] ), $variation_data );
            }

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
}
