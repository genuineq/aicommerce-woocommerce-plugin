<?php
/**
 * User API Endpoints
 *
 * @package AICommerce
 */

namespace AICommerce;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * User API Class
 */
class UserAPI {

    /**
     * Constructor
     */
    public function __construct() {
        /** Register the iframe-facing user endpoint. */
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register REST API routes
     */
    public function register_routes(): void {
        /** Expose a single user details endpoint under the plugin REST namespace. */
        register_rest_route(
            'aicommerce/v1',
            '/user',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_user' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * GET /wp-json/aicommerce/v1/user?user_id=1
     *
     * Returns full user information including WooCommerce customer data and order stats.
     */
    public function get_user( \WP_REST_Request $request ): \WP_REST_Response {
        /** Validate the signed iframe request before returning user data. */
        $validation = APIValidator::validate_request( $request );
        if ( ! $validation['valid'] ) {
            return APIValidator::error_response( $validation );
        }

        /** Normalize the requested user ID. */
        $user_id = absint( $request->get_param( 'user_id' ) );

        /** Reject requests that do not specify a valid user ID. */
        if ( $user_id <= 0 ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'missing_user_id',
                    'message' => __( 'user_id is required.', 'aicommerce' ),
                ),
                400
            );
        }

        /** Load the WordPress user object that will back the response payload. */
        $user = get_user_by( 'id', $user_id );

        /** Return a stable not-found response when the user does not exist. */
        if ( ! $user ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'user_not_found',
                    'message' => __( 'User not found.', 'aicommerce' ),
                ),
                404
            );
        }

        /** Build and return the full iframe-friendly user payload. */
        return new \WP_REST_Response(
            array(
                'success' => true,
                'user'    => $this->build_user_data( $user ),
            ),
            200
        );
    }

    /**
     * Build the full user data array.
     *
     * @param \WP_User $user
     * @return array
     */
    private function build_user_data( \WP_User $user ): array {
        /** Start with the core WordPress user fields that are always available. */
        $data = array(
            'id'                => $user->ID,
            'username'          => $user->user_login,
            'email'             => $user->user_email,
            'display_name'      => $user->display_name,
            'first_name'        => $user->first_name,
            'last_name'         => $user->last_name,
            'roles'             => $user->roles,
            'registered_at'     => $user->user_registered,
            'avatar_url'        => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
        );

        /** Add WooCommerce customer-specific data only when WooCommerce is active. */
        if ( class_exists( 'WC_Customer' ) ) {
            $data['billing']  = $this->get_billing( $user->ID );
            $data['shipping'] = $this->get_shipping( $user->ID );
            $data['orders']   = $this->get_order_stats( $user->ID );
        }

        return $data;
    }

    /**
     * Get billing address data.
     */
    private function get_billing( int $user_id ): array {
        /** Read the billing profile directly from WooCommerce user meta fields. */
        return array(
            'first_name' => get_user_meta( $user_id, 'billing_first_name', true ),
            'last_name'  => get_user_meta( $user_id, 'billing_last_name', true ),
            'company'    => get_user_meta( $user_id, 'billing_company', true ),
            'address_1'  => get_user_meta( $user_id, 'billing_address_1', true ),
            'address_2'  => get_user_meta( $user_id, 'billing_address_2', true ),
            'city'       => get_user_meta( $user_id, 'billing_city', true ),
            'state'      => get_user_meta( $user_id, 'billing_state', true ),
            'postcode'   => get_user_meta( $user_id, 'billing_postcode', true ),
            'country'    => get_user_meta( $user_id, 'billing_country', true ),
            'email'      => get_user_meta( $user_id, 'billing_email', true ),
            'phone'      => get_user_meta( $user_id, 'billing_phone', true ),
        );
    }

    /**
     * Get shipping address data.
     */
    private function get_shipping( int $user_id ): array {
        /** Read the shipping profile directly from WooCommerce user meta fields. */
        return array(
            'first_name' => get_user_meta( $user_id, 'shipping_first_name', true ),
            'last_name'  => get_user_meta( $user_id, 'shipping_last_name', true ),
            'company'    => get_user_meta( $user_id, 'shipping_company', true ),
            'address_1'  => get_user_meta( $user_id, 'shipping_address_1', true ),
            'address_2'  => get_user_meta( $user_id, 'shipping_address_2', true ),
            'city'       => get_user_meta( $user_id, 'shipping_city', true ),
            'state'      => get_user_meta( $user_id, 'shipping_state', true ),
            'postcode'   => get_user_meta( $user_id, 'shipping_postcode', true ),
            'country'    => get_user_meta( $user_id, 'shipping_country', true ),
            'phone'      => get_user_meta( $user_id, 'shipping_phone', true ),
        );
    }

    /**
     * Get WooCommerce order statistics for the user.
     */
    private function get_order_stats( int $user_id ): array {
        /** Aggregate the user order counters using WooCommerce helper functions. */
        $total_orders = wc_get_customer_order_count( $user_id );
        $total_spent  = (float) wc_get_customer_total_spent( $user_id );
        $average      = $total_orders > 0 ? round( $total_spent / $total_orders, 2 ) : 0.0;

        /** Load the most recent order so the iframe can display recency information. */
        $last_order      = wc_get_customer_last_order( $user_id );
        $last_order_id   = $last_order ? $last_order->get_id() : null;
        $last_order_date = null;
        if ( $last_order ) {
            /** Normalize the last-order creation date into a simple string payload. */
            $date_created    = $last_order->get_date_created();
            $last_order_date = $date_created ? $date_created->date( 'Y-m-d H:i:s' ) : null;
        }

        /** Return order statistics in a stable response shape for iframe consumers. */
        return array(
            'total_orders'        => $total_orders,
            'total_spent'         => $total_spent,
            'average_order_value' => $average,
            'currency'            => get_woocommerce_currency(),
            'last_order_id'       => $last_order_id,
            'last_order_date'     => $last_order_date,
        );
    }
}
