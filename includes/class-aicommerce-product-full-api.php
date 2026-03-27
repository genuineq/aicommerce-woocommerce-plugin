<?php
/**
 * Product Full API Endpoint
 *
 * Returns complete product data: all auto-discovered taxonomies, all attributes
 * (global and local), full gallery, variations, downloads, and custom meta.
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ProductFullAPI Class
 */
class ProductFullAPI {

    /** Constructor. */
    public function __construct() {
        /** Register REST API routes. */
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );

        /** Clear products list cache on product save. */
        add_action( 'save_post_product', array( $this, 'clear_products_cache' ) );

        /** Clear single product cache on product save. */
        add_action( 'save_post_product', array( $this, 'clear_single_product_cache' ) );

        /** Clear products list cache on WooCommerce product update. */
        add_action( 'woocommerce_update_product', array( $this, 'clear_products_cache' ) );

        /** Clear single product cache on WooCommerce product update. */
        add_action( 'woocommerce_update_product', array( $this, 'clear_single_product_cache' ) );
    }

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public function register_routes(): void {
        /** Define API namespace. */
        $namespace = 'aicommerce/v1';

        /** Register single product endpoint. */
        register_rest_route(
            $namespace,
            '/products/(?P<id>\d+)',
            array(
                /** HTTP method. */
                'methods'             => 'GET',
                /** Callback method. */
                'callback'            => array( $this, 'get_product' ),
                /** Public access allowed. */
                'permission_callback' => '__return_true',
                /** Route arguments validation. */
                'args'                => array(
                    /** Product ID argument definition. */
                    'id' => array(
                        'description' => __( 'Product ID', 'aicommerce' ),
                        'type'        => 'integer',
                        'minimum'     => 1,
                        'required'    => true,
                    ),
                ),
            )
        );

        /** Register products list endpoint. */
        register_rest_route(
            $namespace,
            '/products',
            array(
                /** HTTP method. */
                'methods'             => 'GET',
                /** Callback method. */
                'callback'            => array( $this, 'get_products' ),
                /** Public access allowed. */
                'permission_callback' => '__return_true',
                /** Route arguments validation. */
                'args'                => array(
                    /** Products per page argument. */
                    'per_page' => array(
                        'description' => __( 'Number of products per page', 'aicommerce' ),
                        'type'        => 'integer',
                        'default'     => 20,
                        'minimum'     => 1,
                        'maximum'     => 100,
                    ),
                    /** Page number argument. */
                    'page'     => array(
                        'description' => __( 'Page number', 'aicommerce' ),
                        'type'        => 'integer',
                        'default'     => 1,
                        'minimum'     => 1,
                    ),
                    /** Sorting field argument. */
                    'orderby'  => array(
                        'description' => __( 'Sort field', 'aicommerce' ),
                        'type'        => 'string',
                        'default'     => 'date',
                        'enum'        => array( 'date', 'title', 'price', 'popularity', 'rating', 'menu_order' ),
                    ),
                    /** Sorting direction argument. */
                    'order'    => array(
                        'description' => __( 'Sort direction', 'aicommerce' ),
                        'type'        => 'string',
                        'default'     => 'DESC',
                        'enum'        => array( 'ASC', 'DESC' ),
                    ),
                ),
            )
        );
    }

    /**
     * Get a single product.
     *
     * @param \WP_REST_Request $request REST request object.
     * @return \WP_REST_Response
     */
    public function get_product( \WP_REST_Request $request ): \WP_REST_Response {
        /** Validate request. */
        $validation = APIValidator::validate_request( $request );

        /** Return validation error if request is invalid. */
        if ( ! $validation['valid'] ) {
            return APIValidator::error_response( $validation );
        }

        /** Extract and sanitize product ID. */
        $product_id = absint( $request->get_param( 'id' ) );

        /** Generate cache key for single product. */
        $cache_key = 'aic_p_single_' . $product_id;

        /** Try to retrieve cached data. */
        $cached = get_transient( $cache_key );

        /** Return cached response if available. */
        if ( false !== $cached ) {
            return new \WP_REST_Response( $cached, 200 );
        }

        /** Load WooCommerce product. */
        $product = wc_get_product( $product_id );

        /** Validate product existence and type. */
        if ( ! $product || 'product' !== get_post_type( $product_id ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'product_not_found',
                    'message' => __( 'Product not found.', 'aicommerce' ),
                ),
                404
            );
        }

        /** Ensure product is published. */
        if ( 'publish' !== $product->get_status() ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'product_not_published',
                    'message' => __( 'Product is not published.', 'aicommerce' ),
                ),
                404
            );
        }

        /** Format product using batch formatter (also primes caches). */
        $products_data = $this->format_products_batch( array( $product_id ) );

        /** Handle unexpected empty result. */
        if ( empty( $products_data ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'product_not_found',
                    'message' => __( 'Product not found.', 'aicommerce' ),
                ),
                404
            );
        }

        /** Prepare response payload. */
        $data = array(
            'success' => true,
            'product' => $products_data[0],
        );

        /** Cache result for 5 minutes. */
        set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );

        /** Return response. */
        return new \WP_REST_Response( $data, 200 );
    }

    /**
     * Get products list.
     *
     * @param \WP_REST_Request $request REST request object.
     * @return \WP_REST_Response
     */
    public function get_products( \WP_REST_Request $request ): \WP_REST_Response {
        /** Validate request. */
        $validation = APIValidator::validate_request( $request );

        /** Return validation error if invalid. */
        if ( ! $validation['valid'] ) {
            return APIValidator::error_response( $validation );
        }

        /** Extract and sanitize pagination parameters. */
        $per_page = absint( $request->get_param( 'per_page' ) ) ?: 20;
        $page     = absint( $request->get_param( 'page' ) ) ?: 1;

        /** Extract sorting parameters. */
        $orderby  = sanitize_text_field( $request->get_param( 'orderby' ) );
        $order    = strtoupper( sanitize_text_field( $request->get_param( 'order' ) ) );

        /** Build cache key for product list. */
        $cache_key = 'aic_p_' . md5( $page . '|' . $per_page . '|' . $orderby . '|' . $order );

        /** Try retrieving cached data. */
        $cached = get_transient( $cache_key );

        /** Return cached response if available. */
        if ( false !== $cached ) {
            return new \WP_REST_Response( $cached, 200 );
        }

        /** Query product IDs and total count. */
        list( $ids, $total ) = $this->query_products( $per_page, $page, $orderby, $order );

        /** Format products in batch. */
        $products = $this->format_products_batch( $ids );

        /** Calculate total pages. */
        $pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

        /** Build response payload. */
        $data = array(
            'success'      => true,
            'products'     => $products,
            'total'        => $total,
            'per_page'     => $per_page,
            'current_page' => $page,
            'pages'        => $pages,
        );

        /** Cache result for 30 minutes. */
        set_transient( $cache_key, $data, 30 * MINUTE_IN_SECONDS );

        /** Return response. */
        return new \WP_REST_Response( $data, 200 );
    }

    /**
     * Query published products.
     *
     * @param int    $per_page Number of products per page.
     * @param int    $page     Current page.
     * @param string $orderby  Sorting field.
     * @param string $order    Sorting direction.
     * @return array
     */
    private function query_products( int $per_page, int $page, string $orderby, string $order ): array {
        /** Map meta-based sorting fields to meta keys. */
        $meta_orderby_map = array(
            'price'      => '_price',
            'popularity' => 'total_sales',
            'rating'     => '_wc_average_rating',
        );

        /** Build WP_Query arguments. */
        $query_args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'fields'         => 'ids',
            /** Validate sorting direction. */
            'order'          => in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC',
        );

        /** Handle meta-based sorting. */
        if ( isset( $meta_orderby_map[ $orderby ] ) ) {
            $query_args['orderby']  = 'meta_value_num';
            $query_args['meta_key'] = $meta_orderby_map[ $orderby ];
        } else {
            /** Fallback to allowed core sorting fields. */
            $query_args['orderby'] = in_array( $orderby, array( 'title', 'menu_order', 'date' ), true )
                ? $orderby
                : 'date';
        }

        /** Execute query. */
        $query = new \WP_Query( $query_args );

        /** Return product IDs and total count. */
        return array( (array) $query->posts, (int) $query->found_posts );
    }

    /**
     * Clear single product cache.
     *
     * @param int $product_id Product ID.
     * @return void
     */
    public function clear_single_product_cache( int $product_id ): void {
        /** Delete cached single product transient. */
        delete_transient( 'aic_p_single_' . $product_id );
    }

    /**
     * Clear products list cache.
     *
     * @return void
     */
    public function clear_products_cache(): void {
        /** Prevent multiple executions in same request. */
        static $cleared = false;

        /** Exit if already cleared. */
        if ( $cleared ) {
            return;
        }

        /** Mark as cleared. */
        $cleared = true;

        /** Access global database object. */
        global $wpdb;

        /** Delete all related transients for products list. */
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_aic_p_%'
            OR option_name LIKE '_transient_timeout_aic_p_%'"
        );
    }
}
