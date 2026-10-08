<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

final class Settings {
	/** Accept demo-card (is_test) results. Off on live sites. */
	public static function test_mode(): bool {
		return '1' === get_option( 'verifyblind_test_mode', '0' );
	}
}
