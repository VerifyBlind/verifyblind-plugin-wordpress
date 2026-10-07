<?php
namespace VerifyBlind\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VerifyBlind\SignatureVerifier;

final class SignatureVerifierTest extends TestCase {
	private $payload;
	private $sig;
	private $pem;

	protected function setUp(): void {
		$dir           = dirname( __DIR__ ) . '/fixtures';
		$this->payload = file_get_contents( "$dir/payload.json" );
		$this->sig     = trim( file_get_contents( "$dir/signature.b64" ) );
		$this->pem     = SignatureVerifier::spki_to_pem( file_get_contents( "$dir/enclave-pubkey.b64" ) );
	}

	public function test_accepts_valid_signature(): void {
		$this->assertTrue( SignatureVerifier::verify( $this->payload, $this->sig, $this->pem ) );
	}

	public function test_rejects_tampered_payload(): void {
		$this->assertFalse( SignatureVerifier::verify( str_replace( '18+', '21+', $this->payload ), $this->sig, $this->pem ) );
	}

	public function test_rejects_flipped_signature_bit(): void {
		$raw       = base64_decode( $this->sig );
		$raw[100]  = chr( ord( $raw[100] ) ^ 0x01 );
		$this->assertFalse( SignatureVerifier::verify( $this->payload, base64_encode( $raw ), $this->pem ) );
	}

	public function test_rejects_other_key(): void {
		$other = SignatureVerifier::spki_to_pem( file_get_contents( dirname( __DIR__ ) . '/fixtures/other-pubkey.b64' ) );
		$this->assertFalse( SignatureVerifier::verify( $this->payload, $this->sig, $other ) );
	}

	public function test_rejects_salt_16(): void {
		$salt16 = trim( file_get_contents( dirname( __DIR__ ) . '/fixtures/signature-salt16.b64' ) );
		$this->assertFalse( SignatureVerifier::verify( $this->payload, $salt16, $this->pem ) );
	}

	public function test_rejects_empty_and_non_base64(): void {
		$this->assertFalse( SignatureVerifier::verify( $this->payload, '', $this->pem ) );
		$this->assertFalse( SignatureVerifier::verify( $this->payload, '***', $this->pem ) );
	}

	public function test_unreadable_key_throws(): void {
		$this->expectException( \RuntimeException::class );
		SignatureVerifier::verify( $this->payload, $this->sig, SignatureVerifier::spki_to_pem( 'not-a-key' ) );
	}
}
