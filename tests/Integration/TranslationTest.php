<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Badge;
use VerifyBlind\Messages;
use VerifyBlind\Widget;

/** The Turkish catalogue loads from the plugin's languages folder, for PHP and for the block editor script. */
final class TranslationTest extends TestCase {
	public function test_tests_run_with_the_english_source(): void {
		$this->assertSame( 'en_US', determine_locale() );
		$this->assertSame( 'Verification complete.', Messages::get( 'ok' ) );
	}

	public function test_turkish_strings(): void {
		$this->assertTrue( switch_to_locale( 'tr_TR' ) );
		$this->assertSame( 'Doğrulama tamamlandı.', Messages::get( 'ok' ) );
		$this->assertSame( 'Doğrulama şu anda yapılamıyor. Lütfen biraz sonra tekrar deneyin.', Messages::get( 'api_unreachable' ) );
		$rule = $this->rule( array( 'age' => '18+' ) );
		$this->assertSame( 'Bu içerik 18 yaş ve üzerindeki ziyaretçilere açıktır.', Widget::describe( $rule ) );
		$box = Widget::box( $rule );
		$this->assertStringContainsString( esc_html( 'VerifyBlind ile doğrula' ), $box );
		$this->assertStringContainsString( esc_html( 'Nasıl çalışır?' ), $box );
		$this->assertStringContainsString( 'https://verifyblind.com/tr/how-it-works', $box );
		$this->assertStringContainsString( 'alt="VerifyBlind ile doğrulandı"', Badge::html() );
		$this->assertSame( 'Kurulum sihirbazı', __( 'Setup wizard', 'verifyblind' ) );
		$json = load_script_textdomain( 'verifyblind-gate-block', 'verifyblind', VERIFYBLIND_DIR . 'languages' );
		$this->assertIsString( $json );
		$this->assertStringContainsString( 'VerifyBlind kilidi', (string) $json );
	}
}
