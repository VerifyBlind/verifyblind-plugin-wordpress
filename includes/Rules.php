<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

/**
 * Rules live in one non-autoloaded option keyed by id. A rule = where (placement + targets) +
 * what (age condition and/or one-person check) + duplicate policy + role + validity.
 */
final class Rules {
	const OPTION   = 'verifyblind_rules';
	const POLICIES = array( 'reject', 'block', 'flag', 'transfer' );

	/** @return array<string,string> placement => label. Placements\Registry adds the other placements through the filter. */
	public static function placements(): array {
		$base = array(
			'content'   => __( 'Page, post or category lock', 'verifyblind' ),
			'role_only' => __( 'Only grant a role (used by another plugin)', 'verifyblind' ),
		);
		return (array) apply_filters( 'verifyblind_placements', $base );
	}

	/** @return array<string,array> */
	public static function all(): array {
		$rules = get_option( self::OPTION, array() );
		return is_array( $rules ) ? $rules : array();
	}

	public static function get( string $id ): ?array {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/** @return array[] switched-on rules of one placement, in saved order */
	public static function enabled( string $placement ): array {
		$out = array();
		foreach ( self::all() as $rule ) {
			if ( ! empty( $rule['enabled'] ) && isset( $rule['placement'] ) && $placement === $rule['placement'] ) {
				$out[] = $rule;
			}
		}
		return $out;
	}

	/** @throws \InvalidArgumentException */
	public static function save( array $input ): array {
		$rule = self::sanitize( $input, array_keys( self::placements() ) );
		if ( '' === $rule['id'] ) {
			$rule['id'] = 'r_' . bin2hex( random_bytes( 4 ) );
		}
		$all                = self::all();
		$all[ $rule['id'] ] = $rule;
		update_option( self::OPTION, $all, false );
		return $rule;
	}

	public static function delete( string $id ): void {
		$all = self::all();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Pure: no WordPress calls.
	 *
	 * @throws \InvalidArgumentException bad_id|empty_name|bad_placement|bad_age|empty_request
	 */
	public static function sanitize( array $in, array $allowed_placements ): array {
		$id = isset( $in['id'] ) ? (string) $in['id'] : '';
		if ( '' !== $id && ! preg_match( '/^r_[a-f0-9]{8}$/', $id ) ) {
			throw new \InvalidArgumentException( 'bad_id' );
		}
		$name = trim( strip_tags( (string) ( isset( $in['name'] ) ? $in['name'] : '' ) ) );
		if ( '' === $name ) {
			throw new \InvalidArgumentException( 'empty_name' );
		}
		$placement = (string) ( isset( $in['placement'] ) ? $in['placement'] : '' );
		if ( ! in_array( $placement, $allowed_placements, true ) ) {
			throw new \InvalidArgumentException( 'bad_placement' );
		}
		$age = trim( (string) ( isset( $in['age'] ) ? $in['age'] : '' ) );
		if ( '' !== $age ) {
			$parsed = AgeRule::parse( $age );
			if ( null === $parsed ) {
				throw new \InvalidArgumentException( 'bad_age' );
			}
			$age = $parsed->to_string();
		}
		$unique = ! empty( $in['unique'] );
		if ( '' === $age && ! $unique ) {
			throw new \InvalidArgumentException( 'empty_request' );
		}
		$policy = (string) ( isset( $in['duplicate_policy'] ) ? $in['duplicate_policy'] : 'reject' );
		if ( ! in_array( $policy, self::POLICIES, true ) ) {
			$policy = 'reject';
		}
		$role = strtolower( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( isset( $in['role'] ) ? $in['role'] : '' ) ) );
		if ( 'administrator' === $role ) {
			$role = '';
		}
		$targets = isset( $in['targets'] ) && is_array( $in['targets'] ) ? $in['targets'] : array();
		return array(
			'id'               => $id,
			'name'             => $name,
			'enabled'          => ! empty( $in['enabled'] ),
			'placement'        => $placement,
			'targets'          => array(
				'post_ids' => self::ints( isset( $targets['post_ids'] ) ? $targets['post_ids'] : array() ),
				'term_ids' => self::ints( isset( $targets['term_ids'] ) ? $targets['term_ids'] : array() ),
			),
			'age'              => $age,
			'unique'           => $unique,
			'duplicate_policy' => $policy,
			'role'             => $role,
			'validity_days'    => max( 0, min( 3650, (int) ( isset( $in['validity_days'] ) ? $in['validity_days'] : 0 ) ) ),
		);
	}

	/** Pure: the editor's age widgets -> condition string ('' = no age). */
	public static function age_from_form( string $type, $n, $m ): string {
		$n = (int) $n;
		$m = (int) $m;
		switch ( $type ) {
			case 'at_least':
				return $n . '+';
			case 'under':
				return $n . '-';
			case 'between':
				return $n . '-' . $m;
			default:
				return '';
		}
	}

	/** @param mixed $v */
	private static function ints( $v ): array {
		if ( is_string( $v ) ) {
			$v = explode( ',', $v );
		}
		if ( ! is_array( $v ) ) {
			return array();
		}
		$out = array();
		foreach ( $v as $x ) {
			$n = (int) trim( (string) $x );
			if ( $n > 0 && ! in_array( $n, $out, true ) ) {
				$out[] = $n;
			}
		}
		return $out;
	}
}
