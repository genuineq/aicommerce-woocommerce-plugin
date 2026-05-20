<?php
/**
 * Cart sync context resolver.
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the current cart identity for guest and logged-in flows.
 */
class CartContextResolver {
	/**
	 * Validate guest token format before using it as a cart identity.
	 *
	 * @param string $guest_token Guest token candidate.
	 * @return bool
	 */
	public static function is_valid_guest_token( string $guest_token ): bool {
		return (bool) preg_match( '/^guest_\d+_[a-zA-Z0-9]+_[a-f0-9]{8}$/', $guest_token );
	}

	/**
	 * Resolve guest/user cart context from the current browser request.
	 *
	 * @return array{guest_token:string,user_id:int}
	 */
	public static function resolve_current_context(): array {
		if ( is_user_logged_in() ) {
			return array(
				'guest_token' => '',
				'user_id'     => (int) get_current_user_id(),
			);
		}

		$guest_token = '';
		if ( isset( $_COOKIE['aicommerce_guest_token'] ) ) {
			$guest_token = sanitize_text_field( wp_unslash( $_COOKIE['aicommerce_guest_token'] ) );
		}

		if ( ! self::is_valid_guest_token( $guest_token ) ) {
			$guest_token = '';
		}

		return array(
			'guest_token' => $guest_token,
			'user_id'     => 0,
		);
	}

	/**
	 * Resolve a cart context from raw request data.
	 *
	 * @param mixed  $raw_guest_token Raw guest token value.
	 * @param mixed  $raw_user_id     Raw user ID value.
	 * @param bool   $allow_session_fallback Allow logged-in fallback when user_id is omitted.
	 * @return array{guest_token:string,user_id:?int}
	 */
	public static function resolve_request_context( $raw_guest_token, $raw_user_id, bool $allow_session_fallback = false ): array {
		$guest_token = sanitize_text_field( (string) $raw_guest_token );
		if ( ! self::is_valid_guest_token( $guest_token ) ) {
			$guest_token = '';
		}

		$user_id = null;
		if ( ! empty( $raw_user_id ) || ( isset( $raw_user_id ) && '' !== $raw_user_id && null !== $raw_user_id ) ) {
			$user_id = absint( $raw_user_id );
			if ( $user_id <= 0 ) {
				$user_id = null;
			}
		}

		if ( $allow_session_fallback && empty( $guest_token ) && empty( $user_id ) && is_user_logged_in() ) {
			$user_id = (int) get_current_user_id();
		}

		return array(
			'guest_token' => $guest_token,
			'user_id'     => $user_id ?: null,
		);
	}

	/**
	 * Build a stable lock/session suffix for a cart identity.
	 *
	 * @param string   $guest_token Guest token.
	 * @param int|null $user_id     User ID.
	 * @return string
	 */
	public static function get_context_key( string $guest_token = '', ?int $user_id = null ): string {
		if ( ! empty( $user_id ) ) {
			return 'user_' . (int) $user_id;
		}

		if ( ! empty( $guest_token ) ) {
			return 'guest_' . md5( $guest_token );
		}

		return 'anonymous';
	}
}
