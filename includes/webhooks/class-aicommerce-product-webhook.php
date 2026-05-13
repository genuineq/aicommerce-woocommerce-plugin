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
 * Product webhook dispatcher.
 *
 * Collects product lifecycle changes from WooCommerce, deduplicates noisy
 * updates, and delivers lightweight sync events to the external platform.
 */
class ProductWebhook {

    /**
     * Third-party API endpoint.
     * Replace with the real URL when available.
     */
    const WEBHOOK_URL = 'https://api.ai.genuineq.com/api/client/products-sync';
    const WEBHOOK_URL_STAGING = 'https://api.ai.staging.genuineq.com/api/client/products-sync';

    /**
     * Action Scheduler hook name.
     */
    const AS_HOOK = 'aicommerce_product_webhook';

    /**
     * Action Scheduler hook name for import batch webhook.
     */
    const AS_IMPORT_HOOK = 'aicommerce_import_webhook';

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
	 * Prefix for persisted event batch collectors.
	 */
	private const BATCH_OPTION_PREFIX = 'aicommerce_product_webhook_batch_';

	/**
	 * Request-local deduplication map for noisy repeated saves.
	 *
	 * @var array<string, true>
	 */
	private static array $scheduled_in_request = [];

	/**
	 * True while WooCommerce product import is running.
	 *
	 * @var bool
	 */
	private static bool $in_import = false;

	/**
	 * Product IDs collected during the current import request.
	 *
	 * @var int[]
	 */
	private static array $imported_ids = [];

