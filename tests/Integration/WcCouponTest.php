<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Identities;
use VerifyBlind\Messages;
use VerifyBlind\Placements\WcCoupon;
use VerifyBlind\Results;
use VerifyBlind\Rules;

final class WcCouponTest extends WcTestCase {
	/** @return true|\WP_Error */
	private function check( \WC_Coupon $coupon ) {
		return ( new \WC_Discounts( WC()->cart ) )->is_coupon_valid( new \WC_Coupon( $coupon->get_code() ) );
	}

	private function coupon_rule( \WC_Coupon $c, array $o = array() ): array {
		return $this->rule( array_merge( array( 'placement' => WcCoupon::KEY, 'age' => '18+', 'targets' => array( 'post_ids' => array( $c->get_id() ) ) ), $o ) );
	}

	private function person( string $code ): int {
		$uid = $this->make_user( 'customer' );
		Identities::insert( $code, $uid, null, null, 'n-' . $code );
		Results::add( 'u:' . $uid, 'uid', true, 'n-' . $code, false );
		return $uid;
	}

	public function test_is_a_placement(): void {
		$this->assertArrayHasKey( WcCoupon::KEY, Rules::placements() );
	}

	public function test_an_age_coupon_needs_verification_and_shows_the_box(): void {
		$c    = $this->coupon();
		$free = $this->coupon();
		$rule = $this->coupon_rule( $c );
		WC()->cart->add_to_cart( $this->product() );
		$r = $this->check( $c );
		$this->assertInstanceOf( \WP_Error::class, $r );
		$this->assertSame( Messages::get( 'coupon_required' ), $r->get_error_message() );
		$this->assertTrue( $this->check( $free ) );

		$this->assertSame( $rule['id'], WC()->session->get( WcCoupon::SESSION ) );
		ob_start();
		WcCoupon::print_box();
		$this->assertStringContainsString( 'verifyblind-start', (string) ob_get_clean() );
		$this->assertStringContainsString( 'verifyblind-box', WcCoupon::render_cart_block( '<div class="wc-block-cart"></div>', array( 'blockName' => 'woocommerce/cart' ) ) );

		$this->verify_guest( '18+' );
		$this->assertTrue( $this->check( $c ) );
		$this->assertNull( WcCoupon::pending_rule() );
		ob_start();
		WcCoupon::print_box();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_a_one_person_coupon_needs_login_and_is_used_once_per_person(): void {
		$c = $this->coupon();
		$this->coupon_rule( $c, array( 'age' => '', 'unique' => true ) );
		WC()->cart->add_to_cart( $this->product() );
		$this->assertSame( Messages::get( 'coupon_login' ), $this->check( $c )->get_error_message() );

		$a = $this->person( 'P-COUPON' );
		wp_set_current_user( $a );
		$this->assertTrue( $this->check( $c ) );

		$order = wc_create_order( array( 'customer_id' => $a ) );
		$item  = new \WC_Order_Item_Coupon();
		$item->set_code( $c->get_code() );
		$order->add_item( $item );
		$order->save();
		$this->wc_orders[] = $order->get_id();
		$order->update_status( 'processing' );
		$this->assertCount( 1, WcCoupon::people( new \WC_Coupon( $c->get_id() ) ) );
		$this->assertNotContains( 'P-COUPON', WcCoupon::people( new \WC_Coupon( $c->get_id() ) ), 'only a keyed hash is stored' );
		$this->assertSame( Messages::get( 'coupon_used' ), $this->check( $c )->get_error_message() );

		$b = $this->make_user( 'customer' ); // the same person on a second account (accepted under the "flag" policy)
		update_user_meta( $b, 'verifyblind_flag_person', 'P-COUPON' );
		Results::add( 'u:' . $b, 'uid', true, 'n-b', false );
		wp_set_current_user( $b );
		$this->assertSame( Messages::get( 'coupon_used' ), $this->check( $c )->get_error_message() );

		wp_set_current_user( $this->person( 'P-OTHER' ) );
		$this->assertTrue( $this->check( $c ) );
	}

	public function test_shop_managers_can_add_coupons_to_orders_by_hand(): void {
		$c = $this->coupon();
		$this->coupon_rule( $c );
		wp_set_current_user( $this->make_user( 'shop_manager' ) );
		$order = wc_create_order();
		$order->add_product( wc_get_product( $this->product() ), 1 );
		$order->save();
		$this->wc_orders[] = $order->get_id();
		$this->assertTrue( ( new \WC_Discounts( $order ) )->is_coupon_valid( new \WC_Coupon( $c->get_code() ) ) );
	}

	public function test_the_store_api_refuses_the_coupon_too(): void {
		$c = $this->coupon();
		$this->coupon_rule( $c );
		WC()->cart->add_to_cart( $this->product() );
		$res = $this->store_api( 'POST', 'cart/apply-coupon', array( 'code' => $c->get_code() ) );
		$this->assertGreaterThanOrEqual( 400, $res->get_status() );
		$this->assertStringContainsString( Messages::get( 'coupon_required' ), wp_json_encode( $res->get_data() ) );
		$this->assertNotContains( $c->get_code(), WC()->cart->get_applied_coupons() );
	}
}
