<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Gate;
use VerifyBlind\Placements\WcSite;
use VerifyBlind\Rules;

final class WcSiteTest extends WcTestCase {
	protected function tearDown(): void {
		unset( $_GET['wc-ajax'] );
		parent::tearDown();
	}

	public function test_is_a_placement(): void {
		$this->assertArrayHasKey( WcSite::KEY, Rules::placements() );
	}

	public function test_guests_meet_the_gate_until_verified(): void {
		$rule = $this->rule( array( 'placement' => WcSite::KEY, 'age' => '18+' ) );
		$this->query_post( $this->product() );
		$this->assertSame( $rule['id'], WcSite::blocking()['id'] );
		$this->verify_guest( '18+' );
		$this->assertNull( WcSite::blocking() );
	}

	public function test_exempt_requests_pages_and_people(): void {
		$this->rule( array( 'placement' => WcSite::KEY, 'age' => '18+' ) );
		$this->query_post( $this->product() );
		$this->assertNotNull( WcSite::blocking() );

		$_GET['wc-ajax'] = 'get_refreshed_fragments';
		$this->assertNull( WcSite::blocking() );
		unset( $_GET['wc-ajax'] );

		$saved            = get_option( 'wp_page_for_privacy_policy' );
		$privacy          = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'VB privacy', 'post_status' => 'publish' ) );
		$this->wc_posts[] = $privacy;
		update_option( 'wp_page_for_privacy_policy', $privacy );
		try {
			$this->query_post( $privacy );
			$this->assertNull( WcSite::blocking(), 'the privacy policy page stays readable' );
		} finally {
			update_option( 'wp_page_for_privacy_policy', $saved );
		}

		$this->query_post( $this->product() );
		wp_set_current_user( $this->make_user( 'administrator' ) );
		$this->assertNull( WcSite::blocking() );
	}

	public function test_the_gate_page_has_the_box_and_loads_its_scripts(): void {
		$rule = $this->rule( array( 'placement' => WcSite::KEY, 'age' => '18+' ) );
		$html = WcSite::page( $rule );
		$this->assertStringContainsString( '<html', $html );
		$this->assertStringContainsString( 'verifyblind-site-gate', $html );
		$this->assertStringContainsString( 'verifyblind-start', $html );
		$this->assertStringContainsString( '</body>', $html );
		$this->assertTrue( wp_script_is( 'verifyblind-front', 'enqueued' ) || wp_script_is( 'verifyblind-front', 'done' ) );
	}

	public function test_without_a_rule_nothing_happens(): void {
		Gate::reset_no_cache_flag();
		WcSite::maybe_gate(); // returns instead of printing a page
		$this->assertFalse( Gate::no_cache_requested() );
	}
}
