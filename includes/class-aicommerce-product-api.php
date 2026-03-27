<?php
/**
 * Product API Endpoints
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Product API class. */
class ProductAPI {

    /**
     * Constructor.
     */
    public function __construct() {
        /** Register REST API routes during REST API initialization. */
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );

        /** Clear cached search results when a product post is saved. */
        add_action( 'save_post_product', array( $this, 'clear_search_cache' ) );

        /** Clear cached search results when WooCommerce updates a product. */
        add_action( 'woocommerce_update_product', array( $this, 'clear_search_cache' ) );
    }

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public function register_routes(): void {
        /** Define the REST API namespace for product endpoints. */
        $namespace = 'aicommerce/v1';

        /** Register the product search endpoint. */
        register_rest_route(
            $namespace,
            '/products/search',
            array(
                /** Allow GET requests for product search. */
                'methods'             => 'GET',

                /** Set the callback that handles product search requests. */
                'callback'            => array( $this, 'search_products' ),

                /** Allow public access and validate request inside the callback. */
                'permission_callback' => '__return_true',

                /** Define accepted request arguments. */
                'args'                => array(
                    /** Define the optional search query argument. */
                    'q'        => array(
                        /** Describe the search query argument. */
                        'description' => __( 'Search query. If omitted, products are returned by date.', 'aicommerce' ),

                        /** Define the expected argument type. */
                        'type'        => 'string',

                        /** Mark the argument as optional. */
                        'required'    => false,
                    ),

                    /** Define the per-page pagination argument. */
                    'per_page' => array(
                        /** Describe the per-page argument. */
                        'description' => __( 'Number of products per page', 'aicommerce' ),

                        /** Define the expected argument type. */
                        'type'        => 'integer',

                        /** Define the default value. */
                        'default'     => 20,

                        /** Define the minimum allowed value. */
                        'minimum'     => 1,

                        /** Define the maximum allowed value. */
                        'maximum'     => 100,
                    ),

                    /** Define the page number argument. */
                    'page'     => array(
                        /** Describe the page argument. */
                        'description' => __( 'Page number', 'aicommerce' ),

                        /** Define the expected argument type. */
                        'type'        => 'integer',

                        /** Define the default value. */
                        'default'     => 1,

                        /** Define the minimum allowed value. */
                        'minimum'     => 1,
                    ),
                ),
            )
        );
    }

    /**
     * Handle product search requests.
     *
     * @param \WP_REST_Request $request The incoming REST request.
     * @return \WP_REST_Response
     */
    public function search_products( \WP_REST_Request $request ): \WP_REST_Response {
        /** Validate the API request headers and signature. */
        $validation = APIValidator::validate_request( $request );

        /** Return a validation error response if the request is invalid. */
        if ( ! $validation['valid'] ) {
            return APIValidator::error_response( $validation );
        }

        /** Get and sanitize the search query from the request. */
        $search_query = sanitize_text_field( $request->get_param( 'q' ) );

        /** Resolve the requested products per page value. */
        $per_page     = absint( $request->get_param( 'per_page' ) ) ?: 20;

        /** Resolve the requested page number. */
        $page         = absint( $request->get_param( 'page' ) ) ?: 1;

        /** Return products ordered by date when no search query was provided. */
        if ( empty( trim( $search_query ) ) ) {
            return $this->get_products_by_date( $per_page, $page );
        }

        /** Build the transient cache key for the current search query and pagination. */
        $cache_key = 'aic_s_' . md5( $search_query . '|' . $page . '|' . $per_page );

        /** Attempt to load a cached search response. */
        $cached    = get_transient( $cache_key );

        /** Return the cached response when available. */
        if ( false !== $cached ) {
            return new \WP_REST_Response( $cached, 200 );
        }

        /** Run the product search query and get paginated product IDs with total count. */
        list( $paginated_ids, $total ) = $this->query_products( $search_query, $per_page, $page );

        /** Return an empty successful response when no products matched the query. */
        if ( empty( $paginated_ids ) ) {
            /** Build the empty search response payload. */
            $data = array(
                /** Indicate that the request succeeded. */
                'success'      => true,

                /** Return an empty product list. */
                'products'     => array(),

                /** Return zero total products. */
                'total'        => 0,

                /** Return the requested per-page value. */
                'per_page'     => $per_page,

                /** Return the current page value. */
                'current_page' => $page,

                /** Return zero total pages. */
                'pages'        => 0,

                /** Return the original search query. */
                'query'        => $search_query,
            );

            /** Cache the empty search response. */
            set_transient( $cache_key, $data, 30 * MINUTE_IN_SECONDS );

            /** Return the empty search response. */
            return new \WP_REST_Response( $data, 200 );
        }

        /** Format the matched products for API output. */
        $products = $this->format_products_batch( $paginated_ids );

        /** Calculate the total number of result pages. */
        $pages    = (int) ceil( $total / $per_page );

        /** Build the successful search response payload. */
        $data = array(
            /** Indicate that the request succeeded. */
            'success'      => true,

            /** Include the formatted product list. */
            'products'     => $products,

            /** Include the total number of matched products. */
            'total'        => $total,

            /** Include the requested per-page value. */
            'per_page'     => $per_page,

            /** Include the current page number. */
            'current_page' => $page,

            /** Include the total number of result pages. */
            'pages'        => $pages,

            /** Include the original search query. */
            'query'        => $search_query,
        );

        /** Cache the search response payload. */
        set_transient( $cache_key, $data, 30 * MINUTE_IN_SECONDS );

        /** Return the search response. */
        return new \WP_REST_Response( $data, 200 );
    }

    /**
     * Query matching products with relevance scoring and pagination.
     *
     * Returns an array containing the paginated product IDs and the total count.
     *
     * @param string $search_query The search query.
     * @param int    $per_page The number of products per page.
     * @param int    $page The current page number.
     * @return array
     */
    private function query_products( string $search_query, int $per_page, int $page ): array {
        /** Access the global WordPress database object. */
        global $wpdb;

        /** Keep the raw search query for exact-match relevance checks. */
        $exact    = $search_query;

        /** Build the prefix LIKE pattern for title and SKU starts-with matching. */
        $starts   = $wpdb->esc_like( $search_query ) . '%';

        /** Build the contains LIKE pattern for broader matching. */
        $contains = '%' . $wpdb->esc_like( $search_query ) . '%';

        /** Calculate the SQL offset for pagination. */
        $offset   = ( $page - 1 ) * $per_page;

        /** Run the relevance-scored SQL query to fetch paginated matching product IDs. */
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, SUM(score) AS relevance
                FROM (
                    SELECT p.ID AS id,
                        CASE
                            WHEN p.post_title = %s      THEN 100
                            WHEN p.post_title LIKE %s   THEN 80
                            WHEN p.post_title LIKE %s   THEN 60
                            ELSE 0
                        END
                        + CASE WHEN p.post_excerpt LIKE %s              THEN 15 ELSE 0 END
                        + CASE WHEN LEFT(p.post_content, 300) LIKE %s   THEN 10 ELSE 0 END
                        AS score
                    FROM {$wpdb->posts} p
                    WHERE p.post_type = 'product'
                    AND p.post_status = 'publish'
                    AND (
                        p.post_title LIKE %s
                        OR p.post_excerpt LIKE %s
                        OR LEFT(p.post_content, 300) LIKE %s
                    )

                    UNION ALL

                    SELECT p.ID AS id,
                        CASE
                            WHEN pm.meta_value = %s     THEN 90
                            WHEN pm.meta_value LIKE %s  THEN 50
                            ELSE 0
                        END AS score
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    WHERE p.post_type = 'product'
                    AND p.post_status = 'publish'
                    AND pm.meta_key = '_sku'
                    AND pm.meta_value LIKE %s
                ) combined
                GROUP BY id
                ORDER BY relevance DESC
                LIMIT %d OFFSET %d",
                $exact, $starts, $contains,
                $contains,
                $contains,
                $contains, $contains, $contains,
                $exact, $contains,
                $contains,
                $per_page, $offset
            )
        );

        /** Extract integer product IDs from the scored result set. */
        $paginated_ids = array_map( function ( $r ) { return (int) $r->id; }, $results );

        /** Run a second SQL query to compute the total number of unique matching products. */
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM (
                    SELECT p.ID
                    FROM {$wpdb->posts} p
                    WHERE p.post_type = 'product'
                    AND p.post_status = 'publish'
                    AND (
                        p.post_title LIKE %s
                        OR p.post_excerpt LIKE %s
                        OR LEFT(p.post_content, 300) LIKE %s
                    )
                    UNION DISTINCT
                    SELECT p.ID
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    WHERE p.post_type = 'product'
                    AND p.post_status = 'publish'
                    AND pm.meta_key = '_sku'
                    AND pm.meta_value LIKE %s
                ) counted",
                $contains, $contains, $contains, $contains
            )
        );

        /** Return the paginated product IDs and total match count. */
        return array( $paginated_ids, $total );
    }

    /**
     * Batch-format multiple products.
     *
     * Uses batched cache priming to avoid N+1 query patterns.
     *
     * @param array $product_ids The product IDs to format.
     * @return array
     */
    private function format_products_batch( array $product_ids ): array {
        /** Return an empty array when there are no product IDs to format. */
        if ( empty( $product_ids ) ) {
            return array();
        }

        /** Prime product post and postmeta caches in bulk. */
        _prime_post_caches( $product_ids, false, true );

        /** Load all product category terms for the requested products in one query. */
        $cat_terms = wp_get_object_terms( $product_ids, 'product_cat', array( 'fields' => 'all_with_object_id' ) );

        /** Load all product tag terms for the requested products in one query. */
        $tag_terms = wp_get_object_terms( $product_ids, 'product_tag', array( 'fields' => 'all_with_object_id' ) );

        /** Initialize the per-product category map. */
        $cats_map = array();

        /** Initialize the per-product tag map. */
        $tags_map = array();

        /** Build the category map when term loading succeeded. */
        if ( ! is_wp_error( $cat_terms ) ) {
            foreach ( $cat_terms as $term ) {
                $cats_map[ $term->object_id ][] = array(
                    /** Include the category term ID. */
                    'id'   => $term->term_id,

                    /** Include the category term name. */
                    'name' => $term->name,

                    /** Include the category term slug. */
                    'slug' => $term->slug,
                );
            }
        }

        /** Build the tag map when term loading succeeded. */
        if ( ! is_wp_error( $tag_terms ) ) {
            foreach ( $tag_terms as $term ) {
                $tags_map[ $term->object_id ][] = array(
                    /** Include the tag term ID. */
                    'id'   => $term->term_id,

                    /** Include the tag term name. */
                    'name' => $term->name,

                    /** Include the tag term slug. */
                    'slug' => $term->slug,
                );
            }
        }

        /** Initialize the map of loaded WooCommerce product objects. */
        $wc_products       = array();

        /** Initialize the list of all variation IDs that need cache priming. */
        $all_variation_ids = array();

        /** Initialize the list of all product image IDs that need meta cache priming. */
        $all_image_ids     = array();

        /** Load product objects from cache and collect related variation and image IDs. */
        foreach ( $product_ids as $product_id ) {
            /** Load the WooCommerce product object. */
            $product = wc_get_product( $product_id );

            /** Skip IDs that do not resolve to products. */
            if ( ! $product ) {
                continue;
            }

            /** Store the loaded WooCommerce product object. */
            $wc_products[ $product_id ] = $product;

            /** Collect up to 20 variation IDs for variable products. */
            if ( $product->is_type( 'variable' ) ) {
                $var_ids           = array_slice( $product->get_children(), 0, 20 );
                $all_variation_ids = array_merge( $all_variation_ids, $var_ids );
            }

            /** Collect the featured image ID when present. */
            $image_id = $product->get_image_id();

            /** Store the image ID for later attachment meta priming. */
            if ( $image_id ) {
                $all_image_ids[] = (int) $image_id;
            }
        }

        /** Prime variation post and postmeta caches in bulk when variations were collected. */
        if ( ! empty( $all_variation_ids ) ) {
            _prime_post_caches( $all_variation_ids, false, true );
        }

        /** Prime attachment meta cache for collected image IDs. */
        if ( ! empty( $all_image_ids ) ) {
            update_meta_cache( 'post', array_unique( $all_image_ids ) );
        }

        /** Initialize the formatted product output list. */
        $products = array();

        /** Format each loaded product using the already primed data. */
        foreach ( $product_ids as $product_id ) {
            /** Skip products that failed to load earlier. */
            if ( ! isset( $wc_products[ $product_id ] ) ) {
                continue;
            }

            /** Append the formatted product payload to the result list. */
            $products[] = $this->format_product(
                $wc_products[ $product_id ],
                $cats_map[ $product_id ] ?? array(),
                $tags_map[ $product_id ] ?? array()
            );
        }

        /** Return the formatted product list. */
        return $products;
    }

    /**
     * Get published products ordered by date when no search query is provided.
     *
     * @param int $per_page The number of products per page.
     * @param int $page The current page number.
     * @return \WP_REST_Response
     */
    private function get_products_by_date( int $per_page, int $page ): \WP_REST_Response {
        /** Query published WooCommerce products ordered by descending publish date. */
        $query = new \WP_Query(
            array(
                /** Query only WooCommerce products. */
                'post_type'      => 'product',

                /** Query only published products. */
                'post_status'    => 'publish',

                /** Limit the number of products per page. */
                'posts_per_page' => $per_page,

                /** Apply pagination. */
                'paged'          => $page,

                /** Order by publish date. */
                'orderby'        => 'date',

                /** Sort newest first. */
                'order'          => 'DESC',

                /** Return only post IDs. */
                'fields'         => 'ids',
            )
        );

        /** Extract the queried product IDs. */
        $ids   = (array) $query->posts;

        /** Extract the total number of found products. */
        $total = (int) $query->found_posts;

        /** Calculate the total number of pages. */
        $pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

        /** Return the date-ordered product response payload. */
        return new \WP_REST_Response(
            array(
                /** Indicate that the request succeeded. */
                'success'      => true,

                /** Include the formatted product list. */
                'products'     => $this->format_products_batch( $ids ),

                /** Include the total number of found products. */
                'total'        => $total,

                /** Include the requested per-page value. */
                'per_page'     => $per_page,

                /** Include the current page number. */
                'current_page' => $page,

                /** Include the total number of pages. */
                'pages'        => $pages,

                /** Include an empty query string because date mode was used. */
                'query'        => '',
            ),
            200
        );
    }

    /**
     * Invalidate cached search results when products change.
     *
     * @return void
     */
    public function clear_search_cache(): void {
        /** Access the global WordPress database object. */
        global $wpdb;

        /** Delete all product search transients and their timeout rows. */
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_aic_s_%'
            OR option_name LIKE '_transient_timeout_aic_s_%'"
        );
    }

    /**
     * Format a single product for the API response.
     *
     * Accepts preloaded categories and tags to avoid extra term queries.
     *
     * @param \WC_Product $product The WooCommerce product object.
     * @param array       $categories Preloaded product categories.
     * @param array       $tags Preloaded product tags.
     * @return array
     */
    private function format_product( \WC_Product $product, array $categories = array(), array $tags = array() ): array {
        /** Get the featured image attachment ID. */
        $image_id  = $product->get_image_id();

        /** Resolve the product thumbnail URL. */
        $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';

        /** Build the base formatted product payload. */
        $data = array(
            /** Include the product ID. */
            'id'             => $product->get_id(),

            /** Include the product name. */
            'name'           => $product->get_name(),

            /** Include the product slug. */
            'slug'           => $product->get_slug(),

            /** Include the product permalink. */
            'permalink'      => $product->get_permalink(),

            /** Include the product SKU. */
            'sku'            => $product->get_sku(),

            /** Include the product type. */
            'type'           => $product->get_type(),

            /** Include the product status. */
            'status'         => $product->get_status(),

            /** Include the active product price. */
            'price'          => $product->get_price(),

            /** Include the regular price. */
            'regular_price'  => $product->get_regular_price(),

            /** Include the sale price. */
            'sale_price'     => $product->get_sale_price(),

            /** Include whether the product is currently on sale. */
            'on_sale'        => $product->is_on_sale(),

            /** Include the stock status. */
            'stock_status'   => $product->get_stock_status(),

            /** Include the stock quantity. */
            'stock_quantity' => $product->get_stock_quantity(),

            /** Include whether stock management is enabled. */
            'manage_stock'   => $product->get_manage_stock(),

            /** Include image information. */
            'image'          => array(
                /** Include the attachment ID. */
                'id'  => $image_id,

                /** Include the thumbnail URL. */
                'url' => $image_url ?: '',

                /** Include the attachment alt text. */
                'alt' => $image_id ? get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : '',
            ),

            /** Include preloaded categories. */
            'categories'     => $categories,

            /** Include preloaded tags. */
            'tags'           => $tags,
        );

        /** Include variation data when the product is variable. */
        if ( $product->is_type( 'variable' ) ) {
            $data['variations'] = $this->get_product_variations( $product );
        }

        /** Return the formatted product payload. */
        return $data;
    }

    /**
     * Get formatted variation data for a variable product.
     *
     * Limited to 20 purchasable variations to prevent excessive processing.
     *
     * @param \WC_Product $product The WooCommerce product object.
     * @return array
     */
    private function get_product_variations( \WC_Product $product ): array {
        /** Return an empty array when the product is not variable. */
        if ( ! $product->is_type( 'variable' ) ) {
            return array();
        }

        /** Initialize the formatted variation output list. */
        $variations    = array();

        /** Limit variation loading to the first 20 child variation IDs. */
        $variation_ids = array_slice( $product->get_children(), 0, 20 );

        /** Format each purchasable variation. */
        foreach ( $variation_ids as $variation_id ) {
            /** Load the variation product object. */
            $variation = wc_get_product( $variation_id );

            /** Skip invalid or non-purchasable variations. */
            if ( ! $variation || ! $variation->is_purchasable() ) {
                continue;
            }

            /** Load the variation attributes. */
            $attrs          = $variation->get_variation_attributes();

            /** Build the base variation payload. */
            $variation_data = array(
                /** Include the variation ID. */
                'variation_id'  => (int) $variation_id,

                /** Initialize the normalized attribute list. */
                'attributes'    => array(),

                /** Include the variation price. */
                'price'         => $variation->get_price(),

                /** Include the variation regular price. */
                'regular_price' => $variation->get_regular_price(),

                /** Include the variation sale price. */
                'sale_price'    => $variation->get_sale_price(),

                /** Include the variation stock status. */
                'stock_status'  => $variation->get_stock_status(),

                /** Include the variation SKU. */
                'sku'           => $variation->get_sku(),
            );

            /** Keep only non-empty variation attributes. */
            foreach ( $attrs as $key => $value ) {
                if ( '' !== $value ) {
                    $variation_data['attributes'][ $key ] = $value;
                }
            }

            /** Build the variation_data structure expected by cart-related endpoints. */
            $variation_data['variation_data'] = array_merge(
                array( 'variation_id' => (int) $variation_id ),
                $variation_data['attributes']
            );

            /** Append the formatted variation payload. */
            $variations[] = $variation_data;
        }

        /** Return the formatted variation list. */
        return $variations;
    }
}
