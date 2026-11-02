<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

final class Settings {
	const TEST_MODE_SECONDS = 86400;

	/**
	 * Accept demo-card (is_test) results. Turns itself off a day after it was switched on: a forgotten test
	 * mode would let anyone pass with the demo card on a live site.
	 */
	public static function test_mode(): bool {
		if ( '1' !== get_option( 'verifyblind_test_mode', '0' ) ) {
			return false;
		}
		$until = (int) get_option( 'verifyblind_test_mode_until', 0 );
		if ( 0 === $until ) {
			self::start_test_mode_clock();
			return true;
		}
		if ( time() < $until ) {
			return true;
		}
		update_option( 'verifyblind_test_mode', '0' );
		delete_option( 'verifyblind_test_mode_until' );
		return false;
	}

	public static function start_test_mode_clock(): void {
		update_option( 'verifyblind_test_mode_until', time() + self::TEST_MODE_SECONDS, false );
	}
}
