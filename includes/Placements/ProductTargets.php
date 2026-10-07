<?php
namespace VerifyBlind\Placements;

defined( 'ABSPATH' ) || exit;

use VerifyBlind\Rules;
use VerifyBlind\Targets;

/**
 * Which WooCommerce rules cover a product: by product id or by product category (with its subcategories).
 * A variation is judged by its parent product. A rule without targets covers no product.
 */
final class ProductTargets {
	public static function base_post( int $product_id ): ?\WP_Post {
		$post = get_post( $product_id );
		if ( $post && 'product_variation' === $post->post_type && (int) $post->post_parent > 0 ) {
			$post = get_post( (int) $post->post_parent );
		}
		return ( $post && 'product' === $post->post_type ) ? $post : null;
	}

	/** @return array[] */
	public static function rules_for_product( int $product_id, string $placement ): array {
		$rules = Rules::enabled( $placement );
		if ( ! $rules ) {
			return array();
		}
		$post = self::base_post( $product_id );
		if ( null === $post ) {
			return array();
		}
		$out = array();
		foreach ( $rules as $rule ) {
			if ( Targets::covers( $post, $rule ) ) {
				$out[] = $rule;
			}
		}
		return $out;
	}

	/**
	 * @param mixed $cart \WC_Cart or null
	 * @return array[] each rule once
	 */
	public static function rules_for_cart( $cart, string $placement ): array {
		if ( ! $cart instanceof \WC_Cart || ! Rules::enabled( $placement ) ) {
			return array();
		}
		$out = array();
		foreach ( $cart->get_cart() as $item ) {
			$id = ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : ( isset( $item['product_id'] ) ? (int) $item['product_id'] : 0 );
			foreach ( self::rules_for_product( $id, $placement ) as $rule ) {
				$out[ $rule['id'] ] = $rule;
			}
		}
		return array_values( $out );
	}

	/** @return array[] each rule once */
	public static function rules_for_order( \WC_Order $order, string $placement ): array {
		if ( ! Rules::enabled( $placement ) ) {
			return array();
		}
		$out = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$id = $item->get_variation_id() ? (int) $item->get_variation_id() : (int) $item->get_product_id();
			foreach ( self::rules_for_product( $id, $placement ) as $rule ) {
				$out[ $rule['id'] ] = $rule;
			}
		}
		return array_values( $out );
	}
}
