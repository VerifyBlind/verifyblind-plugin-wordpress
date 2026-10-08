<?php
namespace VerifyBlind\Admin;

defined( 'ABSPATH' ) || exit;

use VerifyBlind\ApiClient;
use VerifyBlind\Placements\Registry;
use VerifyBlind\Plugin;
use VerifyBlind\Presets;
use VerifyBlind\Rest;
use VerifyBlind\Roles;
use VerifyBlind\Rules;
use VerifyBlind\Targets;

/**
 * Setup wizard: 1 connection, 2 kind of site (ready-made rules), 3 review, 4 finish. It opens once after
 * activation - only for the admin who activated the plugin, never on bulk or network activation, never after
 * the setup was finished or skipped - and again from VerifyBlind → Setup wizard. Each step posts to
 * admin-post.php with its own nonce; every handler checks manage_options first.
 */
final class Wizard {
	const SLUG     = 'verifyblind-wizard';
	const DONE     = 'verifyblind_wizard_done';
	const REDIRECT = 'verifyblind_wizard_redirect';
	const RESULT   = 'verifyblind_wizard_result_';
	const STEPS    = array( 'connect', 'site', 'review', 'finish' );

	public static function hooks(): void {
		add_action( 'admin_init', array( self::class, 'maybe_redirect' ) );
		add_action( 'admin_post_verifyblind_wizard_connect', array( self::class, 'handle_connect' ) );
		add_action( 'admin_post_verifyblind_wizard_site', array( self::class, 'handle_site' ) );
		add_action( 'admin_post_verifyblind_wizard_review', array( self::class, 'handle_review' ) );
		add_action( 'admin_post_verifyblind_wizard_finish', array( self::class, 'handle_finish' ) );
		add_action( 'admin_post_verifyblind_wizard_skip', array( self::class, 'handle_skip' ) );
	}

	public static function url( string $step = 'connect' ): string {
		return add_query_arg( array( 'page' => self::SLUG, 'step' => $step ), admin_url( 'admin.php' ) );
	}

	/** On activation: remember who activated the plugin, so only they are taken to the wizard (once). */
	public static function queue_redirect(): void {
		$uid = get_current_user_id();
		if ( $uid > 0 && '1' !== get_option( self::DONE ) ) {
			set_transient( self::REDIRECT, $uid, MINUTE_IN_SECONDS );
		}
	}

