<?php
namespace VerifyBlind\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase {
	public function test_prefixed_phpseclib_is_loadable(): void {
		$this->assertTrue( class_exists( \VerifyBlind\Vendor\phpseclib3\Crypt\RSA::class ) );
		$this->assertFalse( class_exists( 'phpseclib3\\Crypt\\RSA', false ), 'unprefixed copy must not be loaded by the plugin' );
	}
}