	/**
	 * Debug logging is intentionally disabled in production builds.
	 */
	private static function log_debug( string $event, array $context = array() ): void {
		return;
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
	 * Decide whether broad lifecycle product events should be processed.
	 *
	 * Frontend checkout requests can save products while reducing stock. In that
	 * path we only want the explicit stock hooks, not a generic product.updated
	 * batch that can include unrelated products accumulated by bulk operations.
	 *
	 * @return bool
	 */
	private static function should_handle_product_lifecycle_event(): bool {
		if ( self::$in_import ) {
			return true;
		}

		if ( is_admin() ) {
			return true;
		}

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return true;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( false !== strpos( $request_uri, '/wc/store/' ) ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		return false;
	}

	/**
	 * Constructor.
	 *
	 * Registers WooCommerce and Action Scheduler hooks that feed the product
	 * webhook pipeline.
	 */
	public function __construct() {
		self::log_debug(
			'construct:hooks_registered',
			array(
				'is_admin'         => is_admin(),
				'is_rest'          => defined( 'REST_REQUEST' ) && REST_REQUEST,
				'doing_cron'       => defined( 'DOING_CRON' ) && DOING_CRON,
				'wp_cli'           => defined( 'WP_CLI' ) && WP_CLI,
				'has_credentials'  => Settings::has_credentials(),
				'webhook_url'      => self::get_url(),
				'action_scheduler' => function_exists( 'as_schedule_single_action' ),
			)
		);

		/** Track product creation and updates. */
		add_action( 'woocommerce_new_product', array( $this, 'on_product_created' ), 10, 2 );
		add_action( 'woocommerce_update_product', array( $this, 'on_product_updated' ), 10, 2 );

		/** Track product deletion lifecycle. */
		add_action( 'wp_trash_post', array( $this, 'on_product_trashed' ) );
		add_action( 'before_delete_post', array( $this, 'on_product_deleted' ) );
		add_action( 'untrashed_post', array( $this, 'on_product_restored' ) );

		/** Collapse import runs into one batched webhook. */
		add_action( 'woocommerce_product_import_before_process_item', array( $this, 'on_import_started' ) );
		add_action( self::AS_IMPORT_HOOK, array( $this, 'dispatch_import_webhook' ) );

		/**
		 * Track stock changes from any source:
		 * orders, refunds, admin edits, REST API, or CLI.
		 */
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_product_stock_changed' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_variation_stock_changed' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_product_stock_status_changed' ), 10, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'on_variation_stock_status_changed' ), 10, 3 );

		/** Register async webhook worker. */
		add_action( self::AS_HOOK, array( $this, 'dispatch_webhook' ), 10, 3 );
	}

	/**
	 * Handle product creation.
	 *
	 * During imports, only collect IDs for later batching. Outside imports,
	 * schedule a normal async webhook.
	 *
	 * @param int         $product_id Product ID.
	 * @param \WC_Product $product    Created product instance.
	 *
	 * @return void
	 */
	public function on_product_created( int $product_id, \WC_Product $product ): void {
		if ( ! self::should_handle_product_lifecycle_event() ) {
			self::log_debug(
				'hook:woocommerce_new_product_skipped_context',
				array(
					'product_id'  => $product_id,
					'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '',
				)
			);
			return;
		}

		self::log_debug(
			'hook:woocommerce_new_product',
			array(
				'product_id' => $product_id,
				'type'       => $product->get_type(),
				'in_import'  => self::$in_import,
			)
		);

		if ( self::$in_import ) {
			self::$imported_ids[] = $product_id;
			return;
		}

		$this->schedule( $product_id, 'product.created' );
	}

	/**
	 * Handle product updates.
	 *
	 * During imports, only collect IDs for later batching. Outside imports,
	 * schedule a normal async webhook.
	 *
	 * @param int         $product_id Product ID.
	 * @param \WC_Product $product    Updated product instance.
	 *
	 * @return void
	 */
	public function on_product_updated( int $product_id, \WC_Product $product ): void {
		if ( ! self::should_handle_product_lifecycle_event() ) {
			self::log_debug(
				'hook:woocommerce_update_product_skipped_context',
				array(
					'product_id'  => $product_id,
					'type'        => $product->get_type(),
					'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '',
				)
			);
			return;
		}

		self::log_debug(
			'hook:woocommerce_update_product',
			array(
				'product_id' => $product_id,
				'type'       => $product->get_type(),
				'in_import'  => self::$in_import,
			)
		);

		if ( self::$in_import ) {
			self::$imported_ids[] = $product_id;
			return;
		}

		$this->schedule( $product_id, 'product.updated' );
	}

	/**
	 * Handle product trash events.
	 *
	 * @param int $post_id Trashed post ID.
	 *
	 * @return void
	 */
	public function on_product_trashed( int $post_id ): void {
		if ( ! self::should_handle_product_lifecycle_event() ) {
			self::log_debug(
				'hook:wp_trash_post_skipped_context',
				array(
					'post_id'     => $post_id,
					'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '',
				)
			);
			return;
		}

		if ( 'product' !== get_post_type( $post_id ) ) {
			self::log_debug(
				'hook:wp_trash_post_skipped',
				array(
					'post_id'   => $post_id,
					'post_type' => get_post_type( $post_id ),
				)
			);
			return;
		}

		self::log_debug( 'hook:wp_trash_post', array( 'product_id' => $post_id ) );
		$this->schedule( $post_id, 'product.deleted' );
	}

	/**
	 * Handle hard-delete product events.
	 *
	 * @param int $post_id Deleted post ID.
	 *
	 * @return void
	 */
	public function on_product_deleted( int $post_id ): void {
		if ( ! self::should_handle_product_lifecycle_event() ) {
			self::log_debug(
				'hook:before_delete_post_skipped_context',
				array(
					'post_id'     => $post_id,
					'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '',
				)
			);
			return;
		}

		if ( 'product' !== get_post_type( $post_id ) ) {
			self::log_debug(
				'hook:before_delete_post_skipped',
				array(
					'post_id'   => $post_id,
					'post_type' => get_post_type( $post_id ),
				)
			);
			return;
		}

		self::log_debug( 'hook:before_delete_post', array( 'product_id' => $post_id ) );
		$this->schedule( $post_id, 'product.deleted' );
	}

	/**
	 * Handle product restore events.
	 *
	 * @param int $post_id Restored post ID.
	 *
	 * @return void
	 */
	public function on_product_restored( int $post_id ): void {
		if ( ! self::should_handle_product_lifecycle_event() ) {
			self::log_debug(
				'hook:untrashed_post_skipped_context',
				array(
					'post_id'     => $post_id,
					'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '',
				)
			);
			return;
		}

		if ( 'product' !== get_post_type( $post_id ) ) {
			self::log_debug(
				'hook:untrashed_post_skipped',
				array(
					'post_id'   => $post_id,
					'post_type' => get_post_type( $post_id ),
				)
			);
			return;
		}

		self::log_debug( 'hook:untrashed_post', array( 'product_id' => $post_id ) );
		$this->schedule( $post_id, 'product.restored' );
	}

	/**
	 * Mark the beginning of a WooCommerce import run.
	 *
	 * The first imported product registers a shutdown callback that will
	 * dispatch one batched webhook when the request ends.
	 *
	 * @return void
	 */
	public function on_import_started(): void {
		self::log_debug( 'hook:woocommerce_product_import_before_process_item' );

		if ( ! self::$in_import ) {
			self::$imported_ids = [];
			add_action( 'shutdown', array( $this, 'on_import_completed' ) );
		}

		self::$in_import = true;
	}

	/**
	 * Flush the collected import product IDs as a single batched webhook.
	 *
	 * @return void
	 */
	public function on_import_completed(): void {
		/** Deduplicate imported product IDs before sending. */
		$ids = array_values( array_unique( self::$imported_ids ) );
		self::$in_import = false;
		self::$imported_ids = [];

		self::log_debug(
			'import:completed',
			array(
				'count'       => count( $ids ),
				'product_ids' => array_slice( $ids, 0, 20 ),
			)
		);

		/** Stop when webhook delivery is not configured. */
		if ( empty( self::get_url() ) ) {
			self::log_debug( 'import:skipped_missing_url' );
			return;
		}

		/** Stop when API credentials are not configured. */
		if ( ! Settings::has_credentials() ) {
			self::log_debug( 'import:skipped_missing_credentials' );
			return;
		}

		/** Fall back to immediate delivery when Action Scheduler is unavailable. */
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			self::log_debug( 'import:dispatch_immediate_no_action_scheduler' );
			$this->dispatch_import_webhook( $ids );
			return;
		}

		/** Schedule one delayed batch to absorb fast repeated import saves. */
		as_schedule_single_action(
			time() + self::DISPATCH_DELAY,
			self::AS_IMPORT_HOOK,
			array( $ids ),
			self::AS_GROUP
		);

		self::log_debug(
			'import:scheduled',
			array(
				'run_at' => time() + self::DISPATCH_DELAY,
				'count'  => count( $ids ),
			)
		);
	}

    /**
     * Fires whenever WooCommerce updates stock on a simple / parent product.
     *
     * @param \WC_Product $product
     */
	public function on_product_stock_changed( \WC_Product $product ): void {
		self::log_debug(
			'hook:woocommerce_product_set_stock',
			array(
				'product_id'   => $product->get_id(),
				'type'         => $product->get_type(),
				'stock_qty'    => $product->get_stock_quantity(),
				'stock_status' => $product->get_stock_status(),
			)
		);

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
		/** Variation stock changes are grouped under the parent product. */
		$parent_id = $variation->get_parent_id();
		if ( ! $parent_id ) {
			self::log_debug(
				'hook:woocommerce_variation_set_stock_skipped_missing_parent',
				array( 'variation_id' => $variation->get_id() )
			);
			return;
		}

		self::log_debug(
			'hook:woocommerce_variation_set_stock',
			array(
				'product_id'   => $parent_id,
				'variation_id' => $variation->get_id(),
				'stock_qty'    => $variation->get_stock_quantity(),
				'stock_status' => $variation->get_stock_status(),
			)
		);

		$this->schedule( $parent_id, 'product.stock_updated', $variation->get_id() );
	}

	/**
	 * Fires whenever WooCommerce updates stock status on a simple / parent product.
	 *
	 * @param int              $product_id   Product ID.
	 * @param string           $stock_status New stock status.
	 * @param \WC_Product|null $product      Product object when provided by WooCommerce.
	 */
	public function on_product_stock_status_changed( int $product_id, string $stock_status = '', ?\WC_Product $product = null ): void {
		self::log_debug(
			'hook:woocommerce_product_set_stock_status',
			array(
				'product_id'   => $product ? $product->get_id() : $product_id,
				'stock_status' => $stock_status,
				'has_product'  => $product instanceof \WC_Product,
			)
		);

		$this->schedule( $product ? $product->get_id() : $product_id, 'product.stock_updated' );
	}

	/**
	 * Fires whenever WooCommerce updates stock status on a variation.
	 *
	 * @param int              $variation_id Variation ID.
	 * @param string           $stock_status New stock status.
	 * @param \WC_Product|null $variation    Variation object when provided by WooCommerce.
	 */
	public function on_variation_stock_status_changed( int $variation_id, string $stock_status = '', ?\WC_Product $variation = null ): void {
		$variation = $variation ?: wc_get_product( $variation_id );
		if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
			self::log_debug(
				'hook:woocommerce_variation_set_stock_status_skipped_invalid_variation',
				array(
					'variation_id' => $variation_id,
					'stock_status' => $stock_status,
				)
			);
			return;
		}

		$parent_id = $variation->get_parent_id();
		if ( ! $parent_id ) {
			self::log_debug(
				'hook:woocommerce_variation_set_stock_status_skipped_missing_parent',
				array(
					'variation_id' => $variation_id,
					'stock_status' => $stock_status,
				)
			);
			return;
		}

		self::log_debug(
			'hook:woocommerce_variation_set_stock_status',
			array(
				'product_id'   => $parent_id,
				'variation_id' => $variation_id,
				'stock_status' => $stock_status,
			)
		);

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
		self::log_debug(
			'schedule:request',
			array(
				'event'        => $event,
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'in_import'    => self::$in_import,
			)
		);

		/** Import runs are handled separately as a batch. */
		if ( self::$in_import ) {
			self::log_debug(
				'schedule:skipped_import_active',
				array(
					'event'        => $event,
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
				)
			);
			return;
		}

		/** Stop when webhook delivery is not configured. */
		if ( empty( self::get_url() ) ) {
			self::log_debug(
				'schedule:skipped_missing_url',
				array(
					'event'      => $event,
					'product_id' => $product_id,
				)
			);
			return;
		}

		/** Stop when API credentials are not configured. */
		if ( ! Settings::has_credentials() ) {
			self::log_debug(
				'schedule:skipped_missing_credentials',
				array(
					'event'      => $event,
					'product_id' => $product_id,
				)
			);
			return;
		}

		/**
		 * Within one request, repeated saves for the same product are collapsed
		 * into one scheduled action, except delete/restore events.
		 */
		if ( ! in_array( $event, array( 'product.deleted', 'product.restored' ), true ) ) {
			$request_key = $event . '|' . $product_id . '|' . $variation_id;
			if ( isset( self::$scheduled_in_request[ $request_key ] ) ) {
				self::log_debug(
					'schedule:skipped_request_dedupe',
					array(
						'event'        => $event,
						'product_id'   => $product_id,
						'variation_id' => $variation_id,
					)
				);
				return;
			}
			self::$scheduled_in_request[ $request_key ] = true;
		}

		$this->append_to_batch( $event, $product_id, $variation_id );

		/** Fall back to immediate batched delivery when Action Scheduler is unavailable. */
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			self::log_debug(
				'schedule:dispatch_immediate_no_action_scheduler',
				array(
					'event'      => $event,
					'product_id' => $product_id,
				)
			);
			$this->dispatch_webhook( 0, $event, 0 );
			return;
		}

