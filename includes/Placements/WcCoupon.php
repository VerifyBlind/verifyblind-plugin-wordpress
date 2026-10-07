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
 *
 * The coupon check WooCommerce runs while validating the cart is what the visitor sees. The authoritative check runs
 * when the order is placed (classic and Store API checkout) or paid for later (pay-for-order): it refuses the order
 * when another live order of the same person holds the coupon, under a MySQL named lock per coupon and person that
 * is held until the use is recorded, so two parallel checkouts cannot both pass.
 */
final class WcCoupon {
	const KEY        = 'wc_coupon';
	const META       = '_verifyblind_people';
	const ORDER_META = '_verifyblind_coupon_people';
	const KEY_OPTION = 'verifyblind_person_key';
	const SESSION    = 'verifyblind_coupon_rule';

	/** Seconds a checkout waits for another checkout of the same person and coupon. */
	const LOCK_WAIT = 5;

	/** Order statuses that do not hold a coupon use (WooCommerce releases its usage count on these too). */
	const DEAD = array( 'cancelled', 'failed', 'trash', 'checkout-draft' );

	/** @var array<string, true> named locks this request holds */
	private static $locks = array();

	/** @var string[] routes of the REST requests being served (nested requests stack) */
	private static $routes = array();

	/** @var bool */
	private static $shutdown_hooked = false;

	public static function label(): string {
		return __( 'WooCommerce coupons (chosen coupons)', 'verifyblind' );
	}

