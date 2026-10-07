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
 * Sign-up: WordPress (wp-login.php?action=register) and WooCommerce (My account; account creation at checkout).
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
		add_filter( 'registration_errors', array( self::class, 'registration_errors' ), 10, 3 );
		add_action( 'woocommerce_register_post', array( self::class, 'woocommerce_register_post' ), 10, 3 );
	}

	/** Inside the sign-up form: no reload, so what the visitor typed stays. */
	public static function print_box(): void {
		Prompt::render_for(
			Rules::enabled( self::KEY ),
			array(
				'title'  => __( 'Verify with VerifyBlind to create an account.', 'verifyblind' ),
				'reload' => false,
			)
		);
	}

	/**
	 * Why this visitor may not create an account now, or null. A held one-person check must be fresh and is judged
	 * by the rule that created it; when it passes, this request holds the person lock until the account is bound.
	 */
	public static function refusal(): ?string {
		$rules = Rules::enabled( self::KEY );
		if ( ! $rules ) {
			return null;
		}
		if ( null !== Gate::blocking_rule( $rules ) ) {
			return Messages::get( 'registration_required' );
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
			return Messages::get( 'registration_required' );
		}
		if ( $pending['is_test'] ) {
			// A demo card binds no identity; it counts only while test mode is on.
			return Settings::test_mode() ? null : Messages::get( 'registration_required' );
		}
		$person = (string) $pending['vb_user_id'];
		if ( ! self::lock( $person ) ) {
			return Messages::get( 'duplicate_busy' );
		}
		$rule   = Rules::get( (string) $pending['rule_id'] );
		$policy = $rule ? $rule['duplicate_policy'] : 'reject';
		if ( in_array( $policy, array( 'reject', 'block' ), true ) && null !== Identities::find_by_vb_user_id( $person ) ) {
			self::release_lock();
			return Messages::get( 'duplicate' );
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
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::$lock ) );
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
		$why = self::refusal();
		if ( null === $why ) {
			$normalized        = self::normalize( is_string( $email ) ? $email : '' );
			self::$armed_email = '' === $normalized ? null : $normalized;
			return;
		}
		self::disarm();
		if ( $errors instanceof \WP_Error ) {
			$errors->add( 'verifyblind_required', $why );
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
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 5 ) ) ) {
			return false;
		}
		self::$lock = $name;
		if ( false === has_action( 'shutdown', array( self::class, 'release_lock' ) ) ) {
			add_action( 'shutdown', array( self::class, 'release_lock' ) );
		}
		return true;
	}
}
