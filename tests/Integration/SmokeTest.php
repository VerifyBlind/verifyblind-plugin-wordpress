<?php
namespace VerifyBlind\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase {
	public function test_plugin_is_loaded_in_wordpress(): void {
		$this->assertSame( '1.0.3', VERIFYBLIND_VERSION );
		$this->assertContains( 'verifyblind/verifyblind.php', (array) get_option( 'active_plugins', array() ) );
	}
}
