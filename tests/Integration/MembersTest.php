<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Admin\MembersPage;
use VerifyBlind\Identities;
use VerifyBlind\Members;
use VerifyBlind\PendingIdentities;
use VerifyBlind\Results;
use VerifyBlind\Roles;

final class MembersTest extends TestCase {
	protected function tearDown(): void {
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'wp_die_handler' );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tearDown();
	}

	public function test_page_lists_accounts_with_results_or_an_identity(): void {
		$a = $this->make_user();
		$b = $this->make_user();
		$c = $this->make_user();
		Results::add( 'u:' . $a, '18+', true, 'ma', false );
		Results::add( 'u:' . $a, 'uid', true, 'ma', false );
		Identities::insert( 'P-MA', $a, null, null, 'ma' );
		Results::add( 'u:' . $b, '18+', true, 'mb', true );
		update_user_meta( $b, 'verifyblind_duplicate_of', $a );
		Results::add( 'g:' . str_repeat( 'f', 32 ), '18+', true, 'guest', false ); // guests are not members

		$page = Members::page( 1, 20 );
		$this->assertSame( 2, $page['total'] );
		$rows = array();
		foreach ( $page['rows'] as $row ) {
			$rows[ $row['user_id'] ] = $row;
		}
		$this->assertArrayNotHasKey( $c, $rows );
		$this->assertTrue( $rows[ $a ]['one_person'] );
		$this->assertContains( '18+', $rows[ $a ]['conditions'] );
		$this->assertFalse( $rows[ $a ]['test'] );
		$this->assertTrue( $rows[ $b ]['test'] );
		$this->assertSame( $a, $rows[ $b ]['same_person_as'] );
		$this->assertSame( get_userdata( $a )->user_login, $rows[ $a ]['login'] );
		$this->assertCount( 1, Members::page( 2, 1 )['rows'] );
	}

	public function test_remove_clears_results_identity_flags_and_roles(): void {
		$this->rule( array( 'placement' => 'role_only', 'age' => '18+', 'role' => 'subscriber' ) );
		$uid = $this->make_user( 'customer' );
		Results::add( 'u:' . $uid, '18+', true, 'rm', false );
		Identities::insert( 'P-RM', $uid, null, null, 'rm' );
		update_user_meta( $uid, 'verifyblind_flag_person', 'P-X' );
		update_user_meta( $uid, 'verifyblind_duplicate_of', 1 );
		Roles::sync_user( $uid );
		$this->assertContains( Roles::BASE, get_userdata( $uid )->roles );

		Members::remove( $uid );
		$this->assertSame( array(), Results::passed_conditions( 'u:' . $uid, 0, true ) );
		$this->assertNull( Identities::find_by_wp_user( $uid ) );
		$this->assertSame( '', get_user_meta( $uid, 'verifyblind_flag_person', true ) );
		$this->assertSame( '', get_user_meta( $uid, 'verifyblind_duplicate_of', true ) );
		$roles = get_userdata( $uid )->roles;
		$this->assertNotContains( Roles::BASE, $roles );
		$this->assertNotContains( 'subscriber', $roles );
		$this->assertContains( 'customer', $roles );
	}

	public function test_remove_also_drops_held_checks_and_leaves_other_accounts_alone(): void {
		global $wpdb;
		$uid   = $this->make_user();
		$other = $this->make_user();
		Results::add( 'u:' . $uid, '18+', true, 'pn', false );
		Results::add( 'u:' . $other, '18+', true, 'other', false );
		Identities::insert( 'P-OTHER', $other, null, null, 'other' );
		PendingIdentities::put( 'u:' . $uid, 'r1', 'P-PN', null, null, 'pn', false );
		PendingIdentities::put( 'g:' . str_repeat( 'a', 32 ), 'r1', 'P-PN', null, null, 'pn', false );

		Members::remove( $uid );
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \VerifyBlind\Schema::table( 'pending' ) ) );
		$this->assertSame( array( '18+' ), Results::passed_conditions( 'u:' . $other, 0, true ) );
		$this->assertNotNull( Identities::find_by_wp_user( $other ) );
	}

	private function die_throws(): void {
		add_filter(
			'wp_die_handler',
			function () {
				return function () {
					throw new \RuntimeException( 'wp_die' );
				};
			}
		);
		add_filter(
			'wp_redirect',
			function ( $location ) {
				throw new \RuntimeException( 'redirect:' . $location );
			}
		);
	}

	public function test_remove_action_needs_the_right_nonce_and_rights(): void {
		$uid = $this->make_user();
		Results::add( 'u:' . $uid, '18+', true, 'ra', false );
		$this->die_throws();

		wp_set_current_user( $this->make_user( 'administrator' ) );
		$_GET     = array( 'user_id' => (string) $uid, '_wpnonce' => 'bad' );
		$_REQUEST = $_GET;
		try {
			MembersPage::remove();
			$this->fail( 'a bad nonce must stop' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		}
		$this->assertSame( array( '18+' ), Results::passed_conditions( 'u:' . $uid, 0, false ) );

		$_GET     = array( 'user_id' => (string) $uid, '_wpnonce' => wp_create_nonce( 'verifyblind_remove_member_' . $uid ) );
		$_REQUEST = $_GET;
		try {
			MembersPage::remove();
			$this->fail( 'remove() should redirect' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'vb_msg=removed', $e->getMessage() );
		}
		$this->assertSame( array(), Results::passed_conditions( 'u:' . $uid, 0, true ) );

		wp_set_current_user( $this->make_user( 'editor' ) );
		try {
			MembersPage::remove();
			$this->fail( 'editors may not remove verifications' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		}
	}

	public function test_remove_action_needs_an_existing_account(): void {
		$this->die_throws();
		wp_set_current_user( $this->make_user( 'administrator' ) );
		foreach ( array( 0, 2147483000 ) as $uid ) {
			$_GET     = array( 'user_id' => (string) $uid, '_wpnonce' => wp_create_nonce( 'verifyblind_remove_member_' . $uid ) );
			$_REQUEST = $_GET;
			try {
				MembersPage::remove();
				$this->fail( 'no account ' . $uid . ': must stop' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'wp_die', $e->getMessage(), (string) $uid );
			}
		}
	}

	public function test_screen_lists_members_with_a_remove_link(): void {
		$uid = $this->make_user();
		Results::add( 'u:' . $uid, 'uid', true, 'sc', false );
		Identities::insert( 'P-SECRET-CODE', $uid, null, null, 'sc' );
		wp_set_current_user( $this->make_user( 'administrator' ) );
		ob_start();
		MembersPage::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( esc_html( get_userdata( $uid )->user_login ), $html );
		$this->assertStringContainsString( 'action=verifyblind_remove_member', $html );
		$this->assertStringContainsString( 'user_id=' . $uid, $html );
		$this->assertStringNotContainsString( 'P-SECRET-CODE', $html );
	}
}
