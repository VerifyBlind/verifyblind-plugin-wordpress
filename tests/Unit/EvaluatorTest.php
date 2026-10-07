<?php
namespace VerifyBlind\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VerifyBlind\Evaluator;

final class EvaluatorTest extends TestCase {
	private function rule( string $age, bool $unique ): array {
		return array( 'age' => $age, 'unique' => $unique );
	}

	public function test_age_inference(): void {
		$this->assertTrue( Evaluator::conditions_satisfy( array( '21+' ), $this->rule( '18+', false ) ) );
		$this->assertFalse( Evaluator::conditions_satisfy( array( '18+' ), $this->rule( '21+', false ) ) );
		$this->assertFalse( Evaluator::conditions_satisfy( array(), $this->rule( '18+', false ) ) );
	}

	public function test_unique_needs_uid(): void {
		$this->assertFalse( Evaluator::conditions_satisfy( array( '18+' ), $this->rule( '18+', true ) ) );
		$this->assertTrue( Evaluator::conditions_satisfy( array( '18+', 'uid' ), $this->rule( '18+', true ) ) );
		$this->assertTrue( Evaluator::conditions_satisfy( array( 'uid' ), $this->rule( '', true ) ) );
	}

	public function test_uid_is_not_an_age(): void {
		$this->assertFalse( Evaluator::conditions_satisfy( array( 'uid' ), $this->rule( '18+', false ) ) );
	}
}
