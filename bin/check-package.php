<?php
/**
 * Checks an unpacked release with PHP 7.4: every PHP file parses, the prefixed phpseclib and the plugin's
 * classes load without the development vendor/ folder, and no development file is inside.
 * Usage: php bin/check-package.php <folder that holds verifyblind/>
 */
$root = rtrim( isset( $argv[1] ) ? $argv[1] : '', '/' ) . '/verifyblind';
if ( ! is_file( $root . '/verifyblind.php' ) ) {
	fwrite( STDERR, "no verifyblind/verifyblind.php under the given folder\n" );
	exit( 1 );
}
$fail = 0;
$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	$path = $file->getPathname();
	if ( '.php' !== substr( $path, -4 ) ) {
		continue;
	}
	$out  = array();
	$code = 0;
	exec( 'php -l ' . escapeshellarg( $path ) . ' 2>&1', $out, $code );
	if ( 0 !== $code ) {
		fwrite( STDERR, implode( "\n", $out ) . "\n" );
		$fail = 1;
	}
}
foreach ( array( 'vendor', 'tests', 'bin', 'dist', 'composer.json', 'composer.lock', 'phpunit.xml.dist', 'phpunit-integration.xml.dist', '.gitignore', '.gitattributes', 'README.md' ) as $dev ) {
	if ( file_exists( $root . '/' . $dev ) ) {
		fwrite( STDERR, "development file in the package: $dev\n" );
		$fail = 1;
	}
}
define( 'ABSPATH', sys_get_temp_dir() . '/' );
require $root . '/vendor-prefixed/autoload.php';
require $root . '/includes/autoload.php';
foreach ( array( 'VerifyBlind\Vendor\phpseclib3\Crypt\RSA', 'VerifyBlind\SignatureVerifier', 'VerifyBlind\AgeRule', 'VerifyBlind\Rules' ) as $class ) {
	if ( ! class_exists( $class ) ) {
		fwrite( STDERR, "class does not load: $class\n" );
		$fail = 1;
	}
}
echo $fail ? "FAIL\n" : "OK\n";
exit( $fail );
