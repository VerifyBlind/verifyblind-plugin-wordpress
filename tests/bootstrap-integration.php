<?php
// Runs inside the vb-wp-test-web container: loads WordPress (and the active plugin) from wp-load.php.
$_SERVER['HTTP_HOST']   = 'localhost:8080';
$_SERVER['SERVER_NAME'] = 'localhost';
require dirname( __DIR__ ) . '/vendor/autoload.php';
// PHPUnit includes this file inside a function: core files that assign globals at file scope
// (shortcodes.php sets $shortcode_tags) would otherwise shadow the real global and lose every shortcode.
global $shortcode_tags;
require getenv( 'VB_WP_LOAD' ) ?: '/var/www/html/wp-load.php';
if ( ! class_exists( 'VerifyBlind\\Plugin' ) || ! defined( 'VERIFYBLIND_VERSION' ) ) {
	fwrite( STDERR, "VerifyBlind plugin is not active on the test site\n" );
	exit( 1 );
}
