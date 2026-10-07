<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Identities;
use VerifyBlind\Nonces;
use VerifyBlind\Results;
use VerifyBlind\Roles;
use VerifyBlind\VerificationService;

final class VerificationServiceTest extends TestCase {
	/** @var Signer */
	private $signer;
	/** @var VerificationService */
	private $svc;

	protected function setUp(): void {
		parent::setUp();
		$this->signer = new Signer();
		$this->svc    = new VerificationService( $this->signer );
	}

	private function session( array $rule, string $owner, string $nonce = 'n-1' ): string {
		Nonces::put( $nonce, $rule['id'], $rule['age'], $rule['unique'], $owner, 960 );
		return $nonce;
	}

	public function test_age_pass_is_written_and_satisfies_rule(): void {
		$rule  = $this->rule( array( 'age' => '18+' ) );
		$owner = 'g:' . str_repeat( 'b', 32 );
		$nonce = $this->session( $rule, $owner );
		$r     = $this->svc->verify( $this->signer->token( array( 'nonce' => $nonce, 'validations' => array( 'age' => true ) ) ), $owner, false );
		$this->assertSame( array( 'ok' => true, 'status' => 200, 'code' => 'ok', 'passed' => true ), $r );
		$this->assertSame( array( '18+' ), Results::passed_conditions( $owner, 0, false ) );
	}

	public function test_age_fail_is_recorded_as_not_eligible(): void {
		$rule  = $this->rule( array( 'age' => '21+' ) );
		$owner = 'g:' . str_repeat( 'c', 32 );
		$nonce = $this->session( $rule, $owner );
		$r     = $this->svc->verify( $this->signer->token( array( 'nonce' => $nonce, 'validations' => array( 'age' => false ) ) ), $owner, false );
		$this->assertSame( 'not_eligible', $r['code'] );
		$this->assertFalse( $r['passed'] );
	}

	public function test_nonce_is_single_use_and_owner_bound(): void {
		$rule  = $this->rule();
		$nonce = $this->session( $rule, 'g:' . str_repeat( 'd', 32 ) );
		$token = $this->signer->token( array( 'nonce' => $nonce, 'validations' => array( 'age' => true ) ) );
		$this->assertSame( 'nonce_invalid', $this->svc->verify( $token, 'g:' . str_repeat( 'e', 32 ), false )['code'] );
		$this->assertSame( 'ok', $this->svc->verify( $token, 'g:' . str_repeat( 'd', 32 ), false )['code'] );
		$this->assertSame( 'nonce_invalid', $this->svc->verify( $token, 'g:' . str_repeat( 'd', 32 ), false )['code'] );
	}

	public function test_bad_tokens(): void {
		$this->assertSame( 'bad_token', $this->svc->verify( '%%%', 'u:1', false )['code'] );
		$good    = json_decode( base64_decode( $this->signer->token( array( 'nonce' => 'x', 'validations' => array() ) ) ), true );
		$forged  = base64_encode( wp_json_encode( array( 'payload' => str_replace( 'x', 'y', $good['payload'] ), 'signature' => $good['signature'] ) ) );
		$this->assertSame( 'bad_signature', $this->svc->verify( $forged, 'u:1', false )['code'] );
	}

	public function test_demo_card_needs_test_mode(): void {
		$rule  = $this->rule();
		$owner = 'g:' . str_repeat( 'f', 32 );
		$v     = array( 'age' => true, 'is_test' => true );
		$this->assertSame( 'test_card', $this->svc->verify( $this->signer->token( array( 'nonce' => $this->session( $rule, $owner, 'a1' ), 'validations' => $v ) ), $owner, false )['code'] );
		$this->assertSame( 'ok', $this->svc->verify( $this->signer->token( array( 'nonce' => $this->session( $rule, $owner, 'a2' ), 'validations' => $v ) ), $owner, true )['code'] );
	}

	public function test_missing_requested_field_writes_nothing(): void {
		$rule  = $this->rule( array( 'age' => '18+', 'unique' => true ) );
		$uid   = $this->make_user();
		$owner = 'u:' . $uid;
		$r     = $this->svc->verify( $this->signer->token( array( 'nonce' => $this->session( $rule, $owner ), 'validations' => array( 'user_id' => 'P1' ) ) ), $owner, false );
		$this->assertSame( 'incomplete', $r['code'] );
		$this->assertNull( Identities::find_by_wp_user( $uid ) );
		$this->assertSame( array(), Results::passed_conditions( $owner, 0, false ) );
	}

