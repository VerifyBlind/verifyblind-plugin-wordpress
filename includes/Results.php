<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- this class reads and writes the plugin's own table; results must be current (one-time nonces, uniqueness).

final class Results {
	const CARRY_OVER_SECONDS = 1800;

	public static function add( string $owner, string $cond, bool $passed, string $nonce, bool $is_test ): void {
		global $wpdb;
		$wpdb->insert(
			Schema::table( 'results' ),
			array(
				'owner'       => $owner,
				'cond'        => $cond,
				'passed'      => $passed ? 1 : 0,
				'nonce'       => $nonce,
				'is_test'     => $is_test ? 1 : 0,
				'verified_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s' )
		);
	}

	/**
	 * @param int $within_seconds also only results at most this old (0 = no extra limit), e.g. CARRY_OVER_SECONDS
	 * @return string[] distinct conditions passed within the validity window (0 = forever).
	 */
	public static function passed_conditions( string $owner, int $validity_days, bool $include_test, int $within_seconds = 0 ): array {
		global $wpdb;
		$t    = Schema::table( 'results' );
		$sql  = "SELECT DISTINCT cond FROM {$t} WHERE owner = %s AND passed = 1";
		$args = array( $owner );
		if ( $validity_days > 0 ) {
			$sql   .= ' AND verified_at >= %s';
			$args[] = gmdate( 'Y-m-d H:i:s', time() - $validity_days * DAY_IN_SECONDS );
		}
		if ( $within_seconds > 0 ) {
			$sql   .= ' AND verified_at >= %s';
			$args[] = gmdate( 'Y-m-d H:i:s', time() - $within_seconds );
		}
		if ( ! $include_test ) {
			$sql .= ' AND is_test = 0';
		}
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built from fixed fragments and the plugin's own table name; every value goes through prepare()
		$found = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return array_map( 'strval', $found );
	}

	/** @return array[] id, cond, passed (bool), is_test (bool), verified_at, nonce — newest first */
	public static function for_owner( string $owner ): array {
		global $wpdb;
		$t    = Schema::table( 'results' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $t is $wpdb->prefix plus a fixed name
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, cond, passed, is_test, verified_at, nonce FROM {$t} WHERE owner = %s ORDER BY verified_at DESC, id DESC", $owner ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'          => (int) $r['id'],
				'cond'        => (string) $r['cond'],
				'passed'      => 1 === (int) $r['passed'],
				'is_test'     => 1 === (int) $r['is_test'],
				'verified_at' => (string) $r['verified_at'],
				'nonce'       => (string) $r['nonce'],
			);
		}
		return $out;
	}

	/** @return string[] owners that had results for this nonce */
	public static function delete_by_nonce( string $nonce ): array {
		global $wpdb;
		$t      = Schema::table( 'results' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $t is $wpdb->prefix plus a fixed name
		$owners = array_map( 'strval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT owner FROM {$t} WHERE nonce = %s", $nonce ) ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->delete( $t, array( 'nonce' => $nonce ), array( '%s' ) );
		return $owners;
	}

	public static function delete_cond( string $owner, string $cond ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'results' ), array( 'owner' => $owner, 'cond' => $cond ), array( '%s', '%s' ) );
	}

	/**
	 * Moves a guest's FRESH age results (verified within CARRY_OVER_SECONDS) to an account, so a shared
	 * browser's old guest results do not follow whoever logs in next. One-person ('uid') results never
	 * move: they are bound to an account.
	 */
	public static function reassign_owner( string $from, string $to ): void {
		global $wpdb;
		$t = Schema::table( 'results' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $t is $wpdb->prefix plus a fixed name
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET owner = %s WHERE owner = %s AND cond <> %s AND verified_at >= %s",
				$to,
				$from,
				'uid',
				gmdate( 'Y-m-d H:i:s', time() - self::CARRY_OVER_SECONDS )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	public static function delete_owner( string $owner ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'results' ), array( 'owner' => $owner ), array( '%s' ) );
	}

	public static function purge_guests( int $max_age_seconds ): void {
		global $wpdb;
		$t = Schema::table( 'results' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $t is $wpdb->prefix plus a fixed name
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$t} WHERE owner LIKE %s AND verified_at < %s",
				$wpdb->esc_like( 'g:' ) . '%',
				gmdate( 'Y-m-d H:i:s', time() - $max_age_seconds )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}
}
