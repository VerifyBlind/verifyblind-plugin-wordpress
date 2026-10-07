<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

use VerifyBlind\Vendor\phpseclib3\Crypt\RSA;
use VerifyBlind\Vendor\phpseclib3\Crypt\PublicKeyLoader;

/**
 * RSA-PSS, SHA-256, MGF1-SHA-256, salt length 32 — the contract for both the enclave result signature
 * and VerifyBlind webhooks. PHP's openssl_verify() cannot do PSS, hence (prefixed) phpseclib.
 */
final class SignatureVerifier {
	public static function spki_to_pem( string $spki_base64 ): string {
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( trim( $spki_base64 ), 64, "\n" ) . '-----END PUBLIC KEY-----';
	}

	/**
	 * @throws \RuntimeException when the key itself cannot be read (infrastructure error, not a rejection).
	 */
	public static function verify( string $data, string $signature_b64, string $pem ): bool {
		// phpseclib would otherwise "discover" the salt length and accept salt=16 signatures.
		RSA::disableSaltLengthDiscovery();
		try {
			$key = PublicKeyLoader::loadPublicKey( $pem )
				->withPadding( RSA::SIGNATURE_PSS )
				->withHash( 'sha256' )
				->withMGFHash( 'sha256' )
				->withSaltLength( 32 );
		} catch ( \Throwable $e ) {
			throw new \RuntimeException( 'unreadable_public_key', 0, $e );
		}
		$signature = base64_decode( $signature_b64, true );
		if ( false === $signature || '' === $signature ) {
			return false;
		}
		try {
			return true === $key->verify( $data, $signature );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
