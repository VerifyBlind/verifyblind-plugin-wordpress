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
		add_action( 'deleted_user', array( self::class, 'forget_user' ) );
		if ( is_admin() ) {
			Admin\Menu::hooks();
		}
	}

	public static function forget_user( $user_id ): void {
		Results::delete_owner( Owner::for_user( (int) $user_id ) );
		Identities::delete_for_user( (int) $user_id );
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
