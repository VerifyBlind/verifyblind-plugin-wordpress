<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Cron;
use VerifyBlind\Identities;
use VerifyBlind\Nonces;
use VerifyBlind\Results;
use VerifyBlind\Roles;
use VerifyBlind\Schema;

final class CronTest extends TestCase {
	public function test_purges_and_expires_roles(): void {
		global $wpdb;
		Nonces::put( 'old', 'r_00000001', '18+', false, 'u:1', -10 );
		Results::add( 'g:' . str_repeat( 'a', 32 ), '18+', true, 'g1', false );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'results' ) . ' SET verified_at = %s WHERE nonce = %s', gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ), 'g1' ) );

		$this->rule( array( 'placement' => 'role_only', 'age' => '18+', 'role' => 'subscriber', 'validity_days' => 182 ) );
		$uid = $this->make_user( 'customer' );
		Results::add( 'u:' . $uid, '18+', true, 'u1', false );
		Roles::sync_user( $uid );
		$this->assertContains( 'subscriber', get_userdata( $uid )->roles );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'results' ) . ' SET verified_at = %s WHERE nonce = %s', gmdate( 'Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS ), 'u1' ) );

		Cron::run();

		$this->assertNull( Nonces::consume( 'old', 'u:1' ) );
		$this->assertSame( array(), Results::passed_conditions( 'g:' . str_repeat( 'a', 32 ), 0, false ) );
		$this->assertNotContains( 'subscriber', get_userdata( $uid )->roles );
		$this->assertContains( Roles::BASE, get_userdata( $uid )->roles ); // base role has no expiry
	}

	public function test_deleting_a_user_deletes_their_data(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$uid = $this->make_user();
		Results::add( 'u:' . $uid, '18+', true, 'd1', false );
		Identities::insert( 'PDEL', $uid, null, null, 'd1' );
		wp_delete_user( $uid );
		$this->created_users = array_diff( $this->created_users, array( $uid ) );
		$this->assertSame( array(), Results::passed_conditions( 'u:' . $uid, 0, false ) );
		$this->assertNull( Identities::find_by_vb_user_id( 'PDEL' ) );
	}
}
