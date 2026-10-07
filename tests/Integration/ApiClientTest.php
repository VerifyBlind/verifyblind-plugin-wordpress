<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\ApiClient;

final class ApiClientTest extends TestCase {
	public function test_generate_sends_key_and_client_header(): void {
		$seen = null;
		$this->mock_http(
			function ( $url, $args ) use ( &$seen ) {
				$seen = array( $url, $args );
				return array( 'code' => 402, 'body' => '{"error":"quota"}', 'headers' => array( 'retry-after' => '30' ) );
			}
		);
		$r = ( new ApiClient() )->generate( array( 'public_key' => 'PK', 'validations' => array( 'age' => '18+' ) ), 'tr-TR' );
		$this->assertSame( array( 'status' => 402, 'body' => '{"error":"quota"}', 'retry_after' => '30' ), $r );
		$this->assertSame( 'https://api.verifyblind.com/api/pop/generate', $seen[0] );
		$this->assertSame( 'test-key', $seen[1]['headers']['X-API-Key'] );
		$this->assertSame( 'wordpress/' . VERIFYBLIND_VERSION, $seen[1]['headers']['X-VerifyBlind-Client'] );
		$this->assertSame( 'tr-TR', $seen[1]['headers']['Accept-Language'] );
		$this->assertSame( array( 'public_key' => 'PK', 'validations' => array( 'age' => '18+' ) ), json_decode( $seen[1]['body'], true ) );
	}

	public function test_enclave_key_is_cached_and_converted_to_pem(): void {
		$calls = 0;
		$this->mock_http(
			function ( $url ) use ( &$calls ) {
				if ( false === strpos( $url, '/api/public/enclave-key' ) ) {
					return null;
				}
				$calls++;
				return array( 'body' => "MIIBIjANBg\n" );
			}
		);
		$c = new ApiClient();
		$this->assertStringStartsWith( "-----BEGIN PUBLIC KEY-----\nMIIBIjANBg", $c->enclave_key() );
		$c->enclave_key();
		$this->assertSame( 1, $calls );
		$c->enclave_key( true );
		$this->assertSame( 2, $calls );
	}

	public function test_consecutive_forced_enclave_refreshes_are_rate_limited(): void {
		$calls = 0;
		$this->mock_http(
			function ( $url ) use ( &$calls ) {
				if ( false === strpos( $url, '/api/public/enclave-key' ) ) {
					return null;
				}
				$calls++;
				return array( 'body' => 'MIIBIjANBg' );
			}
		);
		$c = new ApiClient();
		$c->enclave_key();
		$c->enclave_key( true );
		$this->assertSame( 2, $calls );
		$this->assertStringContainsString( 'MIIBIjANBg', $c->enclave_key( true ) );
		$c->enclave_key_spki( true );
		$this->assertSame( 2, $calls, 'a second forced refresh within 30 s must not hit the network' );
		delete_transient( 'verifyblind_enclave_key_refreshed' );
		$c->enclave_key( true );
		$this->assertSame( 3, $calls );
	}

	public function test_consecutive_forced_webhook_refreshes_are_rate_limited(): void {
		$calls = 0;
		$this->mock_http(
			function ( $url ) use ( &$calls ) {
				if ( false === strpos( $url, 'webhook-signing-key' ) ) {
					return null;
				}
				$calls++;
				return array( 'body' => '{"public_key":"PEM"}' );
			}
		);
		$c = new ApiClient();
		$c->webhook_key();
		$c->webhook_key( true );
		$this->assertSame( 2, $calls );
		$this->assertSame( 'PEM', $c->webhook_key( true ) );
		$this->assertSame( 2, $calls );
	}

	public function test_forced_refresh_without_cache_still_fetches(): void {
		$calls = 0;
		$this->mock_http(
			function ( $url ) use ( &$calls ) {
				if ( false === strpos( $url, 'enclave-key' ) ) {
					return null;
				}
				$calls++;
				return array( 'body' => 'MIIBIjANBg' );
			}
		);
		set_transient( 'verifyblind_enclave_key_refreshed', 1, 30 );
		( new ApiClient() )->enclave_key( true );
		$this->assertSame( 1, $calls );
	}

	public function test_webhook_key_reads_json(): void {
		$this->mock_http(
			function ( $url ) {
				return false !== strpos( $url, 'webhook-signing-key' ) ? array( 'body' => '{"public_key":"-----BEGIN PUBLIC KEY-----\nABC\n-----END PUBLIC KEY-----"}' ) : null;
			}
		);
		$this->assertStringContainsString( 'ABC', ( new ApiClient() )->webhook_key() );
	}

	public function test_key_failure_throws(): void {
		$this->mock_http(
			function () {
				return array( 'code' => 500, 'body' => '' );
			}
		);
		$this->expectException( \RuntimeException::class );
		( new ApiClient() )->enclave_key();
	}

	public function test_connection_maps_401(): void {
		$this->mock_http(
			function ( $url ) {
				return false !== strpos( $url, 'enclave-key' ) ? array( 'body' => str_repeat( 'A', 120 ) ) : array( 'code' => 401, 'body' => '{}' );
			}
		);
		$r = ( new ApiClient() )->test_connection();
		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( 'API key', $r['message'] );
	}

	public function test_connection_bypasses_the_refresh_throttle(): void {
		$calls = 0;
		$this->mock_http(
			function ( $url ) use ( &$calls ) {
				if ( false !== strpos( $url, 'enclave-key' ) ) {
					$calls++;
					return array( 'body' => 'MIIBIjANBg' );
				}
				return array( 'code' => 200, 'body' => '{}' );
			}
		);
		$c = new ApiClient();
		$c->enclave_key( true );
		$c->enclave_key( true );
		$this->assertSame( 1, $calls );
		$this->assertTrue( $c->test_connection()['ok'] );
		$this->assertSame( 2, $calls );
	}
}
