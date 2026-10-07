<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

final class Schema {
	const DB_VERSION = '1';

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'verifyblind_' . $name;
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$n = self::table( 'nonces' );
		$r = self::table( 'results' );
		$i = self::table( 'identities' );
		dbDelta(
			array(
				"CREATE TABLE $n (
  nonce varchar(64) NOT NULL,
  rule_id varchar(32) NOT NULL,
  age_cond varchar(16) NOT NULL DEFAULT '',
  want_uid tinyint(1) NOT NULL DEFAULT 0,
  owner varchar(40) NOT NULL,
  expires_at datetime NOT NULL,
  PRIMARY KEY  (nonce),
  KEY expires_at (expires_at)
) $c;",
				"CREATE TABLE $r (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  owner varchar(40) NOT NULL,
  cond varchar(16) NOT NULL,
  passed tinyint(1) NOT NULL,
  nonce varchar(64) NOT NULL,
  is_test tinyint(1) NOT NULL DEFAULT 0,
  verified_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY owner (owner),
  KEY nonce (nonce)
) $c;",
				"CREATE TABLE $i (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  vb_user_id varchar(191) NOT NULL,
  wp_user_id bigint(20) unsigned NOT NULL,
  nsbd_id varchar(191) DEFAULT NULL,
  doc_id varchar(191) DEFAULT NULL,
  nonce varchar(64) NOT NULL,
  verified_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY vb_user_id (vb_user_id),
  KEY wp_user_id (wp_user_id),
  KEY nonce (nonce)
) $c;",
			)
		);
		Roles::install();
		update_option( 'verifyblind_db_version', self::DB_VERSION );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'verifyblind_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}
}
