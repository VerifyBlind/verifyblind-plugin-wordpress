<?php
namespace VerifyBlind\Placements;

defined( 'ABSPATH' ) || exit;

use VerifyBlind\Gate;
use VerifyBlind\Identities;
use VerifyBlind\Messages;
use VerifyBlind\Rules;

/**
 * Coupons valid only for visitors who meet a rule. WooCommerce re-validates coupons at checkout, so the
 * refusal holds there too. A one-person rule makes a coupon usable once per person (on any account): the
 * person is recorded on the coupon — as a keyed hash of the person code — when the order is paid.
 */
final class WcCoupon {
	const KEY     = 'wc_coupon';
	const META    = '_verifyblind_people';
	const SESSION = 'verifyblind_coupon_rule';

	public static function label(): string {
		return __( 'WooCommerce coupons (chosen coupons)', 'verifyblind' );
	}

	public static function hooks(): void {
		add_filter( 'woocommerce_coupon_is_valid', array( self::class, 'is_valid' ), 10, 3 );
		add_action( 'woocommerce_order_status_processing', array( self::class, 'record_use' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'record_use' ), 10, 2 );
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
				if ( null !== $person && in_array( $person, self::people( $coupon ), true ) ) {
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
		return '' === $code ? null : hash_hmac( 'sha256', $code, wp_salt( 'auth' ) );
	}

	public static function people( \WC_Coupon $coupon ): array {
		$people = get_post_meta( (int) $coupon->get_id(), self::META, true );
		return is_array( $people ) ? $people : array();
	}

	/** @param mixed $order WC_Order */
	public static function record_use( $order_id, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$person = self::person_key( (int) $order->get_customer_id() );
		if ( null === $person ) {
			return;
		}
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
			$people = get_post_meta( $coupon_id, self::META, true );
			$people = is_array( $people ) ? $people : array();
			if ( ! in_array( $person, $people, true ) ) {
				$people[] = $person;
				update_post_meta( $coupon_id, self::META, $people );
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
