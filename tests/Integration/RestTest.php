<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Nonces;
use VerifyBlind\Owner;
use VerifyBlind\Rest;
use VerifyBlind\Results;
use VerifyBlind\Schema;

final class RestTest extends TestCase {
	private function post( string $route, array $body = array(), array $query = array(), array $headers = array(), ?string $raw = null ): \WP_REST_Response {
		$req = new \WP_REST_Request( 'POST', '/verifyblind/v1/' . $route );
		$req->set_query_params( $query );
		$req->set_header( 'content-type', 'application/json' );
		foreach ( $headers as $k => $v ) {
			$req->set_header( $k, $v );
		}
		$req->set_body( null !== $raw ? $raw : wp_json_encode( $body ) );
		return rest_do_request( $req );
	}

	public function test_generate_builds_validations_on_the_server(): void {
		$rule = $this->rule( array( 'age' => '18+' ) );
		$sent = null;
		$this->mock_http(
			function ( $url, $args ) use ( &$sent ) {
				$sent = json_decode( $args['body'], true );
				return array( 'body' => '{"nonce":"srv-nonce-1"}' );
			}
		);
		// A tampering browser asks for "1+" and adds extra fields (even a bot token) — only public_key, validations and sdk_version reach VerifyBlind.
		$res = $this->post( 'generate', array( 'public_key' => 'PK', 'validations' => array( 'age' => '1+' ), 'sdk_version' => '1.0.1', 'additional_data' => 'x', 'bot_token' => 'BT' ), array( 'rule' => $rule['id'] ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'nonce' => 'srv-nonce-1' ), $res->get_data() );
		$this->assertSame( array( 'public_key' => 'PK', 'validations' => array( 'age' => '18+' ), 'sdk_version' => '1.0.1' ), $sent );
		$owner = \VerifyBlind\Owner::current( false );
		$this->assertNotNull( $owner );
		$this->assertSame( '18+', Nonces::consume( 'srv-nonce-1', $owner )['age_cond'] );
	}

	public function test_generate_forwards_exactly_public_key_validations_and_sdk_version(): void {
		$rule = $this->rule( array( 'age' => '18+' ) );
		$sent = array();
		$this->mock_http(
			function ( $url, $args ) use ( &$sent ) {
				$sent[] = json_decode( $args['body'], true );
				return array( 'body' => '{"nonce":"fwd-' . count( $sent ) . '"}' );
			}
		);
		$this->assertSame( 200, $this->post( 'generate', array( 'public_key' => 'PK', 'sdk_version' => '1.0.1', 'bot_token' => 'BT', 'extra' => 'x' ), array( 'rule' => $rule['id'] ) )->get_status() );
		$this->assertSame( 200, $this->post( 'generate', array( 'public_key' => 'PK', 'bot_token' => 'BT' ), array( 'rule' => $rule['id'] ) )->get_status() );
		$this->assertSame( array( 'public_key', 'sdk_version', 'validations' ), $this->sorted_keys( $sent[0] ) );
		$this->assertSame( array( 'public_key', 'validations' ), $this->sorted_keys( $sent[1] ) );
	}

	private function sorted_keys( array $a ): array {
		$k = array_keys( $a );
		sort( $k );
		return $k;
	}

	public function test_generate_passes_upstream_errors_through(): void {
		$rule = $this->rule();
		$this->mock_http(
			function () {
				return array( 'code' => 429, 'body' => '{"error":"slow down"}', 'headers' => array( 'retry-after' => '12' ) );
			}
		);
		$res = $this->post( 'generate', array( 'public_key' => 'PK' ), array( 'rule' => $rule['id'] ) );
		$this->assertSame( 429, $res->get_status() );
		$this->assertSame( '12', $res->get_headers()['Retry-After'] );
		$this->assertSame( 'slow down', $res->get_data()['error'] );
		$this->assertNull( Owner::current( false ), 'no guest cookie for a session that did not start' );
		$this->assertSame( 0, $this->nonce_rows() );
	}

