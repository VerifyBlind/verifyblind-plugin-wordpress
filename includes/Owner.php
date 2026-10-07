<?php
namespace VerifyBlind;

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
		$gid = isset( $_COOKIE[ self::COOKIE ] ) ? (string) $_COOKIE[ self::COOKIE ] : '';
		if ( preg_match( '/^[a-f0-9]{32}$/', $gid ) ) {
			return 'g:' . $gid;
		}
		if ( ! $create ) {
			return null;
		}
		$gid = bin2hex( random_bytes( 16 ) );
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$gid,
				array(
					'expires'  => time() + DAY_IN_SECONDS,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE[ self::COOKIE ] = $gid;
		return 'g:' . $gid;
	}
}