	public static function hooks(): void {
		add_filter( 'woocommerce_coupon_is_valid', array( self::class, 'is_valid' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'check_classic_order' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'check_store_api_order' ), 10, 2 );
		add_action( 'woocommerce_before_pay_action', array( self::class, 'check_pay_for_order' ) );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'rest_enter' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'rest_leave' ), 10, 3 );
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
					return array( 'message' => Messages::get( 'coupon_no_person' ), 'rule' => null );
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
		$autoload = function_exists( 'wp_determine_option_autoload_value' ) ? 'off' : 'no';
		$fresh    = wp_generate_password( 64, true, true );
		// INSERT IGNORE: when two requests create it at once the first key stays (add_option would overwrite it).
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", self::KEY_OPTION, $fresh, $autoload ) );
		// An empty key counts as missing and is replaced (only while it is still empty, so concurrent requests agree).
		// Losing or replacing the key forgets every recorded use: the hashes on coupons and orders no longer match anyone.
		$replaced = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s, autoload = %s WHERE option_name = %s AND option_value = ''", $fresh, $autoload, self::KEY_OPTION ) );
		if ( $replaced ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator note, no personal data.
			error_log( 'VerifyBlind: the one-person coupon key was empty and has been replaced; coupon uses recorded before it are forgotten.' );
			wp_cache_delete( 'alloptions', 'options' );
		}
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

	/**
	 * The order the running checkout would resume, read only from that checkout's own session key: the classic
	 * checkout resumes `order_awaiting_payment` (pending/failed, same cart), the Store API `store_api_draft_order`
	 * (its draft, or pending/failed with the same cart). Reading the other key would let one checkout pass on an
	 * order the other one never resumes.
	 */
	private static function resumed_order(): ?\WC_Order {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart ) {
			return null;
		}
		$store = self::in_store_api();
		$id    = absint( WC()->session->get( $store ? 'store_api_draft_order' : 'order_awaiting_payment' ) );
		$order = $id ? wc_get_order( $id ) : false;
		if ( ! $order instanceof \WC_Order || (int) $order->get_customer_id() !== get_current_user_id() ) {
			return null;
		}
		if ( ( $store && $order->has_status( 'checkout-draft' ) ) || ( $order->has_status( array( 'pending', 'failed' ) ) && $order->has_cart_hash( WC()->cart->get_cart_hash() ) ) ) {
			return $order;
		}
		return null;
	}

	/**
	 * @param mixed $response
	 * @param mixed $handler
	 * @param mixed $request WP_REST_Request
	 * @return mixed
	 */
	public static function rest_enter( $response, $handler, $request ) {
		self::$routes[] = $request instanceof \WP_REST_Request ? (string) $request->get_route() : '';
		return $response;
	}

	/**
	 * @param mixed $response
	 * @param mixed $handler
	 * @param mixed $request
	 * @return mixed
	 */
	public static function rest_leave( $response, $handler, $request ) {
		array_pop( self::$routes );
		return $response;
	}

	/** Whether the request being served is a Store API one (block cart/checkout). */
	private static function in_store_api(): bool {
		$route = end( self::$routes );
		return is_string( $route ) && 0 === strpos( $route, '/wc/store/' );
	}

	/** @return int[] the coupons on the order that a one-person rule covers */
	private static function one_person_coupons( \WC_Order $order ): array {
		$out = array();
		foreach ( $order->get_coupon_codes() as $code ) {
			$coupon_id = (int) wc_get_coupon_id_by_code( $code );
			if ( $coupon_id <= 0 || in_array( $coupon_id, $out, true ) ) {
				continue;
			}
			foreach ( self::rules_for_coupon( $coupon_id ) as $rule ) {
				if ( ! empty( $rule['unique'] ) ) {
					$out[] = $coupon_id;
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * The authoritative check when an order is placed or paid for: refuses it when another live order of the same
	 * person holds one of its one-person coupons. On success the locks stay held until the use is recorded
	 * (record_use) or the request ends.
	 *
	 * @return array|null array( 'code' => message code, 'message' => string ) when the order is refused
	 */
	private static function claim( \WC_Order $order ): ?array {
		$coupons = self::one_person_coupons( $order );
		if ( ! $coupons ) {
			return null;
		}
		$code = null;
		$uid  = (int) $order->get_customer_id();
		if ( $uid <= 0 ) {
			$code = 'coupon_login';
		} else {
			$person = self::person_key( $uid );
			if ( null === $person ) {
				$code = 'coupon_no_person';
			} else {
				foreach ( $coupons as $coupon_id ) {
					if ( ! self::lock( $coupon_id, $person ) ) {
						$code = 'coupon_busy';
						break;
					}
					if ( array() !== self::holders( $coupon_id, $person, (int) $order->get_id() ) ) {
						$code = 'coupon_used';
						break;
					}
				}
			}
		}
		if ( null === $code ) {
			return null;
		}
		self::release_locks();
		return array( 'code' => $code, 'message' => Messages::get( $code ) );
	}

	/**
	 * Classic checkout, just before the order is saved.
	 *
	 * @param mixed $order WC_Order
	 * @throws \Exception WooCommerce shows its message and does not save the order.
	 */
	public static function check_classic_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$refusal = self::claim( $order );
		if ( null !== $refusal ) {
			throw new \Exception( $refusal['message'] );
		}
	}

	/**
	 * Store API checkout (POST /checkout, and POST /checkout/{id} paying an existing order), before payment.
	 *
	 * @param mixed $order   WC_Order
	 * @param mixed $request WP_REST_Request
	 * @throws \Exception RouteException (409) the Store API turns into an error response.
	 */
	public static function check_store_api_order( $order, $request = null ): void {
		if ( ! $order instanceof \WC_Order || ! $request instanceof \WP_REST_Request || 'POST' !== $request->get_method() ) {
			return;
		}
		$refusal = self::claim( $order );
		if ( null === $refusal ) {
			return;
		}
		$class = '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException';
		if ( class_exists( $class ) ) {
			throw new $class( 'verifyblind_' . $refusal['code'], esc_html( $refusal['message'] ), 409 );
		}
		throw new \Exception( esc_html( $refusal['message'] ) );
	}

	/**
	 * Classic "pay for order" page: an error notice stops WooCommerce's pay_action before payment.
	 *
	 * @param mixed $order WC_Order
	 */
	public static function check_pay_for_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$refusal = self::claim( $order );
		if ( null !== $refusal ) {
			wc_add_notice( $refusal['message'], 'error' );
		}
	}

	private static function lock( int $coupon_id, string $person ): bool {
		global $wpdb;
		$name = 'vb_c_' . md5( $coupon_id . '|' . $person );
		if ( isset( self::$locks[ $name ] ) ) {
			return true;
		}
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_WAIT ) ) ) {
			return false;
		}
		self::$locks[ $name ] = true;
		if ( ! self::$shutdown_hooked ) {
			self::$shutdown_hooked = true;
			add_action( 'shutdown', array( self::class, 'release_locks' ) ); // the order failed after the check
		}
		return true;
	}

	/** Gives back the named locks this request holds. */
	public static function release_locks(): void {
		global $wpdb;
		foreach ( array_keys( self::$locks ) as $name ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
		self::$locks = array();
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
		if ( null !== $order ) {
			self::record_order( $order );
		}
		// The use is recorded: a parallel checkout waiting on the lock now sees this order.
		self::release_locks();
	}

	private static function record_order( \WC_Order $order ): void {
		$person = self::person_key( (int) $order->get_customer_id() );
		if ( null === $person ) {
			return;
		}
		$pairs   = self::order_pairs( $order );
		$changed = false;
		foreach ( self::one_person_coupons( $order ) as $coupon_id ) {
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
