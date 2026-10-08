<?php
namespace VerifyBlind\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** readme.txt follows the WordPress.org format and matches the plugin header. */
final class ReadmeTest extends TestCase {
	/** @var string */
	private $readme = '';

	protected function setUp(): void {
		$this->readme = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__, 2 ) . '/readme.txt' ) );
	}

	private function header( string $name ): string {
		$this->assertSame( 1, preg_match( '/^' . preg_quote( $name, '/' ) . ':[ \t]*(.+)$/m', $this->readme, $m ), $name );
		return trim( $m[1] );
	}

	private function plugin_version(): string {
		$main = (string) file_get_contents( dirname( __DIR__, 2 ) . '/verifyblind.php' );
		$this->assertSame( 1, preg_match( '/^ \* Version:\s+(\S+)$/m', $main, $h ) );
		$this->assertSame( 1, preg_match( "/define\( 'VERIFYBLIND_VERSION', '([^']+)' \);/", $main, $c ) );
		$this->assertSame( $h[1], $c[1], 'header and constant agree' );
		return $h[1];
	}

	public function test_headers(): void {
		$this->assertSame( '1.0.0', $this->plugin_version() );
		$this->assertSame( $this->plugin_version(), $this->header( 'Stable tag' ) );
		$this->assertSame( '6.0', $this->header( 'Requires at least' ) );
		$this->assertSame( '7.1', $this->header( 'Tested up to' ) );
		$this->assertSame( '7.4', $this->header( 'Requires PHP' ) );
		$this->assertSame( 'GPLv2 or later', $this->header( 'License' ) );
		$this->assertLessThanOrEqual( 5, count( array_map( 'trim', explode( ',', $this->header( 'Tags' ) ) ) ) );
	}

	public function test_short_description_fits(): void {
		$this->assertSame( 1, preg_match( '/\n\n([^\n=][^\n]*)\n\n== Description ==/', $this->readme, $m ) );
		$this->assertLessThanOrEqual( 150, mb_strlen( $m[1], 'UTF-8' ) );
	}

	public function test_sections(): void {
		foreach ( array( '== Description ==', '= External services =', '== Installation ==', '== Frequently Asked Questions ==', '== Screenshots ==', '== Changelog ==', '= 1.0.0 =' ) as $s ) {
			$this->assertStringContainsString( "\n" . $s . "\n", $this->readme, $s );
		}
	}

	public function test_external_services_name_every_host_and_policy(): void {
		$start = (int) strpos( $this->readme, '= External services =' );
		$block = substr( $this->readme, $start, (int) strpos( $this->readme, '== Installation ==' ) - $start );
		$needles = array(
			'https://api.verifyblind.com',
			'/api/pop/generate',
			'/api/public/enclave-key',
			'/api/public/webhook-signing-key',
			'/api/pop/result/',
			'https://cdn.verifyblind.com/sdk/v1.0.1/verifyblind.js',
			'qr-code-styling.min.js',
			'https://app.verifyblind.com',
			'https://verifyblind.com/en/terms',
			'https://verifyblind.com/en/privacy',
		);
		foreach ( $needles as $needle ) {
			$this->assertStringContainsString( $needle, $block, $needle );
		}
	}

	public function test_wording(): void {
		$this->assertDoesNotMatchRegularExpression( '/anonym/i', $this->readme );
	}
}
