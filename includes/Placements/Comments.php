<?php
namespace VerifyBlind\Placements;

defined( 'ABSPATH' ) || exit;

/** Comments on posts and pages (not WooCommerce products - see WcReview). */
final class Comments {
	const KEY = 'comments';

	public static function label(): string {
		return __( 'Comments (every post, or the chosen pages, posts and categories)', 'verifyblind' );
	}

	public static function hooks(): void {
		add_action( 'comment_form_before', array( self::class, 'print_box' ) );
		add_filter( 'pre_comment_approved', array( self::class, 'check' ), 99, 2 );
	}

	public static function print_box(): void {
		CommentGate::render( self::KEY, false, __( 'Verify with VerifyBlind to comment.', 'verifyblind' ) );
	}

	/**
	 * @param mixed $approved
	 * @param mixed $data
	 * @return mixed
	 */
	public static function check( $approved, $data = array() ) {
		return CommentGate::refuse( self::KEY, false, $approved, $data );
	}
}
