<?php
/**
 * Plugin Name:       VerifyBlind
 * Plugin URI:        https://verifyblind.com/en/developers
 * Description:       Age and one-person-one-account verification with a chipped Turkish ID card — the site only receives an eligible / not eligible answer.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            VerifyBlind
 * Author URI:        https://verifyblind.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       verifyblind
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'VERIFYBLIND_VERSION', '0.1.0' );
define( 'VERIFYBLIND_FILE', __FILE__ );
define( 'VERIFYBLIND_DIR', plugin_dir_path( __FILE__ ) );
define( 'VERIFYBLIND_URL', plugin_dir_url( __FILE__ ) );

require_once VERIFYBLIND_DIR . 'vendor-prefixed/autoload.php';
require_once VERIFYBLIND_DIR . 'includes/autoload.php';

add_action( 'plugins_loaded', array( 'VerifyBlind\\Plugin', 'boot' ) );
