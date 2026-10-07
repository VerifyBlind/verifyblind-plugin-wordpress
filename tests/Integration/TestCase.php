<?php
namespace VerifyBlind\Tests\Integration;

use PHPUnit\Framework\TestCase as Base;
use VerifyBlind\Owner;
use VerifyBlind\Rules;
use VerifyBlind\Schema;

/**
 * Runs against the real local test site. Each test starts from empty plugin tables and rules and
 * restores the options it touched.
 */
abstract class TestCase extends Base {
	const OPTIONS = array( 'verifyblind_rules', 'verifyblind_api_key', 'verifyblind_test_mode', 'verifyblind_captcha' );

	/** @var array */
	private $saved = array();
	/** @var int[] */
	protected $created_users = array();

	protected function setUp(): void {
		global $wpdb;
		foreach ( self::OPTIONS as $o ) {
			$this->saved[ $o ] = get_option( $o, null );
		}
		foreach ( array( 'nonces', 'results', 'identities' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( $t ) );
		}
		update_option( 'verifyblind_rules', array(), false );
		update_option( 'verifyblind_test_mode', '0' );
		update_option( 'verifyblind_api_key', 'test-key' );
		delete_transient( 'verifyblind_enclave_key' );
		delete_transient( 'verifyblind_webhook_key' );
		delete_transient( 'verifyblind_enclave_key_refreshed' );
		delete_transient( 'verifyblind_webhook_key_refreshed' );
		delete_transient( \VerifyBlind\Rest::RATE_KEY );
		wp_set_current_user( 0 );
		unset( $_COOKIE[ Owner::COOKIE ] );
	}

	protected function tearDown(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->created_users as $id ) {
			wp_delete_user( $id );
		}
		foreach ( $this->saved as $o => $v ) {
			if ( null === $v ) {
				delete_option( $o );
			} else {
				update_option( $o, $v );
			}
		}
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'verifyblind_key_source' );
		remove_all_filters( 'verifyblind_generate_per_minute' );
		delete_transient( \VerifyBlind\Rest::RATE_KEY );
		wp_set_current_user( 0 );
		unset( $_COOKIE[ Owner::COOKIE ] );
	}

	protected function make_user( string $role = 'subscriber' ): int {
		$id                    = wp_insert_user(
			array(
				'user_login' => 'vbtest_' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => $role,
			)
		);
		$this->created_users[] = $id;
		return $id;
	}

	protected function rule( array $o = array() ): array {
		return Rules::save( array_merge( array( 'name' => 'Test rule', 'enabled' => true, 'placement' => 'content', 'age' => '18+' ), $o ) );
	}

	/** $fn($url, $args) returns null (let it through) or ['code'=>int,'body'=>string,'headers'=>array]. */
	protected function mock_http( callable $fn ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $fn ) {
				$r = $fn( $url, $args );
				if ( null === $r ) {
					return $pre;
				}
				return array(
					'headers'  => isset( $r['headers'] ) ? $r['headers'] : array(),
					'body'     => isset( $r['body'] ) ? $r['body'] : '',
					'response' => array( 'code' => isset( $r['code'] ) ? $r['code'] : 200, 'message' => '' ),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}
}
