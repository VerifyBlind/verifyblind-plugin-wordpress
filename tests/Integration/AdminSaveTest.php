<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Admin\RulesPage;
use VerifyBlind\Roles;

final class AdminSaveTest extends TestCase {
	protected function tearDown(): void {
		remove_role( 'vb_yetiskin_uye' );
		remove_role( 'vb_tmp_customer' );
		remove_role( 'vb_tmp_shop_manager' );
		remove_role( 'vb_tmp_fresh' );
		parent::tearDown();
	}

	public function test_form_input_is_mapped_to_a_rule(): void {
		$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'x', 'post_status' => 'publish' ) );
		$in   = RulesPage::input_from_post(
			array(
				'id'               => '',
				'name'             => ' Yetişkin forum ',
				'enabled'          => '1',
				'placement'        => 'role_only',
				'page_ids'         => array( (string) $page ),
				'other_post_ids'   => '12, 13',
				'term_ids'         => array( '4' ),
				'age_type'         => 'at_least',
				'age_n'            => '21',
				'age_m'            => '',
				'unique'           => '',
				'duplicate_policy' => 'flag',
				'role'             => '',
				'new_role'         => 'Yetişkin Üye',
				'validity_days'    => '365',
			)
		);
		$this->assertSame( '21+', $in['age'] );
		$this->assertSame( 'vb_yetiskin_uye', $in['role'] );
		$this->assertNotNull( get_role( 'vb_yetiskin_uye' ) );
		$this->assertSame( array( (string) $page, '12', ' 13' ), $in['targets']['post_ids'] );
		$rule = \VerifyBlind\Rules::save( $in );
		$this->assertSame( array( $page, 12, 13 ), $rule['targets']['post_ids'] );
		$this->assertSame( 365, $rule['validity_days'] );
		wp_delete_post( $page, true );
	}

	public function test_privileged_roles_are_not_grantable(): void {
		add_role( 'vb_tmp_shop_manager', 'Shop manager', array( 'read' => true, 'edit_shop_orders' => true ) );
		add_role( 'vb_tmp_customer', 'Customer', array( 'read' => true ) );
		$this->assertFalse( Roles::is_grantable( 'administrator' ) );
		$this->assertFalse( Roles::is_grantable( 'editor' ) );
		$this->assertFalse( Roles::is_grantable( 'author' ) );
		$this->assertFalse( Roles::is_grantable( 'vb_tmp_shop_manager' ) );
		$this->assertFalse( Roles::is_grantable( 'no_such_role' ) );
		$this->assertTrue( Roles::is_grantable( 'subscriber' ) );
		$this->assertTrue( Roles::is_grantable( 'vb_tmp_customer' ) );
		Roles::create( 'vb_tmp_fresh', 'Fresh' );
		$this->assertTrue( Roles::is_grantable( 'vb_tmp_fresh' ) );
	}

	public function test_submitted_privileged_role_is_dropped(): void {
		$in = RulesPage::input_from_post( array( 'name' => 'x', 'role' => 'editor', 'age_type' => 'at_least', 'age_n' => '18' ) );
		$this->assertSame( '', $in['role'] );
		$in = RulesPage::input_from_post( array( 'name' => 'x', 'role' => 'subscriber', 'age_type' => 'at_least', 'age_n' => '18' ) );
		$this->assertSame( 'subscriber', $in['role'] );
	}
}
