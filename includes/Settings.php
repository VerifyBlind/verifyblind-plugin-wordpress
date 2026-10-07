<?php
namespace VerifyBlind;

final class Settings {
	/** Accept demo-card (is_test) results. Off on live sites. */
	public static function test_mode(): bool {
		return '1' === get_option( 'verifyblind_test_mode', '0' );
	}

	/** Ask the widget for invisible Cloudflare Turnstile bot protection. */
	public static function captcha(): bool {
		return '1' === get_option( 'verifyblind_captcha', '1' );
	}
}
