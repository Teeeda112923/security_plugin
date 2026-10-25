<?php
/**
 * Plugin Name: CyberNote Security Checker
 * Description: Diagnoses WordPress security settings and version status, presenting clear improvement steps. No external requests. Lightweight design.
 * Version: 1.2.0
 * Author: teeeda1129
 * Author URI: https://www.cybernote.click/wp-security-checker-guide/
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
define( 'CNSC_VERSION', '1.2.0' );
define( 'CNSC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CNSC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include class files.
require_once CNSC_PLUGIN_DIR . 'includes/class-cnsc-category-a.php';
require_once CNSC_PLUGIN_DIR . 'includes/class-cnsc-category-b.php';
require_once CNSC_PLUGIN_DIR . 'includes/class-cnsc-diagnostics.php';
require_once CNSC_PLUGIN_DIR . 'includes/class-cnsc-renderer.php';
require_once CNSC_PLUGIN_DIR . 'includes/class-cnsc-dashboard-widget.php';
require_once CNSC_PLUGIN_DIR . 'includes/class-cnsc-admin-page.php';

// Load translations from the plugin's languages directory.
add_action(
	'init',
	function () {
		load_plugin_textdomain(
			'cybernote-security-checker',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}
);

// Bootstrap the dashboard widget and admin page.
add_action(
	'plugins_loaded',
	function () {
		new CNSC_Dashboard_Widget();
		new CNSC_Admin_Page();
	}
);
