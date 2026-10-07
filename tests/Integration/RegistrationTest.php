<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Cron;
use VerifyBlind\Identities;
use VerifyBlind\Messages;
use VerifyBlind\Nonces;
use VerifyBlind\Owner;
use VerifyBlind\PendingIdentities;
use VerifyBlind\Placements\Registration;
use VerifyBlind\Results;
use VerifyBlind\Roles;
use VerifyBlind\Rules;
use VerifyBlind\Schema;
use VerifyBlind\VerificationService;

final class RegistrationTest extends TestCase {
	const GID = 'abcdefabcdefabcdefabcdefabcdef12';

	private function guest(): string {
		$_COOKIE[ Owner::COOKIE ] = self::GID;
		return 'g:' . self::GID;
	}

	/** The guest verifies $rule through the real service (signed token, stored session). */
	private function guest_verifies( array $rule, string $person, string $nonce = 'reg-n', bool $is_test = false ): array {
		$guest  = $this->guest();
		$signer = new Signer();
		Nonces::put( $nonce, $rule['id'], $rule['age'], $rule['unique'], $guest, 960 );
		$v = array( 'user_id' => $person, 'nsbd_id' => 'N-' . $person, 'doc_id' => 'D-' . $person );
		if ( '' !== $rule['age'] ) {
			$v['age'] = true;
		}
		if ( $is_test ) {
			$v['is_test'] = true;
		}
		return ( new VerificationService( $signer ) )->verify( $signer->token( array( 'nonce' => $nonce, 'validations' => $v ) ), $guest, $is_test );
	}

	/** WordPress sign-up as a visitor who is not logged in. @return int|\WP_Error */
	private function sign_up() {
		$r = register_new_user( 'vbreg_' . strtolower( wp_generate_password( 8, false ) ), 'vbreg' . wp_rand() . '@example.com' );
		if ( is_int( $r ) ) {
			$this->created_users[] = $r;
		}
		return $r;
	}

