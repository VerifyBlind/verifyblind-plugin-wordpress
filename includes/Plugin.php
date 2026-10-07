<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	/** @var ApiClient|null */
	private static $api;

	public static function boot(): void {
		load_plugin_textdomain( 'verifyblind', false, dirname( plugin_basename( VERIFYBLIND_FILE ) ) . '/languages' );
		Schema::maybe_upgrade();
		add_action( 'rest_api_init', array( Rest::class, 'register' ) );
		Gate::hooks();
		Widget::hooks();
		Cron::hooks();
		Placements\Registry::boot();
		Badge::hooks();
		Privacy::hooks();
		SeoRedaction::hooks();
		add_action( 'deleted_user', array( self::class, 'forget_user' ) );
		add_action( 'wp_login', array( self::class, 'on_login' ), 10, 2 );
		add_action( 'user_register', array( self::class, 'on_register' ) );
		if ( is_admin() ) {
			Admin\Menu::hooks();
		}
	}

	public static function forget_user( $user_id ): void {
		Results::delete_owner( Owner::for_user( (int) $user_id ) );
		Identities::delete_for_user( (int) $user_id );
	}

	/** @param mixed $user WP_User */
	public static function on_login( $login, $user = null ): void {
		if ( $user instanceof \WP_User ) {
			$guest = Owner::guest_from_cookie();
			if ( null !== $guest ) {
				// Logged in instead of signing up: a person code held for a sign-up has no use any more.
				PendingIdentities::delete( $guest );
			}
			self::adopt_guest( (int) $user->ID );
		}
	}

	public static function on_register( $user_id ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return;
		}
		$armed   = Placements\Registration::is_armed_for( (string) $user->user_email );
		$current = get_current_user_id();
		if ( $current > 0 && (int) $user_id !== $current && ( Placements\Registration::creates_users() || ! $armed ) ) {
			// Someone logged in created another account. An administrator (or shop manager, API integration) who adds
			// a user from a browser that once verified as a guest must not hand those results to someone else; any
			// other member was judged like a visitor at the sign-up gate, and only the account that passed it takes
			// the held check (and the browser's guest results).
			return;
		}
		$guest = Owner::guest_from_cookie();
		if ( $armed ) {
			// The account that passed the sign-up gate: a one-person check held for it now belongs to it.
			Placements\Registration::disarm();
			$outcome = null === $guest ? '' : PendingIdentities::claim( $guest, (int) $user_id );
			Placements\Registration::release_lock();
			if ( '' !== $outcome && 'ok' !== $outcome ) {
				return; // someone else took the person meanwhile: the account stays unverified (marked by claim)
			}
		} elseif ( null !== $guest && null !== PendingIdentities::find( $guest, Results::CARRY_OVER_SECONDS ) ) {
			// Some other account is being created while this visitor is mid sign-up: leave their check alone.
			return;
		}
		self::adopt_guest( (int) $user_id );
	}

	/**
	 * Results a visitor recently earned as a guest follow them into the account they log
	 * in to or create, so they do not verify (and the site does not pay) twice.
	 */
	public static function adopt_guest( int $user_id ): void {
		$guest = Owner::guest_from_cookie();
		if ( $user_id <= 0 || null === $guest || ! get_userdata( $user_id ) ) {
			return;
		}
		$to = Owner::for_user( $user_id );
		Results::reassign_owner( $guest, $to );
		// Open sessions are not moved: guest pages carry no REST nonce, so the verify call stays a guest call.
		Owner::forget_guest();
		Roles::sync_user( $user_id );
	}

	public static function activate(): void {
		Schema::install();
		Cron::schedule();
	}

	public static function deactivate(): void {
		Cron::unschedule();
	}

	public static function api(): ApiClient {
		if ( null === self::$api ) {
			self::$api = new ApiClient();
		}
		return self::$api;
	}

	/** Tests swap the key source through this filter. */
	public static function keys(): KeySource {
		$keys = apply_filters( 'verifyblind_key_source', self::api() );
		return $keys instanceof KeySource ? $keys : self::api();
	}

	public static function service(): VerificationService {
		return new VerificationService( self::keys() );
	}
}
