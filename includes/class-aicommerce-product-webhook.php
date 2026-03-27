<?php
/**
 * Product Webhook
 *
 * Detects product changes and schedules async HTTP delivery via
 * Action Scheduler (bundled with WooCommerce). AS runs on every page
 * request via the shutdown hook — no WP-Cron required.
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ProductWebhook Class
 */
class ProductWebhook {

    /** Third-party production API endpoint. */
    const WEBHOOK_URL = 'https://api.ai.genuineq.com/api/client/products-sync';

    /** Third-party staging API endpoint. */
    const WEBHOOK_URL_STAGING = 'https://api.ai.staging.genuineq.com/api/client/products-sync';

    /** Action Scheduler hook name for product events. */
    const AS_HOOK = 'aicommerce_product_webhook';

    /** Action Scheduler hook name for import batch webhook. */
    const AS_IMPORT_HOOK = 'aicommerce_import_webhook';

    /** Action Scheduler group name. */
    const AS_GROUP = 'aicommerce';

    /** Delay (seconds) before dispatching webhook to allow batching. */
    const DISPATCH_DELAY = 5;

    /** Maximum concurrent webhook executions allowed. */
    const MAX_CONCURRENT = 10;

    /** Tracks scheduled events within the same request to avoid duplicates. */
    private static array $scheduled_in_request = [];

    /** Indicates whether product import is currently in progress. */
    private static bool $in_import = false;

    /** Stores product IDs collected during import process. */
    private static array $imported_ids = [];

    /**
     * Resolve correct webhook URL based on API key.
     *
     * @return string
     */
    private static function get_url(): string {
        /** Retrieve API key from settings. */
        $api_key = Settings::get_api_key();

        /** Use staging URL if API key starts with "staging_". */
        return ( ! empty( $api_key ) && str_starts_with( $api_key, 'staging_' ) )
            ? self::WEBHOOK_URL_STAGING
            : self::WEBHOOK_URL;
    }

    /**
     * Constructor.
     *
     * Registers all WooCommerce and WordPress hooks.
     *
     * @return void
     */
    public function __construct() {
        /** Hook product creation event. */
        add_action( 'woocommerce_new_product', array( $this, 'on_product_created' ), 10, 2 );

        /** Hook product update event. */
        add_action( 'woocommerce_update_product', array( $this, 'on_product_updated' ), 10, 2 );

        /** Hook product trash event. */
        add_action( 'wp_trash_post', array( $this, 'on_product_trashed' ) );

        /** Hook product deletion event. */
        add_action( 'before_delete_post', array( $this, 'on_product_deleted' ) );

        /** Hook product restore event. */
        add_action( 'untrashed_post', array( $this, 'on_product_restored' ) );

        /** Hook import start event. */
        add_action( 'woocommerce_product_import_before_process_item', array( $this, 'on_import_started' ) );

        /** Hook import webhook dispatcher. */
        add_action( self::AS_IMPORT_HOOK, array( $this, 'dispatch_import_webhook' ) );

        /** Hook stock changes for simple products. */
        add_action( 'woocommerce_product_set_stock', array( $this, 'on_product_stock_changed' ) );

        /** Hook stock changes for variations. */
        add_action( 'woocommerce_variation_set_stock', array( $this, 'on_variation_stock_changed' ) );

        /** Register Action Scheduler worker. */
        add_action( self::AS_HOOK, array( $this, 'dispatch_webhook' ), 10, 3 );
    }

    /** Handle product creation event. */
    public function on_product_created( int $product_id, \WC_Product $product ): void {
        /** Collect IDs during import instead of scheduling immediately. */
        if ( self::$in_import ) {
            self::$imported_ids[] = $product_id;
            return;
        }

        /** Schedule webhook for product creation. */
        $this->schedule( $product_id, 'product.created' );
    }

    /** Handle product update event. */
    public function on_product_updated( int $product_id, \WC_Product $product ): void {
        /** Collect IDs during import instead of scheduling immediately. */
        if ( self::$in_import ) {
            self::$imported_ids[] = $product_id;
            return;
        }

        /** Schedule webhook for product update. */
        $this->schedule( $product_id, 'product.updated' );
    }

