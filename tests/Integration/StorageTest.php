<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Identities;
use VerifyBlind\Nonces;
use VerifyBlind\Owner;
use VerifyBlind\Results;

final class StorageTest extends TestCase {
	public function test_nonce_is_consumed_once_by_its_owner_only(): void {
		Nonces::put( 'n1', 'r_00000001', '18+', true, 'u:5', 960 );
		$this->assertNull( Nonces::consume( 'n1', 'u:6' ) );
		$row = Nonces::consume( 'n1', 'u:5' );
		$this->assertSame( array( 'nonce' => 'n1', 'rule_id' => 'r_00000001', 'age_cond' => '18+', 'want_uid' => true, 'owner' => 'u:5' ), $row );
		$this->assertNull( Nonces::consume( 'n1', 'u:5' ) );
	}

	public function test_expired_nonce_is_rejected(): void {
		Nonces::put( 'n2', 'r_00000001', '18+', false, 'g:' . str_repeat( 'a', 32 ), -1 );
		$this->assertNull( Nonces::consume( 'n2', 'g:' . str_repeat( 'a', 32 ) ) );
	}

	public function test_passed_conditions_respect_validity_and_test_flag(): void {
		global $wpdb;
		Results::add( 'u:9', '18+', true, 'a', false );
		Results::add( 'u:9', '21+', false, 'b', false );
		Results::add( 'u:9', 'uid', true, 'c', true );
		$this->assertEqualsCanonicalizing( array( '18+' ), Results::passed_conditions( 'u:9', 0, false ) );
		$this->assertEqualsCanonicalizing( array( '18+', 'uid' ), Results::passed_conditions( 'u:9', 0, true ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . \VerifyBlind\Schema::table( 'results' ) . ' SET verified_at = %s WHERE nonce = %s', gmdate( 'Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS ), 'a' ) );
		$this->assertSame( array(), Results::passed_conditions( 'u:9', 182, false ) );
		$this->assertSame( array( '18+' ), Results::passed_conditions( 'u:9', 365, false ) );
	}

	public function test_delete_by_nonce_returns_owners(): void {
		Results::add( 'u:1', '18+', true, 'x', false );
		Results::add( 'u:2', '18+', true, 'y', false );
		$this->assertSame( array( 'u:1' ), Results::delete_by_nonce( 'x' ) );
		$this->assertSame( array(), Results::passed_conditions( 'u:1', 0, false ) );
		$this->assertSame( array( '18+' ), Results::passed_conditions( 'u:2', 0, false ) );
	}

	public function test_identity_is_unique(): void {
		$this->assertTrue( Identities::insert( 'vbA', 10, 'nsbdA', 'docA', 'n1' ) );
		$this->assertFalse( Identities::insert( 'vbA', 11, 'nsbdA', 'docA', 'n2' ) );
		$this->assertSame( 10, (int) Identities::find_by_vb_user_id( 'vbA' )['wp_user_id'] );
		Identities::move( 'vbA', 11, 'n3' );
		$this->assertNull( Identities::find_by_wp_user( 10 ) );
		$this->assertSame( 'n3', Identities::find_by_wp_user( 11 )['nonce'] );
		$this->assertSame( array( 11 ), Identities::delete_by_nonce( 'n3' ) );
	}

	public function test_guest_owner_cookie(): void {
		$this->assertNull( Owner::current( false ) );
		$guest = Owner::current( true );
		$this->assertMatchesRegularExpression( '/^g:[a-f0-9]{32}$/', $guest );
		$this->assertSame( $guest, Owner::current( false ) );
		$uid = $this->make_user();
		wp_set_current_user( $uid );
		$this->assertSame( 'u:' . $uid, Owner::current( false ) );
		$this->assertSame( $uid, Owner::user_id( 'u:' . $uid ) );
		$this->assertSame( 0, Owner::user_id( $guest ) );
	}
}
