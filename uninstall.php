<?php
/**
 * VerifyBlind: removes everything the plugin stored when it is deleted from Plugins → Installed plugins.
 * Orders keep their verification records (part of the purchase record).
 *
 * @package VerifyBlind
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/autoload.php';

VerifyBlind\Uninstaller::run();
