<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

/**
 * "Verified with VerifyBlind" next to the names of members who passed the one-person check (a valid 'uid'
 * result). The badge carries no user data: a fixed image, a fixed link and a translatable label.
 */
final class Badge {
	const OPTION = 'verifyblind_badge_places';
	const PLACES = array( 'comments', 'reviews', 'author' );

	/** @var array<int,bool> per-request cache: user id => verified */
	private static $verified = array();

	public static function hooks(): void {
		add_filter( 'get_comment_author_link', array( self::class, 'comment_author_link' ), 20, 3 );
		add_filter( 'comment_author', array( self::class, 'review_author' ), 20, 2 );
		add_filter( 'the_author_posts_link', array( self::class, 'author_posts_link' ), 20 );
		add_filter( 'render_block', array( self::class, 'render_block' ), 20, 3 );
	}

	/** @return array<string,string> */
	public static function place_labels(): array {
		return array(
			'comments' => __( 'Comments', 'verifyblind' ),
			'reviews'  => __( 'Product reviews', 'verifyblind' ),
			'author'   => __( 'Author box', 'verifyblind' ),
		);
	}

	/** @param mixed $value */
	public static function sanitize_places( $value ): array {
		$value = is_array( $value ) ? array_map( 'strval', $value ) : array();
		return array_values( array_intersect( self::PLACES, $value ) );
	}

	public static function places(): array {
		$value = get_option( self::OPTION, null );
		return is_array( $value ) ? self::sanitize_places( $value ) : self::PLACES;
	}

	public static function shows_in( string $place ): bool {
		return in_array( $place, self::places(), true );
	}

	public static function is_verified( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( ! isset( self::$verified[ $user_id ] ) ) {
			self::$verified[ $user_id ] = in_array( 'uid', Results::passed_conditions( Owner::for_user( $user_id ), 0, Settings::test_mode() ), true );
		}
		return self::$verified[ $user_id ];
	}

	/** Test helper. */
	public static function reset_cache(): void {
		self::$verified = array();
	}

	public static function html(): string {
		$lang  = 0 === strpos( determine_locale(), 'tr' ) ? 'tr' : 'en';
		$label = __( 'Verified with VerifyBlind', 'verifyblind' );
		return sprintf(
			' <a class="verifyblind-badge" href="%1$s" target="_blank" rel="noopener"><img src="%2$s" alt="%3$s" title="%3$s" width="16" height="16" style="display:inline-block;vertical-align:middle;width:16px;height:16px;border:0;margin:0 0 0 4px" /></a>',
			esc_url( 'https://verifyblind.com/' . $lang . '/how-it-works' ),
			esc_url( 'https://verifyblind.com/badges/verified-with-verifyblind-icon-' . $lang . '.svg' ),
			esc_attr( $label )
		);
	}

	private static function front(): bool {
		return ! is_admin() && ! is_feed();
	}

	/** @param mixed $comment WP_Comment */
	private static function comment_place( $comment ): string {
		return ( $comment && 'product' === get_post_type( (int) $comment->comment_post_ID ) ) ? 'reviews' : 'comments';
	}

	/**
	 * @param mixed $link
	 * @return mixed
	 */
	public static function comment_author_link( $link, $author = '', $comment_id = 0 ) {
		$comment = get_comment( $comment_id );
		if ( ! $comment || ! self::front() || ! self::shows_in( self::comment_place( $comment ) ) || ! self::is_verified( (int) $comment->user_id ) ) {
			return $link;
		}
		return $link . self::html();
	}

	/**
	 * WooCommerce prints review authors with comment_author(); core escapes that text at priority 10, the badge follows.
	 *
	 * @param mixed $author
	 * @return mixed
	 */
	public static function review_author( $author, $comment_id = 0 ) {
		$comment = get_comment( $comment_id );
		if ( ! $comment || 'reviews' !== self::comment_place( $comment ) || ! self::front() || ! self::shows_in( 'reviews' ) || ! self::is_verified( (int) $comment->user_id ) ) {
			return $author;
		}
		return $author . self::html();
	}

	/**
	 * @param mixed $link
	 * @return mixed
	 */
	public static function author_posts_link( $link ) {
		if ( ! self::front() || ! self::shows_in( 'author' ) || ! self::is_verified( (int) get_the_author_meta( 'ID' ) ) ) {
			return $link;
		}
		return $link . self::html();
	}

	/**
	 * Block themes: comment author name and post author blocks.
	 *
	 * @param mixed $html
	 * @param mixed $block parsed block
	 * @param mixed $instance WP_Block (its context names the comment or post)
	 * @return mixed
	 */
	public static function render_block( $html, $block, $instance = null ) {
		if ( ! is_string( $html ) || ! is_array( $block ) || ! isset( $block['blockName'] ) || ! self::front() || ! is_object( $instance ) || ! isset( $instance->context ) || ! is_array( $instance->context ) ) {
			return $html;
		}
		$ctx = $instance->context;
		if ( 'core/comment-author-name' === $block['blockName'] && isset( $ctx['commentId'] ) ) {
			$comment = get_comment( (int) $ctx['commentId'] );
			if ( $comment && self::shows_in( self::comment_place( $comment ) ) && self::is_verified( (int) $comment->user_id ) ) {
				return self::inside( $html );
			}
		}
		if ( in_array( $block['blockName'], array( 'core/post-author', 'core/post-author-name' ), true ) && isset( $ctx['postId'] ) && self::shows_in( 'author' ) ) {
			$post = get_post( (int) $ctx['postId'] );
			if ( $post && self::is_verified( (int) $post->post_author ) ) {
				return self::inside( $html );
			}
		}
		return $html;
	}

	/** Before the wrapper's closing tag, so the badge sits on the name's line. */
	private static function inside( string $html ): string {
		$pos = strrpos( $html, '</' );
		return false === $pos ? $html . self::html() : substr( $html, 0, $pos ) . self::html() . substr( $html, $pos );
	}
}
