<?php
/**
 * Shared product availability and cart validation helpers.
 *
 * @package AICommerce
 */

namespace AICommerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Product availability helper class.
 */
class ProductAvailability {
    /**
     * Build a stable availability payload for a product or concrete variation.
     *
     * @param \WC_Product      $product       Product object.
     * @param \WC_Product|null $variation     Concrete variation when selected.
     * @param int              $existing_qty  Quantity already present in cart.
     * @return array<string, mixed>
     */
    public static function build_availability_payload( \WC_Product $product, ?\WC_Product $variation = null, int $existing_qty = 0 ): array {
        $target = $variation ?: $product;

        if ( 'publish' !== $product->get_status() ) {
            return array(
                'purchasable_now'             => false,
                'stock_state'                 => 'product_unpublished',
                'max_addable_quantity'        => 0,
                'requires_variation_selection'=> false,
                'sold_individually'           => false,
                'backorders_allowed'          => false,
            );
        }

        if ( $product->is_type( 'variable' ) && ! $variation ) {
            return array(
                'purchasable_now'              => false,
                'stock_state'                  => 'variation_required',
                'max_addable_quantity'         => 0,
                'requires_variation_selection' => true,
                'sold_individually'            => (bool) $product->is_sold_individually(),
                'backorders_allowed'           => false,
            );
        }

        $manages_stock      = (bool) $target->managing_stock();
        $backorders_allowed = (bool) $target->backorders_allowed();
        $sold_individually  = (bool) $target->is_sold_individually();
        $stock_quantity     = $target->get_stock_quantity();
        $max_addable        = self::get_max_addable_quantity( $target, $existing_qty );
        $stock_state        = 'in_stock';

        if ( ! $target->is_purchasable() ) {
            $stock_state = 'not_purchasable';
        } elseif ( ! $target->is_in_stock() ) {
            $stock_state = 'out_of_stock';
        } elseif ( $sold_individually && $existing_qty > 0 ) {
            $stock_state = 'sold_individually';
        } elseif ( $manages_stock && ! $backorders_allowed && $stock_quantity !== null && $max_addable <= 0 ) {
            $stock_state = 'insufficient_stock';
        } elseif ( $target->is_on_backorder( 1 ) || ( $backorders_allowed && $manages_stock && $stock_quantity !== null && $stock_quantity <= 0 ) ) {
            $stock_state = 'backorder';
        }

        return array(
            'purchasable_now'              => $target->is_purchasable() && $max_addable > 0 && 'publish' === $product->get_status(),
            'stock_state'                  => $stock_state,
            'max_addable_quantity'         => $max_addable,
            'requires_variation_selection' => $product->is_type( 'variable' ) && ! $variation,
            'sold_individually'            => $sold_individually,
            'backorders_allowed'           => $backorders_allowed,
            'manage_stock'                 => $manages_stock,
            'stock_quantity'               => is_null( $stock_quantity ) ? null : (int) $stock_quantity,
        );
    }

    /**
     * Validate cart add payload against WooCommerce availability rules.
     *
     * @param \WC_Product $product        Parent product.
     * @param array       $variation_data Variation payload.
     * @param int         $requested_qty  Quantity being added.
     * @param int         $existing_qty   Existing cart quantity for the same line.
     * @return array<string, mixed>
     */
    public static function validate_product_for_cart( \WC_Product $product, array $variation_data, int $requested_qty, int $existing_qty = 0 ): array {
        if ( 'publish' !== $product->get_status() ) {
            return self::build_validation_result( false, 'product_unpublished', __( 'Product is not published.', 'aicommerce' ), $product, null, $existing_qty );
        }

        if ( $product->is_type( 'variable' ) ) {
            if ( empty( $variation_data['variation_id'] ) ) {
                return self::build_validation_result( false, 'variation_required', __( 'Variable products require variation_data.variation_id.', 'aicommerce' ), $product, null, $existing_qty );
            }

            $variation_result = self::validate_variation_for_cart( $product, $variation_data );
            if ( ! $variation_result['valid'] ) {
                return $variation_result;
            }

            $variation = $variation_result['variation'];
            return self::validate_target_for_cart( $product, $variation, $requested_qty, $existing_qty );
        }

        return self::validate_target_for_cart( $product, null, $requested_qty, $existing_qty );
    }

    /**
     * Validate a concrete variation selection for a variable product.
     *
     * @param \WC_Product $product        Parent product.
     * @param array       $variation_data Variation payload.
     * @return array<string, mixed>
     */
    public static function validate_variation_for_cart( \WC_Product $product, array $variation_data ): array {
        $variation_id = isset( $variation_data['variation_id'] ) ? absint( $variation_data['variation_id'] ) : 0;
        if ( $variation_id <= 0 ) {
            return self::build_validation_result( false, 'variation_invalid', __( 'Variation ID is invalid.', 'aicommerce' ), $product, null, 0 );
        }

        $variation = wc_get_product( $variation_id );
        if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
            return self::build_validation_result( false, 'variation_invalid', __( 'Variation not found.', 'aicommerce' ), $product, null, 0 );
        }

