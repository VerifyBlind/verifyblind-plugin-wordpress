<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

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
		// The enclave always signs WHICH age condition it answered. It must be the one this site asked
		// (stored with the session), so nothing between us and the enclave can swap "18+" for "1+".
		// Any age answer must carry the condition this site asked. A missing `age` is handled below with its own code.
		$answered_age = array_key_exists( 'age', $v ) || array_key_exists( 'age_condition', $v );
		if ( $answered_age && ( '' === $session['age_cond'] || ! array_key_exists( 'age_condition', $v ) || ! self::same_condition( $v['age_condition'], $session['age_cond'] ) ) ) {
			return self::fail( 409, 'condition_mismatch' );
		}

		// Validate everything first so a partial answer writes nothing.
		$rule    = Rules::get( $session['rule_id'] );
		$user_id = Owner::user_id( $owner );
		$person  = isset( $v['user_id'] ) && is_string( $v['user_id'] ) ? $v['user_id'] : '';
		// A guest proves one person only while creating an account: the code waits for the new account.
		$signup = $user_id <= 0 && null !== $rule && 'registration' === $rule['placement'];
		if ( $session['want_uid'] && ( '' === $person || ( $user_id <= 0 && ! $signup ) ) ) {
			return self::fail( 502, 'incomplete' );
		}
		if ( '' !== $session['age_cond'] && ( ! isset( $v['age'] ) || ! is_bool( $v['age'] ) ) ) {
			return self::fail( 502, 'incomplete' );
		}

		if ( $session['want_uid'] ) {
			$policy = $rule ? $rule['duplicate_policy'] : 'reject';
			$nsbd   = isset( $v['nsbd_id'] ) && is_string( $v['nsbd_id'] ) ? $v['nsbd_id'] : null;
			$doc    = isset( $v['doc_id'] ) && is_string( $v['doc_id'] ) ? $v['doc_id'] : null;
			if ( $user_id > 0 ) {
				// A demo card's person code is shared by everyone: it never binds an identity.
				if ( ! $is_test ) {
					$outcome = IdentityClaim::claim( $person, $user_id, $nsbd, $doc, $nonce, $policy );
					if ( 'ok' !== $outcome ) {
						return self::fail( 409, $outcome );
					}
				}
			} else {
				// Sign-up would be refused anyway: say so now. Sign-up checks again (someone may take the person meanwhile).
				if ( ! $is_test && in_array( $policy, array( 'reject', 'block' ), true ) && null !== Identities::find_by_vb_user_id( $person ) ) {
					return self::fail( 409, 'duplicate' );
				}
				PendingIdentities::put( $owner, (string) $rule['id'], $person, $nsbd, $doc, $nonce, $is_test );
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
		PendingIdentities::delete_by_nonce( $nonce );
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

	/** @param mixed $signed the signed validations.age_condition */
	private static function same_condition( $signed, string $asked ): bool {
		if ( ! is_string( $signed ) || '' === $asked ) {
			return false;
		}
		$a = AgeRule::parse( $signed );
		$b = AgeRule::parse( $asked );
		return null !== $a && null !== $b && $a->to_string() === $b->to_string();
	}

	private static function fail( int $status, string $code ): array {
		return array( 'ok' => false, 'status' => $status, 'code' => $code, 'passed' => false );
	}
}
