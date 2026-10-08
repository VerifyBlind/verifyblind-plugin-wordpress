<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Identities;
use VerifyBlind\Messages;
use VerifyBlind\Nonces;
use VerifyBlind\Owner;
use VerifyBlind\PendingIdentities;
use VerifyBlind\Placements\Registration;
use VerifyBlind\Placements\WcCheckout;
use VerifyBlind\Results;
use VerifyBlind\VerificationService;

/** A sign-up (registration) rule and the account a guest creates at checkout, classic and block. */
final class RegistrationCheckoutTest extends WcTestCase {
	/** @var int */
	private $pid;

	protected function setUp(): void {
		parent::setUp();
		$this->pid = $this->product();
	}

	private function signup_rule(): array {
		return $this->rule( array( 'placement' => Registration::KEY, 'age' => '18+', 'unique' => true ) );
	}

	/** The current guest runs the sign-up one-person check through the real service: the person is held for them. */
	private function guest_holds( array $rule, string $person ): string {
		$guest  = (string) Owner::current( true );
		$signer = new Signer();
		$nonce  = 'rc-' . wp_rand();
		Nonces::put( $nonce, $rule['id'], $rule['age'], $rule['unique'], $guest, 960 );
		$v   = array( 'user_id' => $person, 'nsbd_id' => 'N-' . $person, 'doc_id' => 'D-' . $person, 'age' => true, 'age_condition' => $rule['age'] );
		$out = ( new VerificationService( $signer ) )->verify( $signer->token( array( 'nonce' => $nonce, 'validations' => $v ) ), $guest, false );
		$this->assertSame( 'ok', $out['code'] );
		$this->assertSame( $person, PendingIdentities::find( $guest )['vb_user_id'] );
		return $guest;
	}

	private function classic_data( string $email ): array {
		return array( 'payment_method' => 'cod', 'billing_email' => $email, 'billing_first_name' => 'Test', 'billing_last_name' => 'Buyer', 'billing_country' => 'TR', 'createaccount' => 1 );
	}

	/** Classic checkout as WooCommerce runs it: validation, then process_customer(). @return string the exception message, '' when the account was created */
	private function classic_sign_up( string $email ): string {
		$data   = $this->classic_data( $email );
		$errors = new \WP_Error();
		do_action( 'woocommerce_after_checkout_validation', $data, $errors );
		$this->assertFalse( $errors->has_errors(), (string) wp_json_encode( $errors->get_error_messages() ) );
		try {
			$this->classic_process_customer( $data );
		} catch ( \Exception $e ) {
			return $e->getMessage();
		}
		return '';
	}

	private function assert_bound( string $email, string $person, string $guest ): void {
		$user = get_user_by( 'email', $email );
		$this->assertInstanceOf( \WP_User::class, $user, 'the account was created' );
		$identity = Identities::find_by_vb_user_id( $person );
		$this->assertNotNull( $identity, 'the held person was bound' );
		$this->assertSame( (int) $user->ID, (int) $identity['wp_user_id'] );
		$passed = Results::passed_conditions( 'u:' . $user->ID, 0, false );
		sort( $passed );
		$this->assertSame( array( '18+', 'uid' ), $passed );
		$this->assertNull( PendingIdentities::find( $guest ), 'the hold is used up' );
	}

	public function test_classic_checkout_account_with_a_fresh_hold_is_created_and_bound(): void {
		$this->allow_checkout_sign_up();
		$guest = $this->guest_holds( $this->signup_rule(), 'P-CC' );
		WC()->cart->add_to_cart( $this->pid );
		$email = 'vbrcc' . wp_rand() . '@example.com';
		$this->assertSame( '', $this->classic_sign_up( $email ) );
		$this->assert_bound( $email, 'P-CC', $guest );
		$this->assertSame( (int) get_user_by( 'email', $email )->ID, get_current_user_id(), 'WooCommerce logged the new customer in' );
	}

