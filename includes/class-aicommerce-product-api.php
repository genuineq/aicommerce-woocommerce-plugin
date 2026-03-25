<?php
/**
 * Product API Endpoints
 *
 * @package AICommerce
 */

namespace AICommerce;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Product API Class
 */
class ProductAPI {

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_action( 'save_post_product', array( $this, 'clear_search_cache' ) );
        add_action( 'woocommerce_update_product', array( $this, 'clear_search_cache' ) );
    }

    /**
     * Register REST API routes
     */
    public function register_routes(): void {
        $namespace = 'aicommerce/v1';

        register_rest_route(
            $namespace,
            '/products/search',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'search_products' ),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'q'        => array(
                        'description' => __( 'Search query. If omitted, products are returned by date.', 'aicommerce' ),
                        'type'        => 'string',
                        'required'    => false,
                    ),
                    'per_page' => array(
                        'description' => __( 'Number of products per page', 'aicommerce' ),
                        'type'        => 'integer',
                        'default'     => 20,
                        'minimum'     => 1,
                        'maximum'     => 100,
                    ),
                    'page'     => array(
                        'description' => __( 'Page number', 'aicommerce' ),
                        'type'        => 'integer',
                        'default'     => 1,
                        'minimum'     => 1,
                    ),
                ),
            )
        );
    }

    /**
     * Search products endpoint
     */
    public function search_products( \WP_REST_Request $request ): \WP_REST_Response {
        $validation = APIValidator::validate_request( $request );
        if ( ! $validation['valid'] ) {
            return APIValidator::error_response( $validation );
        }

        $search_query = sanitize_text_field( $request->get_param( 'q' ) );
        $per_page     = absint( $request->get_param( 'per_page' ) ) ?: 20;
        $page         = absint( $request->get_param( 'page' ) ) ?: 1;

        if ( empty( trim( $search_query ) ) ) {
            return $this->get_products_by_date( $per_page, $page );
        }

        $cache_key = 'aic_s_' . md5( $search_query . '|' . $page . '|' . $per_page );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return new \WP_REST_Response( $cached, 200 );
        }

        list( $paginated_ids, $total ) = $this->query_products( $search_query, $per_page, $page );

        if ( empty( $paginated_ids ) ) {
            $data = array(
                'success'      => true,
                'products'     => array(),
                'total'        => 0,
                'per_page'     => $per_page,
                'current_page' => $page,
                'pages'        => 0,
                'query'        => $search_query,
            );
            set_transient( $cache_key, $data, 30 * MINUTE_IN_SECONDS );
            return new \WP_REST_Response( $data, 200 );
        }

        $products = $this->format_products_batch( $paginated_ids );
        $pages    = (int) ceil( $total / $per_page );

        $data = array(
            'success'      => true,
            'products'     => $products,
            'total'        => $total,
            'per_page'     => $per_page,
            'current_page' => $page,
            'pages'        => $pages,
            'query'        => $search_query,
        );

        set_transient( $cache_key, $data, 30 * MINUTE_IN_SECONDS );
        return new \WP_REST_Response( $data, 200 );
    }

    /**
     * Combined SQL: score + paginate in one query, count in a second.
     * Returns array( $paginated_ids, $total ).
     */
    private function query_products( string $search_query, int $per_page, int $page ): array {
        global $wpdb;

        $exact    = $search_query;
        $starts   = $wpdb->esc_like( $search_query ) . '%';
        $contains = '%' . $wpdb->esc_like( $search_query ) . '%';
        $offset   = ( $page - 1 ) * $per_page;

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
                $exact, $starts, $contains,      // title CASE
                $contains,                        // excerpt CASE
                $contains,                        // content CASE
                $contains, $contains, $contains,  // WHERE title / excerpt / content
                $exact, $contains,               // SKU CASE
                $contains,                        // SKU WHERE
                $per_page, $offset
            )
        );

        $paginated_ids = array_map( function ( $r ) { return (int) $r->id; }, $results );

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

        return array( $paginated_ids, $total );
    }

    /**
     * Batch-format products.
     *
     * Query budget:
     *   1. _prime_post_caches( $product_ids )     — 2 queries: posts + postmeta
     *   2. wp_get_object_terms × 2                — 2 queries: categories + tags
     *   3. _prime_post_caches( $variation_ids )   — 2 queries: variation posts + postmeta
     *   4. update_meta_cache( $image_ids )        — 1 query:  image attachment meta (alt text)
     * Total: ~7 queries regardless of result set size.
     */
    private function format_products_batch( array $product_ids ): array {
        if ( empty( $product_ids ) ) {
            return array();
        }

        // 1. Load all product posts + their postmeta into WP object cache in 2 queries.
        //    Every wc_get_product() call below will read from memory, not from DB.
        _prime_post_caches( $product_ids, false, true );

        // 2. Load categories and tags for all products in 2 queries.
        $cat_terms = wp_get_object_terms( $product_ids, 'product_cat', array( 'fields' => 'all_with_object_id' ) );
        $tag_terms = wp_get_object_terms( $product_ids, 'product_tag', array( 'fields' => 'all_with_object_id' ) );

        $cats_map = array();
        $tags_map = array();

        if ( ! is_wp_error( $cat_terms ) ) {
            foreach ( $cat_terms as $term ) {
                $cats_map[ $term->object_id ][] = array(
                    'id'   => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                );
            }
        }

        if ( ! is_wp_error( $tag_terms ) ) {
            foreach ( $tag_terms as $term ) {
                $tags_map[ $term->object_id ][] = array(
                    'id'   => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                );
            }
        }

        // 3. First pass: load WC product objects from cache and collect
        //    all variation IDs and image IDs for the next batch primes.
        $wc_products       = array();
        $all_variation_ids = array();
        $all_image_ids     = array();

        foreach ( $product_ids as $product_id ) {
            $product = wc_get_product( $product_id ); // 0 DB queries — already in cache
            if ( ! $product ) {
                continue;
            }

            $wc_products[ $product_id ] = $product;

            if ( $product->is_type( 'variable' ) ) {
                $var_ids           = array_slice( $product->get_children(), 0, 20 );
                $all_variation_ids = array_merge( $all_variation_ids, $var_ids );
            }

            $image_id = $product->get_image_id();
            if ( $image_id ) {
                $all_image_ids[] = (int) $image_id;
            }
        }

        // 4. Load all variation posts + postmeta in 2 queries instead of N × 20.
        if ( ! empty( $all_variation_ids ) ) {
            _prime_post_caches( $all_variation_ids, false, true );
        }

        // 5. Load image attachment meta (needed for alt text) in 1 query.
        if ( ! empty( $all_image_ids ) ) {
            update_meta_cache( 'post', array_unique( $all_image_ids ) );
        }

        // 6. Second pass: format each product. All data is now in WP object cache.
        $products = array();
        foreach ( $product_ids as $product_id ) {
            if ( ! isset( $wc_products[ $product_id ] ) ) {
                continue;
            }
            $products[] = $this->format_product(
                $wc_products[ $product_id ],
                $cats_map[ $product_id ] ?? array(),
                $tags_map[ $product_id ] ?? array()
            );
        }

        return $products;
    }

    /**
     * Get published products ordered by date (when no search query)
     */
    private function get_products_by_date( int $per_page, int $page ): \WP_REST_Response {
        $query = new \WP_Query(
            array(
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'fields'         => 'ids',
            )
        );

        $ids   = (array) $query->posts;
        $total = (int) $query->found_posts;
        $pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

        return new \WP_REST_Response(
            array(
                'success'      => true,
                'products'     => $this->format_products_batch( $ids ),
                'total'        => $total,
                'per_page'     => $per_page,
                'current_page' => $page,
                'pages'        => $pages,
                'query'        => '',
            ),
            200
        );
    }

    /**
     * Invalidate search cache when a product is saved or updated.
     */
    public function clear_search_cache(): void {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_aic_s_%'
            OR option_name LIKE '_transient_timeout_aic_s_%'"
        );
    }

    /**
     * Format a single product for API response.
     * Accepts pre-loaded categories and tags to avoid N+1 queries.
     */
    private function format_product( \WC_Product $product, array $categories = array(), array $tags = array() ): array {
        $image_id  = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';

        $data = array(
            'id'             => $product->get_id(),
            'name'           => $product->get_name(),
            'slug'           => $product->get_slug(),
            'permalink'      => $product->get_permalink(),
            'sku'            => $product->get_sku(),
            'type'           => $product->get_type(),
            'status'         => $product->get_status(),
            'price'          => $product->get_price(),
            'regular_price'  => $product->get_regular_price(),
            'sale_price'     => $product->get_sale_price(),
            'on_sale'        => $product->is_on_sale(),
            'stock_status'   => $product->get_stock_status(),
            'stock_quantity' => $product->get_stock_quantity(),
            'manage_stock'   => $product->get_manage_stock(),
            'image'          => array(
                'id'  => $image_id,
                'url' => $image_url ?: '',
                'alt' => $image_id ? get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : '',
            ),
            'categories'     => $categories,
            'tags'           => $tags,
        );

        if ( $product->is_type( 'variable' ) ) {
            $data['variations'] = $this->get_product_variations( $product );
        }

        return $data;
    }

    /**
     * Get variation data for variable products.
     * Limited to 20 purchasable variations to prevent excessive DB queries.
     */
    private function get_product_variations( \WC_Product $product ): array {
        if ( ! $product->is_type( 'variable' ) ) {
            return array();
        }

        $variations    = array();
        $variation_ids = array_slice( $product->get_children(), 0, 20 );

        foreach ( $variation_ids as $variation_id ) {
            $variation = wc_get_product( $variation_id );
            if ( ! $variation || ! $variation->is_purchasable() ) {
                continue;
            }

            $attrs          = $variation->get_variation_attributes();
            $variation_data = array(
                'variation_id'  => (int) $variation_id,
                'attributes'    => array(),
                'price'         => $variation->get_price(),
                'regular_price' => $variation->get_regular_price(),
                'sale_price'    => $variation->get_sale_price(),
                'stock_status'  => $variation->get_stock_status(),
                'sku'           => $variation->get_sku(),
            );

            foreach ( $attrs as $key => $value ) {
                if ( '' !== $value ) {
                    $variation_data['attributes'][ $key ] = $value;
                }
            }

            $variation_data['variation_data'] = array_merge(
                array( 'variation_id' => (int) $variation_id ),
                $variation_data['attributes']
            );

            $variations[] = $variation_data;
        }

        return $variations;
    }
}
