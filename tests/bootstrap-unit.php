<?php
// The plugin's files refuse to run outside WordPress (direct-access guard); the WordPress-free unit suite
// only loads the pure classes, so it stands in for the constant.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}
$root = dirname( __DIR__ );
require $root . '/vendor/autoload.php';
require $root . '/vendor-prefixed/autoload.php';
require $root . '/includes/autoload.php';
