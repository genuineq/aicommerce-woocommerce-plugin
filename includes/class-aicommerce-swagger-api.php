<?php
/**
 * Swagger/OpenAPI Documentation API
 *
 * @package AICommerce
 */

namespace AICommerce;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Swagger API Class
 * Generates OpenAPI 3.0 specification for AICommerce API
 */
class SwaggerAPI {
    
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
        
        // OpenAPI specification endpoint
        register_rest_route(
            $namespace,
            '/swagger.json',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_openapi_spec' ),
                'permission_callback' => '__return_true',
            )
        );
    }
    
    /**
     * Get OpenAPI 3.0 specification
     */
    public function get_openapi_spec( \WP_REST_Request $request ): \WP_REST_Response {
        $spec = $this->generate_openapi_spec();
        
        return new \WP_REST_Response( $spec, 200 );
    }
    
    /**
     * Generate OpenAPI 3.0 specification
     */
    private function generate_openapi_spec(): array {
        $base_url = home_url( '/wp-json/aicommerce/v1' );
        
        return array(
            'openapi' => '3.0.0',
            'info'    => array(
                'title'       => 'AICommerce API',
                'version'     => '1.0.0',
                'description' => 'REST API for AICommerce platform integration with WooCommerce',
                'contact'     => array(
                    'name' => 'AICommerce Support',
                ),
            ),
            'servers' => array(
                array(
                    'url'         => $base_url,
                    'description' => 'AICommerce API Server',
                ),
            ),
            'tags'    => array(
                array(
                    'name'        => 'Authentication',
                    'description' => 'User authentication and JWT token management endpoints',
                ),
                array(
                    'name'        => 'Products',
                    'description' => 'Product search and management endpoints',
                ),
                array(
                    'name'        => 'Cart',
                    'description' => 'Cart management endpoints for guest users',
                ),
            ),
            'paths'   => array(
                '/auth/login'      => $this->get_login_endpoint(),
                '/auth/validate'   => $this->get_validate_endpoint(),
                '/auth/refresh'    => $this->get_refresh_endpoint(),
                '/auth/logout'     => $this->get_logout_endpoint(),
                '/products/search' => $this->get_search_products_endpoint(),
                '/cart/add'        => $this->get_cart_add_endpoint(),
                '/cart'            => $this->get_cart_get_endpoint(),
                '/cart/remove'     => $this->get_cart_remove_endpoint(),
                '/user'            => $this->get_user_endpoint(),
            ),
            'components' => array(
                'securitySchemes' => array(
                    'apiKey' => array(
                        'type'        => 'apiKey',
                        'in'          => 'header',
                        'name'        => 'X-API-Key',
                        'description' => 'API Key for platform authentication',
                    ),
                    'apiSignature' => array(
                        'type'        => 'apiKey',
                        'in'          => 'header',
                        'name'        => 'X-API-Signature',
                        'description' => 'HMAC-SHA256 signature for request validation',
                    ),
                    'apiTimestamp' => array(
                        'type'        => 'apiKey',
                        'in'          => 'header',
                        'name'        => 'X-Request-Timestamp',
                        'description' => 'Unix timestamp in seconds for request validation',
                    ),
                ),
                'schemas' => array(
                    'Error' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'success' => array(
                                'type'    => 'boolean',
                                'example' => false,
                            ),
                            'code'    => array(
                                'type'        => 'string',
                                'description' => 'Error code',
                            ),
                            'message' => array(
                                'type'        => 'string',
                                'description' => 'Error message',
                            ),
                        ),
                    ),
                    'User' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'id'           => array( 'type' => 'integer' ),
                            'email'        => array( 'type' => 'string', 'format' => 'email' ),
                            'username'     => array( 'type' => 'string' ),
                            'display_name' => array( 'type' => 'string' ),
                            'first_name'   => array( 'type' => 'string' ),
                            'last_name'    => array( 'type' => 'string' ),
                            'roles'        => array(
                                'type'  => 'array',
                                'items' => array( 'type' => 'string' ),
                            ),
                        ),
                    ),
                    'Product' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'id'             => array( 'type' => 'integer' ),
                            'name'           => array( 'type' => 'string' ),
                            'slug'           => array( 'type' => 'string' ),
                            'permalink'      => array( 'type' => 'string', 'format' => 'uri' ),
                            'sku'            => array( 'type' => 'string' ),
                            'type'           => array( 'type' => 'string' ),
                            'status'         => array( 'type' => 'string' ),
                            'price'          => array( 'type' => 'string' ),
                            'regular_price'  => array( 'type' => 'string' ),
                            'sale_price'     => array( 'type' => 'string' ),
                            'on_sale'        => array( 'type' => 'boolean' ),
                            'stock_status'   => array( 'type' => 'string' ),
                            'stock_quantity' => array( 'type' => 'integer', 'nullable' => true ),
                            'manage_stock'   => array( 'type' => 'boolean' ),
                            'image'          => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'id'  => array( 'type' => 'integer', 'nullable' => true ),
                                    'url' => array( 'type' => 'string', 'format' => 'uri' ),
                                    'alt' => array( 'type' => 'string' ),
                                ),
                            ),
                            'categories' => array(
                                'type'  => 'array',
                                'items' => array(
                                    'type'       => 'object',
                                    'properties' => array(
                                        'id'   => array( 'type' => 'integer' ),
                                        'name' => array( 'type' => 'string' ),
                                        'slug' => array( 'type' => 'string' ),
                                    ),
                                ),
                            ),
                            'tags' => array(
                                'type'  => 'array',
                                'items' => array(
                                    'type'       => 'object',
                                    'properties' => array(
                                        'id'   => array( 'type' => 'integer' ),
                                        'name' => array( 'type' => 'string' ),
                                        'slug' => array( 'type' => 'string' ),
                                    ),
                                ),
                            ),
                            'variations' => array(
                                'type'        => 'array',
                                'description' => 'Present only for variable products. Each item has variation_id, attributes, variation_data (for POST /cart/add), price, stock_status, sku.',
                                'items'       => array(
                                    'type'       => 'object',
                                    'properties' => array(
                                        'variation_id'   => array( 'type' => 'integer', 'description' => 'WooCommerce variation product ID' ),
                                        'attributes'    => array(
                                            'type'        => 'object',
                                            'description' => 'Attribute slugs to values (e.g. attribute_pa_color => red)',
                                            'additionalProperties' => array( 'type' => 'string' ),
                                        ),
                                        'variation_data' => array(
                                            'type'        => 'object',
                                            'description' => 'Ready for POST /cart/add variation_data (variation_id + attributes)',
                                            'additionalProperties' => array( 'type' => 'string' ),
                                        ),
                                        'price'          => array( 'type' => 'string' ),
                                        'regular_price'  => array( 'type' => 'string' ),
                                        'sale_price'     => array( 'type' => 'string' ),
                                        'stock_status'   => array( 'type' => 'string' ),
                                        'sku'            => array( 'type' => 'string' ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }
    
    /**
     * Get login endpoint specification
     */
    private function get_login_endpoint(): array {
        return array(
            'post' => array(
                'tags'        => array( 'Authentication' ),
                'summary'     => 'User login',
                'description' => 'Authenticate user and receive JWT access and refresh tokens',
                'security'   => array(
                    array( 'apiKey' => array() ),
                    array( 'apiSignature' => array() ),
                    array( 'apiTimestamp' => array() ),
                ),
                'requestBody' => array(
                    'required' => true,
                    'content'  => array(
                        'application/json' => array(
                            'schema' => array(
                                'type'       => 'object',
                                'required'   => array( 'password' ),
                                'properties' => array(
                                    'username' => array(
                                        'type'        => 'string',
                                        'example'     => 'test',
                                        'description' => 'Username for login (optional if email provided)',
                                    ),
                                    'email'    => array(
                                        'type'        => 'string',
                                        'example'     => 'test@test.com',
                                        'format'      => 'email',
                                        'description' => 'Email for login (optional if username provided)',
                                    ),
                                    'password' => array(
                                        'type'        => 'string',
                                        'example'     => 'twgBY13yxTy5ylo^(GMmsDgI',
                                        'format'      => 'password',
                                        'description' => 'User password',
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
                'responses' => array(
                    '200' => array(
                        'description' => 'Login successful',
                        'content'     => array(
                            'application/json' => array(
                                'schema' => array(
                                    'type'       => 'object',
                                    'properties' => array(
                                        'success'       => array( 'type' => 'boolean', 'example' => true ),
                                        'token'         => array( 'type' => 'string', 'description' => 'JWT access token' ),
                                        'refresh_token' => array( 'type' => 'string', 'description' => 'JWT refresh token' ),
                                        'expires_in'    => array( 'type' => 'integer', 'example' => 86400 ),
                                        'user'          => array( '$ref' => '#/components/schemas/User' ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                    '400' => array(
                        'description' => 'Invalid request',
                        'content'    => array(
                            'application/json' => array(
                                'schema' => array( '$ref' => '#/components/schemas/Error' ),
                                'example' => array(
                                    'success' => false,
                                    'code'    => 'missing_password',
                                    'message' => 'Password is required.',
                                ),
                            ),
                        ),
                    ),
                    '401' => array(
                        'description' => 'Authentication failed',
                        'content'    => array(
                            'application/json' => array(
                                'schema' => array( '$ref' => '#/components/schemas/Error' ),
                            ),
                        ),
                    ),
                    '429' => array(
                        'description' => 'Rate limit exceeded',
                        'content'    => array(
                            'application/json' => array(
                                'schema' => array( '$ref' => '#/components/schemas/Error' ),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }
    
    /**
     * Get validate endpoint specification
     */
    private function get_validate_endpoint(): array {
        return array(
            'post' => array(
                'tags'        => array( 'Authentication' ),
                'summary'     => 'Validate JWT token',
                'description' => 'Validate JWT access token and return user information',
                'security'   => array(
                    array( 'apiKey' => array() ),
                    array( 'apiSignature' => array() ),
                    array( 'apiTimestamp' => array() ),
                ),
                'requestBody' => array(
                    'required' => false,
                    'content'  => array(
                        'application/json' => array(
                            'schema' => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'token' => array(
                                        'type'        => 'string',
                                        'description' => 'JWT token (optional if provided in Authorization header)',
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
                'responses' => array(
                    '200' => array(
                        'description' => 'Token is valid',
                        'content'     => array(
                            'application/json' => array(
                                'schema' => array(
                                    'type'       => 'object',
                                    'properties' => array(
                                        'success' => array( 'type' => 'boolean', 'example' => true ),
                                        'valid'   => array( 'type' => 'boolean', 'example' => true ),
                                        'user'    => array( '$ref' => '#/components/schemas/User' ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                    '400' => array(
                        'description' => 'Missing token',
                        'content'    => array(
                            'application/json' => array(
                                'schema' => array( '$ref' => '#/components/schemas/Error' ),
                            ),
                        ),
                    ),
                    '401' => array(
                        'description' => 'Invalid or expired token',
                        'content'    => array(
                            'application/json' => array(
                                'schema' => array( '$ref' => '#/components/schemas/Error' ),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }
    
    /**
     * Get refresh endpoint specification
     */
    private function get_refresh_endpoint(): array {
        return array(
            'post' => array(
                'tags'        => array( 'Authentication' ),
                'summary'     => 'Refresh access token',
                'description' => 'Get a new access token using refresh token',
                'security'   => array(
                    array( 'apiKey' => array() ),
                    array( 'apiSignature' => array() ),
                    array( 'apiTimestamp' => array() ),
                ),
                'requestBody' => array(
                    'required' => true,
                    'content'  => array(
                        'application/json' => array(
                            'schema' => array(
                                'type'       => 'object',
                                'required'   => array( 'refresh_token' ),
                                'properties' => array(
                                    'refresh_token' => array(
                                        'type'        => 'string',
                                        'description' => 'JWT refresh token',
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
                'responses' => array(
                    '200' => array(
                        'description' => 'Token refreshed successfully',
                        'content'     => array(
                            'application/json' => array(
                                'schema' => array(
                                    'type'       => 'object',
                                    'properties' => array(
                                        'success'    => array( 'type' => 'boolean', 'example' => true ),
                                        'token'      => array( 'type' => 'string', 'description' => 'New JWT access token' ),
                                        'expires_in' => array( 'type' => 'integer', 'example' => 86400 ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                    '400' => array(
                        'description' => 'Missing refresh token',
                        'content'    => array(
                            'application/json' => array(
                                'schema' => array( '$ref' => '#/components/schemas/Error' ),
                            ),
                        ),
                    ),
                    '401' => array(
                        'description' => 'Invalid or expired refresh token',
                        'content'    => array(
                            'application/json' => array(
                                'schema' => array( '$ref' => '#/components/schemas/Error' ),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }
    
    /**
     * Get logout endpoint specification
     */
    private function get_logout_endpoint(): array {
        return array(
            'post' => array(
                'tags'        => array( 'Authentication' ),
                'summary'     => 'User logout',
                'description' => 'Revoke refresh token and logout user',
                'security'   => array(
                    array( 'apiKey' => array() ),
                    array( 'apiSignature' => array() ),
                    array( 'apiTimestamp' => array() ),
                ),
                'requestBody' => array(
                    'required' => true,
                    'content'  => array(
                        'application/json' => array(
                            'schema' => array(
                                'type'       => 'object',
                                'required'   => array( 'refresh_token' ),
                                'properties' => array(
                                    'refresh_token' => array(
                                        'type'        => 'string',
                                        'description' => 'JWT refresh token to revoke',
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
                'responses' => array(
                    '200' => array(
                        'description' => 'Logout successful',
                        'content'     => array(
                            'application/json' => array(
                                'schema' => array(
                                    'type'       => 'object',
                                    'properties' => array(
                                        'success' => array( 'type' => 'boolean', 'example' => true ),
                                        'message' => array( 'type' => 'string', 'example' => 'Logged out successfully.' ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                    '400' => array(
                        'description' => 'Missing refresh token',
                        'content'    => array(
                            'application/json' => array(
                                'schema' => array( '$ref' => '#/components/schemas/Error' ),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }
    
    /**
     * Get search products endpoint specification
     */
    private function get_search_products_endpoint(): array {
        return array(
            'get' => array(
                'tags'        => array( 'Products' ),
                'summary'     => 'Search products',
                'description' => 'Search products by name, description, or SKU with pagination and relevance sorting. If q is omitted, returns products ordered by date (newest first).',
                'security'   => array(
                    array( 'apiKey' => array() ),
                    array( 'apiSignature' => array() ),
                    array( 'apiTimestamp' => array() ),
                ),
                'parameters' => array(
                    array(
                        'name'        => 'q',
                        'in'          => 'query',
                        'required'    => false,
                        'description' => 'Search query. If omitted, products are returned by date (newest first).',
                        'schema'      => array(
                            'type' => 'string',
                        ),
                    ),
                    array(
                        'name'        => 'per_page',
                        'in'          => 'query',
                        'required'    => false,
                        'description' => 'Number of products per page',
                        'schema'      => array(
                            'type'    => 'integer',
                            'default' => 20,
                            'minimum' => 1,
                            'maximum' => 100,
                        ),
                    ),
                    array(
                        'name'        => 'page',
                        'in'          => 'query',
                        'required'    => false,
                        'description' => 'Page number',
                        'schema'      => array(
                            'type'    => 'integer',
                            'default' => 1,
                            'minimum' => 1,
                        ),
                    ),
                ),
                'responses' => array(
                    '200' => array(
                        'description' => 'Search results',
                        'content'     => array(
                            'application/json' => array(
                                'schema' => array(
                                    'type'       => 'object',
                                    'properties' => array(
                                        'success'      => array( 'type' => 'boolean', 'example' => true ),
                                        'products'     => array(
                                            'type'  => 'array',
                                            'items' => array( '$ref' => '#/components/schemas/Product' ),
                                        ),
                                        'total'        => array( 'type' => 'integer', 'example' => 45 ),
                                        'per_page'     => array( 'type' => 'integer', 'example' => 20 ),
                                        'current_page' => array( 'type' => 'integer', 'example' => 1 ),
                                        'pages'        => array( 'type' => 'integer', 'example' => 3 ),
                                        'query'        => array( 'type' => 'string', 'example' => 'laptop' ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                    '400' => array(
                        'description' => 'Bad request',
                        'content'    => array(
                            'application/json' => array(
                                'schema' => array( '$ref' => '#/components/schemas/Error' ),
                            ),
                        ),
                    ),
                    '401' => array(
                        'description' => 'Invalid signature',
                        'content'    => array(
                            'application/json' => array(
                                'schema' => array( '$ref' => '#/components/schemas/Error' ),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }
    
    /**
     * Get add to cart endpoint specification
     */
    private function get_cart_add_endpoint(): array {
        return array(
            'post' => array(
                'tags'        => array( 'Cart' ),
                'summary'     => 'Add item to cart',
                'description' => 'Add a product to cart by guest_token or user_id. Provide either guest_token or user_id, not both.',
                'security'   => array(
                    array( 'apiKey' => array() ),
                    array( 'apiSignature' => array() ),
                    array( 'apiTimestamp' => array() ),
                ),
                'requestBody' => array(
                    'required' => true,
                    'content'  => array(
                        'application/json' => array(
                            'schema' => array(
                                'type'       => 'object',
                                'required'   => array( 'product_id' ),
                                'properties' => array(
                                    'guest_token'    => array(
                                        'type'        => 'string',
                                        'description' => 'Guest token for cart identification (required if user_id not provided)',
                                        'example'     => 'guest_1700000000_abc123xyz_1a2b3c4d',
                                    ),
                                    'user_id'        => array(
                                        'type'        => 'integer',
                                        'description' => 'User ID for cart identification (required if guest_token not provided)',
                                        'example'     => 42,
                                    ),
                                    'product_id'    => array(
                                        'type'        => 'integer',
                                        'description' => 'Product ID to add to cart',
                                        'example'     => 123,
                                    ),
                                    'quantity'      => array(
                                        'type'        => 'integer',
                                        'description' => 'Quantity to add (default: 1)',
                                        'default'     => 1,
                                        'minimum'     => 1,
                                        'example'     => 1,
                                    ),
                                    'variation_data' => array(
                                        'type'        => 'object',
                                        'description' => 'For variable products: pass variation_id (required) and optionally attribute key-value pairs (e.g. attribute_pa_color, attribute_pa_size). For simple products omit or send {}.',
                                        'example'     => array(
                                            'variation_id' => 12345,
                                            'attribute_pa_color' => 'red',
                                            'attribute_pa_size'  => 'large',
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
                'responses' => array(
                    '200' => array(
                        'description' => 'Item added successfully',
                        'content'     => array(
                            'application/json' => array(
                                'schema' => array(
                                    'type'       => 'object',
                                    'properties' => array(
                                        'success'    => array( 'type' => 'boolean', 'example' => true ),
                                        'message'    => array( 'type' => 'string', 'example' => 'Item added to cart successfully.' ),
                                        'cart_count' => array( 'type' => 'integer', 'example' => 3 ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                    '400' => array(
                        'description' => 'Invalid request',
                        'content'    => array(
                            'application/json' => array(
                                'schema' => array( '$ref' => '#/components/schemas/Error' ),
                                'examples' => array(
                                    'missing_identifier' => array(
                                        'value' => array(
                                            'success' => false,
                                            'code'    => 'missing_identifier',
                                            'message' => 'Either guest_token or user_id is required.',
                                        ),
                                    ),
                                    'conflicting_identifiers' => array(
                                        'value' => array(
                                            'success' => false,
                                            'code'    => 'conflicting_identifiers',
                                            'message' => 'Provide either guest_token or user_id, not both.',
                                        ),
                                    ),
                                    'invalid_user_id' => array(
                                        'value' => array(
                                            'success' => false,
                                            'code'    => 'invalid_user_id',
                                            'message' => 'Invalid user ID.',
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                    '404' => array(
                        'description' => 'Product not found',
                        'content'    => array(
                            'application/json' => array(
                                'schema' => array( '$ref' => '#/components/schemas/Error' ),
                            ),
                        ),
                    ),
                    '500' => array(
                        'description' => 'Failed to add item',
                        'content'    => array(
                            'application/json' => array(
                                'schema' => array( '$ref' => '#/components/schemas/Error' ),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }
    
    /**
     * Get cart endpoint specification
     */
    private function get_cart_get_endpoint(): array {
        return array(
            'get' => array(
                'tags'        => array( 'Cart' ),
                'summary'     => 'Get cart',
                'description' => 'Get cart by guest_token or user_id. Provide either guest_token or user_id, not both.',
                'security'   => array(
                    array( 'apiKey' => array() ),
                    array( 'apiSignature' => array() ),
                    array( 'apiTimestamp' => array() ),
                ),
                'parameters' => array(
                    array(
                        'name'        => 'guest_token',
                        'in'          => 'query',
                        'required'    => false,
                        'description' => 'Guest token for cart identification (required if user_id not provided)',
                        'schema'      => array(
                            'type'    => 'string',
                            'example' => 'guest_1700000000_abc123xyz_1a2b3c4d',
                        ),
                    ),
                    array(
                        'name'        => 'user_id',
                        'in'          => 'query',
                        'required'    => false,
                        'description' => 'User ID for cart identification (required if guest_token not provided)',
                        'schema'      => array(
                            'type'    => 'integer',
                            'example' => 42,
                        ),
                    ),
                ),
                'responses' => array(
                    '200' => array(
                        'description' => 'Cart retrieved successfully',
                        'content'     => array(
                            'application/json' => array(
                                'schema' => array(
                                    'type'       => 'object',
                                    'properties' => array(
                                        'success'    => array( 'type' => 'boolean', 'example' => true ),
                                        'cart'       => array(
                                            'type'  => 'array',
                                            'items' => array(
                                                'type'       => 'object',
                                                'properties' => array(
                                                    'key'            => array( 'type' => 'string', 'example' => 'simple_4142' ),
                                                    'product_id'     => array( 'type' => 'integer', 'example' => 4142 ),
                                                    'quantity'       => array( 'type' => 'integer', 'example' => 2 ),
                                                    'variation_data' => array( 'type' => 'object' ),
                                                    'added_at'       => array( 'type' => 'integer', 'example' => 1772010101 ),
                                                    'product_details' => array(
                                                        'type'       => 'object',
                                                        'properties' => array(
                                                            'name'  => array( 'type' => 'string', 'example' => 'Product Name' ),
                                                            'sku'   => array( 'type' => 'string', 'example' => 'SKU-001' ),
                                                            'image' => array( 'type' => 'string', 'format' => 'uri', 'example' => 'https://example.com/image.jpg' ),
                                                            'url'   => array( 'type' => 'string', 'format' => 'uri', 'example' => 'https://example.com/product/slug/' ),
                                                        ),
                                                    ),
                                                ),
                                            ),
                                        ),
                                        'cart_count' => array( 'type' => 'integer', 'example' => 3 ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                    '400' => array(
                        'description' => 'Invalid request',
                        'content'    => array(
                            'application/json' => array(
                                'schema' => array( '$ref' => '#/components/schemas/Error' ),
                                'examples' => array(
                                    'missing_identifier' => array(
                                        'value' => array(
                                            'success' => false,
                                            'code'    => 'missing_identifier',
                                            'message' => 'Either guest_token or user_id is required.',
                                        ),
                                    ),
                                    'invalid_user_id' => array(
                                        'value' => array(
                                            'success' => false,
                                            'code'    => 'invalid_user_id',
                                            'message' => 'Invalid user ID.',
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }
    
    /**
     * Get cart sync endpoint specification
     */
    private function get_cart_sync_endpoint(): array {
        return array(
            'post' => array(
                'tags'        => array( 'Cart' ),
                'summary'     => 'Sync cart to WooCommerce session',
                'description' => 'Sync guest cart stored by guest_token to current WooCommerce session (called from frontend)',
                'requestBody' => array(
                    'required' => true,
                    'content'  => array(
                        'application/json' => array(
                            'schema' => array(
                                'type'       => 'object',
                                'required'   => array( 'guest_token' ),
                                'properties' => array(
                                    'guest_token' => array(
                                        'type'        => 'string',
                                        'description' => 'Guest token for cart identification',
                                        'example'     => 'guest_1700000000_abc123xyz_1a2b3c4d',
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
                'responses' => array(
                    '200' => array(
                        'description' => 'Cart synced successfully',
                        'content'     => array(
                            'application/json' => array(
                                'schema' => array(
                                    'type'       => 'object',
                                    'properties' => array(
                                        'success'      => array( 'type' => 'boolean', 'example' => true ),
                                        'message'      => array( 'type' => 'string', 'example' => 'Synced 3 items to cart.' ),
                                        'synced_count' => array( 'type' => 'integer', 'example' => 3 ),
                                        'total_items'  => array( 'type' => 'integer', 'example' => 3 ),
                                        'errors'       => array(
                                            'type'  => 'array',
                                            'items' => array( 'type' => 'string' ),
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                    '400' => array(
                        'description' => 'Missing guest token',
                        'content'    => array(
                            'application/json' => array(
                                'schema' => array( '$ref' => '#/components/schemas/Error' ),
                            ),
                        ),
                    ),
                    '500' => array(
                        'description' => 'WooCommerce not available',
                        'content'    => array(
                            'application/json' => array(
                                'schema' => array( '$ref' => '#/components/schemas/Error' ),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }
    
    /**
     * Get remove from cart endpoint specification
     */
    private function get_cart_remove_endpoint(): array {
        return array(
            'post' => array(
                'tags'        => array( 'Cart' ),
                'summary'     => 'Remove item from cart',
                'description' => 'Remove a product from cart by guest_token or user_id. Provide either guest_token or user_id, not both. For variable products include variation_data with variation_id.',
                'security'    => array(
                    array( 'apiKey' => array() ),
                    array( 'apiSignature' => array() ),
                    array( 'apiTimestamp' => array() ),
                ),
                'requestBody' => array(
                    'required' => true,
                    'content'  => array(
                        'application/json' => array(
                            'schema' => array(
                                'type'       => 'object',
                                'required'   => array( 'product_id' ),
                                'properties' => array(
                                    'guest_token'    => array(
                                        'type'        => 'string',
                                        'description' => 'Guest token for cart identification (required if user_id not provided)',
                                        'example'     => 'guest_1700000000_abc123xyz_1a2b3c4d',
                                    ),
                                    'user_id'        => array(
                                        'type'        => 'integer',
                                        'description' => 'User ID for cart identification (required if guest_token not provided)',
                                        'example'     => 1,
                                    ),
                                    'product_id'     => array(
                                        'type'        => 'integer',
                                        'description' => 'Product ID to remove from cart',
                                        'example'     => 123,
                                    ),
                                    'variation_data' => array(
                                        'type'        => 'object',
                                        'description' => 'For variable products: must include variation_id to identify the line item',
                                        'example'     => array( 'variation_id' => 456 ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
                'responses' => array(
                    '200' => array(
                        'description' => 'Item removed successfully',
                        'content'     => array(
                            'application/json' => array(
                                'schema' => array(
                                    'type'       => 'object',
                                    'properties' => array(
                                        'success'    => array( 'type' => 'boolean', 'example' => true ),
                                        'message'    => array( 'type' => 'string', 'example' => 'Item removed from cart.' ),
                                        'cart_count' => array( 'type' => 'integer', 'example' => 2 ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                    '400' => array(
                        'description' => 'Missing identifier, invalid product_id, or conflicting parameters',
                        'content'    => array(
                            'application/json' => array(
                                'schema' => array( '$ref' => '#/components/schemas/Error' ),
                            ),
                        ),
                    ),
                    '500' => array(
                        'description' => 'Remove failed',
                        'content'    => array(
                            'application/json' => array(
                                'schema' => array( '$ref' => '#/components/schemas/Error' ),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * Get user endpoint specification
     */
    private function get_user_endpoint(): array {
        return array(
            'get' => array(
                'tags'        => array( 'Users' ),
                'summary'     => 'Get user information',
                'description' => 'Returns full user profile including WooCommerce billing/shipping addresses and order statistics.',
                'security'    => array(
                    array( 'apiKey' => array() ),
                    array( 'apiSignature' => array() ),
                    array( 'apiTimestamp' => array() ),
                ),
                'parameters' => array(
                    array(
                        'name'        => 'user_id',
                        'in'          => 'query',
                        'required'    => true,
                        'description' => 'WordPress user ID',
                        'schema'      => array(
                            'type'    => 'integer',
                            'example' => 1,
                        ),
                    ),
                ),
                'responses' => array(
                    '200' => array(
                        'description' => 'User retrieved successfully',
                        'content'     => array(
                            'application/json' => array(
                                'schema' => array(
                                    'type'       => 'object',
                                    'properties' => array(
                                        'success' => array( 'type' => 'boolean', 'example' => true ),
                                        'user'    => array(
                                            'type'       => 'object',
                                            'properties' => array(
                                                'id'            => array( 'type' => 'integer', 'example' => 1 ),
                                                'username'      => array( 'type' => 'string', 'example' => 'john' ),
                                                'email'         => array( 'type' => 'string', 'format' => 'email', 'example' => 'john@example.com' ),
                                                'display_name'  => array( 'type' => 'string', 'example' => 'John Doe' ),
                                                'first_name'    => array( 'type' => 'string', 'example' => 'John' ),
                                                'last_name'     => array( 'type' => 'string', 'example' => 'Doe' ),
                                                'roles'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'example' => array( 'customer' ) ),
                                                'registered_at' => array( 'type' => 'string', 'example' => '2024-01-15 10:30:00' ),
                                                'avatar_url'    => array( 'type' => 'string', 'format' => 'uri' ),
                                                'billing'       => array(
                                                    'type'       => 'object',
                                                    'properties' => array(
                                                        'first_name' => array( 'type' => 'string' ),
                                                        'last_name'  => array( 'type' => 'string' ),
                                                        'company'    => array( 'type' => 'string' ),
                                                        'address_1'  => array( 'type' => 'string' ),
                                                        'address_2'  => array( 'type' => 'string' ),
                                                        'city'       => array( 'type' => 'string' ),
                                                        'state'      => array( 'type' => 'string' ),
                                                        'postcode'   => array( 'type' => 'string' ),
                                                        'country'    => array( 'type' => 'string', 'example' => 'US' ),
                                                        'email'      => array( 'type' => 'string', 'format' => 'email' ),
                                                        'phone'      => array( 'type' => 'string' ),
                                                    ),
                                                ),
                                                'shipping' => array(
                                                    'type'       => 'object',
                                                    'properties' => array(
                                                        'first_name' => array( 'type' => 'string' ),
                                                        'last_name'  => array( 'type' => 'string' ),
                                                        'company'    => array( 'type' => 'string' ),
                                                        'address_1'  => array( 'type' => 'string' ),
                                                        'address_2'  => array( 'type' => 'string' ),
                                                        'city'       => array( 'type' => 'string' ),
                                                        'state'      => array( 'type' => 'string' ),
                                                        'postcode'   => array( 'type' => 'string' ),
                                                        'country'    => array( 'type' => 'string', 'example' => 'US' ),
                                                        'phone'      => array( 'type' => 'string' ),
                                                    ),
                                                ),
                                                'orders' => array(
                                                    'type'       => 'object',
                                                    'properties' => array(
                                                        'total_orders'        => array( 'type' => 'integer', 'example' => 5 ),
                                                        'total_spent'         => array( 'type' => 'number', 'format' => 'float', 'example' => 349.95 ),
                                                        'average_order_value' => array( 'type' => 'number', 'format' => 'float', 'example' => 69.99 ),
                                                        'currency'            => array( 'type' => 'string', 'example' => 'USD' ),
                                                        'last_order_id'       => array( 'type' => 'integer', 'example' => 1042, 'nullable' => true ),
                                                        'last_order_date'     => array( 'type' => 'string', 'example' => '2026-02-20 14:22:10', 'nullable' => true ),
                                                    ),
                                                ),
                                            ),
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                    '400' => array(
                        'description' => 'Missing or invalid user_id',
                        'content'     => array(
                            'application/json' => array(
                                'schema' => array( '$ref' => '#/components/schemas/Error' ),
                            ),
                        ),
                    ),
                    '404' => array(
                        'description' => 'User not found',
                        'content'     => array(
                            'application/json' => array(
                                'schema' => array( '$ref' => '#/components/schemas/Error' ),
                                'example' => array(
                                    'success' => false,
                                    'code'    => 'user_not_found',
                                    'message' => 'User not found.',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }
}