	private function verify_uid( array $rule, int $user, string $person, string $nonce ): array {
		$owner = 'u:' . $user;
		$this->session( $rule, $owner, $nonce );
		return $this->svc->verify( $this->signer->token( array( 'nonce' => $nonce, 'validations' => array( 'user_id' => $person, 'nsbd_id' => 'N-' . $person, 'doc_id' => 'D-' . $person ) ) ), $owner, false );
	}

	public function test_duplicate_reject_and_block(): void {
		foreach ( array( 'reject', 'block' ) as $i => $policy ) {
			$rule = $this->rule( array( 'age' => '', 'unique' => true, 'duplicate_policy' => $policy ) );
			$a    = $this->make_user();
			$b    = $this->make_user();
			$this->assertSame( 'ok', $this->verify_uid( $rule, $a, "P$i", "r$i-a" )['code'] );
			$this->assertSame( 'duplicate', $this->verify_uid( $rule, $b, "P$i", "r$i-b" )['code'] );
			$this->assertSame( array(), Results::passed_conditions( 'u:' . $b, 0, false ) );
		}
	}

	public function test_duplicate_flag_accepts_and_marks(): void {
		$rule = $this->rule( array( 'age' => '', 'unique' => true, 'duplicate_policy' => 'flag' ) );
		$a    = $this->make_user();
		$b    = $this->make_user();
		$this->verify_uid( $rule, $a, 'PF', 'f-a' );
		$this->assertSame( 'ok', $this->verify_uid( $rule, $b, 'PF', 'f-b' )['code'] );
		$this->assertSame( $a, (int) get_user_meta( $b, 'verifyblind_duplicate_of', true ) );
		$this->assertSame( $a, (int) Identities::find_by_vb_user_id( 'PF' )['wp_user_id'] );
	}

	public function test_duplicate_transfer_moves_identity(): void {
		$rule = $this->rule( array( 'age' => '', 'unique' => true, 'duplicate_policy' => 'transfer' ) );
		$a    = $this->make_user();
		$b    = $this->make_user();
		$this->verify_uid( $rule, $a, 'PT', 't-a' );
		$this->assertContains( Roles::BASE, get_userdata( $a )->roles );
		$this->assertSame( 'ok', $this->verify_uid( $rule, $b, 'PT', 't-b' )['code'] );
		$this->assertSame( $b, (int) Identities::find_by_vb_user_id( 'PT' )['wp_user_id'] );
		$this->assertNotContains( 'uid', Results::passed_conditions( 'u:' . $a, 0, false ) );
		$this->assertNotContains( Roles::BASE, get_userdata( $a )->roles );
	}

	public function test_account_cannot_switch_identity(): void {
		$rule = $this->rule( array( 'age' => '', 'unique' => true ) );
		$a    = $this->make_user();
		$this->verify_uid( $rule, $a, 'P1', 's-1' );
		$this->assertSame( 'different_identity', $this->verify_uid( $rule, $a, 'P2', 's-2' )['code'] );
	}

	public function test_revoke_removes_results_identity_and_roles(): void {
		$rule = $this->rule( array( 'age' => '18+', 'unique' => true ) );
		$a    = $this->make_user();
		$owner = 'u:' . $a;
		$this->session( $rule, $owner, 'rv' );
		$this->svc->verify( $this->signer->token( array( 'nonce' => 'rv', 'validations' => array( 'age' => true, 'user_id' => 'PR' ) ) ), $owner, false );
		$this->assertContains( Roles::BASE, get_userdata( $a )->roles );
		$this->svc->revoke( 'rv' );
		$this->assertSame( array(), Results::passed_conditions( $owner, 0, false ) );
		$this->assertNull( Identities::find_by_vb_user_id( 'PR' ) );
		$this->assertNotContains( Roles::BASE, get_userdata( $a )->roles );
	}

	public function test_webhook_signature(): void {
		$raw = '{"event_type":"CONSENT_WITHDRAWN","nonce":"abc"}';
		$ts  = (string) time();
		$sig = $this->signer->sign( $ts . '.' . $raw );
		$this->assertTrue( $this->svc->verify_webhook( $raw, $sig, $ts ) );
		$this->assertFalse( $this->svc->verify_webhook( $raw . ' ', $sig, $ts ) );
		$this->assertFalse( $this->svc->verify_webhook( $raw, $sig, $ts, time() + 301 ) );
		$this->assertFalse( $this->svc->verify_webhook( $raw, null, $ts ) );
		$this->assertFalse( $this->svc->verify_webhook( $raw, $sig, 'abc' ) );
	}
}
