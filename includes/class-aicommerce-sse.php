<?php
/**
 * Server-Sent Events (SSE) for Real-time Push Notifications
 *
 * @package AICommerce
 */

namespace AICommerce;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SSE Class
 * Handles Server-Sent Events for real-time cart updates
 */
class SSE {
    
    /**
     * Active connections storage (in-memory, per request)
     * In production, consider using Redis or similar for multi-server setups
     */
    private static $connections = array();
    
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
        
        register_rest_route(
            $namespace,
            '/sse/cart',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'handle_sse_connection' ),
                'permission_callback' => '__return_true',
            )
        );
    }
    
    /**
     * Handle SSE connection
     */
    public function handle_sse_connection( \WP_REST_Request $request ): void {
        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'Connection: keep-alive' );
        header( 'X-Accel-Buffering: no' );
        
        $guest_token = $request->get_param( 'guest_token' );
        
        if ( empty( $guest_token ) ) {
            $this->send_connection_event( 'error', array(
                'message' => 'Guest token is required',
            ) );
            exit;
        }
        
        if ( ! $this->validate_guest_token( $guest_token ) ) {
            $this->send_connection_event( 'error', array(
                'message' => 'Invalid guest token format',
            ) );
            exit;
        }
        
        // Limit concurrent connections per guest token (max 3) - optimization
        if ( isset( self::$connections[ $guest_token ] ) && count( self::$connections[ $guest_token ] ) >= 3 ) {
            $this->send_connection_event( 'error', array(
                'message' => 'Maximum connections limit reached',
            ) );
            exit;
        }
        
        $connection_id = uniqid( 'conn_', true );
        self::$connections[ $guest_token ][ $connection_id ] = array(
            'started_at' => time(),
            'last_ping'  => time(),
        );
        
        $this->send_connection_event( 'connected', array(
            'connection_id' => $connection_id,
            'guest_token'   => $guest_token,
        ) );
        
        $this->keep_alive( $guest_token, $connection_id );
    }
    
    /**
     * Keep connection alive and handle events
     * Optimized: Reduced event check frequency and increased sleep interval
     */
    private function keep_alive( string $guest_token, string $connection_id ): void {
        $max_idle_time = 300;
        $ping_interval = 30;
        $event_check_interval = 3;
        
        $last_ping = time();
        $last_event_check = time();
        
        while ( true ) {
            if ( ! isset( self::$connections[ $guest_token ][ $connection_id ] ) ) {
                break;
            }
            
            if ( ( time() - $last_ping ) > $max_idle_time ) {
                $this->send_connection_event( 'timeout', array(
                    'message' => 'Connection timeout',
                ) );
                $this->unregister_connection( $guest_token, $connection_id );
                break;
            }
            
            // Check for events less frequently (optimized)
            if ( ( time() - $last_event_check ) >= $event_check_interval ) {
                $events = self::get_pending_events( $guest_token, self::$connections[ $guest_token ][ $connection_id ]['last_event_time'] ?? 0 );
                
                foreach ( $events as $event ) {
                    $this->send_connection_event( $event['event_type'], $event['data'] );
                    self::$connections[ $guest_token ][ $connection_id ]['last_event_time'] = $event['timestamp'];
                }
                
                $last_event_check = time();
            }
            
            if ( ( time() - $last_ping ) >= $ping_interval ) {
                $this->send_connection_event( 'ping', array(
                    'timestamp' => time(),
                ) );
                $last_ping = time();
                self::$connections[ $guest_token ][ $connection_id ]['last_ping'] = $last_ping;
            }
            
            if ( connection_aborted() ) {
                $this->unregister_connection( $guest_token, $connection_id );
                break;
            }
            
            usleep( 1000000 );
            
            if ( ob_get_level() > 0 ) {
                ob_flush();
            }
            flush();
        }
    }
    
    /**
     * Send event to all connections for a guest token
     *
     * @param string $guest_token Guest token
     * @param string $event_type Event type
     * @param array  $data Event data
     */
    public static function send_event( string $guest_token, string $event_type, array $data = array() ): void {
        if ( empty( $guest_token ) || empty( $event_type ) ) {
            return;
        }
        
        $token_hash = hash( 'sha256', $guest_token );
        $transient_key = 'aicommerce_sse_event_' . $token_hash . '_' . time() . '_' . wp_generate_password( 8, false );
        $event_data = array(
            'event_type'       => $event_type,
            'data'             => $data,
            'timestamp'        => time(),
            'guest_token_hash' => $token_hash,
        );
        
        set_transient( $transient_key, $event_data, 60 );
        
        $cache_key = 'aicommerce_sse_events_' . $token_hash;
        $cached_events = wp_cache_get( $cache_key, 'aicommerce' );
        if ( false === $cached_events ) {
            $cached_events = array();
        }
        $cached_events[] = array(
            'event_type' => $event_type,
            'data'       => $data,
            'timestamp'  => time(),
        );

        $cached_events = array_slice( $cached_events, -20 );
        wp_cache_set( $cache_key, $cached_events, 'aicommerce', 30 );
        
        do_action( 'aicommerce_sse_event_sent', $guest_token, $event_type, $data );
    }
    
    /**
     * Send SSE event (for active connection)
     *
     * @param string $event_type Event type
     * @param array  $data Event data
     */
    private function send_connection_event( string $event_type, array $data = array() ): void {
        $event_data = wp_json_encode( $data );
        
        echo "event: {$event_type}\n";
        echo "data: {$event_data}\n\n";
        
        if ( ob_get_level() > 0 ) {
            ob_flush();
        }
        flush();
    }
    
    /**
     * Unregister connection
     */
    private function unregister_connection( string $guest_token, string $connection_id ): void {
        if ( isset( self::$connections[ $guest_token ][ $connection_id ] ) ) {
            unset( self::$connections[ $guest_token ][ $connection_id ] );
        }
        
        if ( isset( self::$connections[ $guest_token ] ) && empty( self::$connections[ $guest_token ] ) ) {
            unset( self::$connections[ $guest_token ] );
        }
    }
    
    /**
     * Validate guest token format
     *
     * @param string $guest_token Guest token
     * @return bool True if valid
     */
    private function validate_guest_token( string $guest_token ): bool {
        if ( empty( $guest_token ) ) {
            return false;
        }
        
        return preg_match( '/^guest_\d+_[a-zA-Z0-9]+_[a-f0-9]{8}$/', $guest_token ) === 1;
    }
    
    /**
     * Get pending events for guest token
     * Optimized: Uses cache if available, limits query results
     *
     * @param string $guest_token Guest token
     * @param int    $since Timestamp to get events since
     * @return array Array of events
     */
    private static function get_pending_events( string $guest_token, int $since = 0 ): array {
        global $wpdb;
        
        $events = array();
        $token_hash = hash( 'sha256', $guest_token );
        
        $cache_key = 'aicommerce_sse_events_' . $token_hash;
        $cached_events = wp_cache_get( $cache_key, 'aicommerce' );
        
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
        
        $all_events = array();
        
        foreach ( $transients as $transient ) {
            $event_data = maybe_unserialize( $transient['option_value'] );
            
            if ( is_array( $event_data ) && isset( $event_data['timestamp'] ) && isset( $event_data['guest_token_hash'] ) ) {
                if ( $event_data['guest_token_hash'] === $token_hash && $event_data['timestamp'] > $since ) {
                    $all_events[] = array(
                        'event_type' => isset( $event_data['event_type'] ) ? $event_data['event_type'] : 'unknown',
                        'data'       => isset( $event_data['data'] ) ? $event_data['data'] : array(),
                        'timestamp'  => $event_data['timestamp'],
                    );
                }
            }
        }
        
        usort( $all_events, function( $a, $b ) {
            return $a['timestamp'] - $b['timestamp'];
        } );
        
        if ( ! empty( $all_events ) ) {
            wp_cache_set( $cache_key, $all_events, 'aicommerce', 30 );
        }
        
        return $all_events;
    }
}