	/** Where to send this request after activation, or null. Only the activating admin uses the redirect up. */
	public static function redirect_target(): ?string {
		$uid = (int) get_transient( self::REDIRECT );
		if ( $uid <= 0 || get_current_user_id() !== $uid ) {
			return null;
		}
		delete_transient( self::REDIRECT );
		$bulk = isset( $_GET['activate-multi'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only tells a bulk activation apart
		if ( $bulk || wp_doing_ajax() || is_network_admin() || '1' === get_option( self::DONE ) || ! current_user_can( 'manage_options' ) ) {
			return null;
		}
		return self::url();
	}

	public static function maybe_redirect(): void {
		$to = self::redirect_target();
		if ( null !== $to ) {
			wp_safe_redirect( $to );
			exit;
		}
	}

	public static function current_step(): string {
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects which step to show
		return in_array( $step, self::STEPS, true ) ? $step : 'connect';
	}

	/**
	 * Saves a non-empty key (an empty field keeps the saved one) and tests it against VerifyBlind.
	 *
	 * @return array{ok:bool, message:string}
	 */
	public static function save_connection( string $key ): array {
		$key = trim( sanitize_text_field( $key ) );
		if ( '' !== $key ) {
			update_option( 'verifyblind_api_key', $key );
		}
		return Plugin::api()->test_connection();
	}

	/** Step 3: the ticked rules are on, the others off. A rule whose placement is not available stays as it is. */
	public static function apply_toggles( array $enabled_ids ): void {
		$enabled_ids = array_map( 'strval', $enabled_ids );
		foreach ( Rules::all() as $id => $rule ) {
			$want = in_array( (string) $id, $enabled_ids, true );
			if ( ! empty( $rule['enabled'] ) === $want ) {
				continue;
			}
			$rule['enabled'] = $want;
			try {
				Rules::save( $rule );
			} catch ( \InvalidArgumentException $e ) {
				continue; // e.g. a WooCommerce rule while WooCommerce is off
			}
		}
		Roles::sync_all();
	}

	public static function finish(): void {
		update_option( self::DONE, '1', false );
	}

	/** A warning for rules that apply to nothing until their pages, products or coupons are chosen. */
	public static function target_hint( array $rule ): string {
		if ( ! Targets::is_empty( $rule ) ) {
			return '';
		}
		switch ( isset( $rule['placement'] ) ? (string) $rule['placement'] : '' ) {
			case 'content':
				return __( 'Applies to nothing yet: choose pages or categories, or put the "VerifyBlind lock" block on a page.', 'verifyblind' );
			case 'wc_checkout':
			case 'wc_product':
				return __( 'Applies to nothing yet: choose the products or product categories.', 'verifyblind' );
			case 'wc_coupon':
				return __( 'Applies to nothing yet: choose the coupons.', 'verifyblind' );
			default:
				return '';
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$step  = self::current_step();
		$index = (int) array_search( $step, self::STEPS, true );
		echo '<div class="wrap verifyblind-wizard"><h1>' . esc_html__( 'VerifyBlind setup', 'verifyblind' ) . '</h1>';
		/* translators: 1: number of the current step, 2: number of steps */
		echo '<p class="description">' . esc_html( sprintf( __( 'Step %1$d of %2$d', 'verifyblind' ), $index + 1, count( self::STEPS ) ) ) . '</p>';
		switch ( $step ) {
			case 'site':
				self::render_site();
				break;
			case 'review':
				self::render_review();
				break;
			case 'finish':
				self::render_finish();
				break;
			default:
				self::render_connect();
		}
		$skip = wp_nonce_url( admin_url( 'admin-post.php?action=verifyblind_wizard_skip' ), 'verifyblind_wizard_skip' );
		echo '<p><a href="' . esc_url( $skip ) . '">' . esc_html__( 'Skip the setup wizard', 'verifyblind' ) . '</a></p></div>';
	}

	private static function render_connect(): void {
		$key    = self::RESULT . get_current_user_id();
		$result = get_transient( $key );
		delete_transient( $key );
		$has_key = '' !== ApiClient::api_key();
		?>
		<h2><?php esc_html_e( '1 · Connect your site', 'verifyblind' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %s: partner portal link */
				esc_html__( 'Create a free partner account at %s and copy the API key from Settings. 2,000 verifications a month are free.', 'verifyblind' ),
				'<a href="https://partner.verifyblind.com" target="_blank" rel="noopener">partner.verifyblind.com</a>'
			);
			?>
		</p>
		<?php if ( is_array( $result ) ) : ?>
			<div class="notice notice-<?php echo ! empty( $result['ok'] ) ? 'success' : 'error'; ?> inline"><p><?php echo esc_html( (string) $result['message'] ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="verifyblind_wizard_connect">
			<?php wp_nonce_field( 'verifyblind_wizard_connect' ); ?>
			<p><label for="verifyblind_api_key"><?php esc_html_e( 'API key', 'verifyblind' ); ?></label><br>
				<input type="password" id="verifyblind_api_key" name="verifyblind_api_key" class="regular-text" autocomplete="off" value=""
					placeholder="<?php echo $has_key ? esc_attr__( 'Saved — leave empty to keep it', 'verifyblind' ) : ''; ?>"></p>
			<?php submit_button( __( 'Save and test the connection', 'verifyblind' ), 'primary', 'submit', false ); ?>
		</form>
		<p><a class="button" href="<?php echo esc_url( self::url( 'site' ) ); ?>"><?php esc_html_e( 'Next', 'verifyblind' ); ?></a></p>
		<?php
	}

	private static function render_site(): void {
		$wc = Registry::woocommerce_active();
		?>
		<h2><?php esc_html_e( '2 · What kind of site is this?', 'verifyblind' ); ?></h2>
		<p><?php esc_html_e( 'Choose all that apply. Each choice adds ready-made rules that you can change later.', 'verifyblind' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="verifyblind_wizard_site">
			<?php wp_nonce_field( 'verifyblind_wizard_site' ); ?>
			<?php foreach ( Presets::all() as $key => $preset ) : ?>
				<?php $off = $preset['woocommerce'] && ! $wc; ?>
				<p><label<?php echo $off ? ' style="opacity:.55"' : ''; ?>>
					<input type="checkbox" name="presets[]" value="<?php echo esc_attr( $key ); ?>" <?php disabled( $off ); ?>>
					<strong><?php echo esc_html( $preset['label'] ); ?></strong>
					<?php if ( $off ) : ?>
						— <?php esc_html_e( 'needs WooCommerce', 'verifyblind' ); ?>
					<?php endif; ?>
					<br><span class="description"><?php echo esc_html( $preset['help'] ); ?></span>
				</label></p>
			<?php endforeach; ?>
			<?php submit_button( __( 'Add these rules', 'verifyblind' ), 'primary', 'submit', false ); ?>
			<a class="button" href="<?php echo esc_url( self::url( 'review' ) ); ?>"><?php esc_html_e( 'Skip this step', 'verifyblind' ); ?></a>
		</form>
		<?php
	}

	private static function render_review(): void {
		$rules  = Rules::all();
		$active = Rules::placements();
		$labels = $active + Registry::unavailable();
		?>
		<h2><?php esc_html_e( '3 · Review your rules', 'verifyblind' ); ?></h2>
		<p><?php esc_html_e( 'Switch rules on or off here. Open a rule to choose its pages, products or coupons, or to change what it asks.', 'verifyblind' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="verifyblind_wizard_review">
			<?php wp_nonce_field( 'verifyblind_wizard_review' ); ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'On', 'verifyblind' ); ?></th>
					<th><?php esc_html_e( 'Rule', 'verifyblind' ); ?></th>
					<th><?php esc_html_e( 'Where', 'verifyblind' ); ?></th>
					<th><?php esc_html_e( 'Asks for', 'verifyblind' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rules ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No rules yet. Go back a step to add ready-made rules, or add your own under VerifyBlind → Rules.', 'verifyblind' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rules as $rule ) : ?>
					<?php
					$available = isset( $active[ $rule['placement'] ] );
					$hint      = self::target_hint( $rule );
					$edit      = admin_url( 'admin.php?page=' . RulesPage::SLUG . '&action=edit&rule=' . rawurlencode( $rule['id'] ) );
					?>
					<tr>
						<td><input type="checkbox" name="enabled[]" value="<?php echo esc_attr( $rule['id'] ); ?>" <?php checked( ! empty( $rule['enabled'] ) ); ?> <?php disabled( ! $available ); ?>></td>
						<td><a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $rule['name'] ); ?></a>
							<?php if ( '' !== $hint ) : ?>
								<br><span class="description" style="color:#b32d2e"><?php echo esc_html( $hint ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( isset( $labels[ $rule['placement'] ] ) ? $labels[ $rule['placement'] ] : $rule['placement'] ); ?></td>
						<td><?php echo esc_html( self::asks( $rule ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Each requested item counts as one verification: age = 1, one-person check = 1. 2,000 verifications a month are free.', 'verifyblind' ); ?></p>
			<?php submit_button( __( 'Save and continue', 'verifyblind' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	private static function render_finish(): void {
		$revoke = rest_url( Rest::NS . '/revoke' );
		?>
		<h2><?php esc_html_e( '4 · Finish', 'verifyblind' ); ?></h2>
		<h3><?php esc_html_e( 'Revoke URL', 'verifyblind' ); ?></h3>
		<p><?php esc_html_e( 'Paste this address into the partner portal → Settings → Revoke URL. When a person withdraws consent in the VerifyBlind app, their result is removed here automatically. It must be HTTPS on your registered domain.', 'verifyblind' ); ?></p>
		<p><input type="text" id="verifyblind-revoke-url" readonly class="large-text code" value="<?php echo esc_attr( $revoke ); ?>">
			<button type="button" class="button" data-vb-copy="verifyblind-revoke-url" data-done="<?php echo esc_attr__( 'Copied', 'verifyblind' ); ?>"><?php esc_html_e( 'Copy', 'verifyblind' ); ?></button></p>
		<h3><?php esc_html_e( 'Privacy policy', 'verifyblind' ); ?></h3>
		<p><?php esc_html_e( 'A suggested paragraph for your privacy policy is in the WordPress privacy policy guide, under "VerifyBlind".', 'verifyblind' ); ?>
			<a href="<?php echo esc_url( admin_url( 'options-privacy.php?tab=policyguide' ) ); ?>"><?php esc_html_e( 'Open the policy guide', 'verifyblind' ); ?></a></p>
		<h3><?php esc_html_e( 'Trying it out', 'verifyblind' ); ?></h3>
		<p><?php esc_html_e( 'Test mode (VerifyBlind → Settings) accepts the demo card of the VerifyBlind app. The demo card only works with a test partner account (ask support@verifyblind.com); with your regular partner account, verify with your own ID card. Turn test mode off on a live site.', 'verifyblind' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="verifyblind_wizard_finish">
			<?php wp_nonce_field( 'verifyblind_wizard_finish' ); ?>
			<?php submit_button( __( 'Finish', 'verifyblind' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
		wp_print_inline_script_tag( '(function(){document.querySelectorAll("[data-vb-copy]").forEach(function(b){b.addEventListener("click",function(){var i=document.getElementById(b.getAttribute("data-vb-copy"));if(!i){return;}i.select();var done=function(){b.textContent=b.getAttribute("data-done");};if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(i.value).then(done,function(){document.execCommand("copy");done();});}else{document.execCommand("copy");done();}});});})();' );
	}

	private static function asks( array $rule ): string {
		$asks = array();
		if ( '' !== (string) $rule['age'] ) {
			$asks[] = (string) $rule['age'];
		}
		if ( ! empty( $rule['unique'] ) ) {
			$asks[] = __( 'one person', 'verifyblind' );
		}
		return implode( ' + ', $asks );
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', 403 );
		}
		check_admin_referer( $action );
	}

	private static function go( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	public static function handle_connect(): void {
		self::guard( 'verifyblind_wizard_connect' );
		$key = isset( $_POST['verifyblind_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['verifyblind_api_key'] ) ) : '';
		set_transient( self::RESULT . get_current_user_id(), self::save_connection( $key ), MINUTE_IN_SECONDS );
		self::go( self::url( 'connect' ) );
	}

	public static function handle_site(): void {
		self::guard( 'verifyblind_wizard_site' );
		$keys = isset( $_POST['presets'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['presets'] ) ) : array();
		Presets::apply( $keys );
		self::go( self::url( 'review' ) );
	}

	public static function handle_review(): void {
		self::guard( 'verifyblind_wizard_review' );
		$ids = isset( $_POST['enabled'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['enabled'] ) ) : array();
		self::apply_toggles( $ids );
		self::go( self::url( 'finish' ) );
	}

	public static function handle_finish(): void {
		self::guard( 'verifyblind_wizard_finish' );
		self::finish();
		self::go( admin_url( 'admin.php?page=' . RulesPage::SLUG ) );
	}

	public static function handle_skip(): void {
		self::guard( 'verifyblind_wizard_skip' );
		self::finish();
		self::go( admin_url( 'admin.php?page=' . RulesPage::SLUG ) );
	}
}