    /** Handle product trash event. */
    public function on_product_trashed( int $post_id ): void {
        /** Ensure post type is product. */
        if ( 'product' !== get_post_type( $post_id ) ) {
            return;
        }

        /** Schedule deletion webhook. */
        $this->schedule( $post_id, 'product.deleted' );
    }

    /** Handle product permanent deletion. */
    public function on_product_deleted( int $post_id ): void {
        /** Ensure post type is product. */
        if ( 'product' !== get_post_type( $post_id ) ) {
            return;
        }

        /** Schedule deletion webhook. */
        $this->schedule( $post_id, 'product.deleted' );
    }

    /** Handle product restore from trash. */
    public function on_product_restored( int $post_id ): void {
        /** Ensure post type is product. */
        if ( 'product' !== get_post_type( $post_id ) ) {
            return;
        }

        /** Schedule restore webhook. */
        $this->schedule( $post_id, 'product.restored' );
    }

    /** Mark import start and initialize collection. */
    public function on_import_started(): void {
        /** Initialize import tracking only once per request. */
        if ( ! self::$in_import ) {
            self::$imported_ids = [];

            /** Register shutdown hook to finalize import. */
            add_action( 'shutdown', array( $this, 'on_import_completed' ) );
        }

        /** Mark import state as active. */
        self::$in_import = true;
    }

    /** Handle import completion and dispatch batch webhook. */
    public function on_import_completed(): void {
        /** Deduplicate imported product IDs. */
        $ids = array_values( array_unique( self::$imported_ids ) );

        /** Reset import state. */
        self::$in_import    = false;
        self::$imported_ids = [];

        /** Skip if webhook URL is missing. */
        if ( empty( self::get_url() ) ) {
            return;
        }

        /** Skip if credentials are missing. */
        if ( ! Settings::has_credentials() ) {
            return;
        }

        /** Fallback to direct dispatch if Action Scheduler is unavailable. */
        if ( ! function_exists( 'as_schedule_single_action' ) ) {
            $this->dispatch_import_webhook( $ids );
            return;
        }

        /** Schedule batch import webhook. */
        as_schedule_single_action(
            time() + self::DISPATCH_DELAY,
            self::AS_IMPORT_HOOK,
            array( $ids ),
            self::AS_GROUP
        );
    }

    /**
     * Handle stock change for simple or parent products.
     *
     * @param \WC_Product $product
     */
    public function on_product_stock_changed( \WC_Product $product ): void {
        /** Schedule stock update webhook. */
        $this->schedule( $product->get_id(), 'product.stock_updated' );
    }

    /**
     * Handle stock change for variations.
     *
     * @param \WC_Product $variation
     */
    public function on_variation_stock_changed( \WC_Product $variation ): void {
        /** Retrieve parent product ID. */
        $parent_id = $variation->get_parent_id();

        /** Skip if no parent exists. */
        if ( ! $parent_id ) {
            return;
        }

        /** Schedule stock update webhook with variation ID. */
        $this->schedule( $parent_id, 'product.stock_updated', $variation->get_id() );
    }

    /**
     * Schedule webhook execution via Action Scheduler.
     *
     * @param int    $product_id
     * @param string $event
     * @param int    $variation_id
     * @return void
     */
    private function schedule( int $product_id, string $event, int $variation_id = 0 ): void {
        /** Skip scheduling during import. */
        if ( self::$in_import ) {
            return;
        }

        /** Skip if webhook URL is missing. */
        if ( empty( self::get_url() ) ) {
            return;
        }

        /** Skip if credentials are missing. */
        if ( ! Settings::has_credentials() ) {
            return;
        }

        /** Deduplicate scheduling within same request. */
        if ( ! in_array( $event, array( 'product.deleted', 'product.restored' ), true ) ) {
            $request_key = $product_id . '|' . $variation_id;

            if ( isset( self::$scheduled_in_request[ $request_key ] ) ) {
                return;
            }

            self::$scheduled_in_request[ $request_key ] = true;
        }

        /** Fallback to immediate dispatch if AS unavailable. */
        if ( ! function_exists( 'as_schedule_single_action' ) ) {
            $this->dispatch_webhook( $product_id, $event, $variation_id );
            return;
        }

        /** Build action arguments. */
        $args = array( $product_id, $event, $variation_id );

        /** Check for already pending identical actions. */
        $pending = as_get_scheduled_actions(
            array(
                'hook'   => self::AS_HOOK,
                'args'   => $args,
                'status' => \ActionScheduler_Store::STATUS_PENDING,
                'group'  => self::AS_GROUP,
            ),
            'ids'
        );

        /** Skip scheduling if already pending. */
        if ( ! empty( $pending ) ) {
            return;
        }

        /** Schedule new Action Scheduler job. */
        as_schedule_single_action(
            time() + self::DISPATCH_DELAY,
            self::AS_HOOK,
            $args,
            self::AS_GROUP
        );
    }

