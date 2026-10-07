<?php
namespace VerifyBlind\Placements;

defined( 'ABSPATH' ) || exit;

use VerifyBlind\Gate;
use VerifyBlind\Messages;
use VerifyBlind\Rules;
use VerifyBlind\Targets;

/** Shared by comments (ordinary posts) and product reviews (WooCommerce products). */
final class CommentGate {
	/** @return array[] enabled rules of $placement covering $post; a rule without targets covers every post */
	public static function rules_for( string $placement, \WP_Post $post ): array {
		$out = array();
		foreach ( Rules::enabled( $placement ) as $rule ) {
			if ( Targets::is_empty( $rule ) || Targets::covers( $post, $rule ) ) {
				$out[] = $rule;
			}
		}
		return $out;
	}

	/** The rule blocking the current visitor from commenting on $post, or null. Moderators are never asked. */
	public static function blocking( string $placement, \WP_Post $post ): ?array {
		if ( current_user_can( 'moderate_comments' ) ) {
			return null;
		}
		$rules = self::rules_for( $placement, $post );
		return $rules ? Gate::blocking_rule( $rules ) : null;
	}

	private static function matches( \WP_Post $post, bool $products ): bool {
		return ( 'product' === $post->post_type ) === $products;
	}

	/**
	 * Whether $post has an open comment form that a comments (posts) or wc_review (products) rule covers. Such a
	 * page is per-visitor (box or no box), so it is never cached - whether or not this visitor sees the box.
	 */
	public static function covers( \WP_Post $post ): bool {
		if ( ! comments_open( $post ) ) {
			return false;
		}
		// Literals for Comments::KEY / WcReview::KEY: wc_review rules only exist while WooCommerce is active.
		return array() !== self::rules_for( 'product' === $post->post_type ? 'wc_review' : 'comments', $post );
	}

	/** comment_form_before: the box above (outside) the form, without reload so a typed comment stays. */
	public static function render( string $placement, bool $products, string $title ): void {
		$post = get_post();
		if ( ! $post || ! self::matches( $post, $products ) ) {
			return;
		}
		if ( self::rules_for( $placement, $post ) ) {
			Gate::no_cache(); // also when this visitor meets the rule: the next one may not
		}
		$rule = self::blocking( $placement, $post );
		if ( null !== $rule ) {
			echo wp_kses_post(
				Prompt::html(
					$rule,
					array(
						'title'    => $title,
						'reload'   => false,
						'redirect' => (string) get_permalink( $post ),
					)
				)
			);
		}
	}

	/**
	 * pre_comment_approved: a WP_Error refuses the comment on every path (wp-comments-post.php, REST, reviews).
	 *
	 * @param mixed $approved
	 * @param mixed $data comment data
	 * @return mixed
	 */
	public static function refuse( string $placement, bool $products, $approved, $data ) {
		if ( is_wp_error( $approved ) || ! is_array( $data ) ) {
			return $approved;
		}
		$post = get_post( isset( $data['comment_post_ID'] ) ? (int) $data['comment_post_ID'] : 0 );
		if ( ! $post || ! self::matches( $post, $products ) || null === self::blocking( $placement, $post ) ) {
			return $approved;
		}
		return new \WP_Error( 'verifyblind_required', Messages::get( 'comment_required' ), 403 );
	}
}
