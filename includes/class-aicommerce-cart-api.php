<?php
/**
 * Cart API Endpoints
 *
 * @package AICommerce
 */

namespace AICommerce;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cart API Class
 */
class CartAPI {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes(): void {
        $namespace = 'aicommerce/v1';
        
        // Add to cart endpoint
        register_rest_route(
            $namespace,
            '/cart/add',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'add_to_cart' ),
                'permission_callback' => '__return_true',
            )
        );
        
        // Get cart endpoint
        register_rest_route(
            $namespace,
            '/cart',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_cart' ),
                'permission_callback' => '__return_true',
            )
        );
        
        // Sync cart to WooCommerce session endpoint
        register_rest_route(
            $namespace,
            '/cart/sync',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'sync_to_wc_session' ),
                'permission_callback' => '__return_true',
            )
        );
        
        // Remove from cart endpoint
        register_rest_route(
            $namespace,
            '/cart/remove',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'remove_from_cart' ),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'product_id'     => array(
                        'description' => __( 'Product ID to remove', 'aicommerce' ),
                        'type'        => 'integer',
                        'required'    => true,
                        'minimum'     => 1,
                    ),
                    'variation_data' => array(
                        'description' => __( 'Variation data for variable products (must include variation_id)', 'aicommerce' ),
                        'type'        => 'object',
                        'required'    => false,
                    ),
                ),
            )
        );
    }
    
    /**
     * Add to cart endpoint
     * Supports both guest_token and user_id
     */
    public function add_to_cart( \WP_REST_Request $request ): \WP_REST_Response {
        // Validate API signature
        $validation = APIValidator::validate_request( $request );
        if ( ! $validation['valid'] ) {
            return APIValidator::error_response( $validation );
        }
        
        $guest_token = $request->get_param( 'guest_token' );
        $user_id = $request->get_param( 'user_id' );
        $product_id = $request->get_param( 'product_id' );
        $quantity = $request->get_param( 'quantity' );
        $variation_data = $request->get_param( 'variation_data' );
        
        // Normalize user_id - convert to int if provided
        $user_id_int = null;
        if ( ! empty( $user_id ) || ( isset( $user_id ) && $user_id !== '' && $user_id !== null ) ) {
            $user_id_int = absint( $user_id );
            if ( $user_id_int <= 0 ) {
                $user_id_int = null;
            }
        }
        
        // Validate that either guest_token or user_id is provided
        if ( empty( $guest_token ) && empty( $user_id_int ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'missing_identifier',
                    'message' => __( 'Either guest_token or user_id is required.', 'aicommerce' ),
                ),
                400
            );
        }
        
        // Validate that both are not provided
        if ( ! empty( $guest_token ) && ! empty( $user_id_int ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'conflicting_identifiers',
                    'message' => __( 'Provide either guest_token or user_id, not both.', 'aicommerce' ),
                ),
                400
            );
        }
        
        // Validate guest token format if provided
        if ( ! empty( $guest_token ) && ! $this->validate_guest_token( $guest_token ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'invalid_guest_token',
                    'message' => __( 'Invalid guest token format.', 'aicommerce' ),
                ),
                400
            );
        }
        
        // Validate user_id if provided
        if ( ! empty( $user_id_int ) ) {
            if ( ! get_user_by( 'id', $user_id_int ) ) {
                return new \WP_REST_Response(
                    array(
                        'success' => false,
                        'code'    => 'invalid_user_id',
                        'message' => __( 'Invalid user ID.', 'aicommerce' ),
                    ),
                    400
                );
            }
            $user_id = $user_id_int;
        } else {
            $user_id = null;
        }
        
        // Validate product ID
        if ( empty( $product_id ) || ! is_numeric( $product_id ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'missing_product_id',
                    'message' => __( 'Product ID is required.', 'aicommerce' ),
                ),
                400
            );
        }
        
        $product_id = absint( $product_id );
        $quantity = ! empty( $quantity ) ? absint( $quantity ) : 1;
        
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'product_not_found',
                    'message' => __( 'Product not found.', 'aicommerce' ),
                ),
                404
            );
        }
        
        $variation_data_array = array();
        if ( ! empty( $variation_data ) && is_array( $variation_data ) ) {
            $variation_data_array = $variation_data;
        }
        
        // Variable products require variation_id in variation_data
        if ( $product->is_type( 'variable' ) ) {
            if ( empty( $variation_data_array['variation_id'] ) ) {
                return new \WP_REST_Response(
                    array(
                        'success' => false,
                        'code'    => 'variation_required',
                        'message' => __( 'Variable products require variation_data.variation_id.', 'aicommerce' ),
                    ),
                    400
                );
            }
        }
        
        $variation_data_array = self::normalize_variation_data_for_wc( $product_id, $variation_data_array );
        
        if ( ! empty( $guest_token ) ) {
            $cart = CartStorage::add_item( $guest_token, $product_id, $quantity, $variation_data_array );
            $cart_count = CartStorage::get_cart_count( $guest_token );
            $identifier = $guest_token;
            
            if ( $cart === false ) {
                return new \WP_REST_Response(
                    array(
                        'success' => false,
                        'code'    => 'add_to_cart_failed',
                        'message' => __( 'Failed to add item to cart.', 'aicommerce' ),
                    ),
                    500
                );
            }
            
            SSE::send_event( $guest_token, 'cart_updated', array(
                'action'     => 'item_added',
                'product_id' => $product_id,
                'quantity'   => $quantity,
                'cart_count' => $cart_count,
            ) );
        } elseif ( ! empty( $user_id ) && $user_id > 0 ) {
            $added_to_wc = false;
            
            if ( class_exists( 'WooCommerce' ) && function_exists( 'WC' ) ) {
                if ( ! did_action( 'woocommerce_load_cart_from_session' ) ) {
                    if ( function_exists( 'wc_load_cart' ) ) {
                        wc_load_cart();
                    }
                }
                
                $wc_cart = WC()->cart;
                if ( $wc_cart && is_a( $wc_cart, 'WC_Cart' ) ) {
                    $variation_id = 0;
                    if ( ! empty( $variation_data_array ) && isset( $variation_data_array['variation_id'] ) ) {
                        $variation_id = absint( $variation_data_array['variation_id'] );
                    }
                    $variation_attrs_for_wc = self::get_variation_attributes_for_add_to_cart( $variation_data_array, $product_id );
                    try {
                        $wc_cart_item_key = $wc_cart->add_to_cart( $product_id, $quantity, $variation_id, $variation_attrs_for_wc );
                    } catch ( \Exception $e ) {
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                            error_log( '[AICOM] add_to_cart exception: ' . $e->getMessage() );
                            error_log( '[AICOM] add_to_cart args: product_id=' . $product_id . ' variation_id=' . $variation_id . ' variation_attrs=' . wp_json_encode( $variation_attrs_for_wc ) );
                        }
                        return new \WP_REST_Response(
                            array(
                                'success' => false,
                                'code'    => 'add_to_cart_failed',
                                'message' => $e->getMessage(),
                            ),
                            400
                        );
                    }

                    if ( $wc_cart_item_key ) {
                        $added_to_wc = true;
                        $wc_cart->calculate_totals();
                        
                        $wc_cart_item = $wc_cart->get_cart_item( $wc_cart_item_key );
                        if ( $wc_cart_item ) {
                            $this->save_user_cart_item_with_wc_key( $user_id, $wc_cart_item_key, $wc_cart_item, $quantity );
                        }
                        $cart_count = CartStorage::get_user_cart_count( $user_id );
                    }
                }
            }
            
            if ( ! $added_to_wc ) {
                $cart = CartStorage::add_item_to_user_cart( $user_id, $product_id, $quantity, $variation_data_array );
                $cart_count = CartStorage::get_user_cart_count( $user_id );
                
                if ( $cart === false ) {
                    return new \WP_REST_Response(
                        array(
                            'success' => false,
                            'code'    => 'add_to_cart_failed',
                            'message' => __( 'Failed to add item to cart.', 'aicommerce' ),
                        ),
                        500
                    );
                }
            } else {
                $cart = CartStorage::get_user_cart( $user_id );
            }
            
            $identifier = 'user_' . $user_id;
        } else {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'missing_identifier',
                    'message' => __( 'Either guest_token or user_id is required.', 'aicommerce' ),
                ),
                400
            );
        }
        
        return new \WP_REST_Response(
            array(
                'success'    => true,
                'message'    => __( 'Item added to cart successfully.', 'aicommerce' ),
                'cart_count' => $cart_count,
            ),
            200
        );
    }
    
    /**
     * Get cart endpoint
     * Supports both guest_token and user_id
     */
    public function get_cart( \WP_REST_Request $request ): \WP_REST_Response {
        $validation = APIValidator::validate_request( $request );
        if ( ! $validation['valid'] ) {
            return APIValidator::error_response( $validation );
        }
        
        $guest_token = $request->get_param( 'guest_token' );
        $user_id = $request->get_param( 'user_id' );
        
        if ( empty( $guest_token ) && empty( $user_id ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'missing_identifier',
                    'message' => __( 'Either guest_token or user_id is required.', 'aicommerce' ),
                ),
                400
            );
        }
        
        if ( ! empty( $guest_token ) && ! empty( $user_id ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'conflicting_identifiers',
                    'message' => __( 'Provide either guest_token or user_id, not both.', 'aicommerce' ),
                ),
                400
            );
        }
        
        if ( ! empty( $user_id ) ) {
            $user_id = absint( $user_id );
            if ( $user_id <= 0 || ! get_user_by( 'id', $user_id ) ) {
                return new \WP_REST_Response(
                    array(
                        'success' => false,
                        'code'    => 'invalid_user_id',
                        'message' => __( 'Invalid user ID.', 'aicommerce' ),
                    ),
                    400
                );
            }
        }
        
        if ( ! empty( $guest_token ) ) {
            $cart = CartStorage::get_cart( $guest_token );
            $cart_count = CartStorage::get_cart_count( $guest_token );
        } elseif ( ! empty( $user_id ) ) {
            $cart = CartStorage::get_user_cart( $user_id );
            $cart_count = CartStorage::get_user_cart_count( $user_id );
        } else {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'missing_identifier',
                    'message' => __( 'Either guest_token or user_id is required.', 'aicommerce' ),
                ),
                400
            );
        }
        
        return new \WP_REST_Response(
            array(
                'success'    => true,
                'cart'       => $cart,
                'cart_count' => $cart_count,
            ),
            200
        );
    }
    
    /**
     * Remove from cart endpoint
     * Supports both guest_token and user_id
     */
    public function remove_from_cart( \WP_REST_Request $request ): \WP_REST_Response {
        $validation = APIValidator::validate_request( $request );
        if ( ! $validation['valid'] ) {
            return APIValidator::error_response( $validation );
        }
        
        $guest_token    = $request->get_param( 'guest_token' );
        $user_id_param  = $request->get_param( 'user_id' );
        $product_id     = absint( $request->get_param( 'product_id' ) );
        $variation_data = $request->get_param( 'variation_data' );
        
        if ( $product_id <= 0 ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'invalid_product_id',
                    'message' => __( 'Valid product_id is required.', 'aicommerce' ),
                ),
                400
            );
        }
        
        $user_id_int = null;
        if ( ! empty( $user_id_param ) || ( isset( $user_id_param ) && $user_id_param !== '' && $user_id_param !== null ) ) {
            $user_id_int = absint( $user_id_param );
            if ( $user_id_int <= 0 ) {
                $user_id_int = null;
            }
        }
        
        if ( empty( $guest_token ) && empty( $user_id_int ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'missing_identifier',
                    'message' => __( 'Either guest_token or user_id is required.', 'aicommerce' ),
                ),
                400
            );
        }
        
        if ( ! empty( $guest_token ) && ! empty( $user_id_int ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'conflicting_identifiers',
                    'message' => __( 'Provide either guest_token or user_id, not both.', 'aicommerce' ),
                ),
                400
            );
        }
        
        if ( ! empty( $guest_token ) && ! $this->validate_guest_token( $guest_token ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'invalid_guest_token',
                    'message' => __( 'Invalid guest_token format.', 'aicommerce' ),
                ),
                400
            );
        }
        
        if ( ! empty( $user_id_int ) && ! get_user_by( 'id', $user_id_int ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'invalid_user_id',
                    'message' => __( 'Invalid user ID.', 'aicommerce' ),
                ),
                400
            );
        }
        
        $variation_data_array = is_array( $variation_data ) ? $variation_data : array();
        
        if ( ! empty( $guest_token ) ) {
            $cart = CartStorage::remove_item( $guest_token, $product_id, $variation_data_array );
            if ( $cart === false ) {
                return new \WP_REST_Response(
                    array(
                        'success' => false,
                        'code'    => 'remove_failed',
                        'message' => __( 'Failed to remove item from cart.', 'aicommerce' ),
                    ),
                    500
                );
            }
            $cart_count = CartStorage::get_cart_count( $guest_token );
            SSE::send_event( $guest_token, 'cart_updated', array(
                'action'     => 'item_removed',
                'product_id' => $product_id,
                'cart_count' => $cart_count,
            ) );
            return new \WP_REST_Response(
                array(
                    'success'    => true,
                    'message'    => __( 'Item removed from cart.', 'aicommerce' ),
                    'cart_count' => $cart_count,
                ),
                200
            );
        }
        
        $user_id = $user_id_int;
        $removed = false;
        
        if ( class_exists( 'WooCommerce' ) && function_exists( 'WC' ) ) {
            if ( ! did_action( 'woocommerce_load_cart_from_session' ) && function_exists( 'wc_load_cart' ) ) {
                wc_load_cart();
            }
            $wc_cart = WC()->cart;
            if ( $wc_cart && is_a( $wc_cart, 'WC_Cart' ) ) {
                $variation_id = ! empty( $variation_data_array['variation_id'] ) ? absint( $variation_data_array['variation_id'] ) : 0;
                foreach ( $wc_cart->get_cart() as $cart_item_key => $cart_item ) {
                    if ( (int) $cart_item['product_id'] === $product_id && (int) $cart_item['variation_id'] === $variation_id ) {
                        $wc_cart->remove_cart_item( $cart_item_key );
                        $removed = true;
                        break;
                    }
                }
                if ( $removed ) {
                    $wc_cart->calculate_totals();
                    if ( WC()->session ) {
                        WC()->session->set( 'cart', $wc_cart->get_cart_for_session() );
                    }
                }
            }
        }
        
        $cart = CartStorage::remove_item_from_user_cart( $user_id, $product_id, $variation_data_array );
        if ( $cart === false ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'remove_failed',
                    'message' => __( 'Failed to remove item from cart.', 'aicommerce' ),
                ),
                500
            );
        }
        $cart_count = CartStorage::get_user_cart_count( $user_id );
        
        return new \WP_REST_Response(
            array(
                'success'    => true,
                'message'    => __( 'Item removed from cart.', 'aicommerce' ),
                'cart_count' => $cart_count,
            ),
            200
        );
    }
    
    /**
     * Sync cart to WooCommerce session endpoint
     * This endpoint is called from frontend to merge cart into WC session
     * Supports both guest_token and user_id
     */
    public function sync_to_wc_session( \WP_REST_Request $request ): \WP_REST_Response {        
        $guest_token = $request->get_param( 'guest_token' );
        $user_id = $request->get_param( 'user_id' );
        
        // Normalize user_id - convert to int if provided
        $user_id_int = null;
        if ( ! empty( $user_id ) || ( isset( $user_id ) && $user_id !== '' && $user_id !== null ) ) {
            $user_id_int = absint( $user_id );
            if ( $user_id_int <= 0 ) {
                $user_id_int = null;
            }
        }
        
        // Validate that either guest_token or user_id is provided
        if ( empty( $guest_token ) && empty( $user_id_int ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'missing_identifier',
                    'message' => __( 'Either guest_token or user_id is required.', 'aicommerce' ),
                ),
                400
            );
        }
        
        // Validate user_id if provided
        if ( ! empty( $user_id_int ) ) {
            if ( ! get_user_by( 'id', $user_id_int ) ) {
                return new \WP_REST_Response(
                    array(
                        'success' => false,
                        'code'    => 'invalid_user_id',
                        'message' => __( 'Invalid user ID.', 'aicommerce' ),
                    ),
                    400
                );
            }
            $user_id = $user_id_int;
        } else {
            $user_id = null;
        }
        
        // Get cart based on identifier type
        if ( ! empty( $guest_token ) ) {
            $guest_cart = CartStorage::get_cart( $guest_token );
        } elseif ( ! empty( $user_id ) && $user_id > 0 ) {
            $guest_cart = CartStorage::get_user_cart( $user_id );
        } else {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'missing_identifier',
                    'message' => __( 'Either guest_token or user_id is required.', 'aicommerce' ),
                ),
                400
            );
        }
        
        if ( empty( $guest_cart ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => true,
                    'message' => __( 'Cart is already empty.', 'aicommerce' ),
                    'synced'  => false,
                ),
                200
            );
        }
        
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'woocommerce_not_available',
                    'message' => __( 'WooCommerce plugin is not active.', 'aicommerce' ),
                ),
                500
            );
        }
        
        if ( ! function_exists( 'WC' ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'woocommerce_not_available',
                    'message' => __( 'WooCommerce is not initialized.', 'aicommerce' ),
                ),
                500
            );
        }
        
        if ( ! did_action( 'woocommerce_load_cart_from_session' ) ) {
            if ( function_exists( 'wc_load_cart' ) ) {
                wc_load_cart();
            } else {
                $wc = WC();
                if ( ! $wc || ! isset( $wc->cart ) ) {
                    if ( ! WC()->session ) {
                        WC()->session = new \WC_Session_Handler();
                        WC()->session->init();
                    }
                    
                    if ( ! $wc || ! isset( $wc->cart ) ) {
                        return new \WP_REST_Response(
                            array(
                                'success' => false,
                                'code'    => 'woocommerce_not_available',
                                'message' => __( 'WooCommerce cart could not be initialized.', 'aicommerce' ),
                                'debug'   => defined( 'WP_DEBUG' ) && WP_DEBUG ? array(
                                    'wc_exists' => function_exists( 'WC' ),
                                    'wc_instance' => $wc ? 'exists' : 'null',
                                    'cart_exists' => isset( $wc->cart ) ? 'yes' : 'no',
                                ) : null,
                            ),
                            500
                        );
                    }
                }
            }
        }
        
        $cart = WC()->cart;
        if ( ! $cart || ! is_a( $cart, 'WC_Cart' ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'woocommerce_not_available',
                    'message' => __( 'WooCommerce cart is not available.', 'aicommerce' ),
                    'debug'   => defined( 'WP_DEBUG' ) && WP_DEBUG ? array(
                        'cart_type' => gettype( $cart ),
                        'is_cart' => is_a( $cart, 'WC_Cart' ) ? 'yes' : 'no',
                    ) : null,
                ),
                500
            );
        }
        
        $synced_count = 0;
        $errors = array();
        
        foreach ( $guest_cart as $item ) {
            $product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
            $quantity = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 1;
            $variation_data = isset( $item['variation_data'] ) ? $item['variation_data'] : array();
            
            if ( $product_id <= 0 ) {
                continue;
            }
            
            $product = wc_get_product( $product_id );
            if ( ! $product || ! $product->is_purchasable() ) {
                $errors[] = sprintf(
                    __( 'Product ID %d is not available.', 'aicommerce' ),
                    $product_id
                );
                continue;
            }
            
            $variation_id = 0;
            if ( ! empty( $variation_data ) && isset( $variation_data['variation_id'] ) ) {
                $variation_id = absint( $variation_data['variation_id'] );
            }
            
            $variation_data = CartAPI::normalize_variation_data_for_wc( $product_id, $variation_data );
            $variation_attrs = CartAPI::get_variation_attributes_for_add_to_cart( $variation_data, $product_id );
            
            $existing_cart_item_key = null;
            $existing_quantity = 0;
            foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
                if ( $cart_item['product_id'] == $product_id && 
                     $cart_item['variation_id'] == $variation_id &&
                     ( empty( $variation_attrs ) || $cart_item['variation'] == $variation_attrs ) ) {
                    $existing_cart_item_key = $cart_item_key;
                    $existing_quantity = $cart_item['quantity'];
                    break;
                }
            }
            
            if ( $existing_cart_item_key ) {
                if ( $existing_quantity < $quantity ) {
                    $cart->set_quantity( $existing_cart_item_key, $quantity );
                    $synced_count++;
                }
            } else {
                $cart_item_key = $cart->add_to_cart( $product_id, $quantity, $variation_id, $variation_attrs );
                
                if ( $cart_item_key ) {
                    $synced_count++;
                } else {
                    $errors[] = sprintf(
                        __( 'Failed to add product ID %d to cart.', 'aicommerce' ),
                        $product_id
                    );
                }
            }
        }
        
        // Update user_meta cart if syncing user cart
        if ( ! empty( $user_id ) && $user_id > 0 ) {
            $wc_cart_items = array();
            foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
                $variation_data = isset( $cart_item['variation'] ) ? $cart_item['variation'] : array();
                if ( ! empty( $cart_item['variation_id'] ) ) {
                    $variation_data = array_merge( array( 'variation_id' => (int) $cart_item['variation_id'] ), $variation_data );
                }
                $wc_cart_items[] = array(
                    'key'            => $cart_item_key,
                    'product_id'     => $cart_item['product_id'],
                    'quantity'       => $cart_item['quantity'],
                    'variation_data' => $variation_data,
                    'added_at'       => time(),
                );
            }
            CartStorage::save_user_cart( $user_id, $wc_cart_items );
        }
        
        $cart->calculate_totals();
        
        if ( WC()->session ) {
            WC()->session->set( 'cart', $cart->get_cart_for_session() );
        }
        
        return new \WP_REST_Response(
            array(
                'success'      => true,
                'message'      => sprintf(
                    __( 'Synced %d items to cart.', 'aicommerce' ),
                    $synced_count
                ),
                'synced_count' => $synced_count,
                'total_items'  => count( $guest_cart ),
                'errors'       => $errors,
            ),
            200
        );
    }
    
    /**
     * Save user cart item with WooCommerce cart key.
     * When $quantity_added is set (API context), existing item quantity is incremented by it;
     * otherwise quantity is taken from WC cart (frontend sync).
     *
     * @param int    $user_id User ID
     * @param string $wc_cart_item_key WooCommerce cart item key
     * @param array  $wc_cart_item WooCommerce cart item data
     * @param int|null $quantity_added Quantity added in this request (API only). If null, use WC cart quantity.
     */
    private function save_user_cart_item_with_wc_key( int $user_id, string $wc_cart_item_key, array $wc_cart_item, ?int $quantity_added = null ): void {
        $user_cart = CartStorage::get_user_cart( $user_id );
        
        $product_id = isset( $wc_cart_item['product_id'] ) ? absint( $wc_cart_item['product_id'] ) : 0;
        $variation_id = isset( $wc_cart_item['variation_id'] ) ? absint( $wc_cart_item['variation_id'] ) : 0;
        $variation_data = isset( $wc_cart_item['variation'] ) ? $wc_cart_item['variation'] : array();
        if ( $variation_id > 0 ) {
            $variation_data = array_merge( array( 'variation_id' => $variation_id ), $variation_data );
        }
        $wc_quantity = isset( $wc_cart_item['quantity'] ) ? absint( $wc_cart_item['quantity'] ) : 1;
        
        $found_index = false;
        foreach ( $user_cart as $index => $item ) {
            if ( $item['product_id'] == $product_id && 
                 ( isset( $item['variation_data']['variation_id'] ) ? absint( $item['variation_data']['variation_id'] ) : 0 ) == $variation_id &&
                 ( empty( $variation_data ) || $item['variation_data'] == $variation_data ) ) {
                $found_index = $index;
                break;
            }
        }
        
        if ( $found_index !== false ) {
            $user_cart[ $found_index ]['key'] = $wc_cart_item_key;
            if ( $quantity_added !== null ) {
                $existing_qty = (int) ( $user_cart[ $found_index ]['quantity'] ?? 0 );
                $user_cart[ $found_index ]['quantity'] = $existing_qty + $quantity_added;
            } else {
                $user_cart[ $found_index ]['quantity'] = $wc_quantity;
            }
            if ( ! empty( $variation_data ) ) {
                $user_cart[ $found_index ]['variation_data'] = $variation_data;
            }
        } else {
            $qty = $quantity_added !== null ? $quantity_added : $wc_quantity;
            $user_cart[] = array(
                'key'            => $wc_cart_item_key,
                'product_id'     => $product_id,
                'quantity'       => $qty,
                'variation_data' => $variation_data,
                'added_at'       => time(),
            );
        }
        
        CartStorage::save_user_cart( $user_id, $user_cart );
    }
    
    /**
     * Normalize variation_data for WooCommerce add_to_cart.
     * When only variation_id is provided, load the variation and merge its attribute keys.
     *
     * @param int   $product_id   Parent product ID
     * @param array $variation_data Variation data (may contain only variation_id)
     * @return array Variation data with attribute keys (e.g. attribute_pa_color) for WC
     */
    public static function normalize_variation_data_for_wc( int $product_id, array $variation_data ): array {
        if ( empty( $variation_data ) || ! isset( $variation_data['variation_id'] ) ) {
            return $variation_data;
        }
        
        $variation_id = absint( $variation_data['variation_id'] );
        if ( $variation_id <= 0 ) {
            return $variation_data;
        }
        
        // Already have attribute keys (e.g. attribute_pa_*)? Use as is.
        $has_attributes = false;
        foreach ( array_keys( $variation_data ) as $key ) {
            if ( $key !== 'variation_id' && strpos( (string) $key, 'attribute_' ) === 0 ) {
                $has_attributes = true;
                break;
            }
        }
        if ( $has_attributes ) {
            return $variation_data;
        }
        
        $variation = wc_get_product( $variation_id );
        if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
            return $variation_data;
        }
        
        $attrs = $variation->get_variation_attributes();
        if ( empty( $attrs ) && function_exists( 'wc_get_product_variation_attributes' ) ) {
            $attrs = wc_get_product_variation_attributes( $variation_id );
        }
        if ( ! empty( $attrs ) ) {
            $variation_data = array_merge( $variation_data, $attrs );
        }
        
        return $variation_data;
    }
    
    /**
     * Build variation array for WC add_to_cart 4th parameter (attributes only).
     * Uses same key format as WC_Cart::add_to_cart: iterate parent attributes, key = 'attribute_' . sanitize_title( name ).
     *
     * @param array $variation_data Full variation_data (variation_id + optional attribute_* keys)
     * @param int   $parent_id      Optional parent product ID (if known); otherwise derived from variation_id
     * @return array Only attribute_* key => value for add_to_cart
     */
    public static function get_variation_attributes_for_add_to_cart( array $variation_data, $parent_id = 0 ): array {
        $variation_id = ! empty( $variation_data['variation_id'] ) ? absint( $variation_data['variation_id'] ) : 0;
        if ( $variation_id <= 0 ) {
            return self::get_variation_attributes_from_request( $variation_data );
        }
        if ( $parent_id <= 0 ) {
            $parent_id = wp_get_post_parent_id( $variation_id );
        }
        if ( $parent_id <= 0 ) {
            return self::get_variation_attributes_from_request( $variation_data );
        }
        $parent = wc_get_product( $parent_id );
        if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
            return self::get_variation_attributes_from_request( $variation_data );
        }
        $variation_values = function_exists( 'wc_get_product_variation_attributes' )
            ? wc_get_product_variation_attributes( $variation_id )
            : array();
        if ( empty( $variation_values ) ) {
            $variation_product = wc_get_product( $variation_id );
            if ( $variation_product && $variation_product->is_type( 'variation' ) && method_exists( $variation_product, 'get_variation_attributes' ) ) {
                $variation_values = $variation_product->get_variation_attributes();
            }
        }
        $out = array();
        foreach ( $parent->get_attributes() as $attribute ) {
            if ( empty( $attribute['is_variation'] ) ) {
                continue;
            }
            $name = is_array( $attribute ) ? ( isset( $attribute['name'] ) ? $attribute['name'] : '' ) : ( method_exists( $attribute, 'get_name' ) ? $attribute->get_name() : ( isset( $attribute['name'] ) ? $attribute['name'] : '' ) );
            if ( $name === '' ) {
                continue;
            }
            $attribute_key = 'attribute_' . sanitize_title( $name );
            $value         = isset( $variation_values[ $attribute_key ] ) ? $variation_values[ $attribute_key ] : '';
            if ( isset( $variation_data[ $attribute_key ] ) && $variation_data[ $attribute_key ] !== '' ) {
                $value = $variation_data[ $attribute_key ];
            }
            $out[ $attribute_key ] = is_string( $value ) ? $value : (string) $value;
        }
        return $out;
    }
    
    /**
     * Fallback: extract attribute_* keys from request variation_data.
     *
     * @param array $variation_data Variation data from request
     * @return array attribute_* key => value
     */
    private static function get_variation_attributes_from_request( array $variation_data ): array {
        $out = array();
        foreach ( $variation_data as $key => $value ) {
            if ( is_string( $key ) && strpos( $key, 'attribute_' ) === 0 ) {
                $out[ $key ] = is_string( $value ) ? $value : (string) $value;
            }
        }
        return $out;
    }
    
    /**
     * Validate guest token format
     *
     * @param string $guest_token Guest token
     * @return bool True if valid
     */
    private function validate_guest_token( string $guest_token ): bool {
        if ( empty( $guest_token ) ) {
            return false;
        }
        
        return preg_match( '/^guest_\d+_[a-zA-Z0-9]+_[a-f0-9]{8}$/', $guest_token ) === 1;
    }
}
