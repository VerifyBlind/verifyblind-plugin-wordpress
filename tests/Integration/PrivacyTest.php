<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Identities;
use VerifyBlind\Privacy;
use VerifyBlind\Results;

final class PrivacyTest extends TestCase {
	private function user_with_data(): \WP_User {
		$uid                   = wp_insert_user(
			array(
				'user_login' => 'vbpriv_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'vbpriv' . wp_rand() . '@example.com',
			)
		);
		$this->created_users[] = $uid;
		Results::add( 'u:' . $uid, '18+', true, 'pv', false );
		Identities::insert( 'P-PRIV', $uid, 'N-PRIV', null, 'pv' );
		return get_userdata( $uid );
	}

	public function test_exporter_and_eraser_are_registered(): void {
		$this->assertArrayHasKey( 'verifyblind', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( 'verifyblind', apply_filters( 'wp_privacy_personal_data_erasers', array() ) );
	}

	public function test_export_lists_results_and_the_pseudonymous_code(): void {
		$user = $this->user_with_data();
		$out  = Privacy::export( $user->user_email );
		$this->assertTrue( $out['done'] );
		$this->assertCount( 2, $out['data'] );
		$flat = (string) wp_json_encode( $out['data'] );
		$this->assertStringContainsString( '18+', $flat );
		$this->assertStringContainsString( 'P-PRIV', $flat );
		$this->assertSame( 'verifyblind', $out['data'][0]['group_id'] );
		$this->assertSame( array( 'data' => array(), 'done' => true ), Privacy::export( 'nobody-' . wp_rand() . '@example.com' ) );
	}

	public function test_erase_removes_everything(): void {
		$user = $this->user_with_data();
		$out  = Privacy::erase( $user->user_email );
		$this->assertTrue( $out['items_removed'] );
		$this->assertFalse( $out['items_retained'] );
		$this->assertTrue( $out['done'] );
		$this->assertNull( Identities::find_by_wp_user( $user->ID ) );
		$this->assertSame( array(), Results::passed_conditions( 'u:' . $user->ID, 0, true ) );
		$this->assertFalse( Privacy::erase( $user->user_email )['items_removed'] );
	}

	public function test_policy_text_and_hook(): void {
		$this->assertSame( 10, has_action( 'admin_init', array( \VerifyBlind\Privacy::class, 'policy_content' ) ) );
		$text = Privacy::policy_text();
		$this->assertStringContainsString( 'href="https://verifyblind.com/en/dpa"', $text );
		$this->assertStringContainsString( 'VerifyBlind', $text );
		$this->assertSame( $text, wp_kses_post( $text ), 'the text is plain safe HTML' );
	}
}
