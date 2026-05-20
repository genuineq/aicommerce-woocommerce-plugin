<?php
/**
 * Webhook delivery log helper.
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small rolling log for outgoing webhook deliveries.
 *
 * Stores the latest delivery attempts so failed webhook payloads can be
 * inspected and replayed without digging through Action Scheduler internals.
 */
class WebhookDeliveryLog {
	/**
	 * Option name used for the rolling delivery log.
	 */
	private const OPTION_NAME = 'aicommerce_webhook_delivery_log';

	/**
	 * Maximum number of deliveries kept in the rolling log.
	 */
	private const MAX_ITEMS = 100;

	/**
	 * Create or refresh a pending delivery entry.
	 *
	 * @param string $delivery_id Delivery ID.
	 * @param string $type        Webhook type (`product` / `order`).
	 * @param string $event       Event name.
	 * @param string $url         Destination URL.
	 * @param array  $payload     Outgoing payload.
	 * @return void
	 */
	public static function log_pending( string $delivery_id, string $type, string $event, string $url, array $payload ): void {
		$log   = self::get_log();
		$entry = isset( $log[ $delivery_id ] ) && is_array( $log[ $delivery_id ] ) ? $log[ $delivery_id ] : array();
		$safe_payload = self::redact_payload( $payload );

		$log[ $delivery_id ] = array(
			'delivery_id'     => $delivery_id,
			'type'            => $type,
			'event'           => $event,
			'status'          => 'pending',
			'url'             => $url,
			'payload'         => $safe_payload,
			'attempt_count'   => (int) ( $entry['attempt_count'] ?? 0 ) + 1,
			'created_at'      => (string) ( $entry['created_at'] ?? gmdate( 'c' ) ),
			'last_attempt_at' => gmdate( 'c' ),
			'http_code'       => null,
			'error_message'   => '',
			'delivered_at'    => null,
		);

		self::store_log( $log );
	}

	/**
	 * Redact secrets before persisting webhook payloads.
	 *
	 * @param array $payload Outgoing payload.
	 * @return array
	 */
	private static function redact_payload( array $payload ): array {
		if ( array_key_exists( 'api_secret', $payload ) ) {
			$payload['api_secret'] = '[redacted]';
		}

		return $payload;
	}

	/**
	 * Mark one delivery as successful.
	 *
	 * @param string $delivery_id Delivery ID.
	 * @param int    $http_code   HTTP status code.
	 * @return void
	 */
	public static function log_success( string $delivery_id, int $http_code ): void {
		$log = self::get_log();
		if ( ! isset( $log[ $delivery_id ] ) || ! is_array( $log[ $delivery_id ] ) ) {
			return;
		}

		$log[ $delivery_id ]['status']        = 'success';
		$log[ $delivery_id ]['http_code']     = $http_code;
		$log[ $delivery_id ]['error_message'] = '';
		$log[ $delivery_id ]['delivered_at']  = gmdate( 'c' );

		self::store_log( $log );
	}

	/**
	 * Mark one delivery as failed.
	 *
	 * @param string $delivery_id   Delivery ID.
	 * @param string $error_message Error description.
	 * @param int    $http_code     HTTP status code when available.
	 * @return void
	 */
	public static function log_failure( string $delivery_id, string $error_message, int $http_code = 0 ): void {
		$log = self::get_log();
		if ( ! isset( $log[ $delivery_id ] ) || ! is_array( $log[ $delivery_id ] ) ) {
			return;
		}

		$log[ $delivery_id ]['status']        = 'failed';
		$log[ $delivery_id ]['http_code']     = $http_code > 0 ? $http_code : null;
		$log[ $delivery_id ]['error_message'] = $error_message;

		self::store_log( $log );
	}

	/**
	 * Fetch one delivery entry by delivery ID.
	 *
	 * @param string $delivery_id Delivery ID.
	 * @return array|null
	 */
	public static function get_delivery( string $delivery_id ): ?array {
		$log = self::get_log();

		if ( ! isset( $log[ $delivery_id ] ) || ! is_array( $log[ $delivery_id ] ) ) {
			return null;
		}

		return $log[ $delivery_id ];
	}

	/**
	 * Return the most recent delivery entries.
	 *
	 * @param int $limit Maximum number of entries.
	 * @return array<int, array>
	 */
	public static function get_recent( int $limit = 20 ): array {
		$entries = array_values( self::get_log() );
		usort(
			$entries,
			static function ( array $left, array $right ): int {
				return strcmp( (string) ( $right['last_attempt_at'] ?? '' ), (string) ( $left['last_attempt_at'] ?? '' ) );
			}
		);

		return array_slice( $entries, 0, max( 1, $limit ) );
	}

	/**
	 * Read the current rolling log from wp_options.
	 *
	 * @return array<string, array>
	 */
	private static function get_log(): array {
		$log = get_option( self::OPTION_NAME, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Persist the rolling delivery log while keeping only recent entries.
	 *
	 * @param array<string, array> $log Log data.
	 * @return void
	 */
	private static function store_log( array $log ): void {
		if ( count( $log ) > self::MAX_ITEMS ) {
			uasort(
				$log,
				static function ( array $left, array $right ): int {
					return strcmp( (string) ( $right['last_attempt_at'] ?? '' ), (string) ( $left['last_attempt_at'] ?? '' ) );
				}
			);

			$log = array_slice( $log, 0, self::MAX_ITEMS, true );
		}

		update_option( self::OPTION_NAME, $log, false );
	}
}
