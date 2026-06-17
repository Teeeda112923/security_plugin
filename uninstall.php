<?php
/**
 * Plugin uninstall handler.
 *
 * WordPress はこのファイルをプラグイン削除時に直接実行する。
 * アンインストール時に保存したオプションやトランジェントを削除する。
 *
 * @package WP_Security_Checker
 */

// アンインストールフック経由でない直接アクセスは拒否。
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// プラグインが保存したオプションを削除。
delete_option( 'wsc_settings' );

// キャッシュ用トランジェントを削除。
delete_transient( 'wsc_diagnostics_cache' );
