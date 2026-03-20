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

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ProductWebhook Class
 */
class ProductWebhook {

    /**
     * Third-party API endpoint.
     * Replace with the real URL when available.
     */
    const WEBHOOK_URL = 'https://your-api-endpoint.example.com/webhooks/product';

    /**
     * Action Scheduler hook name.
     */
    const AS_HOOK = 'aicommerce_product_webhook';

    /**
     * Action Scheduler group name.
     */
    const AS_GROUP = 'aicommerce';

    /**
     * Seconds to wait before dispatching after a product save.
     * Batches rapid consecutive saves (e.g. bulk edit) into one webhook.
     */
    const DISPATCH_DELAY = 5;

    /**
     * Maximum number of webhook HTTP requests allowed to run concurrently.
     * If this limit is reached, AS retries the action later with back-off.
     */
    const MAX_CONCURRENT = 10;

    /**
     * Constructor
     */
    public function __construct() {
        // Product create / update
        add_action( 'woocommerce_new_product',    array( $this, 'on_product_created' ), 10, 2 );
        add_action( 'woocommerce_update_product', array( $this, 'on_product_updated' ), 10, 2 );

        // Product trash / delete / restore
        add_action( 'wp_trash_post',      array( $this, 'on_product_trashed' ) );
        add_action( 'before_delete_post', array( $this, 'on_product_deleted' ) );
        add_action( 'untrashed_post',     array( $this, 'on_product_restored' ) );

        // Product import: one webhook after the full import batch completes,
        // instead of one per product — prevents flooding on 1000+ imports.
        add_action( 'woocommerce_product_importer_complete', array( $this, 'on_import_completed' ) );

        // Stock changes — fires on ANY stock update regardless of source:
        // order placement, cancellation, refund, manual edit, REST API, CLI.
        add_action( 'woocommerce_product_set_stock',   array( $this, 'on_product_stock_changed' ) );
        add_action( 'woocommerce_variation_set_stock', array( $this, 'on_variation_stock_changed' ) );

        // Action Scheduler worker
        add_action( self::AS_HOOK, array( $this, 'dispatch_webhook' ), 10, 3 );
    }


    public function on_product_created( int $product_id, \WC_Product $product ): void {
        $this->schedule( $product_id, 'product.created' );
    }

    public function on_product_updated( int $product_id, \WC_Product $product ): void {
        $this->schedule( $product_id, 'product.updated' );
    }

    public function on_product_trashed( int $post_id ): void {
        if ( 'product' !== get_post_type( $post_id ) ) {
            return;
        }
        $this->schedule( $post_id, 'product.deleted' );
    }

    public function on_product_deleted( int $post_id ): void {
        if ( 'product' !== get_post_type( $post_id ) ) {
            return;
        }
        $this->schedule( $post_id, 'product.deleted' );
    }

    public function on_product_restored( int $post_id ): void {
        if ( 'product' !== get_post_type( $post_id ) ) {
            return;
        }
        $this->schedule( $post_id, 'product.restored' );
    }

    /**
     * Fires once after WooCommerce CSV import batch completes.
     * Sends a single products.imported event instead of one per product.
     *
     * @param array $results { imported, updated, skipped, failed }
     */
    public function on_import_completed( array $results ): void {
        $this->schedule( 0, 'products.imported' );
    }

    /**
     * Fires whenever WooCommerce updates stock on a simple / parent product.
     *
     * @param \WC_Product $product
     */
    public function on_product_stock_changed( \WC_Product $product ): void {
        $this->schedule( $product->get_id(), 'product.stock_updated' );
    }

    /**
     * Fires whenever WooCommerce updates stock on a product variation.
     * Sends variation_id + parent product_id so the receiver knows
     * which specific variation changed.
     *
     * @param \WC_Product $variation
     */
    public function on_variation_stock_changed( \WC_Product $variation ): void {
        $parent_id = $variation->get_parent_id();
        if ( ! $parent_id ) {
            return;
        }
        $this->schedule( $parent_id, 'product.stock_updated', $variation->get_id() );
    }

    /**
     * Enqueue an async Action Scheduler action.
     *
     * Deduplication: if a pending action already exists for this
     * product_id + event + variation_id combination, skip it.
     * The existing action will reflect the latest state when it runs.
     *
     * Falls back to direct (blocking) dispatch if AS is unavailable.
     *
     * @param int    $product_id   Parent product ID (0 for import-level events).
     * @param string $event        Event name.
     * @param int    $variation_id Variation ID, 0 if not applicable.
     */
    private function schedule( int $product_id, string $event, int $variation_id = 0 ): void {
        if ( empty( self::WEBHOOK_URL ) || 'https://your-api-endpoint.example.com/webhooks/product' === self::WEBHOOK_URL ) {
            return;
        }

        if ( ! Settings::is_configured() ) {
            return;
        }

        if ( ! function_exists( 'as_schedule_single_action' ) ) {
            $this->dispatch_webhook( $product_id, $event, $variation_id );
            return;
        }

        $args = array( $product_id, $event, $variation_id );

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

    // ─── Dispatcher (called by Action Scheduler) ──────────────────────────────

    /**
     * Perform the HTTP POST to the webhook URL.
     *
     * Called asynchronously by Action Scheduler.
     * Throwing an exception causes AS to retry automatically
     * (default: up to 3 attempts with exponential back-off).
     *
     * @param int    $product_id
     * @param string $event
     * @param int    $variation_id
     * @throws \Exception On HTTP failure, so AS can retry.
     */
    public function dispatch_webhook( int $product_id, string $event, int $variation_id = 0 ): void {
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

        $payload = $this->build_payload( $product_id, $event, $variation_id );

        $response = wp_remote_post(
            self::WEBHOOK_URL,
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
                sprintf(
                    '[AICommerce] Webhook failed for product %d (%s): %s',
                    $product_id,
                    $event,
                    $response->get_error_message()
                )
            );
        }

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

    // ─── Payload builder ─────────────────────────────────────────────────────

    /**
     * Build a lightweight sync-trigger payload.
     *
     * The payload contains only identifiers and context — no full product data.
     * The receiver is expected to call GET /aicommerce/v1/products/{id} to
     * fetch the current product state.
     *
     * product_type rules:
     *   - 'variation' when variation_id > 0 (stock change on a specific variation)
     *   - WC product type ('simple', 'variable', etc.) otherwise
     *   - 'unknown' if the product no longer exists (deleted)
     *
     * @param int    $product_id
     * @param string $event
     * @param int    $variation_id
     * @return array
     */
    private function build_payload( int $product_id, string $event, int $variation_id = 0 ): array {
        $payload = array(
            'event'      => $event,
            'site_url'   => get_site_url(),
            'timestamp'  => ( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) )->format( \DateTime::ATOM ),
            'api_key'    => Settings::get_api_key(),
            'api_secret' => Settings::get_api_secret(),
        );

        // Import-level event — no specific product
        if ( 'products.imported' === $event ) {
            return $payload;
        }

        // Deleted product — no WC object available
        if ( 'product.deleted' === $event ) {
            $payload['product_id']   = $product_id;
            $payload['product_type'] = 'unknown';
            return $payload;
        }

        // Variation event: product_type = 'variation', send both IDs
        if ( $variation_id > 0 ) {
            $payload['product_id']   = $product_id;
            $payload['variation_id'] = $variation_id;
            $payload['product_type'] = 'variation';
            return $payload;
        }

        // Regular product event: resolve type from WC object
        $product                 = wc_get_product( $product_id );
        $payload['product_id']   = $product_id;
        $payload['product_type'] = $product ? $product->get_type() : 'unknown';

        return $payload;
    }
}
