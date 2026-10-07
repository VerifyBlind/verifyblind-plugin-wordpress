<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

final class Rest {
	const NS       = 'verifyblind/v1';
	const RATE_KEY = 'verifyblind_generate_rate';
	const CAP_HITS_OPTION = 'verifyblind_cap_hits';

	public static function register(): void {
		register_rest_route( self::NS, '/generate', array( 'methods' => 'POST', 'callback' => array( self::class, 'generate' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NS, '/verify', array( 'methods' => 'POST', 'callback' => array( self::class, 'verify' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NS, '/revoke', array( 'methods' => 'POST', 'callback' => array( self::class, 'revoke' ), 'permission_callback' => '__return_true' ) );
	}

	public static function generate( \WP_REST_Request $req ): \WP_REST_Response {
		$rule = Rules::get( (string) $req->get_param( 'rule' ) );
		if ( ! $rule || empty( $rule['enabled'] ) ) {
			return self::error( 404, 'rule_not_found' );
		}
		if ( '' === ApiClient::api_key() ) {
			return self::error( 503, 'not_configured' );
		}
		// One-person checks bind a person to an account: guests log in first - except on the sign-up form.
		if ( ! empty( $rule['unique'] ) && ! is_user_logged_in() && 'registration' !== $rule['placement'] ) {
			return self::error( 401, 'login_required' );
		}
		$json       = $req->get_json_params();
		$json       = is_array( $json ) ? $json : array();
		$public_key = isset( $json['public_key'] ) && is_string( $json['public_key'] ) ? $json['public_key'] : '';
		if ( '' === $public_key ) {
			return self::error( 400, 'bad_request' );
		}
		// What is asked is decided HERE from the rule, never by the browser: the signed result only says
		// age true/false, not which condition was asked.
		$validations = array();
		if ( '' !== $rule['age'] ) {
			$validations['age'] = $rule['age'];
		}
		if ( ! empty( $rule['unique'] ) ) {
			$validations['user_id'] = true;
		}
		$forward = array( 'public_key' => $public_key, 'validations' => $validations );
		foreach ( array( 'cf_token', 'sdk_version' ) as $k ) {
			if ( isset( $json[ $k ] ) && is_string( $json[ $k ] ) ) {
				$forward[ $k ] = $json[ $k ];
			}
		}
		// VerifyBlind checks the bot token only when one is sent, so the site enforces that it is sent.
		if ( Settings::captcha() && ( ! isset( $forward['cf_token'] ) || 1 !== preg_match( '/^[\x21-\x7E]{1,4096}$/', $forward['cf_token'] ) ) ) {
			return self::error( 400, 'captcha_required' );
		}
		if ( ! self::take_generate_slot( time() ) ) {
			$res = self::error( 429, 'rate_limited' );
			$res->header( 'Retry-After', '60' );
			return $res;
		}
		try {
			$up = Plugin::api()->generate( $forward, (string) $req->get_header( 'accept-language' ) );
		} catch ( \RuntimeException $e ) {
			return self::error( 502, 'api_unreachable' );
		}
		$body = json_decode( $up['body'], true );
		ApiErrors::record( (int) $up['status'], is_array( $body ) && isset( $body['code'] ) && is_string( $body['code'] ) ? $body['code'] : '' );
		if ( 200 === $up['status'] ) {
			if ( ! is_array( $body ) || ! isset( $body['nonce'] ) || ! is_string( $body['nonce'] ) || '' === $body['nonce'] ) {
				return self::error( 502, 'api_unreachable' );
			}
			// A guest gets an owner cookie only for a session that really started.
			$owner = Owner::current( true );
			Nonces::put( $body['nonce'], $rule['id'], $rule['age'], ! empty( $rule['unique'] ), (string) $owner, 960 );
		}
		$res = new \WP_REST_Response( is_array( $body ) ? $body : array( 'error' => Messages::get( 'api_unreachable' ) ), $up['status'] );
		if ( null !== $up['retry_after'] ) {
			$res->header( 'Retry-After', $up['retry_after'] );
		}
		return $res;
	}

	/**
	 * Site-wide soft cap on sessions started per minute, so a script cannot burn the site's VerifyBlind
	 * rate limit and session quota. Deliberately not per visitor: no visitor IP address is stored anywhere.
	 * The filter `verifyblind_generate_per_minute` changes the cap (default 30; 0 or less turns it off).
	 * One transient holds the current minute and its count (not one row per minute).
	 */
	public static function take_generate_slot( int $now ): bool {
		$limit = (int) apply_filters( 'verifyblind_generate_per_minute', 30 );
		if ( $limit <= 0 ) {
			return true;
		}
		$minute = (int) floor( $now / 60 );
		$state  = get_transient( self::RATE_KEY );
		$count  = is_array( $state ) && isset( $state['m'], $state['n'] ) && (int) $state['m'] === $minute ? (int) $state['n'] : 0;
		if ( $count >= $limit ) {
			$hits = get_option( self::CAP_HITS_OPTION );
			$n    = is_array( $hits ) && isset( $hits['count'] ) ? (int) $hits['count'] : 0;
			update_option( self::CAP_HITS_OPTION, array( 'count' => $n + 1, 'last' => time() ), false ); // read by the admin notice
			return false;
		}
		set_transient( self::RATE_KEY, array( 'm' => $minute, 'n' => $count + 1 ), 2 * MINUTE_IN_SECONDS );
		return true;
	}

	public static function verify( \WP_REST_Request $req ): \WP_REST_Response {
		$owner = Owner::current( false );
		$json  = $req->get_json_params();
		$token = is_array( $json ) && isset( $json['token'] ) && is_string( $json['token'] ) ? $json['token'] : '';
		if ( null === $owner ) {
			$r = array( 'ok' => false, 'status' => 401, 'code' => 'nonce_invalid', 'passed' => false );
		} else {
			$r = Plugin::service()->verify( $token, $owner, Settings::test_mode() );
		}
		return new \WP_REST_Response(
			array( 'success' => $r['ok'], 'passed' => $r['passed'], 'code' => $r['code'], 'message' => Messages::get( $r['code'] ) ),
			$r['status']
		);
	}

	public static function revoke( \WP_REST_Request $req ): \WP_REST_Response {
		$raw = (string) $req->get_body();
		$sig = $req->get_header( 'x-webhook-signature' );
		$ts  = $req->get_header( 'x-webhook-timestamp' );
		try {
			$valid = Plugin::service()->verify_webhook( $raw, null === $sig ? null : (string) $sig, null === $ts ? null : (string) $ts );
		} catch ( \RuntimeException $e ) {
			return new \WP_REST_Response( array( 'error' => 'signing key unavailable' ), 502 );
		}
		if ( ! $valid ) {
			return new \WP_REST_Response( array( 'error' => 'invalid signature' ), 401 );
		}
		$json  = json_decode( $raw, true );
		$nonce = is_array( $json ) && isset( $json['nonce'] ) && is_string( $json['nonce'] ) ? $json['nonce'] : '';
		if ( '' === $nonce ) {
			return new \WP_REST_Response( array( 'error' => 'nonce required' ), 400 );
		}
		Plugin::service()->revoke( $nonce );
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	private static function error( int $status, string $code ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'error' => Messages::get( $code ), 'code' => $code ), $status );
	}
}
