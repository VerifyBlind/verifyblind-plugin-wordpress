<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Gate;
use VerifyBlind\Messages;
use VerifyBlind\Placements\WcProduct;
use VerifyBlind\Rules;

final class WcProductTest extends WcTestCase {
	/** @var int */
	private $cat;
	/** @var int */
	private $pid;

	protected function setUp(): void {
		parent::setUp();
		Gate::reset_no_cache_flag();
		$this->cat = $this->category( 'VB 18+ product page' );
		$this->pid = $this->product( array( $this->cat ), 'SECRET-DESC', 'SECRET-SHORT' );
		$this->rule( array( 'placement' => WcProduct::KEY, 'age' => '18+', 'targets' => array( 'term_ids' => array( $this->cat ) ) ) );
	}

	protected function tearDown(): void {
		// swap_classic_add_to_cart() may have moved WooCommerce's own add-to-cart template.
		remove_action( 'woocommerce_single_product_summary', array( WcProduct::class, 'print_box' ), 30 );
		if ( false === has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart' ) ) {
			add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
		}
		// test_store_api_add_to_cart_is_refused_until_verified() takes the classic filter away for one call.
		if ( false === has_filter( 'woocommerce_add_to_cart_validation', array( WcProduct::class, 'add_to_cart_validation' ) ) ) {
			add_filter( 'woocommerce_add_to_cart_validation', array( WcProduct::class, 'add_to_cart_validation' ), 10, 4 );
		}
		parent::tearDown();
	}

	public function test_is_a_placement(): void {
		$this->assertArrayHasKey( WcProduct::KEY, Rules::placements() );
	}

	public function test_add_to_cart_is_refused_until_verified(): void {
		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', true, $this->pid, 1 ) );
		$this->assertContains( Messages::get( 'product_required' ), wp_list_pluck( wc_get_notices( 'error' ), 'notice' ) );
		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $this->product(), 1 ), 'other products are not touched' );
		$this->verify_guest( '18+' );
		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $this->pid, 1 ) );
	}

	public function test_store_api_add_to_cart_is_refused_until_verified(): void {
		// The Store API runs the legacy add-to-cart filter first and turns its notice into a 400 error (denendi);
		// woocommerce_store_api_validate_add_to_cart (403) is the second, independent line.
		$refused = $this->store_api( 'POST', 'cart/add-item', array( 'id' => $this->pid, 'quantity' => 1 ) );
		$this->assertSame( 400, $refused->get_status() );
		$this->assertSame( Messages::get( 'product_required' ), $refused->get_data()['message'] );
		$this->assertCount( 0, WC()->cart->get_cart() );
		remove_filter( 'woocommerce_add_to_cart_validation', array( WcProduct::class, 'add_to_cart_validation' ), 10 );
		$second = $this->store_api( 'POST', 'cart/add-item', array( 'id' => $this->pid, 'quantity' => 1 ) );
		$this->assertSame( 403, $second->get_status(), 'the Store API hook refuses on its own too' );
		$this->assertSame( 'verifyblind_required', $second->get_data()['code'] );
		$this->verify_guest( '18+' );
		$this->assertSame( 201, $this->store_api( 'POST', 'cart/add-item', array( 'id' => $this->pid, 'quantity' => 1 ) )->get_status() );
	}

	public function test_descriptions_are_redacted_everywhere_until_verified(): void {
		$data = $this->store_api( 'GET', 'products/' . $this->pid )->get_data();
		$this->assertStringNotContainsString( 'SECRET-DESC', $data['description'] );
		$this->assertStringNotContainsString( 'SECRET-SHORT', $data['short_description'] );
		$this->assertStringContainsString( Gate::locked_text(), $data['description'] );
		$GLOBALS['post'] = get_post( $this->pid );
		$this->assertStringNotContainsString( 'SECRET-DESC', apply_filters( 'the_content', 'SECRET-DESC' ) );
		$this->assertStringNotContainsString( 'SECRET-SHORT', apply_filters( 'woocommerce_short_description', 'SECRET-SHORT' ) );
		$this->assertStringNotContainsString( 'SECRET-SHORT', apply_filters( 'get_the_excerpt', 'SECRET-SHORT', get_post( $this->pid ) ) );
		$rest = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/product/' . $this->pid ) );
		$this->assertStringNotContainsString( 'SECRET-DESC', $rest->get_data()['content']['rendered'] );
		$this->assertSame( 'no-store, private', $rest->get_headers()['Cache-Control'] );

		$this->verify_guest( '18+' );
		$this->assertStringContainsString( 'SECRET-DESC', $this->store_api( 'GET', 'products/' . $this->pid )->get_data()['description'] );
		$this->assertStringContainsString( 'SECRET-DESC', apply_filters( 'the_content', 'SECRET-DESC' ) );
	}

	public function test_people_who_can_edit_the_product_see_everything(): void {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		$data = rest_do_request( new \WP_REST_Request( 'GET', '/wc/v3/products/' . $this->pid ) )->get_data();
		$this->assertStringContainsString( 'SECRET-DESC', $data['description'] );
		$this->assertNull( WcProduct::blocking( $this->pid ) );
	}

	public function test_the_add_to_cart_form_is_replaced_by_the_box(): void {
		$GLOBALS['post'] = get_post( $this->pid );
		$html            = WcProduct::render_block( '<form class="cart">BUY</form>', array( 'blockName' => 'woocommerce/add-to-cart-form' ) );
		$this->assertStringNotContainsString( 'BUY', $html );
		$this->assertStringContainsString( 'verifyblind-start', $html );
		$other = WcProduct::render_block( '<form class="cart">BUY</form>', array( 'blockName' => 'woocommerce/add-to-cart-form' ), (object) array( 'context' => array( 'postId' => $this->product() ) ) );
		$this->assertSame( '<form class="cart">BUY</form>', $other, 'the block context names the product' );

		WcProduct::swap_classic_add_to_cart();
		$this->assertFalse( has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart' ) );
		$this->assertSame( 30, has_action( 'woocommerce_single_product_summary', array( WcProduct::class, 'print_box' ) ) );
	}

	public function test_targeted_product_pages_are_never_cached(): void {
		$this->query_post( $this->product() );
		WcProduct::on_template_redirect();
		$this->assertFalse( Gate::no_cache_requested() );
		$this->verify_guest( '18+' );
		$this->query_post( $this->pid );
		WcProduct::on_template_redirect();
		$this->assertTrue( Gate::no_cache_requested(), 'the unlocked page is per-visitor too' );
	}
}
