<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Admin\Menu;
use VerifyBlind\Admin\Wizard;
use VerifyBlind\Plugin;
use VerifyBlind\Rules;

final class WizardTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		delete_option( Wizard::DONE );
		delete_transient( Wizard::REDIRECT );
	}

	protected function tearDown(): void {
		delete_transient( Wizard::REDIRECT );
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'wp_die_handler' );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tearDown();
	}

	private function die_throws(): void {
		add_filter(
			'wp_die_handler',
			function () {
				return function () {
					throw new \RuntimeException( 'wp_die' );
				};
			}
		);
		add_filter(
			'wp_redirect',
			function ( $location ) {
				throw new \RuntimeException( 'redirect:' . $location );
			}
		);
	}

	private function post( array $fields ): void {
		$_POST    = $fields;
		$_REQUEST = $fields;
	}

	public function test_activation_opens_the_wizard_once_for_the_activating_admin(): void {
		$admin = $this->make_user( 'administrator' );
		$other = $this->make_user( 'administrator' );
		wp_set_current_user( $admin );
		Plugin::activate( false );
		$this->assertSame( $admin, (int) get_transient( Wizard::REDIRECT ) );
		wp_set_current_user( $other );
		$this->assertNull( Wizard::redirect_target(), 'another admin is not redirected' );
		$this->assertSame( $admin, (int) get_transient( Wizard::REDIRECT ), 'and does not use the redirect up' );
		wp_set_current_user( $admin );
		$this->assertSame( Wizard::url(), Wizard::redirect_target() );
		$this->assertNull( Wizard::redirect_target(), 'only once' );
	}

	public function test_no_wizard_on_network_or_bulk_activation_from_cli_or_after_setup(): void {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		Plugin::activate( true );
		$this->assertFalse( get_transient( Wizard::REDIRECT ), 'network activation' );

		Plugin::activate( false );
		$_GET['activate-multi'] = 'true';
		$this->assertNull( Wizard::redirect_target(), 'bulk activation' );
		$this->assertFalse( get_transient( Wizard::REDIRECT ), 'used up' );
		$_GET = array();

		Wizard::finish();
		Plugin::activate( false );
		$this->assertFalse( get_transient( Wizard::REDIRECT ), 'already set up' );

		delete_option( Wizard::DONE );
		wp_set_current_user( 0 );
		Plugin::activate( false );
		$this->assertFalse( get_transient( Wizard::REDIRECT ), 'wp-cli activation (no user)' );
	}

	public function test_connection_step_saves_the_key_and_tests_it(): void {
		$sent = array();
		$this->mock_http(
			function ( $url, $args ) use ( &$sent ) {
				if ( false !== strpos( $url, '/api/public/enclave-key' ) ) {
					return array( 'body' => 'SPKI' );
				}
				$sent[] = $args['headers']['X-API-Key'];
				return array( 'body' => '{"nonce":"n1"}' );
			}
		);
		$r = Wizard::save_connection( '  key-123  ' );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 'key-123', get_option( 'verifyblind_api_key' ) );
		$this->assertSame( array( 'key-123' ), $sent );
		Wizard::save_connection( '' );
		$this->assertSame( 'key-123', get_option( 'verifyblind_api_key' ), 'an empty field keeps the saved key' );
	}

	public function test_review_toggles_switch_rules_on_and_off(): void {
		$a = $this->rule( array( 'name' => 'A' ) );
		$b = $this->rule( array( 'name' => 'B', 'enabled' => false ) );
		Wizard::apply_toggles( array( $b['id'] ) );
		$this->assertFalse( Rules::get( $a['id'] )['enabled'] );
		$this->assertTrue( Rules::get( $b['id'] )['enabled'] );
	}

	public function test_target_hints_for_rules_that_apply_to_nothing_yet(): void {
		$this->assertNotSame( '', Wizard::target_hint( $this->rule( array( 'placement' => 'content' ) ) ) );
		$this->assertSame( '', Wizard::target_hint( $this->rule( array( 'placement' => 'content', 'targets' => array( 'post_ids' => array( 1 ) ) ) ) ) );
		$this->assertNotSame( '', Wizard::target_hint( $this->rule( array( 'placement' => 'wc_checkout' ) ) ) );
		$this->assertNotSame( '', Wizard::target_hint( $this->rule( array( 'placement' => 'wc_coupon', 'age' => '', 'unique' => true ) ) ) );
		$this->assertSame( '', Wizard::target_hint( $this->rule( array( 'placement' => 'registration', 'age' => '', 'unique' => true ) ) ) );
	}

	public function test_handlers_check_rights_and_nonce(): void {
		$this->die_throws();
		wp_set_current_user( $this->make_user( 'editor' ) );
		$this->post( array( '_wpnonce' => wp_create_nonce( 'verifyblind_wizard_finish' ) ) );
		try {
			Wizard::handle_finish();
			$this->fail( 'editors may not finish the setup' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		}
		$this->assertFalse( get_option( Wizard::DONE ) );

		wp_set_current_user( $this->make_user( 'administrator' ) );
		$this->post( array( '_wpnonce' => 'bad' ) );
		try {
			Wizard::handle_finish();
			$this->fail( 'a bad nonce must stop' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		}

		$this->post( array( '_wpnonce' => wp_create_nonce( 'verifyblind_wizard_finish' ) ) );
		try {
			Wizard::handle_finish();
			$this->fail( 'finish should redirect' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'page=verifyblind', $e->getMessage() );
		}
		$this->assertSame( '1', get_option( Wizard::DONE ) );
	}

	public function test_site_step_creates_the_chosen_presets(): void {
		$this->die_throws();
		wp_set_current_user( $this->make_user( 'administrator' ) );
		$this->post( array( '_wpnonce' => wp_create_nonce( 'verifyblind_wizard_site' ), 'presets' => array( 'age_content', 'community' ) ) );
		try {
			Wizard::handle_site();
			$this->fail( 'the site step should redirect' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'step=review', $e->getMessage() );
		}
		$this->assertSame( array( 'content', 'registration' ), array_column( array_values( Rules::all() ), 'placement' ) );
	}

	public function test_running_the_site_step_twice_creates_no_duplicates(): void {
		$this->die_throws();
		wp_set_current_user( $this->make_user( 'administrator' ) );
		for ( $i = 0; $i < 2; $i++ ) {
			$this->post( array( '_wpnonce' => wp_create_nonce( 'verifyblind_wizard_site' ), 'presets' => array( 'age_content' ) ) );
			try {
				Wizard::handle_site();
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( 'step=review', $e->getMessage() );
			}
			if ( 0 === $i ) {
				$rule         = array_values( Rules::all() )[0];
				$rule['name'] = 'Renamed';
				Rules::save( $rule );
			}
		}
		$this->assertCount( 1, Rules::all() );
	}

	public function test_every_step_renders_its_form_with_a_nonce(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php'; // submit_button() is admin-only
		wp_set_current_user( $this->make_user( 'administrator' ) );
		$this->rule( array( 'name' => '"Quoted" & rule' ) );
		$html = array();
		foreach ( Wizard::STEPS as $step ) {
			$_GET['step'] = $step;
			ob_start();
			Wizard::render();
			$html[ $step ] = (string) ob_get_clean();
			$this->assertStringContainsString( 'value="verifyblind_wizard_' . $step . '"', $html[ $step ], $step );
			$this->assertStringContainsString( 'name="_wpnonce"', $html[ $step ], $step );
			$this->assertStringContainsString( 'action=verifyblind_wizard_skip', $html[ $step ], $step );
		}
		$this->assertStringContainsString( 'value="age_shop"', $html['site'] );
		$this->assertDoesNotMatchRegularExpression( '/value="age_shop"\s+disabled/', $html['site'], 'WooCommerce is active on the test site' );
		$this->assertStringContainsString( '&quot;Quoted&quot; &amp; rule', $html['review'] );
		$this->assertStringContainsString( 'name="enabled[]"', $html['review'] );
		$this->assertStringContainsString( esc_attr( rest_url( 'verifyblind/v1/revoke' ) ), $html['finish'] );
		$this->assertStringContainsString( 'data-vb-copy="verifyblind-revoke-url"', $html['finish'] );
		$this->assertStringContainsString( 'options-privacy.php?tab=policyguide', $html['finish'] );
	}

	public function test_menu_has_the_wizard(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		global $menu, $submenu;
		$saved = array( $menu, $submenu );
		wp_set_current_user( $this->make_user( 'administrator' ) );
		try {
			Menu::register();
			$this->assertContains( Wizard::SLUG, wp_list_pluck( $submenu['verifyblind'], 2 ) );
		} finally {
			list( $menu, $submenu ) = $saved;
		}
	}
}
