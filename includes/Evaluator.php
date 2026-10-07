<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

final class Evaluator {
	/**
	 * Pure. $passed = condition strings the owner has passed ('18+', '13-18', 'uid', ...).
	 */
	public static function conditions_satisfy( array $passed, array $rule ): bool {
		if ( ! empty( $rule['unique'] ) && ! in_array( 'uid', $passed, true ) ) {
			return false;
		}
		$age = (string) ( isset( $rule['age'] ) ? $rule['age'] : '' );
		if ( '' === $age ) {
			return true;
		}
		$want = AgeRule::parse( $age );
		if ( null === $want ) {
			return false;
		}
		foreach ( $passed as $cond ) {
			if ( 'uid' === $cond ) {
				continue;
			}
			$have = AgeRule::parse( (string) $cond );
			if ( null !== $have && $have->implies( $want ) ) {
				return true;
			}
		}
		return false;
	}

	/** $include_test null = the global test-mode setting. */
	public static function satisfies( string $owner, array $rule, ?bool $include_test = null ): bool {
		$validity = (int) ( isset( $rule['validity_days'] ) ? $rule['validity_days'] : 0 );
		$test     = null === $include_test ? Settings::test_mode() : $include_test;
		return self::conditions_satisfy( Results::passed_conditions( $owner, $validity, $test ), $rule );
	}
}
