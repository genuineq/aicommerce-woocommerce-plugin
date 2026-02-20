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
        
        // Add to cart based on identifier type
        if ( ! empty( $guest_token ) ) {
            // Guest cart - save to storage
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
            
            // Send SSE event for guest_token
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
                    
                    $wc_cart_item_key = $wc_cart->add_to_cart( $product_id, $quantity, $variation_id, $variation_data_array );
                    
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
        
        // Validate that either guest_token or user_id is provided
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
        
        // Validate that both are not provided
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
            
            $existing_cart_item_key = null;
            $existing_quantity = 0;
            foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
                if ( $cart_item['product_id'] == $product_id && 
                     $cart_item['variation_id'] == $variation_id &&
                     ( empty( $variation_data ) || $cart_item['variation'] == $variation_data ) ) {
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
                $cart_item_key = $cart->add_to_cart( $product_id, $quantity, $variation_id, $variation_data );
                
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
                $wc_cart_items[] = array(
                    'key'            => $cart_item_key,
                    'product_id'     => $cart_item['product_id'],
                    'quantity'       => $cart_item['quantity'],
                    'variation_data' => isset( $cart_item['variation'] ) ? $cart_item['variation'] : array(),
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