	public function test_generate_has_a_site_wide_per_minute_cap(): void {
		$this->away_from_minute_edge();
		$rule  = $this->rule();
		$calls = 0;
		$this->mock_http(
			function () use ( &$calls ) {
				++$calls;
				return array( 'body' => '{"nonce":"cap-' . $calls . '"}' );
			}
		);
		add_filter( 'verifyblind_generate_per_minute', function () {
			return 2;
		} );
		$this->assertSame( 200, $this->post( 'generate', array( 'public_key' => 'PK' ), array( 'rule' => $rule['id'] ) )->get_status() );
		$this->assertSame( 200, $this->post( 'generate', array( 'public_key' => 'PK' ), array( 'rule' => $rule['id'] ) )->get_status() );
		$res = $this->post( 'generate', array( 'public_key' => 'PK' ), array( 'rule' => $rule['id'] ) );
		$this->assertSame( 429, $res->get_status() );
		$this->assertSame( 'rate_limited', $res->get_data()['code'] );
		$this->assertSame( 'Too many verification attempts right now. Please try again in a minute.', $res->get_data()['error'] );
		$this->assertSame( '60', $res->get_headers()['Retry-After'] );
		$this->assertSame( 2, $calls );
		$hits = get_option( 'verifyblind_cap_hits' );
		$this->assertSame( 1, $hits['count'] );
		$this->assertEqualsWithDelta( time(), $hits['last'], 5 );
		$this->post( 'generate', array( 'public_key' => 'PK' ), array( 'rule' => $rule['id'] ) );
		$this->assertSame( 2, get_option( 'verifyblind_cap_hits' )['count'] );
	}

	public function test_generate_cap_defaults_to_30_and_counts_failed_upstream_attempts(): void {
		$this->away_from_minute_edge();
		$rule = $this->rule();
		$this->mock_http(
			function () {
				return array( 'code' => 503, 'body' => '{"error":"down"}' );
			}
		);
		for ( $i = 0; $i < 30; $i++ ) {
			$this->assertSame( 503, $this->post( 'generate', array( 'public_key' => 'PK' ), array( 'rule' => $rule['id'] ) )->get_status() );
		}
		$this->assertSame( 429, $this->post( 'generate', array( 'public_key' => 'PK' ), array( 'rule' => $rule['id'] ) )->get_status() );
		// Requests rejected before VerifyBlind is called do not use up the cap.
		$this->assertSame( 400, $this->post( 'generate', array(), array( 'rule' => $rule['id'] ) )->get_status() );
	}

	public function test_generate_slot_resets_every_minute(): void {
		add_filter( 'verifyblind_generate_per_minute', function () {
			return 1;
		} );
		$this->assertTrue( Rest::take_generate_slot( 6000 ) );
		$this->assertFalse( Rest::take_generate_slot( 6059 ) );
		$this->assertTrue( Rest::take_generate_slot( 6060 ) );
		remove_all_filters( 'verifyblind_generate_per_minute' );
		add_filter( 'verifyblind_generate_per_minute', '__return_zero' );
		$this->assertTrue( Rest::take_generate_slot( 6060 ), '0 turns the cap off' );
	}

	public function test_generate_upstream_200_without_a_nonce_is_an_error(): void {
		$rule = $this->rule();
		foreach ( array( '{"foo":1}', 'not json', '{"nonce":""}', '{"nonce":5}' ) as $body ) {
			remove_all_filters( 'pre_http_request' );
			$this->mock_http(
				function () use ( $body ) {
					return array( 'body' => $body );
				}
			);
			$res = $this->post( 'generate', array( 'public_key' => 'PK' ), array( 'rule' => $rule['id'] ) );
			$this->assertSame( 502, $res->get_status(), $body );
			$this->assertSame( 'api_unreachable', $res->get_data()['code'] );
		}
		$this->assertSame( 0, $this->nonce_rows() );
		$this->assertNull( Owner::current( false ) );
	}

	public function test_generate_binds_a_logged_in_user_without_a_guest_cookie(): void {
		$uid = $this->make_user();
		wp_set_current_user( $uid );
		$rule = $this->rule();
		$this->mock_http(
			function () {
				return array( 'body' => '{"nonce":"user-n"}' );
			}
		);
		$this->assertSame( 200, $this->post( 'generate', array( 'public_key' => 'PK' ), array( 'rule' => $rule['id'] ) )->get_status() );
		$this->assertArrayNotHasKey( Owner::COOKIE, $_COOKIE );
		$this->assertNotNull( Nonces::consume( 'user-n', 'u:' . $uid ) );
	}

