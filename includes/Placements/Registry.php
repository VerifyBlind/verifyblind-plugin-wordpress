<?php
namespace VerifyBlind\Placements;

defined( 'ABSPATH' ) || exit;

/**
 * The placement classes. Each has `const KEY`, `label(): string` and `hooks(): void`. WooCommerce placements
 * run only while WooCommerce is active; otherwise the rule editor shows them greyed out.
 */
final class Registry {
	/** @return string[] placement classes that work on any site */
	public static function core(): array {
		return array();
	}

	/** @return string[] placement classes that need WooCommerce */
	public static function woocommerce(): array {
		return array();
	}

	public static function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/** @return array{active:string[], unavailable:string[]} */
	public static function split( bool $wc_active ): array {
		return array(
			'active'      => $wc_active ? array_merge( self::core(), self::woocommerce() ) : self::core(),
			'unavailable' => $wc_active ? array() : self::woocommerce(),
		);
	}

	public static function boot(): void {
		add_filter( 'verifyblind_placements', array( self::class, 'add_labels' ) );
		foreach ( self::split( self::woocommerce_active() )['active'] as $class ) {
			$class::hooks();
		}
	}

	/** @param mixed $placements */
	public static function add_labels( $placements ): array {
		$placements = is_array( $placements ) ? $placements : array();
		foreach ( self::split( self::woocommerce_active() )['active'] as $class ) {
			$placements[ $class::KEY ] = $class::label();
		}
		return $placements;
	}

	/** @return array<string,string> placement => label, for placements that need a plugin that is not active */
	public static function unavailable(): array {
		$out = array();
		foreach ( self::split( self::woocommerce_active() )['unavailable'] as $class ) {
			$out[ $class::KEY ] = $class::label();
		}
		return $out;
	}
}
