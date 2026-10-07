<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Messages;
use VerifyBlind\Owner;
use VerifyBlind\Placements\ProductTargets;
use VerifyBlind\Placements\Prompt;
use VerifyBlind\Placements\Registry;
use VerifyBlind\Results;
use VerifyBlind\Rules;
use VerifyBlind\Targets;
use VerifyBlind\Widget;

final class PlacementsFoundationTest extends WcTestCase {
	public function test_enabled_lists_only_switched_on_rules_of_one_placement(): void {
		$on = $this->rule( array( 'name' => 'On' ) );
		$this->rule( array( 'name' => 'Off', 'enabled' => false ) );
		$this->rule( array( 'name' => 'Role', 'placement' => 'role_only' ) );
		$this->assertSame( array( $on['id'] ), wp_list_pluck( Rules::enabled( 'content' ), 'id' ) );
	}

	public function test_targets_cover_post_ids_and_descendant_terms(): void {
		$parent = wp_insert_term( 'VB T parent ' . wp_generate_password( 4, false ), 'category' );
		$child  = wp_insert_term( 'VB T child ' . wp_generate_password( 4, false ), 'category', array( 'parent' => $parent['term_id'] ) );
		$a      = wp_insert_post( array( 'post_title' => 'a', 'post_status' => 'publish' ) );
		$b      = wp_insert_post( array( 'post_title' => 'b', 'post_status' => 'publish' ) );
		wp_set_post_categories( $b, array( $child['term_id'] ) );
		$rule = array( 'targets' => array( 'post_ids' => array( $a ), 'term_ids' => array( (int) $parent['term_id'] ) ) );
		$this->assertTrue( Targets::covers( get_post( $a ), $rule ) );
		$this->assertTrue( Targets::covers( get_post( $b ), $rule ), 'a parent term covers its children' );
		$this->assertFalse( Targets::covers( get_post( $b ), array( 'targets' => array( 'post_ids' => array( $a ), 'term_ids' => array() ) ) ) );
		$this->assertTrue( Targets::is_empty( array( 'targets' => array( 'post_ids' => array(), 'term_ids' => array() ) ) ) );
		$this->assertFalse( Targets::is_empty( $rule ) );
		wp_delete_post( $a, true );
		wp_delete_post( $b, true );
		wp_delete_term( $child['term_id'], 'category' );
		wp_delete_term( $parent['term_id'], 'category' );
	}

	public function test_product_targets_follow_categories_and_variations(): void {
		$parent_cat = $this->category( 'VB PT parent' );
		$child_cat  = $this->category( 'VB PT child', $parent_cat );
		$in_child   = $this->product( array( $child_cat ) );
		$plain      = $this->product();
		$variable   = new \WC_Product_Variable();
		$variable->set_name( 'VB variable' );
		$variable->set_status( 'publish' );
		$variable->set_category_ids( array( $parent_cat ) );
		$vp               = $variable->save();
		$this->wc_posts[] = $vp;
		$variation        = new \WC_Product_Variation();
		$variation->set_parent_id( $vp );
		$variation->set_regular_price( '5' );
		$vid              = $variation->save();
		$this->wc_posts[] = $vid;

		$rule = $this->raw_rule( array( 'placement' => 'wc_checkout', 'targets' => array( 'post_ids' => array(), 'term_ids' => array( $parent_cat ) ) ) );
		$this->assertSame( array( $rule['id'] ), wp_list_pluck( ProductTargets::rules_for_product( $in_child, 'wc_checkout' ), 'id' ) );
		$this->assertSame( array( $rule['id'] ), wp_list_pluck( ProductTargets::rules_for_product( $vid, 'wc_checkout' ), 'id' ), 'a variation follows its parent' );
		$this->assertSame( $vp, ProductTargets::base_post( $vid )->ID );
		$this->assertSame( array(), ProductTargets::rules_for_product( $plain, 'wc_checkout' ) );
		$this->assertSame( array(), ProductTargets::rules_for_product( $in_child, 'wc_product' ) );
	}

	public function test_cart_and_order_rules_are_listed_once(): void {
		$cat  = $this->category( 'VB PT cart' );
		$a    = $this->product( array( $cat ) );
		$b    = $this->product( array( $cat ) );
		$rule = $this->raw_rule( array( 'placement' => 'wc_checkout', 'targets' => array( 'post_ids' => array( $a ), 'term_ids' => array( $cat ) ) ) );
		WC()->cart->add_to_cart( $a );
		WC()->cart->add_to_cart( $b );
		$this->assertSame( array( $rule['id'] ), wp_list_pluck( ProductTargets::rules_for_cart( WC()->cart, 'wc_checkout' ), 'id' ) );
		$this->assertSame( array(), ProductTargets::rules_for_cart( null, 'wc_checkout' ) );

		$order = wc_create_order();
		$order->add_product( wc_get_product( $a ), 1 );
		$order->add_product( wc_get_product( $b ), 1 );
		$order->save();
		$this->wc_orders[] = $order->get_id();
		$this->assertSame( array( $rule['id'] ), wp_list_pluck( ProductTargets::rules_for_order( $order, 'wc_checkout' ), 'id' ) );
	}