	private function nonce_rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'nonces' ) );
	}

	/** The cap counts per clock minute: do not start a counting test in the last seconds of one. */
	private function away_from_minute_edge(): void {
		$s = time() % 60;
		if ( $s >= 55 ) {
			sleep( 61 - $s );
		}
	}

	public function test_generate_guards(): void {
		$this->assertSame( 404, $this->post( 'generate', array( 'public_key' => 'PK' ), array( 'rule' => 'r_ffffffff' ) )->get_status() );
		$unique = $this->rule( array( 'age' => '', 'unique' => true ) );
		$this->assertSame( 401, $this->post( 'generate', array( 'public_key' => 'PK' ), array( 'rule' => $unique['id'] ) )->get_status() );
		$age = $this->rule();
		$this->assertSame( 400, $this->post( 'generate', array(), array( 'rule' => $age['id'] ) )->get_status() );
		update_option( 'verifyblind_api_key', '' );
		$this->assertSame( 503, $this->post( 'generate', array( 'public_key' => 'PK' ), array( 'rule' => $age['id'] ) )->get_status() );
	}

	public function test_verify_end_to_end(): void {
		$signer = new Signer();
		add_filter( 'verifyblind_key_source', function () use ( $signer ) {
			return $signer;
		} );
		$rule  = $this->rule();
		$owner = \VerifyBlind\Owner::current( true );
		Nonces::put( 'e2e', $rule['id'], '18+', false, $owner, 960 );
		$res = $this->post( 'verify', array( 'token' => $signer->token( array( 'nonce' => 'e2e', 'validations' => array( 'age' => true, 'age_condition' => '18+' ) ) ) ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['passed'] );
		$this->assertSame( array( '18+' ), Results::passed_conditions( $owner, 0, false ) );
		$again = $this->post( 'verify', array( 'token' => $signer->token( array( 'nonce' => 'e2e', 'validations' => array( 'age' => true, 'age_condition' => '18+' ) ) ) ) );
		$this->assertSame( 401, $again->get_status() );
		$this->assertSame( 'nonce_invalid', $again->get_data()['code'] );
	}

	public function test_revoke_requires_valid_signature(): void {
		$signer = new Signer();
		add_filter( 'verifyblind_key_source', function () use ( $signer ) {
			return $signer;
		} );
		Results::add( 'u:77', '18+', true, 'rv-1', false );
		$raw = '{"event_type":"DATA_ERASURE","nonce":"rv-1","request_id":"q"}';
		$ts  = (string) time();
		$this->assertSame( 401, $this->post( 'revoke', array(), array(), array( 'x-webhook-signature' => 'AAAA', 'x-webhook-timestamp' => $ts ), $raw )->get_status() );
		$this->assertSame( array( '18+' ), Results::passed_conditions( 'u:77', 0, false ), 'a bad signature deletes nothing' );
		$ok = $this->post( 'revoke', array(), array(), array( 'x-webhook-signature' => $signer->sign( $ts . '.' . $raw ), 'x-webhook-timestamp' => $ts ), $raw );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( array(), Results::passed_conditions( 'u:77', 0, false ) );
	}

	public function test_revoke_rejects_missing_headers_and_stale_timestamps(): void {
		$signer = new Signer();
		add_filter( 'verifyblind_key_source', function () use ( $signer ) {
			return $signer;
		} );
		Results::add( 'u:78', '18+', true, 'rv-2', false );
		$raw   = '{"event_type":"DATA_ERASURE","nonce":"rv-2","request_id":"q"}';
		$ts    = (string) time();
		$stale = (string) ( time() - 301 );
		$cases = array(
			'missing signature' => array( 'x-webhook-timestamp' => $ts ),
			'missing timestamp' => array( 'x-webhook-signature' => $signer->sign( $ts . '.' . $raw ) ),
			'stale timestamp'   => array( 'x-webhook-signature' => $signer->sign( $stale . '.' . $raw ), 'x-webhook-timestamp' => $stale ),
		);
		foreach ( $cases as $name => $headers ) {
			$this->assertSame( 401, $this->post( 'revoke', array(), array(), $headers, $raw )->get_status(), $name );
			$this->assertSame( array( '18+' ), Results::passed_conditions( 'u:78', 0, false ), $name . ' deletes nothing' );
		}
	}
}
