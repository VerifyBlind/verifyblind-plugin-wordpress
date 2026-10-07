<?php
namespace VerifyBlind\Placements;

defined( 'ABSPATH' ) || exit;

use VerifyBlind\Gate;
use VerifyBlind\Identities;
use VerifyBlind\Messages;
use VerifyBlind\Owner;
use VerifyBlind\PendingIdentities;
use VerifyBlind\Rules;

/**
 * Sign-up: WordPress (wp-login.php?action=register) and WooCommerce (My account; account creation at checkout).
 * A guest may run the one-person check here: the person code is held for the guest (PendingIdentities) and bound
 * to the account the moment it is created (Plugin::on_register). The box is only the interface - the refusal is
 * in registration_errors / woocommerce_register_post.
 */
final class Registration {
	const KEY = 'registration';

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

	/** Why this visitor may not create an account now, or null. Applies the same-person policy to a held check. */
	public static function refusal(): ?string {
		$rules = Rules::enabled( self::KEY );
		if ( ! $rules ) {
			return null;
		}
		if ( null !== Gate::blocking_rule( $rules ) ) {
			return Messages::get( 'registration_required' );
		}
		$guest = Owner::guest_from_cookie();
		foreach ( $rules as $rule ) {
			if ( empty( $rule['unique'] ) ) {
				continue;
			}
			$pending = null === $guest ? null : PendingIdentities::find( $guest );
			if ( null === $pending ) {
				return Messages::get( 'registration_required' );
			}
			if ( ! $pending['is_test'] && in_array( $rule['duplicate_policy'], array( 'reject', 'block' ), true ) && null !== Identities::find_by_vb_user_id( (string) $pending['vb_user_id'] ) ) {
				return Messages::get( 'duplicate' );
			}
		}
		return null;
	}

	/**
	 * @param mixed $errors WP_Error
	 * @return mixed
	 */
	public static function registration_errors( $errors, $login = '', $email = '' ) {
		$why = self::refusal();
		if ( null !== $why && $errors instanceof \WP_Error ) {
			$errors->add( 'verifyblind_required', $why );
		}
		return $errors;
	}

	/** @param mixed $errors WP_Error */
	public static function woocommerce_register_post( $username, $email, $errors ): void {
		$why = self::refusal();
		if ( null !== $why && $errors instanceof \WP_Error ) {
			$errors->add( 'verifyblind_required', $why );
		}
	}
}
