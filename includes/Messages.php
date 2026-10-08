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
			case 'duplicate_busy':
				return __( 'This identity is being used to create another account right now. Please try again in a moment.', 'verifyblind' );
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
			case 'registration_required':
				return __( 'Please verify with VerifyBlind before creating an account. The verification box is on the registration form.', 'verifyblind' );
			case 'registration_at_checkout':
				return __( 'Verify with VerifyBlind above to create your account.', 'verifyblind' );
			case 'comment_required':
				return __( 'Please verify with VerifyBlind before posting.', 'verifyblind' );
			case 'checkout_required':
				return __( 'Your cart contains products that need verification with VerifyBlind. Please verify on the checkout page, then place the order.', 'verifyblind' );
			case 'account_required':
				return __( 'Please log in or create an account to buy the products in your cart.', 'verifyblind' );
			case 'product_required':
				return __( 'This product needs verification with VerifyBlind before it can be added to the cart.', 'verifyblind' );
			case 'coupon_required':
				return __( 'This coupon is only for customers verified with VerifyBlind.', 'verifyblind' );
			case 'coupon_login':
				return __( 'Please log in to use this coupon.', 'verifyblind' );
			case 'coupon_used':
				return __( 'This coupon was already used by the same person.', 'verifyblind' );
			case 'coupon_no_person':
				return __( 'This coupon needs a one-person verification on your account. Verify again with your own ID card.', 'verifyblind' );
			case 'coupon_busy':
				return __( 'This coupon is being used in another order right now. Please try again in a moment.', 'verifyblind' );
			case 'upgrade_required':
				return __( 'Verification on this site needs a plugin update. Please try again later.', 'verifyblind' );
			default: // key_unavailable, api_unreachable, anything new
				return __( 'Verification is not available right now. Please try again shortly.', 'verifyblind' );
		}
	}
}
