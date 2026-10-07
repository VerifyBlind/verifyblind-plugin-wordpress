<?php
namespace VerifyBlind\Placements;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use VerifyBlind\Gate;
use VerifyBlind\Messages;

/**
 * Checkout of carts with targeted products (by product or product category, subcategories included).
 * Classic checkout: box above the form + validation error; block checkout: box before the checkout block +
 * Store API cart error. Both are checked again when the order is created (last line). Passing orders get
 * a meta record and an order note (audit evidence). Guests' cookie results count unless the rule requires
 * an account; one-person rules always need an account.
 */
final class WcCheckout {
	const KEY   = 'wc_checkout';
	const META  = '_verifyblind_checked';
	const NOTED = '_verifyblind_noted';

	public static function label(): string {
		return __( 'WooCommerce checkout (chosen products or categories in the cart)', 'verifyblind' );
	}

	public static function hooks(): void {
		add_action( 'woocommerce_before_checkout_form', array( self::class, 'print_box' ), 5 );
		add_filter( 'render_block', array( self::class, 'render_checkout_block' ), 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( self::class, 'classic_validation' ), 10, 2 );
		add_action( 'woocommerce_store_api_cart_errors', array( self::class, 'store_api_cart_errors' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'classic_create_order' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_created', array( self::class, 'add_note' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'store_api_order' ), 10, 2 );
	}

	public static function needs_account( array $rule ): bool {
		if ( is_user_logged_in() ) {
			return false;
		}
		return ! empty( $rule['unique'] ) || ( isset( $rule['guest_mode'] ) && 'require_account' === $rule['guest_mode'] );
	}

	/** First of $rules the current visitor does not meet (an account counts as part of the rule), or null. */
	public static function refusing_rule( array $rules ): ?array {
		foreach ( $rules as $rule ) {
			if ( self::needs_account( $rule ) || null !== Gate::blocking_rule( array( $rule ) ) ) {
				return $rule;
			}
		}
		return null;
	}

	/** @param mixed $cart \WC_Cart or null */
	public static function blocking( $cart ): ?array {
		return self::refusing_rule( ProductTargets::rules_for_cart( $cart, self::KEY ) );
	}

	public static function cart(): ?\WC_Cart {
		return ( function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ) ? WC()->cart : null;
	}

	public static function message( array $rule ): string {
		return Messages::get( self::needs_account( $rule ) ? 'account_required' : 'checkout_required' );
	}

	public static function box_html( array $rule ): string {
		return Prompt::html(
			$rule,
			array(
				'title'    => __( 'Some products in your cart need verification before you can order.', 'verifyblind' ),
				'login'    => self::needs_account( $rule ),
				'redirect' => wc_get_checkout_url(),
			)
		);
	}

	/** Classic checkout: above the form (outside it), reloads after verifying. */
	public static function print_box(): void {
		$rule = self::blocking( self::cart() );
		if ( null !== $rule ) {
			echo wp_kses_post( self::box_html( $rule ) );
		}
	}

	/**
	 * Block checkout: the box goes in front of the checkout block.
	 *
	 * @param mixed $html
	 * @param mixed $block
	 * @return mixed
	 */
	public static function render_checkout_block( $html, $block ) {
		if ( ! is_array( $block ) || ! isset( $block['blockName'] ) || 'woocommerce/checkout' !== $block['blockName'] ) {
			return $html;
		}
		$rule = self::blocking( self::cart() );
		return null === $rule ? $html : self::box_html( $rule ) . $html;
	}

	/** @param mixed $errors WP_Error */
	public static function classic_validation( $data, $errors ): void {
		$rule = self::blocking( self::cart() );
		if ( null !== $rule && $errors instanceof \WP_Error ) {
			$errors->add( 'verifyblind_required', self::message( $rule ) );
		}
	}

	/**
	 * Store API (block checkout): a cart error makes POST /checkout fail with 409; the cart block shows it too.
	 *
	 * @param mixed $errors WP_Error
	 * @param mixed $cart   WC_Cart
	 */
	public static function store_api_cart_errors( $errors, $cart = null ): void {
		$rule = self::blocking( $cart instanceof \WC_Cart ? $cart : self::cart() );
		if ( null !== $rule && $errors instanceof \WP_Error ) {
			$errors->add( 'verifyblind_required', self::message( $rule ) );
		}
	}

	/**
	 * Classic checkout, last line: the order's own items, right before it is saved. WooCommerce turns the
	 * exception into a checkout error.
	 *
	 * @param mixed $order WC_Order
	 * @throws \Exception
	 */
	public static function classic_create_order( $order, $data = array() ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$rules = ProductTargets::rules_for_order( $order, self::KEY );
		if ( ! $rules ) {
			return;
		}
		$rule = self::refusing_rule( $rules );
		if ( null !== $rule ) {
			throw new \Exception( self::message( $rule ) );
		}
		self::record( $order, $rules ); // the note follows in add_note() once the order has an id
	}

	/**
	 * Block checkout, last line when the order is placed (POST). Draft updates (PUT) pass.
	 *
	 * @param mixed $order   WC_Order
	 * @param mixed $request WP_REST_Request
	 * @throws RouteException
	 */
	public static function store_api_order( $order, $request = null ): void {
		if ( ! $order instanceof \WC_Order || ( $request instanceof \WP_REST_Request && 'POST' !== $request->get_method() ) ) {
			return;
		}
		$rules = ProductTargets::rules_for_order( $order, self::KEY );
		if ( ! $rules ) {
			return;
		}
		$rule = self::refusing_rule( $rules );
		if ( null !== $rule ) {
			throw new RouteException( 'verifyblind_required', self::message( $rule ), 403 );
		}
		self::record( $order, $rules );
		self::add_note( $order );
	}

	public static function record( \WC_Order $order, array $rules ): void {
		$entries = array();
		foreach ( $rules as $rule ) {
			$entries[] = array(
				'rule'       => $rule['id'],
				'condition'  => $rule['age'],
				'one_person' => ! empty( $rule['unique'] ),
				'time'       => gmdate( 'Y-m-d H:i:s' ),
			);
		}
		$order->update_meta_data( self::META, $entries );
	}

	/** @param mixed $order WC_Order */
	public static function add_note( $order ): void {
		if ( ! $order instanceof \WC_Order || $order->get_id() <= 0 || '' !== (string) $order->get_meta( self::NOTED ) ) {
			return;
		}
		$entries = $order->get_meta( self::META );
		if ( ! is_array( $entries ) || ! $entries ) {
			return;
		}
		$order->add_order_note( self::note_text( $entries ) );
		$order->update_meta_data( self::NOTED, '1' );
		$order->save();
	}

	public static function note_text( array $entries ): string {
		$parts = array();
		foreach ( $entries as $e ) {
			if ( isset( $e['condition'] ) && '' !== $e['condition'] ) {
				/* translators: %s: age condition such as 18+ */
				$parts[] = sprintf( __( 'Age verified (%s) with VerifyBlind.', 'verifyblind' ), $e['condition'] );
			}
			if ( ! empty( $e['one_person'] ) ) {
				$parts[] = __( 'One-person check passed with VerifyBlind.', 'verifyblind' );
			}
		}
		return implode( ' ', array_values( array_unique( $parts ) ) );
	}
}