	public function test_classic_checkout_account_without_a_hold_is_refused_pointing_to_the_box_here(): void {
		$this->allow_checkout_sign_up();
		$this->signup_rule();
		WC()->cart->add_to_cart( $this->pid );
		$email = 'vbrcn' . wp_rand() . '@example.com';
		$this->assertSame( Messages::get( 'registration_at_checkout' ), $this->classic_sign_up( $email ) );
		$this->assertFalse( get_user_by( 'email', $email ), 'no account was created' );
	}

	public function test_block_checkout_account_with_a_fresh_hold_is_created_and_bound(): void {
		$this->enable_cod();
		$this->allow_checkout_sign_up();
		$guest = $this->guest_holds( $this->signup_rule(), 'P-BC' );
		WC()->cart->add_to_cart( $this->pid );
		$email = 'vbrbc' . wp_rand() . '@example.com';
		$res   = $this->store_api_order_creating_account( $email );
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assert_bound( $email, 'P-BC', $guest );
		$this->assertSame( (int) get_user_by( 'email', $email )->ID, wc_get_order( $res->get_data()['order_id'] )->get_customer_id() );
	}

	public function test_block_checkout_account_without_a_hold_is_refused_pointing_to_the_box_here(): void {
		$this->enable_cod();
		$this->allow_checkout_sign_up();
		$this->signup_rule();
		WC()->cart->add_to_cart( $this->pid );
		$email = 'vbrbn' . wp_rand() . '@example.com';
		$res   = $this->store_api_order_creating_account( $email );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'verifyblind_required', $res->get_data()['code'] );
		$this->assertSame( esc_html( Messages::get( 'registration_at_checkout' ) ), $res->get_data()['message'] );
		$this->assertFalse( get_user_by( 'email', $email ), 'no account was created' );
	}

	public function test_the_sign_up_message_elsewhere_still_points_to_the_registration_form(): void {
		$this->signup_rule();
		$refused = wc_create_new_customer( 'vbrmy' . wp_rand() . '@example.com', 'vbrmy_' . strtolower( wp_generate_password( 8, false ) ), wp_generate_password() );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( Messages::get( 'registration_required' ), $refused->get_error_message() );
	}

	public function test_the_checkout_shows_the_sign_up_box_while_sign_up_is_possible_there(): void {
		$rule = $this->signup_rule();
		WC()->cart->add_to_cart( $this->pid );
		$block = '<div class="wc-block-checkout"></div>';

		$this->set_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );
		$this->set_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$this->assertSame( $block, WcCheckout::render_checkout_block( $block, array( 'blockName' => 'woocommerce/checkout' ) ), 'no sign-up at checkout: no box' );

		$this->set_option( 'woocommerce_enable_signup_and_login_from_checkout', 'yes' );
		ob_start();
		do_action( 'woocommerce_after_checkout_registration_form', WC()->checkout() );
		$classic = (string) ob_get_clean();
		$this->assertStringContainsString( 'verifyblind-start', $classic );
		$this->assertStringContainsString( 'data-reload="0"', $classic, 'inside the checkout form: no reload' );
		$html = WcCheckout::render_checkout_block( $block, array( 'blockName' => 'woocommerce/checkout' ) );
		$this->assertStringContainsString( 'verifyblind-start', $html );
		$this->assertStringContainsString( 'data-reload="0"', $html );
		$this->assertStringEndsWith( $block, $html );

		// Verified (person held): no box.
		$this->guest_holds( $rule, 'P-BOX' );
		$this->assertSame( $block, WcCheckout::render_checkout_block( $block, array( 'blockName' => 'woocommerce/checkout' ) ) );

		// Logged-in customers create no account at checkout.
		unset( $_COOKIE[ Owner::COOKIE ] );
		wp_set_current_user( $this->make_user( 'customer' ) );
		$this->assertSame( $block, WcCheckout::render_checkout_block( $block, array( 'blockName' => 'woocommerce/checkout' ) ) );
	}
}
