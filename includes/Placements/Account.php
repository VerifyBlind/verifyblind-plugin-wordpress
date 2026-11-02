<?php
namespace VerifyBlind\Placements;

defined( 'ABSPATH' ) || exit;

use VerifyBlind\Badge;
use VerifyBlind\Messages;
use VerifyBlind\Rules;

/**
 * Verification that is not a gate for everyone. A member verifies from their account for the badge, and a
 * moderator can ask one suspicious account to verify: until it does, that account cannot post. Everyone else
 * is never asked. An account that never verified has no code on the site, so a banned person coming back
 * under a new name is recognised only from the moment that person verifies once.
 */
final class Account {
	const KEY       = 'account';
	const SHORTCODE = 'verifyblind_verify';
	const META      = 'verifyblind_verify_requested';

	public static function label(): string {
		return __( 'Account (voluntary badge, or asked by a moderator)', 'verifyblind' );
	}

	public static function hooks(): void {
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
		add_action( 'woocommerce_account_dashboard', array( self::class, 'print_box' ) );
		add_action( 'comment_form_before', array( self::class, 'print_requested_box' ), 5 );
		add_filter( 'pre_comment_approved', array( self::class, 'refuse_comment' ), 99, 2 );
		add_filter( 'bbp_current_user_can_publish_topics', array( self::class, 'can_post' ) );
		add_filter( 'bbp_current_user_can_publish_replies', array( self::class, 'can_post' ) );
	}

	/** The first switched-on account rule, or null. */
	public static function rule(): ?array {
		$rules = Rules::enabled( self::KEY );
		return $rules ? $rules[0] : null;
	}

	/** Asking one account needs a rule to verify against; the site owner may never have created one. */
	public static function ensure_rule(): array {
		$rule = self::rule();
		if ( null !== $rule ) {
			return $rule;
		}
		return Rules::save(
			array(
				'name'      => __( 'Verified member', 'verifyblind' ),
				'placement' => self::KEY,
				'unique'    => true,
				'enabled'   => true,
			)
		);
	}

	public static function request( int $user_id ): void {
		self::ensure_rule();
		update_user_meta( $user_id, self::META, '1' );
	}

	public static function cancel_request( int $user_id ): void {
		delete_user_meta( $user_id, self::META );
	}

	public static function is_requested( int $user_id ): bool {
		return '1' === get_user_meta( $user_id, self::META, true );
	}

	/** Asked to verify and has not. Moderators are never held back. */
	public static function blocked( int $user_id ): bool {
		return $user_id > 0
			&& self::is_requested( $user_id )
			&& ! Badge::is_verified( $user_id )
			&& ! user_can( $user_id, 'moderate_comments' );
	}

	/** @param mixed $atts */
	public static function shortcode( $atts = array() ): string {
		$rule = self::rule();
		if ( null === $rule ) {
			return '';
		}
		if ( is_user_logged_in() && Badge::is_verified( get_current_user_id() ) ) {
			return '<p class="verifyblind-verified">' . esc_html__( 'Your account is verified.', 'verifyblind' ) . '</p>';
		}
		return Prompt::html(
			$rule,
			array(
				'title'    => __( 'Verify your account', 'verifyblind' ),
				'redirect' => (string) get_permalink(),
			)
		);
	}

	public static function print_box(): void {
		echo wp_kses_post( self::shortcode() );
	}

	/** The verification box above the comment form of an account asked to verify. */
	public static function print_requested_box(): void {
		$rule = self::rule();
		if ( null === $rule || ! self::blocked( get_current_user_id() ) ) {
			return;
		}
		echo wp_kses_post(
			Prompt::html(
				$rule,
				array(
					'title'    => __( 'A moderator asked you to verify this account', 'verifyblind' ),
					'reload'   => false,
					'redirect' => (string) get_permalink(),
				)
			)
		);
	}

	/**
	 * @param mixed $approved
	 * @param mixed $data comment data
	 * @return mixed
	 */
	public static function refuse_comment( $approved, $data ) {
		if ( is_wp_error( $approved ) || ! is_array( $data ) ) {
			return $approved;
		}
		$user_id = isset( $data['user_id'] ) ? (int) $data['user_id'] : 0;
		if ( ! self::blocked( $user_id ) ) {
			return $approved;
		}
		return new \WP_Error( 'verifyblind_required', Messages::get( 'comment_required' ), 403 );
	}

	/** @param mixed $can */
	public static function can_post( $can ) {
		return self::blocked( get_current_user_id() ) ? false : $can;
	}
}
