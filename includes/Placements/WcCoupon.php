<?php
namespace VerifyBlind\Placements;

defined( 'ABSPATH' ) || exit;

use VerifyBlind\Gate;
use VerifyBlind\Identities;
use VerifyBlind\Messages;
use VerifyBlind\Rules;

/**
 * Coupons valid only for visitors who meet a rule. WooCommerce re-validates coupons at checkout, so the
 * refusal holds there too. A one-person rule makes a coupon usable once per person (on any account): like
 * WooCommerce's own usage counts, the person is recorded on the coupon — as a keyed hash of the person
 * code, one post-meta row per person — when the order is placed and on every live status, and released
 * when the order is cancelled, fails or is trashed/deleted (a refund keeps the use).
 */
final class WcCoupon {
	const KEY        = 'wc_coupon';
	const META       = '_verifyblind_people';
	const ORDER_META = '_verifyblind_coupon_people';
	const KEY_OPTION = 'verifyblind_person_key';
	const SESSION    = 'verifyblind_coupon_rule';

	/** Order statuses that do not hold a coupon use (WooCommerce releases its usage count on these too). */
	const DEAD = array( 'cancelled', 'failed', 'trash', 'checkout-draft' );

	public static function label(): string {
		return __( 'WooCommerce coupons (chosen coupons)', 'verifyblind' );
	}

