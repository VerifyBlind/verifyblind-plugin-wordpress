<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

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

	/** Roles this plugin created (from the rule editor's "new role"); uninstall removes them. */
	const CREATED_OPTION = 'verifyblind_created_roles';

	public static function create( string $slug, string $label ): bool {
		$slug = sanitize_key( $slug );
		if ( '' === $slug || 'administrator' === $slug || get_role( $slug ) ) {
			return false;
		}
		if ( null === add_role( $slug, $label, array( 'read' => true ) ) ) {
			return false;
		}
		$created   = self::created();
		$created[] = $slug;
		update_option( self::CREATED_OPTION, array_values( array_unique( $created ) ), false );
		return true;
	}

	/** @return string[] */
	public static function created(): array {
		$value = get_option( self::CREATED_OPTION, array() );
		return is_array( $value ) ? array_values( array_filter( array_map( 'strval', $value ) ) ) : array();
	}

	/**
	 * The only capabilities a role handed out automatically may grant: reading, plus taking part in
	 * bbPress forums as an ordinary member. Anything else (editing, moderating, shop management, any
	 * level above 0, any plugin's own capability) makes the role privileged.
	 */
	const ALLOWED_CAPS = array(
		'read',
		'level_0',
		'spectate',
		'participate',
		'read_private_forums',
		'publish_topics',
		'edit_topics',
		'publish_replies',
		'edit_replies',
		'assign_topic_tags',
	);

	/** True only for an existing, non-administrator role whose every granted capability is allowed. */
	public static function is_grantable( string $slug ): bool {
		if ( '' === $slug || 'administrator' === $slug ) {
			return false;
		}
		$role = get_role( $slug );
		if ( ! $role ) {
			return false;
		}
		foreach ( (array) $role->capabilities as $cap => $granted ) {
			if ( ! empty( $granted ) && ! in_array( (string) $cap, self::ALLOWED_CAPS, true ) ) {
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
		$ids = get_users( array( 'meta_key' => self::META, 'fields' => 'ID', 'number' => -1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only users this plugin gave a role (rule edits, daily cron)
		foreach ( $ids as $id ) {
			self::sync_user( (int) $id );
		}
	}
}
