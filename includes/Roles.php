<?php
namespace VerifyBlind;

final class Roles {
	const BASE = 'verifyblind_verified';
	const META = 'verifyblind_granted_roles';

	public static function install(): void {
		if ( ! get_role( self::BASE ) ) {
			add_role( self::BASE, 'VerifyBlind Verified', array( 'read' => true ) );
		}
	}
}
