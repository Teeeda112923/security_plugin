<?php
/**
 * Plugin Name: CyberNote Security Checker Pro（接続プラグイン）
 * Description: 使用中のプラグイン・テーマを毎日CyberNoteに照合し、既知の脆弱性を管理画面に日本語で表示します。cybernote.click から配布（WordPress.org 配布物には含まれません）。
 * Version: 0.1.0
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Author: CyberNote
 *
 * 本プラグインは購入者向けに cybernote.click で配布する。
 * WordPress.org の無料版（cybernote-security-checker）とは独立して動作し、
 * 無料版が有効な場合はそのメニュー配下に「脆弱性アラート」を追加する。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CNSCP_VERSION', '0.1.0' );
define( 'CNSCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CNSCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'CNSCP_API_URL' ) ) {
	define( 'CNSCP_API_URL', 'https://www.cybernote.click/wp-json/cybernote/v1/scan' );
}

require_once CNSCP_PLUGIN_DIR . 'includes/class-cnscp-scanner.php';
require_once CNSCP_PLUGIN_DIR . 'includes/class-cnscp-notifier.php';
require_once CNSCP_PLUGIN_DIR . 'includes/class-cnscp-cron.php';
require_once CNSCP_PLUGIN_DIR . 'includes/class-cnscp-admin.php';

register_activation_hook( __FILE__, array( 'CNSCP_Cron', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CNSCP_Cron', 'deactivate' ) );

add_action( 'cnscp_daily_scan', array( 'CNSCP_Cron', 'run_daily_scan' ) );
add_action( 'admin_menu', array( 'CNSCP_Admin', 'register_menu' ), 20 );
add_action( 'admin_enqueue_scripts', array( 'CNSCP_Admin', 'enqueue_assets' ) );
add_action( 'admin_post_cnscp_save_license', array( 'CNSCP_Admin', 'handle_save_license' ) );
add_action( 'admin_post_cnscp_scan_now', array( 'CNSCP_Admin', 'handle_scan_now' ) );
add_action( 'admin_post_cnscp_save_settings', array( 'CNSCP_Admin', 'handle_save_settings' ) );
