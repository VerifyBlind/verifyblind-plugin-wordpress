<?php
namespace VerifyBlind\Admin;

defined( 'ABSPATH' ) || exit;

use VerifyBlind\Plugin;
use VerifyBlind\Rest;
use VerifyBlind\Badge;

final class SettingsPage {
	const SLUG = 'verifyblind-settings';

	public static function hooks(): void {
		add_action( 'admin_init', array( self::class, 'register' ) );
		add_action( 'admin_post_verifyblind_test_connection', array( self::class, 'test_connection' ) );
	}

	public static function register(): void {
		register_setting(
			'verifyblind',
			'verifyblind_api_key',
			array(
				'type'              => 'string',
				// An empty field keeps the saved key (the field is never pre-filled).
				'sanitize_callback' => function ( $v ) {
					$v = trim( sanitize_text_field( (string) $v ) );
					return '' === $v ? (string) get_option( 'verifyblind_api_key', '' ) : $v;
				},
			)
		);
		foreach ( array( 'verifyblind_test_mode' ) as $opt ) {
			register_setting(
				'verifyblind',
				$opt,
				array(
					'type'              => 'string',
					'sanitize_callback' => function ( $v ) {
						return $v ? '1' : '0';
					},
				)
			);
		}
		// An empty submission (every box unticked) arrives as null and is stored as an empty list.
		register_setting(
			'verifyblind',
			Badge::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Badge::class, 'sanitize_places' ),
			)
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$key    = 'verifyblind_test_result_' . get_current_user_id();
		$result = get_transient( $key );
		delete_transient( $key );
		$has_key = '' !== (string) get_option( 'verifyblind_api_key', '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'VerifyBlind settings', 'verifyblind' ); ?></h1>
			<?php if ( is_array( $result ) ) : ?>
				<div class="notice notice-<?php echo $result['ok'] ? 'success' : 'error'; ?>"><p><?php echo esc_html( $result['message'] ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'verifyblind' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="verifyblind_api_key"><?php esc_html_e( 'API key', 'verifyblind' ); ?></label></th>
						<td>
							<input type="password" id="verifyblind_api_key" name="verifyblind_api_key" class="regular-text" autocomplete="off" value=""
								placeholder="<?php echo $has_key ? esc_attr__( 'Saved — leave empty to keep it', 'verifyblind' ) : ''; ?>">
							<p class="description">
								<?php
								printf(
									/* translators: %s: partner portal link */
									esc_html__( 'Get it from %s → Settings. Free account; 2,000 verifications a month are free.', 'verifyblind' ),
									'<a href="https://partner.verifyblind.com" target="_blank" rel="noopener">partner.verifyblind.com</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Test mode', 'verifyblind' ); ?></th>
						<td><label><input type="checkbox" name="verifyblind_test_mode" value="1" <?php checked( get_option( 'verifyblind_test_mode', '0' ), '1' ); ?>> <?php esc_html_e( 'Accept verifications made with the demo card. Turn off on a live site.', 'verifyblind' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Verified badge', 'verifyblind' ); ?></th>
						<td>
							<?php foreach ( Badge::place_labels() as $place => $label ) : ?>
								<label style="margin-right:12px"><input type="checkbox" name="<?php echo esc_attr( Badge::OPTION ); ?>[]" value="<?php echo esc_attr( $place ); ?>" <?php checked( Badge::shows_in( $place ) ); ?>> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'A small "Verified with VerifyBlind" badge next to the name of members who passed the one-person check.', 'verifyblind' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Connection', 'verifyblind' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="verifyblind_test_connection">
				<?php wp_nonce_field( 'verifyblind_test_connection' ); ?>
				<?php submit_button( __( 'Test connection', 'verifyblind' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Revoke URL', 'verifyblind' ); ?></h2>
			<p><?php esc_html_e( 'Paste this address into the partner portal → Settings → Revoke URL. When a person withdraws consent in the VerifyBlind app, their result is removed here automatically. It must be HTTPS on your registered domain.', 'verifyblind' ); ?></p>
			<input type="text" readonly class="large-text code" value="<?php echo esc_attr( rest_url( Rest::NS . '/revoke' ) ); ?>" onclick="this.select()">
		</div>
		<?php
	}

	public static function test_connection(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', 403 );
		}
		check_admin_referer( 'verifyblind_test_connection' );
		set_transient( 'verifyblind_test_result_' . get_current_user_id(), Plugin::api()->test_connection(), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}
}
