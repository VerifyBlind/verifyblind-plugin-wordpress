<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

final class Nonces {
	public static function put( string $nonce, string $rule_id, string $age_cond, bool $want_uid, string $owner, int $ttl ): void {
		global $wpdb;
		$wpdb->replace(
			Schema::table( 'nonces' ),
			array(
				'nonce'      => $nonce,
				'rule_id'    => $rule_id,
				'age_cond'   => $age_cond,
				'want_uid'   => $want_uid ? 1 : 0,
				'owner'      => $owner,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/** Get-and-delete. Only the visitor who started the session, only before it expires, only once. */
	public static function consume( string $nonce, string $owner ): ?array {
		global $wpdb;
		$t   = Schema::table( 'nonces' );
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE nonce = %s AND owner = %s AND expires_at > %s", $nonce, $owner, $now ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE nonce = %s AND owner = %s AND expires_at > %s", $nonce, $owner, $now ) );
		if ( 1 !== $deleted ) {
			return null; // a concurrent request consumed it first
		}
		return array(
			'nonce'    => $row['nonce'],
			'rule_id'  => $row['rule_id'],
			'age_cond' => $row['age_cond'],
			'want_uid' => (bool) (int) $row['want_uid'],
			'owner'    => $row['owner'],
		);
	}

	public static function purge_expired(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'nonces' ) . ' WHERE expires_at <= %s', gmdate( 'Y-m-d H:i:s' ) ) );
	}
}