    /**
     * Dispatch webhook via HTTP POST.
     *
     * @param int    $product_id
     * @param string $event
     * @param int    $variation_id
     * @throws \Exception
     */
    public function dispatch_webhook( int $product_id, string $event, int $variation_id = 0 ): void {
        /** Enforce concurrency limit. */
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
                    sprintf(
                        '[AICommerce] Concurrency limit (%d) reached — will retry. Product %d (%s)',
                        self::MAX_CONCURRENT,
                        $product_id,
                        $event
                    )
                );
            }
        }

        /** Build webhook payload. */
        $payload = $this->build_payload( $product_id, $event, $variation_id );

        /** Send HTTP POST request. */
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

        /** Handle WP error response. */
        if ( is_wp_error( $response ) ) {
            throw new \Exception(
                sprintf(
                    '[AICommerce] Webhook failed for product %d (%s): %s',
                    $product_id,
                    $event,
                    $response->get_error_message()
                )
            );
        }

        /** Validate HTTP response code. */
        $code = wp_remote_retrieve_response_code( $response );

        if ( $code < 200 || $code >= 300 ) {
            throw new \Exception(
                sprintf(
                    '[AICommerce] Webhook returned HTTP %d for product %d (%s)',
                    $code,
                    $product_id,
                    $event
                )
            );
        }
    }

    /**
     * Dispatch import webhook.
     *
     * @param array $product_ids
     * @throws \Exception
     */
    public function dispatch_import_webhook( array $product_ids ): void {
        /** Build payload for import event. */
        $payload = array(
            'event'       => 'products.imported',
            'product_ids' => $product_ids,
            'site_url'    => get_site_url(),
            'timestamp'   => ( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) )->format( \DateTime::ATOM ),
            'api_key'     => Settings::get_api_key(),
            'api_secret'  => Settings::get_api_secret(),
        );

        /** Send HTTP POST request. */
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

        /** Handle WP error. */
        if ( is_wp_error( $response ) ) {
            throw new \Exception( '[AICommerce] Import webhook failed: ' . $response->get_error_message() );
        }

        /** Validate HTTP response code. */
        $code = wp_remote_retrieve_response_code( $response );

        if ( $code < 200 || $code >= 300 ) {
            throw new \Exception( sprintf( '[AICommerce] Import webhook returned HTTP %d', $code ) );
        }
    }

    /**
     * Build webhook payload.
     *
     * @param int    $product_id
     * @param string $event
     * @param int    $variation_id
     * @return array
     */
    private function build_payload( int $product_id, string $event, int $variation_id = 0 ): array {
        /** Base payload structure. */
        $payload = array(
            'event'      => $event,
            'site_url'   => get_site_url(),
            'timestamp'  => ( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) )->format( \DateTime::ATOM ),
            'api_key'    => Settings::get_api_key(),
            'api_secret' => Settings::get_api_secret(),
        );

        /** Handle import event (no product-specific data). */
        if ( 'products.imported' === $event ) {
            return $payload;
        }

        /** Handle deleted product (no WC object available). */
        if ( 'product.deleted' === $event ) {
            $payload['product_ids']  = array( $product_id );
            $payload['product_type'] = 'unknown';
            return $payload;
        }

        /** Handle variation event. */
        if ( $variation_id > 0 ) {
            $payload['product_ids']  = array( $product_id );
            $payload['variation_id'] = $variation_id;
            $payload['product_type'] = 'variation';
            return $payload;
        }

        /** Resolve product type from WooCommerce object. */
        $product = wc_get_product( $product_id );

        $payload['product_ids']  = array( $product_id );
        $payload['product_type'] = $product ? $product->get_type() : 'unknown';

        return $payload;
    }
}
