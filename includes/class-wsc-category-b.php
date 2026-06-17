<?php
/**
 * Category B: ハードニング設定チェック
 *
 * @package WP_Security_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all hardening diagnostic checks (B-1 through B-6).
 */
class WSC_Category_B {

	/**
	 * Run all Category B checks.
	 *
	 * @return array Array of check result arrays.
	 */
	public function run() {
		return array(
			$this->check_debug_mode(),
			$this->check_file_editing(),
			$this->check_https(),
			$this->check_login_url(),
			$this->check_admin_username(),
			$this->check_db_prefix(),
		);
	}

	/**
	 * B-1: デバッグモードチェック
	 *
	 * @return array Check result.
	 */
	private function check_debug_mode() {
		$debug_enabled = defined( 'WP_DEBUG' ) && true === WP_DEBUG;

		if ( $debug_enabled ) {
			$status  = 'recommended';
			$message = __( 'wp-config.phpでWP_DEBUGをfalseに設定してください（現在有効: 情報漏洩リスクあり）', 'wp-security-checker' );
		} else {
			$status  = 'good';
			$message = '';
		}

		return array(
			'id'      => 'b1',
			'label'   => __( 'デバッグモード', 'wp-security-checker' ),
			'status'  => $status,
			'message' => $message,
			'detail'  => $debug_enabled
				? __( 'WP_DEBUG: true（本番環境では無効化推奨）', 'wp-security-checker' )
				: __( 'WP_DEBUG: false（正常）', 'wp-security-checker' ),
		);
	}

	/**
	 * B-2: ファイル編集機能チェック
	 *
	 * @return array Check result.
	 */
	private function check_file_editing() {
		$editing_disabled = defined( 'DISALLOW_FILE_EDIT' ) && true === DISALLOW_FILE_EDIT;

		if ( $editing_disabled ) {
			$status  = 'good';
			$message = '';
		} else {
			$status  = 'attention';
			$message = __( "wp-config.phpに define('DISALLOW_FILE_EDIT', true); を追加してください（テーマ・プラグインの不正編集を防止）", 'wp-security-checker' );
		}

		return array(
			'id'      => 'b2',
			'label'   => __( 'ファイル編集機能', 'wp-security-checker' ),
			'status'  => $status,
			'message' => $message,
			'detail'  => $editing_disabled
				? __( 'DISALLOW_FILE_EDIT: true（管理画面からの編集を無効化済み）', 'wp-security-checker' )
				: __( 'DISALLOW_FILE_EDIT: 未設定（管理画面からファイル編集が可能）', 'wp-security-checker' ),
		);
	}

	/**
	 * B-3: HTTPS設定チェック
	 *
	 * @return array Check result.
	 */
	private function check_https() {
		$is_https = is_ssl();

		if ( $is_https ) {
			$status  = 'good';
			$message = '';
		} else {
			$status  = 'recommended';
			$message = __( 'SSL証明書を導入し、常時HTTPSを設定してください（現在: HTTP運用中）', 'wp-security-checker' );
		}

		return array(
			'id'      => 'b3',
			'label'   => __( 'HTTPS設定', 'wp-security-checker' ),
			'status'  => $status,
			'message' => $message,
			'detail'  => $is_https
				? __( 'HTTPS: 有効（SSL証明書が適用されています）', 'wp-security-checker' )
				: __( 'HTTPS: 無効（HTTP接続）', 'wp-security-checker' ),
		);
	}

	/**
	 * B-4: ログインURL変更チェック
	 *
	 * Checks if any known login-URL-changing plugin is active.
	 *
	 * @return array Check result.
	 */
	private function check_login_url() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$login_url_plugins = array(
			'wps-hide-login/wps-hide-login.php',
			'sf-move-login/sf-move-login.php',
			'custom-login-page-customizer/custom-login-page-customizer.php',
			'rename-wp-login/rename-wp-login.php',
			'secure-custom-login-page/secure-custom-login-page.php',
			'wp-hide-login/wp-hide-login.php',
			'loginpress/loginpress.php',
			'change-wp-admin-login/change-wp-admin-login.php',
		);

		$plugin_found = false;
		foreach ( $login_url_plugins as $plugin ) {
			if ( is_plugin_active( $plugin ) ) {
				$plugin_found = true;
				break;
			}
		}

		if ( $plugin_found ) {
			$status  = 'good';
			$message = '';
			$detail  = __( 'ログインURL変更プラグインが有効です', 'wp-security-checker' );
		} else {
			$status  = 'attention';
			$message = __( 'ログインURLを変更するプラグイン（例: WPS Hide Login）の導入を検討してください（デフォルトURL: /wp-login.php）', 'wp-security-checker' );
			$detail  = __( 'デフォルトのログインURL (/wp-login.php) が使用されています', 'wp-security-checker' );
		}

		return array(
			'id'      => 'b4',
			'label'   => __( 'ログインURL', 'wp-security-checker' ),
			'status'  => $status,
			'message' => $message,
			'detail'  => $detail,
		);
	}

	/**
	 * B-5: 管理者ユーザー名チェック
	 *
	 * @return array Check result.
	 */
	private function check_admin_username() {
		$admin_user = get_user_by( 'login', 'admin' );

		if ( false === $admin_user ) {
			$status  = 'good';
			$message = '';
			$detail  = __( 'ユーザー名 "admin" は存在しません（良好）', 'wp-security-checker' );
		} else {
			$status  = 'attention';
			$message = __( 'ユーザー名 "admin" が存在します。別のユーザー名に変更してください（バックアップ後に実施）', 'wp-security-checker' );
			$detail  = __( 'ユーザー名 "admin" が検出されました（ブルートフォース攻撃のリスク）', 'wp-security-checker' );
		}

		return array(
			'id'      => 'b5',
			'label'   => __( '管理者ユーザー名', 'wp-security-checker' ),
			'status'  => $status,
			'message' => $message,
			'detail'  => $detail,
		);
	}

	/**
	 * B-6: データベース接頭辞チェック
	 *
	 * @return array Check result.
	 */
	private function check_db_prefix() {
		global $wpdb;

		$prefix = $wpdb->prefix;

		if ( 'wp_' === $prefix ) {
			$status  = 'attention';
			$message = __( 'DBテーブル接頭辞が "wp_" のままです。新規構築時には変更を推奨します（稼働中サイトでの変更は慎重に）', 'wp-security-checker' );
		} else {
			$status  = 'good';
			$message = '';
		}

		return array(
			'id'      => 'b6',
			'label'   => __( 'データベース接頭辞', 'wp-security-checker' ),
			'status'  => $status,
			'message' => $message,
			/* translators: %s: current database table prefix */
			'detail'  => sprintf( __( '現在の接頭辞: %s', 'wp-security-checker' ), esc_html( $prefix ) ),
		);
	}
}
