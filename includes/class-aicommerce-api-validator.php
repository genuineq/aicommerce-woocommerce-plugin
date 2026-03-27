<?php
/**
 * API Request Validation
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** API validator class. */
class APIValidator {

    /** Maximum request age in seconds (5 minutes). */
    private const MAX_REQUEST_AGE = 300;

    /**
     * Validate an incoming API request.
     *
     * @param \WP_REST_Request $request The incoming REST request.
     * @return array|null
     */
    public static function validate_request( \WP_REST_Request $request ): ?array {
        /** Get the API key from the request headers. */
        $api_key = $request->get_header( 'X-API-Key' );

        /** Get the request signature from the request headers. */
        $signature = $request->get_header( 'X-API-Signature' );

        /** Get the request timestamp from the request headers. */
        $timestamp = $request->get_header( 'X-Request-Timestamp' );

        /** Return an error if the API key header is missing. */
        if ( empty( $api_key ) ) {
            return array(
                /** Indicate that validation failed. */
                'valid'   => false,

                /** Define the machine-readable error code. */
                'code'    => 'missing_api_key',

                /** Define the translated human-readable error message. */
                'message' => __( 'API Key is required.', 'aicommerce' ),
            );
        }

        /** Return an error if the signature header is missing. */
        if ( empty( $signature ) ) {
            return array(
                /** Indicate that validation failed. */
                'valid'   => false,

                /** Define the machine-readable error code. */
                'code'    => 'missing_signature',

                /** Define the translated human-readable error message. */
                'message' => __( 'API Signature is required.', 'aicommerce' ),
            );
        }

        /** Return an error if the timestamp header is missing. */
        if ( empty( $timestamp ) ) {
            return array(
                /** Indicate that validation failed. */
                'valid'   => false,

                /** Define the machine-readable error code. */
                'code'    => 'missing_timestamp',

                /** Define the translated human-readable error message. */
                'message' => __( 'Request Timestamp is required.', 'aicommerce' ),
            );
        }

        /** Validate the request timestamp. */
        $timestamp_validation = self::validate_timestamp( $timestamp );

        /** Return the timestamp validation error if the timestamp is invalid. */
        if ( ! $timestamp_validation['valid'] ) {
            return $timestamp_validation;
        }

        /** Load the stored API key from plugin settings. */
        $stored_api_key = Settings::get_api_key();

        /** Return an error if API credentials are not configured. */
        if ( empty( $stored_api_key ) ) {
            return array(
                /** Indicate that validation failed. */
                'valid'   => false,

                /** Define the machine-readable error code. */
                'code'    => 'api_not_configured',

                /** Define the translated human-readable error message. */
                'message' => __( 'API credentials are not configured. Please configure them in the admin panel.', 'aicommerce' ),
            );
        }

        /** Return an error if the provided API key does not match the stored API key. */
        if ( ! hash_equals( $stored_api_key, $api_key ) ) {
            return array(
                /** Indicate that validation failed. */
                'valid'   => false,

                /** Define the machine-readable error code. */
                'code'    => 'invalid_api_key',

                /** Define the translated human-readable error message. */
                'message' => __( 'Invalid API Key.', 'aicommerce' ),
            );
        }

        /** Validate the request signature against the expected HMAC signature. */
        $signature_validation = self::validate_signature( $request, $signature, $timestamp );

        /** Return the signature validation result if signature validation failed. */
        if ( ! $signature_validation['valid'] ) {
            return $signature_validation;
        }

        /** Return a successful validation result. */
        return array(
            /** Indicate that validation succeeded. */
            'valid' => true,
        );
    }

    /**
     * Validate the request timestamp.
     *
     * @param string $timestamp The timestamp received from the request headers.
     * @return array
     */
    private static function validate_timestamp( string $timestamp ): array {
        /** Return an error if the timestamp is not numeric. */
        if ( ! is_numeric( $timestamp ) ) {
            return array(
                /** Indicate that validation failed. */
                'valid'   => false,

                /** Define the machine-readable error code. */
                'code'    => 'invalid_timestamp',

                /** Define the translated human-readable error message. */
                'message' => __( 'Invalid timestamp format.', 'aicommerce' ),
            );
        }

        /** Cast the timestamp to an integer. */
        $timestamp = (int) $timestamp;

        /** Get the current Unix timestamp. */
        $current_time = time();

        /** Calculate the absolute difference between current time and request time. */
        $time_diff = abs( $current_time - $timestamp );

        /** Return an error if the request is older than the maximum allowed age. */
        if ( $time_diff > self::MAX_REQUEST_AGE ) {
            return array(
                /** Indicate that validation failed. */
                'valid'   => false,

                /** Define the machine-readable error code. */
                'code'    => 'request_expired',

                /** Define the translated human-readable error message. */
                'message' => __( 'Request has expired or timestamp is invalid.', 'aicommerce' ),
            );
        }

        /** Return a successful validation result. */
        return array( 'valid' => true );
    }

