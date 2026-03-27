<?php
/**
 * Authentication API Endpoints
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Auth API class. */
class AuthAPI {

    /**
     * Constructor.
     */
    public function __construct() {
        /** Register REST API routes during REST API initialization. */
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public function register_routes(): void {
        /** Define the REST API namespace for the plugin endpoints. */
        $namespace = 'aicommerce/v1';

        /** Register the login endpoint. */
        register_rest_route(
            $namespace,
            '/auth/login',
            array(
                /** Allow POST requests for the login endpoint. */
                'methods'             => 'POST',

                /** Set the callback that handles login requests. */
                'callback'            => array( $this, 'login' ),

                /** Allow public access and handle validation inside the callback. */
                'permission_callback' => '__return_true',
            )
        );

        /** Register the token validation endpoint. */
        register_rest_route(
            $namespace,
            '/auth/validate',
            array(
                /** Allow POST requests for the validation endpoint. */
                'methods'             => 'POST',

                /** Set the callback that handles token validation requests. */
                'callback'            => array( $this, 'validate' ),

                /** Allow public access and handle validation inside the callback. */
                'permission_callback' => '__return_true',
            )
        );

        /** Register the refresh token endpoint. */
        register_rest_route(
            $namespace,
            '/auth/refresh',
            array(
                /** Allow POST requests for the refresh endpoint. */
                'methods'             => 'POST',

                /** Set the callback that handles refresh token requests. */
                'callback'            => array( $this, 'refresh' ),

                /** Allow public access and handle validation inside the callback. */
                'permission_callback' => '__return_true',
            )
        );

        /** Register the logout endpoint. */
        register_rest_route(
            $namespace,
            '/auth/logout',
            array(
                /** Allow POST requests for the logout endpoint. */
                'methods'             => 'POST',

                /** Set the callback that handles logout requests. */
                'callback'            => array( $this, 'logout' ),

                /** Allow public access and handle validation inside the callback. */
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Handle login requests.
     *
     * @param \WP_REST_Request $request The incoming REST request.
     * @return \WP_REST_Response
     */
    public function login( \WP_REST_Request $request ): \WP_REST_Response {
        /** Validate the API request headers and signature. */
        $validation = APIValidator::validate_request( $request );

        /** Return a validation error response if the request is invalid. */
        if ( ! $validation['valid'] ) {
            return APIValidator::error_response( $validation );
        }

        /** Get the submitted username parameter. */
        $username = $request->get_param( 'username' );

        /** Get the submitted email parameter. */
        $email = $request->get_param( 'email' );

        /** Get the submitted password parameter. */
        $password = $request->get_param( 'password' );

        /** Return an error if the password is missing. */
        if ( empty( $password ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'missing_password',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Password is required.', 'aicommerce' ),
                ),
                400
            );
        }

        /** Return an error if both username and email are missing. */
        if ( empty( $username ) && empty( $email ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'missing_credentials',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Username or email is required.', 'aicommerce' ),
                ),
                400
            );
        }

        /** Get the client identifier used for rate limiting. */
        $client_id = RateLimiter::get_client_id();

        /** Build a unique rate limit key for login attempts from this client. */
        $rate_key = 'login_' . $client_id;

        /** Return an error if the rate limit for login attempts has been exceeded. */
        if ( ! RateLimiter::is_allowed( $rate_key, 5, 60 ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'rate_limit_exceeded',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Too many login attempts. Please try again later.', 'aicommerce' ),
                ),
                429
            );
        }

        /** Authenticate the user using the provided username or email and password. */
        $user = wp_authenticate( $username ?: $email, $password );

        /** Return an error if authentication failed. */
        if ( is_wp_error( $user ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'authentication_failed',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Invalid username or password.', 'aicommerce' ),
                ),
                401
            );
        }

        /** Return an error if the user object is missing or invalid. */
        if ( ! $user || ! $user->ID ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'user_not_found',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'User not found.', 'aicommerce' ),
                ),
                401
            );
        }

        /** Create a new access token for the authenticated user. */
        $access_token = JWT::create_token( $user->ID, 'access' );

        /** Create a new refresh token for the authenticated user. */
        $refresh_token = JWT::create_token( $user->ID, 'refresh' );

        /** Store the refresh token for future validation and revocation. */
        $this->store_refresh_token( $user->ID, $refresh_token );

        /** Reset the rate limiter after a successful login. */
        RateLimiter::reset( $rate_key );

        /** Return a successful login response with token data and user information. */
        return new \WP_REST_Response(
            array(
                /** Indicate that the request succeeded. */
                'success'       => true,

                /** Include the access token. */
                'token'         => $access_token,

                /** Include the refresh token. */
                'refresh_token' => $refresh_token,

                /** Define the access token lifetime in seconds. */
                'expires_in'    => 86400,

                /** Include the authenticated user data. */
                'user'          => array(
                    /** Include the user ID. */
                    'id'            => $user->ID,

                    /** Include the user email address. */
                    'email'         => $user->user_email,

                    /** Include the user login name. */
                    'username'      => $user->user_login,

                    /** Include the display name. */
                    'display_name'  => $user->display_name,

                    /** Include the first name. */
                    'first_name'    => $user->first_name,

                    /** Include the last name. */
                    'last_name'     => $user->last_name,

                    /** Include the user roles. */
                    'roles'         => $user->roles,
                ),
            ),
            200
        );
    }

    /**
     * Validate an access token.
     *
     * @param \WP_REST_Request $request The incoming REST request.
     * @return \WP_REST_Response
     */
    public function validate( \WP_REST_Request $request ): \WP_REST_Response {
        /** Determine whether an authorization token is available in the request header. */
        $has_auth_header = ! empty( JWT::get_token_from_header() );

        /** Validate the API request only when no authorization header token is present. */
        if ( ! $has_auth_header ) {
            /** Validate the API request headers and signature. */
            $validation = APIValidator::validate_request( $request );

            /** Return a validation error response if the request is invalid. */
            if ( ! $validation['valid'] ) {
                return APIValidator::error_response( $validation );
            }
        }

        /** Get the token from the request parameters. */
        $token = $request->get_param( 'token' );

        /** Fallback to the authorization header token if no token parameter was provided. */
        if ( empty( $token ) ) {
            $token = JWT::get_token_from_header();
        }

        /** Return an error if no token was provided anywhere in the request. */
        if ( empty( $token ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Indicate that the token is not valid. */
                    'valid'   => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'missing_token',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Token is required.', 'aicommerce' ),
                ),
                400
            );
        }

        /** Verify and decode the token payload. */
        $payload = JWT::verify_token( $token );

        /** Return an error if the token is invalid or expired. */
        if ( ! $payload ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Indicate that the token is not valid. */
                    'valid'   => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'invalid_token',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Invalid or expired token.', 'aicommerce' ),
                ),
                401
            );
        }

        /** Load the user associated with the token payload. */
        $user = get_user_by( 'id', $payload['user_id'] );

        /** Return an error if the user could not be found. */
        if ( ! $user ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Indicate that the token is not valid in practice because the user does not exist. */
                    'valid'   => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'user_not_found',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'User not found.', 'aicommerce' ),
                ),
                401
            );
        }

        /** Return a successful token validation response with user details. */
        return new \WP_REST_Response(
            array(
                /** Indicate that the request succeeded. */
                'success' => true,

                /** Indicate that the token is valid. */
                'valid'   => true,

                /** Include the user information. */
                'user'    => array(
                    /** Include the user ID. */
                    'id'            => $user->ID,

                    /** Include the user email address. */
                    'email'         => $user->user_email,

                    /** Include the user login name. */
                    'username'      => $user->user_login,

                    /** Include the display name. */
                    'display_name'  => $user->display_name,

                    /** Include the user roles. */
                    'roles'         => $user->roles,
                ),
            ),
            200
        );
    }

    /**
     * Refresh an access token using a refresh token.
     *
     * @param \WP_REST_Request $request The incoming REST request.
     * @return \WP_REST_Response
     */
    public function refresh( \WP_REST_Request $request ): \WP_REST_Response {
        /** Validate the API request headers and signature. */
        $validation = APIValidator::validate_request( $request );

        /** Return a validation error response if the request is invalid. */
        if ( ! $validation['valid'] ) {
            return APIValidator::error_response( $validation );
        }

        /** Get the refresh token from the request parameters. */
        $refresh_token = $request->get_param( 'refresh_token' );

        /** Return an error if the refresh token is missing. */
        if ( empty( $refresh_token ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'missing_refresh_token',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Refresh token is required.', 'aicommerce' ),
                ),
                400
            );
        }

        /** Verify and decode the refresh token payload. */
        $payload = JWT::verify_token( $refresh_token );

        /** Return an error if the refresh token is invalid, expired, or not a refresh token. */
        if ( ! $payload || 'refresh' !== $payload['type'] ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'invalid_refresh_token',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Invalid or expired refresh token.', 'aicommerce' ),
                ),
                401
            );
        }

        /** Return an error if the refresh token has been revoked or is no longer stored. */
        if ( ! $this->is_refresh_token_valid( $payload['user_id'], $refresh_token ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'refresh_token_revoked',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Refresh token has been revoked.', 'aicommerce' ),
                ),
                401
            );
        }

        /** Create a new access token for the refresh token owner. */
        $access_token = JWT::create_token( $payload['user_id'], 'access' );

        /** Return a successful refresh response with the new access token. */
        return new \WP_REST_Response(
            array(
                /** Indicate that the request succeeded. */
                'success'    => true,

                /** Include the new access token. */
                'token'      => $access_token,

                /** Define the access token lifetime in seconds. */
                'expires_in' => 86400,
            ),
            200
        );
    }

    /**
     * Handle logout requests.
     *
     * @param \WP_REST_Request $request The incoming REST request.
     * @return \WP_REST_Response
     */
    public function logout( \WP_REST_Request $request ): \WP_REST_Response {
        /** Validate the API request headers and signature. */
        $validation = APIValidator::validate_request( $request );

        /** Return a validation error response if the request is invalid. */
        if ( ! $validation['valid'] ) {
            return APIValidator::error_response( $validation );
        }

        /** Get the refresh token from the request parameters. */
        $refresh_token = $request->get_param( 'refresh_token' );

        /** Return an error if the refresh token is missing. */
        if ( empty( $refresh_token ) ) {
            return new \WP_REST_Response(
                array(
                    /** Indicate that the request failed. */
                    'success' => false,

                    /** Define the machine-readable error code. */
                    'code'    => 'missing_refresh_token',

                    /** Define the translated human-readable error message. */
                    'message' => __( 'Refresh token is required.', 'aicommerce' ),
                ),
                400
            );
        }

        /** Verify and decode the refresh token payload. */
        $payload = JWT::verify_token( $refresh_token );

        /** Remove the refresh token from storage if the token payload is valid. */
        if ( $payload ) {
            $this->remove_refresh_token( $payload['user_id'], $refresh_token );
        }

        /** Return a successful logout response. */
        return new \WP_REST_Response(
            array(
                /** Indicate that the request succeeded. */
                'success' => true,

                /** Define the translated success message. */
                'message' => __( 'Logged out successfully.', 'aicommerce' ),
            ),
            200
        );
    }

    /**
     * Store a refresh token for a user.
     *
     * @param int    $user_id The user ID.
     * @param string $token The refresh token.
     * @return void
     */
    private function store_refresh_token( int $user_id, string $token ): void {
        /** Load the stored refresh tokens for the user. */
        $tokens = get_user_meta( $user_id, 'aicommerce_refresh_tokens', true );

        /** Initialize the token store as an empty array when no valid token array exists. */
        if ( ! is_array( $tokens ) ) {
            $tokens = array();
        }

        /** Generate a hash of the refresh token for secure storage. */
        $token_hash = hash( 'sha256', $token );

        /** Store metadata for the hashed refresh token. */
        $tokens[ $token_hash ] = array(
            /** Store the token creation timestamp. */
            'created_at' => time(),

            /** Store the token expiration timestamp. */
            'expires_at' => JWT::get_token_expiration( $token ),
        );

        /** Remove expired refresh tokens from the stored token list. */
        $tokens = array_filter(
            $tokens,
            function( $token_data ) {
                /** Keep only tokens that have not expired yet. */
                return $token_data['expires_at'] > time();
            }
        );

        /** Save the updated refresh token list for the user. */
        update_user_meta( $user_id, 'aicommerce_refresh_tokens', $tokens );
    }

    /**
     * Check whether a refresh token is valid for a user.
     *
     * @param int    $user_id The user ID.
     * @param string $token The refresh token.
     * @return bool
     */
    private function is_refresh_token_valid( int $user_id, string $token ): bool {
        /** Load the stored refresh tokens for the user. */
        $tokens = get_user_meta( $user_id, 'aicommerce_refresh_tokens', true );

        /** Return false if the stored token list is not a valid array. */
        if ( ! is_array( $tokens ) ) {
            return false;
        }

        /** Generate the hash for the provided refresh token. */
        $token_hash = hash( 'sha256', $token );

        /** Return whether the hashed token exists in the stored token list. */
        return isset( $tokens[ $token_hash ] );
    }

    /**
     * Remove a stored refresh token for a user.
     *
     * @param int    $user_id The user ID.
     * @param string $token The refresh token.
     * @return void
     */
    private function remove_refresh_token( int $user_id, string $token ): void {
        /** Load the stored refresh tokens for the user. */
        $tokens = get_user_meta( $user_id, 'aicommerce_refresh_tokens', true );

        /** Stop if the stored token list is not a valid array. */
        if ( ! is_array( $tokens ) ) {
            return;
        }

        /** Generate the hash for the provided refresh token. */
        $token_hash = hash( 'sha256', $token );

        /** Remove the hashed token from the stored token list when it exists. */
        if ( isset( $tokens[ $token_hash ] ) ) {
            /** Delete the token entry from the token list. */
            unset( $tokens[ $token_hash ] );

            /** Save the updated token list for the user. */
            update_user_meta( $user_id, 'aicommerce_refresh_tokens', $tokens );
        }
    }
}
