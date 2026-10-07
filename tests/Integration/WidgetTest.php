<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Widget;

final class WidgetTest extends TestCase {
	/** Localizes the front script afresh and returns the VerifyBlindWP object it would print. */
	private function localized(): array {
		$flag = new \ReflectionProperty( Widget::class, 'localized' );
		$flag->setAccessible( true );
		$flag->setValue( null, false );
		Widget::register();
		wp_scripts()->add_data( 'verifyblind-front', 'data', '' );
		Widget::enqueue();
		$data = (string) wp_scripts()->get_data( 'verifyblind-front', 'data' );
		$this->assertSame( 1, preg_match( '/var VerifyBlindWP = (\{.*\});/s', $data, $m ), $data );
		$json = json_decode( $m[1], true );
		$this->assertIsArray( $json );
		return $json;
	}

	public function test_guests_get_no_rest_nonce(): void {
		$cfg = $this->localized();
		$this->assertSame( '', $cfg['restNonce'] );
		$this->assertNotSame( '', $cfg['generateUrl'] );
	}

	public function test_logged_in_users_get_a_valid_rest_nonce(): void {
		wp_set_current_user( $this->make_user() );
		$cfg = $this->localized();
		$this->assertNotSame( '', $cfg['restNonce'] );
		$this->assertNotFalse( wp_verify_nonce( $cfg['restNonce'], 'wp_rest' ) );
	}
}
