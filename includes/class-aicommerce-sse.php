<?php
/**
 * Server-Sent Events (SSE) for Real-time Push Notifications
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SSE Class
 * Handles Server-Sent Events for real-time cart updates
 */
class SSE {

    /** Active in-memory connections for current request (not persistent across requests). */
    private static $connections = array();

    /**
     * Constructor.
     *
     * Registers REST API routes for SSE endpoint.
     *
     * @return void
     */
    public function __construct() {
        /** Hook into REST API initialization to register routes. */
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public function register_routes(): void {
        /** Define API namespace. */
        $namespace = 'aicommerce/v1';

        /** Register SSE endpoint for cart updates. */
        register_rest_route(
            $namespace,
            '/sse/cart',
            array(
                /** Allow GET requests. */
                'methods'             => 'GET',
                /** Callback for handling SSE connection. */
                'callback'            => array( $this, 'handle_sse_connection' ),
                /** Public access allowed. */
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Handle SSE connection.
     *
     * Initializes headers, validates client, registers connection,
     * and starts the keep-alive loop.
     *
     * @param \WP_REST_Request $request
     * @return void
     */
    public function handle_sse_connection( \WP_REST_Request $request ): void {
        /** Set SSE-specific headers. */
        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'Connection: keep-alive' );
        header( 'X-Accel-Buffering: no' );

        /** Extract guest token from request. */
        $guest_token = $request->get_param( 'guest_token' );

        /** Validate presence of guest token. */
        if ( empty( $guest_token ) ) {
            $this->send_connection_event( 'error', array(
                'message' => 'Guest token is required',
            ) );
            exit;
        }

        /** Validate guest token format. */
        if ( ! $this->validate_guest_token( $guest_token ) ) {
            $this->send_connection_event( 'error', array(
                'message' => 'Invalid guest token format',
            ) );
            exit;
        }

        /** Limit concurrent connections per guest token (max 3). */
        if ( isset( self::$connections[ $guest_token ] ) && count( self::$connections[ $guest_token ] ) >= 3 ) {
            $this->send_connection_event( 'error', array(
                'message' => 'Maximum connections limit reached',
            ) );
            exit;
        }

        /** Generate unique connection ID. */
        $connection_id = uniqid( 'conn_', true );

        /** Register connection in memory. */
        self::$connections[ $guest_token ][ $connection_id ] = array(
            'started_at' => time(),
            'last_ping'  => time(),
        );

        /** Send initial connection event. */
        $this->send_connection_event( 'connected', array(
            'connection_id' => $connection_id,
            'guest_token'   => $guest_token,
        ) );

        /** Start keep-alive loop. */
        $this->keep_alive( $guest_token, $connection_id );
    }

    /**
     * Keep connection alive and process events.
     *
     * @param string $guest_token
     * @param string $connection_id
     * @return void
     */
    private function keep_alive( string $guest_token, string $connection_id ): void {
        /** Maximum idle time before disconnect (seconds). */
        $max_idle_time = 300;

        /** Interval for sending ping events. */
        $ping_interval = 30;

        /** Interval for checking new events. */
        $event_check_interval = 3;

        /** Track last ping timestamp. */
        $last_ping = time();

        /** Track last event check timestamp. */
        $last_event_check = time();

        /** Infinite loop to keep connection open. */
        while ( true ) {
            /** Break if connection no longer exists. */
            if ( ! isset( self::$connections[ $guest_token ][ $connection_id ] ) ) {
                break;
            }

            /** Disconnect if idle timeout exceeded. */
            if ( ( time() - $last_ping ) > $max_idle_time ) {
                $this->send_connection_event( 'timeout', array(
                    'message' => 'Connection timeout',
                ) );
                $this->unregister_connection( $guest_token, $connection_id );
                break;
            }

            /** Periodically check for new events. */
            if ( ( time() - $last_event_check ) >= $event_check_interval ) {
                /** Retrieve pending events. */
                $events = self::get_pending_events(
                    $guest_token,
                    self::$connections[ $guest_token ][ $connection_id ]['last_event_time'] ?? 0
                );

                /** Send each event to client. */
                foreach ( $events as $event ) {
                    $this->send_connection_event( $event['event_type'], $event['data'] );

                    /** Update last processed event timestamp. */
                    self::$connections[ $guest_token ][ $connection_id ]['last_event_time'] = $event['timestamp'];
                }

                $last_event_check = time();
            }

            /** Send periodic ping to keep connection alive. */
            if ( ( time() - $last_ping ) >= $ping_interval ) {
                $this->send_connection_event( 'ping', array(
                    'timestamp' => time(),
                ) );

                /** Update ping timestamp. */
                $last_ping = time();
                self::$connections[ $guest_token ][ $connection_id ]['last_ping'] = $last_ping;
            }

            /** Detect client disconnect. */
            if ( connection_aborted() ) {
                $this->unregister_connection( $guest_token, $connection_id );
                break;
            }

            /** Sleep to reduce CPU usage (1 second). */
            usleep( 1000000 );

            /** Flush output buffers. */
            if ( ob_get_level() > 0 ) {
                ob_flush();
            }
            flush();
        }
    }

    /**
     * Send event to all connections for a guest token.
     *
     * @param string $guest_token
     * @param string $event_type
     * @param array  $data
     * @return void
     */
    public static function send_event( string $guest_token, string $event_type, array $data = array() ): void {
        /** Skip if required data is missing. */
        if ( empty( $guest_token ) || empty( $event_type ) ) {
            return;
        }

        /** Hash guest token for secure storage. */
        $token_hash = hash( 'sha256', $guest_token );

        /** Build unique transient key. */
        $transient_key = 'aicommerce_sse_event_' . $token_hash . '_' . time() . '_' . wp_generate_password( 8, false );

        /** Prepare event data payload. */
        $event_data = array(
            'event_type'       => $event_type,
            'data'             => $data,
            'timestamp'        => time(),
            'guest_token_hash' => $token_hash,
        );

        /** Store event in transient for persistence. */
        set_transient( $transient_key, $event_data, 60 );

        /** Cache recent events for fast retrieval. */
        $cache_key = 'aicommerce_sse_events_' . $token_hash;
        $cached_events = wp_cache_get( $cache_key, 'aicommerce' );

        /** Initialize cache if empty. */
        if ( false === $cached_events ) {
            $cached_events = array();
        }

        /** Append new event. */
        $cached_events[] = array(
            'event_type' => $event_type,
            'data'       => $data,
            'timestamp'  => time(),
        );

        /** Keep only last 20 events. */
        $cached_events = array_slice( $cached_events, -20 );

        /** Store cache with expiration. */
        wp_cache_set( $cache_key, $cached_events, 'aicommerce', 30 );

        /** Trigger hook for external listeners. */
        do_action( 'aicommerce_sse_event_sent', $guest_token, $event_type, $data );
    }

    /**
     * Send SSE event to current connection.
     *
     * @param string $event_type
     * @param array  $data
     * @return void
     */
    private function send_connection_event( string $event_type, array $data = array() ): void {
        /** Encode event data as JSON. */
        $event_data = wp_json_encode( $data );

        /** Output SSE event format. */
        echo "event: {$event_type}\n";
        echo "data: {$event_data}\n\n";

        /** Flush output buffers. */
        if ( ob_get_level() > 0 ) {
            ob_flush();
        }
        flush();
    }

    /**
     * Unregister connection.
     *
     * @param string $guest_token
     * @param string $connection_id
     * @return void
     */
    private function unregister_connection( string $guest_token, string $connection_id ): void {
        /** Remove specific connection. */
        if ( isset( self::$connections[ $guest_token ][ $connection_id ] ) ) {
            unset( self::$connections[ $guest_token ][ $connection_id ] );
        }

        /** Remove guest entry if no connections remain. */
        if ( isset( self::$connections[ $guest_token ] ) && empty( self::$connections[ $guest_token ] ) ) {
            unset( self::$connections[ $guest_token ] );
        }
    }

    /**
     * Validate guest token format.
     *
     * @param string $guest_token
     * @return bool
     */
    private function validate_guest_token( string $guest_token ): bool {
        /** Reject empty tokens. */
        if ( empty( $guest_token ) ) {
            return false;
        }

        /** Validate token pattern. */
        return preg_match( '/^guest_\d+_[a-zA-Z0-9]+_[a-f0-9]{8}$/', $guest_token ) === 1;
    }

    /**
     * Get pending events.
     *
     * @param string $guest_token
     * @param int    $since
     * @return array
     */
    private static function get_pending_events( string $guest_token, int $since = 0 ): array {
        global $wpdb;

        /** Initialize events array. */
        $events = array();

        /** Hash guest token for lookup. */
        $token_hash = hash( 'sha256', $guest_token );

        /** Attempt to retrieve events from cache. */
        $cache_key = 'aicommerce_sse_events_' . $token_hash;
        $cached_events = wp_cache_get( $cache_key, 'aicommerce' );

        /** Use cached events if available. */
        if ( false !== $cached_events && is_array( $cached_events ) ) {
            foreach ( $cached_events as $event ) {
                if ( isset( $event['timestamp'] ) && $event['timestamp'] > $since ) {
                    $events[] = $event;
                }
            }

            if ( ! empty( $events ) ) {
                return $events;
            }
        }

        /** Fallback: query events from transients table. */
        $transient_prefix = 'aicommerce_sse_event_';

        $transients = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value, option_id
                FROM {$wpdb->options}
                WHERE option_name LIKE %s
                AND option_name LIKE %s
                ORDER BY option_id DESC
                LIMIT 50",
                $transient_prefix . '%',
                '%' . substr( $token_hash, 0, 16 ) . '%'
            ),
            ARRAY_A
        );

        /** Collect matching events. */
        $all_events = array();

        foreach ( $transients as $transient ) {
            /** Unserialize stored data. */
            $event_data = maybe_unserialize( $transient['option_value'] );

            /** Validate event structure. */
            if ( is_array( $event_data ) && isset( $event_data['timestamp'] ) && isset( $event_data['guest_token_hash'] ) ) {
                /** Filter events by token and timestamp. */
                if ( $event_data['guest_token_hash'] === $token_hash && $event_data['timestamp'] > $since ) {
                    $all_events[] = array(
                        'event_type' => isset( $event_data['event_type'] ) ? $event_data['event_type'] : 'unknown',
                        'data'       => isset( $event_data['data'] ) ? $event_data['data'] : array(),
                        'timestamp'  => $event_data['timestamp'],
                    );
                }
            }
        }

        /** Sort events by timestamp ascending. */
        usort( $all_events, function( $a, $b ) {
            return $a['timestamp'] - $b['timestamp'];
        } );

        /** Cache results for faster subsequent retrieval. */
        if ( ! empty( $all_events ) ) {
            wp_cache_set( $cache_key, $all_events, 'aicommerce', 30 );
        }

        /** Return collected events. */
        return $all_events;
    }
}
