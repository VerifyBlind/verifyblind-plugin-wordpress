<?php
namespace VerifyBlind\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VerifyBlind\AgeRule;

final class AgeRuleTest extends TestCase {
	/** @dataProvider valid */
	public function test_parses_and_normalizes( string $in, string $out, int $min, ?int $max ): void {
		$r = AgeRule::parse( $in );
		$this->assertNotNull( $r );
		$this->assertSame( $out, $r->to_string() );
		$this->assertSame( $min, $r->min() );
		$this->assertSame( $max, $r->max() );
	}

	public function valid(): array {
		return array(
			array( '18+', '18+', 18, null ),
			array( ' 21+ ', '21+', 21, null ),
			array( '16-', '16-', 0, 16 ),
			array( '18-65', '18-65', 18, 65 ),
			array( '0-13', '13-', 0, 13 ),
		);
	}

	/** @dataProvider invalid */
	public function test_rejects( string $in ): void {
		$this->assertNull( AgeRule::parse( $in ) );
	}

	public function invalid(): array {
		return array( array( '' ), array( '0+' ), array( '0-' ), array( 'abc' ), array( '65-18' ), array( '18-18' ), array( '1000+' ), array( '18' ), array( '-5' ), array( '18+65' ) );
	}

	/** @dataProvider implications */
	public function test_implies( string $have, string $want, bool $expected ): void {
		$this->assertSame( $expected, AgeRule::parse( $have )->implies( AgeRule::parse( $want ) ) );
	}

	public function implications(): array {
		return array(
			'21+ covers 18+'        => array( '21+', '18+', true ),
			'18+ does not cover 21+' => array( '18+', '21+', false ),
			'same'                  => array( '18+', '18+', true ),
			'16- covers 18-'        => array( '16-', '18-', true ),
			'18- does not cover 16-' => array( '18-', '16-', false ),
			'13-18 covers 13+'      => array( '13-18', '13+', true ),
			'13-18 covers 18-'      => array( '13-18', '18-', true ),
			'13-18 not 16+'         => array( '13-18', '16+', false ),
			'18+ not 18-65'         => array( '18+', '18-65', false ),
			'20-30 covers 18-65'    => array( '20-30', '18-65', true ),
			'18- not 18+'           => array( '18-', '18+', false ),
		);
	}
}
