<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

final class ApiClient implements KeySource {
	const ENCLAVE_KEY_TTL = 60;
	const WEBHOOK_KEY_TTL = 3600;
	/** Minimum seconds between two forced (network) refreshes of the same key. */
	const FORCED_REFRESH_INTERVAL = 30;

	public static function base_url(): string {
		return defined( 'VERIFYBLIND_API_URL' ) ? rtrim( (string) VERIFYBLIND_API_URL, '/' ) : 'https://api.verifyblind.com';
	}

	public static function api_key(): string {
		return trim( (string) get_option( 'verifyblind_api_key', '' ) );
	}

	/**
	 * Decides whether a forced refresh may go to the network. Consecutive forced refreshes within
	 * FORCED_REFRESH_INTERVAL return the cached value, so bad-signature requests cannot make the
	 * site hammer the API. Without a cached value the fetch always proceeds.
	 *
	 * @param string $name   Key name used in transient names.
	 * @param bool   $refresh Whether the caller asked for a forced refresh.
	 * @param bool   $bypass  Skip the throttle (admin-triggered).
	 * @return string Cached value to return, or '' when the network must be used.
	 */
	private function cached_or_throttled( string $name, bool $refresh, bool $bypass ): string {
		$cached = get_transient( 'verifyblind_' . $name );
		$cached = ( is_string( $cached ) && '' !== $cached ) ? $cached : '';
		if ( ! $refresh ) {
			return $cached;
		}
		if ( ! $bypass && '' !== $cached && false !== get_transient( 'verifyblind_' . $name . '_refreshed' ) ) {
			return $cached;
		}
		set_transient( 'verifyblind_' . $name . '_refreshed', 1, self::FORCED_REFRESH_INTERVAL );
		return '';
	}

	public function enclave_key_spki( bool $refresh = false, bool $bypass_throttle = false ): string {
		$cached = $this->cached_or_throttled( 'enclave_key', $refresh, $bypass_throttle );
		if ( '' !== $cached ) {
			return $cached;
		}
		$res = wp_remote_get( self::base_url() . '/api/public/enclave-key', array( 'timeout' => 5 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			throw new \RuntimeException( 'enclave_key_unavailable' );
		}
		$spki = trim( (string) wp_remote_retrieve_body( $res ) );
		if ( '' === $spki ) {
			throw new \RuntimeException( 'enclave_key_unavailable' );
		}
		set_transient( 'verifyblind_enclave_key', $spki, self::ENCLAVE_KEY_TTL );
		return $spki;
	}

	public function enclave_key( bool $refresh = false ): string {
		return SignatureVerifier::spki_to_pem( $this->enclave_key_spki( $refresh ) );
	}

	public function webhook_key( bool $refresh = false ): string {
		$cached = $this->cached_or_throttled( 'webhook_key', $refresh, false );
		if ( '' !== $cached ) {
			return $cached;
		}
		$res = wp_remote_get( self::base_url() . '/api/public/webhook-signing-key', array( 'timeout' => 5 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			throw new \RuntimeException( 'webhook_key_unavailable' );
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$pem  = is_array( $json ) && isset( $json['public_key'] ) && is_string( $json['public_key'] ) ? $json['public_key'] : '';
		if ( '' === $pem ) {
			throw new \RuntimeException( 'webhook_key_unavailable' );
		}
		set_transient( 'verifyblind_webhook_key', $pem, self::WEBHOOK_KEY_TTL );
		return $pem;
	}

	/**
	 * @return array{status:int, body:string, retry_after:?string}
	 * @throws \RuntimeException when VerifyBlind cannot be reached.
	 */
	public function generate( array $body, string $accept_language ): array {
		$res = wp_remote_post(
			self::base_url() . '/api/pop/generate',
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type'         => 'application/json',
					'X-API-Key'            => self::api_key(),
					'Accept-Language'      => '' !== $accept_language ? $accept_language : 'tr',
					'X-VerifyBlind-Client' => 'wordpress/' . VERIFYBLIND_VERSION,
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			throw new \RuntimeException( 'api_unreachable' );
		}
		$retry = wp_remote_retrieve_header( $res, 'retry-after' );
		$retry = is_array( $retry ) ? reset( $retry ) : $retry;
		return array(
			'status'      => (int) wp_remote_retrieve_response_code( $res ),
			'body'        => (string) wp_remote_retrieve_body( $res ),
			'retry_after' => ( is_string( $retry ) && '' !== $retry ) ? $retry : null,
		);
	}

	/**
	 * Opens a real (unused, self-expiring) session with the enclave's own public key as a stand-in
	 * public key: the cheapest call that proves the API key works. Unused sessions are not billed.
	 *
	 * @return array{ok:bool, message:string}
	 */
	public function test_connection(): array {
		if ( '' === self::api_key() ) {
			return array( 'ok' => false, 'message' => __( 'Enter your API key first.', 'verifyblind' ) );
		}
		try {
			$up = $this->generate( array( 'public_key' => $this->enclave_key_spki( true, true ), 'validations' => array( 'age' => '18+' ) ), 'en' );
		} catch ( \RuntimeException $e ) {
			return array( 'ok' => false, 'message' => __( 'VerifyBlind could not be reached from this server.', 'verifyblind' ) );
		}
		switch ( $up['status'] ) {
			case 200:
				return array( 'ok' => true, 'message' => __( 'Connected. Your API key works.', 'verifyblind' ) );
			case 401:
			case 402:
			case 403:
			case 426:
				return array( 'ok' => false, 'message' => ApiErrors::text( (int) $up['status'] ) );
			default:
				/* translators: %d: HTTP status code */
				return array( 'ok' => false, 'message' => sprintf( __( 'Unexpected response from VerifyBlind (HTTP %d).', 'verifyblind' ), $up['status'] ) );
		}
	}
}
