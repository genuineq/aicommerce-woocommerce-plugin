<?php
/**
 * JWT Token handling
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** JWT class. */
class JWT {

    /** JWT signing algorithm. */
    private const ALGORITHM = 'HS256';

    /** Access token expiration time in seconds (24 hours). */
    private const ACCESS_TOKEN_EXP = 86400;

    /** Refresh token expiration time in seconds (7 days). */
    private const REFRESH_TOKEN_EXP = 604800;

    /**
     * Get the JWT secret.
     *
     * @return string
     */
    private static function get_secret(): string {
        /** Load the encrypted JWT secret from WordPress options. */
        $encrypted = get_option( 'aicommerce_jwt_secret', '' );

        /** Generate and reload the JWT secret when it does not exist yet. */
        if ( empty( $encrypted ) ) {
            self::generate_secret();
            $encrypted = get_option( 'aicommerce_jwt_secret', '' );
        }

        /** Decrypt and return the JWT secret. */
        return Encryption::decrypt( $encrypted );
    }

    /**
     * Generate and save a JWT secret.
     *
     * @return bool
     */
    public static function generate_secret(): bool {
        /** Generate a random 32-byte secret and convert it to hexadecimal. */
        $secret = bin2hex( random_bytes( 32 ) );

        /** Encrypt and store the generated JWT secret in WordPress options. */
        return update_option( 'aicommerce_jwt_secret', Encryption::encrypt( $secret ) );
    }

