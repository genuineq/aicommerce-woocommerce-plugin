<?php
/**
 * Cart API Endpoints
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Cart API class. */
class CartAPI {

    /**
     * Constructor.
     */
    public function __construct() {
        /** Register REST API routes during REST API initialization. */
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Update WooCommerce persistent cart user meta from the AICommerce user cart.
     *
     * This keeps WooCommerce in sync for logged-in users even when the cart
     * is manipulated through server-to-server API calls without a browser session.
     *
     * @param int $user_id The user ID.
     * @return void
     */
    private function sync_wc_persistent_cart_from_user_cart( int $user_id ): void {
        /** Stop if the provided user ID is invalid. */
        if ( $user_id <= 0 ) {
            return;
        }

        /** Load the stored AICommerce cart items for the user. */
        $items = CartStorage::get_user_cart( $user_id );

        /** Build the WooCommerce persistent cart user meta key for the current site. */
        $wc_persistent_key = '_woocommerce_persistent_cart_' . get_current_blog_id();

        /** Load the existing WooCommerce persistent cart data. */
        $persistent_cart   = get_user_meta( $user_id, $wc_persistent_key, true );

        /** Initialize the persistent cart structure when the stored value is invalid. */
        if ( ! is_array( $persistent_cart ) ) {
            $persistent_cart = array();
        }

        /** Initialize the normalized WooCommerce cart array. */
        $cart = array();

        /** Convert each stored AICommerce item into WooCommerce persistent cart format. */
        foreach ( $items as $item ) {
            /** Extract the parent product ID. */
            $product_id     = (int) ( $item['product_id'] ?? 0 );

            /** Extract the item quantity. */
            $quantity       = (int) ( $item['quantity'] ?? 0 );

            /** Extract the variation data if present and valid. */
            $variation_data = isset( $item['variation_data'] ) && is_array( $item['variation_data'] ) ? $item['variation_data'] : array();

            /** Extract the variation ID when available. */
            $variation_id   = isset( $variation_data['variation_id'] ) ? absint( $variation_data['variation_id'] ) : 0;

            /** Skip invalid cart items. */
            if ( $product_id <= 0 || $quantity <= 0 ) {
                continue;
            }

            /** Reuse the existing cart key when available, otherwise generate a deterministic fallback key. */
            $key = isset( $item['key'] ) && is_string( $item['key'] ) && $item['key'] !== ''
                ? $item['key']
                : 'aicom_' . md5( $product_id . ':' . $variation_id . ':' . wp_json_encode( $variation_data ) );

            /** Build the WooCommerce-compatible variation attributes array. */
            $variation_attrs = self::get_variation_attributes_for_add_to_cart( $variation_data, $product_id );

            /** Store the normalized cart row under the resolved cart key. */
            $cart[ $key ] = array(
                /** Store the parent product ID. */
                'product_id'   => $product_id,

                /** Store the selected variation ID. */
                'variation_id' => $variation_id,

                /** Store the selected variation attributes. */
                'variation'    => $variation_attrs,

                /** Store the requested quantity. */
                'quantity'     => $quantity,
            );
        }

        /** Replace the WooCommerce persistent cart content with the normalized cart. */
        $persistent_cart['cart'] = $cart;

        /** Save the updated WooCommerce persistent cart to user meta. */
        update_user_meta( $user_id, $wc_persistent_key, $persistent_cart );
    }

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public function register_routes(): void {
        /** Define the REST API namespace for cart endpoints. */
        $namespace = 'aicommerce/v1';

        /** Register the add-to-cart endpoint. */
        register_rest_route(
            $namespace,
            '/cart/add',
            array(
                /** Allow POST requests for add-to-cart operations. */
                'methods'             => 'POST',

                /** Set the callback that handles add-to-cart requests. */
                'callback'            => array( $this, 'add_to_cart' ),

                /** Allow public access and perform validation inside the callback. */
                'permission_callback' => '__return_true',
            )
        );

        /** Register the get-cart endpoint. */
        register_rest_route(
            $namespace,
            '/cart',
            array(
                /** Allow GET requests for cart retrieval. */
                'methods'             => 'GET',

                /** Set the callback that handles cart retrieval requests. */
                'callback'            => array( $this, 'get_cart' ),

                /** Allow public access and perform validation inside the callback. */
                'permission_callback' => '__return_true',
            )
        );

        /** Register the endpoint that syncs stored cart data into the WooCommerce session cart. */
        register_rest_route(
            $namespace,
            '/cart/sync',
            array(
                /** Allow POST requests for cart sync operations. */
                'methods'             => 'POST',

                /** Set the callback that handles cart sync requests. */
                'callback'            => array( $this, 'sync_to_wc_session' ),

                /** Allow public access and perform validation inside the callback. */
                'permission_callback' => '__return_true',
            )
        );

        /** Register the lightweight cart hash endpoint used for polling. */
        register_rest_route(
            $namespace,
            '/cart/hash',
            array(
                /** Allow GET requests for cart hash retrieval. */
                'methods'             => 'GET',

                /** Set the callback that handles cart hash requests. */
                'callback'            => array( $this, 'get_cart_hash' ),

                /** Allow public access and perform validation inside the callback. */
                'permission_callback' => '__return_true',

                /** Define the accepted route arguments. */
                'args'                => array(
                    /** Define the optional guest token argument. */
                    'guest_token' => array(
                        /** Define the expected argument type. */
                        'type'     => 'string',

                        /** Mark the argument as optional. */
                        'required' => false,
                    ),
                ),
            )
        );

        /** Register the remove-from-cart endpoint. */
        register_rest_route(
            $namespace,
            '/cart/remove',
            array(
                /** Allow POST requests for remove-from-cart operations. */
                'methods'             => 'POST',

                /** Set the callback that handles remove-from-cart requests. */
                'callback'            => array( $this, 'remove_from_cart' ),

                /** Allow public access and perform validation inside the callback. */
                'permission_callback' => '__return_true',

                /** Define the accepted route arguments. */
                'args'                => array(
                    /** Define the required product ID argument. */
                    'product_id'     => array(
                        /** Describe the product ID argument. */
                        'description' => __( 'Product ID to remove', 'aicommerce' ),

                        /** Define the expected argument type. */
                        'type'        => 'integer',

                        /** Mark the argument as required. */
                        'required'    => true,

                        /** Require product IDs greater than or equal to 1. */
                        'minimum'     => 1,
                    ),

                    /** Define the optional variation data argument. */
                    'variation_data' => array(
                        /** Describe the variation data argument. */
                        'description' => __( 'Variation data for variable products (must include variation_id)', 'aicommerce' ),

                        /** Define the expected argument type. */
                        'type'        => 'object',

                        /** Mark the argument as optional. */
                        'required'    => false,
                    ),
                ),
            )
        );
    }

    /**
     * Handle add-to-cart requests.
     *
     * Supports both guest_token and user_id identifiers.
     *
     * @param \WP_REST_Request $request The incoming REST request.
     * @return \WP_REST_Response
     */
    public function add_to_cart( \WP_REST_Request $request ): \WP_REST_Response {
        /** Validate the API request headers and signature. */
        $validation = APIValidator::validate_request( $request );

        /** Return a validation error response if the request is invalid. */
        if ( ! $validation['valid'] ) {
            return APIValidator::error_response( $validation );
        }

        /** Get the guest token from the request. */
        $guest_token = $request->get_param( 'guest_token' );

        /** Get the user ID from the request. */
        $user_id = $request->get_param( 'user_id' );

        /** Get the product ID from the request. */
        $product_id = $request->get_param( 'product_id' );

        /** Get the requested quantity from the request. */
        $quantity = $request->get_param( 'quantity' );

        /** Get the variation data from the request. */
        $variation_data = $request->get_param( 'variation_data' );

        /** Initialize the normalized user ID as null. */
        $user_id_int = null;

        /** Normalize the user ID when one was provided. */
        if ( ! empty( $user_id ) || ( isset( $user_id ) && $user_id !== '' && $user_id !== null ) ) {
            /** Convert the provided user ID to an absolute integer. */
            $user_id_int = absint( $user_id );

            /** Reset the normalized user ID if the value is invalid. */
            if ( $user_id_int <= 0 ) {
                $user_id_int = null;
            }
        }

        /** Return an error when neither guest_token nor user_id was provided. */
        if ( empty( $guest_token ) && empty( $user_id_int ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'missing_identifier',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Either guest_token or user_id is required.', 'aicommerce' ),
                ),
                400
            );
        }

        /** Return an error when both guest_token and user_id were provided together. */
        if ( ! empty( $guest_token ) && ! empty( $user_id_int ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'conflicting_identifiers',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Provide either guest_token or user_id, not both.', 'aicommerce' ),
                ),
                400
            );
        }

