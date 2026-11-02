<?php
namespace VerifyBlind\Admin;

defined( 'ABSPATH' ) || exit;

use VerifyBlind\Members;
use VerifyBlind\Placements\Account;

final class MembersPage {
	const SLUG     = 'verifyblind-members';
	const PER_PAGE = 20;

	public static function hooks(): void {
		add_action( 'admin_post_verifyblind_remove_member', array( self::class, 'remove' ) );
		add_action( 'admin_post_verifyblind_request_verification', array( self::class, 'toggle_request' ) );
		add_filter( 'user_row_actions', array( self::class, 'row_action' ), 10, 2 );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- paging only
		$data  = Members::page( $paged, self::PER_PAGE );
		$msg   = isset( $_GET['vb_msg'] ) ? sanitize_key( wp_unslash( $_GET['vb_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Verified members', 'verifyblind' ); ?></h1>
			<?php if ( 'removed' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Verification removed.', 'verifyblind' ); ?></p></div>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Accounts with a VerifyBlind result. The site only keeps eligible / not eligible answers and pseudonymous codes — never identity data.', 'verifyblind' ); ?></p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Account', 'verifyblind' ); ?></th>
					<th><?php esc_html_e( 'Conditions passed', 'verifyblind' ); ?></th>
					<th><?php esc_html_e( 'Last verified (UTC)', 'verifyblind' ); ?></th>
					<th><?php esc_html_e( 'One person', 'verifyblind' ); ?></th>
					<th><?php esc_html_e( 'Same person as', 'verifyblind' ); ?></th>
					<th><?php esc_html_e( 'Demo card', 'verifyblind' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $data['rows'] ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No verified members yet.', 'verifyblind' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $data['rows'] as $row ) : ?>
					<?php
					$remove = wp_nonce_url( admin_url( 'admin-post.php?action=verifyblind_remove_member&user_id=' . $row['user_id'] ), 'verifyblind_remove_member_' . $row['user_id'] );
					$same   = $row['same_person_as'] > 0 ? get_userdata( $row['same_person_as'] ) : false;
					?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_user_link( $row['user_id'] ) ); ?>"><?php echo esc_html( '' !== $row['login'] ? $row['login'] : '#' . $row['user_id'] ); ?></a></td>
						<td><?php echo esc_html( implode( ', ', array_map( array( self::class, 'condition_label' ), $row['conditions'] ) ) ); ?></td>
						<td><?php echo esc_html( $row['verified_at'] ); ?></td>
						<td><?php echo $row['one_person'] ? esc_html__( 'Yes', 'verifyblind' ) : '—'; ?></td>
						<td>
							<?php if ( $same ) : ?>
								<a href="<?php echo esc_url( get_edit_user_link( $row['same_person_as'] ) ); ?>"><?php echo esc_html( $same->user_login ); ?></a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td><?php echo $row['test'] ? esc_html__( 'Yes', 'verifyblind' ) : '—'; ?></td>
						<td><a class="button-link-delete" href="<?php echo esc_url( $remove ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Remove this account\'s VerifyBlind verification?', 'verifyblind' ) ); ?>')"><?php esc_html_e( 'Remove verification', 'verifyblind' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $data['total'] / self::PER_PAGE );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => admin_url( 'admin.php?page=' . self::SLUG . '&paged=%#%' ),
							'format'  => '',
							'current' => $paged,
							'total'   => $pages,
						)
					)
				);
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * Users screen: ask one account to verify, or take the request back.
	 *
	 * @param mixed $actions
	 * @param mixed $user
	 * @return mixed
	 */
	public static function row_action( $actions, $user ) {
		if ( ! is_array( $actions ) || ! $user instanceof \WP_User || ! current_user_can( 'manage_options' ) ) {
			return $actions;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=verifyblind_request_verification&user_id=' . $user->ID ), 'verifyblind_request_verification_' . $user->ID );
		$actions['verifyblind_request'] = '<a href="' . esc_url( $url ) . '">' . esc_html( Account::is_requested( (int) $user->ID ) ? __( 'Cancel VerifyBlind request', 'verifyblind' ) : __( 'Ask to verify with VerifyBlind', 'verifyblind' ) ) . '</a>';
		return $actions;
	}

	public static function toggle_request(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', 403 );
		}
		$user_id = isset( $_REQUEST['user_id'] ) ? absint( $_REQUEST['user_id'] ) : 0;
		check_admin_referer( 'verifyblind_request_verification_' . $user_id );
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			wp_die( esc_html__( 'This account does not exist.', 'verifyblind' ), '', array( 'response' => 400 ) );
		}
		if ( Account::is_requested( $user_id ) ) {
			Account::cancel_request( $user_id );
		} else {
			Account::request( $user_id );
		}
		wp_safe_redirect( admin_url( 'users.php' ) );
		exit;
	}

	public static function condition_label( string $cond ): string {
		return 'uid' === $cond ? __( 'one person', 'verifyblind' ) : $cond;
	}

	public static function remove(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', 403 );
		}
		$user_id = isset( $_REQUEST['user_id'] ) ? absint( $_REQUEST['user_id'] ) : 0;
		check_admin_referer( 'verifyblind_remove_member_' . $user_id );
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			wp_die( esc_html__( 'This account does not exist.', 'verifyblind' ), '', array( 'response' => 400 ) );
		}
		Members::remove( $user_id );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'vb_msg' => 'removed' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
