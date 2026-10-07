<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

/** Binds a VerifyBlind person code to an account, applying the rule's same-person policy. */
final class IdentityClaim {
	/** @return string 'ok' | 'duplicate' | 'different_identity' */
	public static function claim( string $person, int $user_id, ?string $nsbd, ?string $doc, string $nonce, string $policy ): string {
		$mine = Identities::find_by_wp_user( $user_id );
		if ( $mine ) {
			return $mine['vb_user_id'] === $person ? 'ok' : 'different_identity';
		}
		// A flag-accepted duplicate has no identity row, but the account still may not switch person later.
		$flagged = get_user_meta( $user_id, 'verifyblind_flag_person', true );
		if ( is_string( $flagged ) && '' !== $flagged && $flagged !== $person ) {
			return 'different_identity';
		}
		if ( Identities::insert( $person, $user_id, $nsbd, $doc, $nonce ) ) {
			return 'ok';
		}
		$existing = Identities::find_by_vb_user_id( $person );
		if ( ! $existing ) {
			return 'duplicate';
		}
		$other = (int) $existing['wp_user_id'];
		switch ( $policy ) {
			case 'flag':
				update_user_meta( $user_id, 'verifyblind_duplicate_of', $other );
				update_user_meta( $user_id, 'verifyblind_flag_person', $person );
				return 'ok';
			case 'transfer':
				Identities::move( $person, $user_id, $nonce, $nsbd, $doc );
				Results::delete_cond( Owner::for_user( $other ), 'uid' );
				Roles::sync_user( $other );
				return 'ok';
			default: // reject, block: the gated action stays closed
				return 'duplicate';
		}
	}
}
