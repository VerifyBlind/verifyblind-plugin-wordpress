<?php
namespace VerifyBlind;

final class Plugin {
	/** @var ApiClient|null */
	private static $api;

	public static function boot(): void {
		load_plugin_textdomain( 'verifyblind', false, dirname( plugin_basename( VERIFYBLIND_FILE ) ) . '/languages' );
		Schema::maybe_upgrade();
		add_action( 'rest_api_init', array( Rest::class, 'register' ) );
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