        /** Return an error when the provided guest token format is invalid. */
        if ( ! empty( $guest_token ) && ! $this->validate_guest_token( $guest_token ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'invalid_guest_token',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Invalid guest token format.', 'aicommerce' ),
                ),
                400
            );
        }

        /** Validate the provided user ID when one is present. */
        if ( ! empty( $user_id_int ) ) {
            /** Return an error when the user does not exist. */
            if ( ! get_user_by( 'id', $user_id_int ) ) {
                return new \WP_REST_Response(
                    array(
                        /** Indicate that the request failed. */
                        'success' => false,

                        /** Define the machine-readable error code. */
                        'code'    => 'invalid_user_id',

                        /** Define the translated human-readable error message. */
                        'message' => __( 'Invalid user ID.', 'aicommerce' ),
                    ),
                    400
                );
            }

            /** Replace the raw user ID with the normalized user ID. */
            $user_id = $user_id_int;
        } else {
            /** Keep the user ID null when not provided or invalid. */
            $user_id = null;
        }

        /** Return an error when product_id is missing or invalid. */
        if ( empty( $product_id ) || ! is_numeric( $product_id ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'missing_product_id',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Product ID is required.', 'aicommerce' ),
                ),
                400
            );
        }

        /** Normalize the product ID. */
        $product_id = absint( $product_id );

        /** Normalize the quantity and default it to 1. */
        $quantity = ! empty( $quantity ) ? absint( $quantity ) : 1;

        /** Load the WooCommerce product object. */
        $product = wc_get_product( $product_id );

        /** Return an error when the product does not exist. */
        if ( ! $product ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'product_not_found',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Product not found.', 'aicommerce' ),
                ),
                404
            );
        }

        /** Initialize the normalized variation data array. */
        $variation_data_array = array();

        /** Copy provided variation data when it is a valid array. */
        if ( ! empty( $variation_data ) && is_array( $variation_data ) ) {
            $variation_data_array = $variation_data;
        }

        /** Require variation_id for variable products. */
        if ( $product->is_type( 'variable' ) ) {
            /** Return an error when a variable product request does not include variation_id. */
            if ( empty( $variation_data_array['variation_id'] ) ) {
                return new \WP_REST_Response(
                    array(
                        /** Indicate that the request failed. */
                        'success' => false,

                        /** Define the machine-readable error code. */
                        'code'    => 'variation_required',

                        /** Define the translated human-readable error message. */
                        'message' => __( 'Variable products require variation_data.variation_id.', 'aicommerce' ),
                    ),
                    400
                );
            }
        }

        /** Normalize variation data for WooCommerce compatibility. */
        $variation_data_array = self::normalize_variation_data_for_wc( $product_id, $variation_data_array );

        /** Handle guest cart add-to-cart requests. */
        if ( ! empty( $guest_token ) ) {
            /** Add the item to the guest cart. */
            $cart = CartStorage::add_item( $guest_token, $product_id, $quantity, $variation_data_array );

            /** Get the updated guest cart item count. */
            $cart_count = CartStorage::get_cart_count( $guest_token );

            /** Store the identifier used for this cart operation. */
            $identifier = $guest_token;

            /** Return an error if the cart update failed. */
            if ( $cart === false ) {
                return new \WP_REST_Response(
                    array(
                        /** Indicate that the request failed. */
                        'success' => false,

                        /** Define the machine-readable error code. */
                        'code'    => 'add_to_cart_failed',

                        /** Define the translated human-readable error message. */
                        'message' => __( 'Failed to add item to cart.', 'aicommerce' ),
                    ),
                    500
                );
            }

            /** Mark the guest cart as managed by AICommerce. */
            CartStorage::mark_as_ai_cart( $guest_token );

            /** Send a server-sent event to notify listeners that the guest cart changed. */
            SSE::send_event( $guest_token, 'cart_updated', array(
                /** Describe the cart action performed. */
                'action'     => 'item_added',

                /** Include the added product ID. */
                'product_id' => $product_id,

                /** Include the added quantity. */
                'quantity'   => $quantity,

                /** Include the updated cart count. */
                'cart_count' => $cart_count,
            ) );
        } elseif ( ! empty( $user_id ) && $user_id > 0 ) {
            /** Add the item to the persistent AICommerce user cart. */
            $cart = CartStorage::add_item_to_user_cart( $user_id, $product_id, $quantity, $variation_data_array );

            /** Get the updated user cart item count. */
            $cart_count = CartStorage::get_user_cart_count( $user_id );

            /** Return an error if the cart update failed. */
            if ( $cart === false ) {
                return new \WP_REST_Response(
                    array(
                        /** Indicate that the request failed. */
                        'success' => false,

                        /** Define the machine-readable error code. */
                        'code'    => 'add_to_cart_failed',

                        /** Define the translated human-readable error message. */
                        'message' => __( 'Failed to add item to cart.', 'aicommerce' ),
                    ),
                    500
                );
            }

            /** Sync the AICommerce user cart into WooCommerce persistent cart user meta. */
            $this->sync_wc_persistent_cart_from_user_cart( (int) $user_id );

            /** Mark the user cart as managed by AICommerce. */
            CartStorage::mark_as_ai_user_cart( $user_id );

            /** Build an internal identifier for the user cart context. */
            $identifier = 'user_' . $user_id;
        } else {
            /** Return an error when no valid identifier is available after normalization. */
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'missing_identifier',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Either guest_token or user_id is required.', 'aicommerce' ),
                ),
                400
            );
        }

        /** Return a successful add-to-cart response. */
        return new \WP_REST_Response(
            array(
                /** Indicate that the request succeeded. */
                'success'    => true,

                /** Define the translated success message. */
                'message'    => __( 'Item added to cart successfully.', 'aicommerce' ),

                /** Include the updated cart item count. */
                'cart_count' => $cart_count,
            ),
            200
        );
    }

    /**
     * Handle get-cart requests.
     *
     * Supports both guest_token and user_id identifiers.
     *
     * @param \WP_REST_Request $request The incoming REST request.
     * @return \WP_REST_Response
     */
    public function get_cart( \WP_REST_Request $request ): \WP_REST_Response {
        /** Validate the API request headers and signature. */
        $validation = APIValidator::validate_request( $request );

        /** Return a validation error response if the request is invalid. */
        if ( ! $validation['valid'] ) {
            return APIValidator::error_response( $validation );
        }

        /** Get the guest token from the request. */
        $guest_token = $request->get_param( 'guest_token' );

        /** Get the user ID from the request. */
        $user_id = $request->get_param( 'user_id' );

        /** Return an error when neither guest_token nor user_id was provided. */
        if ( empty( $guest_token ) && empty( $user_id ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'missing_identifier',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Either guest_token or user_id is required.', 'aicommerce' ),
                ),
                400
            );
        }

        /** Return an error when both guest_token and user_id were provided together. */
        if ( ! empty( $guest_token ) && ! empty( $user_id ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'conflicting_identifiers',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Provide either guest_token or user_id, not both.', 'aicommerce' ),
                ),
                400
            );
        }

        /** Validate and normalize the user ID when one was provided. */
        if ( ! empty( $user_id ) ) {
            /** Normalize the provided user ID. */
            $user_id = absint( $user_id );

            /** Return an error when the normalized user ID is invalid or does not exist. */
            if ( $user_id <= 0 || ! get_user_by( 'id', $user_id ) ) {
                return new \WP_REST_Response(
                    array(
                        /** Indicate that the request failed. */
                        'success' => false,

                        /** Define the machine-readable error code. */
                        'code'    => 'invalid_user_id',

                        /** Define the translated human-readable error message. */
                        'message' => __( 'Invalid user ID.', 'aicommerce' ),
                    ),
                    400
                );
            }
        }

        /** Load the guest cart when a guest token was provided. */
        if ( ! empty( $guest_token ) ) {
            /** Retrieve guest cart items from storage. */
            $cart       = CartStorage::get_cart( $guest_token );

            /** Retrieve guest cart item count from storage. */
            $cart_count = CartStorage::get_cart_count( $guest_token );
        } elseif ( ! empty( $user_id ) ) {
            /** Retrieve user cart items from storage. */
            $cart       = CartStorage::get_user_cart( $user_id );

            /** Retrieve user cart item count from storage. */
            $cart_count = CartStorage::get_user_cart_count( $user_id );
        } else {
            /** Return an error when no valid identifier is available after validation. */
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'missing_identifier',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Either guest_token or user_id is required.', 'aicommerce' ),
                ),
                400
            );
        }

        /** Enrich raw cart items with product details. */
        $cart = $this->enrich_cart_items( $cart );

        /** Return the cart payload and cart count. */
        return new \WP_REST_Response(
            array(
                /** Indicate that the request succeeded. */
                'success'    => true,

                /** Include the enriched cart items. */
                'cart'       => $cart,

                /** Include the total item count. */
                'cart_count' => $cart_count,
            ),
            200
        );
    }

    /**
     * Enrich cart items with product details.
     *
     * Adds name, SKU, image URL, and product URL to each cart item.
     * For variable products, the variation image is preferred when available,
     * with fallback to the parent product image.
     *
     * @param array $items Raw cart items from storage.
     * @return array
     */
    private function enrich_cart_items( array $items ): array {
        /** Iterate by reference so product details can be appended directly to each item. */
        foreach ( $items as &$item ) {
            /** Extract the parent product ID from the cart item. */
            $product_id   = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;

            /** Extract the variation ID from variation_data when present. */
            $variation_id = isset( $item['variation_data']['variation_id'] )
                ? absint( $item['variation_data']['variation_id'] )
                : 0;

            /** Set product_details to null and skip invalid items. */
            if ( $product_id <= 0 ) {
                $item['product_details'] = null;
                continue;
            }

            /** Load the variation product when a variation ID exists. */
            $product_to_use = $variation_id > 0 ? wc_get_product( $variation_id ) : null;

            /** Load the parent product. */
            $parent_product = wc_get_product( $product_id );

            /** Set product_details to null and skip items whose parent product does not exist. */
            if ( ! $parent_product ) {
                $item['product_details'] = null;
                continue;
            }

            /** Prefer the variation product, otherwise use the parent product. */
            $active_product = $product_to_use ?: $parent_product;

            /** Get the image ID from the active product. */
            $image_id  = $active_product->get_image_id();

            /** Resolve the product image URL or fall back to the WooCommerce placeholder image. */
            $image_url = $image_id
                ? wp_get_attachment_image_url( $image_id, 'woocommerce_single' )
                : wc_placeholder_img_src( 'woocommerce_single' );

            /** Append resolved product details to the cart item. */
            $item['product_details'] = array(
                /** Include the parent product name. */
                'name'  => $parent_product->get_name(),

                /** Prefer the variation SKU and fall back to the parent SKU. */
                'sku'   => $active_product->get_sku() ?: $parent_product->get_sku(),

                /** Include the resolved image URL or null when unavailable. */
                'image' => $image_url ?: null,

                /** Include the parent product permalink or null when unavailable. */
                'url'   => get_permalink( $product_id ) ?: null,
            );
        }

        /** Break the reference created by the foreach loop. */
        unset( $item );

        /** Return the enriched cart items. */
        return $items;
    }

    /**
     * Handle remove-from-cart requests.
     *
     * Supports both guest_token and user_id identifiers.
     *
     * @param \WP_REST_Request $request The incoming REST request.
     * @return \WP_REST_Response
     */
    public function remove_from_cart( \WP_REST_Request $request ): \WP_REST_Response {
        /** Validate the API request headers and signature. */
        $validation = APIValidator::validate_request( $request );

        /** Return a validation error response if the request is invalid. */
        if ( ! $validation['valid'] ) {
            return APIValidator::error_response( $validation );
        }

        /** Get the guest token from the request. */
        $guest_token    = $request->get_param( 'guest_token' );

        /** Get the raw user ID parameter from the request. */
        $user_id_param  = $request->get_param( 'user_id' );

        /** Get and normalize the product ID from the request. */
        $product_id     = absint( $request->get_param( 'product_id' ) );

        /** Get the variation data from the request. */
        $variation_data = $request->get_param( 'variation_data' );

        /** Return an error when the provided product ID is invalid. */
        if ( $product_id <= 0 ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'invalid_product_id',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Valid product_id is required.', 'aicommerce' ),
                ),
                400
            );
        }

        /** Initialize the normalized user ID as null. */
        $user_id_int = null;

        /** Normalize the user ID when one was provided. */
        if ( ! empty( $user_id_param ) || ( isset( $user_id_param ) && $user_id_param !== '' && $user_id_param !== null ) ) {
            /** Convert the provided user ID to an absolute integer. */
            $user_id_int = absint( $user_id_param );

            /** Reset the normalized user ID if the value is invalid. */
            if ( $user_id_int <= 0 ) {
                $user_id_int = null;
            }
        }

        /** Return an error when neither guest_token nor user_id was provided. */
        if ( empty( $guest_token ) && empty( $user_id_int ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'missing_identifier',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Either guest_token or user_id is required.', 'aicommerce' ),
                ),
                400
            );
        }

        /** Return an error when both guest_token and user_id were provided together. */
        if ( ! empty( $guest_token ) && ! empty( $user_id_int ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'conflicting_identifiers',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Provide either guest_token or user_id, not both.', 'aicommerce' ),
                ),
                400
            );
        }

        /** Return an error when the provided guest token format is invalid. */
        if ( ! empty( $guest_token ) && ! $this->validate_guest_token( $guest_token ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'invalid_guest_token',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Invalid guest_token format.', 'aicommerce' ),
                ),
                400
            );
        }

        /** Return an error when the provided user ID does not exist. */
        if ( ! empty( $user_id_int ) && ! get_user_by( 'id', $user_id_int ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'invalid_user_id',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Invalid user ID.', 'aicommerce' ),
                ),
                400
            );
        }

        /** Normalize variation_data to an array. */
        $variation_data_array = is_array( $variation_data ) ? $variation_data : array();

        /** Handle guest cart removal requests. */
        if ( ! empty( $guest_token ) ) {
            /** Remove the item from the guest cart. */
            $cart = CartStorage::remove_item( $guest_token, $product_id, $variation_data_array );

            /** Return an error when the removal failed. */
            if ( $cart === false ) {
                return new \WP_REST_Response(
                    array(
                        /** Indicate that the request failed. */
                        'success' => false,

                        /** Define the machine-readable error code. */
                        'code'    => 'remove_failed',

                        /** Define the translated human-readable error message. */
                        'message' => __( 'Failed to remove item from cart.', 'aicommerce' ),
                    ),
                    500
                );
            }

            /** Get the updated guest cart count. */
            $cart_count = CartStorage::get_cart_count( $guest_token );

            /** Send a server-sent event to notify listeners that the guest cart changed. */
            SSE::send_event( $guest_token, 'cart_updated', array(
                /** Describe the cart action performed. */
                'action'     => 'item_removed',

                /** Include the removed product ID. */
                'product_id' => $product_id,

                /** Include the updated cart item count. */
                'cart_count' => $cart_count,
            ) );

            /** Return a successful guest cart removal response. */
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request succeeded. */
                    'success'    => true,

                    /** Define the translated success message. */
                    'message'    => __( 'Item removed from cart.', 'aicommerce' ),

                    /** Include the updated cart item count. */
                    'cart_count' => $cart_count,
                ),
                200
            );
        }

        /** Use the normalized user ID for the user cart branch. */
        $user_id      = $user_id_int;

        /** Extract the variation ID when present. */
        $variation_id = ! empty( $variation_data_array['variation_id'] ) ? absint( $variation_data_array['variation_id'] ) : 0;

        /** Remove the item from the persistent AICommerce user cart. */
        $cart = CartStorage::remove_item_from_user_cart( $user_id, $product_id, $variation_data_array );

        /** Return an error when the removal failed. */
        if ( $cart === false ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'remove_failed',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Failed to remove item from cart.', 'aicommerce' ),
                ),
                500
            );
        }

        /** Sync the AICommerce user cart into WooCommerce persistent cart user meta. */
        $this->sync_wc_persistent_cart_from_user_cart( (int) $user_id );

        /** Get the updated user cart item count. */
        $cart_count = CartStorage::get_user_cart_count( $user_id );

        /** Return a successful user cart removal response. */
        return new \WP_REST_Response(
            array(
                /** Indicate that the request succeeded. */
                'success'    => true,

                /** Define the translated success message. */
                'message'    => __( 'Item removed from cart.', 'aicommerce' ),

                /** Include the updated cart item count. */
                'cart_count' => $cart_count,
            ),
            200
        );
    }

    /**
     * Sync the stored AICommerce cart into the WooCommerce session cart.
     *
     * This endpoint is called from the frontend to merge stored cart data into
     * the active WooCommerce browser session cart.
     *
     * Supports both guest_token and user_id identifiers.
     *
     * @param \WP_REST_Request $request The incoming REST request.
     * @return \WP_REST_Response
     */
    public function sync_to_wc_session( \WP_REST_Request $request ): \WP_REST_Response {
        /** Get the guest token from the request. */
        $guest_token = $request->get_param( 'guest_token' );

        /** Get the user ID from the request. */
        $user_id = $request->get_param( 'user_id' );

        /** Initialize the normalized user ID as null. */
        $user_id_int = null;

        /** Normalize the user ID when one was provided. */
        if ( ! empty( $user_id ) || ( isset( $user_id ) && $user_id !== '' && $user_id !== null ) ) {
            /** Convert the provided user ID to an absolute integer. */
            $user_id_int = absint( $user_id );

            /** Reset the normalized user ID if the value is invalid. */
            if ( $user_id_int <= 0 ) {
                $user_id_int = null;
            }
        }

        /** Resolve the current logged-in user ID when no explicit identifier was provided. */
        if ( empty( $guest_token ) && empty( $user_id_int ) && is_user_logged_in() ) {
            $user_id_int = get_current_user_id();
        }

        /** Return an error when neither guest_token nor user_id is available. */
        if ( empty( $guest_token ) && empty( $user_id_int ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'missing_identifier',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Either guest_token or user_id is required.', 'aicommerce' ),
                ),
                400
            );
        }

        /** Validate the provided user ID when one is available. */
        if ( ! empty( $user_id_int ) ) {
            /** Return an error when the user does not exist. */
            if ( ! get_user_by( 'id', $user_id_int ) ) {
                return new \WP_REST_Response(
                    array(
                        /** Indicate that the request failed. */
                        'success' => false,

                        /** Define the machine-readable error code. */
                        'code'    => 'invalid_user_id',

                        /** Define the translated human-readable error message. */
                        'message' => __( 'Invalid user ID.', 'aicommerce' ),
                    ),
                    400
                );
            }

            /** Replace the raw user ID with the normalized user ID. */
            $user_id = $user_id_int;
        } else {
            /** Keep the user ID null when not provided or invalid. */
            $user_id = null;
        }

        /** Load the guest cart when a guest token is available. */
        if ( ! empty( $guest_token ) ) {
            /** Retrieve the guest cart from storage. */
            $guest_cart = CartStorage::get_cart( $guest_token );
        } elseif ( ! empty( $user_id ) && $user_id > 0 ) {
            /** Retrieve the user cart from storage. */
            $guest_cart = CartStorage::get_user_cart( $user_id );
        } else {
            /** Return an error when no valid identifier is available after normalization. */
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'missing_identifier',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Either guest_token or user_id is required.', 'aicommerce' ),
                ),
                400
            );
        }

        /** Return early when there is nothing to sync. */
        if ( empty( $guest_cart ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request succeeded. */
                    'success' => true,

                    /** Define the translated informational message. */
                    'message' => __( 'Cart is already empty.', 'aicommerce' ),

                    /** Indicate that no sync was performed. */
                    'synced'  => false,
                ),
                200
            );
        }

        /** Return an error when WooCommerce is not active. */
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'woocommerce_not_available',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'WooCommerce plugin is not active.', 'aicommerce' ),
                ),
                500
            );
        }

        /** Return an error when the WC() helper function is not available. */
        if ( ! function_exists( 'WC' ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'woocommerce_not_available',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'WooCommerce is not initialized.', 'aicommerce' ),
                ),
                500
            );
        }

        /** Ensure the WooCommerce cart is loaded from the session before syncing items. */
        if ( ! did_action( 'woocommerce_load_cart_from_session' ) ) {
            /** Prefer the official WooCommerce cart loader when available. */
            if ( function_exists( 'wc_load_cart' ) ) {
                wc_load_cart();
            } else {
                /** Load the WooCommerce singleton instance. */
                $wc = WC();

                /** Attempt a manual session bootstrap when the cart is missing. */
                if ( ! $wc || ! isset( $wc->cart ) ) {
                    /** Initialize the WooCommerce session handler if needed. */
                    if ( ! WC()->session ) {
                        WC()->session = new \WC_Session_Handler();
                        WC()->session->init();
                    }

                    /** Return an error when the cart still cannot be initialized. */
                    if ( ! $wc || ! isset( $wc->cart ) ) {
                        return new \WP_REST_Response(
                            array(
                                /** Indicate that the request failed. */
                                'success' => false,

                                /** Define the machine-readable error code. */
                                'code'    => 'woocommerce_not_available',

                                /** Define the translated human-readable error message. */
                                'message' => __( 'WooCommerce cart could not be initialized.', 'aicommerce' ),

                                /** Include debug information only in WordPress debug mode. */
                                'debug'   => defined( 'WP_DEBUG' ) && WP_DEBUG ? array(
                                    /** Report whether the WC helper function exists. */
                                    'wc_exists' => function_exists( 'WC' ),

                                    /** Report whether the WooCommerce singleton instance exists. */
                                    'wc_instance' => $wc ? 'exists' : 'null',

                                    /** Report whether the cart property is available. */
                                    'cart_exists' => isset( $wc->cart ) ? 'yes' : 'no',
                                ) : null,
                            ),
                            500
                        );
                    }
                }
            }
        }

        /** Load the active WooCommerce cart object. */
        $cart = WC()->cart;

        /** Return an error when the WooCommerce cart object is unavailable or invalid. */
        if ( ! $cart || ! is_a( $cart, 'WC_Cart' ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'woocommerce_not_available',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'WooCommerce cart is not available.', 'aicommerce' ),

                    /** Include debug information only in WordPress debug mode. */
                    'debug'   => defined( 'WP_DEBUG' ) && WP_DEBUG ? array(
                        /** Report the PHP type of the cart variable. */
                        'cart_type' => gettype( $cart ),

                        /** Report whether the object is a WC_Cart instance. */
                        'is_cart' => is_a( $cart, 'WC_Cart' ) ? 'yes' : 'no',
                    ) : null,
                ),
                500
            );
        }

        /** Initialize the count of successfully synced items. */
        $synced_count = 0;

        /** Initialize the list of sync errors. */
        $errors = array();

        /** Iterate over each stored cart item and merge it into the WooCommerce session cart. */
        foreach ( $guest_cart as $item ) {
            /** Extract the product ID from the stored cart item. */
            $product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;

            /** Extract the quantity from the stored cart item. */
            $quantity = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 1;

            /** Extract variation data from the stored cart item. */
            $variation_data = isset( $item['variation_data'] ) ? $item['variation_data'] : array();

            /** Skip invalid cart items. */
            if ( $product_id <= 0 ) {
                continue;
            }

            /** Load the WooCommerce product object. */
            $product = wc_get_product( $product_id );

            /** Record an error and skip items that are unavailable or not purchasable. */
            if ( ! $product || ! $product->is_purchasable() ) {
                $errors[] = sprintf(
                    __( 'Product ID %d is not available.', 'aicommerce' ),
                    $product_id
                );
                continue;
            }

            /** Initialize the variation ID. */
            $variation_id = 0;

            /** Extract the variation ID when present in variation data. */
            if ( ! empty( $variation_data ) && isset( $variation_data['variation_id'] ) ) {
                $variation_id = absint( $variation_data['variation_id'] );
            }

            /** Normalize variation data for WooCommerce compatibility. */
            $variation_data = CartAPI::normalize_variation_data_for_wc( $product_id, $variation_data );

            /** Build the attribute-only variation array for add_to_cart. */
            $variation_attrs = CartAPI::get_variation_attributes_for_add_to_cart( $variation_data, $product_id );

            /** Initialize the matching WooCommerce cart item key. */
            $existing_cart_item_key = null;

            /** Initialize the quantity of the matching WooCommerce cart item. */
            $existing_quantity = 0;

            /** Search for an existing matching item already present in the WooCommerce cart. */
            foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
                /** Match by product, variation, and variation attributes. */
                if ( $cart_item['product_id'] == $product_id &&
                     $cart_item['variation_id'] == $variation_id &&
                     ( empty( $variation_attrs ) || $cart_item['variation'] == $variation_attrs ) ) {
                    /** Store the matching WooCommerce cart item key. */
                    $existing_cart_item_key = $cart_item_key;

                    /** Store the current quantity already present in the cart. */
                    $existing_quantity = $cart_item['quantity'];

                    /** Stop scanning after the first match. */
                    break;
                }
            }

            /** Update quantity when the product already exists in the WooCommerce cart. */
            if ( $existing_cart_item_key ) {
                /** Increase quantity only when the WooCommerce cart has less than the stored desired quantity. */
                if ( $existing_quantity < $quantity ) {
                    $cart->set_quantity( $existing_cart_item_key, $quantity );
                    $synced_count++;
                }
            } else {
                /** Add the item to the WooCommerce cart when it does not exist yet. */
                $cart_item_key = $cart->add_to_cart( $product_id, $quantity, $variation_id, $variation_attrs );

                /** Increment the synced count on success. */
                if ( $cart_item_key ) {
                    $synced_count++;
                } else {
                    /** Record a sync error when add_to_cart fails. */
                    $errors[] = sprintf(
                        __( 'Failed to add product ID %d to cart.', 'aicommerce' ),
                        $product_id
                    );
                }
            }
        }

        /** Persist the resolved WooCommerce cart state back to user meta when syncing a user cart. */
        if ( ! empty( $user_id ) && $user_id > 0 ) {
            /** Initialize the normalized user cart items array. */
            $wc_cart_items = array();

            /** Convert each WooCommerce cart item into AICommerce user cart storage format. */
            foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
                /** Extract variation attributes from the WooCommerce cart item. */
                $variation_data = isset( $cart_item['variation'] ) ? $cart_item['variation'] : array();

                /** Re-add variation_id when it exists on the WooCommerce cart item. */
                if ( ! empty( $cart_item['variation_id'] ) ) {
                    $variation_data = array_merge( array( 'variation_id' => (int) $cart_item['variation_id'] ), $variation_data );
                }

                /** Append the normalized cart item to the AICommerce user cart array. */
                $wc_cart_items[] = array(
                    /** Store the WooCommerce cart item key. */
                    'key'            => $cart_item_key,

                    /** Store the parent product ID. */
                    'product_id'     => $cart_item['product_id'],

                    /** Store the current cart quantity. */
                    'quantity'       => $cart_item['quantity'],

                    /** Store variation metadata. */
                    'variation_data' => $variation_data,

                    /** Store the current timestamp. */
                    'added_at'       => time(),
                );
            }

            /** Save the normalized user cart back to AICommerce storage. */
            CartStorage::save_user_cart( $user_id, $wc_cart_items );
        }

        /** Recalculate WooCommerce cart totals after sync. */
        $cart->calculate_totals();

        /** Persist the current WooCommerce cart state into the session. */
        if ( WC()->session ) {
            WC()->session->set( 'cart', $cart->get_cart_for_session() );
        }

        /** Return a successful cart sync response. */
        return new \WP_REST_Response(
            array(
                /** Indicate that the request succeeded. */
                'success'      => true,

                /** Define the translated success message. */
                'message'      => sprintf(
                    __( 'Synced %d items to cart.', 'aicommerce' ),
                    $synced_count
                ),

                /** Include the number of items that were synced. */
                'synced_count' => $synced_count,

                /** Include the number of stored source items examined. */
                'total_items'  => count( $guest_cart ),

                /** Include any item-level sync errors. */
                'errors'       => $errors,
            ),
            200
        );
    }

    /**
     * Save a user cart item with its WooCommerce cart key.
     *
     * When $quantity_added is provided in API context, the existing quantity is incremented.
     * Otherwise the quantity is taken directly from the WooCommerce cart item.
     *
     * @param int      $user_id The user ID.
     * @param string   $wc_cart_item_key The WooCommerce cart item key.
     * @param array    $wc_cart_item The WooCommerce cart item data.
     * @param int|null $quantity_added Optional quantity added in this request.
     * @return void
     */
    private function save_user_cart_item_with_wc_key( int $user_id, string $wc_cart_item_key, array $wc_cart_item, ?int $quantity_added = null ): void {
        /** Load the stored user cart. */
        $user_cart = CartStorage::get_user_cart( $user_id );

        /** Extract the parent product ID from the WooCommerce cart item. */
        $product_id = isset( $wc_cart_item['product_id'] ) ? absint( $wc_cart_item['product_id'] ) : 0;

        /** Extract the variation ID from the WooCommerce cart item. */
        $variation_id = isset( $wc_cart_item['variation_id'] ) ? absint( $wc_cart_item['variation_id'] ) : 0;

        /** Extract variation attributes from the WooCommerce cart item. */
        $variation_data = isset( $wc_cart_item['variation'] ) ? $wc_cart_item['variation'] : array();

        /** Re-add the variation_id to variation_data when one exists. */
        if ( $variation_id > 0 ) {
            $variation_data = array_merge( array( 'variation_id' => $variation_id ), $variation_data );
        }

        /** Extract the WooCommerce cart quantity. */
        $wc_quantity = isset( $wc_cart_item['quantity'] ) ? absint( $wc_cart_item['quantity'] ) : 1;

        /** Initialize the found item index. */
        $found_index = false;

        /** Search the stored user cart for a matching item. */
        foreach ( $user_cart as $index => $item ) {
            /** Match by product ID, variation ID, and variation data. */
            if ( $item['product_id'] == $product_id &&
                 ( isset( $item['variation_data']['variation_id'] ) ? absint( $item['variation_data']['variation_id'] ) : 0 ) == $variation_id &&
                 ( empty( $variation_data ) || $item['variation_data'] == $variation_data ) ) {
                /** Store the matching item index. */
                $found_index = $index;
                break;
            }
        }

        /** Update the existing matching user cart item. */
        if ( $found_index !== false ) {
            /** Save the WooCommerce cart item key on the stored item. */
            $user_cart[ $found_index ]['key'] = $wc_cart_item_key;

            /** Increment quantity in API context when quantity_added was provided. */
            if ( $quantity_added !== null ) {
                /** Read the existing quantity from storage. */
                $existing_qty = (int) ( $user_cart[ $found_index ]['quantity'] ?? 0 );

                /** Store the incremented quantity. */
                $user_cart[ $found_index ]['quantity'] = $existing_qty + $quantity_added;
            } else {
                /** Mirror the quantity from the WooCommerce cart item. */
                $user_cart[ $found_index ]['quantity'] = $wc_quantity;
            }

            /** Update variation data when present. */
            if ( ! empty( $variation_data ) ) {
                $user_cart[ $found_index ]['variation_data'] = $variation_data;
            }
        } else {
            /** Determine the quantity to store for a new item. */
            $qty = $quantity_added !== null ? $quantity_added : $wc_quantity;

            /** Append a new item to the stored user cart. */
            $user_cart[] = array(
                /** Store the WooCommerce cart item key. */
                'key'            => $wc_cart_item_key,

                /** Store the parent product ID. */
                'product_id'     => $product_id,

                /** Store the item quantity. */
                'quantity'       => $qty,

                /** Store variation metadata. */
                'variation_data' => $variation_data,

                /** Store the current timestamp. */
                'added_at'       => time(),
            );
        }

        /** Save the updated user cart to storage. */
        CartStorage::save_user_cart( $user_id, $user_cart );
    }

    /**
     * Normalize variation_data for WooCommerce add_to_cart.
     *
     * When only variation_id is provided, the variation product is loaded and
     * its attribute keys are merged into the returned variation data.
     *
     * @param int   $product_id The parent product ID.
     * @param array $variation_data The provided variation data.
     * @return array
     */
    public static function normalize_variation_data_for_wc( int $product_id, array $variation_data ): array {
        /** Return the input unchanged when variation_id is missing. */
        if ( empty( $variation_data ) || ! isset( $variation_data['variation_id'] ) ) {
            return $variation_data;
        }

        /** Normalize the variation ID. */
        $variation_id = absint( $variation_data['variation_id'] );

        /** Return the input unchanged when variation_id is invalid. */
        if ( $variation_id <= 0 ) {
            return $variation_data;
        }

        /** Track whether variation attribute keys are already present. */
        $has_attributes = false;

        /** Inspect the variation_data keys for attribute_* entries. */
        foreach ( array_keys( $variation_data ) as $key ) {
            /** Detect existing WooCommerce variation attribute keys. */
            if ( $key !== 'variation_id' && strpos( (string) $key, 'attribute_' ) === 0 ) {
                $has_attributes = true;
                break;
            }
        }

        /** Return the input unchanged when variation attributes are already present. */
        if ( $has_attributes ) {
            return $variation_data;
        }

        /** Load the WooCommerce variation product. */
        $variation = wc_get_product( $variation_id );

        /** Return the input unchanged when the variation product is invalid. */
        if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
            return $variation_data;
        }

        /** Load the variation attributes from the product object. */
        $attrs = $variation->get_variation_attributes();

        /** Fallback to WooCommerce helper when direct variation attributes are empty. */
        if ( empty( $attrs ) && function_exists( 'wc_get_product_variation_attributes' ) ) {
            $attrs = wc_get_product_variation_attributes( $variation_id );
        }

        /** Merge resolved attributes into the provided variation data. */
        if ( ! empty( $attrs ) ) {
            $variation_data = array_merge( $variation_data, $attrs );
        }

        /** Return the normalized variation data. */
        return $variation_data;
    }

    /**
     * Build the variation attributes array for WooCommerce add_to_cart.
     *
     * Uses the same key format as WC_Cart::add_to_cart and returns only
     * attribute_* key => value pairs.
     *
     * @param array    $variation_data Full variation data.
     * @param int|mixed $parent_id Optional parent product ID.
     * @return array
     */
    public static function get_variation_attributes_for_add_to_cart( array $variation_data, $parent_id = 0 ): array {
        /** Extract the variation ID from variation_data. */
        $variation_id = ! empty( $variation_data['variation_id'] ) ? absint( $variation_data['variation_id'] ) : 0;

        /** Fall back to request attributes when no variation ID exists. */
        if ( $variation_id <= 0 ) {
            return self::get_variation_attributes_from_request( $variation_data );
        }

        /** Resolve the parent ID from the variation when not explicitly provided. */
        if ( $parent_id <= 0 ) {
            $parent_id = wp_get_post_parent_id( $variation_id );
        }

        /** Fall back to request attributes when the parent ID cannot be determined. */
        if ( $parent_id <= 0 ) {
            return self::get_variation_attributes_from_request( $variation_data );
        }

        /** Load the parent WooCommerce product. */
        $parent = wc_get_product( $parent_id );

        /** Fall back to request attributes when the parent product is invalid or not variable. */
        if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
            return self::get_variation_attributes_from_request( $variation_data );
        }

        /** Load variation attribute values via the WooCommerce helper when available. */
        $variation_values = function_exists( 'wc_get_product_variation_attributes' )
            ? wc_get_product_variation_attributes( $variation_id )
            : array();

        /** Fall back to the variation product object when helper attributes are empty. */
        if ( empty( $variation_values ) ) {
            /** Load the variation product object. */
            $variation_product = wc_get_product( $variation_id );

            /** Load attributes from the variation product when possible. */
            if ( $variation_product && $variation_product->is_type( 'variation' ) && method_exists( $variation_product, 'get_variation_attributes' ) ) {
                $variation_values = $variation_product->get_variation_attributes();
            }
        }

        /** Initialize the output variation attributes array. */
        $out = array();

        /** Iterate over each parent product attribute. */
        foreach ( $parent->get_attributes() as $attribute ) {
            /** Skip attributes that are not used for variations. */
            if ( empty( $attribute['is_variation'] ) ) {
                continue;
            }

            /** Resolve the attribute name for array-based and object-based attribute representations. */
            $name = is_array( $attribute ) ? ( isset( $attribute['name'] ) ? $attribute['name'] : '' ) : ( method_exists( $attribute, 'get_name' ) ? $attribute->get_name() : ( isset( $attribute['name'] ) ? $attribute['name'] : '' ) );

            /** Skip attributes with empty names. */
            if ( $name === '' ) {
                continue;
            }

            /** Build the WooCommerce attribute_* key. */
            $attribute_key = 'attribute_' . sanitize_title( $name );

            /** Load the resolved variation value or default to an empty string. */
            $value         = isset( $variation_values[ $attribute_key ] ) ? $variation_values[ $attribute_key ] : '';

            /** Prefer the explicitly provided request value when present. */
            if ( isset( $variation_data[ $attribute_key ] ) && $variation_data[ $attribute_key ] !== '' ) {
                $value = $variation_data[ $attribute_key ];
            }

            /** Save the normalized string value in the output array. */
            $out[ $attribute_key ] = is_string( $value ) ? $value : (string) $value;
        }

        /** Return the attribute-only variation array. */
        return $out;
    }

    /**
     * Extract attribute_* keys from request variation_data.
     *
     * @param array $variation_data The provided variation data.
     * @return array
     */
    private static function get_variation_attributes_from_request( array $variation_data ): array {
        /** Initialize the output array. */
        $out = array();

        /** Iterate through each variation_data entry. */
        foreach ( $variation_data as $key => $value ) {
            /** Keep only attribute_* keys and normalize values to strings. */
            if ( is_string( $key ) && strpos( $key, 'attribute_' ) === 0 ) {
                $out[ $key ] = is_string( $value ) ? $value : (string) $value;
            }
        }

        /** Return the filtered attribute array. */
        return $out;
    }

    /**
     * Validate a guest token format.
     *
     * @param string $guest_token The guest token.
     * @return bool
     */
    private function validate_guest_token( string $guest_token ): bool {
        /** Return false when the guest token is empty. */
        if ( empty( $guest_token ) ) {
            return false;
        }

        /** Return whether the guest token matches the expected format. */
        return preg_match( '/^guest_\d+_[a-zA-Z0-9]+_[a-f0-9]{8}$/', $guest_token ) === 1;
    }

    /**
     * Return a lightweight cart hash response for polling.
     *
     * The response includes a cheap version-derived hash, item count, and version.
     *
     * @param \WP_REST_Request $request The incoming REST request.
     * @return \WP_REST_Response
     */
    public function get_cart_hash( \WP_REST_Request $request ): \WP_REST_Response {
        /** Get the client identifier used for rate limiting. */
        $client_id = RateLimiter::get_client_id();

        /** Return an empty stable response when the cart-hash polling limit is exceeded. */
        if ( ! RateLimiter::is_allowed( 'cart_hash:' . $client_id, 120, 60 ) ) {
            return new \WP_REST_Response( array( 'hash' => '', 'count' => 0, 'version' => 0 ), 200 );
        }

        /** Get and sanitize the guest token from the request. */
        $guest_token = sanitize_text_field( $request->get_param( 'guest_token' ) );

        /** Resolve the transient cache key based on guest token or logged-in user. */
        if ( ! empty( $guest_token ) ) {
            /** Return an empty stable response when the guest token format is invalid. */
            if ( ! $this->validate_guest_token( $guest_token ) ) {
                return new \WP_REST_Response( array( 'hash' => '', 'count' => 0, 'version' => 0 ), 200 );
            }

            /** Build the transient cache key for the guest cart hash. */
            $cache_key = 'aicommerce_cart_hash_guest_' . md5( $guest_token );
        } elseif ( is_user_logged_in() ) {
            /** Build the transient cache key for the logged-in user cart hash. */
            $cache_key = 'aicommerce_cart_hash_user_' . (int) get_current_user_id();
        } else {
            /** Return an empty stable response when no cart identity is available. */
            return new \WP_REST_Response( array( 'hash' => '', 'count' => 0, 'version' => 0 ), 200 );
        }

        /** Attempt to load the cached cart hash payload. */
        $cached = $cache_key ? get_transient( $cache_key ) : false;

        /** Return the cached payload when it contains the expected fields. */
        if ( is_array( $cached ) && isset( $cached['hash'], $cached['count'], $cached['version'] ) ) {
            return new \WP_REST_Response(
                array(
                    /** Include the cached hash string. */
                    'hash'  => (string) $cached['hash'],

                    /** Include the cached item count. */
                    'count' => (int) $cached['count'],

                    /** Include the cached cart version. */
                    'version' => (int) $cached['version'],
                ),
                200
            );
        }

        /** Load cart metadata from guest or user storage. */
        if ( ! empty( $guest_token ) ) {
            /** Load metadata for the guest cart. */
            $meta = CartStorage::get_cart_meta( $guest_token );
        } else {
            /** Load metadata for the logged-in user cart. */
            $meta = CartStorage::get_user_cart_meta( get_current_user_id() );
        }

        /** Extract the cart version from metadata. */
        $version = (int) ( $meta['version'] ?? 0 );

        /** Extract the item count from metadata. */
        $count   = (int) ( $meta['count'] ?? 0 );

        /** Return and cache an empty payload when the cart is empty or has no valid version. */
        if ( $version <= 0 || $count <= 0 ) {
            /** Build the empty cart hash payload. */
            $payload = array( 'hash' => 'empty', 'count' => 0, 'version' => 0 );

            /** Cache the empty payload briefly to reduce repeated reads. */
            if ( $cache_key ) {
                set_transient( $cache_key, $payload, 3 );
            }

            /** Return the empty cart hash payload. */
            return new \WP_REST_Response( $payload, 200 );
        }

        /** Build a cheap version-derived hash payload. */
        $payload = array(
            /** Keep the legacy hash field based on cart version. */
            'hash'    => 'v' . $version,

            /** Include the cart item count. */
            'count'   => $count,

            /** Include the cart version. */
            'version' => $version,
        );

        /** Cache the non-empty payload briefly to reduce repeated reads. */
        if ( $cache_key ) {
            set_transient( $cache_key, $payload, 3 );
        }

        /** Return the cart hash payload. */
        return new \WP_REST_Response( $payload, 200 );
    }
}
