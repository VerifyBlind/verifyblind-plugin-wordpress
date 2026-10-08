<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

use VerifyBlind\Placements\Registry;

/**
 * Ready-made rules for the setup wizard's "What kind of site is this?" step. A preset only creates normal
 * rules through Rules::save() (and switches on badge places); the site owner changes them later like any
 * other rule. No preset creates a whole-site entrance (wc_site) rule, and none would be created switched on.
 */
final class Presets {
	/** @return array<string,array> key => label, help, woocommerce (bool), rules (Rules::save() input), badges (Badge places) */
	public static function all(): array {
		return array(
			'age_shop'    => array(
				'label'       => __( 'Shop selling alcohol, tobacco or adult products', 'verifyblind' ),
				'help'        => __( 'Age 18+ at checkout for the products or categories you choose.', 'verifyblind' ),
				'woocommerce' => true,
				'rules'       => array(
					array( 'name' => __( 'Age 18+ at checkout', 'verifyblind' ), 'placement' => 'wc_checkout', 'age' => '18+' ),
				),
				'badges'      => array(),
			),
			'age_content' => array(
				'label'       => __( 'Age-restricted content', 'verifyblind' ),
				'help'        => __( 'Pages, posts or categories you choose open at age 18+.', 'verifyblind' ),
				'woocommerce' => false,
				'rules'       => array(
					array( 'name' => __( 'Age 18+ content', 'verifyblind' ), 'placement' => 'content', 'age' => '18+' ),
				),
				'badges'      => array(),
			),
			'community'   => array(
				'label'       => __( 'Forum, community or classifieds', 'verifyblind' ),
				'help'        => __( 'One account per person at sign-up, with a verified badge.', 'verifyblind' ),
				'woocommerce' => false,
				'rules'       => array(
					array( 'name' => __( 'One account per person at sign-up', 'verifyblind' ), 'placement' => 'registration', 'unique' => true, 'duplicate_policy' => 'block' ),
				),
				'badges'      => array( 'comments', 'author' ),
			),
			'reviews'     => array(
				'label'       => __( 'Comments and reviews', 'verifyblind' ),
				'help'        => __( 'Comments and product reviews from verified people (one person, one account), with a verified badge.', 'verifyblind' ),
				'woocommerce' => false,
				'rules'       => array(
					array( 'name' => __( 'Comments from verified people', 'verifyblind' ), 'placement' => 'comments', 'unique' => true ),
					array( 'name' => __( 'Product reviews from verified people', 'verifyblind' ), 'placement' => 'wc_review', 'unique' => true ),
				),
				'badges'      => array( 'comments', 'reviews' ),
			),
			'coupons'     => array(
				'label'       => __( 'Campaigns and coupons', 'verifyblind' ),
				'help'        => __( 'Coupons you choose can be used once per person.', 'verifyblind' ),
				'woocommerce' => true,
				'rules'       => array(
					array( 'name' => __( 'Coupons once per person', 'verifyblind' ), 'placement' => 'wc_coupon', 'unique' => true ),
				),
				'badges'      => array(),
			),
		);
	}

	public static function available( string $key, bool $wc_active ): bool {
		$all = self::all();
		return isset( $all[ $key ] ) && ( $wc_active || ! $all[ $key ]['woocommerce'] );
	}

	/**
	 * Creates the rules of the chosen presets. Skipped: unknown or unavailable presets, rules whose placement is
	 * not registered (a review rule without WooCommerce), and rules that already exist (same name and placement).
	 *
	 * @return array[] the rules created, in order
	 */
	public static function apply( array $keys ): array {
		$all        = self::all();
		$wc         = Registry::woocommerce_active();
		$placements = array_keys( Rules::placements() );
		$saved      = array();
		$badges     = array();
		foreach ( array_unique( array_map( 'strval', $keys ) ) as $key ) {
			if ( ! self::available( $key, $wc ) ) {
				continue;
			}
			foreach ( $all[ $key ]['rules'] as $index => $template ) {
				$marker = $key . ':' . $index;
				if ( ! in_array( $template['placement'], $placements, true ) || self::exists( $marker, $template['name'], $template['placement'] ) ) {
					continue;
				}
				$template['preset'] = $marker;
				$defaults = array(
					'enabled'          => 'wc_site' !== $template['placement'],
					'age'              => '',
					'unique'           => false,
					'duplicate_policy' => 'reject',
				);
				$saved[]  = Rules::save( array_merge( $defaults, $template ) );
			}
			$badges = array_merge( $badges, $all[ $key ]['badges'] );
		}
		if ( $badges ) {
			update_option( Badge::OPTION, Badge::sanitize_places( array_merge( Badge::places(), $badges ) ) );
		}
		return $saved;
	}

	/** Same marker = already created (even if renamed); rules without a marker fall back to name + placement. */
	private static function exists( string $marker, string $name, string $placement ): bool {
		foreach ( Rules::all() as $rule ) {
			if ( ! empty( $rule['preset'] ) ) {
				if ( $marker === $rule['preset'] ) {
					return true;
				}
				continue;
			}
			if ( isset( $rule['name'], $rule['placement'] ) && $name === $rule['name'] && $placement === $rule['placement'] ) {
				return true;
			}
		}
		return false;
	}
}
