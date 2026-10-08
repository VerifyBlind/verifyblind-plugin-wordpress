<?php
namespace VerifyBlind\Placements;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use VerifyBlind\Evaluator;
use VerifyBlind\Gate;
use VerifyBlind\Messages;
use VerifyBlind\Owner;
use VerifyBlind\Results;
use VerifyBlind\Rules;
use VerifyBlind\Settings;

/**
 * Checkout of carts with targeted products (by product or product category, subcategories included), and of every
 * cart while a shop entrance (wc_site) rule is on.
 * Classic checkout: box above the form + validation error; block checkout: box before the checkout block +
 * Store API cart error. Both are checked again when the order is created (last line). Passing orders get
 * a meta record and an order note (audit evidence). Guests' cookie results count unless the rule requires
 * an account; one-person rules always need an existing account. A guest creating their account in the same
 * checkout meets "require an account" with a fresh result, which follows them into the new account.
 */
final class WcCheckout {
	const KEY   = 'wc_checkout';
	const META  = '_verifyblind_checked';
	const NOTED = '_verifyblind_noted';

	public static function label(): string {
		return __( 'WooCommerce checkout (chosen products or categories in the cart)', 'verifyblind' );
	}

	/** @var bool[] one entry per REST request being served: whether it is a Store API checkout that creates the customer's account */
	private static $creates_account = array();
	/** @var bool whether this request's classic checkout validation saw a guest creating their account */
	private static $classic_creating = false;

	/** Tests: forget what this request's checkout said about creating an account. */
	public static function reset(): void {
		self::$classic_creating = false;
		self::$creates_account  = array();
	}

