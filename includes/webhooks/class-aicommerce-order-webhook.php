<?php
/**
 * Order Webhook
 *
 * Detects AI-influenced orders and sends full order data to the external API
 * via Action Scheduler. An order is considered AI-influenced when the cart
 * was modified through the AICommerce REST API before checkout.
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order webhook dispatcher.
 *
 * Tracks AI-influenced orders, marks them in order meta, and sends their
 * lifecycle updates to the external platform through Action Scheduler.
 */
class OrderWebhook {

    const WEBHOOK_URL         = 'https://api.ai.genuineq.com/api/client/orders-sync';
    const WEBHOOK_URL_STAGING = 'https://api.ai.staging.genuineq.com/api/client/orders-sync';

    const AS_HOOK  = 'aicommerce_order_webhook';
    const AS_GROUP = 'aicommerce';

    const DISPATCH_DELAY  = 5;
    const MAX_CONCURRENT  = 10;

	/**
	 * Constructor.
	 *
	 * Registers WooCommerce checkout hooks and the Action Scheduler worker used
	 * for asynchronous order webhook delivery.
	 */
	public function __construct() {
		add_action( 'woocommerce_checkout_order_created', array( $this, 'on_order_created' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_order_created' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 4 );
		add_action( self::AS_HOOK, array( $this, 'dispatch_webhook' ), 10, 2 );
	}

    /**
     * Fires when a new order is created at checkout.
     * Checks if the cart was AI-influenced, marks the order, and schedules webhook.
     */
	public function on_order_created( \WC_Order $order ): void {
		/** Detect whether the checkout originated from an AI-influenced cart. */
		$guest_token = $this->detect_ai_cart( $order );

		if ( false === $guest_token ) {
			return;
		}

		/** Persist an order-level marker so later status changes can be filtered. */
		$order->update_meta_data( '_aicommerce_order', '1' );

		/** Store guest token only for guest-originated AI carts. */
		if ( ! empty( $guest_token ) ) {
			$order->update_meta_data( '_aicommerce_guest_token', $guest_token );
		}

		$order->save_meta_data();

		$this->clear_ai_cart_after_order( $order, $guest_token );

		$this->schedule( $order->get_id(), 'order.created' );
	}

    /**
     * Fires on every order status change.
     * Re-sends the webhook only for AI-influenced orders.
     */
	public function on_order_status_changed( int $order_id, string $old_status, string $new_status, \WC_Order $order ): void {
		/** Only resend status changes for orders already marked as AI-influenced. */
		if ( ! $order->get_meta( '_aicommerce_order' ) ) {
			return;
		}

		$this->schedule( $order_id, 'order.status_changed' );
	}

    /**
     * Determines if the current cart was AI-influenced.
     *
     * Returns:
     *   string  — guest token (non-empty) if guest AI cart detected
     *   ''      — empty string if logged-in user AI cart detected
     *   false   — not an AI order
     */
	private function detect_ai_cart( \WC_Order $order ) {
		/** Logged-in users are detected through the persisted AI user flag. */
		$user_id = (int) $order->get_customer_id();

		if ( $user_id > 0 ) {
			return CartStorage::has_ai_user_flag( $user_id ) ? '' : false;
		}

		/** Guests are matched by guest token stored in the browser cookie. */
		$guest_token = isset( $_COOKIE['aicommerce_guest_token'] )
			? sanitize_text_field( wp_unslash( $_COOKIE['aicommerce_guest_token'] ) )
			: '';

		if ( empty( $guest_token ) ) {
			return false;
		}

		return CartStorage::has_ai_flag( $guest_token ) ? $guest_token : false;
	}

	/**
	 * Clear AICommerce's persisted cart once WooCommerce has created the order.
	 *
	 * WooCommerce clears its own session cart after checkout, but AICommerce keeps
	 * a separate cart projection by guest token or user ID. If that projection is
	 * left behind, a later sync can re-import already-purchased products.
	 *
	 * @param \WC_Order   $order       Created order.
	 * @param string|null $guest_token Guest token for guest AI orders; empty for logged-in AI orders.
	 */
	private function clear_ai_cart_after_order( \WC_Order $order, ?string $guest_token ): void {
		$user_id = (int) $order->get_customer_id();

		if ( $user_id > 0 ) {
			CartStorage::delete_user_cart( $user_id );
			return;
		}

		if ( ! empty( $guest_token ) ) {
			CartStorage::delete_cart( $guest_token );
		}
	}

    /**
     * Schedule an async AS action. Deduplicates pending actions for the same order.
     */
	private function schedule( int $order_id, string $event ): void {
		/** Stop when webhook delivery is not configured. */
		if ( empty( self::get_url() ) ) {
			return;
		}

		/** Stop when API credentials are not configured. */
		if ( ! Settings::has_credentials() ) {
			return;
		}

		/** Fall back to immediate delivery when Action Scheduler is unavailable. */
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->dispatch_webhook( $order_id, $event );
			return;
		}

		$args    = array( $order_id, $event );
		$pending = as_get_scheduled_actions(
			array(
				'hook'   => self::AS_HOOK,
				'args'   => $args,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
				'group'  => self::AS_GROUP,
			),
			'ids'
		);

		/** Skip duplicate pending deliveries for the same order event. */
		if ( ! empty( $pending ) ) {
			return;
		}

		as_schedule_single_action(
			time() + self::DISPATCH_DELAY,
			self::AS_HOOK,
			$args,
			self::AS_GROUP
		);
	}

    /**
     * Perform the HTTP POST. Called by Action Scheduler.
     *
     * @throws \Exception On HTTP failure so AS can retry.
     */
	public function dispatch_webhook( int $order_id, string $event ): void {
		/** Back off when too many order webhook workers are already running. */
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$running = as_get_scheduled_actions(
				array(
					'hook'   => self::AS_HOOK,
					'status' => \ActionScheduler_Store::STATUS_RUNNING,
					'group'  => self::AS_GROUP,
				),
				'ids'
			);

			if ( count( $running ) >= self::MAX_CONCURRENT ) {
				throw new \Exception(
					sprintf( '[AICommerce] Order webhook concurrency limit reached — will retry. Order %d (%s)', $order_id, $event )
				);
			}
		}

		/** Build full order payload at dispatch time to capture latest state. */
		$delivery_id = $this->generate_delivery_id( $order_id, $event );
		$payload     = $this->build_payload( $order_id, $event, $delivery_id );

		$this->send_webhook_request( $payload, $event, $delivery_id );
    }

