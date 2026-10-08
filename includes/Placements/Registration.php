<?php
namespace VerifyBlind\Placements;

defined( 'ABSPATH' ) || exit;

use VerifyBlind\Gate;
use VerifyBlind\Identities;
use VerifyBlind\Messages;
use VerifyBlind\Owner;
use VerifyBlind\PendingIdentities;
use VerifyBlind\Results;
use VerifyBlind\Rules;
use VerifyBlind\Settings;

/**
 * Sign-up: WordPress (wp-login.php?action=register) and WooCommerce (My account; account creation at checkout, where
 * the box is shown too while the shop lets guests create their account there).
 * A guest may run the one-person check here: the person code is held for the guest (PendingIdentities) and bound
 * to the account the moment it is created (Plugin::on_register). The box is only the interface - the refusal is
 * in registration_errors / woocommerce_register_post.
 *
 * Only the account that passed this gate takes the held check: a pass "arms" the e-mail address being signed up,
 * and Plugin::on_register binds only to a new account with that address. While a held one-person check is being
 * bound, a MySQL named lock on the person keeps two simultaneous sign-ups of the same person apart.
 */
final class Registration {
	const KEY = 'registration';

	/** @var string|null lowercase e-mail of the sign-up that passed the gate in this request */
	private static $armed_email = null;
	/** @var string|null name of the person lock this request holds */
	private static $lock = null;

	public static function label(): string {
		return __( 'Sign-up (WordPress and WooCommerce registration)', 'verifyblind' );
	}

	public static function hooks(): void {
		add_action( 'register_form', array( self::class, 'print_box' ) );
		add_action( 'woocommerce_register_form', array( self::class, 'print_box' ) );
		add_action( 'woocommerce_after_checkout_registration_form', array( self::class, 'print_checkout_box' ) );
		add_filter( 'registration_errors', array( self::class, 'registration_errors' ), 10, 3 );
		add_action( 'woocommerce_register_post', array( self::class, 'woocommerce_register_post' ), 10, 3 );
	}

	/** Inside the sign-up form: no reload, so what the visitor typed stays. */
	public static function print_box(): void {
		Prompt::render_for( Rules::enabled( self::KEY ), self::box_opts() );
	}

	private static function box_opts(): array {
		return array(
			'title'  => __( 'Verify with VerifyBlind to create an account.', 'verifyblind' ),
			'reload' => false,
		);
	}

	/**
	 * The sign-up box on the checkout page, for a guest who can create their account there (WooCommerce > Accounts:
	 * sign-up at checkout; without it a guest cannot create an account at either checkout); '' otherwise. No reload,
	 * so what the guest typed stays. Classic: the account part of the form; block checkout: WcCheckout prepends it.
	 */
	public static function checkout_box_html(): string {
		if ( is_user_logged_in() || ! function_exists( 'WC' ) || ! WC()->checkout()->is_registration_enabled() ) {
			return '';
		}
		$rules = Rules::enabled( self::KEY );
		$rule  = $rules ? Gate::blocking_rule( $rules ) : null;
		return null === $rule ? '' : Prompt::html( $rule, self::box_opts() );
	}

	/** Classic checkout: woocommerce_after_checkout_registration_form (inside the form, under the account fields). */
	public static function print_checkout_box(): void {
		$html = self::checkout_box_html();
		if ( '' !== $html ) {
			echo wp_kses_post( $html );
		}
	}

	/**
	 * Someone logged in who may create accounts (an administrator, a shop manager, an API integration such as POST
	 * /wc/v3/customers): their account creation is an administrative act, not a sign-up. Any other logged-in user
	 * creating an account is judged like a visitor.
	 */
	public static function creates_users(): bool {
		return is_user_logged_in() && ( current_user_can( 'create_users' ) || current_user_can( 'create_customers' ) );
	}

	/**
	 * Why this visitor may not create an account now, or null. A held one-person check must be fresh and is judged
	 * by the rule that created it; when it passes, this request holds the person lock until the account is bound.
	 */
	public static function refusal(): ?string {
		$code = self::refusal_code();
		return null === $code ? null : Messages::get( $code );
	}

