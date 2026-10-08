<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- this class reads and writes the plugin's own table; results must be current (one-time nonces, uniqueness).

/**
 * A one-person check a guest made on the sign-up form, waiting for the account that is about to be created.
 * One row per guest (the latest check wins); it lives as long as the guest cookie (24 hours).
 */
final class PendingIdentities {
	const TTL = 86400;

	public static function put( string $owner, string $rule_id, string $vb, ?string $nsbd, ?string $doc, string $nonce, bool $is_test ): void {
		global $wpdb;
		$wpdb->replace(
			Schema::table( 'pending' ),
			array(
				'owner'      => $owner,
				'rule_id'    => $rule_id,
				'vb_user_id' => $vb,
				'nsbd_id'    => $nsbd,
				'doc_id'     => $doc,
				'nonce'      => $nonce,
				'is_test'    => $is_test ? 1 : 0,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * The guest's held check, or null. Sign-up uses it only while fresh: pass Results::CARRY_OVER_SECONDS
	 * (the 24-hour TTL only decides when a row is gone for good).
	 */
	public static function find( string $owner, int $max_age_seconds = self::TTL ): ?array {
		global $wpdb;
		$t = Schema::table( 'pending' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $t is $wpdb->prefix plus a fixed name
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE owner = %s AND created_at >= %s",
				$owner,
				gmdate( 'Y-m-d H:i:s', time() - min( $max_age_seconds, self::TTL ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( ! $row ) {
			return null;
		}
		$row['is_test'] = 1 === (int) $row['is_test'];
		return $row;
	}

	public static function delete( string $owner ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'pending' ), array( 'owner' => $owner ), array( '%s' ) );
	}

	public static function delete_by_nonce( string $nonce ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'pending' ), array( 'nonce' => $nonce ), array( '%s' ) );
	}

	public static function purge( int $max_age_seconds ): void {
		global $wpdb;
		$t = Schema::table( 'pending' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $t is $wpdb->prefix plus a fixed name
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $max_age_seconds ) ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Binds the guest's held check to the account just created (same-person policy applied).
	 *
	 * Only a fresh hold (Results::CARRY_OVER_SECONDS) binds. When the person was taken by another account in the
	 * meantime the new account gets no one-person pass and is marked `verifyblind_duplicate_of`.
	 *
	 * @return string '' when nothing usable was held, otherwise IdentityClaim's outcome
	 */
	public static function claim( string $guest, int $user_id ): string {
		$p = self::find( $guest, Results::CARRY_OVER_SECONDS );
		if ( null === $p ) {
			return '';
		}
		self::delete( $guest );
		Results::delete_cond( $guest, 'uid' );
		$owner = Owner::for_user( $user_id );
		if ( $p['is_test'] ) {
			// A demo card's person code is shared by everyone: it never binds an identity.
			Results::add( $owner, 'uid', true, (string) $p['nonce'], true );
			return 'ok';
		}
		$rule    = Rules::get( (string) $p['rule_id'] );
		$outcome = IdentityClaim::claim(
			(string) $p['vb_user_id'],
			$user_id,
			null === $p['nsbd_id'] ? null : (string) $p['nsbd_id'],
			null === $p['doc_id'] ? null : (string) $p['doc_id'],
			(string) $p['nonce'],
			$rule ? $rule['duplicate_policy'] : 'reject'
		);
		if ( 'ok' === $outcome ) {
			Results::add( $owner, 'uid', true, (string) $p['nonce'], false );
			return $outcome;
		}
		$existing = Identities::find_by_vb_user_id( (string) $p['vb_user_id'] );
		$other    = $existing ? (int) $existing['wp_user_id'] : 0;
		update_user_meta( $user_id, 'verifyblind_duplicate_of', $other );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator note, user ids only.
		error_log( sprintf( 'VerifyBlind: new account %d could not take its sign-up one-person check (%s); marked as duplicate of account %d.', $user_id, $outcome, $other ) );
		return $outcome;
	}
}
