<?php
namespace VerifyBlind\Placements;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use VerifyBlind\Gate;
use VerifyBlind\Messages;

/**
 * Product pages of targeted products. Until the visitor meets the rule: the add-to-cart form is replaced by
 * the box, descriptions are replaced by the lock text everywhere they are read, and adding to the cart is
 * refused on the server (classic, AJAX and Store API). Pages are never cached.
 */
final class WcProduct {
	const KEY    = 'wc_product';
	const BLOCKS = array( 'woocommerce/add-to-cart-form', 'woocommerce/add-to-cart-with-options' );

	public static function label(): string {
		return __( 'WooCommerce product page (chosen products or categories)', 'verifyblind' );
	}

	public static function hooks(): void {
		add_action( 'template_redirect', array( self::class, 'on_template_redirect' ) );
		add_action( 'woocommerce_before_single_product', array( self::class, 'swap_classic_add_to_cart' ) );
		add_filter( 'render_block', array( self::class, 'render_block' ), 10, 3 );
		add_filter( 'woocommerce_add_to_cart_validation', array( self::class, 'add_to_cart_validation' ), 10, 4 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( self::class, 'store_api_validate' ), 10, 2 );
		foreach ( array( 'woocommerce_product_get_description', 'woocommerce_product_get_short_description', 'woocommerce_product_variation_get_description' ) as $getter ) {
			add_filter( $getter, array( self::class, 'filter_description' ), 999, 2 );
		}
		add_filter( 'woocommerce_short_description', array( self::class, 'filter_short_description' ), 999 );
		add_filter( 'the_content', array( self::class, 'filter_post_text' ), 999 );
		add_filter( 'get_the_excerpt', array( self::class, 'filter_excerpt' ), 999, 2 );
		add_filter( 'rest_prepare_product', array( self::class, 'filter_rest' ), 999, 2 );
	}

	/** @var array<string,?array> per-request memo of blocking(), keyed by product id and user id */
	private static $memo = array();

	/** Forget the per-request memo (tests; a verification just happened). */
	public static function reset_memo(): void {
		self::$memo = array();
	}

	/**
	 * The rule blocking the current visitor from this product (or its parent), or null. Editors are never blocked.
	 * Any product with wc_product rules is per-visitor output, so the page is marked uncacheable here.
	 */
	public static function blocking( int $product_id ): ?array {
		$key = $product_id . ':' . get_current_user_id();
		if ( array_key_exists( $key, self::$memo ) ) {
			if ( self::$memo[ $key ]['targeted'] ) {
				Gate::no_cache();
			}
			return self::$memo[ $key ]['rule'];
		}
		$rules    = ProductTargets::rules_for_product( $product_id, self::KEY );
		$rule     = null;
		if ( $rules ) {
			Gate::no_cache();
			if ( ! Gate::bypass( ProductTargets::base_post( $product_id ) ) ) {
				$rule = Gate::blocking_rule( $rules );
			}
		}
		self::$memo[ $key ] = array( 'targeted' => (bool) $rules, 'rule' => $rule );
		return $rule;
	}