    /**
     * Encode data using Base64 URL-safe encoding.
     *
     * @param string $data The input data.
     * @return string
     */
    private static function base64url_encode( string $data ): string {
        /** Return the Base64 URL-safe encoded string without padding. */
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    /**
     * Decode Base64 URL-safe encoded data.
     *
     * @param string $data The encoded input data.
     * @return string
     */
    private static function base64url_decode( string $data ): string {
        /** Convert URL-safe characters back to standard Base64 and decode the value. */
        return base64_decode( strtr( $data, '-_', '+/' ) );
    }

    /**
     * Create a JWT token.
     *
     * @param int    $user_id The user ID.
     * @param string $type The token type, either access or refresh.
     * @return string
     */
    public static function create_token( int $user_id, string $type = 'access' ): string {
        /** Get the token issue timestamp. */
        $issued_at = time();

        /** Compute the token expiration timestamp based on token type. */
        $expiration = $issued_at + ( 'refresh' === $type ? self::REFRESH_TOKEN_EXP : self::ACCESS_TOKEN_EXP );

        /** Build the JWT header. */
        $header = array(
            /** Define the token type. */
            'typ' => 'JWT',

            /** Define the signing algorithm. */
            'alg' => self::ALGORITHM,
        );

        /** Build the JWT payload. */
        $payload = array(
            /** Store the issued-at timestamp. */
            'iat'     => $issued_at,

            /** Store the expiration timestamp. */
            'exp'     => $expiration,

            /** Store the user ID. */
            'user_id' => $user_id,

            /** Store the token type. */
            'type'    => $type,
        );

        /** Encode the JWT header using Base64 URL-safe encoding. */
        $header_encoded = self::base64url_encode( wp_json_encode( $header ) );

        /** Encode the JWT payload using Base64 URL-safe encoding. */
        $payload_encoded = self::base64url_encode( wp_json_encode( $payload ) );

        /** Generate the HMAC SHA-256 signature for the header and payload. */
        $signature = hash_hmac( 'sha256', "$header_encoded.$payload_encoded", self::get_secret(), true );

        /** Encode the raw signature using Base64 URL-safe encoding. */
        $signature_encoded = self::base64url_encode( $signature );

        /** Return the final JWT token string. */
        return "$header_encoded.$payload_encoded.$signature_encoded";
    }

    /**
     * Verify and decode a JWT token.
     *
     * @param string $token The JWT token.
     * @return array|null
     */
    public static function verify_token( string $token ): ?array {
        /** Split the JWT token into its three dot-separated parts. */
        $parts = explode( '.', $token );

        /** Return null when the token format is invalid. */
        if ( 3 !== count( $parts ) ) {
            return null;
        }

        /** Assign the token parts to header, payload, and signature variables. */
        list( $header_encoded, $payload_encoded, $signature_encoded ) = $parts;

        /** Decode the signature from Base64 URL-safe format. */
        $signature = self::base64url_decode( $signature_encoded );

        /** Recompute the expected HMAC SHA-256 signature. */
        $expected_signature = hash_hmac( 'sha256', "$header_encoded.$payload_encoded", self::get_secret(), true );

        /** Return null when the provided signature does not match the expected signature. */
        if ( ! hash_equals( $expected_signature, $signature ) ) {
            return null;
        }

        /** Decode the JWT payload into an associative array. */
        $payload = json_decode( self::base64url_decode( $payload_encoded ), true );

        /** Return null when the payload cannot be decoded. */
        if ( ! $payload ) {
            return null;
        }

        /** Return null when the token has expired. */
        if ( isset( $payload['exp'] ) && $payload['exp'] < time() ) {
            return null;
        }

        /** Return the verified payload data. */
        return $payload;
    }

    /**
     * Extract the token from the Authorization header.
     *
     * @return string|null
     */
    public static function get_token_from_header(): ?string {
        /** Read the Authorization header from the server variables. */
        $auth_header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : '';

        /** Fallback to redirected Authorization header when needed. */
        if ( empty( $auth_header ) && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
            $auth_header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        /** Return null when no Authorization header is present. */
        if ( empty( $auth_header ) ) {
            return null;
        }

        /** Return the Bearer token when the header matches the expected format. */
        if ( preg_match( '/Bearer\s+(.*)$/i', $auth_header, $matches ) ) {
            return $matches[1];
        }

        /** Return null when the Authorization header does not contain a Bearer token. */
        return null;
    }

    /**
     * Get the user ID from a JWT token.
     *
     * @param string $token The JWT token.
     * @return int|null
     */
    public static function get_user_id_from_token( string $token ): ?int {
        /** Verify the token and decode its payload. */
        $payload = self::verify_token( $token );

        /** Return null when the token is invalid or does not contain a user ID. */
        if ( ! $payload || ! isset( $payload['user_id'] ) ) {
            return null;
        }

        /** Return the user ID as an integer. */
        return (int) $payload['user_id'];
    }

    /**
     * Check whether a token is expired.
     *
     * @param string $token The JWT token.
     * @return bool
     */
    public static function is_token_expired( string $token ): bool {
        /** Split the JWT token into its three dot-separated parts. */
        $parts = explode( '.', $token );

        /** Treat the token as expired when the token format is invalid. */
        if ( 3 !== count( $parts ) ) {
            return true;
        }

        /** Decode the JWT payload into an associative array. */
        $payload = json_decode( self::base64url_decode( $parts[1] ), true );

        /** Treat the token as expired when the payload or expiration field is invalid. */
        if ( ! $payload || ! isset( $payload['exp'] ) ) {
            return true;
        }

        /** Return whether the token expiration timestamp is in the past. */
        return $payload['exp'] < time();
    }

    /**
     * Get the token expiration timestamp.
     *
     * @param string $token The JWT token.
     * @return int|null
     */
    public static function get_token_expiration( string $token ): ?int {
        /** Split the JWT token into its three dot-separated parts. */
        $parts = explode( '.', $token );

        /** Return null when the token format is invalid. */
        if ( 3 !== count( $parts ) ) {
            return null;
        }

        /** Decode the JWT payload into an associative array. */
        $payload = json_decode( self::base64url_decode( $parts[1] ), true );

        /** Return null when the payload or expiration field is invalid. */
        if ( ! $payload || ! isset( $payload['exp'] ) ) {
            return null;
        }

        /** Return the expiration timestamp as an integer. */
        return (int) $payload['exp'];
    }
}
