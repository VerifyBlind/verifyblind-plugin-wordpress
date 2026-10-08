<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

/** Who a verification belongs to: 'u:<user id>' or, for guests, 'g:<random cookie id>'. */
final class Owner {
	const COOKIE = 'verifyblind_gid';

	public static function for_user( int $user_id ): string {
		return 'u:' . $user_id;
	}

	public static function user_id( string $owner ): int {
		return 0 === strpos( $owner, 'u:' ) ? (int) substr( $owner, 2 ) : 0;
	}

	public static function current( bool $create = false ): ?string {
		$uid = get_current_user_id();
		if ( $uid > 0 ) {
			return self::for_user( $uid );
		}
		$guest = self::guest_from_cookie();
		if ( null !== $guest ) {
			return $guest;
		}
		if ( ! $create ) {
			return null;
		}
		$gid = bin2hex( random_bytes( 16 ) );
		self::set_cookie( $gid, time() + DAY_IN_SECONDS );
		$_COOKIE[ self::COOKIE ] = $gid;
		return 'g:' . $gid;
	}

	/** The guest owner this request's cookie names (whoever is logged in), or null. */
	public static function guest_from_cookie(): ?string {
		$gid = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		return preg_match( '/^[a-f0-9]{32}$/', $gid ) ? 'g:' . $gid : null;
	}

	/** Expires the guest cookie (its results now belong to an account). */
	public static function forget_guest(): void {
		if ( ! isset( $_COOKIE[ self::COOKIE ] ) ) {
			return;
		}
		self::set_cookie( '', time() - YEAR_IN_SECONDS );
		unset( $_COOKIE[ self::COOKIE ] );
	}

	private static function set_cookie( string $value, int $expires ): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}
}
