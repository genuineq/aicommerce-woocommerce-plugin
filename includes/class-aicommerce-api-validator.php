<?php
/**
 * API Request Validation
 *
 * @package AICommerce
 */

namespace AICommerce;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * API Validator Class
 */
class APIValidator {
    
    /**
     * Maximum request age in seconds (5 minutes)
     */
    private const MAX_REQUEST_AGE = 300;
    
    /**
     * Validate API request
     */
    public static function validate_request( \WP_REST_Request $request ): ?array {
        $api_key = $request->get_header( 'X-API-Key' );
        $signature = $request->get_header( 'X-API-Signature' );
        $timestamp = $request->get_header( 'X-Request-Timestamp' );
        
        if ( empty( $api_key ) ) {
            return array(
                'valid'   => false,
                'code'    => 'missing_api_key',
                'message' => __( 'API Key is required.', 'aicommerce' ),
            );
        }
        
        if ( empty( $signature ) ) {
            return array(
                'valid'   => false,
                'code'    => 'missing_signature',
                'message' => __( 'API Signature is required.', 'aicommerce' ),
            );
        }
        
        if ( empty( $timestamp ) ) {
            return array(
                'valid'   => false,
                'code'    => 'missing_timestamp',
                'message' => __( 'Request Timestamp is required.', 'aicommerce' ),
            );
        }
        
        $timestamp_validation = self::validate_timestamp( $timestamp );
        if ( ! $timestamp_validation['valid'] ) {
            return $timestamp_validation;
        }
        
        $stored_api_key = Settings::get_api_key();
        
        if ( empty( $stored_api_key ) ) {
            return array(
                'valid'   => false,
                'code'    => 'api_not_configured',
                'message' => __( 'API credentials are not configured. Please configure them in the admin panel.', 'aicommerce' ),
            );
        }
        
        if ( ! hash_equals( $stored_api_key, $api_key ) ) {
            return array(
                'valid'   => false,
                'code'    => 'invalid_api_key',
                'message' => __( 'Invalid API Key.', 'aicommerce' ),
            );
        }
        
        $signature_validation = self::validate_signature( $request, $signature, $timestamp );
        if ( ! $signature_validation['valid'] ) {
            return $signature_validation;
        }
        
        return array(
            'valid' => true,
        );
    }
    
    /**
     * Validate timestamp
     */
    private static function validate_timestamp( string $timestamp ): array {
        if ( ! is_numeric( $timestamp ) ) {
            return array(
                'valid'   => false,
                'code'    => 'invalid_timestamp',
                'message' => __( 'Invalid timestamp format.', 'aicommerce' ),
            );
        }
        
        $timestamp = (int) $timestamp;
        $current_time = time();
        $time_diff = abs( $current_time - $timestamp );
        
        if ( $time_diff > self::MAX_REQUEST_AGE ) {
            return array(
                'valid'   => false,
                'code'    => 'request_expired',
                'message' => __( 'Request has expired or timestamp is invalid.', 'aicommerce' ),
            );
        }
        
        return array( 'valid' => true );
    }
    
    /**
     * Validate HMAC signature
     */
    private static function validate_signature( \WP_REST_Request $request, string $signature, string $timestamp ): array {
        $api_secret = Settings::get_api_secret();
        
        if ( empty( $api_secret ) ) {
            return array(
                'valid'   => false,
                'code'    => 'api_not_configured',
                'message' => __( 'API credentials are not configured.', 'aicommerce' ),
            );
        }
        
        $body_original = $request->get_body();
        
        $method = $request->get_method();
        $path = '/wp-json' . $request->get_route();
        
        $signature_string_original = $method . $path . $body_original . $timestamp;
        $expected_signature_original = hash_hmac( 'sha256', $signature_string_original, $api_secret );
        
        $is_valid_original = hash_equals( $expected_signature_original, $signature );
        
        $is_valid_normalized = false;
        $body_normalized = $body_original;
        $expected_signature_normalized = '';
        
        if ( ! $is_valid_original && ! empty( $body_original ) && self::is_json( $body_original ) ) {
            $body_decoded = json_decode( $body_original, true );
            if ( $body_decoded !== null ) {
                $body_normalized = wp_json_encode( $body_decoded );
                $signature_string_normalized = $method . $path . $body_normalized . $timestamp;
                $expected_signature_normalized = hash_hmac( 'sha256', $signature_string_normalized, $api_secret );
                $is_valid_normalized = hash_equals( $expected_signature_normalized, $signature );
            }
        }
        
        $is_valid = $is_valid_original || $is_valid_normalized;
        
        if ( ! $is_valid ) {
            return array(
                'valid'   => false,
                'code'    => 'invalid_signature',
                'message' => __( 'Invalid request signature.', 'aicommerce' ),
                'debug'   => defined( 'WP_DEBUG' ) && WP_DEBUG ? array(
                    'expected_original'   => $expected_signature_original,
                    'expected_normalized' => $expected_signature_normalized,
                    'received'            => $signature,
                ) : null,
            );
        }
        
        return array( 'valid' => true );
    }
    
    /**
     * Check if string is valid JSON
     */
    private static function is_json( string $string ): bool {
        if ( empty( $string ) ) {
            return false;
        }
        
        json_decode( $string );
        return json_last_error() === JSON_ERROR_NONE;
    }
    
    /**
     * Generate signature for testing/documentation
     */
    public static function generate_signature( string $method, string $path, string $body, string $timestamp, string $api_secret ): string {
        if ( ! empty( $body ) && self::is_json( $body ) ) {
            $body_decoded = json_decode( $body, true );
            if ( $body_decoded !== null ) {
                $body = wp_json_encode( $body_decoded );
            }
        }
        
        $signature_string = $method . $path . $body . $timestamp;
        return hash_hmac( 'sha256', $signature_string, $api_secret );
    }
    
    /**
     * Create error response
     */
    public static function error_response( array $validation_result ): \WP_REST_Response {
        return new \WP_REST_Response(
            array(
                'success' => false,
                'code'    => $validation_result['code'],
                'message' => $validation_result['message'],
            ),
            401
        );
    }
}
