<?php
namespace VerifyBlind;

/** One VerifyBlind person code (user_id) <-> one WordPress account. UNIQUE on vb_user_id. */
final class Identities {
	public static function find_by_vb_user_id( string $vb ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'identities' ) . ' WHERE vb_user_id = %s', $vb ), ARRAY_A );
		return $row ? $row : null;
	}

	public static function find_by_wp_user( int $wp ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'identities' ) . ' WHERE wp_user_id = %d', $wp ), ARRAY_A );
		return $row ? $row : null;
	}

	/** false when the person code already belongs to another account (UNIQUE violation, also under races). */
	public static function insert( string $vb, int $wp, ?string $nsbd, ?string $doc, string $nonce ): bool {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert(
			Schema::table( 'identities' ),
			array(
				'vb_user_id'  => $vb,
				'wp_user_id'  => $wp,
				'nsbd_id'     => $nsbd,
				'doc_id'      => $doc,
				'nonce'       => $nonce,
				'verified_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( $suppress );
		return 1 === $ok;
	}

	public static function move( string $vb, int $to_wp, string $nonce ): void {
		global $wpdb;
		$wpdb->update(
			Schema::table( 'identities' ),
			array( 'wp_user_id' => $to_wp, 'nonce' => $nonce, 'verified_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'vb_user_id' => $vb ),
			array( '%d', '%s', '%s' ),
			array( '%s' )
		);
	}

	/** @return int[] accounts whose identity was tied to this nonce */
	public static function delete_by_nonce( string $nonce ): array {
		global $wpdb;
		$t     = Schema::table( 'identities' );
		$users = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT wp_user_id FROM $t WHERE nonce = %s", $nonce ) ) );
		$wpdb->delete( $t, array( 'nonce' => $nonce ), array( '%s' ) );
		return $users;
	}

	public static function delete_for_user( int $wp ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'identities' ), array( 'wp_user_id' => $wp ), array( '%d' ) );
	}
}
