<?php
namespace VerifyBlind\Tests\Integration;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use VerifyBlind\Messages;
use VerifyBlind\Placements\WcCheckout;
use VerifyBlind\Results;
use VerifyBlind\Rules;

final class WcCheckoutTest extends WcTestCase {
	/** @var int */
	private $cat;
	/** @var int */
	private $pid;

	protected function setUp(): void {
		parent::setUp();
		$this->cat = $this->category( 'VB 18+ drinks' );
		$this->pid = $this->product( array( $this->cat ) );
	}

	private function checkout_rule( array $o = array() ): array {
		return $this->rule( array_merge( array( 'placement' => WcCheckout::KEY, 'age' => '18+', 'targets' => array( 'term_ids' => array( $this->cat ) ) ), $o ) );
	}

	private function classic_errors(): \WP_Error {
		$errors = new \WP_Error();
		do_action( 'woocommerce_after_checkout_validation', array(), $errors );
		return $errors;
	}

	private function classic_data(): array {
		return array( 'payment_method' => 'cod', 'billing_email' => 'buyer@example.com', 'billing_first_name' => 'Test', 'billing_last_name' => 'Buyer', 'billing_country' => 'TR' );
	}

	public function test_is_a_placement(): void {
		$this->assertArrayHasKey( WcCheckout::KEY, Rules::placements() );
	}

	public function test_carts_without_targeted_products_are_not_checked(): void {
		$this->checkout_rule();
		WC()->cart->add_to_cart( $this->product() );
		$this->assertFalse( $this->classic_errors()->has_errors() );
		$this->assertNull( WcCheckout::blocking( WC()->cart ) );
	}

	public function test_classic_checkout_is_refused_until_verified(): void {
		$this->checkout_rule();
		WC()->cart->add_to_cart( $this->pid );
		$this->assertSame( array( Messages::get( 'checkout_required' ) ), $this->classic_errors()->get_error_messages( 'verifyblind_required' ) );
		ob_start();
		WcCheckout::print_box();
		$box = (string) ob_get_clean();
		$this->assertStringContainsString( 'verifyblind-start', $box );
		$this->assertStringContainsString( 'data-reload="1"', $box );
		$this->verify_guest( '18+' );
		$this->assertFalse( $this->classic_errors()->has_errors() );
		ob_start();
		WcCheckout::print_box();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_products_in_a_child_category_are_covered(): void {
		$child = $this->category( 'VB child', $this->cat );
		$this->checkout_rule();
		WC()->cart->add_to_cart( $this->product( array( $child ) ) );
		$this->assertNotNull( WcCheckout::blocking( WC()->cart ) );
	}

	public function test_block_checkout_shows_the_box_and_the_store_api_refuses_then_records(): void {
		$this->enable_cod();
		$rule = $this->checkout_rule();
		WC()->cart->add_to_cart( $this->pid );
		$this->assertStringContainsString( 'verifyblind-box', WcCheckout::render_checkout_block( '<div class="wc-block-checkout"></div>', array( 'blockName' => 'woocommerce/checkout' ) ) );
		$this->assertSame( '<p>x</p>', WcCheckout::render_checkout_block( '<p>x</p>', array( 'blockName' => 'core/paragraph' ) ) );

		$refused = $this->place_store_api_order();
		$this->assertSame( 409, $refused->get_status() );
		$this->assertSame( 'verifyblind_required', $refused->get_data()['code'] );

		$this->verify_guest( '18+' );
		$placed = $this->place_store_api_order();
		$this->assertSame( 200, $placed->get_status(), (string) wp_json_encode( $placed->get_data() ) );
		$order   = wc_get_order( $placed->get_data()['order_id'] );
		$checked = $order->get_meta( WcCheckout::META );
		$this->assertSame( $rule['id'], $checked[0]['rule'] );
		$this->assertSame( '18+', $checked[0]['condition'] );
		$this->assertFalse( $checked[0]['one_person'] );
		$this->assertStringContainsString( '18+', WcCheckout::note_text( $checked ) );
		$this->assertContains( WcCheckout::note_text( $checked ), wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
	}

	public function test_classic_order_creation_is_the_last_line(): void {
		$this->checkout_rule();
		WC()->cart->add_to_cart( $this->pid );
		$refused = WC()->checkout()->create_order( $this->classic_data() );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( Messages::get( 'checkout_required' ), $refused->get_error_message() );

		$this->verify_guest( '21+' ); // 21+ implies 18+
		$id = WC()->checkout()->create_order( $this->classic_data() );
		$this->assertIsInt( $id );
		$this->wc_orders[] = $id;
		$checked           = wc_get_order( $id )->get_meta( WcCheckout::META );
		$this->assertSame( '18+', $checked[0]['condition'] );
		$this->assertContains( WcCheckout::note_text( $checked ), wp_list_pluck( wc_get_order_notes( array( 'order_id' => $id ) ), 'content' ) );
	}

	public function test_store_api_order_update_is_the_last_line_for_block_checkout(): void {
		$this->checkout_rule();
		$order = wc_create_order();
		$order->add_product( wc_get_product( $this->pid ), 1 );
		$order->save();
		$this->wc_orders[] = $order->get_id();
		// Draft updates (PUT) are not refused: only placing the order (POST) is.
		WcCheckout::store_api_order( $order, new \WP_REST_Request( 'PUT', '/wc/store/v1/checkout' ) );
		try {
			WcCheckout::store_api_order( $order, new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' ) );
			$this->fail( 'placing the order must be refused' );
		} catch ( RouteException $e ) {
			$this->assertSame( 'verifyblind_required', $e->getErrorCode() );
			$this->assertSame( 403, $e->getCode() );
		}
		$this->verify_guest( '18+' );
		WcCheckout::store_api_order( $order, new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' ) );
		$this->assertNotEmpty( wc_get_order( $order->get_id() )->get_meta( WcCheckout::META ) );
	}

	public function test_require_account_mode_sends_guests_to_log_in(): void {
		$this->checkout_rule( array( 'guest_mode' => 'require_account' ) );
		WC()->cart->add_to_cart( $this->pid );
		$this->verify_guest( '18+' );
		$this->assertSame( array( Messages::get( 'account_required' ) ), $this->classic_errors()->get_error_messages( 'verifyblind_required' ) );
		ob_start();
		WcCheckout::print_box();
		$this->assertStringContainsString( 'verifyblind-box--login', (string) ob_get_clean() );
		$uid = $this->make_user( 'customer' );
		wp_set_current_user( $uid );
		Results::add( 'u:' . $uid, '18+', true, 'acct', false );
		$this->assertFalse( $this->classic_errors()->has_errors() );
	}

	public function test_one_person_rules_need_an_account_and_are_noted(): void {
		$rule = $this->checkout_rule( array( 'age' => '', 'unique' => true ) );
		WC()->cart->add_to_cart( $this->pid );
		$this->assertSame( array( Messages::get( 'account_required' ) ), $this->classic_errors()->get_error_messages( 'verifyblind_required' ) );
		$this->assertStringContainsString( WcCheckout::note_text( array( array( 'rule' => $rule['id'], 'condition' => '', 'one_person' => true ) ) ), WcCheckout::note_text( array( array( 'rule' => $rule['id'], 'condition' => '18+', 'one_person' => true ) ) ) );
	}
}
