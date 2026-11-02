<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Badge;
use VerifyBlind\Owner;
use VerifyBlind\Placements\Account;
use VerifyBlind\Results;
use VerifyBlind\Settings;

final class AccountPlacementTest extends TestCase {
	private function member(): int {
		return (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function test_asking_an_account_creates_the_rule_once(): void {
		Account::request( $this->member() );
		$first = Account::rule();
		Account::request( $this->member() );
		$this->assertNotNull( $first );
		$this->assertSame( $first['id'], Account::rule()['id'] );
		$this->assertTrue( $first['unique'] );
	}

	public function test_asked_account_cannot_comment_until_it_verifies(): void {
		$user = $this->member();
		Account::request( $user );

		$refused = Account::refuse_comment( 1, array( 'user_id' => $user ) );
		$this->assertInstanceOf( \WP_Error::class, $refused );

		Results::add( Owner::for_user( $user ), 'uid', true, 'nonce-account', false );
		Badge::reset_cache();
		$this->assertSame( 1, Account::refuse_comment( 1, array( 'user_id' => $user ) ) );
	}

	public function test_everyone_else_is_not_held_back(): void {
		$this->assertSame( 1, Account::refuse_comment( 1, array( 'user_id' => $this->member() ) ) );
		$this->assertSame( 1, Account::refuse_comment( 1, array( 'user_id' => 0 ) ) );
		$moderator = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		Account::request( $moderator );
		$this->assertSame( 1, Account::refuse_comment( 1, array( 'user_id' => $moderator ) ) );
	}

	public function test_cancelled_request_lets_the_account_post_again(): void {
		$user = $this->member();
		Account::request( $user );
		Account::cancel_request( $user );
		$this->assertFalse( Account::blocked( $user ) );
	}

	public function test_forum_filter_follows_the_same_decision(): void {
		$user = $this->member();
		Account::request( $user );
		wp_set_current_user( $user );
		$this->assertFalse( Account::can_post( true ) );
		wp_set_current_user( $this->member() );
		$this->assertTrue( Account::can_post( true ) );
	}

	public function test_test_mode_turns_itself_off(): void {
		update_option( 'verifyblind_test_mode', '1' );
		update_option( 'verifyblind_test_mode_until', time() + 60 );
		$this->assertTrue( Settings::test_mode() );

		update_option( 'verifyblind_test_mode_until', time() - 1 );
		$this->assertFalse( Settings::test_mode() );
		$this->assertSame( '0', get_option( 'verifyblind_test_mode' ) );
	}

	public function test_test_mode_saved_before_this_version_gets_a_deadline(): void {
		update_option( 'verifyblind_test_mode', '1' );
		delete_option( 'verifyblind_test_mode_until' );
		$this->assertTrue( Settings::test_mode() );
		$this->assertGreaterThan( time(), (int) get_option( 'verifyblind_test_mode_until' ) );
	}
}
