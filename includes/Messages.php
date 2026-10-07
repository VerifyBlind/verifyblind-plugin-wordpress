<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

final class Messages {
	public static function get( string $code ): string {
		switch ( $code ) {
			case 'ok':
				return __( 'Verification complete.', 'verifyblind' );
			case 'not_eligible':
				return __( 'Verification complete, but the requirement for this content was not met.', 'verifyblind' );
			case 'bad_token':
			case 'bad_request':
			case 'incomplete':
				return __( 'The verification result could not be read. Please try again.', 'verifyblind' );
			case 'bad_signature':
				return __( 'The verification could not be confirmed.', 'verifyblind' );
			case 'nonce_invalid':
				return __( 'This verification session has expired or was already used. Please try again.', 'verifyblind' );
			case 'test_card':
				return __( 'Verifications made with a demo card are not accepted on this site.', 'verifyblind' );
			case 'duplicate':
				return __( 'This identity is already verified on another account. Each person can verify only one account.', 'verifyblind' );
			case 'different_identity':
				return __( 'This account was verified with a different identity before.', 'verifyblind' );
			case 'rule_not_found':
				return __( 'This verification is no longer available.', 'verifyblind' );
			case 'not_configured':
				return __( 'Verification is not set up on this site yet.', 'verifyblind' );
			case 'login_required':
				return __( 'Please log in to verify your account.', 'verifyblind' );
			case 'condition_mismatch':
				return __( 'The verification answered a different question than this site asked. Please try again.', 'verifyblind' );
			case 'captcha_required':
				return __( 'Bot check could not be completed. Please reload the page and try again.', 'verifyblind' );
			case 'rate_limited':
				return __( 'Too many verification attempts right now. Please try again in a minute.', 'verifyblind' );
			default: // key_unavailable, api_unreachable, anything new
				return __( 'Verification is not available right now. Please try again shortly.', 'verifyblind' );
		}
	}
}
