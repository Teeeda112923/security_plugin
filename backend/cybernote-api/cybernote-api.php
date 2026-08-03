<?php
/**
 * Plugin Name: CyberNote API
 * Description: cybernote.click 専用の脆弱性スキャンAPI。WordPressサイトから送られた環境情報をWPVulnerabilityと突合し、日本語の脆弱性リストを返す。配布用ではない（このサイト専用の内部プラグイン）。
 * Version: 0.1.0
 * Requires PHP: 7.4
 * Author: CyberNote
 *
 * 注意: このプラグインは cybernote.click にのみ設置する。WordPress.org には公開しない。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CNAPI_VERSION', '0.1.0' );
define( 'CNAPI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once CNAPI_PLUGIN_DIR . 'includes/class-cnapi-license.php';
require_once CNAPI_PLUGIN_DIR . 'includes/class-cnapi-matcher.php';
require_once CNAPI_PLUGIN_DIR . 'includes/class-cnapi-poc.php';
require_once CNAPI_PLUGIN_DIR . 'includes/class-cnapi-rest.php';
require_once CNAPI_PLUGIN_DIR . 'includes/class-cnapi-admin.php';

add_action( 'rest_api_init', array( 'CNAPI_Rest', 'register_routes' ) );
add_action( 'admin_menu', array( 'CNAPI_Admin', 'register_menu' ) );
add_action( 'admin_init', array( 'CNAPI_Admin', 'register_settings' ) );
