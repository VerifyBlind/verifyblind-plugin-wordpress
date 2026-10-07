<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\KeySource;
use VerifyBlind\Vendor\phpseclib3\Crypt\PublicKeyLoader;
use VerifyBlind\Vendor\phpseclib3\Crypt\RSA;

/** Plays the enclave and the webhook signer with a throwaway RSA key (same PSS contract). */
final class Signer implements KeySource {
	private static $private;
	private static $public;

	public function __construct() {
		if ( null === self::$private ) {
			$res = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
			openssl_pkey_export( $res, $pem );
			self::$private = $pem;
			self::$public  = openssl_pkey_get_details( $res )['key'];
		}
	}

	public function enclave_key( bool $refresh = false ): string {
		return self::$public;
	}

	public function webhook_key( bool $refresh = false ): string {
		return self::$public;
	}

	public function sign( string $data ): string {
		$key = PublicKeyLoader::load( self::$private )
			->withPadding( RSA::SIGNATURE_PSS )->withHash( 'sha256' )->withMGFHash( 'sha256' )->withSaltLength( 32 );
		return base64_encode( $key->sign( $data ) );
	}

	public function token( array $payload ): string {
		$p = wp_json_encode( $payload );
		return base64_encode( wp_json_encode( array( 'payload' => $p, 'signature' => $this->sign( $p ) ) ) );
	}
}
