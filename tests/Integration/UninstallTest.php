<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Admin\Wizard;
use VerifyBlind\Cron;
use VerifyBlind\Placements\WcCoupon;
use VerifyBlind\Roles;
use VerifyBlind\Schema;
use VerifyBlind\Uninstaller;

/**
 * Runs the real uninstall routine on the test site, then puts back what the site had before
 * (tables, options, user meta and roles, coupon records, the cron event).
 */
final class UninstallTest extends WcTestCase {
	/** @var array */
	private $snap = array();

	protected function setUp(): void {
		parent::setUp();
		$this->snap = $this->snapshot();
	}

	protected function tearDown(): void {
		$this->restore( $this->snap );
		remove_role( 'vb_uninstall_test' );
		parent::tearDown();
	}

	private function like( string $prefix ): string {
		global $wpdb;
		return $wpdb->esc_like( $prefix ) . '%';
	}

	private function snapshot(): array {
		global $wpdb;
		$snap = array( 'options' => array(), 'meta' => array(), 'roles' => array(), 'user_roles' => array(), 'coupons' => array() );
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $this->like( 'verifyblind_' ) ) ) as $name ) {
			$snap['options'][ (string) $name ] = get_option( (string) $name );
		}
		$snap['meta'] = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $this->like( 'verifyblind_' ) ), ARRAY_A );
		$names        = wp_roles()->get_names();
		foreach ( array_unique( array_merge( array( Roles::BASE ), Roles::created() ) ) as $slug ) {
			$role = get_role( $slug );
			if ( $role ) {
				$snap['roles'][ $slug ] = array( isset( $names[ $slug ] ) ? (string) $names[ $slug ] : $slug, $role->capabilities );
			}
		}
		$users = array_map( 'intval', wp_list_pluck( $snap['meta'], 'user_id' ) );
		if ( $snap['roles'] ) {
			$users = array_merge( $users, array_map( 'intval', get_users( array( 'role__in' => array_keys( $snap['roles'] ), 'fields' => 'ID' ) ) ) );
		}
		foreach ( array_unique( $users ) as $uid ) {
			$u = get_userdata( $uid );
			if ( $u ) {
				$snap['user_roles'][ $uid ] = array_values( (array) $u->roles );
			}
		}
		$snap['coupons'] = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", WcCoupon::META ), ARRAY_A );
		return $snap;
	}

	private function restore( array $snap ): void {
		global $wpdb;
		Schema::install(); // tables, the base role, the schema version
		foreach ( $snap['roles'] as $slug => $def ) {
			if ( ! get_role( $slug ) ) {
				add_role( $slug, $def[0], $def[1] );
			}
		}
		foreach ( $snap['options'] as $name => $value ) {
			update_option( $name, $value, false );
		}
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $this->like( 'verifyblind_' ) ) ) as $key ) {
			delete_metadata( 'user', 0, (string) $key, '', true );
		}
		foreach ( $snap['meta'] as $row ) {
			add_user_meta( (int) $row['user_id'], (string) $row['meta_key'], maybe_unserialize( $row['meta_value'] ) );
		}
		foreach ( $snap['user_roles'] as $uid => $roles ) {
			$u = get_userdata( (int) $uid );
			if ( ! $u ) {
				continue;
			}
			foreach ( $roles as $role ) {
				if ( ! in_array( $role, (array) $u->roles, true ) ) {
					$u->add_role( $role );
				}
			}
		}
		delete_post_meta_by_key( WcCoupon::META );
		foreach ( $snap['coupons'] as $row ) {
			add_post_meta( (int) $row['post_id'], WcCoupon::META, (string) $row['meta_value'] );
		}
		Cron::schedule();
	}

	public function test_uninstall_removes_everything_the_plugin_stored(): void {
		global $wpdb;
		$uid = $this->make_user( 'subscriber' );
		$this->assertTrue( Roles::create( 'vb_uninstall_test', 'Uninstall test' ) );
		$this->assertContains( 'vb_uninstall_test', Roles::created(), 'created roles are remembered' );
		$user = get_userdata( $uid );
		$user->add_role( Roles::BASE );
		$user->add_role( 'vb_uninstall_test' );
		$user->add_role( 'customer' ); // an existing role a rule handed out
		update_user_meta( $uid, Roles::META, array( Roles::BASE, 'vb_uninstall_test', 'customer' ) );
		update_user_meta( $uid, 'verifyblind_duplicate_of', 1 );
		$coupon = $this->coupon();
		add_post_meta( $coupon->get_id(), WcCoupon::META, 'person-hash' );
		set_transient( 'verifyblind_enclave_key', 'SPKI', 60 );
		update_option( 'verifyblind_rules', array( 'r_00000000' => array() ), false );
		Wizard::finish();
		Cron::schedule();

		Uninstaller::run();

		foreach ( Schema::TABLES as $t ) {
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table( $t ) ) ), $t );
		}
		$left = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$this->like( 'verifyblind_' ),
				$this->like( '_transient_verifyblind_' ),
				$this->like( '_transient_timeout_verifyblind_' )
			)
		);
		$this->assertSame( '0', (string) $left );
		$this->assertFalse( get_transient( 'verifyblind_enclave_key' ) );
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $this->like( 'verifyblind_' ) ) ) );
		$this->assertNull( get_role( Roles::BASE ) );
		$this->assertNull( get_role( 'vb_uninstall_test' ) );
		clean_user_cache( $uid );
		$this->assertSame( array( 'subscriber' ), array_values( get_userdata( $uid )->roles ) );
		$this->assertSame( array(), get_post_meta( $coupon->get_id(), WcCoupon::META, false ) );
		$this->assertFalse( wp_next_scheduled( Cron::HOOK ) );
	}

	public function test_uninstall_file_runs_only_from_wordpress(): void {
		$src = (string) file_get_contents( VERIFYBLIND_DIR . 'uninstall.php' );
		$this->assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' ) || exit;", $src );
		$this->assertStringContainsString( 'VerifyBlind\\Uninstaller::run();', $src );
	}
}
