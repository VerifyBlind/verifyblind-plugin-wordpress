<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Identities;
use VerifyBlind\Placements\WcCheckout;
use VerifyBlind\Placements\WcCoupon;
use VerifyBlind\Privacy;
use VerifyBlind\Results;

/** The eraser says what it keeps: shop records that enforce once-per-person offers or document orders. */
final class PrivacyWcTest extends WcTestCase {
	/** @var bool */
	private $had_key = false;

	protected function setUp(): void {
		parent::setUp();
		$this->had_key = false !== get_option( WcCoupon::KEY_OPTION, false );
	}

	protected function tearDown(): void {
		if ( ! $this->had_key ) {
			delete_option( WcCoupon::KEY_OPTION );
		}
		parent::tearDown();
	}

	private function customer(): \WP_User {
		$uid                   = wp_insert_user(
			array(
				'user_login' => 'vbprivwc_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'vbprivwc' . wp_rand() . '@example.com',
				'role'       => 'customer',
			)
		);
		$this->created_users[] = $uid;
		Results::add( 'u:' . $uid, 'uid', true, 'pwc-' . $uid, false );
		Identities::insert( 'P-PWC-' . $uid, $uid, null, null, 'pwc-' . $uid );
		return get_userdata( $uid );
	}

	public function test_coupon_use_records_are_reported_as_kept(): void {
		$user   = $this->customer();
		$coupon = $this->coupon();
		$person = WcCoupon::person_key( $user->ID );
		add_post_meta( $coupon->get_id(), WcCoupon::META, $person );
		$out = Privacy::erase( $user->user_email );
		$this->assertTrue( $out['items_removed'] );
		$this->assertTrue( $out['items_retained'] );
		$this->assertNotEmpty( $out['messages'] );
		$this->assertTrue( WcCoupon::has_person( $coupon->get_id(), $person ), 'kept on purpose: the offer stays once per person' );
		$this->assertNull( Identities::find_by_wp_user( $user->ID ) );
	}

	public function test_order_evidence_is_reported_as_kept(): void {
		$user  = $this->customer();
		$order = wc_create_order( array( 'customer_id' => $user->ID ) );
		$order->update_meta_data( WcCheckout::META, array( array( 'rule' => 'r_x', 'condition' => '18+', 'one_person' => false, 'time' => gmdate( 'Y-m-d H:i:s' ) ) ) );
		$order->save();
		$this->wc_orders[] = $order->get_id();
		$out               = Privacy::erase( $user->user_email );
		$this->assertTrue( $out['items_retained'] );
		$this->assertNotEmpty( $out['messages'] );
	}

	public function test_nothing_kept_means_nothing_reported(): void {
		$user              = $this->customer();
		$order             = wc_create_order( array( 'customer_id' => $user->ID ) ); // an order without verification records
		$this->wc_orders[] = $order->get_id();
		$out               = Privacy::erase( $user->user_email );
		$this->assertTrue( $out['items_removed'] );
		$this->assertFalse( $out['items_retained'] );
		$this->assertSame( array(), $out['messages'] );
	}
}
