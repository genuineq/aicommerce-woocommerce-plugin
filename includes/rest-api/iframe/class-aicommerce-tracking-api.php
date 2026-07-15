<?php
/**
 * Tracking proxy API endpoints.
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tracking API Class
 */
class TrackingAPI {
    /** Maximum accepted JSON body size for one tracking event. */
    private const MAX_BODY_BYTES = 4096;

    /** Maximum forwarded user-agent header length. */
    private const MAX_USER_AGENT_LENGTH = 512;

    /** Maximum forwarded page URL length. */
    private const MAX_PAGE_URL_LENGTH = 2048;

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public function register_routes(): void {
        foreach ( array( 'new-session', 'chat-opened' ) as $event ) {
            register_rest_route(
                'aicommerce/v1',
                '/tracking/' . $event,
                array(
                    'methods'             => 'POST',
                    'callback'            => function( \WP_REST_Request $request ) use ( $event ) {
                        return $this->proxy_tracking_event( $request, $event );
                    },
                    'permission_callback' => '__return_true',
                )
            );
        }
    }

    /**
     * Forward a storefront tracking event to the AICommerce backend.
     *
     * @param \WP_REST_Request $request REST request.
     * @param string           $event   Tracking event slug.
     * @return \WP_REST_Response REST response.
     */
    public function proxy_tracking_event( \WP_REST_Request $request, string $event ): \WP_REST_Response {
        if ( strlen( (string) $request->get_body() ) > self::MAX_BODY_BYTES ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'tracking_payload_too_large',
                    'message' => __( 'Tracking payload is too large.', 'aicommerce' ),
                ),
                413
            );
        }

        if ( ! $this->is_valid_tracking_nonce( $request ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'invalid_tracking_nonce',
                    'message' => __( 'Invalid tracking request.', 'aicommerce' ),
                ),
                403
            );
        }

        if ( ! $this->has_valid_request_origin( $request ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'invalid_tracking_origin',
                    'message' => __( 'Invalid tracking request origin.', 'aicommerce' ),
                ),
                403
            );
        }

        if ( ! RateLimiter::is_allowed( 'tracking:' . $event . ':' . RateLimiter::get_client_id(), 120, 60 ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'rate_limited',
                    'message' => __( 'Too many tracking requests. Please try again shortly.', 'aicommerce' ),
                ),
                429
            );
        }

        $api_key = Settings::get_api_key();

        if ( empty( $api_key ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'missing_api_key',
                    'message' => __( 'API key is not configured.', 'aicommerce' ),
                ),
                400
            );
        }

        $payload = $this->sanitize_payload( $request->get_json_params() );

        if ( empty( $payload['session_id'] ) || ! $this->is_valid_session_id( (string) $payload['session_id'] ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'invalid_session_id',
                    'message' => __( 'A valid session_id is required.', 'aicommerce' ),
                ),
                400
            );
        }

        $payload['api_key'] = $api_key;
        $payload['context'] = $this->complete_context( $payload['context'], $request );
        $body               = wp_json_encode( $payload );
        $user_agent         = ! empty( $payload['context']['user_agent'] )
            ? (string) $payload['context']['user_agent']
            : 'AICommerce WordPress Tracking Proxy';
        $page_url           = ! empty( $payload['context']['page_url'] ) ? (string) $payload['context']['page_url'] : '';
        $device_type        = ! empty( $payload['context']['device_type'] ) ? (string) $payload['context']['device_type'] : '';

        $response = wp_remote_post(
            $this->get_tracking_endpoint_base_url( $api_key ) . '/' . $event,
            array(
                'timeout'    => 5,
                'user-agent' => $user_agent,
                'headers'    => array(
                    'Accept'                  => 'application/json',
                    'Content-Type'            => 'application/json',
                    'X-AICommerce-Api-Key'    => $api_key,
                    'X-AICommerce-Event'      => str_replace( '-', '_', $event ),
                    'X-AICommerce-Source-Url' => get_site_url(),
                    'X-AICommerce-User-Agent' => $user_agent,
                    'X-AICommerce-Page-Url'   => $page_url,
                    'X-AICommerce-Device-Type' => $device_type,
                ),
                'body'    => $body,
            )
        );

        if ( is_wp_error( $response ) ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'tracking_forward_failed',
                    'message' => __( 'Tracking event could not be forwarded.', 'aicommerce' ),
                ),
                502
            );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );

        if ( $status < 200 || $status >= 300 ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'code'    => 'tracking_backend_rejected',
                    'status'  => $status,
                ),
                502
            );
        }

        return new \WP_REST_Response(
            array(
                'success' => true,
                'status'  => $status,
            ),
            200
        );
    }

    /**
     * Sanitize the frontend tracking payload before forwarding.
     *
     * @param mixed $payload Raw JSON payload.
     * @return array<string,mixed> Sanitized payload.
     */
    private function sanitize_payload( $payload ): array {
        $payload = is_array( $payload ) ? $payload : array();
        $context = isset( $payload['context'] ) && is_array( $payload['context'] ) ? $payload['context'] : array();

        $sanitized = array(
            'session_id' => isset( $payload['session_id'] ) ? sanitize_text_field( (string) $payload['session_id'] ) : '',
            'context'    => array(
                'user_agent'  => isset( $context['user_agent'] ) ? $this->truncate_text( sanitize_text_field( (string) $context['user_agent'] ), self::MAX_USER_AGENT_LENGTH ) : '',
                'page_url'    => isset( $context['page_url'] ) ? $this->truncate_text( esc_url_raw( (string) $context['page_url'] ), self::MAX_PAGE_URL_LENGTH ) : '',
                'device_type' => isset( $context['device_type'] ) ? $this->normalize_device_type( (string) $context['device_type'] ) : '',
            ),
        );

        if ( ! empty( $payload['guest_token'] ) ) {
            $guest_token = sanitize_text_field( (string) $payload['guest_token'] );
            if ( $this->is_valid_guest_token( $guest_token ) ) {
                $sanitized['guest_token'] = $guest_token;
            }
        }

        if ( ! empty( $payload['user_id'] ) ) {
            $user_id = absint( $payload['user_id'] );
            if ( $user_id > 0 ) {
                $sanitized['user_id'] = $user_id;
            }
        }

        if ( ! empty( $payload['cart_token'] ) ) {
            $cart_token = sanitize_text_field( (string) $payload['cart_token'] );
            if ( $this->is_valid_cart_token( $cart_token ) ) {
                $sanitized['cart_token'] = $cart_token;
            }
        }

        return $sanitized;
    }

    /**
     * Validate the short-lived visit/session token generated by the storefront.
     *
     * @param string $session_id Session ID.
     * @return bool True when the format is safe to forward.
     */
    private function is_valid_session_id( string $session_id ): bool {
        return (bool) preg_match( '/^\d{10,17}_[a-zA-Z0-9]{4,32}_[a-zA-Z0-9]{1,16}$/', $session_id );
    }

    /**
     * Validate guest token format before forwarding identity metadata.
     *
     * @param string $guest_token Guest token.
     * @return bool True when the format matches AICommerce guest identities.
     */
    private function is_valid_guest_token( string $guest_token ): bool {
        return (bool) preg_match( '/^guest_\d+_[a-zA-Z0-9]+_[a-f0-9]{8}$/', $guest_token );
    }

    /**
     * Validate browser-scoped user cart token format before forwarding.
     *
     * @param string $cart_token Cart token.
     * @return bool True when token shape is plausible.
     */
    private function is_valid_cart_token( string $cart_token ): bool {
        return (bool) preg_match( '/^[a-zA-Z0-9]{32,128}$/', $cart_token );
    }

    /**
     * Normalize frontend device type into a small public enum.
     *
     * @param string $device_type Device type candidate.
     * @return string Normalized device type or empty string.
     */
    private function normalize_device_type( string $device_type ): string {
        $device_type = sanitize_key( $device_type );

        return in_array( $device_type, array( 'desktop', 'mobile', 'tablet' ), true ) ? $device_type : '';
    }

    /**
     * Truncate user-controlled strings before forwarding them as headers or JSON.
     *
     * @param string $value String value.
     * @param int    $max_length Maximum byte length.
     * @return string Truncated string.
     */
    private function truncate_text( string $value, int $max_length ): string {
        return strlen( $value ) > $max_length ? substr( $value, 0, $max_length ) : $value;
    }

    /**
     * Validate that the tracking request came from a rendered storefront page.
     *
     * @param \WP_REST_Request $request REST request.
     * @return bool True when nonce is valid.
     */
    private function is_valid_tracking_nonce( \WP_REST_Request $request ): bool {
        $nonce = $request->get_header( 'x_aicommerce_tracking_nonce' );

        if ( empty( $nonce ) ) {
            return false;
        }

        return (bool) wp_verify_nonce( sanitize_text_field( (string) $nonce ), 'aicommerce_tracking' );
    }

    /**
     * Validate Origin/Referer when browsers provide them.
     *
     * Missing headers are allowed because the tracking nonce already proves the
     * request came from a rendered storefront page.
     *
     * @param \WP_REST_Request $request REST request.
     * @return bool True when origin headers are absent or same-site.
     */
    private function has_valid_request_origin( \WP_REST_Request $request ): bool {
        $origin  = esc_url_raw( (string) $request->get_header( 'origin' ) );
        $referer = esc_url_raw( (string) $request->get_header( 'referer' ) );

        if ( empty( $origin ) && empty( $referer ) ) {
            return true;
        }

        if ( ! empty( $origin ) && ! $this->is_same_site_url( $origin ) ) {
            return false;
        }

        if ( ! empty( $referer ) && ! $this->is_same_site_url( $referer ) ) {
            return false;
        }

        return true;
    }

    /**
     * Check whether a URL belongs to this WordPress site host.
     *
     * @param string $url URL to check.
     * @return bool True when the URL host matches the site host.
     */
    private function is_same_site_url( string $url ): bool {
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        $url_host  = wp_parse_url( $url, PHP_URL_HOST );

        if ( empty( $site_host ) || empty( $url_host ) ) {
            return false;
        }

        return strtolower( (string) $site_host ) === strtolower( (string) $url_host );
    }

    /**
     * Fill context values that may be missing before forwarding.
     *
     * @param array<string,string> $context Sanitized context.
     * @param \WP_REST_Request     $request REST request.
     * @return array<string,string> Complete context.
     */
    private function complete_context( array $context, \WP_REST_Request $request ): array {
        if ( empty( $context['user_agent'] ) ) {
            $context['user_agent'] = $this->truncate_text(
                sanitize_text_field( (string) $request->get_header( 'user_agent' ) ),
                self::MAX_USER_AGENT_LENGTH
            );
        }

        if ( empty( $context['page_url'] ) ) {
            $context['page_url'] = $this->truncate_text(
                esc_url_raw( (string) $request->get_header( 'referer' ) ),
                self::MAX_PAGE_URL_LENGTH
            );
        }

        if ( empty( $context['device_type'] ) ) {
            $context['device_type'] = $this->infer_device_type( (string) $context['user_agent'] );
        }

        return $context;
    }

    /**
     * Infer device type from the user agent as a server-side fallback.
     *
     * @param string $user_agent User agent.
     * @return string Device type.
     */
    private function infer_device_type( string $user_agent ): string {
        return preg_match( '/Mobile|Android|iPhone|iPad|iPod|Windows Phone/i', $user_agent )
            ? 'mobile'
            : 'desktop';
    }

    /**
     * Resolve the backend tracking endpoint base URL.
     *
     * @param string $api_key Configured API key.
     * @return string Tracking endpoint base URL.
     */
    private function get_tracking_endpoint_base_url( string $api_key ): string {
        return ( 0 === strpos( $api_key, 'staging_' ) )
            ? 'https://api.ai.staging.genuineq.com/api/client/tracking'
            : 'https://api.ai.genuineq.com/api/client/tracking';
    }
}
