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

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ProductFullAPI Class
 */
class ProductFullAPI {

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_action( 'save_post_product', array( $this, 'clear_products_cache' ) );
        add_action( 'save_post_product', array( $this, 'clear_single_product_cache' ) );
        add_action( 'woocommerce_update_product', array( $this, 'clear_products_cache' ) );
        add_action( 'woocommerce_update_product', array( $this, 'clear_single_product_cache' ) );
    }

    /**
     * Register REST API routes
     */
    public function register_routes(): void {
        $namespace = 'aicommerce/v1';

        register_rest_route(
            $namespace,
            '/products/(?P<id>\d+)',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_product' ),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'id' => array(
                        'description' => __( 'Product ID', 'aicommerce' ),
                        'type'        => 'integer',
                        'minimum'     => 1,
                        'required'    => true,
                    ),
                ),
            )
        );

        register_rest_route(
            $namespace,
            '/products',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_products' ),
                'permission_callback' => '__return_true',
                'args'                => array(
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
                    'orderby'  => array(
                        'description' => __( 'Sort field', 'aicommerce' ),
                        'type'        => 'string',
                        'default'     => 'date',
                        'enum'        => array( 'date', 'title', 'price', 'popularity', 'rating', 'menu_order' ),
                    ),
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
     * GET /aicommerce/v1/products/{id}
     */
    public function get_product( \WP_REST_Request $request ): \WP_REST_Response {
        $validation = APIValidator::validate_request( $request );
        if ( ! $validation['valid'] ) {
            return APIValidator::error_response( $validation );
        }

        $product_id = absint( $request->get_param( 'id' ) );

        $cache_key = 'aic_p_single_' . $product_id;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return new \WP_REST_Response( $cached, 200 );
        }

        $product = wc_get_product( $product_id );

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

        // Prime postmeta cache for the product (and its variations + images).
        $products_data = $this->format_products_batch( array( $product_id ) );

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

        $data = array(
            'success' => true,
            'product' => $products_data[0],
        );

        set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );
        return new \WP_REST_Response( $data, 200 );
    }

    /**
     * GET /aicommerce/v1/products
     */
    public function get_products( \WP_REST_Request $request ): \WP_REST_Response {
        $validation = APIValidator::validate_request( $request );
        if ( ! $validation['valid'] ) {
            return APIValidator::error_response( $validation );
        }

        $per_page = absint( $request->get_param( 'per_page' ) ) ?: 20;
        $page     = absint( $request->get_param( 'page' ) ) ?: 1;
        $orderby  = sanitize_text_field( $request->get_param( 'orderby' ) );
        $order    = strtoupper( sanitize_text_field( $request->get_param( 'order' ) ) );

        $cache_key = 'aic_p_' . md5( $page . '|' . $per_page . '|' . $orderby . '|' . $order );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return new \WP_REST_Response( $cached, 200 );
        }

        list( $ids, $total ) = $this->query_products( $per_page, $page, $orderby, $order );

        $products = $this->format_products_batch( $ids );
        $pages    = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

        $data = array(
            'success'      => true,
            'products'     => $products,
            'total'        => $total,
            'per_page'     => $per_page,
            'current_page' => $page,
            'pages'        => $pages,
        );

        set_transient( $cache_key, $data, 30 * MINUTE_IN_SECONDS );
        return new \WP_REST_Response( $data, 200 );
    }

    /**
     * Query published products, return [ $ids[], $total ].
     *
     * Supported $orderby values are mapped to WP_Query equivalents.
     * Meta-based sorts (price, popularity, rating) add a single meta_key join.
     */
    private function query_products( int $per_page, int $page, string $orderby, string $order ): array {
        $meta_orderby_map = array(
            'price'      => '_price',
            'popularity' => 'total_sales',
            'rating'     => '_wc_average_rating',
        );

        $query_args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'fields'         => 'ids',
            'order'          => in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC',
        );

        if ( isset( $meta_orderby_map[ $orderby ] ) ) {
            $query_args['orderby']  = 'meta_value_num';
            $query_args['meta_key'] = $meta_orderby_map[ $orderby ];
        } else {
            $query_args['orderby'] = in_array( $orderby, array( 'title', 'menu_order', 'date' ), true )
                ? $orderby
                : 'date';
        }

        $query = new \WP_Query( $query_args );

        return array( (array) $query->posts, (int) $query->found_posts );
    }

    /**
     * Batch-format products with full data.
     *
     * Query budget per call:
     *   2   — _prime_post_caches( $product_ids )   posts + postmeta
     *   N   — wp_get_object_terms per taxonomy      (auto-discovered, 1 query each)
     *   2   — _prime_post_caches( $variation_ids )  variation posts + postmeta
     *   1   — update_meta_cache( $image_ids )       attachment alt text
     *   ─────────────────────────────────────────────────────────────────
     *   ~5 + number_of_taxonomies  (typically 8–12 total)
     */
    private function format_products_batch( array $product_ids ): array {
        if ( empty( $product_ids ) ) {
            return array();
        }

        // 1. Load all product posts + postmeta into WP object cache (2 queries).
        //    After this, every wc_get_product() call reads from memory — 0 DB queries.
        _prime_post_caches( $product_ids, false, true );

        // 2. Auto-discover every taxonomy registered for the 'product' post type.
        //    This automatically picks up: product_cat, product_tag, product_type,
        //    product_visibility, product_shipping_class, pa_* (global attributes),
        //    custom-specifications, and any taxonomy added by third-party plugins.
        $taxonomies = get_object_taxonomies( 'product' );

        // 3. Batch-load terms for every taxonomy — 1 query per taxonomy.
        //    terms_map[taxonomy][product_id] = [ term_data, ... ]
        $terms_map = array();
        foreach ( $taxonomies as $taxonomy ) {
            $terms = wp_get_object_terms(
                $product_ids,
                $taxonomy,
                array( 'fields' => 'all_with_object_id' )
            );
            if ( is_wp_error( $terms ) ) {
                continue;
            }
            foreach ( $terms as $term ) {
                $terms_map[ $taxonomy ][ $term->object_id ][] = array(
                    'id'          => (int) $term->term_id,
                    'name'        => $term->name,
                    'slug'        => $term->slug,
                    'description' => $term->description,
                    'parent'      => (int) $term->parent,
                );
            }
        }

        // 4. First pass: load WC objects from cache and collect
        //    variation IDs + all image IDs for the next batch primes.
        $wc_products       = array();
        $all_variation_ids = array();
        $all_image_ids     = array();

        foreach ( $product_ids as $product_id ) {
            $product = wc_get_product( $product_id ); // reads from WP cache — 0 DB queries
            if ( ! $product ) {
                continue;
            }

            $wc_products[ $product_id ] = $product;

            if ( $product->is_type( 'variable' ) ) {
                $var_ids           = array_slice( $product->get_children(), 0, 20 );
                $all_variation_ids = array_merge( $all_variation_ids, $var_ids );
            }

            // Collect main image + gallery images
            $image_id = $product->get_image_id();
            if ( $image_id ) {
                $all_image_ids[] = (int) $image_id;
            }
            foreach ( $product->get_gallery_image_ids() as $gid ) {
                $all_image_ids[] = (int) $gid;
            }
        }

        // 5. Prime variation posts + postmeta in 2 queries total.
        if ( ! empty( $all_variation_ids ) ) {
            _prime_post_caches( $all_variation_ids, false, true );
        }

        // 6. Prime image attachment meta (for alt text) in 1 query.
        if ( ! empty( $all_image_ids ) ) {
            update_meta_cache( 'post', array_unique( $all_image_ids ) );
        }

        // 7. Second pass: format each product — all data is in WP object cache.
        $products = array();
        foreach ( $product_ids as $product_id ) {
            if ( ! isset( $wc_products[ $product_id ] ) ) {
                continue;
            }
            $products[] = $this->format_product_full(
                $wc_products[ $product_id ],
                $terms_map,
                $taxonomies
            );
        }

        return $products;
    }

    /**
     * Build a complete product data array.
     *
     * All taxonomy terms come from the pre-loaded $terms_map — no extra queries.
     * All attachment meta (alt text) was primed — wp_get_attachment_image_url reads cache.
     *
     * @param \WC_Product $product    The WooCommerce product object.
     * @param array       $terms_map  [taxonomy => [product_id => [term_data]]]
     * @param array       $taxonomies All registered product taxonomies.
     */
    private function format_product_full( \WC_Product $product, array $terms_map, array $taxonomies ): array {
        $product_id = $product->get_id();

        // ── Images ───────────────────────────────────────────────────────────
        $main_image_id = $product->get_image_id();
        $gallery_ids   = $product->get_gallery_image_ids();
        $images        = array();

        foreach ( array_merge( array( $main_image_id ), $gallery_ids ) as $image_id ) {
            if ( ! $image_id ) {
                continue;
            }
            $images[] = array(
                'id'       => (int) $image_id,
                'url'      => wp_get_attachment_image_url( $image_id, 'full' ) ?: '',
                'thumb'    => wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) ?: '',
                'alt'      => get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ?: '',
                'title'    => get_the_title( $image_id ) ?: '',
                'featured' => ( (int) $image_id === (int) $main_image_id ),
            );
        }

        // ── Taxonomies (auto-discovered) ─────────────────────────────────────
        // Every taxonomy registered for 'product' is included automatically.
        // terms_map already has all data — zero extra queries here.
        $taxonomy_data = array();
        foreach ( $taxonomies as $taxonomy ) {
            $tax_object = get_taxonomy( $taxonomy );
            $taxonomy_data[ $taxonomy ] = array(
                'label' => $tax_object ? $tax_object->label : $taxonomy,
                'terms' => $terms_map[ $taxonomy ][ $product_id ] ?? array(),
            );
        }

        // ── WooCommerce Attributes ────────────────────────────────────────────
        // Global attributes (pa_*) reuse data already in terms_map.
        // Local attributes read options from postmeta (already in WP cache).
        $attributes_data = array();
        foreach ( $product->get_attributes() as $attribute ) {
            if ( $attribute->is_taxonomy() ) {
                $attr_terms = $terms_map[ $attribute->get_name() ][ $product_id ] ?? array();
                $attributes_data[] = array(
                    'id'           => $attribute->get_id(),
                    'name'         => $attribute->get_name(),
                    'label'        => wc_attribute_label( $attribute->get_name() ),
                    'type'         => 'global',
                    'is_visible'   => (bool) $attribute->get_visible(),
                    'is_variation' => (bool) $attribute->get_variation(),
                    'options'      => $attr_terms,
                );
            } else {
                $options = array_map(
                    function ( $option ) {
                        return array(
                            'name' => $option,
                            'slug' => sanitize_title( $option ),
                        );
                    },
                    $attribute->get_options()
                );
                $attributes_data[] = array(
                    'id'           => 0,
                    'name'         => $attribute->get_name(),
                    'label'        => $attribute->get_name(),
                    'type'         => 'local',
                    'is_visible'   => (bool) $attribute->get_visible(),
                    'is_variation' => (bool) $attribute->get_variation(),
                    'options'      => $options,
                );
            }
        }

        // ── Variations (variable products only) ───────────────────────────────
        $variations_data = array();
        if ( $product->is_type( 'variable' ) ) {
            foreach ( array_slice( $product->get_children(), 0, 20 ) as $variation_id ) {
                $variation = wc_get_product( $variation_id ); // from cache
                if ( ! $variation || ! $variation->is_purchasable() ) {
                    continue;
                }

                $var_attrs = array();
                foreach ( $variation->get_variation_attributes() as $key => $value ) {
                    if ( '' !== $value ) {
                        $var_attrs[ $key ] = $value;
                    }
                }

                $var_image_id = $variation->get_image_id();
                $variations_data[] = array(
                    'variation_id'   => (int) $variation_id,
                    'sku'            => $variation->get_sku(),
                    'price'          => $variation->get_price(),
                    'regular_price'  => $variation->get_regular_price(),
                    'sale_price'     => $variation->get_sale_price(),
                    'on_sale'        => $variation->is_on_sale(),
                    'stock_status'   => $variation->get_stock_status(),
                    'stock_quantity' => $variation->get_stock_quantity(),
                    'manage_stock'   => $variation->get_manage_stock(),
                    'weight'         => $variation->get_weight(),
                    'dimensions'     => array(
                        'length' => $variation->get_length(),
                        'width'  => $variation->get_width(),
                        'height' => $variation->get_height(),
                    ),
                    'image'          => array(
                        'id'    => (int) $var_image_id,
                        'url'   => $var_image_id
                            ? ( wp_get_attachment_image_url( $var_image_id, 'full' ) ?: '' )
                            : '',
                        'thumb' => $var_image_id
                            ? ( wp_get_attachment_image_url( $var_image_id, 'woocommerce_thumbnail' ) ?: '' )
                            : '',
                    ),
                    'attributes'     => $var_attrs,
                    'variation_data' => array_merge(
                        array( 'variation_id' => (int) $variation_id ),
                        $var_attrs
                    ),
                );
            }
        }

        // ── Downloads ────────────────────────────────────────────────────────
        $downloads_data = array();
        if ( $product->is_downloadable() ) {
            foreach ( $product->get_downloads() as $download ) {
                $downloads_data[] = array(
                    'id'   => $download->get_id(),
                    'name' => $download->get_name(),
                    'file' => $download->get_file(),
                );
            }
        }

        // ── Custom meta (non-WC-internal keys only) ───────────────────────────
        $meta_data = array();
        foreach ( $product->get_meta_data() as $meta ) {
            $entry = $meta->get_data();
            // Expose only public meta (keys not starting with underscore)
            if ( isset( $entry['key'] ) && strpos( $entry['key'], '_' ) !== 0 ) {
                $meta_data[] = array(
                    'key'   => $entry['key'],
                    'value' => $entry['value'],
                );
            }
        }

        // ── Date helpers ─────────────────────────────────────────────────────
        $date_created  = $product->get_date_created();
        $date_modified = $product->get_date_modified();
        $sale_from     = $product->get_date_on_sale_from();
        $sale_to       = $product->get_date_on_sale_to();

        return array(
            // Identity
            'id'                 => $product_id,
            'name'               => $product->get_name(),
            'slug'               => $product->get_slug(),
            'permalink'          => $product->get_permalink(),
            'date_created'       => $date_created  ? $date_created->date( 'c' )  : null,
            'date_modified'      => $date_modified ? $date_modified->date( 'c' ) : null,
            'menu_order'         => $product->get_menu_order(),
            'parent_id'          => $product->get_parent_id(),

            // Type & status
            'type'               => $product->get_type(),
            'status'             => $product->get_status(),
            'featured'           => $product->is_featured(),
            'virtual'            => $product->is_virtual(),
            'downloadable'       => $product->is_downloadable(),

            // Description
            'description'        => $product->get_description(),
            'short_description'  => $product->get_short_description(),

            // Pricing
            'sku'                => $product->get_sku(),
            'price'              => $product->get_price(),
            'regular_price'      => $product->get_regular_price(),
            'sale_price'         => $product->get_sale_price(),
            'on_sale'            => $product->is_on_sale(),
            'date_on_sale_from'  => $sale_from ? $sale_from->date( 'c' ) : null,
            'date_on_sale_to'    => $sale_to   ? $sale_to->date( 'c' )   : null,

            // Stock
            'stock_status'       => $product->get_stock_status(),
            'stock_quantity'     => $product->get_stock_quantity(),
            'manage_stock'       => $product->get_manage_stock(),
            'backorders'         => $product->get_backorders(),
            'backorders_allowed' => $product->backorders_allowed(),
            'sold_individually'  => $product->is_sold_individually(),

            // Physical
            'weight'             => $product->get_weight(),
            'dimensions'         => array(
                'length' => $product->get_length(),
                'width'  => $product->get_width(),
                'height' => $product->get_height(),
            ),

            // Shipping & tax
            'shipping_class'     => $product->get_shipping_class(),
            'shipping_class_id'  => $product->get_shipping_class_id(),
            'tax_status'         => $product->get_tax_status(),
            'tax_class'          => $product->get_tax_class(),

            // Reviews
            'average_rating'     => $product->get_average_rating(),
            'rating_count'       => $product->get_rating_count(),
            'review_count'       => $product->get_review_count(),

            // Relations
            'upsell_ids'         => $product->get_upsell_ids(),
            'cross_sell_ids'     => $product->get_cross_sell_ids(),

            // Downloads
            'downloads'          => $downloads_data,
            'download_limit'     => $product->get_download_limit(),
            'download_expiry'    => $product->get_download_expiry(),

            // Images (main + gallery)
            'images'             => $images,

            // WooCommerce attributes (global pa_* and local)
            'attributes'         => $attributes_data,

            // All taxonomies auto-discovered (product_cat, product_tag,
            // pa_*, custom-specifications, and any third-party taxonomies)
            'taxonomies'         => $taxonomy_data,

            // Variations (variable products, max 20)
            'variations'         => $variations_data,

            // Public custom meta
            'meta_data'          => $meta_data,
        );
    }

    /**
     * Invalidate single product cache when that product is saved.
     *
     * @param int $product_id
     */
    public function clear_single_product_cache( int $product_id ): void {
        delete_transient( 'aic_p_single_' . $product_id );
    }

    /**
     * Invalidate products list cache when any product is saved.
     */
    public function clear_products_cache(): void {
        static $cleared = false;
        if ( $cleared ) {
            return;
        }
        $cleared = true;

        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_aic_p_%'
            OR option_name LIKE '_transient_timeout_aic_p_%'"
        );
    }
}