	/** Cron, imports and WP-CLI are not visitors: they must read (and persist) the real text. */
	private static function is_visitor_context(): bool {
		return ! ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) );
	}

	public static function box_html( array $rule ): string {
		return Prompt::html( $rule, array( 'title' => __( 'Verify with VerifyBlind to see and buy this product.', 'verifyblind' ) ) );
	}

	/** Both the locked and the unlocked page are per-visitor. */
	public static function on_template_redirect(): void {
		if ( is_singular( 'product' ) && ProductTargets::rules_for_product( (int) get_queried_object_id(), self::KEY ) ) {
			Gate::no_cache();
		}
	}

	/** Classic product template: the add-to-cart form gives way to the box. */
	public static function swap_classic_add_to_cart(): void {
		$id = (int) get_the_ID();
		if ( $id > 0 && null !== self::blocking( $id ) ) {
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
			add_action( 'woocommerce_single_product_summary', array( self::class, 'print_box' ), 30 );
		}
	}

	public static function print_box(): void {
		$rule = self::blocking( (int) get_the_ID() );
		if ( null !== $rule ) {
			echo wp_kses_post( self::box_html( $rule ) );
		}
	}

	/**
	 * Block product template (the test site's theme): the add-to-cart blocks give way to the box.
	 *
	 * @param mixed $html
	 * @param mixed $block
	 * @param mixed $instance WP_Block
	 * @return mixed
	 */
	public static function render_block( $html, $block, $instance = null ) {
		if ( ! is_array( $block ) || ! isset( $block['blockName'] ) || ! in_array( $block['blockName'], self::BLOCKS, true ) ) {
			return $html;
		}
		$id   = ( is_object( $instance ) && isset( $instance->context ) && is_array( $instance->context ) && isset( $instance->context['postId'] ) ) ? (int) $instance->context['postId'] : (int) get_the_ID();
		$rule = $id > 0 ? self::blocking( $id ) : null;
		return null === $rule ? $html : wp_kses_post( self::box_html( $rule ) );
	}

	/**
	 * Classic and AJAX add to cart.
	 *
	 * @param mixed $passed
	 * @return mixed
	 */
	public static function add_to_cart_validation( $passed, $product_id = 0, $quantity = 1, $variation_id = 0 ) {
		$id = (int) $variation_id > 0 ? (int) $variation_id : (int) $product_id;
		if ( $passed && $id > 0 && null !== self::cart_blocking( $id ) ) {
			wc_add_notice( Messages::get( 'product_required' ), 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Store API add to cart (cart and product blocks).
	 *
	 * @param mixed $product WC_Product
	 * @throws RouteException
	 */
	public static function store_api_validate( $product, $request = null ): void {
		if ( $product instanceof \WC_Product && null !== self::cart_blocking( (int) $product->get_id() ) ) {
			throw new RouteException( 'verifyblind_required', Messages::get( 'product_required' ), 403 );
		}
	}

	/** The rule refusing this product at add to cart: its own product rule, or a shop entrance (wc_site) rule, which covers every product. */
	public static function cart_blocking( int $product_id ): ?array {
		$rule = self::blocking( $product_id );
		return null !== $rule ? $rule : WcSite::cart_blocking();
	}

	/**
	 * Product getters ('view' context): Store API, REST v3 and templates read descriptions through these.
	 *
	 * @param mixed $value
	 * @param mixed $product WC_Product
	 * @return mixed
	 */
	public static function filter_description( $value, $product = null ) {
		return ( $product instanceof \WC_Product && self::is_visitor_context() && null !== self::blocking( (int) $product->get_id() ) ) ? Gate::locked_text() : $value;
	}

	/**
	 * Classic template short description.
	 *
	 * @param mixed $text
	 * @return mixed
	 */
	public static function filter_short_description( $text ) {
		$id = (int) get_the_ID();
		return ( $id > 0 && self::is_visitor_context() && 'product' === get_post_type( $id ) && null !== self::blocking( $id ) ) ? Gate::locked_text() : $text;
	}

	/**
	 * Description tab (the_content of the product).
	 *
	 * @param mixed $content
	 * @return mixed
	 */
	public static function filter_post_text( $content ) {
		$post = get_post();
		return ( $post && self::is_visitor_context() && 'product' === $post->post_type && null !== self::blocking( (int) $post->ID ) ) ? Gate::locked_text() : $content;
	}

	/**
	 * core/post-excerpt in the block product template.
	 *
	 * @param mixed $excerpt
	 * @param mixed $post
	 * @return mixed
	 */
	public static function filter_excerpt( $excerpt, $post = null ) {
		$post = get_post( $post );
		return ( $post && self::is_visitor_context() && 'product' === $post->post_type && null !== self::blocking( (int) $post->ID ) ) ? Gate::locked_text() : $excerpt;
	}

	/**
	 * /wp/v2/product.
	 *
	 * @param mixed $response WP_REST_Response
	 * @param mixed $post     WP_Post
	 * @return mixed
	 */
	public static function filter_rest( $response, $post ) {
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post || ! ProductTargets::rules_for_product( (int) $post->ID, self::KEY ) ) {
			return $response;
		}
		$response->header( 'Cache-Control', 'no-store, private' );
		if ( null === self::blocking( (int) $post->ID ) ) {
			return $response;
		}
		$data = $response->get_data();
		foreach ( array( 'content', 'excerpt' ) as $field ) {
			if ( isset( $data[ $field ] ) && is_array( $data[ $field ] ) ) {
				$data[ $field ]['rendered'] = Gate::locked_text();
				unset( $data[ $field ]['raw'] );
			}
		}
		$response->set_data( $data );
		return $response;
	}
}
