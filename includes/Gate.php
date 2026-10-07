<?php
namespace VerifyBlind;

/**
 * Content lock. Locked content is removed on the server — it never reaches the browser — in the post
 * body, excerpts, the REST API and feeds. Pages with a gate (locked or unlocked) are excluded from page
 * caches: both versions are per-visitor.
 */
final class Gate {
	/** @var bool whether no_cache() ran during this request (also lets tests observe the decision) */
	private static $no_cache_requested = false;

	public static function hooks(): void {
		add_shortcode( 'verifyblind_gate', array( self::class, 'shortcode' ) );
		add_action( 'init', array( self::class, 'register_block' ) );
		// After all post types (including custom ones registered on init) exist.
		add_action( 'init', array( self::class, 'register_rest_filters' ), 99 );
		add_action( 'template_redirect', array( self::class, 'maybe_no_cache' ) );
		add_filter( 'the_content', array( self::class, 'filter_content' ), 999 );
		add_filter( 'get_the_excerpt', array( self::class, 'filter_excerpt' ), 999, 2 );
		add_filter( 'the_content_feed', array( self::class, 'filter_feed' ), 999 );
		add_filter( 'the_excerpt_rss', array( self::class, 'filter_feed' ), 999 );
	}

	public static function register_rest_filters(): void {
		$skip = array( 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' );
		foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $type ) {
			if ( in_array( $type, $skip, true ) ) {
				continue;
			}
			add_filter( "rest_prepare_{$type}", array( self::class, 'filter_rest' ), 999, 2 );
		}
	}