	private function generate( string $rule_id ): \WP_REST_Response {
		$req = new \WP_REST_Request( 'POST', '/verifyblind/v1/generate' );
		$req->set_query_params( array( 'rule' => $rule_id ) );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'public_key' => 'PK' ) ) );
		return rest_do_request( $req );
	}

	public function test_is_a_placement(): void {
		$this->assertArrayHasKey( Registration::KEY, Rules::placements() );
	}

	public function test_a_guest_may_start_a_one_person_check_only_for_sign_up(): void {
		update_option( 'verifyblind_captcha', '0' );
		$this->mock_http(
			function () {
				return array( 'body' => '{"nonce":"reg-gen"}' );
			}
		);
		$signup  = $this->rule( array( 'placement' => Registration::KEY, 'age' => '', 'unique' => true ) );
		$content = $this->rule( array( 'age' => '', 'unique' => true ) );
		$this->assertSame( 401, $this->generate( $content['id'] )->get_status() );
		$this->assertSame( 200, $this->generate( $signup['id'] )->get_status() );
	}

	public function test_sign_up_is_refused_until_the_age_check_passes(): void {
		$this->rule( array( 'placement' => Registration::KEY, 'age' => '18+' ) );
		$refused = $this->sign_up();
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertContains( Messages::get( 'registration_required' ), $refused->get_error_messages() );
		Results::add( $this->guest(), '18+', true, 'reg-age', false );
		$uid = $this->sign_up();
		$this->assertIsInt( $uid );
		$this->assertSame( array( '18+' ), Results::passed_conditions( 'u:' . $uid, 0, false ) );
	}

	public function test_one_person_check_is_held_and_bound_to_the_new_account(): void {
		$rule  = $this->rule( array( 'placement' => Registration::KEY, 'age' => '18+', 'unique' => true ) );
		$guest = 'g:' . self::GID;
		$this->assertSame( 'ok', $this->guest_verifies( $rule, 'P-NEW' )['code'] );
		$this->assertSame( 'P-NEW', PendingIdentities::find( $guest )['vb_user_id'] );
		$this->assertNull( Identities::find_by_vb_user_id( 'P-NEW' ), 'no account yet' );

		$uid = $this->sign_up();
		$this->assertIsInt( $uid );
		$identity = Identities::find_by_vb_user_id( 'P-NEW' );
		$this->assertSame( $uid, (int) $identity['wp_user_id'] );
		$this->assertSame( 'N-P-NEW', $identity['nsbd_id'] );
		$passed = Results::passed_conditions( 'u:' . $uid, 0, false );
		sort( $passed );
		$this->assertSame( array( '18+', 'uid' ), $passed );
		$this->assertNull( PendingIdentities::find( $guest ) );
		$this->assertSame( array(), Results::passed_conditions( $guest, 0, true ) );
		$this->assertContains( Roles::BASE, get_userdata( $uid )->roles );
	}

	public function test_a_known_person_is_refused_at_verification_under_reject(): void {
		$other = $this->make_user();
		Identities::insert( 'P-DUP', $other, null, null, 'old' );
		$rule = $this->rule( array( 'placement' => Registration::KEY, 'age' => '', 'unique' => true ) );
		$this->assertSame( 'duplicate', $this->guest_verifies( $rule, 'P-DUP' )['code'] );
		$this->assertNull( PendingIdentities::find( 'g:' . self::GID ) );
		$this->assertInstanceOf( \WP_Error::class, $this->sign_up() );
	}

	public function test_sign_up_checks_duplicates_again(): void {
		$rule = $this->rule( array( 'placement' => Registration::KEY, 'age' => '', 'unique' => true, 'duplicate_policy' => 'block' ) );
		$this->assertSame( 'ok', $this->guest_verifies( $rule, 'P-RACE' )['code'] );
		Identities::insert( 'P-RACE', $this->make_user(), null, null, 'race' ); // another account took the person meanwhile
		$refused = $this->sign_up();
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertContains( Messages::get( 'duplicate' ), $refused->get_error_messages() );
	}

	public function test_flag_policy_allows_sign_up_and_marks_the_same_person(): void {
		$other = $this->make_user();
		Identities::insert( 'P-FLAG', $other, null, null, 'old' );
		$rule = $this->rule( array( 'placement' => Registration::KEY, 'age' => '', 'unique' => true, 'duplicate_policy' => 'flag' ) );
		$this->assertSame( 'ok', $this->guest_verifies( $rule, 'P-FLAG' )['code'] );
		$uid = $this->sign_up();
		$this->assertIsInt( $uid );
		$this->assertSame( $other, (int) get_user_meta( $uid, 'verifyblind_duplicate_of', true ) );
		$this->assertSame( $other, (int) Identities::find_by_vb_user_id( 'P-FLAG' )['wp_user_id'] );
		$this->assertContains( 'uid', Results::passed_conditions( 'u:' . $uid, 0, false ) );
	}

	public function test_transfer_policy_moves_the_identity_at_sign_up(): void {
		$other = $this->make_user();
		Identities::insert( 'P-MOVE', $other, null, null, 'old' );
		Results::add( 'u:' . $other, 'uid', true, 'old', false );
		$rule = $this->rule( array( 'placement' => Registration::KEY, 'age' => '', 'unique' => true, 'duplicate_policy' => 'transfer' ) );
		$this->assertSame( 'ok', $this->guest_verifies( $rule, 'P-MOVE' )['code'] );
		$uid = $this->sign_up();
		$this->assertIsInt( $uid );
		$this->assertSame( $uid, (int) Identities::find_by_vb_user_id( 'P-MOVE' )['wp_user_id'] );
		$this->assertNotContains( 'uid', Results::passed_conditions( 'u:' . $other, 0, false ) );
	}

	public function test_woocommerce_sign_up_is_checked_too(): void {
		if ( ! function_exists( 'wc_create_new_customer' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active' );
		}
		$this->rule( array( 'placement' => Registration::KEY, 'age' => '18+' ) );
		$email   = 'vbwc' . wp_rand() . '@example.com';
		$refused = wc_create_new_customer( $email, 'vbwc_' . strtolower( wp_generate_password( 8, false ) ), wp_generate_password() );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( Messages::get( 'registration_required' ), $refused->get_error_message() );
		Results::add( $this->guest(), '18+', true, 'wc-reg', false );
		$uid = wc_create_new_customer( $email, 'vbwc_' . strtolower( wp_generate_password( 8, false ) ), wp_generate_password() );
		$this->assertIsInt( $uid );
		$this->created_users[] = $uid;
	}

	public function test_sign_up_forms_show_the_box_without_reload(): void {
		$this->rule( array( 'placement' => Registration::KEY, 'age' => '18+' ) );
		foreach ( array( 'register_form', 'woocommerce_register_form' ) as $hook ) {
			ob_start();
			do_action( $hook );
			$html = (string) ob_get_clean();
			$this->assertStringContainsString( 'verifyblind-box', $html, $hook );
			$this->assertStringContainsString( 'data-reload="0"', $html, $hook );
		}
		Results::add( $this->guest(), '18+', true, 'form', false );
		ob_start();
		do_action( 'register_form' );
		$this->assertStringNotContainsString( 'verifyblind-box', (string) ob_get_clean() );
	}

	public function test_demo_card_sign_up_binds_no_identity(): void {
		update_option( 'verifyblind_test_mode', '1' );
		$rule = $this->rule( array( 'placement' => Registration::KEY, 'age' => '', 'unique' => true ) );
		$this->assertSame( 'ok', $this->guest_verifies( $rule, 'DEMO', 'reg-demo', true )['code'] );
		$uid = $this->sign_up();
		$this->assertIsInt( $uid );
		$this->assertNull( Identities::find_by_vb_user_id( 'DEMO' ) );
		$this->assertContains( 'uid', Results::passed_conditions( 'u:' . $uid, 0, true ) );
	}

	public function test_an_account_created_outside_the_sign_up_form_takes_nothing_held(): void {
		$rule  = $this->rule( array( 'placement' => Registration::KEY, 'age' => '18+', 'unique' => true ) );
		$guest = 'g:' . self::GID;
		$this->assertSame( 'ok', $this->guest_verifies( $rule, 'P-OUT' )['code'] );
		$uid = $this->make_user(); // wp_insert_user in the same request, not through the gated sign-up

		$this->assertSame( 'P-OUT', PendingIdentities::find( $guest )['vb_user_id'], 'the held check stays for the real sign-up' );
		$this->assertNull( Identities::find_by_vb_user_id( 'P-OUT' ) );
		$this->assertSame( array(), Results::passed_conditions( 'u:' . $uid, 0, true ) );
		$this->assertNotContains( Roles::BASE, get_userdata( $uid )->roles );
	}

	public function test_a_stale_held_check_is_refused_at_sign_up(): void {
		global $wpdb;
		$rule  = $this->rule( array( 'placement' => Registration::KEY, 'age' => '', 'unique' => true ) );
		$guest = 'g:' . self::GID;
		$this->assertSame( 'ok', $this->guest_verifies( $rule, 'P-STALE' )['code'] );
		$wpdb->update( Schema::table( 'pending' ), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * MINUTE_IN_SECONDS ) ), array( 'owner' => $guest ) );

		$refused = $this->sign_up();
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertContains( Messages::get( 'registration_required' ), $refused->get_error_messages() );
		$this->assertNull( Identities::find_by_vb_user_id( 'P-STALE' ) );
	}

	public function test_a_person_taken_after_the_sign_up_check_leaves_the_new_account_marked_and_unverified(): void {
		$other = $this->make_user();
		$rule  = $this->rule( array( 'placement' => Registration::KEY, 'age' => '18+', 'unique' => true ) );
		$this->assertSame( 'ok', $this->guest_verifies( $rule, 'P-LATE' )['code'] );
		$take = function ( $errors ) use ( $other ) {
			Identities::insert( 'P-LATE', $other, null, null, 'late' ); // another sign-up wins between check and bind
			return $errors;
		};
		add_filter( 'registration_errors', $take, 99 );
		$log      = tempnam( sys_get_temp_dir(), 'vblog' );
		$old_log  = ini_set( 'error_log', $log );
		try {
			$uid = $this->sign_up();
		} finally {
			remove_filter( 'registration_errors', $take, 99 );
			ini_set( 'error_log', false === $old_log ? '' : $old_log );
		}
		$note = (string) file_get_contents( $log );
		unlink( $log );

		$this->assertIsInt( $uid );
		$this->assertStringContainsString( 'marked as duplicate of account ' . $other, $note );
		$this->assertStringNotContainsString( 'P-LATE', $note, 'no person code in the log' );
		$this->assertSame( $other, (int) get_user_meta( $uid, 'verifyblind_duplicate_of', true ) );
		$this->assertSame( $other, (int) Identities::find_by_vb_user_id( 'P-LATE' )['wp_user_id'] );
		$this->assertNotContains( 'uid', Results::passed_conditions( 'u:' . $uid, 0, true ) );
		$this->assertNotContains( Roles::BASE, get_userdata( $uid )->roles );
	}

	public function test_a_second_sign_up_of_the_same_person_waits_for_the_first(): void {
		$rule = $this->rule( array( 'placement' => Registration::KEY, 'age' => '', 'unique' => true ) );
		$this->assertSame( 'ok', $this->guest_verifies( $rule, 'P-BUSY' )['code'] );
		// Another request (its own database connection) is binding the same person right now.
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$this->assertSame( '1', (string) $other->query( "SELECT GET_LOCK('vb_p_" . md5( 'P-BUSY' ) . "', 0)" )->fetch_row()[0] );
		try {
			$refused = $this->sign_up();
		} finally {
			$other->close(); // releases its lock
		}
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertContains( Messages::get( 'duplicate_busy' ), $refused->get_error_messages() );
		$this->assertNotNull( PendingIdentities::find( 'g:' . self::GID ), 'the held check stays for a retry' );
	}

	public function test_a_demo_card_hold_is_refused_once_test_mode_is_off(): void {
		update_option( 'verifyblind_test_mode', '1' );
		$rule = $this->rule( array( 'placement' => Registration::KEY, 'age' => '', 'unique' => true ) );
		$this->assertSame( 'ok', $this->guest_verifies( $rule, 'DEMO', 'reg-demo-off', true )['code'] );
		update_option( 'verifyblind_test_mode', '0' );

		$this->assertInstanceOf( \WP_Error::class, $this->sign_up() );
		$this->assertNull( Identities::find_by_vb_user_id( 'DEMO' ) );
	}

	public function test_a_guest_who_logs_in_instead_loses_the_held_check(): void {
		$uid   = $this->make_user();
		$rule  = $this->rule( array( 'placement' => Registration::KEY, 'age' => '', 'unique' => true ) );
		$guest = 'g:' . self::GID;
		$this->assertSame( 'ok', $this->guest_verifies( $rule, 'P-LOGIN' )['code'] );
		\VerifyBlind\Plugin::on_login( get_userdata( $uid )->user_login, get_userdata( $uid ) );

		global $wpdb;
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'pending' ) ) );
		$this->assertNull( Identities::find_by_vb_user_id( 'P-LOGIN' ) );
	}

	public function test_revoke_and_daily_cleanup_remove_held_checks(): void {
		global $wpdb;
		$guest = 'g:' . self::GID;
		PendingIdentities::put( $guest, 'r_00000001', 'P-RV', null, null, 'rv-n', false );
		( new VerificationService( new Signer() ) )->revoke( 'rv-n' );
		$this->assertNull( PendingIdentities::find( $guest ) );
		PendingIdentities::put( $guest, 'r_00000001', 'P-OLD', null, null, 'old-n', false );
		$wpdb->update( Schema::table( 'pending' ), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ), array( 'owner' => $guest ) );
		$this->assertNull( PendingIdentities::find( $guest ), 'older than 24 hours counts as gone' );
		Cron::run();
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'pending' ) ) );
	}
}
