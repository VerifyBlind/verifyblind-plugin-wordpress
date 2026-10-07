<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

use VerifyBlind\Placements\Registry;
use VerifyBlind\Placements\WcProduct;

/**
 * Locked posts and products also keep their text out of SEO plugins' meta descriptions (Yoast SEO, Rank Math):
 * search engines and link previews never verify.
 *
 * Coverage note: every redaction in this plugin works through WordPress and WooCommerce filters
 * (the_content, excerpts, feeds, REST, Store API, product getters, these SEO filters). A theme or plugin that
 * reads $post->post_content straight from the database and prints it bypasses all of them. For such themes,
 * put the locked part inside the "VerifyBlind lock" block or the [verifyblind_gate] shortcode: the gate then
 * decides on the server wherever the content is rendered. WordPress's generated excerpts drop enclosing
 * shortcodes (with their content) and non-text blocks, so inline locks do not leak there; SEO plugins that build
 * descriptions from raw content may still show text from an inline lock — give such pages a custom description.
 */
final class SeoRedaction {
	const FILTERS = array( 'wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description', 'rank_math/frontend/description' );

	public static function hooks(): void {
		foreach ( self::FILTERS as $filter ) {
			add_filter( $filter, array( self::class, 'filter' ), 999 );
		}
	}

	/**
	 * @param mixed $description
	 * @return mixed
	 */
	public static function filter( $description ) {
		if ( ! is_singular() ) {
			return $description;
		}
		$post = get_queried_object();
		return ( $post instanceof \WP_Post && self::is_locked( $post ) ) ? Gate::locked_text() : $description;
	}

	public static function is_locked( \WP_Post $post ): bool {
		$rules = Gate::rules_for_post( $post );
		if ( $rules && ! Gate::bypass( $post ) && null !== Gate::blocking_rule( $rules ) ) {
			return true;
		}
		return 'product' === $post->post_type && Registry::woocommerce_active() && null !== WcProduct::blocking( (int) $post->ID );
	}
}
