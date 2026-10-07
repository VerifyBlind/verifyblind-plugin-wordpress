<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Results;
use VerifyBlind\Roles;

final class RolesTest extends TestCase {
	protected function tearDown(): void {
		remove_role( 'vb_adult' );
		parent::tearDown();
	}

	public function test_grants_and_revokes_rule_and_base_roles(): void {
		Roles::create( 'vb_adult', 'Adult member' );
		$this->rule( array( 'placement' => 'role_only', 'age' => '21+', 'role' => 'vb_adult' ) );
		$uid = $this->make_user( 'customer' );

		Results::add( 'u:' . $uid, '18+', true, 'n1', false );
		Roles::sync_user( $uid );
		$roles = get_userdata( $uid )->roles;
		$this->assertContains( 'customer', $roles );
		$this->assertContains( Roles::BASE, $roles );
		$this->assertNotContains( 'vb_adult', $roles );

		Results::add( 'u:' . $uid, '25+', true, 'n2', false );
		Roles::sync_user( $uid );
		$this->assertContains( 'vb_adult', get_userdata( $uid )->roles );

		Results::delete_by_nonce( 'n1' );
		Results::delete_by_nonce( 'n2' );
		Roles::sync_user( $uid );
		$roles = get_userdata( $uid )->roles;
		$this->assertSame( array( 'customer' ), array_values( $roles ) );
	}

	public function test_does_not_remove_a_role_it_did_not_grant(): void {
		Roles::create( 'vb_adult', 'Adult member' );
		$this->rule( array( 'placement' => 'role_only', 'age' => '21+', 'role' => 'vb_adult' ) );
		$uid = $this->make_user();
		get_userdata( $uid )->add_role( 'vb_adult' ); // given by hand
		Roles::sync_user( $uid );
		$this->assertContains( 'vb_adult', get_userdata( $uid )->roles );
	}

	public function test_test_results_count_only_in_test_mode(): void {
		$uid = $this->make_user();
		Results::add( 'u:' . $uid, '18+', true, 'n1', true );
		Roles::sync_user( $uid );
		$this->assertNotContains( Roles::BASE, get_userdata( $uid )->roles );
		update_option( 'verifyblind_test_mode', '1' );
		Roles::sync_user( $uid );
		$this->assertContains( Roles::BASE, get_userdata( $uid )->roles );
	}
}
