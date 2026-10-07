<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Gate;
use VerifyBlind\Messages;
use VerifyBlind\Placements\WcCheckout;
use VerifyBlind\Placements\WcProduct;
use VerifyBlind\Placements\WcSite;
use VerifyBlind\Rules;

final class WcSiteTest extends WcTestCase {
	protected function tearDown(): void {
		unset( $_GET['wc-ajax'] );
		set_query_var( 'sitemap', '' );
		parent::tearDown();
	}

	public function test_is_a_placement(): void {
		$this->assertArrayHasKey( WcSite::KEY, Rules::placements() );
	}

	public function test_guests_meet_the_gate_until_verified(): void {
		$rule = $this->rule( array( 'placement' => WcSite::KEY, 'age' => '18+' ) );
		$this->query_post( $this->product() );
		$this->assertSame( $rule['id'], WcSite::blocking()['id'] );
		$this->verify_guest( '18+' );
		$this->assertNull( WcSite::blocking() );
	}

	public function test_exempt_requests_pages_and_people(): void {
		$this->rule( array( 'placement' => WcSite::KEY, 'age' => '18+' ) );
		$this->query_post( $this->product() );
		$this->assertNotNull( WcSite::blocking() );

		$_GET['wc-ajax'] = 'get_refreshed_fragments';
		$this->assertNull( WcSite::blocking() );
		unset( $_GET['wc-ajax'] );

		foreach ( array( '', '0' ) as $value ) {
			$_GET['wc-ajax'] = $value;
			$this->assertNotNull( WcSite::blocking(), "wc-ajax='$value' is not an endpoint" );
		}
		unset( $_GET['wc-ajax'] );

		set_query_var( 'sitemap', '0' );
		$this->assertNotNull( WcSite::blocking(), 'sitemap=0 is not exempt' );
		set_query_var( 'sitemap', 'index' );
		$this->assertNull( WcSite::blocking() );
		set_query_var( 'sitemap', '' );

		$saved            = get_option( 'wp_page_for_privacy_policy' );
		$privacy          = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'VB privacy', 'post_status' => 'publish' ) );
		$this->wc_posts[] = $privacy;
		update_option( 'wp_page_for_privacy_policy', $privacy );
		try {
			$this->query_post( $privacy );
			$this->assertNull( WcSite::blocking(), 'the privacy policy page stays readable' );
		} finally {
			update_option( 'wp_page_for_privacy_policy', $saved );
		}

		$this->query_post( $this->product() );
		wp_set_current_user( $this->make_user( 'administrator' ) );
		$this->assertNull( WcSite::blocking() );
	}

	public function test_the_gate_page_has_the_box_and_loads_its_scripts(): void {
		$rule = $this->rule( array( 'placement' => WcSite::KEY, 'age' => '18+' ) );
		$html = WcSite::page( $rule );
		$this->assertStringContainsString( '<html', $html );
		$this->assertStringContainsString( 'verifyblind-site-gate', $html );
		$this->assertStringContainsString( 'verifyblind-start', $html );
		$this->assertStringContainsString( '</body>', $html );
		$this->assertMatchesRegularExpression( '/<meta name=.robots. content=.[^>]*noindex/', $html );
		$this->assertTrue( wp_script_is( 'verifyblind-front', 'enqueued' ) || wp_script_is( 'verifyblind-front', 'done' ) );
	}

	public function test_without_a_rule_nothing_happens(): void {
		Gate::reset_no_cache_flag();
		WcSite::maybe_gate(); // returns instead of printing a page
		$this->assertFalse( Gate::no_cache_requested() );
	}

	public function test_decision_is_separate_from_output(): void {
		$this->rule( array( 'placement' => WcSite::KEY, 'age' => '18+' ) );
		$this->query_post( $this->product() );
		$this->assertSame( 'gate', WcSite::decide() );
		$this->verify_guest( '18+' );
		$this->assertSame( 'open', WcSite::decide() );
	}

	public function test_the_store_api_cannot_add_to_cart_or_order_until_verified(): void {
		$this->enable_cod();
		$rule = $this->rule( array( 'placement' => WcSite::KEY, 'age' => '18+' ) );
		$pid  = $this->product();
		// The Store API runs the legacy add-to-cart filter first (400 with its notice); its own hook (403) is the second line.
		$add = $this->store_api( 'POST', 'cart/add-item', array( 'id' => $pid, 'quantity' => 1 ) );
		$this->assertSame( 400, $add->get_status() );
		$this->assertSame( Messages::get( 'product_required' ), $add->get_data()['message'] );
		$legacy = array( WcProduct::class, 'add_to_cart_validation' );
		remove_filter( 'woocommerce_add_to_cart_validation', $legacy, 10 );
		try {
			$add = $this->store_api( 'POST', 'cart/add-item', array( 'id' => $pid, 'quantity' => 1 ) );
		} finally {
			add_filter( 'woocommerce_add_to_cart_validation', $legacy, 10, 4 );
		}
		$this->assertSame( 403, $add->get_status() );
		$this->assertSame( 'verifyblind_required', $add->get_data()['code'] );
		$this->assertTrue( WC()->cart->is_empty() );

		WC()->cart->add_to_cart( $pid ); // in the cart some other way (an older session, a direct call)
		$order = $this->place_store_api_order();
		$this->assertSame( 409, $order->get_status() );
		$this->assertSame( 'verifyblind_required', $order->get_data()['code'] );

		$this->verify_guest( '18+' );
		$this->assertSame( 201, $this->store_api( 'POST', 'cart/add-item', array( 'id' => $pid, 'quantity' => 1 ) )->get_status() );
		$placed = $this->place_store_api_order();
		$this->assertSame( 200, $placed->get_status(), (string) wp_json_encode( $placed->get_data() ) );
		$checked = wc_get_order( $placed->get_data()['order_id'] )->get_meta( WcCheckout::META );
		$this->assertSame( $rule['id'], $checked[0]['rule'], 'the order carries the age evidence' );
	}

	public function test_classic_add_to_cart_and_checkout_are_refused_until_verified(): void {
		$this->rule( array( 'placement' => WcSite::KEY, 'age' => '18+' ) );
		$pid = $this->product();
		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', true, $pid, 1 ) );
		$this->assertContains( Messages::get( 'product_required' ), wp_list_pluck( wc_get_notices( 'error' ), 'notice' ) );
		WC()->cart->add_to_cart( $pid );
		$errors = new \WP_Error();
		do_action( 'woocommerce_after_checkout_validation', array(), $errors );
		$this->assertSame( array( Messages::get( 'checkout_required' ) ), $errors->get_error_messages( 'verifyblind_required' ) );
		$data = array( 'payment_method' => 'cod', 'billing_email' => 'buyer@example.com', 'billing_first_name' => 'Test', 'billing_last_name' => 'Buyer', 'billing_country' => 'TR' );
		$this->assertInstanceOf( \WP_Error::class, WC()->checkout()->create_order( $data ) );

		$this->verify_guest( '18+' );
		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $pid, 1 ) );
		$id = WC()->checkout()->create_order( $data );
		$this->assertIsInt( $id );
		$this->wc_orders[] = $id;
	}

	public function test_without_a_site_rule_orders_are_untouched(): void {
		$pid = $this->product();
		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $pid, 1 ) );
		WC()->cart->add_to_cart( $pid );
		$this->assertNull( WcCheckout::blocking( WC()->cart ) );
	}

	public function test_exempt_request_with_a_rule_is_still_not_cached(): void {
		$this->rule( array( 'placement' => WcSite::KEY, 'age' => '18+' ) );
		$this->query_post( $this->product() );
		$_GET['wc-ajax'] = 'get_refreshed_fragments';
		Gate::reset_no_cache_flag();
		$this->assertSame( 'exempt', WcSite::decide() );
		WcSite::maybe_gate();
		$this->assertTrue( Gate::no_cache_requested() );
	}
}
