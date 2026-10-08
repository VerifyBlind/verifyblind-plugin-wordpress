<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

final class Schema {
	const DB_VERSION = '2';

	/** The plugin's own tables (Schema::table() adds the site prefix and "verifyblind_"). */
	const TABLES = array( 'nonces', 'results', 'identities', 'pending' );

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
		$p = self::table( 'pending' );
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
				"CREATE TABLE $p (
  owner varchar(40) NOT NULL,
  rule_id varchar(32) NOT NULL,
  vb_user_id varchar(191) NOT NULL,
  nsbd_id varchar(191) DEFAULT NULL,
  doc_id varchar(191) DEFAULT NULL,
  nonce varchar(64) NOT NULL,
  is_test tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (owner),
  KEY nonce (nonce),
  KEY created_at (created_at)
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
