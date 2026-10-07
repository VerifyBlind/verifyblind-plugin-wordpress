<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

/** Whether a rule's targets — post ids, or terms together with their descendant terms — cover a post. */
final class Targets {
	public static function is_empty( array $rule ): bool {
		return empty( $rule['targets']['post_ids'] ) && empty( $rule['targets']['term_ids'] );
	}

	public static function covers( \WP_Post $post, array $rule ): bool {
		$post_ids = isset( $rule['targets']['post_ids'] ) ? array_map( 'intval', (array) $rule['targets']['post_ids'] ) : array();
		if ( in_array( (int) $post->ID, $post_ids, true ) ) {
			return true;
		}
		$term_ids = isset( $rule['targets']['term_ids'] ) ? (array) $rule['targets']['term_ids'] : array();
		foreach ( $term_ids as $tid ) {
			$term = get_term( (int) $tid );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$ids      = array( (int) $tid );
			$children = get_term_children( (int) $tid, $term->taxonomy );
			if ( is_array( $children ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $children ) );
			}
			if ( has_term( $ids, $term->taxonomy, $post ) ) {
				return true;
			}
		}
		return false;
	}
}
