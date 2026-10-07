<?php
defined( 'ABSPATH' ) || exit;

// VerifyBlind\Foo\Bar -> includes/Foo/Bar.php. Prefixed vendor classes (VerifyBlind\Vendor\...) and
// tests (VerifyBlind\Tests\...) are owned by their own autoloaders.
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'VerifyBlind\\';
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		if ( strpos( $relative, 'Vendor\\' ) === 0 || strpos( $relative, 'Tests\\' ) === 0 ) {
			return;
		}
		$file = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);
