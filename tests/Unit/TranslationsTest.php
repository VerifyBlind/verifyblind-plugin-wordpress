<?php
namespace VerifyBlind\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The Turkish catalogue translates every string of the template, keeps placeholders and VerifyBlind's wording. */
final class TranslationsTest extends TestCase {
	const KEY = array(
		'Verify with VerifyBlind' => 'VerifyBlind ile doğrula',
		'Verification complete.' => 'Doğrulama tamamlandı.',
		'This content is available to visitors aged %d and over.' => 'Bu içerik %d yaş ve üzerindeki ziyaretçilere açıktır.',
		'How does it work?' => 'Nasıl çalışır?',
		'Verified with VerifyBlind' => 'VerifyBlind ile doğrulandı',
		'Setup wizard' => 'Kurulum sihirbazı',
		'VerifyBlind lock' => 'VerifyBlind kilidi',
		'Verification is not available right now. Please try again shortly.' => 'Doğrulama şu anda yapılamıyor. Lütfen biraz sonra tekrar deneyin.',
		'One-person check' => 'Tek kişi kontrolü',
		'Log in' => 'Giriş yap',
		'Verify without sharing your identity data. The site only receives an eligible / not eligible answer.' => 'Kimlik bilgileriniz bu siteye ulaşmadan doğrulama yapın. Site yalnızca "uygun / uygun değil" yanıtını alır.',
		'Person code (pseudonymous, issued for this site only)' => 'Kişiye özel kod (takma ad; yalnızca bu site için üretilir)',
	);

	private static function file( string $name ): string {
		return dirname( __DIR__, 2 ) . '/languages/' . $name;
	}

	/** @return array[] each: ctxt, id, plural, str (string[] by index), fuzzy (bool) */
	private static function entries( string $file ): array {
		$text = str_replace( "\r\n", "\n", (string) file_get_contents( $file ) );
		$out  = array();
		foreach ( preg_split( "/\n{2,}/", trim( $text ) ) as $block ) {
			$e   = array( 'ctxt' => '', 'id' => null, 'plural' => '', 'str' => array(), 'fuzzy' => false );
			$cur = null;
			foreach ( explode( "\n", $block ) as $line ) {
				if ( 0 === strpos( $line, '#,' ) && false !== strpos( $line, 'fuzzy' ) ) {
					$e['fuzzy'] = true;
					continue;
				}
				if ( '' === $line || '#' === $line[0] ) {
					continue;
				}
				if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr)(?:\[(\d+)\])?\s+"(.*)"$/', $line, $m ) ) {
					$value = stripcslashes( $m[3] );
					if ( 'msgstr' === $m[1] ) {
						$cur                 = array( 'str', '' === $m[2] ? 0 : (int) $m[2] );
						$e['str'][ $cur[1] ] = $value;
					} else {
						$map       = array( 'msgctxt' => 'ctxt', 'msgid' => 'id', 'msgid_plural' => 'plural' );
						$cur       = array( $map[ $m[1] ], null );
						$e[ $cur[0] ] = $value;
					}
				} elseif ( null !== $cur && preg_match( '/^"(.*)"$/', $line, $m ) ) {
					if ( 'str' === $cur[0] ) {
						$e['str'][ $cur[1] ] .= stripcslashes( $m[1] );
					} else {
						$e[ $cur[0] ] .= stripcslashes( $m[1] );
					}
				}
			}
			if ( null !== $e['id'] ) {
				$out[] = $e;
			}
		}
		return $out;
	}

	private static function by_key( array $entries ): array {
		$out = array();
		foreach ( $entries as $e ) {
			$out[ $e['ctxt'] . "\4" . $e['id'] ] = $e;
		}
		return $out;
	}

	private static function placeholders( string $s ): array {
		preg_match_all( '/%(?:\d+\$)?[sd]/', $s, $m );
		$p = $m[0];
		sort( $p );
		return $p;
	}

	public function test_header(): void {
		$po = self::by_key( self::entries( self::file( 'verifyblind-tr_TR.po' ) ) );
		$this->assertArrayHasKey( "\4", $po );
		$this->assertStringContainsString( 'Language: tr_TR', $po["\4"]['str'][0] );
		$this->assertStringContainsString( 'Plural-Forms: nplurals=2; plural=(n > 1);', $po["\4"]['str'][0] );
	}

	public function test_every_template_string_is_translated(): void {
		$pot = self::by_key( self::entries( self::file( 'verifyblind.pot' ) ) );
		$po  = self::by_key( self::entries( self::file( 'verifyblind-tr_TR.po' ) ) );
		$this->assertGreaterThan( 200, count( $pot ) );
		$this->assertSame( array(), array_values( array_diff( array_keys( $pot ), array_keys( $po ) ) ), 'in the template but not in the Turkish file' );
		$untranslated = array();
		foreach ( $po as $e ) {
			if ( '' === $e['id'] ) {
				continue;
			}
			$want = '' === $e['plural'] ? 1 : 2;
			if ( $e['fuzzy'] || count( array_filter( $e['str'], 'strlen' ) ) < $want ) {
				$untranslated[] = $e['id'];
			}
		}
		$this->assertSame( array(), $untranslated );
	}

	public function test_placeholders_are_kept(): void {
		foreach ( self::entries( self::file( 'verifyblind-tr_TR.po' ) ) as $e ) {
			if ( '' === $e['id'] ) {
				continue;
			}
			foreach ( $e['str'] as $i => $s ) {
				$source = ( $i > 0 && '' !== $e['plural'] ) ? $e['plural'] : $e['id'];
				$this->assertSame( self::placeholders( $source ), self::placeholders( $s ), $e['id'] );
			}
		}
	}

	public function test_wording(): void {
		foreach ( self::entries( self::file( 'verifyblind-tr_TR.po' ) ) as $e ) {
			if ( '' === $e['id'] ) {
				continue;
			}
			$source = mb_strtolower( $e['id'] . ' ' . $e['plural'], 'UTF-8' );
			foreach ( $e['str'] as $s ) {
				$t = mb_strtolower( $s, 'UTF-8' );
				$this->assertStringNotContainsString( 'anonim', $t, $e['id'] );
				$this->assertStringNotContainsString( 'paylaş', $t, $e['id'] );
				if ( false !== strpos( $t, 'giriş' ) ) {
					$this->assertMatchesRegularExpression( '/log ?in|logged in|entrance/', $source, '"Giriş" only for a real account log-in or the site entrance: ' . $e['id'] );
				}
				if ( false !== strpos( str_replace( 'verifyblind', '', $source ), 'verif' ) ) {
					$this->assertStringContainsString( 'doğrula', $t, $e['id'] );
				}
			}
		}
	}

	public function test_key_strings(): void {
		$po = self::by_key( self::entries( self::file( 'verifyblind-tr_TR.po' ) ) );
		foreach ( self::KEY as $id => $tr ) {
			$this->assertArrayHasKey( "\4" . $id, $po, $id );
			$this->assertSame( $tr, $po[ "\4" . $id ]['str'][0], $id );
		}
	}

	public function test_block_editor_catalogue_exists(): void {
		$json = self::file( 'verifyblind-tr_TR-' . md5( 'assets/js/gate-block.js' ) . '.json' );
		$this->assertFileExists( $json );
		$this->assertStringContainsString( 'VerifyBlind kilidi', (string) file_get_contents( $json ) );
		$this->assertFileExists( self::file( 'verifyblind-tr_TR.mo' ) );
	}
}
