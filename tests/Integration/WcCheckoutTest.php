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

	/** WooCommerce > Accounts: guests may create an account at checkout. No auth cookies from the command line. */
	private function allow_checkout_sign_up(): void {
		$this->set_option( 'woocommerce_enable_signup_and_login_from_checkout', 'yes' );
		add_filter( 'send_auth_cookies', '__return_false' );
	}

	/** Block checkout placing the order and creating the customer's account in the same request. */
	private function store_api_order_creating_account( string $email ): \WP_REST_Response {
		$address = array_merge( $this->address(), array( 'email' => $email ) );
		$res     = $this->store_api( 'POST', 'checkout', array( 'billing_address' => $address, 'shipping_address' => $address, 'payment_method' => 'cod', 'create_account' => true, 'customer_password' => wp_generate_password() ) );
		$data    = $res->get_data();
		if ( is_array( $data ) && isset( $data['order_id'] ) ) {
			$this->wc_orders[] = (int) $data['order_id'];
		}
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$this->created_users[] = (int) $user->ID;
		}
		return $res;
	}

	/** Classic checkout after validation: WooCommerce's own process_customer() creates the account and logs it in. */
	private function classic_process_customer( array $data ): void {
		$m = new \ReflectionMethod( WC()->checkout(), 'process_customer' );
		$m->setAccessible( true );
		$m->invoke( WC()->checkout(), $data );
		$user = get_user_by( 'email', $data['billing_email'] );
		if ( $user ) {
			$this->created_users[] = (int) $user->ID;
		}
	}

	public function test_require_account_lets_a_freshly_verified_guest_create_the_account_at_block_checkout(): void {
		$this->enable_cod();
		$this->allow_checkout_sign_up();
		$rule = $this->checkout_rule( array( 'guest_mode' => 'require_account' ) );
		WC()->cart->add_to_cart( $this->pid );
		$this->verify_guest( '18+' );

		$as_guest = $this->place_store_api_order();
		$this->assertSame( 409, $as_guest->get_status(), 'no account, none being created: refused' );
		$this->assertSame( 'verifyblind_required', $as_guest->get_data()['code'] );

		$email = 'vbco' . wp_rand() . '@example.com';
		$res   = $this->store_api_order_creating_account( $email );
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$user = get_user_by( 'email', $email );
		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertSame( $user->ID, get_current_user_id(), 'WooCommerce logged the new customer in' );
		$this->assertContains( '18+', Results::passed_conditions( 'u:' . $user->ID, 0, false ), 'the age result followed the guest into the new account' );
		$order   = wc_get_order( $res->get_data()['order_id'] );
		$checked = $order->get_meta( WcCheckout::META );
		$this->assertSame( $user->ID, $order->get_customer_id() );
		$this->assertSame( $rule['id'], $checked[0]['rule'] );
		$this->assertContains( WcCheckout::note_text( $checked ), wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
	}

	public function test_require_account_lets_a_freshly_verified_guest_create_the_account_at_classic_checkout(): void {
		$this->allow_checkout_sign_up();
		$this->checkout_rule( array( 'guest_mode' => 'require_account' ) );
		WC()->cart->add_to_cart( $this->pid );
		$this->verify_guest( '18+' );
		$data   = array_merge( $this->classic_data(), array( 'createaccount' => 1, 'billing_email' => 'vbcc' . wp_rand() . '@example.com' ) );
		$errors = new \WP_Error();
		do_action( 'woocommerce_after_checkout_validation', $data, $errors );
		$this->assertFalse( $errors->has_errors(), (string) wp_json_encode( $errors->get_error_messages() ) );

		$this->classic_process_customer( $data );
		$uid = get_current_user_id();
		$this->assertGreaterThan( 0, $uid, 'WooCommerce created the account and logged it in' );
		$this->assertContains( '18+', Results::passed_conditions( 'u:' . $uid, 0, false ), 'the age result followed the guest into the new account' );
		$id = WC()->checkout()->create_order( $data );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		$this->wc_orders[] = $id;
		$this->assertNotEmpty( wc_get_order( $id )->get_meta( WcCheckout::META ) );
	}

	public function test_a_stale_guest_result_does_not_carry_an_account_sign_up_at_checkout(): void {
		global $wpdb;
		$this->enable_cod();
		$this->allow_checkout_sign_up();
		$this->checkout_rule( array( 'guest_mode' => 'require_account' ) );
		WC()->cart->add_to_cart( $this->pid );
		$guest = $this->verify_guest( '18+' );
		// Older than the carry-over window: it would not follow into the new account.
		$wpdb->update( \VerifyBlind\Schema::table( 'results' ), array( 'verified_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * MINUTE_IN_SECONDS ) ), array( 'owner' => $guest ) );
		$errors = new \WP_Error();
		do_action( 'woocommerce_after_checkout_validation', array_merge( $this->classic_data(), array( 'createaccount' => 1 ) ), $errors );
		$this->assertSame( array( Messages::get( 'checkout_required' ) ), $errors->get_error_messages( 'verifyblind_required' ) );
		$email = 'vbst' . wp_rand() . '@example.com';
		$this->assertSame( 409, $this->store_api_order_creating_account( $email )->get_status() );
		$this->assertFalse( get_user_by( 'email', $email ), 'no account was created' );
	}

	public function test_with_checkout_sign_up_guests_can_verify_on_the_checkout_for_require_account(): void {
		$this->allow_checkout_sign_up();
		$this->checkout_rule( array( 'guest_mode' => 'require_account' ) );
		WC()->cart->add_to_cart( $this->pid );
		ob_start();
		WcCheckout::print_box();
		$this->assertStringContainsString( 'verifyblind-start', (string) ob_get_clean(), 'verify here, then create the account in this checkout' );
		$this->verify_guest( '18+' );
		ob_start();
		WcCheckout::print_box();
		$this->assertSame( '', ob_get_clean() );
		$this->assertSame( array( Messages::get( 'account_required' ) ), $this->classic_errors()->get_error_messages( 'verifyblind_required' ), 'an order without an account is still refused' );
	}

	public function test_one_person_rules_still_need_an_existing_account_when_signing_up_at_checkout(): void {
		$this->enable_cod();
		$this->allow_checkout_sign_up();
		$this->checkout_rule( array( 'age' => '', 'unique' => true ) );
		WC()->cart->add_to_cart( $this->pid );
		$errors = new \WP_Error();
		do_action( 'woocommerce_after_checkout_validation', array_merge( $this->classic_data(), array( 'createaccount' => 1 ) ), $errors );
		$this->assertSame( array( Messages::get( 'account_required' ) ), $errors->get_error_messages( 'verifyblind_required' ) );
		$email = 'vbop' . wp_rand() . '@example.com';
		$res   = $this->store_api_order_creating_account( $email );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'verifyblind_required', $res->get_data()['code'] );
		$this->assertFalse( get_user_by( 'email', $email ) );
	}

	public function test_the_login_box_sends_shop_customers_to_my_account_to_sign_up(): void {
		$rule = $this->checkout_rule( array( 'age' => '', 'unique' => true ) );
		$this->set_option( 'woocommerce_enable_myaccount_registration', 'yes' );
		$box = WcCheckout::box_html( $rule );
		$this->assertStringContainsString( 'verifyblind-box--login', $box );
		$this->assertStringContainsString( 'class="verifyblind-register" href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '"', $box );
		$this->assertStringNotContainsString( 'action=register', $box );
		$this->set_option( 'woocommerce_enable_myaccount_registration', 'no' );
		$this->assertStringNotContainsString( 'verifyblind-register', WcCheckout::box_html( $rule ), 'no sign-up link when the shop has no sign-up' );
	}

	public function test_one_person_rules_need_an_account_and_are_noted(): void {
		$rule = $this->checkout_rule( array( 'age' => '', 'unique' => true ) );
		WC()->cart->add_to_cart( $this->pid );
		$this->assertSame( array( Messages::get( 'account_required' ) ), $this->classic_errors()->get_error_messages( 'verifyblind_required' ) );
		$this->assertStringContainsString( WcCheckout::note_text( array( array( 'rule' => $rule['id'], 'condition' => '', 'one_person' => true ) ) ), WcCheckout::note_text( array( array( 'rule' => $rule['id'], 'condition' => '18+', 'one_person' => true ) ) ) );
	}
}