	/** @return string|null Messages code */
	private static function refusal_code(): ?string {
		$rules = Rules::enabled( self::KEY );
		if ( ! $rules ) {
			return null;
		}
		if ( null !== Gate::blocking_rule( $rules ) ) {
			return 'registration_required';
		}
		$unique = false;
		foreach ( $rules as $rule ) {
			if ( ! empty( $rule['unique'] ) ) {
				$unique = true;
				break;
			}
		}
		if ( ! $unique ) {
			return null;
		}
		$guest   = Owner::guest_from_cookie();
		$pending = null === $guest ? null : PendingIdentities::find( $guest, Results::CARRY_OVER_SECONDS );
		if ( null === $pending ) {
			if ( null !== $guest ) {
				// The check went stale (or is gone): drop its guest pass so the box asks again.
				Results::delete_cond( $guest, 'uid' );
			}
			return 'registration_required';
		}
		if ( $pending['is_test'] ) {
			// A demo card binds no identity; it counts only while test mode is on.
			return Settings::test_mode() ? null : 'registration_required';
		}
		$person = (string) $pending['vb_user_id'];
		if ( ! self::lock( $person ) ) {
			return 'duplicate_busy';
		}
		$rule   = Rules::get( (string) $pending['rule_id'] );
		$policy = $rule ? $rule['duplicate_policy'] : 'reject';
		if ( in_array( $policy, array( 'reject', 'block' ), true ) && null !== Identities::find_by_vb_user_id( $person ) ) {
			self::release_lock();
			return 'duplicate';
		}
		return null;
	}

	/**
	 * @param mixed $errors WP_Error
	 * @return mixed
	 */
	public static function registration_errors( $errors, $login = '', $email = '' ) {
		self::check( $errors, $email );
		return $errors;
	}

	/** @param mixed $errors WP_Error */
	public static function woocommerce_register_post( $username, $email, $errors ): void {
		self::check( $errors, $email );
	}

	/** True when $email is the address that passed the sign-up gate in this request. */
	public static function is_armed_for( string $email ): bool {
		return null !== self::$armed_email && self::normalize( $email ) === self::$armed_email;
	}

	public static function disarm(): void {
		self::$armed_email = null;
	}

	/** Releases the person lock this request holds, if any (also runs on shutdown). */
	public static function release_lock(): void {
		global $wpdb;
		if ( null === self::$lock ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::$lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named lock, not table data
		self::$lock = null;
	}

	/** Tests: forget the armed e-mail and release the lock. */
	public static function reset(): void {
		self::disarm();
		self::release_lock();
	}

	/**
	 * @param mixed $errors WP_Error
	 * @param mixed $email
	 */
	private static function check( $errors, $email ): void {
		if ( self::creates_users() ) {
			// An administrative act, not a sign-up: no check, nothing armed (Plugin::on_register ignores it too).
			self::disarm();
			return;
		}
		$code = self::refusal_code();
		if ( null === $code ) {
			$normalized        = self::normalize( is_string( $email ) ? $email : '' );
			self::$armed_email = '' === $normalized ? null : $normalized;
			return;
		}
		self::disarm();
		if ( 'registration_required' === $code && WcCheckout::creating_account() ) {
			// The account is being created with a checkout order: the box is on the checkout page.
			$code = 'registration_at_checkout';
		}
		if ( $errors instanceof \WP_Error ) {
			$errors->add( 'verifyblind_required', Messages::get( $code ) );
		}
	}

	private static function normalize( string $email ): string {
		return strtolower( trim( $email ) );
	}

	private static function lock( string $person ): bool {
		global $wpdb;
		$name = 'vb_p_' . md5( $person );
		if ( self::$lock === $name ) {
			return true;
		}
		self::release_lock();
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 5 ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named lock, not table data
			return false;
		}
		self::$lock = $name;
		if ( false === has_action( 'shutdown', array( self::class, 'release_lock' ) ) ) {
			add_action( 'shutdown', array( self::class, 'release_lock' ) );
		}
		return true;
	}
}
