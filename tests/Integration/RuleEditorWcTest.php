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
		$this->assertSame( array( $p, $c->get_id() ), $rule['targets']['post_ids'] );
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
}
