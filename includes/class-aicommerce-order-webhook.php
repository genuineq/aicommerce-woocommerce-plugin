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

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * OrderWebhook Class
 */
class OrderWebhook {

    const WEBHOOK_URL         = 'https://webhook.site/f65eb2d5-1d08-4813-b801-8b0ec5528e8a';
    const WEBHOOK_URL_STAGING = 'https://webhook.site/f65eb2d5-1d08-4813-b801-8b0ec5528e8a';

    const AS_HOOK  = 'aicommerce_order_webhook';
    const AS_GROUP = 'aicommerce';

    const DISPATCH_DELAY  = 5;
    const MAX_CONCURRENT  = 10;

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'woocommerce_checkout_order_created',          array( $this, 'on_order_created' ) );
        add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_order_created' ) );
        add_action( 'woocommerce_order_status_changed',    array( $this, 'on_order_status_changed' ), 10, 4 );
        add_action( self::AS_HOOK,                         array( $this, 'dispatch_webhook' ), 10, 2 );
    }

    /**
     * Fires when a new order is created at checkout.
     * Checks if the cart was AI-influenced, marks the order, and schedules webhook.
     */
    public function on_order_created( \WC_Order $order ): void {
        $guest_token = $this->detect_ai_cart( $order );

        if ( $guest_token === false ) {
            return;
        }

        $order->update_meta_data( '_aicommerce_order', '1' );

        if ( ! empty( $guest_token ) ) {
            $order->update_meta_data( '_aicommerce_guest_token', $guest_token );
        }

        $order->save_meta_data();

        $this->schedule( $order->get_id(), 'order.created' );
    }

    /**
     * Fires on every order status change.
     * Re-sends the webhook only for AI-influenced orders.
     */
    public function on_order_status_changed( int $order_id, string $old_status, string $new_status, \WC_Order $order ): void {
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
        $user_id = (int) $order->get_customer_id();

        if ( $user_id > 0 ) {
            return CartStorage::has_ai_user_flag( $user_id ) ? '' : false;
        }

        $guest_token = isset( $_COOKIE['aicommerce_guest_token'] )
            ? sanitize_text_field( wp_unslash( $_COOKIE['aicommerce_guest_token'] ) )
            : '';

        if ( empty( $guest_token ) ) {
            return false;
        }

        return CartStorage::has_ai_flag( $guest_token ) ? $guest_token : false;
    }

    /**
     * Schedule an async AS action. Deduplicates pending actions for the same order.
     */
    private function schedule( int $order_id, string $event ): void {
        if ( empty( self::get_url() ) ) {
            return;
        }

        if ( ! Settings::has_credentials() ) {
            return;
        }

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

        $payload  = $this->build_payload( $order_id, $event );
        $response = wp_remote_post(
            self::get_url(),
            array(
                'timeout'     => 15,
                'blocking'    => true,
                'redirection' => 3,
                'headers'     => array(
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ),
                'body'        => wp_json_encode( $payload ),
            )
        );

        if ( is_wp_error( $response ) ) {
            throw new \Exception(
                sprintf( '[AICommerce] Order webhook failed for order %d (%s): %s', $order_id, $event, $response->get_error_message() )
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            throw new \Exception(
                sprintf( '[AICommerce] Order webhook returned HTTP %d for order %d (%s)', $code, $order_id, $event )
            );
        }
    }

    /**
     * Build the full order payload.
     *
     * @throws \Exception If order cannot be loaded.
     */
    private function build_payload( int $order_id, string $event ): array {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            throw new \Exception( sprintf( '[AICommerce] Order %d not found for webhook', $order_id ) );
        }

        // ── Line items ────────────────────────────────────────────────────────
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

        // ── Dates ─────────────────────────────────────────────────────────────
        $date_created  = $order->get_date_created();
        $date_modified = $order->get_date_modified();

        return array(
            'event'          => $event,
            'site_url'       => get_site_url(),
            'timestamp'      => ( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) )->format( \DateTime::ATOM ),
            'api_key'        => Settings::get_api_key(),
            'api_secret'     => Settings::get_api_secret(),

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

    private static function get_url(): string {
        $api_key = Settings::get_api_key();
        return ( ! empty( $api_key ) && str_starts_with( $api_key, 'staging_' ) )
            ? self::WEBHOOK_URL_STAGING
            : self::WEBHOOK_URL;
    }
}
