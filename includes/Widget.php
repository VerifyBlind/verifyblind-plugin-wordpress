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

	/** Box styles only. Also works where wp_enqueue_scripts never runs (wp-login.php): late styles print in the footer. */
	public static function enqueue_style(): void {
		if ( ! wp_style_is( 'verifyblind-front', 'registered' ) ) {
			self::register();
		}
		wp_enqueue_style( 'verifyblind-front' );
	}

	public static function enqueue(): void {
		if ( ! wp_script_is( 'verifyblind-front', 'registered' ) ) {
			self::register();
		}
		wp_enqueue_script( 'verifyblind-front' );
		self::enqueue_style();
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
					'success'  => __( 'Verified. Reloading…', 'verifyblind' ),
					'verified' => __( 'Verified. You can continue.', 'verifyblind' ),
					'failed'   => __( 'Verification could not be completed. Please try again.', 'verifyblind' ),
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

	/** What is asked, in one short line — for boxes whose title names the action ("Verify to comment"). */
	public static function requirement( array $rule ): string {
		$parts = array();
		$age   = ( isset( $rule['age'] ) && '' !== $rule['age'] ) ? AgeRule::parse( (string) $rule['age'] ) : null;
		if ( null !== $age ) {
			if ( null === $age->max() ) {
				/* translators: %d: minimum age */
				$parts[] = sprintf( __( 'Age %d or over.', 'verifyblind' ), $age->min() );
			} elseif ( 0 === $age->min() ) {
				/* translators: %d: age limit */
				$parts[] = sprintf( __( 'Under %d.', 'verifyblind' ), $age->max() );
			} else {
				/* translators: 1: minimum age, 2: maximum age (inclusive) */
				$parts[] = sprintf( __( 'Age %1$d to %2$d.', 'verifyblind' ), $age->min(), $age->max() - 1 );
			}
		}
		if ( ! empty( $rule['unique'] ) ) {
			$parts[] = __( 'One person, one account.', 'verifyblind' );
		}
		return implode( ' ', $parts );
	}

	/**
	 * @param array $opts 'title' (string: replaces describe() and adds the requirement line),
	 *                    'reload' (bool, default true: reload after a passing verification; false keeps a form's input).
	 */
	public static function box( array $rule, array $opts = array() ): string {
		self::enqueue();
		$cid    = 'verifyblind-container-' . wp_unique_id();
		$reload = ! isset( $opts['reload'] ) || false !== $opts['reload'];
		return sprintf(
			'<div class="verifyblind-box" data-rule="%1$s" data-reload="%2$s"><p class="verifyblind-box__title">%3$s</p>%4$s<p class="verifyblind-box__text">%5$s</p><button type="button" class="verifyblind-start">%6$s</button><div id="%7$s" class="verifyblind-container"></div><p class="verifyblind-box__msg" role="status"></p><p class="verifyblind-box__help"><a href="%8$s" target="_blank" rel="noopener">%9$s</a></p></div>',
			esc_attr( $rule['id'] ),
			$reload ? '1' : '0',
			esc_html( self::title( $rule, $opts ) ),
			self::requirement_html( $rule, $opts ),
			esc_html__( 'Verify without sharing your identity data. The site only receives an eligible / not eligible answer.', 'verifyblind' ),
			esc_html__( 'Verify with VerifyBlind', 'verifyblind' ),
			esc_attr( $cid ),
			esc_url( self::is_turkish() ? 'https://verifyblind.com/tr/how-it-works' : 'https://verifyblind.com/en/how-it-works' ),
			esc_html__( 'How does it work?', 'verifyblind' )
		);
	}

	/**
	 * For checks that need an account: log-in (and, when the site allows it, sign-up) links instead of the widget.
	 *
	 * @param array $opts 'title' (string), 'redirect' (string: where log-in returns to; default the current post or home).
	 */
	public static function login_box( array $rule, array $opts = array() ): string {
		self::enqueue_style();
		$redirect = ( isset( $opts['redirect'] ) && '' !== (string) $opts['redirect'] ) ? (string) $opts['redirect'] : ( is_singular() ? (string) get_permalink() : home_url( '/' ) );
		$links    = sprintf( '<a class="verifyblind-login" href="%1$s">%2$s</a>', esc_url( wp_login_url( $redirect ) ), esc_html__( 'Log in', 'verifyblind' ) );
		$register = self::registration_url();
		if ( '' !== $register ) {
			$links .= sprintf( '<a class="verifyblind-register" href="%1$s">%2$s</a>', esc_url( $register ), esc_html__( 'Create an account', 'verifyblind' ) );
		}
		return sprintf(
			'<div class="verifyblind-box verifyblind-box--login" data-rule="%1$s"><p class="verifyblind-box__title">%2$s</p>%3$s<p class="verifyblind-box__text">%4$s</p><p class="verifyblind-box__links">%5$s</p></div>',
			esc_attr( $rule['id'] ),
			esc_html( self::title( $rule, $opts ) ),
			self::requirement_html( $rule, $opts ),
			esc_html__( 'This check needs an account: log in first, then verify.', 'verifyblind' ),
			$links
		);
	}

	/** Where a visitor creates an account: a shop's My Account page (WooCommerce sign-up), else wp-login.php; '' when the site has no sign-up. */
	private static function registration_url(): string {
		if ( function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'myaccount' ) > 0 && 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) ) {
			return (string) wc_get_page_permalink( 'myaccount' );
		}
		return get_option( 'users_can_register' ) ? (string) wp_registration_url() : '';
	}

	private static function title( array $rule, array $opts ): string {
		return ( isset( $opts['title'] ) && '' !== (string) $opts['title'] ) ? (string) $opts['title'] : self::describe( $rule );
	}

	/** Only boxes with a custom title get the requirement line (describe() already says it). */
	private static function requirement_html( array $rule, array $opts ): string {
		if ( ! isset( $opts['title'] ) || '' === (string) $opts['title'] ) {
			return '';
		}
		$req = self::requirement( $rule );
		return '' === $req ? '' : '<p class="verifyblind-box__req">' . esc_html( $req ) . '</p>';
	}

	private static function is_turkish(): bool {
		return 0 === strpos( determine_locale(), 'tr' );
	}
}
