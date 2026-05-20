<?php
/**
 * WooCommerce cart bridge.
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projection bridge between AICommerce cart storage and WooCommerce cart state.
 */
class WooCartBridge {
	/**
	 * Prevents exporting the Woo cart while we intentionally rebuild it.
	 *
	 * @var bool
	 */
	private static bool $is_importing = false;

	/**
	 * Short transient lock TTL in seconds.
	 *
	 * @var int
	 */
	private const LOCK_TTL = 6;

	/**
	 * Debug logging is intentionally disabled in production builds.
	 */
	private static function log_debug( string $event, array $context = array() ): void {
		return;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		self::log_debug(
			'construct:hooks_registered',
			array(
				'is_admin'   => is_admin(),
				'is_rest'    => defined( 'REST_REQUEST' ) && REST_REQUEST,
				'doing_cron' => defined( 'DOING_CRON' ) && DOING_CRON,
			)
		);

		add_action( 'woocommerce_before_cart', array( $this, 'import_current_storage_to_wc_cart' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'import_current_storage_to_wc_cart' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'export_current_wc_cart_to_storage' ), 20, 0 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'export_current_wc_cart_to_storage' ), 20, 0 );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'export_current_wc_cart_to_storage' ), 20, 0 );
		add_action( 'woocommerce_cart_item_restored', array( $this, 'export_current_wc_cart_to_storage' ), 20, 0 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'clear_current_storage_after_checkout' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'clear_current_storage_after_checkout' ), 20, 1 );
	}

	/**
	 * Import the active AICommerce cart into WooCommerce for the current visitor.
	 *
	 * @return void
	 */
	public function import_current_storage_to_wc_cart(): void {
		$context = CartContextResolver::resolve_current_context();

		if ( empty( $context['guest_token'] ) && empty( $context['user_id'] ) ) {
			return;
		}

		self::import_storage_to_wc_cart(
			(string) $context['guest_token'],
			$context['user_id'] ? (int) $context['user_id'] : null
		);
	}

	/**
	 * Export the current WooCommerce cart back into AICommerce storage.
	 *
	 * @return void
	 */
	public function export_current_wc_cart_to_storage(): void {
		$context = CartContextResolver::resolve_current_context();

		if ( empty( $context['guest_token'] ) && empty( $context['user_id'] ) ) {
			return;
		}

		self::export_wc_cart_to_storage(
			(string) $context['guest_token'],
			$context['user_id'] ? (int) $context['user_id'] : null
		);
	}

	/**
	 * Mark the active AICommerce cart as empty once WooCommerce creates an order.
	 *
	 * WooCommerce clears its session cart after checkout, but AICommerce keeps a
	 * separate canonical cart. If that canonical cart still contains the purchased
	 * items, a later cart/checkout sync can re-import them into WooCommerce.
	 *
	 * @param \WC_Order|int $order Order object or order ID.
	 * @return void
	 */
	public function clear_current_storage_after_checkout( $order ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		if ( ! $order ) {
			self::log_debug( 'checkout_clear:skipped_missing_order' );
			return;
		}

		$user_id = (int) $order->get_customer_id();
		if ( $user_id > 0 ) {
			CartStorage::save_user_cart( $user_id, array() );
			self::clear_wc_persistent_cart( $user_id );
			self::set_synced_state( CartContextResolver::get_context_key( '', $user_id ), 0, 'empty' );
			self::log_debug(
				'checkout_clear:user_cart_emptied',
				array(
					'order_id' => $order->get_id(),
					'user_id'  => $user_id,
				)
			);
			return;
		}

		$guest_token = isset( $_COOKIE['aicommerce_guest_token'] )
			? sanitize_text_field( wp_unslash( $_COOKIE['aicommerce_guest_token'] ) )
			: '';

		if ( empty( $guest_token ) || ! CartContextResolver::is_valid_guest_token( $guest_token ) ) {
			self::log_debug(
				'checkout_clear:skipped_missing_guest_token',
				array(
					'order_id'             => $order->get_id(),
					'guest_token_present'  => ! empty( $guest_token ),
				)
			);
			return;
		}

		CartStorage::save_cart( $guest_token, array() );
		self::set_synced_state( CartContextResolver::get_context_key( $guest_token, null ), 0, 'empty' );
		self::log_debug(
			'checkout_clear:guest_cart_emptied',
			array(
				'order_id'     => $order->get_id(),
				'guest_token'  => $guest_token,
				'storage_key'  => CartStorage::get_guest_cart_option_name( $guest_token ),
			)
		);
	}

	/**
	 * Import canonical storage into the active WooCommerce cart.
	 *
	 * Direction:
	 * AICommerce -> WooCommerce
	 *
	 * @param string   $guest_token Guest token.
	 * @param int|null $user_id     User ID.
	 * @return array<string, mixed>
	 */
	public static function import_storage_to_wc_cart( string $guest_token = '', ?int $user_id = null ): array {
		$cart = self::get_wc_cart();
		if ( ! $cart ) {
			return array(
				'success' => false,
				'code'    => 'woocommerce_not_available',
				'message' => __( 'WooCommerce cart is not available.', 'aicommerce' ),
			);
		}

		$context_key = CartContextResolver::get_context_key( $guest_token, $user_id );
		if ( ! self::acquire_lock( $context_key ) ) {
			return array(
				'success' => true,
				'synced'  => false,
				'code'    => 'locked',
				'message' => __( 'Cart sync skipped because another sync is already running.', 'aicommerce' ),
				'version' => self::get_storage_version( $guest_token, $user_id ),
			);
		}

		try {
			$storage_items = ! empty( $user_id )
				? CartStorage::get_user_cart( (int) $user_id )
				: CartStorage::get_cart( $guest_token );

			/** Prune deleted or invalid items before projecting storage into WooCommerce. */
			$sanitized     = CartProjector::sanitize_storage_items( $storage_items );
			$storage_items = $sanitized['items'];

			/** Persist the cleaned cart back into storage so stale lines do not keep reappearing. */
			if ( $sanitized['changed'] ) {
				self::save_storage_items( $guest_token, $user_id, $storage_items );
			}

			$meta                = self::get_storage_meta( $guest_token, $user_id );
			$storage_version     = (int) ( $meta['version'] ?? 0 );
			$storage_fingerprint = (string) ( $meta['fingerprint'] ?? '' );
			$wc_fingerprint      = CartProjector::compute_wc_cart_fingerprint( $cart );
			$session_state       = self::get_synced_state( $context_key );

			/**
			 * Skip rebuild when:
			 * - storage version is unchanged
			 * - storage fingerprint is unchanged
			 * - current Woo cart already matches the synchronized fingerprint
			 */
			if (
				$storage_version > 0 &&
				$storage_fingerprint !== '' &&
				$session_state['version'] === $storage_version &&
				$session_state['fingerprint'] === $storage_fingerprint &&
				$wc_fingerprint === $storage_fingerprint
			) {
				return array(
					'success'      => true,
					'synced'       => false,
					'synced_count' => 0,
					'total_items'  => count( $storage_items ),
					'errors'       => $sanitized['errors'],
					'version'      => $storage_version,
				);
			}

			self::$is_importing = true;

			if ( empty( $storage_items ) ) {
				$wc_items = $cart->get_cart();
				if ( $storage_version <= 1 && ! empty( $wc_items ) ) {
					self::$is_importing = false;
					self::save_storage_items( $guest_token, $user_id, CartProjector::storage_items_from_wc_cart( $cart ) );

					$updated_meta = self::get_storage_meta( $guest_token, $user_id );
					self::set_synced_state(
						$context_key,
						(int) ( $updated_meta['version'] ?? 0 ),
						(string) ( $updated_meta['fingerprint'] ?? '' )
					);

					return array(
						'success'      => true,
						'synced'       => false,
						'synced_count' => 0,
						'total_items'  => count( $wc_items ),
						'errors'       => $sanitized['errors'],
						'version'      => (int) ( $updated_meta['version'] ?? 0 ),
						'code'         => 'preserved_wc_cart',
					);
				}

				$cart->empty_cart();
				$cart->calculate_totals();
				self::persist_cart_session( $cart );
				self::$is_importing = false;
				self::set_synced_state( $context_key, $storage_version, $storage_fingerprint );

				return array(
					'success'      => true,
					'synced'       => false,
					'synced_count' => 0,
					'total_items'  => 0,
					'errors'       => $sanitized['errors'],
					'version'      => $storage_version,
				);
			}

			$notice_snapshot = self::capture_wc_notices();
			$result          = CartProjector::rebuild_wc_cart_from_storage( $storage_items, $cart );
			self::restore_wc_notices( $notice_snapshot );
			$cart->calculate_totals();
			self::persist_cart_session( $cart );
			self::$is_importing = false;
			self::set_synced_state( $context_key, $storage_version, $storage_fingerprint );

			return array(
				'success'      => true,
				'synced'       => true,
				'synced_count' => (int) ( $result['synced_count'] ?? 0 ),
				'total_items'  => count( $storage_items ),
				'errors'       => isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : $sanitized['errors'],
				'version'      => $storage_version,
			);
		} finally {
			self::$is_importing = false;
			self::release_lock( $context_key );
		}
	}

	/**
	 * Export the active WooCommerce cart into canonical storage.
	 *
	 * Direction:
	 * WooCommerce -> AICommerce
	 *
	 * @param string   $guest_token Guest token.
	 * @param int|null $user_id     User ID.
	 * @return bool
	 */
	public static function export_wc_cart_to_storage( string $guest_token = '', ?int $user_id = null ): bool {
		if ( self::$is_importing ) {
			return false;
		}

		$cart = self::get_wc_cart();
		if ( ! $cart ) {
			return false;
		}

		$context_key = CartContextResolver::get_context_key( $guest_token, $user_id );
		if ( ! self::acquire_lock( $context_key ) ) {
			return false;
		}

		try {
			$items          = CartProjector::storage_items_from_wc_cart( $cart );
			$fingerprint    = CartProjector::compute_items_fingerprint( $items );
			$storage_meta   = self::get_storage_meta( $guest_token, $user_id );
			$storage_fp     = (string) ( $storage_meta['fingerprint'] ?? '' );
			$storage_ver    = (int) ( $storage_meta['version'] ?? 0 );

			/** Skip writing when Woo and storage already describe the same logical cart. */
			if ( $fingerprint !== '' && $fingerprint === $storage_fp ) {
				self::set_synced_state( $context_key, $storage_ver, $storage_fp );
				return true;
			}

			self::save_storage_items( $guest_token, $user_id, $items );

			/** Mark the new storage state as already reflected in the current Woo cart. */
			$updated_meta = self::get_storage_meta( $guest_token, $user_id );
			self::set_synced_state(
				$context_key,
				(int) ( $updated_meta['version'] ?? 0 ),
				(string) ( $updated_meta['fingerprint'] ?? '' )
			);

			return true;
		} finally {
			self::release_lock( $context_key );
		}
	}

	/**
	 * Return the active WooCommerce cart instance.
	 *
	 * @return \WC_Cart|null
	 */
	private static function get_wc_cart(): ?\WC_Cart {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
			return null;
		}

		if ( WC()->session && method_exists( WC()->session, 'set_customer_session_cookie' ) ) {
			WC()->session->set_customer_session_cookie( true );
		}

		if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( ! WC()->cart || ! is_a( WC()->cart, 'WC_Cart' ) ) {
			return null;
		}

		return WC()->cart;
	}

	/**
	 * Persist the current Woo cart session payload.
	 *
	 * @param \WC_Cart $cart WooCommerce cart instance.
	 * @return void
	 */
	private static function persist_cart_session( \WC_Cart $cart ): void {
		if ( function_exists( 'WC' ) && WC() && WC()->session ) {
			WC()->session->set( 'cart', $cart->get_cart_for_session() );
		}
	}

	/**
	 * Remove WooCommerce's saved persistent cart for a user after checkout.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private static function clear_wc_persistent_cart( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		delete_user_meta( $user_id, '_woocommerce_persistent_cart_' . get_current_blog_id() );
	}

	/**
	 * Capture the current WooCommerce notice stack before a programmatic sync.
	 *
	 * @return array|null
	 */
	private static function capture_wc_notices(): ?array {
		if ( ! function_exists( 'wc_get_notices' ) ) {
			return null;
		}

		return wc_get_notices();
	}

	/**
	 * Restore the WooCommerce notice stack after a programmatic sync.
	 *
	 * @param array|null $notices Notice snapshot.
	 * @return void
	 */
	private static function restore_wc_notices( ?array $notices ): void {
		if ( null === $notices ) {
			return;
		}

		if ( function_exists( 'wc_set_notices' ) ) {
			wc_set_notices( $notices );
			return;
		}

		if ( function_exists( 'wc_clear_notices' ) ) {
			wc_clear_notices();
		}
	}

	/**
	 * Save storage items for the resolved cart identity.
	 *
	 * @param string   $guest_token Guest token.
	 * @param int|null $user_id     User ID.
	 * @param array    $items       Cart items.
	 * @return void
	 */
	private static function save_storage_items( string $guest_token = '', ?int $user_id = null, array $items = array() ): void {
		if ( ! empty( $user_id ) ) {
			CartStorage::save_user_cart( (int) $user_id, $items );
			return;
		}

		if ( ! empty( $guest_token ) ) {
			CartStorage::save_cart( $guest_token, $items );
		}
	}

	/**
	 * Read storage metadata for the resolved cart identity.
	 *
	 * @param string   $guest_token Guest token.
	 * @param int|null $user_id     User ID.
	 * @return array{version:int,count:int,fingerprint:string}
	 */
	private static function get_storage_meta( string $guest_token = '', ?int $user_id = null ): array {
		if ( ! empty( $user_id ) ) {
			return CartStorage::get_user_cart_meta( (int) $user_id );
		}

		if ( ! empty( $guest_token ) ) {
			return CartStorage::get_cart_meta( $guest_token );
		}

		return array(
			'version'     => 0,
			'count'       => 0,
			'fingerprint' => '',
		);
	}

	/**
	 * Get the current storage version for the resolved cart.
	 *
	 * @param string   $guest_token Guest token.
	 * @param int|null $user_id     User ID.
	 * @return int
	 */
	private static function get_storage_version( string $guest_token = '', ?int $user_id = null ): int {
		$meta = self::get_storage_meta( $guest_token, $user_id );
		return (int) ( $meta['version'] ?? 0 );
	}

	/**
	 * Acquire a short-lived lock for the current cart identity.
	 *
	 * @param string $context_key Stable cart identity key.
	 * @return bool
	 */
	private static function acquire_lock( string $context_key ): bool {
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
			return true;
		}

		$lock_key = 'aicommerce_cart_bridge_lock_' . $context_key;
		if ( (bool) get_transient( $lock_key ) ) {
			return false;
		}

		set_transient( $lock_key, 1, self::LOCK_TTL );
		return true;
	}

	/**
	 * Release the short-lived lock for the current cart identity.
	 *
	 * @param string $context_key Stable cart identity key.
	 * @return void
	 */
	private static function release_lock( string $context_key ): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( 'aicommerce_cart_bridge_lock_' . $context_key );
		}
	}

	/**
	 * Read the last synchronized cart state from Woo session.
	 *
	 * @param string $context_key Stable cart identity key.
	 * @return array{version:int,fingerprint:string}
	 */
	private static function get_synced_state( string $context_key ): array {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
			return array(
				'version'     => 0,
				'fingerprint' => '',
			);
		}

		$state = WC()->session->get( 'aicommerce_cart_bridge_state_' . $context_key, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return array(
			'version'     => isset( $state['version'] ) ? (int) $state['version'] : 0,
			'fingerprint' => isset( $state['fingerprint'] ) ? (string) $state['fingerprint'] : '',
		);
	}

	/**
	 * Persist the last synchronized cart state into Woo session.
	 *
	 * @param string $context_key Stable cart identity key.
	 * @param int    $version     Storage version.
	 * @param string $fingerprint Storage fingerprint.
	 * @return void
	 */
	private static function set_synced_state( string $context_key, int $version, string $fingerprint ): void {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
			return;
		}

		WC()->session->set(
			'aicommerce_cart_bridge_state_' . $context_key,
			array(
				'version'     => $version,
				'fingerprint' => $fingerprint,
			)
		);
	}
}
