<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Nonces;
use VerifyBlind\Owner;
use VerifyBlind\Results;
use VerifyBlind\Roles;
use VerifyBlind\VerificationService;

final class GuestCarryOverTest extends TestCase {
	const GID = '0123456789abcdef0123456789abcdef';

	/** A guest with a cookie verifies 18+ through the real service. */
	private function guest_verifies_18(): string {
		$_COOKIE[ Owner::COOKIE ] = self::GID;
		$guest                    = 'g:' . self::GID;
		$rule                     = $this->rule( array( 'age' => '18+' ) );
		$signer                   = new Signer();
		Nonces::put( 'guest-n', $rule['id'], '18+', false, $guest, 960 );
		$r = ( new VerificationService( $signer ) )->verify( $signer->token( array( 'nonce' => 'guest-n', 'validations' => array( 'age' => true ) ) ), $guest, false );
		$this->assertSame( 'ok', $r['code'] );
		$this->assertSame( array( '18+' ), Results::passed_conditions( $guest, 0, false ) );
		return $guest;
	}

	public function test_guest_results_follow_the_visitor_when_they_log_in(): void {
		$uid   = $this->make_user();
		$guest = $this->guest_verifies_18();
		do_action( 'wp_login', get_userdata( $uid )->user_login, get_userdata( $uid ) );

		$this->assertSame( array( '18+' ), Results::passed_conditions( 'u:' . $uid, 0, false ) );
		$this->assertSame( array(), Results::passed_conditions( $guest, 0, true ) );
		$this->assertArrayNotHasKey( Owner::COOKIE, $_COOKIE, 'the guest cookie is expired' );
		$this->assertContains( Roles::BASE, get_userdata( $uid )->roles );
	}

	public function test_only_fresh_guest_results_move(): void {
		global $wpdb;
		$uid                      = $this->make_user();
		$_COOKIE[ Owner::COOKIE ] = self::GID;
		$guest                    = 'g:' . self::GID;
		Results::add( $guest, '18+', true, 'old', false );
		$wpdb->update(
			\VerifyBlind\Schema::table( 'results' ),
			array( 'verified_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) ),
			array( 'nonce' => 'old' )
		);
		Results::add( $guest, '21+', true, 'fresh', false );
		do_action( 'wp_login', get_userdata( $uid )->user_login, get_userdata( $uid ) );

		$this->assertSame( array( '21+' ), Results::passed_conditions( 'u:' . $uid, 0, false ) );
		$this->assertSame( array( '18+' ), Results::passed_conditions( $guest, 0, false ) );
	}

	public function test_guest_results_follow_the_visitor_when_they_register(): void {
		$guest = $this->guest_verifies_18();
		$uid   = $this->make_user(); // wp_insert_user fires user_register, visitor not logged in

		$this->assertSame( array( '18+' ), Results::passed_conditions( 'u:' . $uid, 0, false ) );
		$this->assertSame( array(), Results::passed_conditions( $guest, 0, true ) );
		$this->assertContains( Roles::BASE, get_userdata( $uid )->roles );
	}

	public function test_an_administrator_creating_a_user_does_not_hand_over_their_guest_results(): void {
		$admin = $this->make_user( 'administrator' );
		$guest = $this->guest_verifies_18();
		wp_set_current_user( $admin );
		$uid = $this->make_user();

		$this->assertSame( array(), Results::passed_conditions( 'u:' . $uid, 0, true ) );
		$this->assertSame( array( '18+' ), Results::passed_conditions( $guest, 0, false ) );
	}

	public function test_one_person_results_never_move(): void {
		$uid                      = $this->make_user();
		$_COOKIE[ Owner::COOKIE ] = self::GID;
		$guest                    = 'g:' . self::GID;
		Results::add( $guest, 'uid', true, 'x', false ); // cannot happen today; must stay put if it ever does
		Results::add( $guest, '21+', true, 'y', false );
		do_action( 'wp_login', get_userdata( $uid )->user_login, get_userdata( $uid ) );

		$this->assertSame( array( '21+' ), Results::passed_conditions( 'u:' . $uid, 0, false ) );
		$this->assertSame( array( 'uid' ), Results::passed_conditions( $guest, 0, false ) );
	}

	public function test_no_or_bad_cookie_changes_nothing(): void {
		$uid = $this->make_user();
		Results::add( 'g:' . self::GID, '18+', true, 'z', false );
		do_action( 'wp_login', get_userdata( $uid )->user_login, get_userdata( $uid ) );
		$_COOKIE[ Owner::COOKIE ] = 'not-a-guest-id';
		do_action( 'wp_login', get_userdata( $uid )->user_login, get_userdata( $uid ) );

		$this->assertSame( array(), Results::passed_conditions( 'u:' . $uid, 0, true ) );
		$this->assertSame( array( '18+' ), Results::passed_conditions( 'g:' . self::GID, 0, false ) );
	}
}
