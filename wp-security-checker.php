<?php
/**
 * Plugin Name: WP Security Checker
 * Plugin URI: https://wordpress.org/plugins/wp-security-checker/
 * Description: WordPressサイトのセキュリティ設定とバージョン状態を診断し、日本語で改善手順を提示します。外部通信なし・軽量設計。
 * Version: 1.0.0
 * Author: Tanabe
 * Author URI: https://cybernote.click/
 * Text Domain: wp-security-checker
 * Domain Path: /languages
 * Requires at least: 5.9
 * Tested up to: 6.7
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

// Load text domain.
add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain(
			'wp-security-checker',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}
);

// Include class files.
require_once WSC_PLUGIN_DIR . 'includes/class-wsc-category-a.php';
require_once WSC_PLUGIN_DIR . 'includes/class-wsc-category-b.php';
require_once WSC_PLUGIN_DIR . 'includes/class-wsc-diagnostics.php';
require_once WSC_PLUGIN_DIR . 'includes/class-wsc-renderer.php';
require_once WSC_PLUGIN_DIR . 'includes/class-wsc-dashboard-widget.php';
require_once WSC_PLUGIN_DIR . 'includes/class-wsc-admin-page.php';

// Bootstrap the dashboard widget and the dedicated admin page.
add_action(
	'plugins_loaded',
	function () {
		new WSC_Dashboard_Widget();
		new WSC_Admin_Page();
	}
);
