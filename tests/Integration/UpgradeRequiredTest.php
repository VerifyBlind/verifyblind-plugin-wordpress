<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Admin\Notices;
use VerifyBlind\ApiErrors;
use VerifyBlind\Messages;
use VerifyBlind\Plugin;

/** VerifyBlind refuses this plugin version (426): visitors get a plain message, the admin a notice and one e-mail a day. */
final class UpgradeRequiredTest extends TestCase {
	/** @var array[] e-mails wp_mail() was asked to send */
	private $mails = array();

	protected function setUp(): void {
		parent::setUp();
		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $short, $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
	}

	protected function tearDown(): void {
		delete_option( ApiErrors::OPTION );
		delete_option( ApiErrors::MAILED );
		parent::tearDown();
	}

	private function upstream_426(): void {
		$this->mock_http(
			function ( $url ) {
				if ( false !== strpos( $url, '/api/public/enclave-key' ) ) {
					return array( 'body' => 'SPKI' );
				}
				return array( 'code' => 426, 'body' => '{"error":"Bu sitenin VerifyBlind eklentisi güncellenmeli.","code":"CLIENT_UPGRADE_REQUIRED"}' );
			}
		);
	}

	public function test_an_old_plugin_is_an_account_problem_with_its_own_text(): void {
		$this->assertTrue( ApiErrors::is_account_problem( 426, 'CLIENT_UPGRADE_REQUIRED' ) );
		$this->assertFalse( ApiErrors::is_account_problem( 426, '' ) );
		$this->assertStringContainsString( 'security update', ApiErrors::text( 426 ) );
		$this->assertStringNotContainsString( 'HTTP 426', ApiErrors::text( 426 ) );
	}

	public function test_visitor_gets_the_upgrade_message_and_the_admin_is_told(): void {
		$rule = $this->rule();
		$this->upstream_426();
		$req = new \WP_REST_Request( 'POST', '/verifyblind/v1/generate' );
		$req->set_query_params( array( 'rule' => $rule['id'] ) );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'public_key' => 'PK' ) ) );
		$res = rest_do_request( $req );

		$this->assertSame( 426, $res->get_status() );
		$this->assertSame( 'upgrade_required', $res->get_data()['code'] );
		$this->assertSame( Messages::get( 'upgrade_required' ), $res->get_data()['error'] );
		$this->assertNotSame( Messages::get( 'api_unreachable' ), Messages::get( 'upgrade_required' ) );
		$this->assertSame( 426, ApiErrors::recent()['status'] );
		$this->assertContains( 'api_error', wp_list_pluck( Notices::messages(), 'id' ) );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( ApiErrors::text( 426 ), $this->mails[0]['message'] );
	}

	public function test_connection_test_reports_the_needed_update(): void {
		$this->upstream_426();
		$r = Plugin::api()->test_connection();
		$this->assertFalse( $r['ok'] );
		$this->assertSame( ApiErrors::text( 426 ), $r['message'] );
	}
}
