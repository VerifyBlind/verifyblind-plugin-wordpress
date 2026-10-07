<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Admin\ProductRulesBox;
use VerifyBlind\Admin\RulesPage;
use VerifyBlind\Rules;

final class RuleEditorWcTest extends WcTestCase {
	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tearDown();
	}

	public function test_woocommerce_pickers_are_merged_into_targets(): void {
		$p      = $this->product();
		$cat    = $this->category( 'VB editor' );
		$c      = $this->coupon();
		$parsed = RulesPage::input_from_post(
			array(
				'name'            => 'Drinks',
				'enabled'         => '1',
				'placement'       => 'wc_checkout',
				'age_type'        => 'at_least',
				'age_n'           => '18',
				'product_ids'     => $p . ', 0',
				'product_cat_ids' => array( (string) $cat ),
				'coupon_ids'      => array( (string) $c->get_id() ),
				'guest_mode'      => 'require_account',
			)
		);
		$rule = Rules::save( $parsed['input'] );
		$this->assertSame( array( $p ), $rule['targets']['post_ids'], 'coupons belong to coupon rules only' );
		$this->assertSame( array( $cat ), $rule['targets']['term_ids'] );
		$this->assertSame( 'require_account', $rule['guest_mode'] );
	}

	public function test_editor_shows_woocommerce_rows_and_the_toggle_script(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php'; // submit_button() is admin-only
		$this->coupon();
		wp_set_current_user( $this->make_user( 'administrator' ) );
		$_GET['page']   = 'verifyblind';
		$_GET['action'] = 'new';
		ob_start();
		RulesPage::render();
		$html = (string) ob_get_clean();
		foreach ( array( 'name="product_ids"', 'name="product_cat_ids[]"', 'name="coupon_ids[]"', 'name="guest_mode"', 'data-vb-for="wc_coupon"', 'value="wc_checkout"', 'value="registration"', 'data-vb-for=' ) as $needle ) {
			$this->assertStringContainsString( $needle, $html, $needle );
		}
		$this->assertStringContainsString( "querySelectorAll('[data-vb-for]')", $html );
	}

	public function test_product_box_ticks_rules_for_one_product(): void {
		$p       = $this->product();
		$q       = $this->product();
		$a       = $this->rule( array( 'placement' => 'wc_checkout', 'targets' => array( 'post_ids' => array( $q ) ) ) );
		$b       = $this->rule( array( 'placement' => 'wc_product' ) );
		$content = $this->rule( array( 'placement' => 'content' ) );
		ProductRulesBox::apply( $p, array( $a['id'], $b['id'], $content['id'] ) );
		$this->assertSame( array( $q, $p ), Rules::get( $a['id'] )['targets']['post_ids'] );
		$this->assertSame( array( $p ), Rules::get( $b['id'] )['targets']['post_ids'] );
		$this->assertSame( array(), Rules::get( $content['id'] )['targets']['post_ids'], 'only WooCommerce product rules are offered' );
		ProductRulesBox::apply( $p, array( $b['id'] ) );
		$this->assertSame( array( $q ), Rules::get( $a['id'] )['targets']['post_ids'] );
		$this->assertSame( array( $p ), Rules::get( $b['id'] )['targets']['post_ids'] );
	}

	public function test_product_box_saves_only_with_a_nonce_and_admin_rights(): void {
		$p    = $this->product();
		$rule = $this->rule( array( 'placement' => 'wc_product' ) );
		$_POST = array( 'verifyblind_rules' => array( $rule['id'] ) );
		wp_set_current_user( $this->make_user( 'administrator' ) );
		ProductRulesBox::save( $p );
		$this->assertSame( array(), Rules::get( $rule['id'] )['targets']['post_ids'], 'no nonce, no change' );

		wp_set_current_user( $this->make_user( 'shop_manager' ) );
		$_POST['verifyblind_product_rules_nonce'] = wp_create_nonce( 'verifyblind_product_rules' );
		ProductRulesBox::save( $p );
		$this->assertSame( array(), Rules::get( $rule['id'] )['targets']['post_ids'], 'shop managers do not edit rules' );

		wp_set_current_user( $this->make_user( 'administrator' ) );
		$_POST['verifyblind_product_rules_nonce'] = wp_create_nonce( 'verifyblind_product_rules' );
		ProductRulesBox::save( $p );
		$this->assertSame( array( $p ), Rules::get( $rule['id'] )['targets']['post_ids'] );
	}

	public function test_product_box_lists_woocommerce_rules(): void {
		$cat  = $this->category( 'VB box' );
		$p    = $this->product( array( $cat ) );
		$rule = $this->rule( array( 'name' => 'Box rule', 'placement' => 'wc_checkout', 'targets' => array( 'post_ids' => array( $p ) ) ) );
		$this->rule( array( 'name' => 'Category rule', 'placement' => 'wc_product', 'targets' => array( 'term_ids' => array( $cat ) ) ) );
		$this->rule( array( 'name' => 'Content rule' ) );
		ob_start();
		ProductRulesBox::render( get_post( $p ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Box rule', $html );
		$this->assertStringContainsString( 'Category rule', $html );
		$this->assertStringNotContainsString( 'Content rule', $html );
		$this->assertMatchesRegularExpression( '/value="' . $rule['id'] . '"[^>]*checked/', $html );
		$this->assertStringContainsString( 'verifyblind_product_rules_nonce', $html );
	}

	public function test_save_drops_targets_that_do_not_belong_to_the_placement(): void {
		$p   = $this->product();
		$cat = $this->category( 'VB foreign' );
		$c   = $this->coupon();
		$pg  = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'VB foreign', 'post_status' => 'publish' ) );
		$this->wc_posts[] = $pg;
		$all = array(
			'name'            => 'Foreign',
			'age_type'        => 'at_least',
			'age_n'           => '18',
			'page_ids'        => array( (string) $pg ),
			'other_post_ids'  => '999991',
			'term_ids'        => array( '1' ),
			'product_ids'     => (string) $p,
			'product_cat_ids' => array( (string) $cat ),
			'coupon_ids'      => array( (string) $c->get_id() ),
		);
		$comments = RulesPage::input_from_post( $all + array( 'placement' => 'comments' ) );
		$this->assertSame( array( $pg, 999991 ), array_map( 'intval', $comments['input']['targets']['post_ids'] ) );
		$this->assertSame( array( '1' ), array_map( 'strval', $comments['input']['targets']['term_ids'] ) );
		$wc = RulesPage::input_from_post( $all + array( 'placement' => 'wc_product' ) );
		$this->assertSame( array( (string) $p ), array_map( 'strval', $wc['input']['targets']['post_ids'] ) );
		$this->assertSame( array( (string) $cat ), array_map( 'strval', $wc['input']['targets']['term_ids'] ) );
		$coupon = RulesPage::input_from_post( $all + array( 'placement' => 'wc_coupon' ) );
		$this->assertSame( array( (string) $c->get_id() ), array_map( 'strval', $coupon['input']['targets']['post_ids'] ) );
		$this->assertSame( array(), $coupon['input']['targets']['term_ids'] );
		$reg = RulesPage::input_from_post( $all + array( 'placement' => 'registration' ) );
		$this->assertSame( array(), $reg['input']['targets']['post_ids'] );
		$this->assertSame( array(), $reg['input']['targets']['term_ids'] );
	}

	public function test_editor_preselects_require_account_for_a_checkout_rule(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		$rule = $this->rule( array( 'placement' => 'wc_checkout', 'guest_mode' => 'require_account' ) );
		wp_set_current_user( $this->make_user( 'administrator' ) );
		$_GET['page']   = 'verifyblind';
		$_GET['action'] = 'edit';
		$_GET['rule']   = $rule['id'];
		ob_start();
		RulesPage::render();
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '/<option value="require_account"\s+selected=/', $html );
		$this->assertDoesNotMatchRegularExpression( '/<option value="verify_each_order"\s+selected=/', $html );
	}

	public function test_product_box_keeps_the_other_settings_of_the_rule(): void {
		$p    = $this->product();
		$rule = $this->rule( array( 'placement' => 'wc_checkout', 'enabled' => false, 'age' => '21+', 'role' => 'customer', 'guest_mode' => 'require_account' ) );
		ProductRulesBox::apply( $p, array( $rule['id'] ) );
		$after = Rules::get( $rule['id'] );
		$this->assertSame( array( $p ), $after['targets']['post_ids'] );
		foreach ( array( 'enabled', 'age', 'role', 'guest_mode' ) as $key ) {
			$this->assertSame( $rule[ $key ], $after[ $key ], $key );
		}
	}

	public function test_product_box_never_rescopes_an_all_products_review_rule(): void {
		$p    = $this->product();
		$rule = $this->rule( array( 'name' => 'Every review', 'placement' => 'wc_review' ) );
		ob_start();
		ProductRulesBox::render( get_post( $p ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Every review', $html );
		$this->assertStringContainsString( 'Applies to all products', $html );
		$this->assertStringNotContainsString( 'value="' . $rule['id'] . '"', $html, 'no submittable checkbox' );
		ProductRulesBox::apply( $p, array( $rule['id'] ) );
		$this->assertSame( array(), Rules::get( $rule['id'] )['targets']['post_ids'], 'ticking does not narrow' );
		ProductRulesBox::apply( $p, array() );
		$this->assertSame( array(), Rules::get( $rule['id'] )['targets']['post_ids'] );
	}

	public function test_product_box_refuses_to_unlink_the_last_product_of_a_review_rule(): void {
		$p    = $this->product();
		$q    = $this->product();
		$one  = $this->rule( array( 'placement' => 'wc_review', 'name' => 'Only P', 'targets' => array( 'post_ids' => array( $p ) ) ) );
		$two  = $this->rule( array( 'placement' => 'wc_review', 'name' => 'P and Q', 'targets' => array( 'post_ids' => array( $p, $q ) ) ) );
		$user = $this->make_user( 'administrator' );
		wp_set_current_user( $user );
		ProductRulesBox::apply( $p, array() );
		$this->assertSame( array( $p ), Rules::get( $one['id'] )['targets']['post_ids'], 'last product is kept' );
		$this->assertSame( array( $q ), Rules::get( $two['id'] )['targets']['post_ids'] );
		ob_start();
		ProductRulesBox::notices();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Only P', $html );
		$this->assertStringNotContainsString( 'P and Q', $html );
		ob_start();
		ProductRulesBox::notices();
		$this->assertSame( '', (string) ob_get_clean(), 'shown once' );
	}

	public function test_product_box_skips_a_stored_rule_that_no_longer_validates(): void {
		$p     = $this->product();
		$stale = $this->raw_rule( array( 'placement' => 'wc_checkout', 'name' => 'Stale' ) );
		$ok    = $this->rule( array( 'placement' => 'wc_product' ) );
		$drop = static function ( $placements ) {
			unset( $placements['wc_checkout'] ); // the stored rule's placement is no longer registered
			return $placements;
		};
		add_filter( 'verifyblind_placements', $drop, 99 );
		ProductRulesBox::apply( $p, array( $stale['id'], $ok['id'] ) );
		remove_filter( 'verifyblind_placements', $drop, 99 );
		$this->assertSame( array(), Rules::get( $stale['id'] )['targets']['post_ids'], 'the invalid rule is left alone' );
		$this->assertSame( array( $p ), Rules::get( $ok['id'] )['targets']['post_ids'], 'the valid rule is still saved' );
	}
}
