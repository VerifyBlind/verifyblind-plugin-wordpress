<?php
namespace VerifyBlind\Placements;

defined( 'ABSPATH' ) || exit;

use VerifyBlind\Gate;
use VerifyBlind\Widget;

/** What a visitor who does not meet a rule sees at a placement. Showing it is only the interface: every placement also refuses on the server. */
final class Prompt {
	/**
	 * The verification box, or a log-in box when the check needs an account. Marks the page as not cacheable.
	 *
	 * @param array $opts Widget::box() options ('title', 'reload') plus 'login' (bool: guests must log in) and 'redirect' (string).
	 */
	public static function html( array $rule, array $opts = array() ): string {
		Gate::no_cache();
		if ( self::needs_login( $rule, ! empty( $opts['login'] ) ) ) {
			return Widget::login_box( $rule, $opts );
		}
		return Widget::box( $rule, $opts );
	}

	/** One-person checks bind a person to an account, so guests log in first — except on the sign-up form itself. */
	public static function needs_login( array $rule, bool $forced = false ): bool {
		if ( is_user_logged_in() ) {
			return false;
		}
		return $forced || Gate::needs_account( $rule );
	}

	/** For action hooks: prints the box for the first of $rules the visitor does not meet (nothing when all are met). */
	public static function render_for( array $rules, array $opts = array() ): void {
		$rule = $rules ? Gate::blocking_rule( $rules ) : null;
		if ( null !== $rule ) {
			echo wp_kses_post( self::html( $rule, $opts ) );
		}
	}
}
