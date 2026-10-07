<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

final class Results {
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

	/** @return string[] distinct conditions passed within the validity window (0 = forever). */
	public static function passed_conditions( string $owner, int $validity_days, bool $include_test ): array {
		global $wpdb;
		$sql  = 'SELECT DISTINCT cond FROM ' . Schema::table( 'results' ) . ' WHERE owner = %s AND passed = 1';
		$args = array( $owner );
		if ( $validity_days > 0 ) {
			$sql   .= ' AND verified_at >= %s';
			$args[] = gmdate( 'Y-m-d H:i:s', time() - $validity_days * DAY_IN_SECONDS );
		}
		if ( ! $include_test ) {
			$sql .= ' AND is_test = 0';
		}
		return array_map( 'strval', $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
	}

	/** @return string[] owners that had results for this nonce */
	public static function delete_by_nonce( string $nonce ): array {
		global $wpdb;
		$t      = Schema::table( 'results' );
		$owners = array_map( 'strval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT owner FROM $t WHERE nonce = %s", $nonce ) ) );
		$wpdb->delete( $t, array( 'nonce' => $nonce ), array( '%s' ) );
		return $owners;
	}

	public static function delete_cond( string $owner, string $cond ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'results' ), array( 'owner' => $owner, 'cond' => $cond ), array( '%s', '%s' ) );
	}

	public static function delete_owner( string $owner ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'results' ), array( 'owner' => $owner ), array( '%s' ) );
	}

	public static function purge_guests( int $max_age_seconds ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . Schema::table( 'results' ) . ' WHERE owner LIKE %s AND verified_at < %s',
				$wpdb->esc_like( 'g:' ) . '%',
				gmdate( 'Y-m-d H:i:s', time() - $max_age_seconds )
			)
		);
	}
}
