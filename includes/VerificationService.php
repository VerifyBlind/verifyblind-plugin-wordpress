<?php
namespace VerifyBlind;

final class VerificationService {
	/** @var KeySource */
	private $keys;

	public function __construct( KeySource $keys ) {
		$this->keys = $keys;
	}

	public function verify( string $token, string $owner, bool $test_mode ): array {
		$decoded = base64_decode( $token, true );
		$signed  = false === $decoded ? null : json_decode( $decoded, true );
		if ( ! is_array( $signed ) || ! isset( $signed['payload'], $signed['signature'] ) || ! is_string( $signed['payload'] ) || ! is_string( $signed['signature'] ) ) {
			return self::fail( 400, 'bad_token' );
		}
		try {
			$valid = $this->signature_ok( $signed['payload'], $signed['signature'] );
		} catch ( \RuntimeException $e ) {
			return self::fail( 503, 'key_unavailable' );
		}
		if ( ! $valid ) {
			return self::fail( 401, 'bad_signature' );
		}
		$data  = json_decode( $signed['payload'], true );
		$nonce = is_array( $data ) && isset( $data['nonce'] ) && is_string( $data['nonce'] ) ? $data['nonce'] : '';
		if ( '' === $nonce ) {
			return self::fail( 400, 'bad_token' );
		}

		// The requested condition comes from OUR stored session, never from the browser or the payload.
		$session = Nonces::consume( $nonce, $owner );
		if ( null === $session ) {
			return self::fail( 401, 'nonce_invalid' );
		}
		$v       = isset( $data['validations'] ) && is_array( $data['validations'] ) ? $data['validations'] : array();
		$is_test = isset( $v['is_test'] ) && true === $v['is_test'];
		if ( $is_test && ! $test_mode ) {
			return self::fail( 400, 'test_card' );
		}

		// Validate everything first so a partial answer writes nothing.
		$user_id = Owner::user_id( $owner );
		$person  = isset( $v['user_id'] ) && is_string( $v['user_id'] ) ? $v['user_id'] : '';
		if ( $session['want_uid'] && ( '' === $person || $user_id <= 0 ) ) {
			return self::fail( 502, 'incomplete' );
		}
		if ( '' !== $session['age_cond'] && ( ! isset( $v['age'] ) || ! is_bool( $v['age'] ) ) ) {
			return self::fail( 502, 'incomplete' );
		}

		$rule = Rules::get( $session['rule_id'] );
		if ( $session['want_uid'] ) {
			// A demo card's person code is shared by everyone: it never binds an identity.
			if ( ! $is_test ) {
				$outcome = $this->claim_identity( $person, $user_id, $v, $nonce, $rule ? $rule['duplicate_policy'] : 'reject' );
				if ( 'ok' !== $outcome ) {
					return self::fail( 409, $outcome );
				}
			}
			Results::add( $owner, 'uid', true, $nonce, $is_test );
		}
		if ( '' !== $session['age_cond'] ) {
			Results::add( $owner, $session['age_cond'], $v['age'], $nonce, $is_test );
		}
		if ( $user_id > 0 ) {
			Roles::sync_user( $user_id, $test_mode );
		}
		// Judge with the caller's test-mode flag (not the global setting) so a demo card counts only where the caller allowed it.
		$passed = null !== $rule && Evaluator::satisfies( $owner, $rule, $test_mode );
		return array( 'ok' => true, 'status' => 200, 'code' => $passed ? 'ok' : 'not_eligible', 'passed' => $passed );
	}

	/**
	 * @throws \RuntimeException when the webhook key cannot be fetched.
	 */
	public function verify_webhook( string $raw, ?string $sig, ?string $ts, ?int $now = null ): bool {
		if ( null === $sig || '' === $sig || null === $ts || ! ctype_digit( $ts ) ) {
			return false;
		}
		$now = null === $now ? time() : $now;
		if ( abs( $now - (int) $ts ) > 300 ) {
			return false;
		}
		$data = $ts . '.' . $raw;
		if ( SignatureVerifier::verify( $data, $sig, $this->keys->webhook_key() ) ) {
			return true;
		}
		return SignatureVerifier::verify( $data, $sig, $this->keys->webhook_key( true ) );
	}

	public function revoke( string $nonce ): void {
		$users = array();
		foreach ( Identities::delete_by_nonce( $nonce ) as $u ) {
			Results::delete_cond( Owner::for_user( $u ), 'uid' ); // no person code left -> no one-person pass
			$users[] = $u;
		}
		foreach ( Results::delete_by_nonce( $nonce ) as $owner ) {
			$u = Owner::user_id( $owner );
			if ( $u > 0 ) {
				$users[] = $u;
			}
		}
		foreach ( array_unique( $users ) as $u ) {
			delete_user_meta( (int) $u, 'verifyblind_duplicate_of' );
			delete_user_meta( (int) $u, 'verifyblind_flag_person' );
			Roles::sync_user( (int) $u );
		}
	}

	private function signature_ok( string $payload, string $signature ): bool {
		if ( SignatureVerifier::verify( $payload, $signature, $this->keys->enclave_key() ) ) {
			return true;
		}
		// The enclave key rotates on enclave restart: refresh once and retry.
		return SignatureVerifier::verify( $payload, $signature, $this->keys->enclave_key( true ) );
	}

	private function claim_identity( string $person, int $user_id, array $v, string $nonce, string $policy ): string {
		$mine = Identities::find_by_wp_user( $user_id );
		if ( $mine ) {
			return $mine['vb_user_id'] === $person ? 'ok' : 'different_identity';
		}
		// A flag-accepted duplicate has no identity row, but the account still may not switch person later.
		$flagged = get_user_meta( $user_id, 'verifyblind_flag_person', true );
		if ( is_string( $flagged ) && '' !== $flagged && $flagged !== $person ) {
			return 'different_identity';
		}
		$nsbd = isset( $v['nsbd_id'] ) && is_string( $v['nsbd_id'] ) ? $v['nsbd_id'] : null;
		$doc  = isset( $v['doc_id'] ) && is_string( $v['doc_id'] ) ? $v['doc_id'] : null;
		if ( Identities::insert( $person, $user_id, $nsbd, $doc, $nonce ) ) {
			return 'ok';
		}
		$existing = Identities::find_by_vb_user_id( $person );
		if ( ! $existing ) {
			return 'duplicate';
		}
		$other = (int) $existing['wp_user_id'];
		switch ( $policy ) {
			case 'flag':
				update_user_meta( $user_id, 'verifyblind_duplicate_of', $other );
				update_user_meta( $user_id, 'verifyblind_flag_person', $person );
				return 'ok';
			case 'transfer':
				Identities::move( $person, $user_id, $nonce, $nsbd, $doc );
				Results::delete_cond( Owner::for_user( $other ), 'uid' );
				Roles::sync_user( $other );
				return 'ok';
			default: // reject, block: the gated action stays closed
				return 'duplicate';
		}
	}

	private static function fail( int $status, string $code ): array {
		return array( 'ok' => false, 'status' => $status, 'code' => $code, 'passed' => false );
	}
}
