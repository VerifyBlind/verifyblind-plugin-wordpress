<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

/** Accounts that have VerifyBlind results or a bound identity, for the "Verified members" screen. */
final class Members {
	/** @return array{rows: array[], total: int} newest verification first */
	public static function page( int $paged, int $per_page ): array {
		global $wpdb;
		$r     = Schema::table( 'results' );
		$i     = Schema::table( 'identities' );
		$union = $wpdb->prepare(
			"SELECT CAST(SUBSTRING(owner, 3) AS UNSIGNED) AS uid, verified_at AS at FROM $r WHERE LEFT(owner, 2) = %s UNION ALL SELECT wp_user_id AS uid, verified_at AS at FROM $i",
			'u:'
		);
		$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT uid) FROM ( $union ) m" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $union is prepared above
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT uid FROM ( $union ) m GROUP BY uid ORDER BY MAX(at) DESC, uid DESC LIMIT %d OFFSET %d", $per_page, max( 0, ( $paged - 1 ) * $per_page ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = array();
		foreach ( $ids as $id ) {
			$id     = (int) $id;
			$user   = get_userdata( $id );
			$passed = array();
			$last   = '';
			$test   = false;
			foreach ( Results::for_owner( Owner::for_user( $id ) ) as $h ) {
				if ( $h['passed'] ) {
					$passed[] = $h['cond'];
				}
				if ( $h['verified_at'] > $last ) {
					$last = $h['verified_at'];
				}
				$test = $test || $h['is_test'];
			}
			$identity = Identities::find_by_wp_user( $id );
			if ( $identity && (string) $identity['verified_at'] > $last ) {
				$last = (string) $identity['verified_at'];
			}
			$rows[] = array(
				'user_id'        => $id,
				'login'          => $user ? (string) $user->user_login : '',
				'conditions'     => array_values( array_unique( $passed ) ),
				'verified_at'    => $last,
				'one_person'     => null !== $identity || in_array( 'uid', $passed, true ),
				'same_person_as' => (int) get_user_meta( $id, 'verifyblind_duplicate_of', true ),
				'test'           => $test,
			);
		}
		return array( 'rows' => $rows, 'total' => $total );
	}

	/**
	 * Removes everything this plugin holds for the account and takes back the roles it granted.
	 * Coupon person records of past orders are order data and stay.
	 */
	public static function remove( int $user_id ): void {
		$owner  = Owner::for_user( $user_id );
		$nonces = array();
		foreach ( Results::for_owner( $owner ) as $h ) {
			$nonces[] = $h['nonce'];
		}
		$identity = Identities::find_by_wp_user( $user_id );
		if ( $identity ) {
			$nonces[] = (string) $identity['nonce'];
		}
		foreach ( array_unique( $nonces ) as $nonce ) {
			if ( '' !== $nonce ) {
				PendingIdentities::delete_by_nonce( $nonce );
			}
		}
		PendingIdentities::delete( $owner );
		Results::delete_owner( $owner );
		Identities::delete_for_user( $user_id );
		delete_user_meta( $user_id, 'verifyblind_duplicate_of' );
		delete_user_meta( $user_id, 'verifyblind_flag_person' );
		Roles::sync_user( $user_id );
	}
}