    /**
     * Validate the HMAC request signature.
     *
     * @param \WP_REST_Request $request The incoming REST request.
     * @param string           $signature The signature received from the request headers.
     * @param string           $timestamp The timestamp received from the request headers.
     * @return array
     */
    private static function validate_signature( \WP_REST_Request $request, string $signature, string $timestamp ): array {
        /** Load the stored API secret from plugin settings. */
        $api_secret = Settings::get_api_secret();

        /** Return an error if API credentials are not configured. */
        if ( empty( $api_secret ) ) {
            return array(
                /** Indicate that validation failed. */
                'valid'   => false,

                /** Define the machine-readable error code. */
                'code'    => 'api_not_configured',

                /** Define the translated human-readable error message. */
                'message' => __( 'API credentials are not configured.', 'aicommerce' ),
            );
        }

        /** Get the raw request body. */
        $body_original = $request->get_body();

        /** Get the HTTP request method. */
        $method = $request->get_method();

        /** Build the REST path used for signature generation. */
        $path = '/wp-json' . $request->get_route();

        /** Build the original signature payload string. */
        $signature_string_original = $method . $path . $body_original . $timestamp;

        /** Generate the expected signature from the original payload string. */
        $expected_signature_original = hash_hmac( 'sha256', $signature_string_original, $api_secret );

        /** Compare the expected original signature with the received signature. */
        $is_valid_original = hash_equals( $expected_signature_original, $signature );

        /** Initialize normalized signature validation state. */
        $is_valid_normalized = false;

        /** Keep the normalized body initialized with the original body by default. */
        $body_normalized = $body_original;

        /** Initialize the normalized expected signature value. */
        $expected_signature_normalized = '';

        /** Try JSON normalization if the original signature does not match and the body looks like JSON. */
        if ( ! $is_valid_original && ! empty( $body_original ) && self::is_json( $body_original ) ) {
            /** Decode the JSON body into an array for normalization. */
            $body_decoded = json_decode( $body_original, true );

            /** Re-encode the decoded JSON and validate against the normalized payload if decoding succeeded. */
            if ( $body_decoded !== null ) {
                /** Normalize the JSON body using WordPress JSON encoding. */
                $body_normalized = wp_json_encode( $body_decoded );

                /** Build the normalized signature payload string. */
                $signature_string_normalized = $method . $path . $body_normalized . $timestamp;

                /** Generate the expected signature from the normalized payload string. */
                $expected_signature_normalized = hash_hmac( 'sha256', $signature_string_normalized, $api_secret );

                /** Compare the expected normalized signature with the received signature. */
                $is_valid_normalized = hash_equals( $expected_signature_normalized, $signature );
            }
        }

        /** Treat the signature as valid if either original or normalized validation passed. */
        $is_valid = $is_valid_original || $is_valid_normalized;

        /** Return an error if neither signature validation strategy succeeded. */
        if ( ! $is_valid ) {
            return array(
                /** Indicate that validation failed. */
                'valid'   => false,

                /** Define the machine-readable error code. */
                'code'    => 'invalid_signature',

                /** Define the translated human-readable error message. */
                'message' => __( 'Invalid request signature.', 'aicommerce' ),

                /** Include debug signature details only when WordPress debug mode is enabled. */
                'debug'   => defined( 'WP_DEBUG' ) && WP_DEBUG ? array(
                    /** Include the expected signature generated from the original body. */
                    'expected_original'   => $expected_signature_original,

                    /** Include the expected signature generated from the normalized body. */
                    'expected_normalized' => $expected_signature_normalized,

                    /** Include the received signature from the request. */
                    'received'            => $signature,
                ) : null,
            );
        }

        /** Return a successful validation result. */
        return array( 'valid' => true );
    }

    /**
     * Check whether a string contains valid JSON.
     *
     * @param string $string The string to check.
     * @return bool
     */
    private static function is_json( string $string ): bool {
        /** Return false if the given string is empty. */
        if ( empty( $string ) ) {
            return false;
        }

        /** Attempt to decode the string as JSON. */
        json_decode( $string );

        /** Return whether the JSON parser reported no errors. */
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Generate a request signature for testing or documentation purposes.
     *
     * @param string $method The HTTP request method.
     * @param string $path The request path.
     * @param string $body The request body.
     * @param string $timestamp The request timestamp.
     * @param string $api_secret The API secret used to generate the signature.
     * @return string
     */
    public static function generate_signature( string $method, string $path, string $body, string $timestamp, string $api_secret ): string {
        /** Normalize the body if it contains valid JSON. */
        if ( ! empty( $body ) && self::is_json( $body ) ) {
            /** Decode the JSON body into an array for normalization. */
            $body_decoded = json_decode( $body, true );

            /** Re-encode the decoded JSON into a normalized JSON string if decoding succeeded. */
            if ( $body_decoded !== null ) {
                $body = wp_json_encode( $body_decoded );
            }
        }

        /** Build the signature payload string. */
        $signature_string = $method . $path . $body . $timestamp;

        /** Return the generated HMAC SHA-256 signature. */
        return hash_hmac( 'sha256', $signature_string, $api_secret );
    }

    /**
     * Create a standardized error response for failed validation.
     *
     * @param array $validation_result The validation result array.
     * @return \WP_REST_Response
     */
    public static function error_response( array $validation_result ): \WP_REST_Response {
        /** Return a REST response with the validation error details and unauthorized status code. */
        return new \WP_REST_Response(
            array(
                /** Indicate that the response represents a failed operation. */
                'success' => false,

                /** Include the machine-readable validation error code. */
                'code'    => $validation_result['code'],

                /** Include the human-readable validation error message. */
                'message' => $validation_result['message'],
            ),
            401
        );
    }
}
