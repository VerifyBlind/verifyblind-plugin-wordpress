<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Admin\Notices;
use VerifyBlind\ApiErrors;
use VerifyBlind\Rest;

final class NoticesTest extends TestCase {
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
		remove_all_filters( 'wp_redirect' );
		$_GET     = array();
		$_REQUEST = array();
		delete_option( ApiErrors::OPTION );
		delete_option( ApiErrors::MAILED );
		delete_option( Rest::CAP_HITS_OPTION );
		parent::tearDown();
	}

	private function ids(): array {
		return wp_list_pluck( Notices::messages(), 'id' );
	}

	private function dismiss( string $id ): void {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		$_GET     = array( 'notice' => $id, '_wpnonce' => wp_create_nonce( Notices::ACTION ) );
		$_REQUEST = $_GET;
		add_filter(
			'wp_redirect',
			function ( $location ) {
				throw new \RuntimeException( (string) $location );
			}
		);
		try {
			Notices::dismiss();
			$this->fail( 'dismiss() should redirect' );
		} catch ( \RuntimeException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}
		remove_all_filters( 'wp_redirect' );
	}

	public function test_missing_api_key(): void {
		update_option( 'verifyblind_api_key', '' );
		$this->assertContains( 'api_key', $this->ids() );
		update_option( 'verifyblind_api_key', 'k' );
		$this->assertNotContains( 'api_key', $this->ids() );
	}

	public function test_cap_hits_show_until_dismissed_and_dismissal_resets_them(): void {
		$this->assertNotContains( 'cap', $this->ids() );
		update_option( Rest::CAP_HITS_OPTION, array( 'count' => 3, 'last' => time() ) );
		$this->assertContains( 'cap', $this->ids() );
		$this->dismiss( 'cap' );
		$this->assertNotContains( 'cap', $this->ids() );
		$this->assertFalse( get_option( Rest::CAP_HITS_OPTION ) );
	}

	public function test_account_errors_raise_a_notice_and_one_mail_a_day_per_status(): void {
		$now = time();
		ApiErrors::record( 402, 'INSUFFICIENT_BALANCE', $now );
		ApiErrors::record( 402, 'INSUFFICIENT_BALANCE', $now + 60 );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( get_option( 'admin_email' ), $this->mails[0]['to'] );
		$this->assertStringContainsString( ApiErrors::text( 402 ), $this->mails[0]['message'] );
		ApiErrors::record( 401, 'API_KEY_INVALID', $now + 120 );
		$this->assertCount( 2, $this->mails, 'another status mails on its own' );
		ApiErrors::record( 402, 'INSUFFICIENT_BALANCE', $now + DAY_IN_SECONDS + 1 );
		$this->assertCount( 3, $this->mails, 'a day later the same status mails again' );
		$this->assertContains( 'api_error', $this->ids() );
		$this->dismiss( 'api_error' );
		$this->assertNotContains( 'api_error', $this->ids() );
	}

	public function test_a_failed_bot_check_is_not_an_account_error(): void {
		ApiErrors::record( 403, 'CAPTCHA_FAILED' );
		ApiErrors::record( 429, '' );
		$this->assertCount( 0, $this->mails );
		$this->assertNull( ApiErrors::recent() );
		ApiErrors::record( 403, 'EMAIL_NOT_VERIFIED' );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 403, ApiErrors::recent()['status'] );
	}

	public function test_generate_records_upstream_account_errors(): void {
		update_option( 'verifyblind_captcha', '0' );
		$rule = $this->rule();
		$this->mock_http(
			function () {
				return array( 'code' => 402, 'body' => '{"error":"no balance","code":"INSUFFICIENT_BALANCE"}' );
			}
		);
		$req = new \WP_REST_Request( 'POST', '/verifyblind/v1/generate' );
		$req->set_query_params( array( 'rule' => $rule['id'] ) );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'public_key' => 'PK' ) ) );
		$this->assertSame( 402, rest_do_request( $req )->get_status() );
		$this->assertSame( 402, ApiErrors::recent()['status'] );
		$this->assertCount( 1, $this->mails );
	}

	public function test_notices_render_only_on_plugin_screens_for_admins(): void {
		update_option( 'verifyblind_api_key', '' );
		wp_set_current_user( $this->make_user( 'administrator' ) );
		$_GET['page'] = 'wc-settings';
		ob_start();
		Notices::render();
		$this->assertSame( '', ob_get_clean() );
		$_GET['page'] = 'verifyblind-settings';
		ob_start();
		Notices::render();
		$this->assertStringContainsString( 'notice-warning', (string) ob_get_clean() );
		wp_set_current_user( $this->make_user( 'editor' ) );
		ob_start();
		Notices::render();
		$this->assertSame( '', ob_get_clean() );
	}
}