		/** Batch workers are scheduled per event, not per product. */
		$args = array( 0, $event, 0 );

		/** Skip scheduling when an identical pending action already exists. */
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
			self::log_debug(
				'schedule:pending_action_exists',
				array(
					'event'       => $event,
					'pending_ids' => array_values( array_slice( $pending, 0, 10 ) ),
				)
			);
			return;
		}

		as_schedule_single_action(
			time() + self::DISPATCH_DELAY,
			self::AS_HOOK,
			$args,
			self::AS_GROUP
		);

		self::log_debug(
			'schedule:action_scheduled',
			array(
				'event'  => $event,
				'run_at' => time() + self::DISPATCH_DELAY,
				'args'   => $args,
			)
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
		self::log_debug(
			'dispatch:start',
			array(
				'event'        => $event,
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
			)
		);

		/** Back off when too many webhook workers are already running. */
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
				self::log_debug(
					'dispatch:concurrency_limit',
					array(
						'event'   => $event,
						'running' => count( $running ),
						'limit'   => self::MAX_CONCURRENT,
					)
				);

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

		$batch = ( 0 === $product_id )
			? $this->get_batch( $event )
			: array(
				'product_ids'   => $product_id > 0 ? array( $product_id ) : array(),
				'variation_ids' => $variation_id > 0 ? array( $variation_id ) : array(),
			);

		if ( empty( $batch['product_ids'] ) && empty( $batch['variation_ids'] ) ) {
			self::log_debug(
				'dispatch:skipped_empty_batch',
				array(
					'event' => $event,
				)
			);
			return;
		}

		$delivery_id = $this->generate_delivery_id( $event, $batch['product_ids'], $batch['variation_ids'] );
		$payload     = $this->build_payload( $event, $batch['product_ids'], $batch['variation_ids'], $delivery_id );

		self::log_debug(
			'dispatch:payload_ready',
			array(
				'event'         => $event,
				'delivery_id'   => $delivery_id,
				'product_ids'   => $batch['product_ids'],
				'variation_ids' => $batch['variation_ids'],
			)
		);

		$this->send_webhook_request( $payload, $event, $delivery_id );

		if ( 0 === $product_id ) {
			$this->clear_batch( $event );
			self::log_debug(
				'dispatch:batch_cleared',
				array(
					'event'       => $event,
					'delivery_id' => $delivery_id,
				)
			);
		}
    }

    /**
     * @param array $product_ids
     * @throws \Exception On HTTP failure, so AS can retry.
     */
	public function dispatch_import_webhook( array $product_ids ): void {
		self::log_debug(
			'import_dispatch:start',
			array(
				'count'       => count( $product_ids ),
				'product_ids' => array_slice( $product_ids, 0, 20 ),
			)
		);

		/** Build one batch payload instead of one webhook per imported product. */
		$delivery_id = $this->generate_delivery_id( 'products.imported', $product_ids, array() );
		$payload = array(
			'event'       => 'products.imported',
			'delivery_id' => $delivery_id,
			'product_ids' => $product_ids,
			'site_url'    => get_site_url(),
			'timestamp'   => ( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) )->format( \DateTime::ATOM ),
			'api_key'     => Settings::get_api_key(),
		);

		$this->send_webhook_request( $payload, 'products.imported', $delivery_id );
    }

	/**
	 * Replay one logged product delivery by its delivery ID.
	 *
	 * @param string $delivery_id Delivery ID.
	 * @return bool
	 */
	public static function replay_delivery( string $delivery_id ): bool {
		$entry = WebhookDeliveryLog::get_delivery( $delivery_id );
		if ( ! is_array( $entry ) || ( $entry['type'] ?? '' ) !== 'product' ) {
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

    // ─── Payload builder ─────────────────────────────────────────────────────

    /**
     * Build a lightweight sync-trigger payload.
     *
     * The payload contains only identifiers and context — no full product data.
     * The receiver is expected to call GET /aicommerce/v1/products/{id} to
     * fetch the current product state.
     *
     * Payload rules:
     *   - regular events send `product_ids`
     *   - stock updates on variations also send `variation_ids`
     *   - delete events use `product_type = unknown`
     *
	 * @param string $event
	 * @param int[]  $product_ids
	 * @param int[]  $variation_ids
	 * @param string $delivery_id
	 * @return array
	 */
	private function build_payload( string $event, array $product_ids, array $variation_ids = array(), string $delivery_id = '' ): array {
		/** Start with metadata shared by every product webhook. */
		$payload = array(
			'event'       => $event,
			'delivery_id' => $delivery_id,
			'site_url'    => get_site_url(),
			'timestamp'   => ( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) )->format( \DateTime::ATOM ),
			'api_key'     => Settings::get_api_key(),
		);

		$product_ids   = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
		$variation_ids = array_values( array_unique( array_filter( array_map( 'absint', $variation_ids ) ) ) );

		/** Deleted products no longer have a reliable WC object to inspect. */
		if ( 'product.deleted' === $event ) {
			$payload['product_ids']  = $product_ids;
			$payload['product_type'] = 'unknown';
			return $payload;
		}

		/** Variation stock updates include both parent and variation IDs. */
		if ( ! empty( $variation_ids ) ) {
			$payload['product_ids']   = $product_ids;
			$payload['variation_ids'] = $variation_ids;
			$payload['product_type'] = 'variation';
			return $payload;
		}

		/** Regular product events include only IDs; the receiver can refresh current state from the API. */
		$payload['product_ids'] = $product_ids;

		return $payload;
	}

	/**
	 * Append one product change into the batch collector for the given event.
	 *
	 * @param string $event Event name.
	 * @param int    $product_id Product ID.
	 * @param int    $variation_id Variation ID.
	 * @return void
	 */
	private function append_to_batch( string $event, int $product_id, int $variation_id = 0 ): void {
		if ( ! $this->coalesce_existing_batches( $event, $product_id, $variation_id ) ) {
			self::log_debug(
				'batch:coalesced_skip',
				array(
					'event'        => $event,
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
				)
			);
			return;
		}

		$batch = $this->get_batch( $event );

		if ( $product_id > 0 ) {
			$batch['product_ids'][] = $product_id;
		}

		if ( $variation_id > 0 ) {
			$batch['variation_ids'][] = $variation_id;
		}

		$batch['product_ids']   = array_values( array_unique( array_filter( array_map( 'absint', $batch['product_ids'] ) ) ) );
		$batch['variation_ids'] = array_values( array_unique( array_filter( array_map( 'absint', $batch['variation_ids'] ) ) ) );

		update_option( $this->get_batch_option_key( $event ), $batch, false );

		self::log_debug(
			'batch:updated',
			array(
				'event'         => $event,
				'product_ids'   => $batch['product_ids'],
				'variation_ids' => $batch['variation_ids'],
				'option_key'    => $this->get_batch_option_key( $event ),
			)
		);
	}

	/**
	 * Compact the same product across related event batches.
	 *
	 * Rules:
	 * - `product.deleted` wins over any previous event for the same product.
	 * - `product.restored` and `product.created` replace `updated` / `stock_updated`
	 *   and clear stale delete markers for the same product.
	 * - `product.updated` replaces `product.stock_updated`.
	 * - `product.stock_updated` is ignored when a stronger event is already queued.
	 *
	 * @param string $incoming_event Incoming event name.
	 * @param int    $product_id Product ID.
	 * @param int    $variation_id Variation ID.
	 * @return bool True when the incoming event should still be appended.
	 */
	private function coalesce_existing_batches( string $incoming_event, int $product_id, int $variation_id = 0 ): bool {
		if ( $product_id <= 0 ) {
			return true;
		}

		if ( 'product.deleted' === $incoming_event ) {
			$this->remove_product_from_batches(
				$product_id,
				array(
					'product.created',
					'product.updated',
					'product.restored',
					'product.stock_updated',
				)
			);
			return true;
		}

		if ( in_array( $incoming_event, array( 'product.restored', 'product.created' ), true ) ) {
			$this->remove_product_from_batches(
				$product_id,
				array(
					'product.deleted',
					'product.updated',
					'product.stock_updated',
				)
			);
			return true;
		}

		if ( 'product.updated' === $incoming_event ) {
			$this->remove_product_from_batches( $product_id, array( 'product.stock_updated' ) );
			return true;
		}

		if ( 'product.stock_updated' === $incoming_event ) {
			if (
				$this->batch_contains_product( 'product.created', $product_id ) ||
				$this->batch_contains_product( 'product.updated', $product_id ) ||
				$this->batch_contains_product( 'product.restored', $product_id ) ||
				$this->batch_contains_product( 'product.deleted', $product_id )
			) {
				return false;
			}

			if ( $variation_id > 0 ) {
				$this->remove_variation_from_batch( 'product.stock_updated', $variation_id );
			}
		}

		return true;
	}

	/**
	 * Check whether a given batch already contains the product ID.
	 *
	 * @param string $event Event name.
	 * @param int    $product_id Product ID.
	 * @return bool
	 */
	private function batch_contains_product( string $event, int $product_id ): bool {
		$batch = $this->get_batch( $event );
		return in_array( $product_id, $batch['product_ids'], true );
	}

	/**
	 * Remove one product from a list of event batches.
	 *
	 * @param int      $product_id Product ID.
	 * @param string[] $events Event names.
	 * @return void
	 */
	private function remove_product_from_batches( int $product_id, array $events ): void {
		foreach ( $events as $event ) {
			$batch = $this->get_batch( $event );

			$updated_product_ids = array_values(
				array_filter(
					$batch['product_ids'],
					static function ( int $batched_product_id ) use ( $product_id ): bool {
						return $batched_product_id !== $product_id;
					}
				)
			);

			if ( $updated_product_ids === $batch['product_ids'] ) {
				continue;
			}

			$batch['product_ids'] = $updated_product_ids;

			if ( empty( $batch['product_ids'] ) && empty( $batch['variation_ids'] ) ) {
				$this->clear_batch( $event );
				continue;
			}

			update_option( $this->get_batch_option_key( $event ), $batch, false );
		}
	}

	/**
	 * Remove one variation ID from a given batch.
	 *
	 * @param string $event Event name.
	 * @param int    $variation_id Variation ID.
	 * @return void
	 */
	private function remove_variation_from_batch( string $event, int $variation_id ): void {
		if ( $variation_id <= 0 ) {
			return;
		}

		$batch = $this->get_batch( $event );
		$updated_variation_ids = array_values(
			array_filter(
				$batch['variation_ids'],
				static function ( int $batched_variation_id ) use ( $variation_id ): bool {
					return $batched_variation_id !== $variation_id;
				}
			)
		);

		if ( $updated_variation_ids === $batch['variation_ids'] ) {
			return;
		}

		$batch['variation_ids'] = $updated_variation_ids;

		if ( empty( $batch['product_ids'] ) && empty( $batch['variation_ids'] ) ) {
			$this->clear_batch( $event );
			return;
		}

		update_option( $this->get_batch_option_key( $event ), $batch, false );
	}

	/**
	 * Read the current batch payload for a product event.
	 *
	 * @param string $event Event name.
	 * @return array{product_ids:int[],variation_ids:int[]}
	 */
	private function get_batch( string $event ): array {
		$batch = get_option( $this->get_batch_option_key( $event ), array() );

		if ( ! is_array( $batch ) ) {
			$batch = array();
		}

		return array(
			'product_ids'   => isset( $batch['product_ids'] ) && is_array( $batch['product_ids'] ) ? $batch['product_ids'] : array(),
			'variation_ids' => isset( $batch['variation_ids'] ) && is_array( $batch['variation_ids'] ) ? $batch['variation_ids'] : array(),
		);
	}

	/**
	 * Clear the batch collector after a successful delivery.
	 *
	 * @param string $event Event name.
	 * @return void
	 */
	private function clear_batch( string $event ): void {
		delete_option( $this->get_batch_option_key( $event ) );
	}

	/**
	 * Build the option key used for one event batch collector.
	 *
	 * @param string $event Event name.
	 * @return string
	 */
	private function get_batch_option_key( string $event ): string {
		return self::BATCH_OPTION_PREFIX . md5( $event );
	}

	/**
	 * Generate a unique delivery ID for one outgoing webhook.
	 *
	 * @param string $event Event name.
	 * @param int[]  $product_ids Product IDs.
	 * @param int[]  $variation_ids Variation IDs.
	 * @return string
	 */
	private function generate_delivery_id( string $event, array $product_ids, array $variation_ids ): string {
		return sprintf(
			'prod_%s_%s',
			md5( $event . '|' . wp_json_encode( $product_ids ) . '|' . wp_json_encode( $variation_ids ) ),
			wp_generate_password( 12, false )
		);
	}

	/**
	 * Send one signed product webhook request.
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

		self::log_debug(
			'http:request',
			array(
				'event'       => $event,
				'delivery_id' => $delivery_id,
				'url'         => $url,
				'body_bytes'  => is_string( $body ) ? strlen( $body ) : 0,
				'product_ids' => isset( $payload['product_ids'] ) ? $payload['product_ids'] : array(),
			)
		);

		WebhookDeliveryLog::log_pending( $delivery_id, 'product', $event, $url, $payload );

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
			self::log_debug(
				'http:failure_wp_error',
				array(
					'event'         => $event,
					'delivery_id'   => $delivery_id,
					'error_message' => $response->get_error_message(),
				)
			);
			throw new \Exception( '[AICommerce] Product webhook failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			WebhookDeliveryLog::log_failure( $delivery_id, sprintf( 'HTTP %d', $code ), $code );
			self::log_debug(
				'http:failure_status',
				array(
					'event'       => $event,
					'delivery_id' => $delivery_id,
					'http_code'   => $code,
				)
			);
			throw new \Exception( sprintf( '[AICommerce] Product webhook returned HTTP %d for %s', $code, $event ) );
		}

		WebhookDeliveryLog::log_success( $delivery_id, $code );
		self::log_debug(
			'http:success',
			array(
				'event'       => $event,
				'delivery_id' => $delivery_id,
				'http_code'   => $code,
			)
		);
	}

	/**
	 * Build signed headers for outgoing product webhooks.
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
			'Content-Type'            => 'application/json',
			'Accept'                  => 'application/json',
			'X-AICommerce-Api-Key'    => $api_key,
			'X-AICommerce-Event'      => $event,
			'X-AICommerce-Delivery-Id'=> $delivery_id,
			'X-AICommerce-Timestamp'  => $timestamp,
			'X-AICommerce-Signature'  => $signature,
		);
	}
}
