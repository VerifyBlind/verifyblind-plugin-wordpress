<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Identities;
use VerifyBlind\Messages;
use VerifyBlind\Placements\WcCoupon;
use VerifyBlind\Results;
use VerifyBlind\Rules;

final class WcCouponTest extends WcTestCase {
	/** @var bool */
	private $had_key;

	protected function setUp(): void {
		parent::setUp();
		$this->had_key = false !== get_option( WcCoupon::KEY_OPTION, false );
	}

	protected function tearDown(): void {
		if ( function_exists( 'WC' ) && WC()->session ) {
			foreach ( array( 'order_awaiting_payment', 'store_api_draft_order', WcCoupon::SESSION ) as $k ) {
				WC()->session->set( $k, null );
			}
		}
		parent::tearDown();
		if ( ! $this->had_key ) {
			delete_option( WcCoupon::KEY_OPTION );
		}
	}

	/** @return true|\WP_Error */
	private function check( \WC_Coupon $coupon ) {
		return ( new \WC_Discounts( WC()->cart ) )->is_coupon_valid( new \WC_Coupon( $coupon->get_code() ) );
	}

	/** @param true|\WP_Error $r */
	private function message( $r ): string {
		return $r instanceof \WP_Error ? $r->get_error_message() : 'valid';
	}

	private function coupon_rule( \WC_Coupon $c, array $o = array() ): array {
		return $this->rule( array_merge( array( 'placement' => WcCoupon::KEY, 'age' => '18+', 'targets' => array( 'post_ids' => array( $c->get_id() ) ) ), $o ) );
	}

	private function one_person_coupon(): \WC_Coupon {
		$c = $this->coupon();
		$this->coupon_rule( $c, array( 'age' => '', 'unique' => true ) );
		return $c;
	}

	private function person( string $code ): int {
		$uid = $this->make_user( 'customer' );
		Identities::insert( $code, $uid, null, null, 'n-' . $code );
		Results::add( 'u:' . $uid, 'uid', true, 'n-' . $code, false );
		return $uid;
	}

	private function people( \WC_Coupon $c ): array {
		return WcCoupon::people( new \WC_Coupon( $c->get_id() ) );
	}

	/** An order of $uid using $c, created like checkout does (recorded at creation), then moved to $status. */
	private function order( \WC_Coupon $c, int $uid, string $status = 'pending' ): \WC_Order {
		$order = wc_create_order( array( 'customer_id' => $uid ) );
		$item  = new \WC_Order_Item_Coupon();
		$item->set_code( $c->get_code() );
		$order->add_item( $item );
		$order->save();
		$this->wc_orders[] = $order->get_id();
		do_action( 'woocommerce_checkout_order_created', $order );
		if ( 'pending' !== $status ) {
			$order->update_status( $status );
		}
		return $order;
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
		$c = $this->one_person_coupon();
		WC()->cart->add_to_cart( $this->product() );
		$this->assertSame( Messages::get( 'coupon_login' ), $this->message( $this->check( $c ) ) );
		ob_start();
		WcCoupon::print_box();
		$this->assertStringContainsString( 'verifyblind-box--login', (string) ob_get_clean(), 'a guest sees the log-in box' );

		$a = $this->person( 'P-COUPON' );
		wp_set_current_user( $a );
		$this->assertTrue( $this->check( $c ) );

		$this->order( $c, $a, 'processing' );
		$this->assertCount( 1, $this->people( $c ) );
		$this->assertNotContains( 'P-COUPON', $this->people( $c ), 'only a keyed hash is stored' );
		$this->assertSame( Messages::get( 'coupon_used' ), $this->message( $this->check( $c ) ) );

		$b = $this->make_user( 'customer' ); // the same person on a second account (accepted under the "flag" policy)
		update_user_meta( $b, 'verifyblind_flag_person', 'P-COUPON' );
		Results::add( 'u:' . $b, 'uid', true, 'n-b', false );
		wp_set_current_user( $b );
		$this->assertSame( Messages::get( 'coupon_used' ), $this->message( $this->check( $c ) ) );

		wp_set_current_user( $this->person( 'P-OTHER' ) );
		$this->assertTrue( $this->check( $c ) );
	}

