<?php
/**
 * User API Endpoints
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * User API Class
 */
class UserAPI {

    /**
     * Constructor.
     *
     * Registers REST API routes.
     *
     * @return void
     */
    public function __construct() {
        /** Hook into REST API initialization. */
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public function register_routes(): void {
        /** Register endpoint for fetching user data. */
        register_rest_route(
            'aicommerce/v1',
            '/user',
            array(
                /** Allow GET requests. */
                'methods'             => 'GET',
                /** Callback for handling the request. */
                'callback'            => array( $this, 'get_user' ),
                /** Public access allowed. */
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Get user data endpoint.
     *
     * GET /wp-json/aicommerce/v1/user?user_id=1
     *
     * Returns full user information including WooCommerce data.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_user( \WP_REST_Request $request ): \WP_REST_Response {
        /** Validate incoming request. */
        $validation = APIValidator::validate_request( $request );

        /** Return error response if validation fails. */
        if ( ! $validation['valid'] ) {
            return APIValidator::error_response( $validation );
        }

        /** Extract and sanitize user ID. */
        $user_id = absint( $request->get_param( 'user_id' ) );

        /** Ensure user_id is provided and valid. */
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

        /** Retrieve user by ID. */
        $user = get_user_by( 'id', $user_id );

        /** Return error if user does not exist. */
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

        /** Return successful response with user data. */
        return new \WP_REST_Response(
            array(
                'success' => true,
                'user'    => $this->build_user_data( $user ),
            ),
            200
        );
    }

    /**
     * Build full user data array.
     *
     * @param \WP_User $user
     * @return array
     */
    private function build_user_data( \WP_User $user ): array {
        /** Base user data structure. */
        $data = array(
            'id'                => $user->ID,
            'username'          => $user->user_login,
            'email'             => $user->user_email,
            'display_name'      => $user->display_name,
            'first_name'        => $user->first_name,
            'last_name'         => $user->last_name,
            'roles'             => $user->roles,
            'registered_at'     => $user->user_registered,
            /** Generate avatar URL for user. */
            'avatar_url'        => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
        );

        /** Add WooCommerce-specific data if available. */
        if ( class_exists( 'WC_Customer' ) ) {
            /** Include billing address. */
            $data['billing']  = $this->get_billing( $user->ID );

            /** Include shipping address. */
            $data['shipping'] = $this->get_shipping( $user->ID );

            /** Include order statistics. */
            $data['orders']   = $this->get_order_stats( $user->ID );
        }

        return $data;
    }

    /**
     * Get billing address data.
     *
     * @param int $user_id
     * @return array
     */
    private function get_billing( int $user_id ): array {
        /** Return billing-related user meta fields. */
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
     *
     * @param int $user_id
     * @return array
     */
    private function get_shipping( int $user_id ): array {
        /** Return shipping-related user meta fields. */
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
     * Get WooCommerce order statistics.
     *
     * @param int $user_id
     * @return array
     */
    private function get_order_stats( int $user_id ): array {
        /** Get total number of orders. */
        $total_orders = wc_get_customer_order_count( $user_id );

        /** Get total amount spent by the user. */
        $total_spent  = (float) wc_get_customer_total_spent( $user_id );

        /** Calculate average order value. */
        $average      = $total_orders > 0 ? round( $total_spent / $total_orders, 2 ) : 0.0;

        /** Retrieve last order. */
        $last_order      = wc_get_customer_last_order( $user_id );

        /** Extract last order ID. */
        $last_order_id   = $last_order ? $last_order->get_id() : null;

        /** Initialize last order date. */
        $last_order_date = null;

        /** Extract last order date if available. */
        if ( $last_order ) {
            $date_created    = $last_order->get_date_created();
            $last_order_date = $date_created ? $date_created->date( 'Y-m-d H:i:s' ) : null;
        }

        /** Return order statistics. */
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
