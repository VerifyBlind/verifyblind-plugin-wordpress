<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

/**
 * Deleting the plugin (Plugins → Installed plugins → Delete) removes everything it stored: its tables, options,
 * transients, user meta, the roles it handed out and the roles it created, the daily cron event and the
 * one-person coupon records on coupons. Orders keep their verification notes and records (part of the purchase
 * record; without the deleted site key the coupon records on orders no longer match anyone). A WooCommerce
 * session value expires with the session.
 */
final class Uninstaller {
	public static function run(): void {
		Cron::unschedule();
		self::remove_roles();
		self::drop_tables();
		self::delete_options();
		self::delete_user_meta();
		delete_post_meta_by_key( Placements\WcCoupon::META );
	}

	private static function remove_roles(): void {
		// Roles that rules handed out (existing roles too) are taken away from the people who got them here.
		$granted_to = get_users( array( 'meta_key' => Roles::META, 'fields' => 'ID', 'number' => -1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- runs once, on uninstall
		foreach ( $granted_to as $id ) {
			$user    = get_userdata( (int) $id );
			$granted = get_user_meta( (int) $id, Roles::META, true );
			if ( $user && is_array( $granted ) ) {
				foreach ( $granted as $role ) {
					$user->remove_role( (string) $role );
				}
			}
		}
		// The base role and the roles this plugin created: off every user, then deleted.
		foreach ( array_unique( array_merge( array( Roles::BASE ), Roles::created() ) ) as $slug ) {
			if ( ! get_role( $slug ) ) {
				continue;
			}
			foreach ( get_users( array( 'role' => $slug, 'fields' => 'ID', 'number' => -1 ) ) as $id ) {
				$user = get_userdata( (int) $id );
				if ( $user ) {
					$user->remove_role( $slug );
				}
			}
			remove_role( $slug );
		}
	}

	private static function drop_tables(): void {
		global $wpdb;
		foreach ( Schema::TABLES as $name ) {
			$t = Schema::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the plugin's own tables ($wpdb->prefix + a fixed name), removed on uninstall
		}
	}

	private static function delete_options(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- finds this plugin's option names once, on uninstall; each is then deleted through the options API
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'verifyblind_' ) . '%', $wpdb->esc_like( '_transient_verifyblind_' ) . '%', $wpdb->esc_like( '_transient_timeout_verifyblind_' ) . '%' ) );
		foreach ( $names as $name ) {
			$name = (string) $name;
			if ( 0 === strpos( $name, '_transient_timeout_' ) ) {
				delete_option( $name );
			} elseif ( 0 === strpos( $name, '_transient_' ) ) {
				delete_transient( substr( $name, strlen( '_transient_' ) ) );
			} else {
				delete_option( $name );
			}
		}
		// Transients kept in a persistent object cache never reach the options table.
		foreach ( array( 'verifyblind_enclave_key', 'verifyblind_webhook_key', 'verifyblind_enclave_key_refreshed', 'verifyblind_webhook_key_refreshed', Rest::RATE_KEY, Admin\Wizard::REDIRECT ) as $transient ) {
			delete_transient( $transient );
		}
	}

	private static function delete_user_meta(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- finds this plugin's user meta keys once, on uninstall; each is then deleted through the metadata API
		$keys = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'verifyblind_' ) . '%' ) );
		foreach ( $keys as $key ) {
			delete_metadata( 'user', 0, (string) $key, '', true );
		}
	}
}