        if ( (int) $variation->get_parent_id() !== (int) $product->get_id() ) {
            return self::build_validation_result( false, 'variation_mismatch', __( 'Variation does not belong to the requested product.', 'aicommerce' ), $product, null, 0 );
        }

        $requested_attributes = self::extract_request_attributes( $variation_data );
        if ( ! empty( $requested_attributes ) ) {
            $variation_attributes = $variation->get_variation_attributes();
            foreach ( $requested_attributes as $attribute_key => $value ) {
                if ( isset( $variation_attributes[ $attribute_key ] ) && (string) $variation_attributes[ $attribute_key ] !== (string) $value ) {
                    return self::build_validation_result( false, 'variation_mismatch', __( 'Variation attributes do not match the selected variation.', 'aicommerce' ), $product, $variation, 0 );
                }
            }
        }

        return self::build_validation_result( true, 'valid', '', $product, $variation, 0 );
    }

    /**
     * Return how many more units can be added for the product.
     *
     * @param \WC_Product $product      Target product.
     * @param int         $existing_qty Existing quantity already in cart.
     * @return int
     */
    public static function get_max_addable_quantity( \WC_Product $product, int $existing_qty = 0 ): int {
        if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
            return 0;
        }

        if ( $product->is_sold_individually() ) {
            return $existing_qty > 0 ? 0 : 1;
        }

        if ( ! $product->managing_stock() ) {
            return PHP_INT_MAX;
        }

        if ( $product->backorders_allowed() ) {
            return PHP_INT_MAX;
        }

        $stock_quantity = $product->get_stock_quantity();
        if ( null === $stock_quantity ) {
            return PHP_INT_MAX;
        }

        return max( 0, (int) $stock_quantity - max( 0, $existing_qty ) );
    }

    /**
     * Validate one concrete target object after variation resolution.
     *
     * @param \WC_Product      $product       Parent product.
     * @param \WC_Product|null $variation     Concrete variation when selected.
     * @param int              $requested_qty Requested quantity.
     * @param int              $existing_qty  Existing quantity in cart.
     * @return array<string, mixed>
     */
    private static function validate_target_for_cart( \WC_Product $product, ?\WC_Product $variation, int $requested_qty, int $existing_qty ): array {
        $target       = $variation ?: $product;
        $availability = self::build_availability_payload( $product, $variation, $existing_qty );

        if ( ! $target->is_purchasable() ) {
            return self::build_validation_result( false, 'product_not_purchasable', __( 'Product is not purchasable right now.', 'aicommerce' ), $product, $variation, $existing_qty );
        }

        if ( ! $target->is_in_stock() ) {
            return self::build_validation_result( false, 'out_of_stock', __( 'Product is out of stock.', 'aicommerce' ), $product, $variation, $existing_qty );
        }

        if ( $target->is_sold_individually() && ( $existing_qty > 0 || $requested_qty > 1 ) ) {
            return self::build_validation_result( false, 'sold_individually', __( 'This product is sold individually.', 'aicommerce' ), $product, $variation, $existing_qty );
        }

        if ( $requested_qty > $availability['max_addable_quantity'] ) {
            return self::build_validation_result( false, 'insufficient_stock', __( 'Requested quantity exceeds available stock.', 'aicommerce' ), $product, $variation, $existing_qty );
        }

        return self::build_validation_result( true, 'valid', '', $product, $variation, $existing_qty );
    }

    /**
     * Assemble a stable validation payload.
     *
     * @param bool             $valid        Result flag.
     * @param string           $code         Stable error/status code.
     * @param string           $message      Human-readable message.
     * @param \WC_Product      $product      Parent product.
     * @param \WC_Product|null $variation    Optional variation.
     * @param int              $existing_qty Existing cart quantity.
     * @return array<string, mixed>
     */
    private static function build_validation_result( bool $valid, string $code, string $message, \WC_Product $product, ?\WC_Product $variation, int $existing_qty ): array {
        return array(
            'valid'        => $valid,
            'code'         => $code,
            'message'      => $message,
            'product'      => $product,
            'variation'    => $variation,
            'availability' => self::build_availability_payload( $product, $variation, $existing_qty ),
        );
    }

    /**
     * Extract request-side variation attributes only.
     *
     * @param array $variation_data Variation payload.
     * @return array<string, string>
     */
    private static function extract_request_attributes( array $variation_data ): array {
        $attributes = array();
        foreach ( $variation_data as $key => $value ) {
            if ( is_string( $key ) && strpos( $key, 'attribute_' ) === 0 && $value !== '' && $value !== null ) {
                $attributes[ $key ] = (string) $value;
            }
        }

        return $attributes;
    }
}
