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

/** OrderWebhook class. */
class OrderWebhook {

    /** Production webhook URL. */
    const WEBHOOK_URL         = 'https://webhook.site/f65eb2d5-1d08-4813-b801-8b0ec5528e8a';

    /** Staging webhook URL. */
    const WEBHOOK_URL_STAGING = 'https://webhook.site/f65eb2d5-1d08-4813-b801-8b0ec5528e8a';

    /** Action Scheduler hook name for order webhook dispatch. */
    const AS_HOOK  = 'aicommerce_order_webhook';

    /** Action Scheduler group name. */
    const AS_GROUP = 'aicommerce';

    /** Delay in seconds before dispatching the scheduled webhook. */
    const DISPATCH_DELAY  = 5;

    /** Maximum number of concurrently running webhook jobs. */
    const MAX_CONCURRENT  = 10;

    /**
     * Constructor.
     */
    public function __construct() {
        /** Hook into classic checkout order creation. */
        add_action( 'woocommerce_checkout_order_created', array( $this, 'on_order_created' ) );

        /** Hook into Store API checkout order processing. */
        add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_order_created' ) );

        /** Hook into WooCommerce order status changes. */
        add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 4 );

        /** Register the Action Scheduler webhook dispatcher callback. */
        add_action( self::AS_HOOK, array( $this, 'dispatch_webhook' ), 10, 2 );
    }

    /**
     * Handle new order creation at checkout.
     *
     * Checks whether the order was AI-influenced, marks it, stores guest token
     * metadata when available, and schedules the webhook dispatch.
     *
     * @param \WC_Order $order The created WooCommerce order.
     * @return void
     */
    public function on_order_created( \WC_Order $order ): void {
        /** Detect whether the current order came from an AI-influenced cart. */
        $guest_token = $this->detect_ai_cart( $order );

        /** Stop when the order is not AI-influenced. */
        if ( $guest_token === false ) {
            return;
        }

        /** Mark the order as AI-influenced. */
        $order->update_meta_data( '_aicommerce_order', '1' );

        /** Store the guest token on the order when one exists. */
        if ( ! empty( $guest_token ) ) {
            $order->update_meta_data( '_aicommerce_guest_token', $guest_token );
        }

        /** Persist the order metadata changes. */
        $order->save_meta_data();

        /** Schedule the order.created webhook event. */
        $this->schedule( $order->get_id(), 'order.created' );
    }

    /**
     * Handle order status changes.
     *
     * Re-sends the webhook only for AI-influenced orders.
     *
     * @param int       $order_id The order ID.
     * @param string    $old_status The previous order status.
     * @param string    $new_status The new order status.
     * @param \WC_Order $order The WooCommerce order object.
     * @return void
     */
    public function on_order_status_changed( int $order_id, string $old_status, string $new_status, \WC_Order $order ): void {
        /** Stop when the order is not marked as AI-influenced. */
        if ( ! $order->get_meta( '_aicommerce_order' ) ) {
            return;
        }

        /** Schedule the order.status_changed webhook event. */
        $this->schedule( $order_id, 'order.status_changed' );
    }

    /**
     * Determine whether the current order came from an AI-influenced cart.
     *
     * Returns:
     *   string  — guest token if guest AI cart detected
     *   ''      — empty string if logged-in user AI cart detected
     *   false   — not an AI order
     *
     * @param \WC_Order $order The WooCommerce order object.
     * @return string|false
     */
    private function detect_ai_cart( \WC_Order $order ) {
        /** Get the customer ID associated with the order. */
        $user_id = (int) $order->get_customer_id();

        /** For logged-in users, return an empty string when the user cart has the AI flag. */
        if ( $user_id > 0 ) {
            return CartStorage::has_ai_user_flag( $user_id ) ? '' : false;
        }

        /** Read the guest token from the AICommerce cookie when available. */
        $guest_token = isset( $_COOKIE['aicommerce_guest_token'] )
            ? sanitize_text_field( wp_unslash( $_COOKIE['aicommerce_guest_token'] ) )
            : '';

        /** Return false when no guest token exists. */
        if ( empty( $guest_token ) ) {
            return false;
        }

        /** Return the guest token only when the guest cart has the AI flag. */
        return CartStorage::has_ai_flag( $guest_token ) ? $guest_token : false;
    }

    /**
     * Schedule an asynchronous Action Scheduler job for webhook dispatch.
     *
     * Deduplicates pending actions for the same order and event pair.
     *
     * @param int    $order_id The order ID.
     * @param string $event The webhook event name.
     * @return void
     */
    private function schedule( int $order_id, string $event ): void {
        /** Stop when no webhook URL is configured for the current environment. */
        if ( empty( self::get_url() ) ) {
            return;
        }

        /** Stop when API credentials are missing. */
        if ( ! Settings::has_credentials() ) {
            return;
        }

        /** Dispatch immediately when Action Scheduler is not available. */
        if ( ! function_exists( 'as_schedule_single_action' ) ) {
            $this->dispatch_webhook( $order_id, $event );
            return;
        }

        /** Build the action arguments used for deduplication and scheduling. */
        $args    = array( $order_id, $event );

        /** Look for already pending actions with the same hook, args, and group. */
        $pending = as_get_scheduled_actions(
            array(
                /** Match the configured Action Scheduler hook. */
                'hook'   => self::AS_HOOK,

                /** Match the exact order/event argument pair. */
                'args'   => $args,

                /** Restrict results to pending actions. */
                'status' => \ActionScheduler_Store::STATUS_PENDING,

                /** Restrict results to the plugin action group. */
                'group'  => self::AS_GROUP,
            ),
            'ids'
        );

        /** Stop when a matching pending action already exists. */
        if ( ! empty( $pending ) ) {
            return;
        }

        /** Schedule the webhook action with a short dispatch delay. */
        as_schedule_single_action(
            time() + self::DISPATCH_DELAY,
            self::AS_HOOK,
            $args,
            self::AS_GROUP
        );
    }

    /**
     * Dispatch the order webhook via HTTP POST.
     *
     * Called by Action Scheduler. Throws exceptions on failure so Action
     * Scheduler can retry the job.
     *
     * @param int    $order_id The order ID.
     * @param string $event The webhook event name.
     * @return void
     * @throws \Exception When concurrency limit is reached or the HTTP request fails.
     */
    public function dispatch_webhook( int $order_id, string $event ): void {
        /** Enforce a maximum number of concurrently running webhook jobs when Action Scheduler is available. */
        if ( function_exists( 'as_get_scheduled_actions' ) ) {
            /** Load currently running webhook actions. */
            $running = as_get_scheduled_actions(
                array(
                    /** Match the configured Action Scheduler hook. */
                    'hook'   => self::AS_HOOK,

                    /** Restrict results to running actions. */
                    'status' => \ActionScheduler_Store::STATUS_RUNNING,

                    /** Restrict results to the plugin action group. */
                    'group'  => self::AS_GROUP,
                ),
                'ids'
            );

            /** Throw when the concurrency limit has been reached so the job can be retried later. */
            if ( count( $running ) >= self::MAX_CONCURRENT ) {
                throw new \Exception(
                    sprintf( '[AICommerce] Order webhook concurrency limit reached — will retry. Order %d (%s)', $order_id, $event )
                );
            }
        }

        /** Build the outgoing webhook payload for the order and event. */
        $payload  = $this->build_payload( $order_id, $event );

        /** Send the webhook request to the configured endpoint. */
        $response = wp_remote_post(
            self::get_url(),
            array(
                /** Set the HTTP timeout in seconds. */
                'timeout'     => 15,

                /** Send the request synchronously. */
                'blocking'    => true,

                /** Allow up to three redirects. */
                'redirection' => 3,

                /** Set the request headers. */
                'headers'     => array(
                    /** Send JSON request bodies. */
                    'Content-Type' => 'application/json',

                    /** Expect a JSON response. */
                    'Accept'       => 'application/json',
                ),

                /** Encode the payload as JSON for the request body. */
                'body'        => wp_json_encode( $payload ),
            )
        );

        /** Throw when the HTTP request returns a WP_Error instance. */
        if ( is_wp_error( $response ) ) {
            throw new \Exception(
                sprintf( '[AICommerce] Order webhook failed for order %d (%s): %s', $order_id, $event, $response->get_error_message() )
            );
        }

        /** Get the HTTP response code. */
        $code = wp_remote_retrieve_response_code( $response );

        /** Throw when the endpoint does not return a 2xx success code. */
        if ( $code < 200 || $code >= 300 ) {
            throw new \Exception(
                sprintf( '[AICommerce] Order webhook returned HTTP %d for order %d (%s)', $code, $order_id, $event )
            );
        }
    }

    /**
     * Build the full webhook payload for an order.
     *
     * @param int    $order_id The order ID.
     * @param string $event The webhook event name.
     * @return array
     * @throws \Exception When the order cannot be loaded.
     */
    private function build_payload( int $order_id, string $event ): array {
        /** Load the WooCommerce order object. */
        $order = wc_get_order( $order_id );

        /** Throw when the order cannot be found. */
        if ( ! $order ) {
            throw new \Exception( sprintf( '[AICommerce] Order %d not found for webhook', $order_id ) );
        }

        /** Initialize the line items array. */
        $line_items = array();

        /** Convert each order item into the outgoing webhook line item structure. */
        foreach ( $order->get_items() as $item ) {
            /** Append normalized line item data to the payload. */
            $line_items[] = array(
                /** Include the parent product ID. */
                'product_id'   => $item->get_product_id(),

                /** Include the variation ID when present. */
                'variation_id' => $item->get_variation_id() ?: null,

                /** Include the purchased item name. */
                'name'         => $item->get_name(),

                /** Include the SKU when available. */
                'sku'          => ( $item->get_product() ) ? $item->get_product()->get_sku() : '',

                /** Include the ordered quantity. */
                'quantity'     => $item->get_quantity(),

                /** Include the line subtotal. */
                'subtotal'     => $item->get_subtotal(),

                /** Include the line total. */
                'total'        => $item->get_total(),

                /** Include the line tax amount. */
                'tax'          => $item->get_total_tax(),
            );
        }

        /** Load the order creation date object. */
        $date_created  = $order->get_date_created();

        /** Load the order modification date object. */
        $date_modified = $order->get_date_modified();

        /** Return the complete webhook payload. */
        return array(
            /** Include the webhook event name. */
            'event'          => $event,

            /** Include the current site URL. */
            'site_url'       => get_site_url(),

            /** Include the current UTC timestamp in ISO 8601 format. */
            'timestamp'      => ( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) )->format( \DateTime::ATOM ),

            /** Include the configured API key. */
            'api_key'        => Settings::get_api_key(),

            /** Include the configured API secret. */
            'api_secret'     => Settings::get_api_secret(),

            /** Include the WooCommerce order ID. */
            'order_id'       => $order_id,

            /** Include the WooCommerce order number. */
            'order_number'   => $order->get_order_number(),

            /** Include the current order status. */
            'status'         => $order->get_status(),

            /** Include the order creation timestamp. */
            'date_created'   => $date_created  ? $date_created->date( 'c' )  : null,

            /** Include the order modification timestamp. */
            'date_modified'  => $date_modified ? $date_modified->date( 'c' ) : null,

            /** Include customer identity data. */
            'customer'       => array(
                /** Include the customer user ID when available. */
                'user_id'    => $order->get_customer_id() ?: null,

                /** Include the billing email address. */
                'email'      => $order->get_billing_email(),

                /** Include the billing first name. */
                'first_name' => $order->get_billing_first_name(),

                /** Include the billing last name. */
                'last_name'  => $order->get_billing_last_name(),
            ),

            /** Include the guest token when one was stored on the order. */
            'guest_token'    => $order->get_meta( '_aicommerce_guest_token' ) ?: null,

            /** Include billing address data. */
            'billing'        => array(
                /** Include billing first name. */
                'first_name' => $order->get_billing_first_name(),

                /** Include billing last name. */
                'last_name'  => $order->get_billing_last_name(),

                /** Include billing company. */
                'company'    => $order->get_billing_company(),

                /** Include billing address line 1. */
                'address_1'  => $order->get_billing_address_1(),

                /** Include billing address line 2. */
                'address_2'  => $order->get_billing_address_2(),

                /** Include billing city. */
                'city'       => $order->get_billing_city(),

                /** Include billing state. */
                'state'      => $order->get_billing_state(),

                /** Include billing postcode. */
                'postcode'   => $order->get_billing_postcode(),

                /** Include billing country. */
                'country'    => $order->get_billing_country(),

                /** Include billing email. */
                'email'      => $order->get_billing_email(),

                /** Include billing phone number. */
                'phone'      => $order->get_billing_phone(),
            ),

            /** Include shipping address data. */
            'shipping'       => array(
                /** Include shipping first name. */
                'first_name' => $order->get_shipping_first_name(),

                /** Include shipping last name. */
                'last_name'  => $order->get_shipping_last_name(),

                /** Include shipping company. */
                'company'    => $order->get_shipping_company(),

                /** Include shipping address line 1. */
                'address_1'  => $order->get_shipping_address_1(),

                /** Include shipping address line 2. */
                'address_2'  => $order->get_shipping_address_2(),

                /** Include shipping city. */
                'city'       => $order->get_shipping_city(),

                /** Include shipping state. */
                'state'      => $order->get_shipping_state(),

                /** Include shipping postcode. */
                'postcode'   => $order->get_shipping_postcode(),

                /** Include shipping country. */
                'country'    => $order->get_shipping_country(),
            ),

            /** Include order line items. */
            'line_items'     => $line_items,

            /** Include monetary totals. */
            'totals'         => array(
                /** Include subtotal amount. */
                'subtotal'      => $order->get_subtotal(),

                /** Include discount total. */
                'discount'      => $order->get_discount_total(),

                /** Include shipping total. */
                'shipping'      => $order->get_shipping_total(),

                /** Include total tax amount. */
                'tax'           => $order->get_total_tax(),

                /** Include final order total. */
                'total'         => $order->get_total(),

                /** Include currency code. */
                'currency'      => $order->get_currency(),
            ),

            /** Include payment method ID. */
            'payment_method' => $order->get_payment_method(),

            /** Include payment method title. */
            'payment_title'  => $order->get_payment_method_title(),

            /** Include customer note. */
            'customer_note'  => $order->get_customer_note(),
        );
    }

    /**
     * Resolve the webhook URL based on the current API key.
     *
     * @return string
     */
    private static function get_url(): string {
        /** Load the configured API key. */
        $api_key = Settings::get_api_key();

        /** Return the staging webhook URL when the API key starts with the staging prefix, otherwise return production URL. */
        return ( ! empty( $api_key ) && str_starts_with( $api_key, 'staging_' ) )
            ? self::WEBHOOK_URL_STAGING
            : self::WEBHOOK_URL;
    }
}