	public function test_the_person_hash_uses_a_dedicated_key(): void {
		$uid = $this->person( 'P-KEY' );
		$key = WcCoupon::person_key( $uid );
		$opt = get_option( WcCoupon::KEY_OPTION );
		$this->assertIsString( $opt );
		$this->assertSame( 64, strlen( $opt ) );
		$this->assertSame( hash_hmac( 'sha256', 'P-KEY', $opt ), $key );
		global $wpdb;
		$this->assertContains( $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", WcCoupon::KEY_OPTION ) ), array( 'off', 'no' ) );
		$this->assertSame( $key, WcCoupon::person_key( $uid ), 'created once' );
	}

	public function test_a_pending_order_holds_the_coupon_from_creation_and_cancelling_releases_it(): void {
		$c = $this->one_person_coupon();
		$a = $this->person( 'P-PENDING' );
		wp_set_current_user( $a );
		WC()->cart->add_to_cart( $this->product() );
		$order = $this->order( $c, $a );
		$this->assertTrue( $order->has_status( 'pending' ) );
		$this->assertSame( array( WcCoupon::person_key( $a ) ), $this->people( $c ) );
		$this->assertSame( array( array( $c->get_id(), WcCoupon::person_key( $a ) ) ), wc_get_order( $order->get_id() )->get_meta( WcCoupon::ORDER_META ) );
		$this->assertSame( Messages::get( 'coupon_used' ), $this->message( $this->check( $c ) ) );

		$order->update_status( 'cancelled' );
		$this->assertSame( array(), $this->people( $c ) );
		$this->assertTrue( $this->check( $c ) );
	}

	public function test_an_on_hold_order_holds_the_coupon_and_a_failed_one_releases_it(): void {
		$c = $this->one_person_coupon();
		$a = $this->person( 'P-HOLD' );
		wp_set_current_user( $a );
		WC()->cart->add_to_cart( $this->product() );
		$order = $this->order( $c, $a, 'on-hold' );
		$this->assertSame( Messages::get( 'coupon_used' ), $this->message( $this->check( $c ) ) );
		$order->update_status( 'failed' );
		$this->assertTrue( $this->check( $c ) );
	}

	public function test_completing_an_order_records_the_use(): void {
		$c     = $this->one_person_coupon();
		$a     = $this->person( 'P-DONE' );
		$order = wc_create_order( array( 'customer_id' => $a ) ); // not created through checkout: nothing recorded yet
		$item  = new \WC_Order_Item_Coupon();
		$item->set_code( $c->get_code() );
		$order->add_item( $item );
		$order->save();
		$this->wc_orders[] = $order->get_id();
		$this->assertSame( array(), $this->people( $c ) );
		$order->update_status( 'completed' );
		$this->assertSame( array( WcCoupon::person_key( $a ) ), $this->people( $c ) );
		$order->update_status( 'completed' ); // idempotent
		$this->assertCount( 1, $this->people( $c ) );
	}

	public function test_a_refund_keeps_the_use(): void {
		$c = $this->one_person_coupon();
		$a = $this->person( 'P-REFUND' );
		wp_set_current_user( $a );
		WC()->cart->add_to_cart( $this->product() );
		$order = $this->order( $c, $a, 'processing' );
		$order->update_status( 'refunded' );
		$this->assertCount( 1, $this->people( $c ) );
		$this->assertSame( Messages::get( 'coupon_used' ), $this->message( $this->check( $c ) ) );
	}

	public function test_a_dead_order_does_not_release_while_another_order_of_the_person_holds_the_coupon(): void {
		$c = $this->one_person_coupon();
		$a = $this->person( 'P-TWO' );
		$b = $this->make_user( 'customer' ); // same person, second account
		update_user_meta( $b, 'verifyblind_flag_person', 'P-TWO' );
		$first  = $this->order( $c, $a, 'processing' );
		$second = $this->order( $c, $b, 'on-hold' ); // e.g. added by hand in wp-admin
		$this->assertCount( 1, $this->people( $c ), 'one row per person' );

		$first->update_status( 'cancelled' );
		$this->assertCount( 1, $this->people( $c ), 'the second order still holds it' );
		$second->update_status( 'cancelled' );
		$this->assertSame( array(), $this->people( $c ) );

		$refunded = $this->order( $c, $a, 'refunded' );
		$third    = $this->order( $c, $b, 'processing' );
		$third->update_status( 'failed' );
		$this->assertCount( 1, $this->people( $c ), 'a refunded order keeps holding it' );
		$this->assertTrue( $refunded->has_status( 'refunded' ) );
	}

	public function test_trashing_or_deleting_an_order_releases_the_use(): void {
		$c     = $this->one_person_coupon();
		$a     = $this->person( 'P-TRASH' );
		$order = $this->order( $c, $a, 'completed' );
		$this->assertCount( 1, $this->people( $c ) );
		$order->delete( false );
		$this->assertSame( array(), $this->people( $c ) );

		$order = $this->order( $c, $a, 'processing' );
		$this->assertCount( 1, $this->people( $c ) );
		$order->delete( true );
		$this->assertSame( array(), $this->people( $c ) );
	}

	public function test_an_account_without_a_person_code_is_refused(): void {
		$c   = $this->one_person_coupon();
		$uid = $this->make_user( 'customer' );
		// Passed the one-person check (e.g. with a demo card in test mode) but no identity is bound to the account.
		Results::add( 'u:' . $uid, 'uid', true, 'n-demo', false );
		wp_set_current_user( $uid );
		WC()->cart->add_to_cart( $this->product() );
		$this->assertSame( Messages::get( 'coupon_required' ), $this->message( $this->check( $c ) ) );
		$this->order( $c, $uid, 'processing' );
		$this->assertSame( array(), $this->people( $c ), 'nothing to record' );
	}

	public function test_the_order_a_checkout_resumes_does_not_count_against_the_person(): void {
		$c = $this->one_person_coupon();
		$a = $this->person( 'P-RETRY' );
		wp_set_current_user( $a );
		WC()->cart->add_to_cart( $this->product() );
		$this->assertTrue( WC()->cart->apply_coupon( $c->get_code() ) );
		WC()->cart->calculate_totals();
		$id = WC()->checkout()->create_order( array( 'payment_method' => 'cod', 'billing_email' => 'buyer@example.com', 'billing_first_name' => 'Test', 'billing_last_name' => 'Buyer', 'billing_country' => 'TR' ) );
		$this->assertIsInt( $id );
		$this->wc_orders[] = $id;
		$this->assertCount( 1, $this->people( $c ), 'recorded when the classic checkout created the order' );
		$this->assertSame( Messages::get( 'coupon_used' ), $this->message( $this->check( $c ) ), 'a second order' );

		// The payment did not go through: the customer submits the same cart again and WooCommerce resumes that order.
		WC()->session->set( 'order_awaiting_payment', $id );
		$this->assertTrue( $this->check( $c ) );

		// A changed cart makes a new order, which would be a second use.
		WC()->cart->add_to_cart( $this->product() );
		WC()->cart->calculate_totals();
		$this->assertSame( Messages::get( 'coupon_used' ), $this->message( $this->check( $c ) ) );
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
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'woocommerce_rest_cart_coupon_error', $res->get_data()['code'] );
		$this->assertStringContainsString( Messages::get( 'coupon_required' ), $res->get_data()['message'] );
		$this->assertNotContains( $c->get_code(), WC()->cart->get_applied_coupons() );
	}

