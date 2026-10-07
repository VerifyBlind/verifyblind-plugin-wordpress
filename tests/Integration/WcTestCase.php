<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Owner;
use VerifyBlind\Results;

/** WooCommerce fixtures. Everything created here is deleted in tearDown. */
abstract class WcTestCase extends TestCase {
	/** @var int[] posts (products, coupons, pages) to delete */
	protected $wc_posts = array();
	/** @var int[] product_cat terms to delete (children first) */
	protected $wc_terms = array();
	/** @var int[] orders to delete */
	protected $wc_orders = array();
	/** @var array<string,mixed> options changed through set_option(): name => value before (false = did not exist) */
	private $options_saved = array();
	/** @var bool */
	private $cod_changed = false;
	/** @var mixed */
	private $cod_saved;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active on the test site' );
		}
		wc_load_cart();
		WC()->cart->empty_cart();
		wc_clear_notices();
		$this->forget_checkout_orders();
	}

	/** The session's resumable orders: a later test must not resume (or reload from the cache) an order deleted by an earlier one. */
	private function forget_checkout_orders(): void {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'store_api_draft_order', null );
			WC()->session->set( 'order_awaiting_payment', null );
		}
		// The Store API checkout route object keeps its order between requests (one request per PHP process on a
		// real site; many in this test process): a later test would otherwise resume an earlier test's order.
		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( 1 !== preg_match( '#^/wc/store(/v\d+)?/checkout$#', $route ) ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				$object = isset( $handler['callback'][0] ) && is_object( $handler['callback'][0] ) ? $handler['callback'][0] : null;
				if ( null !== $object && property_exists( $object, 'order' ) ) {
					$p = new \ReflectionProperty( $object, 'order' );
					$p->setAccessible( true );
					$p->setValue( $object, null );
				}
			}
		}
	}

	protected function tearDown(): void {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
			wc_clear_notices();
		}
		$this->forget_checkout_orders();
		foreach ( $this->wc_orders as $id ) {
			// Deleting an order (HPOS) leaves its notes behind as orphan comments.
			foreach ( wc_get_order_notes( array( 'order_id' => $id ) ) as $note ) {
				wc_delete_order_note( $note->id );
			}
			$order = wc_get_order( $id );
			if ( $order ) {
				$order->delete( true );
			}
		}
		foreach ( $this->wc_posts as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( array_reverse( $this->wc_terms ) as $id ) {
			wp_delete_term( $id, 'product_cat' );
		}
		if ( $this->cod_changed ) {
			if ( false === $this->cod_saved ) {
				delete_option( 'woocommerce_cod_settings' );
			} else {
				update_option( 'woocommerce_cod_settings', $this->cod_saved );
			}
			WC()->payment_gateways()->init();
		}
		foreach ( $this->options_saved as $name => $value ) {
			if ( false === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value );
			}
		}
		$this->options_saved = array();
		remove_all_filters( 'send_auth_cookies' );
		parent::tearDown();
	}

	/** Changes a site option for this test only (restored in tearDown). */
	protected function set_option( string $name, $value ): void {
		if ( ! array_key_exists( $name, $this->options_saved ) ) {
			$this->options_saved[ $name ] = get_option( $name, false );
		}
		update_option( $name, $value );
	}

	protected function category( string $name, int $parent = 0 ): int {
		$t                = wp_insert_term( $name . ' ' . wp_generate_password( 4, false ), 'product_cat', array( 'parent' => $parent ) );
		$this->wc_terms[] = (int) $t['term_id'];
		return (int) $t['term_id'];
	}

	protected function product( array $cat_ids = array(), string $description = 'SECRET-DESC', string $short = 'SECRET-SHORT' ): int {
		$p = new \WC_Product_Simple();
		$p->set_name( 'VB test product' );
		$p->set_status( 'publish' );
		$p->set_regular_price( '10' );
		$p->set_virtual( true );
		$p->set_description( $description );
		$p->set_short_description( $short );
		$p->set_category_ids( $cat_ids );
		$id               = $p->save();
		$this->wc_posts[] = $id;
		return $id;
	}

	protected function coupon( string $amount = '1' ): \WC_Coupon {
		$c = new \WC_Coupon();
		$c->set_code( 'vbtest' . strtolower( wp_generate_password( 6, false ) ) );
		$c->set_amount( $amount );
		$c->save();
		$this->wc_posts[] = $c->get_id();
		return $c;
	}

	/** Cash on delivery for this test (the test site has no payment method switched on). */
	protected function enable_cod(): void {
		if ( ! $this->cod_changed ) {
			$this->cod_saved   = get_option( 'woocommerce_cod_settings', false );
			$this->cod_changed = true;
		}
		update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes', 'title' => 'COD', 'enable_for_methods' => array(), 'enable_for_virtual' => 'yes' ) );
		WC()->payment_gateways()->init();
	}

	protected function store_api( string $method, string $route, array $body = array() ): \WP_REST_Response {
		$req = new \WP_REST_Request( $method, '/wc/store/v1/' . ltrim( $route, '/' ) );
		$req->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		$req->set_header( 'content-type', 'application/json' );
		if ( $body ) {
			$req->set_body( wp_json_encode( $body ) );
		}
		return rest_do_request( $req );
	}

	protected function address(): array {
		return array( 'first_name' => 'Test', 'last_name' => 'Buyer', 'address_1' => 'Test Sk. 1', 'city' => 'Istanbul', 'state' => 'TR34', 'postcode' => '34000', 'country' => 'TR', 'email' => 'buyer@example.com', 'phone' => '5550000000' );
	}

	/** Block checkout: POST /wc/store/v1/checkout with cash on delivery. */
	protected function place_store_api_order(): \WP_REST_Response {
		$res  = $this->store_api( 'POST', 'checkout', array( 'billing_address' => $this->address(), 'shipping_address' => $this->address(), 'payment_method' => 'cod' ) );
		$data = $res->get_data();
		if ( is_array( $data ) && isset( $data['order_id'] ) ) {
			$this->wc_orders[] = (int) $data['order_id'];
		}
		return $res;
	}

	/** The current guest (cookie created if needed) passed $cond just now. */
	protected function verify_guest( string $cond = '18+' ): string {
		$owner = (string) Owner::current( true );
		Results::add( $owner, $cond, true, 'n-' . wp_rand(), false );
		\VerifyBlind\Placements\WcProduct::reset_memo();
		return $owner;
	}
}
