<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Nonces;
use VerifyBlind\Results;

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
		// A tampering browser asks for "1+" — it must not reach VerifyBlind.
		$res = $this->post( 'generate', array( 'public_key' => 'PK', 'validations' => array( 'age' => '1+' ), 'cf_token' => 'CF', 'sdk_version' => '1.0.1', 'additional_data' => 'x' ), array( 'rule' => $rule['id'] ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'nonce' => 'srv-nonce-1' ), $res->get_data() );
		$this->assertSame( array( 'public_key' => 'PK', 'validations' => array( 'age' => '18+' ), 'cf_token' => 'CF', 'sdk_version' => '1.0.1' ), $sent );
		$owner = \VerifyBlind\Owner::current( false );
		$this->assertNotNull( $owner );
		$this->assertSame( '18+', Nonces::consume( 'srv-nonce-1', $owner )['age_cond'] );
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
		$res = $this->post( 'verify', array( 'token' => $signer->token( array( 'nonce' => 'e2e', 'validations' => array( 'age' => true ) ) ) ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['passed'] );
		$this->assertSame( array( '18+' ), Results::passed_conditions( $owner, 0, false ) );
		$again = $this->post( 'verify', array( 'token' => $signer->token( array( 'nonce' => 'e2e', 'validations' => array( 'age' => true ) ) ) ) );
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
		$ok = $this->post( 'revoke', array(), array(), array( 'x-webhook-signature' => $signer->sign( $ts . '.' . $raw ), 'x-webhook-timestamp' => $ts ), $raw );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( array(), Results::passed_conditions( 'u:77', 0, false ) );
	}
}
