<?php
namespace VerifyBlind\Placements;

defined( 'ABSPATH' ) || exit;

/** WooCommerce product reviews (comments on products). */
final class WcReview {
	const KEY = 'wc_review';

	public static function label(): string {
		return __( 'WooCommerce product reviews (every product, or the chosen products and categories)', 'verifyblind' );
	}

	public static function hooks(): void {
		add_action( 'comment_form_before', array( self::class, 'print_box' ) );
		add_filter( 'pre_comment_approved', array( self::class, 'check' ), 99, 2 );
	}

	public static function print_box(): void {
		CommentGate::render( self::KEY, true, __( 'Verify with VerifyBlind to write a review.', 'verifyblind' ) );
	}

	/**
	 * @param mixed $approved
	 * @param mixed $data
	 * @return mixed
	 */
	public static function check( $approved, $data = array() ) {
		return CommentGate::refuse( self::KEY, true, $approved, $data );
	}
}
