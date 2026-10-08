<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Badge;
use VerifyBlind\Presets;
use VerifyBlind\Rules;

final class PresetsTest extends TestCase {
	public function test_presets_create_normal_enabled_rules(): void {
		$saved = Presets::apply( array( 'age_shop', 'age_content', 'community', 'reviews', 'coupons' ) );
		$this->assertSame( array( 'wc_checkout', 'content', 'registration', 'comments', 'wc_review', 'wc_coupon' ), array_column( $saved, 'placement' ) );
		$by = array();
		foreach ( $saved as $r ) {
			$by[ $r['placement'] ] = $r;
			$this->assertTrue( $r['enabled'], $r['placement'] );
			$this->assertNotSame( 'wc_site', $r['placement'] );
			$this->assertSame( $r, Rules::get( $r['id'] ), 'stored as a normal rule' );
		}
		$this->assertSame( '18+', $by['wc_checkout']['age'] );
		$this->assertFalse( $by['wc_checkout']['unique'] );
		$this->assertSame( '18+', $by['content']['age'] );
		$this->assertSame( '', $by['registration']['age'] );
		$this->assertTrue( $by['registration']['unique'] );
		$this->assertSame( 'block', $by['registration']['duplicate_policy'] );
		$this->assertTrue( $by['comments']['unique'] );
		$this->assertTrue( $by['wc_review']['unique'] );
		$this->assertTrue( $by['wc_coupon']['unique'] );
	}

	public function test_badges_are_switched_on_for_the_chosen_places(): void {
		update_option( Badge::OPTION, array() );
		Presets::apply( array( 'community' ) );
		$this->assertSame( array( 'comments', 'author' ), Badge::places() );
		Presets::apply( array( 'reviews' ) );
		$this->assertSame( array( 'comments', 'reviews', 'author' ), Badge::places() );
	}

	public function test_applying_twice_adds_nothing_new(): void {
		Presets::apply( array( 'age_content' ) );
		$this->assertSame( array(), Presets::apply( array( 'age_content' ) ) );
		$this->assertCount( 1, Rules::all() );
	}

	public function test_marker_survives_a_rename_and_still_prevents_a_duplicate(): void {
		$first = Presets::apply( array( 'age_content' ) );
		$this->assertSame( 'age_content:0', $first[0]['preset'] );
		$renamed         = $first[0];
		$renamed['name'] = 'My own name';
		Rules::save( $renamed );
		$this->assertSame( array(), Presets::apply( array( 'age_content' ) ) );
		$this->assertCount( 1, Rules::all() );
		$this->assertSame( 'age_content:0', Rules::get( $first[0]['id'] )['preset'] );
	}

	public function test_sanitize_keeps_only_a_valid_marker(): void {
		$base = array( 'name' => 'X', 'placement' => 'content', 'age' => '18+' );
		$this->assertSame( 'age_shop:0', Rules::sanitize( $base + array( 'preset' => 'age_shop:0' ), array( 'content' ) )['preset'] );
		$this->assertSame( '', Rules::sanitize( $base + array( 'preset' => '<b>x' ), array( 'content' ) )['preset'] );
		$this->assertSame( '', Rules::sanitize( $base, array( 'content' ) )['preset'] );
	}

	public function test_unknown_keys_are_ignored(): void {
		$this->assertSame( array(), Presets::apply( array( 'nope', '<script>' ) ) );
		$this->assertSame( array(), Rules::all() );
	}

	public function test_woocommerce_presets_need_woocommerce(): void {
		$all = Presets::all();
		$this->assertSame( array( 'age_shop', 'age_content', 'community', 'reviews', 'coupons' ), array_keys( $all ) );
		$this->assertTrue( $all['age_shop']['woocommerce'] );
		$this->assertTrue( $all['coupons']['woocommerce'] );
		$this->assertFalse( $all['community']['woocommerce'] );
		$this->assertTrue( Presets::available( 'age_shop', true ) );
		$this->assertFalse( Presets::available( 'age_shop', false ) );
		$this->assertTrue( Presets::available( 'community', false ) );
		$this->assertFalse( Presets::available( 'nope', true ) );
	}
}
