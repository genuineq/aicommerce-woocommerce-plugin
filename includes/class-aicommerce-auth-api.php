<?php
/**
 * Authentication API Endpoints
 *
 * @package AICommerce
 */

namespace AICommerce;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Auth API Class
 */
class AuthAPI {
    
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
        
        // Login endpoint
        register_rest_route(
            $namespace,
            '/auth/login',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'login' ),
                'permission_callback' => '__return_true',
            )
        );
        
        // Validate token endpoint
        register_rest_route(
            $namespace,
            '/auth/validate',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'validate' ),
                'permission_callback' => '__return_true',
            )
        );
        
        // Refresh token endpoint
        register_rest_route(
            $namespace,
            '/auth/refresh',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'refresh' ),
                'permission_callback' => '__return_true',
            )
        );
        
        // Logout endpoint
        register_rest_route(
            $namespace,
            '/auth/logout',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'logout' ),
                'permission_callback' => '__return_true',
            )
        );
    }
    
    /**
     * Login endpoint
     */
    public function login( \WP_REST_Request $request ): \WP_REST_Response {
        $validation = APIValidator::validate_request( $request );
        if ( ! $validation['valid'] ) {
            return APIValidator::error_response( $validation );
        }
        
        $username = $request->get_param( 'username' );
        $email = $request->get_param( 'email' );
        $password = $request->get_param( 'password' );
        
        // Validate input
        if ( empty( $password ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'missing_password',
                    'message' => __( 'Password is required.', 'aicommerce' ),
                ),
                400
            );
        }
        
        if ( empty( $username ) && empty( $email ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'missing_credentials',
                    'message' => __( 'Username or email is required.', 'aicommerce' ),
                ),
                400
            );
        }
        
        // Rate limiting
        $client_id = RateLimiter::get_client_id();
        $rate_key = 'login_' . $client_id;
        
        if ( ! RateLimiter::is_allowed( $rate_key, 5, 60 ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'rate_limit_exceeded',
                    'message' => __( 'Too many login attempts. Please try again later.', 'aicommerce' ),
                ),
                429
            );
        }
        
        // Authenticate user
        $user = wp_authenticate( $username ?: $email, $password );
        
        if ( is_wp_error( $user ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'authentication_failed',
                    'message' => __( 'Invalid username or password.', 'aicommerce' ),
                ),
                401
            );
        }
        
        // Check if user exists
        if ( ! $user || ! $user->ID ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'user_not_found',
                    'message' => __( 'User not found.', 'aicommerce' ),
                ),
                401
            );
        }
        
        $access_token = JWT::create_token( $user->ID, 'access' );
        $refresh_token = JWT::create_token( $user->ID, 'refresh' );
        
        $this->store_refresh_token( $user->ID, $refresh_token );
        
        RateLimiter::reset( $rate_key );
        
        return new \WP_REST_Response(
            array(
                'success'       => true,
                'token'         => $access_token,
                'refresh_token' => $refresh_token,
                'expires_in'    => 86400, // 24 hours
                'user'          => array(
                    'id'            => $user->ID,
                    'email'         => $user->user_email,
                    'username'      => $user->user_login,
                    'display_name'  => $user->display_name,
                    'first_name'    => $user->first_name,
                    'last_name'     => $user->last_name,
                    'roles'         => $user->roles,
                ),
            ),
            200
        );
    }
    
    /**
     * Validate token endpoint
     */
    public function validate( \WP_REST_Request $request ): \WP_REST_Response {
        $has_auth_header = ! empty( JWT::get_token_from_header() );
        
        if ( ! $has_auth_header ) {
            $validation = APIValidator::validate_request( $request );
            if ( ! $validation['valid'] ) {
                return APIValidator::error_response( $validation );
            }
        }
        
        $token = $request->get_param( 'token' );
        
        if ( empty( $token ) ) {
            $token = JWT::get_token_from_header();
        }
        
        if ( empty( $token ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'valid'   => false,
                    'code'    => 'missing_token',
                    'message' => __( 'Token is required.', 'aicommerce' ),
                ),
                400
            );
        }
        
        // Verify token
        $payload = JWT::verify_token( $token );
        
        if ( ! $payload ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'valid'   => false,
                    'code'    => 'invalid_token',
                    'message' => __( 'Invalid or expired token.', 'aicommerce' ),
                ),
                401
            );
        }
        
        // Get user
        $user = get_user_by( 'id', $payload['user_id'] );
        
        if ( ! $user ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'valid'   => false,
                    'code'    => 'user_not_found',
                    'message' => __( 'User not found.', 'aicommerce' ),
                ),
                401
            );
        }
        
        return new \WP_REST_Response(
            array(
                'success' => true,
                'valid'   => true,
                'user'    => array(
                    'id'            => $user->ID,
                    'email'         => $user->user_email,
                    'username'      => $user->user_login,
                    'display_name'  => $user->display_name,
                    'roles'         => $user->roles,
                ),
            ),
            200
        );
    }
    
    /**
     * Refresh token endpoint
     */
    public function refresh( \WP_REST_Request $request ): \WP_REST_Response {
        $validation = APIValidator::validate_request( $request );
        if ( ! $validation['valid'] ) {
            return APIValidator::error_response( $validation );
        }
        
        $refresh_token = $request->get_param( 'refresh_token' );
        
        if ( empty( $refresh_token ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'missing_refresh_token',
                    'message' => __( 'Refresh token is required.', 'aicommerce' ),
                ),
                400
            );
        }
        
        $payload = JWT::verify_token( $refresh_token );
        
        if ( ! $payload || 'refresh' !== $payload['type'] ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'invalid_refresh_token',
                    'message' => __( 'Invalid or expired refresh token.', 'aicommerce' ),
                ),
                401
            );
        }
        
        if ( ! $this->is_refresh_token_valid( $payload['user_id'], $refresh_token ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'refresh_token_revoked',
                    'message' => __( 'Refresh token has been revoked.', 'aicommerce' ),
                ),
                401
            );
        }
        
        $access_token = JWT::create_token( $payload['user_id'], 'access' );
        
        return new \WP_REST_Response(
            array(
                'success'    => true,
                'token'      => $access_token,
                'expires_in' => 86400,
            ),
            200
        );
    }
    
    /**
     * Logout endpoint
     */
    public function logout( \WP_REST_Request $request ): \WP_REST_Response {
        $validation = APIValidator::validate_request( $request );
        if ( ! $validation['valid'] ) {
            return APIValidator::error_response( $validation );
        }
        
        $refresh_token = $request->get_param( 'refresh_token' );
        
        if ( empty( $refresh_token ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'missing_refresh_token',
                    'message' => __( 'Refresh token is required.', 'aicommerce' ),
                ),
                400
            );
        }
        
        $payload = JWT::verify_token( $refresh_token );
        
        if ( $payload ) {
            $this->remove_refresh_token( $payload['user_id'], $refresh_token );
        }
        
        return new \WP_REST_Response(
            array(
                'success' => true,
                'message' => __( 'Logged out successfully.', 'aicommerce' ),
            ),
            200
        );
    }
    
    /**
     * Store refresh token
     */
    private function store_refresh_token( int $user_id, string $token ): void {
        $tokens = get_user_meta( $user_id, 'aicommerce_refresh_tokens', true );
        
        if ( ! is_array( $tokens ) ) {
            $tokens = array();
        }
        
        $token_hash = hash( 'sha256', $token );
        $tokens[ $token_hash ] = array(
            'created_at' => time(),
            'expires_at' => JWT::get_token_expiration( $token ),
        );
        
        $tokens = array_filter(
            $tokens,
            function( $token_data ) {
                return $token_data['expires_at'] > time();
            }
        );
        
        update_user_meta( $user_id, 'aicommerce_refresh_tokens', $tokens );
    }
    
    /**
     * Check if refresh token is valid
     */
    private function is_refresh_token_valid( int $user_id, string $token ): bool {
        $tokens = get_user_meta( $user_id, 'aicommerce_refresh_tokens', true );
        
        if ( ! is_array( $tokens ) ) {
            return false;
        }
        
        $token_hash = hash( 'sha256', $token );
        
        return isset( $tokens[ $token_hash ] );
    }
    
    /**
     * Remove refresh token
     */
    private function remove_refresh_token( int $user_id, string $token ): void {
        $tokens = get_user_meta( $user_id, 'aicommerce_refresh_tokens', true );
        
        if ( ! is_array( $tokens ) ) {
            return;
        }
        
        $token_hash = hash( 'sha256', $token );
        
        if ( isset( $tokens[ $token_hash ] ) ) {
            unset( $tokens[ $token_hash ] );
            update_user_meta( $user_id, 'aicommerce_refresh_tokens', $tokens );
        }
    }
}
