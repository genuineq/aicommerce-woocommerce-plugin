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
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes(): void {
        $namespace = 'aicommerce/v1';
        
        // Search products endpoint
        register_rest_route(
            $namespace,
            '/products/search',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'search_products' ),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'q'         => array(
                        'description' => __( 'Search query', 'aicommerce' ),
                        'type'        => 'string',
                        'required'    => true,
                    ),
                    'per_page'  => array(
                        'description' => __( 'Number of products per page', 'aicommerce' ),
                        'type'        => 'integer',
                        'default'     => 20,
                        'minimum'     => 1,
                        'maximum'     => 100,
                    ),
                    'page'      => array(
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
        $search_query = sanitize_text_field( $request->get_param( 'q' ) );
        $per_page     = absint( $request->get_param( 'per_page' ) ) ?: 20;
        $page         = absint( $request->get_param( 'page' ) ) ?: 1;
        
        if ( empty( $search_query ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'empty_search_query',
                    'message' => __( 'Search query is required.', 'aicommerce' ),
                ),
                400
            );
        }
        
        $title_desc_ids = $this->search_in_title_description( $search_query );
        
        $sku_ids = $this->search_in_sku( $search_query );
        
        $all_ids = array_unique( array_merge( $title_desc_ids, $sku_ids ) );
        
        if ( empty( $all_ids ) ) {
            return new \WP_REST_Response(
                array(
                    'success'      => true,
                    'products'     => array(),
                    'total'        => 0,
                    'per_page'     => $per_page,
                    'current_page' => $page,
                    'pages'        => 0,
                    'query'        => $search_query,
                ),
                200
            );
        }
        
        $scored_ids = $this->calculate_relevance( $all_ids, $search_query );
        
        arsort( $scored_ids );
        
        $sorted_ids = array_keys( $scored_ids );
        
        $offset = ( $page - 1 ) * $per_page;
        $paginated_ids = array_slice( $sorted_ids, $offset, $per_page );
        
        $products = array();
        foreach ( $paginated_ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( $product ) {
                $products[] = $this->format_product( $product );
            }
        }
        
        $total = count( $sorted_ids );
        $pages = ceil( $total / $per_page );
        
        return new \WP_REST_Response(
            array(
                'success'      => true,
                'products'     => $products,
                'total'        => $total,
                'per_page'     => $per_page,
                'current_page' => $page,
                'pages'        => $pages,
                'query'        => $search_query,
            ),
            200
        );
    }
    
    /**
     * Search in product title and description
     */
    private function search_in_title_description( string $search_query ): array {
        global $wpdb;
        
        $like = '%' . $wpdb->esc_like( $search_query ) . '%';
        
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT p.ID
                FROM {$wpdb->posts} p
                WHERE p.post_type = 'product'
                AND p.post_status = 'publish'
                AND (
                    p.post_title LIKE %s
                    OR p.post_content LIKE %s
                    OR p.post_excerpt LIKE %s
                )",
                $like,
                $like,
                $like
            )
        );
        
        return array_map( 'intval', $ids );
    }
    
    /**
     * Search in product SKU
     */
    private function search_in_sku( string $search_query ): array {
        global $wpdb;
        
        $like = '%' . $wpdb->esc_like( $search_query ) . '%';
        
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT p.ID
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                WHERE p.post_type = 'product'
                AND p.post_status = 'publish'
                AND pm.meta_key = '_sku'
                AND pm.meta_value LIKE %s",
                $like
            )
        );
        
        return array_map( 'intval', $ids );
    }
    
    /**
     * Calculate relevance score for products
     */
    private function calculate_relevance( array $product_ids, string $search_query ): array {
        global $wpdb;
        
        if ( empty( $product_ids ) ) {
            return array();
        }
        
        $ids_int = array_map( 'intval', $product_ids );
        $ids_string = implode( ',', $ids_int );
        $search_lower = strtolower( $search_query );
        
        $products = $wpdb->get_results(
            "SELECT ID, post_title, post_content, post_excerpt
            FROM {$wpdb->posts}
            WHERE ID IN ($ids_string)
            AND post_type = 'product'"
        );
        
        $skus = $wpdb->get_results(
            "SELECT post_id, meta_value as sku
            FROM {$wpdb->postmeta}
            WHERE post_id IN ($ids_string)
            AND meta_key = '_sku'",
            OBJECT_K
        );
        
        $scores = array();
        
        foreach ( $products as $product ) {
            $product_id = (int) $product->ID;
            $score      = 0;
            
            $title   = strtolower( $product->post_title );
            $content = strtolower( $product->post_content );
            $excerpt = strtolower( $product->post_excerpt );
            $sku     = isset( $skus[ $product_id ] ) ? strtolower( $skus[ $product_id ]->sku ) : '';
            
            if ( $title === $search_lower ) {
                $score += 100;
            } elseif ( strpos( $title, $search_lower ) === 0 ) {
                $score += 80;
            } elseif ( strpos( $title, $search_lower ) !== false ) {
                $score += 60;
            }
            
            if ( $sku === $search_lower ) {
                $score += 90;
            } elseif ( strpos( $sku, $search_lower ) !== false ) {
                $score += 50;
            }
            
            if ( strpos( $content, $search_lower ) !== false ) {
                $score += 20;
            }
            if ( strpos( $excerpt, $search_lower ) !== false ) {
                $score += 15;
            }
            
            $scores[ $product_id ] = $score;
        }
        
        return $scores;
    }
    
    /**
     * Format product for API response
     */
    private function format_product( \WC_Product $product ): array {
        $image_id = $product->get_image_id();
        $image_url = '';
        
        if ( $image_id ) {
            $image_url = wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );
        }
        
        return array(
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
                'url' => $image_url,
                'alt' => $image_id ? get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : '',
            ),
            'categories'     => $this->get_product_categories( $product ),
            'tags'           => $this->get_product_tags( $product ),
        );
    }
    
    /**
     * Get product categories
     */
    private function get_product_categories( \WC_Product $product ): array {
        $categories = array();
        $term_ids   = $product->get_category_ids();
        
        foreach ( $term_ids as $term_id ) {
            $term = get_term( $term_id, 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $categories[] = array(
                    'id'   => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                );
            }
        }
        
        return $categories;
    }
    
    /**
     * Get product tags
     */
    private function get_product_tags( \WC_Product $product ): array {
        $tags     = array();
        $term_ids = $product->get_tag_ids();
        
        foreach ( $term_ids as $term_id ) {
            $term = get_term( $term_id, 'product_tag' );
            if ( $term && ! is_wp_error( $term ) ) {
                $tags[] = array(
                    'id'   => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                );
            }
        }
        
        return $tags;
    }
}