	public function test_box_options_and_requirement_line(): void {
		$rule    = $this->rule( array( 'age' => '21+' ) );
		$default = Widget::box( $rule );
		$this->assertStringContainsString( 'data-reload="1"', $default );
		$this->assertStringContainsString( esc_html( Widget::describe( $rule ) ), $default );
		$this->assertStringNotContainsString( 'verifyblind-box__req', $default );
		$custom = Widget::box( $rule, array( 'title' => 'Custom <title>', 'reload' => false ) );
		$this->assertStringContainsString( 'data-reload="0"', $custom );
		$this->assertStringContainsString( 'Custom &lt;title&gt;', $custom );
		$this->assertStringContainsString( esc_html( Widget::requirement( $rule ) ), $custom );
	}

	public function test_requirement_texts_follow_the_rule(): void {
		$this->assertNotSame( Widget::requirement( array( 'age' => '18+', 'unique' => false ) ), Widget::requirement( array( 'age' => '18-', 'unique' => false ) ) );
		$this->assertStringContainsString( '18', Widget::requirement( array( 'age' => '18+', 'unique' => false ) ) );
		$this->assertStringContainsString( '17', Widget::requirement( array( 'age' => '13-18', 'unique' => false ) ), 'the upper bound is exclusive' );
		$this->assertSame( '', Widget::requirement( array( 'age' => '', 'unique' => false ) ) );
		$this->assertNotSame( '', Widget::requirement( array( 'age' => '', 'unique' => true ) ) );
	}

	public function test_one_person_rules_ask_guests_to_log_in_except_at_sign_up(): void {
		$rule = array( 'id' => 'r_0000000a', 'placement' => 'content', 'age' => '', 'unique' => true );
		$html = Prompt::html( $rule, array( 'redirect' => 'http://localhost:8080/x/' ) );
		$this->assertStringContainsString( 'verifyblind-box--login', $html );
		$this->assertStringContainsString( esc_url( wp_login_url( 'http://localhost:8080/x/' ) ), $html );
		$this->assertStringNotContainsString( 'verifyblind-start', $html );
		$this->assertStringContainsString( 'verifyblind-start', Prompt::html( array_merge( $rule, array( 'placement' => 'registration' ) ) ) );
		$this->assertStringContainsString( 'verifyblind-box--login', Prompt::html( array_merge( $rule, array( 'unique' => false, 'age' => '18+' ) ), array( 'login' => true ) ) );
		wp_set_current_user( $this->make_user() );
		$this->assertStringContainsString( 'verifyblind-start', Prompt::html( $rule ) );
	}

	public function test_render_for_prints_only_for_visitors_who_do_not_meet_the_rule(): void {
		$rule = $this->rule( array( 'age' => '18+' ) );
		ob_start();
		Prompt::render_for( array( $rule ) );
		$this->assertStringContainsString( 'verifyblind-box', (string) ob_get_clean() );
		Results::add( (string) Owner::current( true ), '18+', true, 'pf', false );
		ob_start();
		Prompt::render_for( array( $rule ) );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_registry_splits_placements_by_woocommerce(): void {
		$off = Registry::split( false );
		$this->assertSame( Registry::core(), $off['active'] );
		$this->assertSame( Registry::woocommerce(), $off['unavailable'] );
		$on = Registry::split( true );
		$this->assertSame( array_merge( Registry::core(), Registry::woocommerce() ), $on['active'] );
		$this->assertSame( array(), $on['unavailable'] );
		$this->assertSame( array(), Registry::unavailable(), 'WooCommerce is active on the test site' );
		foreach ( $on['active'] as $class ) {
			$this->assertArrayHasKey( $class::KEY, Rules::placements() );
		}
	}

	public function test_new_visitor_messages_exist(): void {
		$fallback = Messages::get( 'something_unknown' );
		foreach ( array( 'registration_required', 'comment_required', 'checkout_required', 'account_required', 'product_required', 'coupon_required', 'coupon_login', 'coupon_used', 'coupon_no_person', 'coupon_busy' ) as $code ) {
			$this->assertNotSame( $fallback, Messages::get( $code ), $code );
		}
	}
}
