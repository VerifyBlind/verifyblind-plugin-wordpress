<?php
namespace VerifyBlind;

/**
 * An age condition as the enclave evaluates it: "N+" (age >= N), "N-" (age < N), "N-M" (N <= age < M).
 * Internally a half-open interval [min, max). A person proven to be inside interval A is also inside
 * interval B when A ⊆ B — that is the only inference the plugin makes (never from a failed result).
 */
final class AgeRule {
	const MAX_AGE = 150;

	/** @var int */
	private $min;
	/** @var int|null exclusive upper bound, null = unbounded */
	private $max;

	private function __construct( int $min, ?int $max ) {
		$this->min = $min;
		$this->max = $max;
	}

	public static function parse( string $s ): ?AgeRule {
		$s = trim( $s );
		if ( preg_match( '/^(\d{1,3})\+$/', $s, $m ) ) {
			$n = (int) $m[1];
			return ( $n >= 1 && $n <= self::MAX_AGE ) ? new self( $n, null ) : null;
		}
		if ( preg_match( '/^(\d{1,3})-$/', $s, $m ) ) {
			$n = (int) $m[1];
			return ( $n >= 1 && $n <= self::MAX_AGE ) ? new self( 0, $n ) : null;
		}
		if ( preg_match( '/^(\d{1,3})-(\d{1,3})$/', $s, $m ) ) {
			$a = (int) $m[1];
			$b = (int) $m[2];
			return ( $a < $b && $b <= self::MAX_AGE ) ? new self( $a, $b ) : null;
		}
		return null;
	}

	public function min(): int {
		return $this->min;
	}

	public function max(): ?int {
		return $this->max;
	}

	public function to_string(): string {
		if ( null === $this->max ) {
			return $this->min . '+';
		}
		if ( 0 === $this->min ) {
			return $this->max . '-';
		}
		return $this->min . '-' . $this->max;
	}

	public function implies( AgeRule $other ): bool {
		if ( $this->min < $other->min ) {
			return false;
		}
		if ( null === $other->max ) {
			return true;
		}
		return null !== $this->max && $this->max <= $other->max;
	}
}
