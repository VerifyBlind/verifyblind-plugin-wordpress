<?php
/**
 * Plugin Name:       VerifyBlind
 * Plugin URI:        https://verifyblind.com/en/developers
 * Description:       Age and one-person-one-account verification with a chipped Turkish ID card — the site only receives an eligible / not eligible answer.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 8.0
 * WC tested up to:  11.1
 * Author:            VerifyBlind
 * Author URI:        https://verifyblind.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       verifyblind
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'VERIFYBLIND_VERSION', '1.0.1' );
define( 'VERIFYBLIND_FILE', __FILE__ );
define( 'VERIFYBLIND_DIR', plugin_dir_path( __FILE__ ) );
define( 'VERIFYBLIND_URL', plugin_dir_url( __FILE__ ) );

require_once VERIFYBLIND_DIR . 'vendor-prefixed/autoload.php';
require_once VERIFYBLIND_DIR . 'includes/autoload.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'VerifyBlind\\Plugin', 'boot' ) );

register_activation_hook( __FILE__, array( 'VerifyBlind\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'VerifyBlind\\Plugin', 'deactivate' ) );
