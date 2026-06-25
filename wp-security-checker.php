<?php
/**
 * Plugin Name: CyberNote Security Checker
 * Description: Diagnoses WordPress security settings and version status, presenting improvement steps in plain Japanese. No external requests. Lightweight design.
 * Version: 1.0.0
 * Author: teeeda1129
 * Author URI: https://cybernote.click/
 * Text Domain: cybernote-security-checker
 * Domain Path: /languages
 * Requires at least: 5.9
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'WSC_VERSION', '1.0.0' );
define( 'WSC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WSC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include class files.
require_once WSC_PLUGIN_DIR . 'includes/class-wsc-category-a.php';
require_once WSC_PLUGIN_DIR . 'includes/class-wsc-category-b.php';
require_once WSC_PLUGIN_DIR . 'includes/class-wsc-diagnostics.php';
require_once WSC_PLUGIN_DIR . 'includes/class-wsc-renderer.php';
require_once WSC_PLUGIN_DIR . 'includes/class-wsc-dashboard-widget.php';
require_once WSC_PLUGIN_DIR . 'includes/class-wsc-pro-license.php';
require_once WSC_PLUGIN_DIR . 'includes/class-wsc-pro-scanner.php';
require_once WSC_PLUGIN_DIR . 'includes/class-wsc-admin-page.php';

// Bootstrap the dashboard widget, admin page, and Pro license handler.
add_action(
	'plugins_loaded',
	function () {
		new WSC_Dashboard_Widget();
		new WSC_Admin_Page();
		new WSC_Pro_License();
	}
);