	public static function hooks(): void {
		add_filter( 'woocommerce_coupon_is_valid', array( self::class, 'is_valid' ), 10, 3 );
		add_action( 'woocommerce_checkout_order_created', array( self::class, 'record_use' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'record_use' ) );
		foreach ( array( 'pending', 'on-hold', 'processing', 'completed' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( self::class, 'record_use' ), 10, 2 );
		}
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'release_use' ), 10, 2 );
		add_action( 'woocommerce_order_status_failed', array( self::class, 'release_use' ), 10, 2 );
		add_action( 'woocommerce_trash_order', array( self::class, 'release_use' ) );
		add_action( 'woocommerce_before_delete_order', array( self::class, 'release_use' ), 10, 2 );
		add_action( 'woocommerce_before_cart', array( self::class, 'print_box' ) );
		add_action( 'woocommerce_before_checkout_form', array( self::class, 'print_box' ), 6 );
		add_filter( 'render_block', array( self::class, 'render_cart_block' ), 10, 2 );
	}

	/** @return array[] */
	public static function rules_for_coupon( int $coupon_id ): array {
		$out = array();
		foreach ( Rules::enabled( self::KEY ) as $rule ) {
			if ( in_array( $coupon_id, $rule['targets']['post_ids'], true ) ) {
				$out[] = $rule;
			}
		}
		return $out;
	}

	/** @return array|null array( 'message' => string, 'rule' => array|null ) for the first rule refusing the visitor */
	public static function refusal( \WC_Coupon $coupon, array $rules ): ?array {
		foreach ( $rules as $rule ) {
			$unique = ! empty( $rule['unique'] );
			if ( $unique && ! is_user_logged_in() ) {
				return array( 'message' => Messages::get( 'coupon_login' ), 'rule' => $rule );
			}
			if ( null !== Gate::blocking_rule( array( $rule ) ) ) {
				return array( 'message' => Messages::get( 'coupon_required' ), 'rule' => $rule );
			}
			if ( $unique ) {
				$person = self::person_key( get_current_user_id() );
				if ( null === $person ) {
					// Fail closed: without a person code the use cannot be tied to a person. This is also the case of
					// demo-card verifications in test mode (a demo card never binds an identity), so they cannot use
					// one-person coupons.
					return array( 'message' => Messages::get( 'coupon_required' ), 'rule' => null );
				}
				if ( self::used( (int) $coupon->get_id(), $person ) ) {
					return array( 'message' => Messages::get( 'coupon_used' ), 'rule' => null );
				}
			}
		}
		return null;
	}

	/**
	 * @param mixed $valid
	 * @param mixed $coupon    WC_Coupon
	 * @param mixed $discounts WC_Discounts
	 * @return mixed
	 * @throws \Exception WooCommerce shows its message as the coupon error.
	 */
	public static function is_valid( $valid, $coupon, $discounts = null ) {
		if ( ! $valid || ! $coupon instanceof \WC_Coupon ) {
			return $valid;
		}
		$rules = self::rules_for_coupon( (int) $coupon->get_id() );
		if ( ! $rules ) {
			return $valid;
		}
		// A shop manager adding a coupon to an order by hand in wp-admin.
		if ( $discounts instanceof \WC_Discounts && $discounts->get_object() instanceof \WC_Order && current_user_can( 'edit_shop_orders' ) ) {
			return $valid;
		}
		$refusal = self::refusal( $coupon, $rules );
		if ( null === $refusal ) {
			return $valid;
		}
		if ( null !== $refusal['rule'] ) {
			self::remember( $refusal['rule'] );
		}
		throw new \Exception( $refusal['message'] );
	}

	/** A keyed hash of the account's person code (identity row, or the code a flag-accepted duplicate carries). */
	public static function person_key( int $user_id ): ?string {
		if ( $user_id <= 0 ) {
			return null;
		}
		$row  = Identities::find_by_wp_user( $user_id );
		$code = $row ? (string) $row['vb_user_id'] : (string) get_user_meta( $user_id, 'verifyblind_flag_person', true );
		return '' === $code ? null : hash_hmac( 'sha256', $code, self::secret() );
	}

	/** The site's own key for person hashes, created once (not autoloaded). */
	private static function secret(): string {
		global $wpdb;
		$key = get_option( self::KEY_OPTION );
		if ( is_string( $key ) && '' !== $key ) {
			return $key;
		}
		// INSERT IGNORE: when two requests create it at once the first key stays (add_option would overwrite it).
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", self::KEY_OPTION, wp_generate_password( 64, true, true ), function_exists( 'wp_determine_option_autoload_value' ) ? 'off' : 'no' ) );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ self::KEY_OPTION ] ) ) {
			unset( $notoptions[ self::KEY_OPTION ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
		wp_cache_delete( self::KEY_OPTION, 'options' );
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::KEY_OPTION ) );
	}

	/** @return string[] the person hashes recorded on the coupon */
	public static function people( \WC_Coupon $coupon ): array {
		return array_values( array_map( 'strval', (array) get_post_meta( (int) $coupon->get_id(), self::META, false ) ) );
	}

	public static function has_person( int $coupon_id, string $person ): bool {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s AND meta_value = %s LIMIT 1", $coupon_id, self::META, $person ) );
	}

	/**
	 * Whether the person already used the coupon. The order this checkout resumes (a pending/failed order with the
	 * same cart, or the block checkout's draft — WooCommerce reuses it instead of creating a new one) does not count,
	 * so a customer whose payment did not go through can try again.
	 */
	private static function used( int $coupon_id, string $person ): bool {
		if ( ! self::has_person( $coupon_id, $person ) ) {
			return false;
		}
		$resumed = self::resumed_order();
		if ( null === $resumed || ! in_array( array( $coupon_id, $person ), self::order_pairs( $resumed ), true ) ) {
			return true;
		}
		return array() !== self::holders( $coupon_id, $person, (int) $resumed->get_id() );
	}

	private static function resumed_order(): ?\WC_Order {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart ) {
			return null;
		}
		foreach ( array( 'order_awaiting_payment', 'store_api_draft_order' ) as $key ) {
			$id    = absint( WC()->session->get( $key ) );
			$order = $id ? wc_get_order( $id ) : false;
			if ( ! $order instanceof \WC_Order || (int) $order->get_customer_id() !== get_current_user_id() ) {
				continue;
			}
			if ( $order->has_status( 'checkout-draft' ) || ( $order->has_status( array( 'pending', 'failed' ) ) && $order->has_cart_hash( WC()->cart->get_cart_hash() ) ) ) {
				return $order;
			}
		}
		return null;
	}

	/** @return array[] the [coupon id, person hash] pairs an order recorded */
	private static function order_pairs( \WC_Order $order ): array {
		$pairs = $order->get_meta( self::ORDER_META );
		$out   = array();
		foreach ( is_array( $pairs ) ? $pairs : array() as $pair ) {
			if ( is_array( $pair ) && 2 === count( $pair ) ) {
				$out[] = array( (int) $pair[0], (string) $pair[1] );
			}
		}
		return $out;
	}

	/** @return int[] orders other than $exclude that recorded this person for this coupon and still hold it */
	private static function holders( int $coupon_id, string $person, int $exclude ): array {
		global $wpdb;
		$table = $wpdb->postmeta;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'get_table_for_order_meta' ) ) {
			$table = (string) \Automattic\WooCommerce\Utilities\OrderUtil::get_table_for_order_meta();
		}
		$column = $wpdb->postmeta === $table ? 'post_id' : 'order_id';
		// The table and column names come from WordPress/WooCommerce, not from input.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT {$column} FROM {$table} WHERE meta_key = %s AND meta_value LIKE %s AND {$column} <> %d", self::ORDER_META, '%' . $wpdb->esc_like( $person ) . '%', $exclude ) );
		$out = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( (int) $id );
			if ( $order instanceof \WC_Order && ! $order->has_status( self::DEAD ) && in_array( array( $coupon_id, $person ), self::order_pairs( $order ), true ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * @param mixed $order
	 * @return \WC_Order|null
	 */
	private static function order_of( $order_id, $order ) {
		if ( $order instanceof \WC_Order ) {
			return $order;
		}
		if ( $order_id instanceof \WC_Order ) {
			return $order_id;
		}
		$order = is_numeric( $order_id ) ? wc_get_order( (int) $order_id ) : false;
		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * Records the order's customer on each one-person coupon of the order (idempotent).
	 *
	 * @param mixed $order_id order id, or the order (order-created hooks)
	 * @param mixed $order    WC_Order
	 */
	public static function record_use( $order_id, $order = null ): void {
		$order = self::order_of( $order_id, $order );
		if ( null === $order ) {
			return;
		}
		$person = self::person_key( (int) $order->get_customer_id() );
		if ( null === $person ) {
			return;
		}
		$pairs   = self::order_pairs( $order );
		$changed = false;
		foreach ( $order->get_coupon_codes() as $code ) {
			$coupon_id = (int) wc_get_coupon_id_by_code( $code );
			if ( $coupon_id <= 0 ) {
				continue;
			}
			$unique = false;
			foreach ( self::rules_for_coupon( $coupon_id ) as $rule ) {
				$unique = $unique || ! empty( $rule['unique'] );
			}
			if ( ! $unique ) {
				continue;
			}
			if ( ! self::has_person( $coupon_id, $person ) ) {
				add_post_meta( $coupon_id, self::META, $person );
			}
			if ( ! in_array( array( $coupon_id, $person ), $pairs, true ) ) {
				$pairs[] = array( $coupon_id, $person );
				$changed = true;
			}
		}
		if ( $changed ) {
			$order->update_meta_data( self::ORDER_META, $pairs );
			$order->save_meta_data();
		}
	}

	/**
	 * A dead order gives back the uses it recorded, unless another order of the same person still holds the coupon.
	 *
	 * @param mixed $order_id
	 * @param mixed $order WC_Order
	 */
	public static function release_use( $order_id, $order = null ): void {
		$order = self::order_of( $order_id, $order );
		if ( null === $order ) {
			return;
		}
		foreach ( self::order_pairs( $order ) as $pair ) {
			if ( array() === self::holders( $pair[0], $pair[1], (int) $order->get_id() ) ) {
				delete_post_meta( $pair[0], self::META, $pair[1] );
			}
		}
	}

	private static function remember( array $rule ): void {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION, $rule['id'] );
		}
	}

	/** The rule of the coupon this visitor was last refused, while it still refuses them. */
	public static function pending_rule(): ?array {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}
		$id   = (string) WC()->session->get( self::SESSION );
		$rule = '' === $id ? null : Rules::get( $id );
		if ( null === $rule || empty( $rule['enabled'] ) || self::KEY !== $rule['placement'] || ( ! Prompt::needs_login( $rule ) && null === Gate::blocking_rule( array( $rule ) ) ) ) {
			WC()->session->set( self::SESSION, null );
			return null;
		}
		return $rule;
	}

	public static function box_html( array $rule ): string {
		return Prompt::html( $rule, array( 'title' => __( 'Verify with VerifyBlind to use this coupon.', 'verifyblind' ) ) );
	}

	/** Classic cart and checkout pages. */
	public static function print_box(): void {
		$rule = self::pending_rule();
		if ( null !== $rule ) {
			echo wp_kses_post( self::box_html( $rule ) );
		}
	}

	/**
	 * Cart and checkout blocks.
	 *
	 * @param mixed $html
	 * @param mixed $block
	 * @return mixed
	 */
	public static function render_cart_block( $html, $block ) {
		if ( ! is_array( $block ) || ! isset( $block['blockName'] ) || ! in_array( $block['blockName'], array( 'woocommerce/cart', 'woocommerce/checkout' ), true ) ) {
			return $html;
		}
		$rule = self::pending_rule();
		return null === $rule ? $html : self::box_html( $rule ) . $html;
	}
}
