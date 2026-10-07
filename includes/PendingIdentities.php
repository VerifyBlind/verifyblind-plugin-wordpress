<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

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

	public static function find( string $owner ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'pending' ) . ' WHERE owner = %s AND created_at >= %s',
				$owner,
				gmdate( 'Y-m-d H:i:s', time() - self::TTL )
			),
			ARRAY_A
		);
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
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'pending' ) . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - $max_age_seconds ) ) );
	}

	/**
	 * Binds the guest's held check to the account just created (same-person policy applied).
	 *
	 * @return string '' when nothing was held, otherwise IdentityClaim's outcome
	 */
	public static function claim( string $guest, int $user_id ): string {
		$p = self::find( $guest );
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
		}
		return $outcome;
	}
}
