<?php
namespace VerifyBlind\Placements;

defined( 'ABSPATH' ) || exit;

use VerifyBlind\Gate;
use VerifyBlind\Rules;

/**
 * Whole-site entrance (age gate). Until the visitor meets the rule, every front-end page is replaced on the
 * server by a minimal page with the box — the requested page is never rendered. wp_head()/wp_footer() run so
 * the widget's scripts load. Both the gate and the open site are per-visitor: never cached.
 *
 * Limits (Plan 3 readme carries this): the entrance gate hides front-end pages only. REST API responses and
 * direct media (uploads) URLs are not hidden by it, and page caches should be purged when this placement is enabled.
 */
final class WcSite {
	const KEY = 'wc_site';

	public static function label(): string {
		return __( 'Whole site entrance (age gate for the shop)', 'verifyblind' );
	}

	public static function hooks(): void {
		// Before WooCommerce's own template_redirect work (wc-ajax runs at priority 0).
		add_action( 'template_redirect', array( self::class, 'maybe_gate' ), -100 );
	}

	public static function exempt(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return true;
		}
		// Mirror class-wc-ajax.php: `if ( $action )` after sanitising — empty and '0' are not an endpoint.
		if ( isset( $_GET['wc-ajax'] ) && is_string( $_GET['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only checks which endpoint this is
			$action = sanitize_text_field( wp_unslash( $_GET['wc-ajax'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( '' !== $action && '0' !== $action ) {
				return true;
			}
		}
		// Core's sitemap handler uses `! empty( get_query_var( 'sitemap' ) )`.
		if ( is_robots() || is_favicon() || ! empty( get_query_var( 'sitemap' ) ) ) {
			return true;
		}
		$privacy = (int) get_option( 'wp_page_for_privacy_policy' );
		if ( $privacy > 0 && is_page( $privacy ) ) {
			return true;
		}
		return Gate::bypass( null );
	}

	public static function blocking(): ?array {
		$rules = Rules::enabled( self::KEY );
		if ( ! $rules || self::exempt() ) {
			return null;
		}
		return Gate::blocking_rule( $rules );
	}

	/** @return string 'none' (no rule) | 'exempt' | 'open' (visitor meets the rule) | 'gate' */
	public static function decide(): string {
		$rules = Rules::enabled( self::KEY );
		if ( ! $rules ) {
			return 'none';
		}
		if ( self::exempt() ) {
			return 'exempt';
		}
		return null === Gate::blocking_rule( $rules ) ? 'open' : 'gate';
	}

	public static function maybe_gate(): void {
		$decision = self::decide();
		if ( 'none' === $decision ) {
			return;
		}
		Gate::no_cache();
		if ( 'gate' !== $decision ) {
			return;
		}
		$rule = self::blocking();
		if ( null === $rule ) {
			return;
		}
		status_header( 200 );
		echo self::page( $rule ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a full document built from escaped parts
		exit;
	}

	public static function page( array $rule ): string {
		// Build the box first: it enqueues the widget scripts and styles that wp_head()/wp_footer() print.
		$box     = Prompt::html( $rule, array( 'title' => __( 'Verify with VerifyBlind to enter this site.', 'verifyblind' ) ) );
		$privacy = get_privacy_policy_url();
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		ob_start();
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ( ! current_theme_supports( 'title-tag' ) ) : ?>
<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
<?php endif; ?>
<?php wp_head(); ?>
</head>
<body class="verifyblind-site-gate">
<main class="verifyblind-site-gate__main">
<h1><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1>
		<?php echo wp_kses_post( $box ); ?>
		<?php if ( '' !== $privacy ) : ?>
<p class="verifyblind-site-gate__privacy"><a href="<?php echo esc_url( $privacy ); ?>"><?php esc_html_e( 'Privacy policy', 'verifyblind' ); ?></a></p>
<?php endif; ?>
</main>
		<?php wp_footer(); ?>
</body>
</html>
		<?php
		remove_filter( 'wp_robots', 'wp_robots_no_robots' );
		return (string) ob_get_clean();
	}
}
