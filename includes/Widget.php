<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

final class Widget {
	const SDK_URL = 'https://cdn.verifyblind.com/sdk/v1.0.1/verifyblind.js';
	const SDK_SRI = 'sha384-DkPyvLVGNq29kFGtgdih7jgC8YhOpGoyIK6PPPSe2atkZju7SlMbKRPLbp/1gYw1';

	/** @var bool */
	private static $localized = false;

	public static function hooks(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'register' ) );
		add_filter( 'script_loader_tag', array( self::class, 'sri' ), 10, 2 );
	}

	public static function register(): void {
		// Loaded from VerifyBlind's CDN (WordPress.org guideline 8 allows a documented service's code),
		// pinned to an immutable version and an SRI hash.
		wp_register_script( 'verifyblind-sdk', self::SDK_URL, array(), null, true );
		wp_register_script( 'verifyblind-front', VERIFYBLIND_URL . 'assets/js/front.js', array( 'verifyblind-sdk' ), VERIFYBLIND_VERSION, true );
		wp_register_style( 'verifyblind-front', VERIFYBLIND_URL . 'assets/css/front.css', array(), VERIFYBLIND_VERSION );
	}

	public static function sri( string $tag, string $handle ): string {
		if ( 'verifyblind-sdk' !== $handle ) {
			return $tag;
		}
		return preg_replace( '/<script /', '<script integrity="' . esc_attr( self::SDK_SRI ) . '" crossorigin="anonymous" ', $tag, 1 );
	}

	public static function enqueue(): void {
		if ( ! wp_script_is( 'verifyblind-front', 'registered' ) ) {
			self::register();
		}
		wp_enqueue_script( 'verifyblind-front' );
		wp_enqueue_style( 'verifyblind-front' );
		if ( self::$localized ) {
			return;
		}
		self::$localized = true;
		wp_localize_script(
			'verifyblind-front',
			'VerifyBlindWP',
			array(
				'generateUrl' => rest_url( Rest::NS . '/generate' ),
				'verifyUrl'   => rest_url( Rest::NS . '/verify' ),
				// Guests need no REST nonce, and a stale one (cached page, old tab) makes WordPress refuse the
				// request: only logged-in pages (never page-cached) carry it.
				'restNonce'   => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'captcha'     => Settings::captcha() ? '1' : '0',
				'locale'      => self::is_turkish() ? 'tr' : 'en',
				'i18n'        => array(
					'success' => __( 'Verified. Reloading…', 'verifyblind' ),
					'failed'  => __( 'Verification could not be completed. Please try again.', 'verifyblind' ),
				),
			)
		);
	}

	public static function describe( array $rule ): string {
		$age = '' !== $rule['age'] ? AgeRule::parse( $rule['age'] ) : null;
		if ( null === $age ) {
			return __( 'This content is available to verified members.', 'verifyblind' );
		}
		if ( null === $age->max() ) {
			/* translators: %d: minimum age */
			return sprintf( __( 'This content is available to visitors aged %d and over.', 'verifyblind' ), $age->min() );
		}
		if ( 0 === $age->min() ) {
			/* translators: %d: age limit */
			return sprintf( __( 'This content is available to visitors under %d.', 'verifyblind' ), $age->max() );
		}
		/* translators: 1: minimum age, 2: maximum age (inclusive) */
		return sprintf( __( 'This content is available to visitors aged %1$d to %2$d.', 'verifyblind' ), $age->min(), $age->max() - 1 );
	}

	public static function box( array $rule ): string {
		self::enqueue();
		$cid = 'verifyblind-container-' . wp_unique_id();
		return sprintf(
			'<div class="verifyblind-box" data-rule="%1$s"><p class="verifyblind-box__title">%2$s</p><p class="verifyblind-box__text">%3$s</p><button type="button" class="verifyblind-start">%4$s</button><div id="%5$s" class="verifyblind-container"></div><p class="verifyblind-box__msg" role="status"></p><p class="verifyblind-box__help"><a href="%6$s" target="_blank" rel="noopener">%7$s</a></p></div>',
			esc_attr( $rule['id'] ),
			esc_html( self::describe( $rule ) ),
			esc_html__( 'Verify without sharing your identity data. The site only receives an eligible / not eligible answer.', 'verifyblind' ),
			esc_html__( 'Verify with VerifyBlind', 'verifyblind' ),
			esc_attr( $cid ),
			esc_url( self::is_turkish() ? 'https://verifyblind.com/tr/how-it-works' : 'https://verifyblind.com/en/how-it-works' ),
			esc_html__( 'How does it work?', 'verifyblind' )
		);
	}

	private static function is_turkish(): bool {
		return 0 === strpos( determine_locale(), 'tr' );
	}
}