	public static function hooks(): void {
		add_action( 'woocommerce_before_checkout_form', array( self::class, 'print_box' ), 5 );
		add_filter( 'render_block', array( self::class, 'render_checkout_block' ), 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( self::class, 'classic_validation' ), 10, 2 );
		add_action( 'woocommerce_store_api_cart_errors', array( self::class, 'store_api_cart_errors' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'classic_create_order' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_created', array( self::class, 'add_note' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'store_api_order' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'store_api_order_processed' ) );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'rest_enter' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'rest_leave' ), 10, 3 );
	}

	/**
	 * Whether this rule needs the visitor to have an account. One-person rules always need an existing account (the
	 * person is bound to an account at its own sign-up). "Require an account" rules are also met by a guest who is
	 * creating the account in this very checkout ($creating).
	 */
	public static function needs_account( array $rule, bool $creating = false ): bool {
		if ( is_user_logged_in() ) {
			return false;
		}
		if ( ! empty( $rule['unique'] ) ) {
			return true;
		}
		return ! $creating && isset( $rule['guest_mode'] ) && 'require_account' === $rule['guest_mode'];
	}

	/**
	 * First of $rules the current visitor does not meet (an account counts as part of the rule), or null.
	 *
	 * @param bool $creating the visitor is a guest creating their account in this checkout: a "require an account"
	 *                       rule is then judged on the guest's FRESH results - the ones that follow into the new
	 *                       account (Results::CARRY_OVER_SECONDS) - so the order placed by the new account still passes.
	 */
	public static function refusing_rule( array $rules, bool $creating = false ): ?array {
		$creating = $creating && ! is_user_logged_in();
		foreach ( $rules as $rule ) {
			if ( self::needs_account( $rule, $creating ) ) {
				return $rule;
			}
			if ( $creating && isset( $rule['guest_mode'] ) && 'require_account' === $rule['guest_mode'] ) {
				if ( ! self::guest_meets_freshly( $rule ) ) {
					return $rule;
				}
				continue;
			}
			if ( null !== Gate::blocking_rule( array( $rule ) ) ) {
				return $rule;
			}
		}
		return null;
	}

	private static function guest_meets_freshly( array $rule ): bool {
		$owner = Owner::current( false );
		if ( null === $owner ) {
			return false;
		}
		$validity = (int) ( isset( $rule['validity_days'] ) ? $rule['validity_days'] : 0 );
		return Evaluator::conditions_satisfy( Results::passed_conditions( $owner, $validity, Settings::test_mode(), Results::CARRY_OVER_SECONDS ), $rule );
	}

	/**
	 * @param mixed $cart     \WC_Cart or null
	 * @param bool  $creating see refusing_rule()
	 */
	public static function blocking( $cart, bool $creating = false ): ?array {
		return self::refusing_rule( self::rules_for_cart( $cart ), $creating );
	}

	/**
	 * Rules covering the cart: the wc_checkout rules of its products, and the shop entrance (wc_site) rules, which
	 * cover every cart - the entrance page gate alone leaves the Store API and wc-ajax checkout open.
	 *
	 * @param mixed $cart \WC_Cart or null
	 * @return array[]
	 */
	public static function rules_for_cart( $cart ): array {
		$rules = ProductTargets::rules_for_cart( $cart, self::KEY );
		if ( $cart instanceof \WC_Cart && ! $cart->is_empty() ) {
			$rules = array_merge( $rules, Rules::enabled( WcSite::KEY ) );
		}
		return $rules;
	}

	/** @return array[] see rules_for_cart() */
	public static function rules_for_order( \WC_Order $order ): array {
		$rules = ProductTargets::rules_for_order( $order, self::KEY );
		if ( count( $order->get_items() ) > 0 ) {
			$rules = array_merge( $rules, Rules::enabled( WcSite::KEY ) );
		}
		return $rules;
	}

	public static function cart(): ?\WC_Cart {
		return ( function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ) ? WC()->cart : null;
	}

	public static function message( array $rule, bool $creating = false ): string {
		return Messages::get( self::needs_account( $rule, $creating ) ? 'account_required' : 'checkout_required' );
	}

	/** @param bool $creating see refusing_rule() */
	public static function box_html( array $rule, bool $creating = false ): string {
		return Prompt::html(
			$rule,
			array(
				'title'    => __( 'Some products in your cart need verification before you can order.', 'verifyblind' ),
				'login'    => self::needs_account( $rule, $creating ),
				'redirect' => wc_get_checkout_url(),
			)
		);
	}

	/**
	 * What the checkout page shows a guest: when the shop lets guests create their account at checkout, a "require an
	 * account" rule is shown as the verification itself (the account is created with the order); the order without an
	 * account is still refused with "log in or create an account".
	 */
	private static function display_creating(): bool {
		return ! is_user_logged_in() && function_exists( 'WC' ) && WC()->checkout()->is_registration_enabled();
	}

	/** Classic checkout: above the form (outside it), reloads after verifying. */
	public static function print_box(): void {
		$creating = self::display_creating();
		$rule     = self::blocking( self::cart(), $creating );
		if ( null !== $rule ) {
			echo wp_kses_post( self::box_html( $rule, $creating ) );
		}
	}

	/**
	 * Block checkout: the box goes in front of the checkout block, and so does the sign-up box when a guest can create
	 * their account in this checkout (Registration::checkout_box_html()).
	 *
	 * @param mixed $html
	 * @param mixed $block
	 * @return mixed
	 */
	public static function render_checkout_block( $html, $block ) {
		if ( ! is_array( $block ) || ! isset( $block['blockName'] ) || 'woocommerce/checkout' !== $block['blockName'] ) {
			return $html;
		}
		$creating = self::display_creating();
		$rule     = self::blocking( self::cart(), $creating );
		$boxes    = ( null === $rule ? '' : self::box_html( $rule, $creating ) ) . Registration::checkout_box_html();
		return '' === $boxes ? $html : $boxes . $html;
	}

	/**
	 * Classic checkout validation. It runs before WooCommerce creates the account a guest asked for
	 * (process_customer()); classic_create_order() runs after it, with the new account logged in.
	 *
	 * @param mixed $data   posted checkout data
	 * @param mixed $errors WP_Error
	 */
	public static function classic_validation( $data, $errors ): void {
		// Mirrors WC_Checkout::process_customer(); 'createaccount' is already 0 when checkout sign-up is off.
		$creating               = ! is_user_logged_in() && ( WC()->checkout()->is_registration_required() || ( is_array( $data ) && ! empty( $data['createaccount'] ) ) );
		self::$classic_creating = $creating;
		$rule                   = self::blocking( self::cart(), $creating );
		if ( null !== $rule && $errors instanceof \WP_Error ) {
			$errors->add( 'verifyblind_required', self::message( $rule, $creating ) );
		}
	}

	/**
	 * Once the account a guest created in this checkout exists, only the "require an account" rules are judged again,
	 * on the account: the other rules were judged moments before in the same request on the guest's results, which
	 * may be valid yet too old to follow into a new account (Results::CARRY_OVER_SECONDS).
	 *
	 * @return array[]
	 */
	private static function account_rules( array $rules ): array {
		$out = array();
		foreach ( $rules as $rule ) {
			if ( isset( $rule['guest_mode'] ) && 'require_account' === $rule['guest_mode'] ) {
				$out[] = $rule;
			}
		}
		return $out;
	}

	/**
	 * Store API (block checkout): a cart error makes POST /checkout fail with 409; the cart block shows it too.
	 *
	 * @param mixed $errors WP_Error
	 * @param mixed $cart   WC_Cart
	 */
	public static function store_api_cart_errors( $errors, $cart = null ): void {
		$creating = self::store_api_creates_account();
		$rule     = self::blocking( $cart instanceof \WC_Cart ? $cart : self::cart(), $creating );
		if ( null !== $rule && $errors instanceof \WP_Error ) {
			$errors->add( 'verifyblind_required', self::message( $rule, $creating ) );
		}
	}

	/**
	 * Remembers, for each REST request served, whether it is a Store API checkout that creates the customer's account.
	 *
	 * @param mixed $response
	 * @param mixed $handler
	 * @param mixed $request WP_REST_Request
	 * @return mixed
	 */
	public static function rest_enter( $response, $handler, $request ) {
		self::$creates_account[] = $request instanceof \WP_REST_Request
			&& 'POST' === $request->get_method()
			&& 1 === preg_match( '#^/wc/store(/v\d+)?/checkout/?$#', (string) $request->get_route() )
			&& self::request_creates_account( $request );
		return $response;
	}

	/**
	 * @param mixed $response
	 * @param mixed $handler
	 * @param mixed $request
	 * @return mixed
	 */
	public static function rest_leave( $response, $handler, $request ) {
		array_pop( self::$creates_account );
		return $response;
	}

	/** Mirrors the Store API checkout's should_create_customer_account(). */
	private static function request_creates_account( \WP_REST_Request $request ): bool {
		if ( is_user_logged_in() || ! function_exists( 'WC' ) ) {
			return false;
		}
		$checkout = WC()->checkout();
		if ( ! filter_var( $checkout->is_registration_enabled(), FILTER_VALIDATE_BOOLEAN ) ) {
			return false;
		}
		return filter_var( $checkout->is_registration_required(), FILTER_VALIDATE_BOOLEAN ) || filter_var( $request['create_account'], FILTER_VALIDATE_BOOLEAN );
	}

	/** Whether this request is a checkout (classic or block) in which a guest creates their account. */
	public static function creating_account(): bool {
		return self::$classic_creating || self::store_api_creates_account();
	}

	/** Whether the Store API request being served places an order that creates the customer's account. */
	private static function store_api_creates_account(): bool {
		return true === end( self::$creates_account );
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
		$rules = self::rules_for_order( $order );
		if ( ! $rules ) {
			return;
		}
		// After process_customer(): a guest who asked for an account in this checkout is logged in to it now.
		$judge = ( self::$classic_creating && is_user_logged_in() ) ? self::account_rules( $rules ) : $rules;
		$rule  = self::refusing_rule( $judge );
		if ( null !== $rule ) {
			throw new \Exception( self::message( $rule ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text from Messages::get(), not output here; WooCommerce escapes notices when it prints them
		}
		self::record( $order, $rules ); // the note follows in add_note() once the order has an id
	}

	/**
	 * Block checkout, last line when the order is placed (POST). Draft updates (PUT) pass. When the order creates
	 * the customer's account, WooCommerce creates it after this hook: store_api_order_processed() judges and records.
	 *
	 * @param mixed $order   WC_Order
	 * @param mixed $request WP_REST_Request
	 * @throws RouteException
	 */
	public static function store_api_order( $order, $request = null ): void {
		if ( ! $order instanceof \WC_Order || ( $request instanceof \WP_REST_Request && 'POST' !== $request->get_method() ) ) {
			return;
		}
		$rules = self::rules_for_order( $order );
		if ( ! $rules ) {
			return;
		}
		$creating = $request instanceof \WP_REST_Request ? self::request_creates_account( $request ) : self::store_api_creates_account();
		$rule     = self::refusing_rule( $rules, $creating );
		if ( null !== $rule ) {
			throw new RouteException( 'verifyblind_required', self::message( $rule, $creating ), 403 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text from Messages::get(), not output here; WooCommerce escapes notices when it prints them
		}
		if ( $creating ) {
			return;
		}
		self::record( $order, $rules );
		self::add_note( $order );
	}

	/**
	 * Block checkout that created the customer's account: the account now exists and is logged in, with the fresh
	 * guest results carried over (Plugin::on_register). Judged like any customer's order, then recorded.
	 *
	 * @param mixed $order WC_Order
	 * @throws RouteException
	 */
	public static function store_api_order_processed( $order ): void {
		if ( ! $order instanceof \WC_Order || ! self::store_api_creates_account() ) {
			return;
		}
		$rules = self::rules_for_order( $order );
		if ( ! $rules ) {
			return;
		}
		$rule = self::refusing_rule( self::account_rules( $rules ) );
		if ( null !== $rule ) {
			throw new RouteException( 'verifyblind_required', self::message( $rule ), 403 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text from Messages::get(), not output here; WooCommerce escapes notices when it prints them
		}
		self::record( $order, $rules );
		// Saved here: the payment step that follows loads its own copy of the order (a resumed order may already be noted).
		$order->save();
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