	/** @return array[] enabled content rules that target this post directly or through a term (or its descendants) */
	public static function rules_for_post( \WP_Post $post ): array {
		$out = array();
		foreach ( Rules::all() as $rule ) {
			if ( empty( $rule['enabled'] ) || 'content' !== $rule['placement'] ) {
				continue;
			}
			if ( in_array( (int) $post->ID, $rule['targets']['post_ids'], true ) ) {
				$out[] = $rule;
				continue;
			}
			foreach ( $rule['targets']['term_ids'] as $tid ) {
				$term = get_term( $tid );
				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}
				// A rule on a term also covers posts in its descendant terms.
				$ids      = array( (int) $tid );
				$children = get_term_children( (int) $tid, $term->taxonomy );
				if ( is_array( $children ) ) {
					$ids = array_merge( $ids, array_map( 'intval', $children ) );
				}
				if ( has_term( $ids, $term->taxonomy, $post ) ) {
					$out[] = $rule;
					break;
				}
			}
		}
		return $out;
	}

	public static function bypass( ?\WP_Post $post ): bool {
		$bypass = $post ? current_user_can( 'edit_post', $post->ID ) : current_user_can( 'manage_options' );
		return (bool) apply_filters( 'verifyblind_bypass_gate', $bypass, $post );
	}

	/** First rule the current visitor does not satisfy, or null. */
	public static function blocking_rule( array $rules ): ?array {
		$owner = Owner::current( false );
		foreach ( $rules as $rule ) {
			if ( null === $owner || ! Evaluator::satisfies( $owner, $rule ) ) {
				return $rule;
			}
		}
		return null;
	}

	public static function no_cache(): void {
		self::$no_cache_requested = true;
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	public static function no_cache_requested(): bool {
		return self::$no_cache_requested;
	}

	/** Test helper. */
	public static function reset_no_cache_flag(): void {
		self::$no_cache_requested = false;
	}

	private static function post_is_gated( \WP_Post $post ): bool {
		return (bool) self::rules_for_post( $post )
			|| has_shortcode( (string) $post->post_content, 'verifyblind_gate' )
			|| has_block( 'verifyblind/gate', $post );
	}

	/** Early guard: any page whose main query contains a gated post must not be cached (archives, search, home...). */
	public static function maybe_no_cache(): void {
		if ( is_feed() ) {
			return;
		}
		global $wp_query;
		$posts = ( $wp_query instanceof \WP_Query && is_array( $wp_query->posts ) ) ? $wp_query->posts : array();
		if ( is_singular() ) {
			$queried = get_queried_object();
			if ( $queried instanceof \WP_Post ) {
				$posts[] = $queried;
			}
		}
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post && self::post_is_gated( $post ) ) {
				self::no_cache();
				return;
			}
		}
	}

	public static function locked_text(): string {
		return __( 'This content requires verification. Open the page to verify.', 'verifyblind' );
	}

	public static function filter_content( $content ) {
		$post = get_post();
		if ( ! $post ) {
			return $content;
		}
		$rules = self::rules_for_post( $post );
		if ( ! $rules ) {
			return $content;
		}
		self::no_cache();
		if ( self::bypass( $post ) ) {
			return $content;
		}
		$blocking = self::blocking_rule( $rules );
		return $blocking ? Widget::box( $blocking ) : $content;
	}

	public static function filter_excerpt( $excerpt, $post = null ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return $excerpt;
		}
		$rules = self::rules_for_post( $post );
		if ( ! $rules ) {
			return $excerpt;
		}
		self::no_cache();
		if ( self::bypass( $post ) ) {
			return $excerpt;
		}
		return self::blocking_rule( $rules ) ? self::locked_text() : $excerpt;
	}

	/** Feed readers never carry a verification: targeted posts are always redacted. */
	public static function filter_feed( $content ) {
		$post = get_post();
		return ( $post && self::rules_for_post( $post ) ) ? self::locked_text() : $content;
	}

	public static function filter_rest( $response, $post ) {
		if ( ! $post instanceof \WP_Post || ! $response instanceof \WP_REST_Response ) {
			return $response;
		}
		$rules = self::rules_for_post( $post );
		if ( ! $rules ) {
			return $response;
		}
		// Locked and unlocked responses are both per-visitor.
		$response->header( 'Cache-Control', 'no-store, private' );
		if ( self::bypass( $post ) || ! self::blocking_rule( $rules ) ) {
			return $response;
		}
		$data = $response->get_data();
		foreach ( array( 'content', 'excerpt' ) as $field ) {
			if ( isset( $data[ $field ] ) && is_array( $data[ $field ] ) ) {
				$data[ $field ]['rendered'] = self::locked_text();
				unset( $data[ $field ]['raw'] );
			}
		}
		$response->set_data( $data );
		return $response;
	}

	/**
	 * Decide what a visitor sees for one gate. Returns the replacement output (box, or '' for a
	 * misconfigured gate), or null when the inner content may be shown.
	 */
	private static function closed_output( string $rule_id ): ?string {
		self::no_cache();
		$post = get_post();
		$rule = Rules::get( $rule_id );
		if ( ! $rule || empty( $rule['enabled'] ) ) {
			// Misconfigured gate fails closed for visitors.
			return self::bypass( $post ) ? null : '';
		}
		if ( self::bypass( $post ) ) {
			return null;
		}
		$blocking = self::blocking_rule( array( $rule ) );
		return $blocking ? Widget::box( $blocking ) : null;
	}

	/** For the block path: the inner HTML is already rendered by core. */
	public static function render_gate( string $rule_id, string $inner ): string {
		$closed = self::closed_output( $rule_id );
		return null === $closed ? $inner : $closed;
	}

	public static function shortcode( $atts, $content = '' ): string {
		$atts   = shortcode_atts( array( 'rule' => '' ), $atts, 'verifyblind_gate' );
		$closed = self::closed_output( (string) $atts['rule'] );
		if ( null !== $closed ) {
			return $closed; // Locked: inner shortcodes are never executed.
		}
		return do_shortcode( (string) $content );
	}

	public static function register_block(): void {
		wp_register_script(
			'verifyblind-gate-block',
			VERIFYBLIND_URL . 'assets/js/gate-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			VERIFYBLIND_VERSION,
			true
		);
		$choices = array();
		foreach ( Rules::all() as $r ) {
			$choices[] = array( 'value' => $r['id'], 'label' => $r['name'] );
		}
		wp_add_inline_script( 'verifyblind-gate-block', 'window.VerifyBlindRules = ' . wp_json_encode( $choices, JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
		register_block_type(
			'verifyblind/gate',
			array(
				'editor_script'   => 'verifyblind-gate-block',
				'attributes'      => array( 'rule' => array( 'type' => 'string', 'default' => '' ) ),
				'render_callback' => function ( $attrs, $content ) {
					return Gate::render_gate( (string) ( isset( $attrs['rule'] ) ? $attrs['rule'] : '' ), (string) $content );
				},
			)
		);
	}
}