	public function test_a_store_api_checkout_records_the_use_and_refuses_a_second_one(): void {
		$this->enable_cod();
		$c = $this->one_person_coupon();
		$a = $this->person( 'P-BLOCK' );
		wp_set_current_user( $a );
		WC()->cart->add_to_cart( $this->product() );
		$this->assertTrue( WC()->cart->apply_coupon( $c->get_code() ) );

		$placed = $this->place_store_api_order();
		$this->assertSame( 200, $placed->get_status(), (string) wp_json_encode( $placed->get_data() ) );
		$order = wc_get_order( $placed->get_data()['order_id'] );
		$this->assertContains( $c->get_code(), $order->get_coupon_codes() );
		$this->assertSame( array( WcCoupon::person_key( $a ) ), $this->people( $c ) );
		$this->assertSame( array( array( $c->get_id(), WcCoupon::person_key( $a ) ) ), $order->get_meta( WcCoupon::ORDER_META ) );

		// The coupon sits in the cart from before another order of the person took it; placing the order is refused.
		$order->update_status( 'cancelled' );
		WC()->session->set( 'store_api_draft_order', null );
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $this->product() );
		$this->assertTrue( WC()->cart->apply_coupon( $c->get_code() ) );
		$this->order( $c, $a, 'on-hold' );
		$refused = $this->place_store_api_order();
		$draft   = (int) WC()->session->get( 'store_api_draft_order' );
		if ( $draft > 0 ) {
			$this->wc_orders[] = $draft;
		}
		$this->assertSame( 409, $refused->get_status(), (string) wp_json_encode( $refused->get_data() ) );
		$this->assertSame( 'woocommerce_rest_cart_coupon_error', $refused->get_data()['code'] );
		$this->assertStringContainsString( Messages::get( 'coupon_used' ), $refused->get_data()['message'], (string) wp_json_encode( $refused->get_data() ) );
		$this->assertArrayNotHasKey( 'order_id', (array) $refused->get_data() );
		$this->assertNotContains( $c->get_code(), WC()->cart->get_applied_coupons() );
	}
}
