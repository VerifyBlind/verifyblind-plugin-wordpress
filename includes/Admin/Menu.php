<?php
namespace VerifyBlind\Admin;

defined( 'ABSPATH' ) || exit;

use VerifyBlind\Placements\Registry;

final class Menu {
	public static function hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register' ) );
		RulesPage::hooks();
		SettingsPage::hooks();
		MembersPage::hooks();
		Notices::hooks();
		if ( Registry::woocommerce_active() ) {
			ProductRulesBox::hooks();
		}
	}

	public static function register(): void {
		add_menu_page( 'VerifyBlind', 'VerifyBlind', 'manage_options', RulesPage::SLUG, array( RulesPage::class, 'render' ), 'dashicons-shield', 58 );
		add_submenu_page( RulesPage::SLUG, __( 'Rules', 'verifyblind' ), __( 'Rules', 'verifyblind' ), 'manage_options', RulesPage::SLUG, array( RulesPage::class, 'render' ) );
		add_submenu_page( RulesPage::SLUG, __( 'Verified members', 'verifyblind' ), __( 'Verified members', 'verifyblind' ), 'manage_options', MembersPage::SLUG, array( MembersPage::class, 'render' ) );
		add_submenu_page( RulesPage::SLUG, __( 'Settings', 'verifyblind' ), __( 'Settings', 'verifyblind' ), 'manage_options', SettingsPage::SLUG, array( SettingsPage::class, 'render' ) );
	}
}