    /**
     * Build the full order payload.
     *
     * @throws \Exception If order cannot be loaded.
     */
	private function build_payload( int $order_id, string $event, string $delivery_id = '' ): array {
		/** Load order lazily so the payload reflects the latest saved state. */
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			throw new \Exception( sprintf( '[AICommerce] Order %d not found for webhook', $order_id ) );
		}

		/** Normalize line items into a transport-safe payload structure. */
		$line_items = array();
		foreach ( $order->get_items() as $item ) {
			/** @var \WC_Order_Item_Product $item */
			$line_items[] = array(
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id() ?: null,
				'name'         => $item->get_name(),
				'sku'          => ( $item->get_product() ) ? $item->get_product()->get_sku() : '',
				'quantity'     => $item->get_quantity(),
				'subtotal'     => $item->get_subtotal(),
				'total'        => $item->get_total(),
				'tax'          => $item->get_total_tax(),
			);
		}

		/** Serialize order dates only when Woo has them available. */
		$date_created  = $order->get_date_created();
		$date_modified = $order->get_date_modified();

		return array(
			'event'          => $event,
			'delivery_id'    => $delivery_id,
			'event_id'       => sprintf( 'order_%d_%s', $order_id, str_replace( '.', '_', $event ) ),
			'event_version'  => 1,
			'site_url'       => get_site_url(),
			'timestamp'      => ( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) )->format( \DateTime::ATOM ),
			'api_key'        => Settings::get_api_key(),

            'order_id'       => $order_id,
            'order_number'   => $order->get_order_number(),
            'status'         => $order->get_status(),
            'date_created'   => $date_created  ? $date_created->date( 'c' )  : null,
            'date_modified'  => $date_modified ? $date_modified->date( 'c' ) : null,

            'customer'       => array(
                'user_id'    => $order->get_customer_id() ?: null,
                'email'      => $order->get_billing_email(),
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
            ),

            'guest_token'    => $order->get_meta( '_aicommerce_guest_token' ) ?: null,

            'billing'        => array(
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'company'    => $order->get_billing_company(),
                'address_1'  => $order->get_billing_address_1(),
                'address_2'  => $order->get_billing_address_2(),
                'city'       => $order->get_billing_city(),
                'state'      => $order->get_billing_state(),
                'postcode'   => $order->get_billing_postcode(),
                'country'    => $order->get_billing_country(),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
            ),

            'shipping'       => array(
                'first_name' => $order->get_shipping_first_name(),
                'last_name'  => $order->get_shipping_last_name(),
                'company'    => $order->get_shipping_company(),
                'address_1'  => $order->get_shipping_address_1(),
                'address_2'  => $order->get_shipping_address_2(),
                'city'       => $order->get_shipping_city(),
                'state'      => $order->get_shipping_state(),
                'postcode'   => $order->get_shipping_postcode(),
                'country'    => $order->get_shipping_country(),
            ),

