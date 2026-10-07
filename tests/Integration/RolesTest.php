<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Results;
use VerifyBlind\Roles;

final class RolesTest extends TestCase {
	const TMP_ROLES = array( 'vb_tmp_reader', 'vb_tmp_moderate', 'vb_tmp_keymaster', 'vb_tmp_products', 'vb_tmp_level1', 'vb_tmp_participant', 'vb_tmp_denied_edit', 'vb_tmp_custom_cap' );

	protected function tearDown(): void {
		remove_role( 'vb_adult' );
		foreach ( self::TMP_ROLES as $r ) {
			remove_role( $r );
		}
		parent::tearDown();
	}

	public function test_only_roles_with_allowed_capabilities_are_grantable(): void {
		add_role( 'vb_tmp_reader', 'Reader', array( 'read' => true ) );
		add_role( 'vb_tmp_moderate', 'Forum moderator', array( 'read' => true, 'moderate' => true ) );
		add_role( 'vb_tmp_keymaster', 'Forum keymaster', array( 'read' => true, 'keep_gate' => true ) );
		add_role( 'vb_tmp_products', 'Product editor', array( 'read' => true, 'edit_products' => true ) );
		add_role( 'vb_tmp_level1', 'Level 1', array( 'read' => true, 'level_1' => true ) );
		add_role( 'vb_tmp_custom_cap', 'Custom', array( 'read' => true, 'some_plugin_cap' => true ) );
		// bbPress "Participant": an ordinary forum member.
		add_role(
			'vb_tmp_participant',
			'Participant',
			array(
				'read'                => true,
				'spectate'            => true,
				'participate'         => true,
				'read_private_forums' => true,
				'publish_topics'      => true,
				'edit_topics'         => true,
				'publish_replies'     => true,
				'edit_replies'        => true,
				'assign_topic_tags'   => true,
			)
		);
		// A capability present but switched off grants nothing.
		add_role( 'vb_tmp_denied_edit', 'Denied edit', array( 'read' => true, 'edit_posts' => false ) );

		$this->assertTrue( Roles::is_grantable( 'vb_tmp_reader' ) );
		$this->assertTrue( Roles::is_grantable( 'vb_tmp_participant' ) );
		$this->assertTrue( Roles::is_grantable( 'vb_tmp_denied_edit' ) );
		$this->assertTrue( Roles::is_grantable( 'subscriber' ) );
		$this->assertTrue( Roles::is_grantable( Roles::BASE ) );
		$this->assertFalse( Roles::is_grantable( 'vb_tmp_moderate' ) );
		$this->assertFalse( Roles::is_grantable( 'vb_tmp_keymaster' ) );
		$this->assertFalse( Roles::is_grantable( 'vb_tmp_products' ) );
		$this->assertFalse( Roles::is_grantable( 'vb_tmp_level1' ) );
		$this->assertFalse( Roles::is_grantable( 'vb_tmp_custom_cap' ) );
		$this->assertFalse( Roles::is_grantable( 'contributor' ) );
		$this->assertFalse( Roles::is_grantable( 'administrator' ) );
		$this->assertFalse( Roles::is_grantable( 'no_such_role' ) );
	}

	public function test_sync_user_never_grants_a_forum_moderator_role(): void {
		add_role( 'vb_tmp_moderate', 'Forum moderator', array( 'read' => true, 'moderate' => true ) );
		$this->rule( array( 'placement' => 'role_only', 'age' => '18+', 'role' => 'vb_tmp_moderate' ) );
		$uid = $this->make_user();
		Results::add( 'u:' . $uid, '18+', true, 'n1', false );
		Roles::sync_user( $uid );
		$this->assertNotContains( 'vb_tmp_moderate', get_userdata( $uid )->roles );
		$this->assertContains( Roles::BASE, get_userdata( $uid )->roles );
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

	public function test_sync_user_never_grants_a_non_grantable_rule_role(): void {
		$this->rule( array( 'placement' => 'role_only', 'age' => '18+', 'role' => 'editor' ) );
		$uid = $this->make_user();
		Results::add( 'u:' . $uid, '25+', true, 'n1', false );
		Roles::sync_user( $uid );
		$roles = get_userdata( $uid )->roles;
		$this->assertNotContains( 'editor', $roles );
		$this->assertContains( Roles::BASE, $roles );
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
