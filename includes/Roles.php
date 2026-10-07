<?php
namespace VerifyBlind;

/**
 * Roles are a bridge to other plugins. Everyone with a valid result gets BASE; a rule may add its own
 * role. Roles are added next to the user's existing role, never replacing it, and only roles this
 * plugin granted (tracked in META) are ever taken away.
 */
final class Roles {
	const BASE = 'verifyblind_verified';
	const META = 'verifyblind_granted_roles';

	public static function install(): void {
		if ( ! get_role( self::BASE ) ) {
			add_role( self::BASE, 'VerifyBlind Verified', array( 'read' => true ) );
		}
	}

	public static function create( string $slug, string $label ): bool {
		$slug = sanitize_key( $slug );
		if ( '' === $slug || 'administrator' === $slug || get_role( $slug ) ) {
			return false;
		}
		return null !== add_role( $slug, $label, array( 'read' => true ) );
	}

	/** Capabilities that make a role privileged; a rule may never hand such a role out automatically. */
	const DANGEROUS_CAPS = array(
		'edit_posts',
		'edit_pages',
		'edit_others_posts',
		'publish_posts',
		'moderate_comments',
		'manage_options',
		'manage_categories',
		'promote_users',
		'list_users',
		'edit_users',
		'create_users',
		'delete_users',
		'remove_users',
		'unfiltered_html',
		'upload_files',
		'edit_theme_options',
		'activate_plugins',
		'install_plugins',
		'manage_woocommerce',
		'edit_shop_orders',
		'view_woocommerce_reports',
	);

	/** True only for an existing, non-privileged role. */
	public static function is_grantable( string $slug ): bool {
		if ( '' === $slug || 'administrator' === $slug ) {
			return false;
		}
		$role = get_role( $slug );
		if ( ! $role ) {
			return false;
		}
		foreach ( self::DANGEROUS_CAPS as $cap ) {
			if ( $role->has_cap( $cap ) ) {
				return false;
			}
		}
		return true;
	}

	/** $include_test null = the global test-mode setting. */
	public static function sync_user( int $user_id, ?bool $include_test = null ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$test  = null === $include_test ? Settings::test_mode() : $include_test;
		$owner = Owner::for_user( $user_id );
		$want  = array();
		if ( Results::passed_conditions( $owner, 0, $test ) ) {
			$want[] = self::BASE;
		}
		foreach ( Rules::all() as $rule ) {
			if ( empty( $rule['enabled'] ) || '' === $rule['role'] || ! self::is_grantable( $rule['role'] ) ) {
				continue;
			}
			if ( Evaluator::satisfies( $owner, $rule, $test ) ) {
				$want[] = $rule['role'];
			}
		}
		$want    = array_values( array_unique( $want ) );
		$granted = get_user_meta( $user_id, self::META, true );
		$granted = is_array( $granted ) ? $granted : array();
		foreach ( $want as $role ) {
			if ( ! in_array( $role, (array) $user->roles, true ) ) {
				$user->add_role( $role );
				$granted[] = $role;
			}
		}
		foreach ( $granted as $i => $role ) {
			if ( ! in_array( $role, $want, true ) ) {
				$user->remove_role( $role );
				unset( $granted[ $i ] );
			}
		}
		$granted = array_values( array_unique( $granted ) );
		if ( $granted ) {
			update_user_meta( $user_id, self::META, $granted );
		} else {
			delete_user_meta( $user_id, self::META );
		}
	}

	/** Re-evaluate everyone this plugin ever granted a role to (rule edits, validity expiry). */
	public static function sync_all(): void {
		$ids = get_users( array( 'meta_key' => self::META, 'fields' => 'ID', 'number' => -1 ) );
		foreach ( $ids as $id ) {
			self::sync_user( (int) $id );
		}
	}
}
