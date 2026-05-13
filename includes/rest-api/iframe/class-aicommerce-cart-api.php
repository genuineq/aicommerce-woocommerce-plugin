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
     * Debug logging is intentionally disabled in production builds.
     */
    private static function log_debug( string $event, array $context = array() ): void {
        return;
    }

    /**
     * Build a normalized cart identity payload for API responses.
     *
     * @param string   $guest_token Guest token.
     * @param int|null $user_id     User ID.
     * @return array<string, mixed>
     */
    private function build_identity_payload( string $guest_token = '', ?int $user_id = null ): array {
        return array(
            'identifier'  => ! empty( $guest_token ) ? 'guest' : 'user',
            'guest_token' => ! empty( $guest_token ) ? $guest_token : '',
            'storage_key' => ! empty( $guest_token ) ? CartStorage::get_guest_cart_option_name( $guest_token ) : '',
            'user_id'     => ! empty( $user_id ) ? (int) $user_id : 0,
        );
    }

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Update WooCommerce persistent cart user_meta from AICommerce user cart.
     *
     * This keeps WooCommerce in sync for logged-in users even when the cart
     * is manipulated through server-to-server API calls (no browser session).
     *
     * @param int $user_id User ID
     */
    private function sync_wc_persistent_cart_from_user_cart( int $user_id ): void {
        if ( $user_id <= 0 ) {
            return;
        }

        $items = CartStorage::get_user_cart( $user_id );

        $wc_persistent_key = '_woocommerce_persistent_cart_' . get_current_blog_id();
        $persistent_cart   = get_user_meta( $user_id, $wc_persistent_key, true );
        if ( ! is_array( $persistent_cart ) ) {
            $persistent_cart = array();
        }

        $cart = array();
        foreach ( $items as $item ) {
            $product_id     = (int) ( $item['product_id'] ?? 0 );
            $quantity       = (int) ( $item['quantity'] ?? 0 );
            $variation_data = isset( $item['variation_data'] ) && is_array( $item['variation_data'] ) ? $item['variation_data'] : array();
            $variation_id   = isset( $variation_data['variation_id'] ) ? absint( $variation_data['variation_id'] ) : 0;

            if ( $product_id <= 0 || $quantity <= 0 ) {
                continue;
            }

            $key = isset( $item['key'] ) && is_string( $item['key'] ) && $item['key'] !== ''
                ? $item['key']
                : 'aicom_' . md5( $product_id . ':' . $variation_id . ':' . wp_json_encode( $variation_data ) );

            $variation_attrs = self::get_variation_attributes_for_add_to_cart( $variation_data, $product_id );

            $cart[ $key ] = array(
                'product_id'   => $product_id,
                'variation_id' => $variation_id,
                'variation'    => $variation_attrs,
                'quantity'     => $quantity,
            );
        }

        $persistent_cart['cart'] = $cart;
        update_user_meta( $user_id, $wc_persistent_key, $persistent_cart );
    }

    /**
     * Resolve request identity consistently across cart endpoints.
     *
     * Browser-originated requests may omit the explicit identifier even though
     * the current WooCommerce page still has the guest cookie or logged-in user.
     *
     * @param \WP_REST_Request $request REST request.
     * @param bool             $allow_session_fallback Whether to fall back to the current browser session.
     * @return array{guest_token:string,user_id:?int,error:\WP_REST_Response|null}
     */
    private function resolve_cart_identity( \WP_REST_Request $request, bool $allow_session_fallback = true, bool $require_user_cart_session = true ): array {
        $raw_guest_token = $request->get_param( 'guest_token' );
        $raw_user_id     = $request->get_param( 'user_id' );
        $raw_cart_token  = $request->get_param( 'cart_token' );
        if ( empty( $raw_cart_token ) ) {
            $raw_cart_token = $request->get_param( 't' );
        }

        $has_guest_token = is_string( $raw_guest_token ) && '' !== trim( $raw_guest_token );
        $has_user_id     = isset( $raw_user_id ) && '' !== $raw_user_id && null !== $raw_user_id;

        if ( $allow_session_fallback && is_user_logged_in() && $has_guest_token && ! $has_user_id ) {
            $raw_guest_token = '';
            $raw_user_id     = get_current_user_id();
            $has_guest_token = false;
            $has_user_id     = true;
        }

        if ( $has_guest_token && $has_user_id ) {
            return array(
                'guest_token' => '',
                'user_id'     => null,
                'error'       => new \WP_REST_Response(
                    array(
                        'success' => false,
                        'code'    => 'conflicting_identifiers',
                        'message' => __( 'Provide either guest_token or user_id, not both.', 'aicommerce' ),
                    ),
                    400
                ),
            );
        }

        $context     = CartContextResolver::resolve_request_context( $raw_guest_token, $raw_user_id, $allow_session_fallback );
        $guest_token = (string) $context['guest_token'];
        $user_id     = $context['user_id'];

        if ( $has_guest_token && empty( $guest_token ) ) {
            return array(
                'guest_token' => '',
                'user_id'     => null,
                'error'       => new \WP_REST_Response(
                    array(
                        'success' => false,
                        'code'    => 'invalid_guest_token',
                        'message' => __( 'Invalid guest token format.', 'aicommerce' ),
                    ),
                    400
                ),
            );
        }

        if ( $has_user_id && empty( $user_id ) ) {
            return array(
                'guest_token' => '',
                'user_id'     => null,
                'error'       => new \WP_REST_Response(
                    array(
                        'success' => false,
                        'code'    => 'invalid_user_id',
                        'message' => __( 'Invalid user ID.', 'aicommerce' ),
                    ),
                    400
                ),
            );
        }

        if ( $require_user_cart_session && $has_user_id && ! $this->can_use_user_cart_identity( (int) $user_id, (string) $raw_cart_token ) ) {
            return array(
                'guest_token' => '',
                'user_id'     => null,
                'error'       => new \WP_REST_Response(
                    array(
                        'success' => false,
                        'code'    => 'invalid_user_cart_session',
                        'message' => __( 'Invalid user cart session.', 'aicommerce' ),
                    ),
                    403
                ),
            );
        }

        if ( empty( $guest_token ) && empty( $user_id ) ) {
            $current_context = $allow_session_fallback ? CartContextResolver::resolve_current_context() : array( 'guest_token' => '', 'user_id' => 0 );
            $guest_token     = (string) $current_context['guest_token'];
            $user_id         = ! empty( $current_context['user_id'] ) ? (int) $current_context['user_id'] : null;
        }

        if ( empty( $guest_token ) && empty( $user_id ) ) {
            return array(
                'guest_token' => '',
                'user_id'     => null,
                'error'       => new \WP_REST_Response(
                    array(
                        'success' => false,
                        'code'    => 'missing_identifier',
                        'message' => __( 'Either guest_token or user_id is required.', 'aicommerce' ),
                    ),
                    400
                ),
            );
        }

        if ( ! empty( $user_id ) && ! get_user_by( 'id', $user_id ) ) {
            return array(
                'guest_token' => '',
                'user_id'     => null,
                'error'       => new \WP_REST_Response(
                    array(
                        'success' => false,
                        'code'    => 'invalid_user_id',
                        'message' => __( 'Invalid user ID.', 'aicommerce' ),
                    ),
                    400
                ),
            );
        }

        return array(
            'guest_token' => $guest_token,
            'user_id'     => $user_id,
            'error'       => null,
        );
    }

    /**
     * Check whether the current request may operate on a user cart.
     *
     * @param int    $user_id    User ID.
     * @param string $cart_token Browser-scoped cart token.
     * @return bool
     */
    private function can_use_user_cart_identity( int $user_id, string $cart_token = '' ): bool {
        if ( $user_id <= 0 ) {
            return false;
        }

        if ( is_user_logged_in() && (int) get_current_user_id() === $user_id ) {
            return true;
        }

        if ( '' === $cart_token ) {
            return false;
        }

        $token_user_id = (int) get_transient( 'aicommerce_user_cart_token_' . hash( 'sha256', $cart_token ) );

        return $token_user_id === $user_id;
    }

    /**
     * Check whether this guest token was just consumed by a login merge.
     *
     * @param string $guest_token Guest token.
     * @return bool
     */
    private function is_guest_token_claimed_after_login( string $guest_token ): bool {
        if ( empty( $guest_token ) || ! function_exists( 'get_transient' ) ) {
            return false;
        }

        return (bool) get_transient( 'aicommerce_guest_login_user_' . md5( $guest_token ) );
    }

    /**
     * Check whether a guest cart has a persisted AICommerce storage row.
     *
     * Missing guest storage usually means an old cached token or a fresh empty
     * token. Sync must not project that absence over the current Woo cart.
     *
     * @param string $guest_token Guest token.
     * @return bool
     */
    private function guest_cart_storage_exists( string $guest_token ): bool {
        if ( empty( $guest_token ) ) {
            return false;
        }

        $sentinel = '__aicommerce_missing_guest_cart__';
        $value    = get_option( CartStorage::get_guest_cart_option_name( $guest_token ), $sentinel );

        return $value !== $sentinel;
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
                'args'                => array(
                    'guest_token'    => array(
                        'type'     => 'string',
                        'required' => false,
                    ),
                    'user_id'        => array(
                        'type'     => 'integer',
                        'required' => false,
                    ),
                    'cart_token'     => array(
                        'type'     => 'string',
                        'required' => false,
                    ),
                    'product_id'     => array(
                        'description' => __( 'Product ID to add', 'aicommerce' ),
                        'type'        => 'integer',
                        'required'    => true,
                        'minimum'     => 1,
                    ),
                    'quantity'       => array(
                        'type'     => 'integer',
                        'required' => false,
                        'minimum'  => 1,
                    ),
                    'variation_data' => array(
                        'description' => __( 'Variation data for variable products (must include variation_id)', 'aicommerce' ),
                        'type'        => 'object',
                        'required'    => false,
                    ),
                ),
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
                'args'                => array(
                    'guest_token' => array(
                        'type'     => 'string',
                        'required' => false,
                    ),
                ),
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
                'args'                => array(
                    'guest_token' => array(
                        'type'     => 'string',
                        'required' => false,
                    ),
                    'user_id'     => array(
                        'type'     => 'integer',
                        'required' => false,
                    ),
                    'cart_token'  => array(
                        'type'     => 'string',
                        'required' => false,
                    ),
                ),
            )
        );

        // Cart hash endpoint (lightweight read-only cart metadata)
        register_rest_route(
            $namespace,
            '/cart/hash',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_cart_hash' ),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'guest_token' => array(
                        'type'     => 'string',
                        'required' => false,
                    ),
                    'user_id'     => array(
                        'type'     => 'integer',
                        'required' => false,
                    ),
                    'cart_token'  => array(
                        'type'     => 'string',
                        'required' => false,
                    ),
                ),
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
                    'guest_token'    => array(
                        'type'     => 'string',
                        'required' => false,
                    ),
                    'user_id'        => array(
                        'type'     => 'integer',
                        'required' => false,
                    ),
                    'cart_token'     => array(
                        'type'     => 'string',
                        'required' => false,
                    ),
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
        if ( $this->is_rate_limited( 'cart_add', 30, 60 ) ) {
            return $this->rate_limit_response();
        }

        self::log_debug(
            'add_to_cart:request',
            array(
                'guest_token_present' => ! empty( $request->get_param( 'guest_token' ) ),
                'user_id'             => $request->get_param( 'user_id' ),
                'product_id'          => $request->get_param( 'product_id' ),
                'quantity'            => $request->get_param( 'quantity' ),
            )
        );

        // Validate API signature
        $validation = APIValidator::validate_request( $request );
        if ( ! $validation['valid'] ) {
            self::log_debug( 'add_to_cart:validation_failed', $validation );
            return APIValidator::error_response( $validation );
        }

        $identity = $this->resolve_cart_identity( $request, true, false );
        if ( $identity['error'] instanceof \WP_REST_Response ) {
            self::log_debug(
                'add_to_cart:identity_error',
                array(
                    'code' => $identity['error']->get_data()['code'] ?? 'identity_error',
                )
            );
            return $identity['error'];
        }

        $guest_token = $identity['guest_token'];
        $user_id     = $identity['user_id'];
        $product_id = $request->get_param( 'product_id' );
        $quantity = $request->get_param( 'quantity' );
        $variation_data = $request->get_param( 'variation_data' );

        // Validate product ID
        if ( empty( $product_id ) || ! is_numeric( $product_id ) ) {
            self::log_debug( 'add_to_cart:missing_product_id' );
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

        if ( $quantity <= 0 ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'invalid_quantity',
                    'message' => __( 'Quantity must be greater than zero.', 'aicommerce' ),
                ),
                400
            );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            self::log_debug(
                'add_to_cart:product_not_found',
                array(
                    'product_id' => $product_id,
                )
            );
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

        $variation_data_array = self::normalize_variation_data_for_wc( $product_id, $variation_data_array );
        $existing_quantity    = $this->get_existing_line_quantity(
            ! empty( $guest_token ) ? (string) $guest_token : '',
            ! empty( $user_id ) ? (int) $user_id : null,
            $product_id,
            $variation_data_array
        );
        $availability_result  = ProductAvailability::validate_product_for_cart(
            $product,
            $variation_data_array,
            $quantity,
            $existing_quantity
        );

        if ( empty( $availability_result['valid'] ) ) {
            return new \WP_REST_Response(
                array(
                    'success'      => false,
                    'code'         => $availability_result['code'],
                    'message'      => $availability_result['message'],
                    'availability' => $availability_result['availability'],
                ),
                409
            );
        }

        /**
         * Idempotency / duplicate protection:
         * In practice, we can receive near-simultaneous duplicate `cart/add` calls
         * (double-submit, multiple widget instances, race with sync).
         * Without protection, quantities can be incremented twice.
         */
        $lockTtlSeconds = 5;
        $variationSig = md5( wp_json_encode( $variation_data_array ) );
        if ( ! empty( $guest_token ) ) {
            $lockKey = 'aicommerce_cart_add_lock_guest_' . md5( $guest_token . ':' . (int) $product_id . ':' . (int) $quantity . ':' . $variationSig );
        } else {
            $lockKey = 'aicommerce_cart_add_lock_user_' . md5( (string) $user_id . ':' . (int) $product_id . ':' . (int) $quantity . ':' . $variationSig );
        }

        if ( function_exists( 'get_transient' ) && function_exists( 'set_transient' ) ) {
            if ( (bool) get_transient( $lockKey ) ) {
                // Return current cart count without mutating anything.
                $cart_count = ! empty( $guest_token )
                    ? CartStorage::get_cart_count( (string) $guest_token )
                    : CartStorage::get_user_cart_count( (int) $user_id );

                return new \WP_REST_Response(
                    array(
                        'success'    => true,
                        'code'       => 'duplicate_request',
                        'message'    => __( 'Duplicate add ignored.', 'aicommerce' ),
                        'cart_count' => $cart_count,
                    ),
                    200
                );
            }

            set_transient( $lockKey, 1, $lockTtlSeconds );
        }

        if ( ! empty( $guest_token ) ) {
            $cart = CartStorage::add_item( $guest_token, $product_id, $quantity, $variation_data_array );
            $cart_count = CartStorage::get_cart_count( $guest_token );
            $identifier = $guest_token;

            if ( $cart === false ) {
                self::log_debug(
                    'add_to_cart:storage_failed_guest',
                    array(
                        'product_id'  => $product_id,
                        'guest_token' => $guest_token,
                    )
                );
                return new \WP_REST_Response(
                    array(
                        'success' => false,
                        'code'    => 'add_to_cart_failed',
                        'message' => __( 'Failed to add item to cart.', 'aicommerce' ),
                    ),
                    500
                );
            }

            CartStorage::mark_as_ai_cart( $guest_token );
        } elseif ( ! empty( $user_id ) && $user_id > 0 ) {
            /**
             * Server-to-server context: do not attempt to operate on the WooCommerce
             * session cart (it is tied to the browser session). Instead, update
             * AICommerce persistent user cart and keep WooCommerce persistent cart
             * meta in sync so the browser sees consistent state on next load.
             */
            $cart = CartStorage::add_item_to_user_cart( $user_id, $product_id, $quantity, $variation_data_array );
            $cart_count = CartStorage::get_user_cart_count( $user_id );

            if ( $cart === false ) {
                self::log_debug(
                    'add_to_cart:storage_failed_user',
                    array(
                        'product_id' => $product_id,
                        'user_id'    => $user_id,
                    )
                );
                return new \WP_REST_Response(
                    array(
                        'success' => false,
                        'code'    => 'add_to_cart_failed',
                        'message' => __( 'Failed to add item to cart.', 'aicommerce' ),
                    ),
                    500
                );
            }

            $this->sync_wc_persistent_cart_from_user_cart( (int) $user_id );

            CartStorage::mark_as_ai_user_cart( $user_id );

            $identifier = 'user_' . $user_id;
        } else {
            self::log_debug( 'add_to_cart:missing_identifier_after_normalize' );
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'missing_identifier',
                    'message' => __( 'Either guest_token or user_id is required.', 'aicommerce' ),
                ),
                400
            );
        }

        self::log_debug(
            'add_to_cart:success',
            array(
                'product_id'  => $product_id,
                'quantity'    => $quantity,
                'cart_count'  => $cart_count,
                'identifier'  => ! empty( $guest_token ) ? 'guest' : 'user',
                'guest_token' => ! empty( $guest_token ) ? $guest_token : '',
                'user_id'     => ! empty( $user_id ) ? (int) $user_id : 0,
            )
        );

        /** Trigger Woo sync immediately after a successful REST cart mutation. */
        $this->trigger_wc_bridge_sync(
            ! empty( $guest_token ) ? (string) $guest_token : '',
            ! empty( $user_id ) ? (int) $user_id : null
        );

        return new \WP_REST_Response(
            array(
                'success'      => true,
                'message'      => __( 'Item added to cart successfully.', 'aicommerce' ),
                'cart_count'   => $cart_count,
                'availability' => $availability_result['availability'],
                'cart_event'   => 'aicommerce:cart_added',
            ),
            200
        );
    }

    /**
     * Get cart endpoint
     * Supports both guest_token and user_id
     */
    public function get_cart( \WP_REST_Request $request ): \WP_REST_Response {
        self::log_debug(
            'get_cart:request',
            array(
                'guest_token_present' => ! empty( $request->get_param( 'guest_token' ) ),
                'user_id'             => $request->get_param( 'user_id' ),
            )
        );

        $validation = APIValidator::validate_request( $request );
        if ( ! $validation['valid'] ) {
            self::log_debug( 'get_cart:validation_failed', $validation );
            return APIValidator::error_response( $validation );
        }

        $identity = $this->resolve_cart_identity( $request, true, false );
        if ( $identity['error'] instanceof \WP_REST_Response ) {
            self::log_debug(
                'get_cart:identity_error',
                array(
                    'code' => $identity['error']->get_data()['code'] ?? 'identity_error',
                )
            );
            return $identity['error'];
        }

        $guest_token = $identity['guest_token'];
        $user_id     = $identity['user_id'];

        if ( ! empty( $guest_token ) ) {
            $cart = $this->prune_invalid_cart_storage( $guest_token, null );
            $cart_count = count( $cart );
        } elseif ( ! empty( $user_id ) ) {
            $cart = $this->prune_invalid_cart_storage( '', (int) $user_id );
            $cart_count = count( $cart );
        } else {
            self::log_debug( 'get_cart:missing_identifier_after_normalize' );
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'missing_identifier',
                    'message' => __( 'Either guest_token or user_id is required.', 'aicommerce' ),
                ),
                400
            );
        }

        $cart = $this->enrich_cart_items( $cart );
        self::log_debug(
            'get_cart:success',
            array(
                'identifier'  => ! empty( $guest_token ) ? 'guest' : 'user',
                'storage_key' => ! empty( $guest_token ) ? CartStorage::get_guest_cart_option_name( $guest_token ) : '',
                'cart_count'  => $cart_count,
                'items'       => count( $cart ),
            )
        );

        return new \WP_REST_Response(
            array(
                'success'    => true,
                'identity'   => $this->build_identity_payload( $guest_token, $user_id ),
                'cart'       => $cart,
                'cart_count' => $cart_count,
            ),
            200
        );
    }

    /**
     * Enrich cart items with product details: name, SKU, image URL, product URL.
     * For variable products the variation's own image is used when available,
     * falling back to the parent product's featured image.
     *
     * @param array $items Raw cart items from storage
     * @return array Cart items with added product_details field
     */
    private function enrich_cart_items( array $items ): array {
        foreach ( $items as &$item ) {
            $product_id   = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
            $variation_id = isset( $item['variation_data']['variation_id'] )
                ? absint( $item['variation_data']['variation_id'] )
                : 0;

            if ( $product_id <= 0 ) {
                $item['product_details'] = null;
                $item['line_status']     = 'invalid';
                $item['requires_action'] = true;
                $item['availability']    = null;
                continue;
            }

            // Use variation product when available so we get the right SKU / image
            $product_to_use = $variation_id > 0 ? wc_get_product( $variation_id ) : null;
            $parent_product = wc_get_product( $product_id );

            if ( ! $parent_product ) {
                $item['product_details'] = null;
                $item['line_status']     = 'product_missing';
                $item['requires_action'] = true;
                $item['availability']    = null;
                continue;
            }

            $active_product = $product_to_use ?: $parent_product;

            // Image: prefer variation image, fall back to parent featured image
            $image_id  = $active_product->get_image_id();
            $image_url = $image_id
                ? wp_get_attachment_image_url( $image_id, 'woocommerce_single' )
                : wc_placeholder_img_src( 'woocommerce_single' );

            $item['product_details'] = array(
                'name'  => $parent_product->get_name(),
                'sku'   => $active_product->get_sku() ?: $parent_product->get_sku(),
                'image' => $image_url ?: null,
                'url'   => get_permalink( $product_id ) ?: null,
            );

            $quantity              = isset( $item['quantity'] ) ? max( 0, (int) $item['quantity'] ) : 0;
            $existing_quantity     = max( 0, $quantity - 1 );
            $availability_result   = ProductAvailability::validate_product_for_cart(
                $parent_product,
                isset( $item['variation_data'] ) && is_array( $item['variation_data'] ) ? $item['variation_data'] : array(),
                1,
                $existing_quantity
            );
            $item['availability']       = $availability_result['availability'];
            $item['line_status']        = $this->map_validation_code_to_line_status( $availability_result['code'] );
            $item['requires_action']    = 'valid' !== $item['line_status'];
            $item['max_addable_quantity'] = (int) ( $availability_result['availability']['max_addable_quantity'] ?? 0 );
        }
        unset( $item );

        return $items;
    }

    /**
     * Remove deleted or invalid items from the stored cart and persist the cleaned result.
     *
     * @param string   $guest_token Guest token.
     * @param int|null $user_id     User ID.
     * @return array<int, array<string, mixed>>
     */
    private function prune_invalid_cart_storage( string $guest_token = '', ?int $user_id = null ): array {
        /** Load the current stored cart for the resolved identity. */
        $items = ! empty( $guest_token )
            ? CartStorage::get_cart( $guest_token )
            : CartStorage::get_user_cart( (int) $user_id );

        /** Remove deleted products, invalid variations, and malformed lines. */
        $sanitized = CartProjector::sanitize_storage_items( $items );
        if ( ! $sanitized['changed'] ) {
            return $sanitized['items'];
        }

        /** Persist the cleaned cart so later reads and syncs see the same reality. */
        if ( ! empty( $guest_token ) ) {
            CartStorage::save_cart( $guest_token, $sanitized['items'] );
        } elseif ( ! empty( $user_id ) ) {
            CartStorage::save_user_cart( (int) $user_id, $sanitized['items'] );
        }

        return $sanitized['items'];
    }

    /**
     * Trigger an immediate WooCommerce bridge import after a successful REST mutation.
     *
     * @param string   $guest_token Guest token.
     * @param int|null $user_id     User ID.
     * @return array<string, mixed>
     */
    private function trigger_wc_bridge_sync( string $guest_token = '', ?int $user_id = null ): array {
        if ( empty( $guest_token ) && empty( $user_id ) ) {
            return array(
                'success' => false,
                'code'    => 'missing_identifier',
            );
        }

        $result = WooCartBridge::import_storage_to_wc_cart( $guest_token, $user_id );
        self::log_debug(
            'trigger_wc_bridge_sync:result',
            array(
                'identifier'   => ! empty( $guest_token ) ? 'guest' : 'user',
                'guest_token'  => ! empty( $guest_token ) ? $guest_token : '',
                'user_id'      => ! empty( $user_id ) ? (int) $user_id : 0,
                'success'      => ! empty( $result['success'] ),
                'synced'       => ! empty( $result['synced'] ),
                'synced_count' => (int) ( $result['synced_count'] ?? 0 ),
                'total_items'  => (int) ( $result['total_items'] ?? 0 ),
            )
        );

        return is_array( $result ) ? $result : array(
            'success' => false,
            'code'    => 'invalid_bridge_result',
        );
    }

    /**
     * Return existing quantity for the same logical line already present in cart storage.
     *
     * @param string   $guest_token    Guest token.
     * @param int|null $user_id        User ID.
     * @param int      $product_id     Product ID.
     * @param array    $variation_data Variation payload.
     * @return int
     */
    private function get_existing_line_quantity( string $guest_token = '', ?int $user_id = null, int $product_id = 0, array $variation_data = array() ): int {
        if ( $product_id <= 0 ) {
            return 0;
        }

        $items = ! empty( $guest_token )
            ? CartStorage::get_cart( $guest_token )
            : CartStorage::get_user_cart( (int) $user_id );

        $variation_id = isset( $variation_data['variation_id'] ) ? (int) $variation_data['variation_id'] : 0;
        foreach ( $items as $item ) {
            if ( (int) ( $item['product_id'] ?? 0 ) !== $product_id ) {
                continue;
            }

            $item_variation_id = isset( $item['variation_data']['variation_id'] ) ? (int) $item['variation_data']['variation_id'] : 0;
            if ( $item_variation_id === $variation_id ) {
                return max( 0, (int) ( $item['quantity'] ?? 0 ) );
            }
        }

        return 0;
    }

    /**
     * Map product validation codes to per-line cart statuses.
     *
     * @param string $code Validation code.
     * @return string
     */
    private function map_validation_code_to_line_status( string $code ): string {
        $map = array(
            'valid'                   => 'valid',
            'product_unpublished'     => 'product_unpublished',
            'product_not_purchasable' => 'product_not_purchasable',
            'variation_invalid'       => 'variation_invalid',
            'variation_mismatch'      => 'variation_invalid',
            'variation_required'      => 'variation_invalid',
            'out_of_stock'            => 'out_of_stock',
            'insufficient_stock'      => 'insufficient_stock',
            'sold_individually'       => 'insufficient_stock',
        );

        return isset( $map[ $code ] ) ? $map[ $code ] : 'invalid';
    }

    /**
     * Remove from cart endpoint
     * Supports both guest_token and user_id
     */
    public function remove_from_cart( \WP_REST_Request $request ): \WP_REST_Response {
        if ( $this->is_rate_limited( 'cart_remove', 30, 60 ) ) {
            return $this->rate_limit_response();
        }

        self::log_debug(
            'remove_from_cart:request',
            array(
                'guest_token_present' => ! empty( $request->get_param( 'guest_token' ) ),
                'user_id'             => $request->get_param( 'user_id' ),
                'product_id'          => $request->get_param( 'product_id' ),
            )
        );

        $validation = APIValidator::validate_request( $request );
        if ( ! $validation['valid'] ) {
            self::log_debug( 'remove_from_cart:validation_failed', $validation );
            return APIValidator::error_response( $validation );
        }

        $identity = $this->resolve_cart_identity( $request, true, false );
        if ( $identity['error'] instanceof \WP_REST_Response ) {
            self::log_debug(
                'remove_from_cart:identity_error',
                array(
                    'code' => $identity['error']->get_data()['code'] ?? 'identity_error',
                )
            );
            return $identity['error'];
        }

        $guest_token    = $identity['guest_token'];
        $user_id_int    = $identity['user_id'];
        $product_id     = absint( $request->get_param( 'product_id' ) );
        $variation_data = $request->get_param( 'variation_data' );

        if ( $product_id <= 0 ) {
            self::log_debug( 'remove_from_cart:invalid_product_id' );
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'invalid_product_id',
                    'message' => __( 'Valid product_id is required.', 'aicommerce' ),
                ),
                400
            );
        }

        $variation_data_array = is_array( $variation_data ) ? $variation_data : array();

        if ( ! empty( $guest_token ) ) {
            /** Clean stale guest items first so remove runs against the current valid cart shape. */
            $this->prune_invalid_cart_storage( $guest_token, null );
            $cart = CartStorage::remove_item( $guest_token, $product_id, $variation_data_array );
            if ( $cart === false ) {
                self::log_debug(
                    'remove_from_cart:failed_guest',
                    array(
                        'guest_token' => $guest_token,
                        'product_id'  => $product_id,
                    )
                );
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
            self::log_debug(
                'remove_from_cart:success',
                array(
                    'identifier'  => 'guest',
                    'guest_token' => $guest_token,
                    'storage_key' => CartStorage::get_guest_cart_option_name( $guest_token ),
                    'product_id'  => $product_id,
                    'cart_count'  => $cart_count,
                )
            );

            /** Trigger Woo sync immediately after a successful REST cart mutation. */
            $this->trigger_wc_bridge_sync( (string) $guest_token, null );

            return new \WP_REST_Response(
	                array(
	                    'success'    => true,
		                    'message'    => __( 'Item removed from cart.', 'aicommerce' ),
		                    'identity'   => $this->build_identity_payload( $guest_token, null ),
		                    'cart_count' => $cart_count,
		                    'cart_event' => 'aicommerce:cart_removed',
		                ),
                200
            );
        }

        $user_id      = $user_id_int;
        $variation_id = ! empty( $variation_data_array['variation_id'] ) ? absint( $variation_data_array['variation_id'] ) : 0;

        /** Clean stale user items first so remove runs against the current valid cart shape. */
        $this->prune_invalid_cart_storage( '', (int) $user_id );

        // 1. Remove from our user_meta cart (source of truth for the API)
        $cart = CartStorage::remove_item_from_user_cart( $user_id, $product_id, $variation_data_array );
        if ( $cart === false ) {
            self::log_debug(
                'remove_from_cart:failed_user',
                array(
                    'user_id'    => $user_id,
                    'product_id' => $product_id,
                )
            );
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'remove_failed',
                    'message' => __( 'Failed to remove item from cart.', 'aicommerce' ),
                ),
                500
            );
        }

        // 2. Keep WooCommerce persistent cart user_meta in sync with our cart.
        // This ensures WooCommerce will not resurrect removed items on next load.
        $this->sync_wc_persistent_cart_from_user_cart( (int) $user_id );

        $cart_count = CartStorage::get_user_cart_count( $user_id );
        self::log_debug(
            'remove_from_cart:success',
            array(
                'identifier' => 'user',
                'user_id'    => $user_id,
                'product_id' => $product_id,
                'cart_count' => $cart_count,
            )
        );

        /** Trigger Woo sync immediately after a successful REST cart mutation. */
        $this->trigger_wc_bridge_sync( '', (int) $user_id );

        return new \WP_REST_Response(
	            array(
	                'success'    => true,
		                'message'    => __( 'Item removed from cart.', 'aicommerce' ),
		                'identity'   => $this->build_identity_payload( '', $user_id ),
		                'cart_count' => $cart_count,
		                'cart_event' => 'aicommerce:cart_removed',
		            ),
            200
        );
    }

    /**
     * Sync cart to WooCommerce session endpoint.
     *
     * Reconciles the persistent AICommerce cart into the active WooCommerce cart
     * and returns the applied cart version so the frontend can stay version-driven.
     */
    public function sync_to_wc_session( \WP_REST_Request $request ): \WP_REST_Response {
        if ( $this->is_rate_limited( 'cart_sync', 60, 60 ) ) {
            return $this->rate_limit_response();
        }

        /** Log the incoming sync request so version-related flows are easier to trace. */
        self::log_debug(
            'sync_to_wc_session:request',
            array(
                'guest_token_present' => ! empty( $request->get_param( 'guest_token' ) ),
                'user_id'             => $request->get_param( 'user_id' ),
                'logged_in'           => is_user_logged_in(),
                'nonce_present'       => ! empty( $request->get_header( 'x_wp_nonce' ) ),
                'cart_token_present'  => ! empty( $request->get_param( 'cart_token' ) ) || ! empty( $request->get_param( 't' ) ),
            )
        );

        /** Resolve the cart identity from request payload, cookie, or authenticated session. */
        $identity = $this->resolve_cart_identity( $request, true );
        if ( $identity['error'] instanceof \WP_REST_Response ) {
            self::log_debug(
                'sync_to_wc_session:identity_error',
                array(
                    'code' => $identity['error']->get_data()['code'] ?? 'identity_error',
                )
            );
            return $identity['error'];
        }

        $guest_token = $identity['guest_token'];
        $user_id     = $identity['user_id'];

        if ( ! empty( $guest_token ) && empty( $user_id ) && $this->is_guest_token_claimed_after_login( $guest_token ) ) {
            self::log_debug(
                'sync_to_wc_session:claimed_guest_skipped',
                array(
                    'guest_token' => $guest_token,
                    'storage_key' => CartStorage::get_guest_cart_option_name( $guest_token ),
                )
            );

            return new \WP_REST_Response(
                array(
                    'success'      => true,
                    'message'      => __( 'Guest cart was claimed by login; anonymous sync skipped.', 'aicommerce' ),
                    'identity'     => $this->build_identity_payload( $guest_token, null ),
                    'synced'       => false,
                    'synced_count' => 0,
                    'total_items'  => 0,
                    'errors'       => array(),
                    'version'      => 0,
                    'code'         => 'claimed_guest_after_login',
                ),
                200
            );
        }

        if ( ! empty( $guest_token ) && empty( $user_id ) && ! $this->guest_cart_storage_exists( $guest_token ) ) {
            self::log_debug(
                'sync_to_wc_session:missing_guest_storage_skipped',
                array(
                    'guest_token' => $guest_token,
                    'storage_key' => CartStorage::get_guest_cart_option_name( $guest_token ),
                )
            );

            return new \WP_REST_Response(
                array(
                    'success'      => true,
                    'message'      => __( 'Guest cart storage is missing; sync skipped.', 'aicommerce' ),
                    'identity'     => $this->build_identity_payload( $guest_token, null ),
                    'synced'       => false,
                    'synced_count' => 0,
                    'total_items'  => 0,
                    'errors'       => array(),
                    'version'      => 0,
                    'code'         => 'missing_guest_storage',
                ),
                200
            );
        }

        /** Run one explicit bridge import instead of continuous session sync logic. */
        $result = WooCartBridge::import_storage_to_wc_cart( $guest_token, $user_id );

        if ( empty( $result['success'] ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => $result['code'] ?? 'woocommerce_not_available',
                    'message' => $result['message'] ?? __( 'WooCommerce cart is not available.', 'aicommerce' ),
                ),
                500
            );
        }

        $synced_count = (int) ( $result['synced_count'] ?? 0 );
        $errors       = isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : array();
        $total_items  = (int) ( $result['total_items'] ?? 0 );
        $version      = (int) ( $result['version'] ?? 0 );

        self::log_debug(
            'sync_to_wc_session:success',
            array(
                'identifier'   => ! empty( $guest_token ) ? 'guest' : 'user',
                'guest_token'  => ! empty( $guest_token ) ? $guest_token : '',
                'storage_key'  => ! empty( $guest_token ) ? CartStorage::get_guest_cart_option_name( $guest_token ) : '',
                'user_id'      => ! empty( $user_id ) ? (int) $user_id : 0,
                'synced_count' => $synced_count,
                'total_items'  => $total_items,
                'errors_count' => count( $errors ),
                'errors'       => array_slice( $errors, 0, 3 ),
            )
        );

        return new \WP_REST_Response(
            array(
                'success'      => true,
                'message'      => __( 'Cart synchronized to WooCommerce session.', 'aicommerce' ),
                'identity'     => $this->build_identity_payload( $guest_token, $user_id ),
                'synced'       => ! empty( $result['synced'] ),
                'synced_count' => $synced_count,
                'total_items'  => $total_items,
                'errors'       => $errors,
                'version'      => $version,
            ),
            200
        );
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

        return CartContextResolver::is_valid_guest_token( $guest_token );
    }

    /**
     * Route-specific rate-limit check for cart endpoints.
     *
     * @param string $route_key    Stable route key.
     * @param int    $max_attempts Max attempts in window.
     * @param int    $window       Window in seconds.
     * @return bool
     */
    private function is_rate_limited( string $route_key, int $max_attempts, int $window ): bool {
        $client_id = RateLimiter::get_client_id();
        return ! RateLimiter::is_allowed( $route_key . ':' . $client_id, $max_attempts, $window );
    }

    /**
     * Stable rate-limit response for cart endpoints.
     *
     * @return \WP_REST_Response
     */
    private function rate_limit_response(): \WP_REST_Response {
        return new \WP_REST_Response(
            array(
                'success' => false,
                'code'    => 'rate_limited',
                'message' => __( 'Too many requests. Please try again shortly.', 'aicommerce' ),
            ),
            429
        );
    }

    /**
     * Cart hash endpoint — returns MD5 of cart contents + item count.
     * Lightweight: no writes, no WC session.
     */
    public function get_cart_hash( \WP_REST_Request $request ): \WP_REST_Response {
        // Rate limit lightweight hash reads.
        $client_id = RateLimiter::get_client_id();
        if ( ! RateLimiter::is_allowed( 'cart_hash:' . $client_id, 120, 60 ) ) {
            // Keep response shape stable.
            return new \WP_REST_Response( array( 'hash' => '', 'count' => 0, 'version' => 0 ), 200 );
        }

        $guest_token = sanitize_text_field( (string) $request->get_param( 'guest_token' ) );

        if ( ! empty( $guest_token ) ) {
            if ( ! $this->validate_guest_token( $guest_token ) ) {
                return new \WP_REST_Response( array( 'hash' => '', 'count' => 0, 'version' => 0 ), 200 );
            }
            $cache_key = 'aicommerce_cart_hash_guest_' . md5( $guest_token );
        } elseif ( is_user_logged_in() ) {
            $cache_key = 'aicommerce_cart_hash_user_' . (int) get_current_user_id();
        } else {
            return new \WP_REST_Response( array( 'hash' => '', 'count' => 0, 'version' => 0 ), 200 );
        }

        $cached = $cache_key ? get_transient( $cache_key ) : false;
        if ( is_array( $cached ) && isset( $cached['hash'], $cached['count'], $cached['version'] ) ) {
            return new \WP_REST_Response(
                array(
                    'hash'  => (string) $cached['hash'],
                    'count' => (int) $cached['count'],
                    'version' => (int) $cached['version'],
                ),
                200
            );
        }

        if ( ! empty( $guest_token ) ) {
            $meta = CartStorage::get_cart_meta( $guest_token );
        } else {
            $meta = CartStorage::get_user_cart_meta( get_current_user_id() );
        }

        $version = (int) ( $meta['version'] ?? 0 );
        $count   = (int) ( $meta['count'] ?? 0 );

        if ( $version <= 0 || $count <= 0 ) {
            $payload = array( 'hash' => 'empty', 'count' => 0, 'version' => 0 );
            if ( $cache_key ) {
                set_transient( $cache_key, $payload, 3 );
            }
            return new \WP_REST_Response( $payload, 200 );
        }

        // Keep the legacy `hash` field but make it cheap: derived from version.
        $payload = array(
            'hash'    => 'v' . $version,
            'count'   => $count,
            'version' => $version,
        );
        if ( $cache_key ) {
            set_transient( $cache_key, $payload, 3 );
        }

        return new \WP_REST_Response( $payload, 200 );
    }
}
