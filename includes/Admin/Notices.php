<?php
namespace VerifyBlind\Admin;

defined( 'ABSPATH' ) || exit;

use VerifyBlind\ApiClient;
use VerifyBlind\ApiErrors;
use VerifyBlind\Rest;

/** Admin notices on VerifyBlind's own screens. */
final class Notices {
	const ACTION = 'verifyblind_dismiss_notice';

	public static function hooks(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'dismiss' ) );
	}

	/** @return array[] each: id, type, text, dismissible */
	public static function messages(): array {
		$out = array();
		if ( '' === ApiClient::api_key() ) {
			$out[] = array(
				'id'          => 'api_key',
				'type'        => 'warning',
				'text'        => __( 'VerifyBlind is not connected yet: enter your API key under VerifyBlind → Settings. Until then visitors see "Verification is not set up on this site yet."', 'verifyblind' ),
				'dismissible' => false,
			);
		}
		$error = ApiErrors::recent();
		if ( null !== $error ) {
			$out[] = array(
				'id'          => 'api_error',
				'type'        => 'error',
				'text'        => ApiErrors::text( (int) $error['status'] ) . ' ' . __( 'Visitors cannot verify until this is fixed.', 'verifyblind' ),
				'dismissible' => true,
			);
		}
		$hits  = get_option( Rest::CAP_HITS_OPTION );
		$count = is_array( $hits ) && isset( $hits['count'] ) ? (int) $hits['count'] : 0;
		if ( $count > 0 ) {
			$out[] = array(
				'id'          => 'cap',
				'type'        => 'warning',
				/* translators: %d: number of verification starts turned away */
				'text'        => sprintf( _n( '%d verification start was turned away because too many started in the same minute (site-wide limit). If real visitors were affected, raise the limit with the verifyblind_generate_per_minute filter.', '%d verification starts were turned away because too many started in the same minute (site-wide limit). If real visitors were affected, raise the limit with the verifyblind_generate_per_minute filter.', $count, 'verifyblind' ), $count ),
				'dismissible' => true,
			);
		}
		return $out;
	}

	public static function on_plugin_screen(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads which screen this is
		return 0 === strpos( $page, 'verifyblind' );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::on_plugin_screen() ) {
			return;
		}
		foreach ( self::messages() as $m ) {
			echo '<div class="notice notice-' . esc_attr( $m['type'] ) . '"><p>' . esc_html( $m['text'] ) . '</p>';
			if ( $m['dismissible'] ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&notice=' . rawurlencode( $m['id'] ) ), self::ACTION );
				echo '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Dismiss', 'verifyblind' ) . '</a></p>';
			}
			echo '</div>';
		}
	}

	public static function dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', 403 );
		}
		check_admin_referer( self::ACTION );
		$id = isset( $_REQUEST['notice'] ) ? sanitize_key( wp_unslash( $_REQUEST['notice'] ) ) : '';
		if ( 'cap' === $id ) {
			delete_option( Rest::CAP_HITS_OPTION ); // dismissing resets the counter
		} elseif ( 'api_error' === $id ) {
			ApiErrors::clear();
		}
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=' . RulesPage::SLUG ) );
		exit;
	}
}
