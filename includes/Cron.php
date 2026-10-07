<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

final class Cron {
	const HOOK = 'verifyblind_daily';

	public static function hooks(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
		self::schedule();
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function run(): void {
		Nonces::purge_expired();
		Results::purge_guests( DAY_IN_SECONDS );
		PendingIdentities::purge( DAY_IN_SECONDS );
		Roles::sync_all(); // drops rule roles whose validity window has passed
	}
}
