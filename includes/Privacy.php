<?php
namespace VerifyBlind;

defined( 'ABSPATH' ) || exit;

/** WordPress privacy tools: export and erase this plugin's data of a user; suggested privacy policy text. */
final class Privacy {
	public static function hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
		add_action( 'admin_init', array( self::class, 'policy_content' ) );
	}

	/** @param mixed $exporters */
	public static function register_exporter( $exporters ): array {
		$exporters                = is_array( $exporters ) ? $exporters : array();
		$exporters['verifyblind'] = array(
			'exporter_friendly_name' => __( 'VerifyBlind verifications', 'verifyblind' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/** @param mixed $erasers */
	public static function register_eraser( $erasers ): array {
		$erasers                = is_array( $erasers ) ? $erasers : array();
		$erasers['verifyblind'] = array(
			'eraser_friendly_name' => __( 'VerifyBlind verifications', 'verifyblind' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * @param mixed $email
	 * @param mixed $page
	 */
	public static function export( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}
		$group = __( 'VerifyBlind verifications', 'verifyblind' );
		$data  = array();
		foreach ( Results::for_owner( Owner::for_user( (int) $user->ID ) ) as $row ) {
			$data[] = array(
				'group_id'    => 'verifyblind',
				'group_label' => $group,
				'item_id'     => 'verifyblind-result-' . $row['id'],
				'data'        => array(
					array( 'name' => __( 'Checked', 'verifyblind' ), 'value' => 'uid' === $row['cond'] ? __( 'One-person check', 'verifyblind' ) : $row['cond'] ),
					array( 'name' => __( 'Result', 'verifyblind' ), 'value' => $row['passed'] ? __( 'Eligible', 'verifyblind' ) : __( 'Not eligible', 'verifyblind' ) ),
					array( 'name' => __( 'Verified at (UTC)', 'verifyblind' ), 'value' => $row['verified_at'] ),
				),
			);
		}
		$identity = Identities::find_by_wp_user( (int) $user->ID );
		if ( $identity ) {
			$data[] = array(
				'group_id'    => 'verifyblind',
				'group_label' => $group,
				'item_id'     => 'verifyblind-identity',
				'data'        => array(
					array( 'name' => __( 'Person code (pseudonymous, issued for this site only)', 'verifyblind' ), 'value' => (string) $identity['vb_user_id'] ),
					array( 'name' => __( 'Verified at (UTC)', 'verifyblind' ), 'value' => (string) $identity['verified_at'] ),
				),
			);
		}
		return array( 'data' => $data, 'done' => true );
	}

	/**
	 * @param mixed $email
	 * @param mixed $page
	 */
	public static function erase( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		$had  = false;
		$kept = array();
		if ( $user ) {
			$had  = (bool) Results::for_owner( Owner::for_user( (int) $user->ID ) ) || null !== Identities::find_by_wp_user( (int) $user->ID );
			$kept = self::kept( (int) $user->ID ); // before the removal: the coupon records are found through the person code
			Members::remove( (int) $user->ID );
		}
		return array( 'items_removed' => $had, 'items_retained' => (bool) $kept, 'messages' => $kept, 'done' => true );
	}

	/**
	 * Shop records the eraser deliberately leaves (Members::remove() does not touch them), as messages for the
	 * privacy tools: one-person coupon uses (a keyed code of the person, which keeps the offer once per person) and
	 * the verification records on the user's orders (part of the purchase record).
	 *
	 * @return string[]
	 */
	private static function kept( int $user_id ): array {
		global $wpdb;
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$out = array();
		$key = get_option( Placements\WcCoupon::KEY_OPTION );
		// Without the site's key no use was ever recorded (and asking for the person code would create the key).
		$person = ( is_string( $key ) && '' !== $key ) ? Placements\WcCoupon::person_key( $user_id ) : null;
		if ( null !== $person && null !== $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", Placements\WcCoupon::META, $person ) ) ) {
			$out[] = __( 'One-person coupon uses are kept as a keyed code of the person (no identity data), so that each offer stays once per person.', 'verifyblind' );
		}
		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => 1,
				'return'      => 'ids',
				'status'      => array_keys( wc_get_order_statuses() ),
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- privacy tool, one user, limit 1
					'relation' => 'OR',
					array( 'key' => Placements\WcCheckout::META, 'compare' => 'EXISTS' ),
					array( 'key' => Placements\WcCoupon::ORDER_META, 'compare' => 'EXISTS' ),
				),
			)
		);
		if ( $orders ) {
			$out[] = __( 'Verification records on orders (which check passed, and when) are kept with the orders as part of the purchase record.', 'verifyblind' );
		}
		return $out;
	}

	public static function policy_text(): string {
		$dpa = 'https://verifyblind.com/en/dpa';
		return '<p>' . esc_html__( 'This site may ask you to verify your age, or that you are one person with one account, with VerifyBlind. You verify in the VerifyBlind app with your chipped ID card. The verification runs in a sealed environment, so your identity data does not reach this site, nor VerifyBlind. This site receives only an eligible / not eligible answer and, when the one-person check is used, pseudonymous codes issued for this site only. They let us recognise the same person across accounts without knowing who that person is.', 'verifyblind' ) . '</p>'
			. '<p>' . esc_html__( 'We keep these results with your account (results of visitors without an account are deleted after 24 hours) and delete them when you delete your account or withdraw your consent in the VerifyBlind app.', 'verifyblind' ) . '</p>'
			/* translators: %s: link to the VerifyBlind data processing terms */
			. '<p>' . sprintf( esc_html__( 'More information: %s', 'verifyblind' ), '<a href="' . esc_url( $dpa ) . '">' . esc_html( $dpa ) . '</a>' ) . '</p>';
	}

	public static function policy_content(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'VerifyBlind', wp_kses_post( self::policy_text() ) );
		}
	}
}
