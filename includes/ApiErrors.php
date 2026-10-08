<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

/**
 * Account problems reported by VerifyBlind when a verification starts: 401 (API key), 402 (quota / balance),
 * 403 for an unverified partner e-mail, 426 when VerifyBlind no longer accepts this plugin version. A 403 for a
 * failed bot check is the visitor's, not the account's. The last one is shown as an admin notice; the site
 * admin gets at most one e-mail a day per status.
 */
final class ApiErrors {
	const OPTION = 'verifyblind_last_api_error';
	const MAILED = 'verifyblind_api_error_mailed';

	public static function is_account_problem( int $status, string $code ): bool {
		return 401 === $status
			|| 402 === $status
			|| ( 403 === $status && 'EMAIL_NOT_VERIFIED' === $code )
			|| ( 426 === $status && 'CLIENT_UPGRADE_REQUIRED' === $code );
	}

	public static function record( int $status, string $code, ?int $now = null ): void {
		if ( ! self::is_account_problem( $status, $code ) ) {
			return;
		}
		$now = null === $now ? time() : $now;
		update_option( self::OPTION, array( 'status' => $status, 'time' => $now ), false );
		$mailed = get_option( self::MAILED );
		$mailed = is_array( $mailed ) ? $mailed : array();
		if ( isset( $mailed[ $status ] ) && $now - (int) $mailed[ $status ] < DAY_IN_SECONDS ) {
			return;
		}
		$mailed[ $status ] = $now;
		update_option( self::MAILED, $mailed, false );
		self::mail( $status );
	}

	public static function recent( ?int $now = null ): ?array {
		$e = get_option( self::OPTION );
		if ( ! is_array( $e ) || ! isset( $e['status'], $e['time'] ) ) {
			return null;
		}
		$now = null === $now ? time() : $now;
		return ( $now - (int) $e['time'] ) < DAY_IN_SECONDS ? array( 'status' => (int) $e['status'], 'time' => (int) $e['time'] ) : null;
	}

	public static function clear(): void {
		if ( false !== get_option( self::OPTION, false ) ) {
			delete_option( self::OPTION );
		}
	}

	public static function text( int $status ): string {
		switch ( $status ) {
			case 401:
				return __( 'The API key was rejected. Copy it again from partner.verifyblind.com → Settings.', 'verifyblind' );
			case 402:
				return __( 'The free monthly quota is used up and there is no balance. Add balance in the partner portal.', 'verifyblind' );
			case 403:
				return __( 'The partner account is not ready yet (for example, its e-mail address is not verified).', 'verifyblind' );
			case 426:
				return __( 'A security update of the VerifyBlind plugin is required: VerifyBlind no longer accepts this version. Update the plugin under Plugins → Installed plugins, or download the latest version from github.com/VerifyBlind/verifyblind-plugin-wordpress/releases.', 'verifyblind' );
			default:
				/* translators: %d: HTTP status code */
				return sprintf( __( 'Unexpected response from VerifyBlind (HTTP %d).', 'verifyblind' ), $status );
		}
	}

	private static function mail( int $status ): void {
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] VerifyBlind verifications are failing', 'verifyblind' ), $site );
		$body    = self::text( $status ) . "\n\n" . __( 'Visitors cannot verify until this is fixed.', 'verifyblind' ) . "\n" . admin_url( 'admin.php?page=verifyblind-settings' );
		wp_mail( (string) get_option( 'admin_email' ), $subject, $body );
	}
}
