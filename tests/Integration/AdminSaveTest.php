<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Admin\RulesPage;
use VerifyBlind\Roles;

final class AdminSaveTest extends TestCase {
	/** @var string */
	private $redirect = '';
	/** @var int[] pages to delete */
	private $pages = array();

	protected function tearDown(): void {
		remove_role( 'vb_privileged_test' );
		remove_role( 'vb_yetiskin_uye' );
		remove_role( 'vb_tmp_customer' );
		remove_role( 'vb_tmp_shop_manager' );
		remove_role( 'vb_tmp_fresh' );
		remove_role( 'vb_orphan_test' );
		remove_role( 'vb_ok_test' );
		remove_all_filters( 'wp_redirect' );
		foreach ( $this->pages as $id ) {
			wp_delete_post( $id, true );
		}
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tearDown();
	}

	public function test_form_input_is_mapped_to_a_rule(): void {
		$page          = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'x', 'post_status' => 'publish' ) );
		$this->pages[] = $page;
		$parsed = RulesPage::input_from_post(
			array(
				'id'               => '',
				'name'             => ' Yetişkin forum ',
				'enabled'          => '1',
				'placement'        => 'content',
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
		$in = $parsed['input'];
		$this->assertSame( '21+', $in['age'] );
		$this->assertSame( 'vb_yetiskin_uye', $in['role'] );
		$this->assertSame( array( 'slug' => 'vb_yetiskin_uye', 'label' => 'Yetişkin Üye' ), $parsed['new_role'] );
		$this->assertNull( get_role( 'vb_yetiskin_uye' ), 'parsing must not create the role' );
		$this->assertSame( array( (string) $page, '12', ' 13' ), $in['targets']['post_ids'] );
		$rule = \VerifyBlind\Rules::save( $in );
		$this->assertSame( array( $page, 12, 13 ), $rule['targets']['post_ids'] );
		$this->assertSame( 365, $rule['validity_days'] );
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
		$parsed = RulesPage::input_from_post( array( 'name' => 'x', 'role' => 'editor', 'age_type' => 'at_least', 'age_n' => '18' ) );
		$this->assertSame( '', $parsed['input']['role'] );
		$parsed = RulesPage::input_from_post( array( 'name' => 'x', 'role' => 'subscriber', 'age_type' => 'at_least', 'age_n' => '18' ) );
		$this->assertSame( 'subscriber', $parsed['input']['role'] );
	}

	public function test_new_role_name_without_usable_characters_is_ignored(): void {
		$parsed = RulesPage::input_from_post( array( 'name' => 'x', 'role' => 'subscriber', 'new_role' => '!!!', 'age_type' => 'at_least', 'age_n' => '18' ) );
		$this->assertNull( $parsed['new_role'] );
		$this->assertSame( 'subscriber', $parsed['input']['role'] );
		$this->assertNull( get_role( 'vb_' ) );
	}

	private function post_save( array $fields ): void {
		$admin = $this->make_user( 'administrator' );
		wp_set_current_user( $admin );
		$nonce     = wp_create_nonce( 'verifyblind_save_rule' );
		$_POST     = array_merge( $fields, array( '_wpnonce' => $nonce ) );
		$_REQUEST  = $_POST;
		add_filter(
			'wp_redirect',
			function ( $location ) {
				throw new \RuntimeException( (string) $location );
			}
		);
		try {
			RulesPage::save();
			$this->fail( 'save() should redirect' );
		} catch ( \RuntimeException $e ) {
			$this->redirect = $e->getMessage();
		}
	}

	public function test_new_role_name_colliding_with_a_privileged_role_is_not_stored(): void {
		add_role( 'vb_privileged_test', 'Privileged Test', array( 'read' => true, 'edit_posts' => true ) );
		$this->post_save( array( 'name' => 'Collide', 'placement' => 'content', 'age_type' => 'at_least', 'age_n' => '18', 'new_role' => 'Privileged Test', 'role' => '' ) );
		$this->assertStringContainsString( 'vb_msg=saved', $this->redirect );
		$rules = \VerifyBlind\Rules::all();
		$this->assertNotEmpty( $rules );
		foreach ( $rules as $rule ) {
			$this->assertNotSame( 'vb_privileged_test', $rule['role'] );
		}
		$this->assertSame( '', end( $rules )['role'] );
	}

	public function test_failed_save_does_not_create_the_new_role(): void {
		$this->post_save( array( 'name' => 'Orphan', 'placement' => 'content', 'age_type' => 'none', 'age_n' => '', 'new_role' => 'Orphan Test', 'role' => '' ) );
		$this->assertStringContainsString( 'vb_err=empty_request', $this->redirect );
		$this->assertNull( get_role( 'vb_orphan_test' ) );
	}

	public function test_successful_save_creates_the_new_role(): void {
		$this->post_save( array( 'name' => 'Ok', 'placement' => 'content', 'age_type' => 'at_least', 'age_n' => '18', 'new_role' => 'Ok Test', 'role' => '' ) );
		$this->assertStringContainsString( 'vb_msg=saved', $this->redirect );
		$this->assertNotNull( get_role( 'vb_ok_test' ) );
	}

	public function test_role_dropdown_offers_only_grantable_roles(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php'; // submit_button() is admin-only
		wp_set_current_user( $this->make_user( 'administrator' ) );
		$_GET['page']   = 'verifyblind';
		$_GET['action'] = 'new';
		ob_start();
		RulesPage::render();
		$html = (string) ob_get_clean();
		$this->assertSame( 1, preg_match( '#<select name="role">(.*?)</select>#s', $html, $m ) );
		$this->assertStringContainsString( 'value="subscriber"', $m[1] );
		$this->assertStringNotContainsString( 'value="editor"', $m[1] );
		$this->assertStringNotContainsString( 'value="administrator"', $m[1] );
	}
}
