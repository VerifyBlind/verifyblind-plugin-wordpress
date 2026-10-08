<?php
namespace VerifyBlind\Tests\Integration;

use PHPUnit\Framework\TestCase as Base;
use VerifyBlind\Owner;
use VerifyBlind\Placements\Registration;
use VerifyBlind\Rules;
use VerifyBlind\Schema;

/**
 * Runs against the real local test site. Each test starts from empty plugin tables and rules and
 * restores the options it touched. E-mail is never sent (the container has no sendmail).
 */
abstract class TestCase extends Base {
	const OPTIONS = array( 'verifyblind_rules', 'verifyblind_api_key', 'verifyblind_test_mode', 'verifyblind_cap_hits', 'verifyblind_badge_places', 'verifyblind_last_api_error', 'verifyblind_api_error_mailed', 'verifyblind_wizard_done', 'verifyblind_created_roles' );
	const TABLES  = array( 'nonces', 'results', 'identities', 'pending' );

	/** @var array */
	private $saved = array();
	/** @var array */
	private $saved_globals = array();
	/** @var int[] */
	protected $created_users = array();

	protected function setUp(): void {
		// The test site speaks Turkish; tests compare against the English source unless they switch locale themselves.
		switch_to_locale( 'en_US' );
		// WooCommerce customer e-mails switch to the site locale and restore afterwards. While a locale is
		// already switched the switch is a no-op but the restore still pops ours, leaving the rest of the
		// test in Turkish — so e-mails keep the test's locale.
		add_filter( 'woocommerce_allow_switching_email_locale', '__return_false' );
		add_filter( 'woocommerce_allow_restoring_email_locale', '__return_false' );
		global $wpdb;
		wp_cache_flush();
		foreach ( self::OPTIONS as $o ) {
			$this->saved[ $o ] = get_option( $o, null );
		}
		foreach ( array( 'wp_query', 'wp_the_query', 'post' ) as $g ) {
			$this->saved_globals[ $g ] = isset( $GLOBALS[ $g ] ) ? $GLOBALS[ $g ] : null;
		}
		foreach ( self::TABLES as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( $t ) );
		}
		foreach ( array( 'verifyblind_cap_hits', 'verifyblind_badge_places', 'verifyblind_last_api_error', 'verifyblind_api_error_mailed' ) as $o ) {
			delete_option( $o );
		}
		update_option( 'verifyblind_rules', array(), false );
		update_option( 'verifyblind_test_mode', '0' );
		update_option( 'verifyblind_api_key', 'test-key' );
		delete_transient( 'verifyblind_enclave_key' );
		delete_transient( 'verifyblind_webhook_key' );
		delete_transient( 'verifyblind_enclave_key_refreshed' );
		delete_transient( 'verifyblind_webhook_key_refreshed' );
		delete_transient( \VerifyBlind\Rest::RATE_KEY );
		add_filter( 'pre_wp_mail', '__return_true' );
		wp_set_current_user( 0 );
		unset( $_COOKIE[ Owner::COOKIE ] );
		Registration::reset();
		\VerifyBlind\Placements\WcProduct::reset_memo();
		\VerifyBlind\Placements\WcCheckout::reset(); // per-request checkout state (one request per process on a real site)
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
		foreach ( $this->saved_globals as $g => $v ) {
			if ( null === $v ) {
				unset( $GLOBALS[ $g ] );
			} else {
				$GLOBALS[ $g ] = $v;
			}
		}
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'verifyblind_key_source' );
		remove_all_filters( 'verifyblind_generate_per_minute' );
		remove_all_filters( 'pre_wp_mail' );
		delete_transient( \VerifyBlind\Rest::RATE_KEY );
		wp_set_current_user( 0 );
		unset( $_COOKIE[ Owner::COOKIE ] );
		Registration::reset();
		remove_filter( 'woocommerce_allow_switching_email_locale', '__return_false' );
		remove_filter( 'woocommerce_allow_restoring_email_locale', '__return_false' );
		while ( is_locale_switched() ) {
			restore_previous_locale();
		}
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

	/** Stores a rule for any placement string, even one not registered (yet). */
	protected function raw_rule( array $o ): array {
		$placement  = isset( $o['placement'] ) ? (string) $o['placement'] : 'content';
		$rule       = Rules::sanitize( array_merge( array( 'name' => 'Raw rule', 'enabled' => true, 'placement' => $placement, 'age' => '18+' ), $o ), array( $placement ) );
		$rule['id'] = 'r_' . bin2hex( random_bytes( 4 ) );
		$all        = Rules::all();
		$all[ $rule['id'] ] = $rule;
		update_option( Rules::OPTION, $all, false );
		return $rule;
	}

	/** Makes $id the main query (is_singular(), is_page(), get_queried_object()) and the global post. */
	protected function query_post( int $id ): void {
		$type                    = get_post_type( $id );
		$q                       = new \WP_Query( 'page' === $type ? array( 'page_id' => $id ) : array( 'p' => $id, 'post_type' => $type ) );
		$GLOBALS['wp_query']     = $q;
		$GLOBALS['wp_the_query'] = $q;
		$GLOBALS['post']         = get_post( $id );
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