            'line_items'     => $line_items,

            'totals'         => array(
                'subtotal'      => $order->get_subtotal(),
                'discount'      => $order->get_discount_total(),
                'shipping'      => $order->get_shipping_total(),
                'tax'           => $order->get_total_tax(),
                'total'         => $order->get_total(),
                'currency'      => $order->get_currency(),
            ),

            'payment_method' => $order->get_payment_method(),
            'payment_title'  => $order->get_payment_method_title(),
            'customer_note'  => $order->get_customer_note(),
        );
    }

	/**
	 * Resolve the correct webhook URL for the current environment.
	 *
	 * @return string Production or staging webhook URL.
	 */
	private static function get_url(): string {
		$api_key = Settings::get_api_key();

		return ( ! empty( $api_key ) && 0 === strpos( $api_key, 'staging_' ) )
			? self::WEBHOOK_URL_STAGING
			: self::WEBHOOK_URL;
	}

	/**
	 * Replay one logged order delivery by its delivery ID.
	 *
	 * @param string $delivery_id Delivery ID.
	 * @return bool
	 */
	public static function replay_delivery( string $delivery_id ): bool {
		$entry = WebhookDeliveryLog::get_delivery( $delivery_id );
		if ( ! is_array( $entry ) || ( $entry['type'] ?? '' ) !== 'order' ) {
			return false;
		}

		$payload = isset( $entry['payload'] ) && is_array( $entry['payload'] ) ? $entry['payload'] : array();
		$event   = isset( $entry['event'] ) ? (string) $entry['event'] : '';
		if ( empty( $payload ) || '' === $event ) {
			return false;
		}

		$instance = new self();
		$instance->send_webhook_request( $payload, $event, $delivery_id );

		return true;
	}

	/**
	 * Generate a unique delivery ID for one outgoing order webhook.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $event Event name.
	 * @return string
	 */
	private function generate_delivery_id( int $order_id, string $event ): string {
		return sprintf(
			'order_%d_%s_%s',
			$order_id,
			md5( $event ),
			wp_generate_password( 12, false )
		);
	}

	/**
	 * Build signed headers for outgoing order webhooks.
	 *
	 * @param string $event Event name.
	 * @param string $delivery_id Delivery ID.
	 * @param string $timestamp ISO timestamp.
	 * @param string $body JSON body.
	 * @return array<string,string>
	 */
	private function build_request_headers( string $event, string $delivery_id, string $timestamp, string $body ): array {
		$api_key    = Settings::get_api_key();
		$api_secret = Settings::get_api_secret();
		$signature  = hash_hmac( 'sha256', implode( "\n", array( $event, $delivery_id, $timestamp, get_site_url(), $body ) ), $api_secret );

		return array(
			'Content-Type'             => 'application/json',
			'Accept'                   => 'application/json',
			'X-AICommerce-Api-Key'     => $api_key,
			'X-AICommerce-Event'       => $event,
			'X-AICommerce-Delivery-Id' => $delivery_id,
			'X-AICommerce-Timestamp'   => $timestamp,
			'X-AICommerce-Signature'   => $signature,
		);
	}

	/**
	 * Send one signed order webhook request.
	 *
	 * @param array  $payload Payload body.
	 * @param string $event Event name.
	 * @param string $delivery_id Delivery ID.
	 * @return void
	 * @throws \Exception On HTTP failure.
	 */
	private function send_webhook_request( array $payload, string $event, string $delivery_id ): void {
		$body      = wp_json_encode( $payload );
		$timestamp = isset( $payload['timestamp'] ) ? (string) $payload['timestamp'] : gmdate( 'c' );
		$url       = self::get_url();

		WebhookDeliveryLog::log_pending( $delivery_id, 'order', $event, $url, $payload );

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 15,
				'blocking'    => true,
				'redirection' => 3,
				'headers'     => $this->build_request_headers( $event, $delivery_id, $timestamp, (string) $body ),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WebhookDeliveryLog::log_failure( $delivery_id, $response->get_error_message() );
			throw new \Exception( '[AICommerce] Order webhook failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			WebhookDeliveryLog::log_failure( $delivery_id, sprintf( 'HTTP %d', $code ), $code );
			throw new \Exception( sprintf( '[AICommerce] Order webhook returned HTTP %d for %s', $code, $event ) );
		}

		WebhookDeliveryLog::log_success( $delivery_id, $code );
	}
}
