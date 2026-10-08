<?php
namespace VerifyBlind\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VerifyBlind\Rules;

final class RulesSanitizeTest extends TestCase {
	private function base( array $o = array() ): array {
		return array_merge( array( 'name' => 'Alkol', 'enabled' => '1', 'placement' => 'content', 'age' => ' 18+ ' ), $o );
	}

	public function test_normalizes_a_valid_rule(): void {
		$r = Rules::sanitize( $this->base( array( 'targets' => array( 'post_ids' => '3, 5,abc,3', 'term_ids' => array( '7', '0' ) ) ) ), array( 'content' ) );
		$this->assertSame( '', $r['id'] );
		$this->assertSame( 'Alkol', $r['name'] );
		$this->assertTrue( $r['enabled'] );
		$this->assertSame( '18+', $r['age'] );
		$this->assertFalse( $r['unique'] );
		$this->assertSame( 'reject', $r['duplicate_policy'] );
		$this->assertSame( array( 3, 5 ), $r['targets']['post_ids'] );
		$this->assertSame( array( 7 ), $r['targets']['term_ids'] );
		$this->assertSame( 0, $r['validity_days'] );
	}

	/** @dataProvider rejects */
	public function test_rejects( array $override, string $code ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( $code );
		Rules::sanitize( $this->base( $override ), array( 'content' ) );
	}

	public function rejects(): array {
		return array(
			array( array( 'id' => 'x' ), 'bad_id' ),
			array( array( 'name' => '  <b></b> ' ), 'empty_name' ),
			array( array( 'placement' => 'wc_checkout' ), 'bad_placement' ),
			array( array( 'age' => '0+' ), 'bad_age' ),
			array( array( 'age' => '', 'unique' => '' ), 'empty_request' ),
		);
	}

	public function test_unique_only_rule_is_valid(): void {
		$r = Rules::sanitize( $this->base( array( 'age' => '', 'unique' => '1', 'duplicate_policy' => 'transfer' ) ), array( 'content' ) );
		$this->assertTrue( $r['unique'] );
		$this->assertSame( 'transfer', $r['duplicate_policy'] );
	}

	public function test_unknown_policy_falls_back_and_admin_role_is_dropped(): void {
		$r = Rules::sanitize( $this->base( array( 'duplicate_policy' => 'nuke', 'role' => 'administrator' ) ), array( 'content' ) );
		$this->assertSame( 'reject', $r['duplicate_policy'] );
		$this->assertSame( '', $r['role'] );
	}

	public function test_validity_is_clamped(): void {
		$this->assertSame( 3650, Rules::sanitize( $this->base( array( 'validity_days' => '99999' ) ), array( 'content' ) )['validity_days'] );
		$this->assertSame( 0, Rules::sanitize( $this->base( array( 'validity_days' => '-4' ) ), array( 'content' ) )['validity_days'] );
	}

	public function test_age_from_form(): void {
		$this->assertSame( '21+', Rules::age_from_form( 'at_least', '21', '' ) );
		$this->assertSame( '15-', Rules::age_from_form( 'under', 15, 0 ) );
		$this->assertSame( '13-18', Rules::age_from_form( 'between', '13', '18' ) );
		$this->assertSame( '', Rules::age_from_form( 'none', 18, 0 ) );
	}

	public function test_ages_nobody_verifiable_can_meet_are_refused(): void {
		foreach ( array( '15-', '10-', '1-', '5-15', '14-15' ) as $age ) {
			try {
				Rules::sanitize( $this->base( array( 'age' => $age ) ), array( 'content' ) );
				$this->fail( 'expected age_unreachable for ' . $age );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'age_unreachable', $e->getMessage(), $age );
			}
		}
	}

	public function test_ages_just_above_the_minimum_are_allowed(): void {
		foreach ( array( '16-', '15-18', '15+', '18+' ) as $age ) {
			$this->assertSame( $age, Rules::sanitize( $this->base( array( 'age' => $age ) ), array( 'content' ) )['age'] );
		}
	}

	public function test_guest_mode_defaults_to_verify_each_order(): void {
		$this->assertSame( 'verify_each_order', Rules::sanitize( $this->base(), array( 'content' ) )['guest_mode'] );
		$this->assertSame( 'require_account', Rules::sanitize( $this->base( array( 'guest_mode' => 'require_account' ) ), array( 'content' ) )['guest_mode'] );
		$this->assertSame( 'verify_each_order', Rules::sanitize( $this->base( array( 'guest_mode' => 'anything' ) ), array( 'content' ) )['guest_mode'] );
	}
}
