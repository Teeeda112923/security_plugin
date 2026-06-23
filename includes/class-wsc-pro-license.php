<?php
/**
 * Pro版ライセンス管理
 *
 * Phase 1: フォーマット検証のみ（APIなしモック）
 * Phase 2: POST /api/v1/license/verify エンドポイントとの連携に置き換える
 *
 * @package WP_Security_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages Pro license key storage and status.
 */
class WSC_Pro_License {

	const OPTION_KEY    = 'wsc_license_key';
	const OPTION_STATUS = 'wsc_license_status';

	/** ライセンスキーフォーマット: WSC-XXXX-XXXX-XXXX-XXXX（英数字大文字） */
	const KEY_PATTERN = '/^WSC-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/';

	public function __construct() {
		add_action( 'admin_post_wsc_save_license', array( $this, 'handle_save' ) );
	}

	/**
	 * 保存済みライセンスキーを返す。
	 */
	public static function get_key() {
		return (string) get_option( self::OPTION_KEY, '' );
	}

	/**
	 * ライセンス状態を返す。
	 *
	 * @return array { valid: bool, plan: string, expires_at: string, error: string }
	 */
	public static function get_status() {
		$status = get_option( self::OPTION_STATUS, array() );
		if ( ! is_array( $status ) ) {
			return array(
				'valid'      => false,
				'plan'       => '',
				'expires_at' => '',
				'error'      => '',
			);
		}
		return wp_parse_args(
			$status,
			array(
				'valid'      => false,
				'plan'       => '',
				'expires_at' => '',
				'error'      => '',
			)
		);
	}

	/**
	 * Pro版が有効かどうか。
	 */
	public static function is_active() {
		$status = self::get_status();
		return ! empty( $status['valid'] );
	}

	/**
	 * キーのフォーマットが正しいか。
	 *
	 * @param string $key
	 * @return bool
	 */
	public static function is_valid_format( $key ) {
		return (bool) preg_match( self::KEY_PATTERN, strtoupper( trim( $key ) ) );
	}

	/**
	 * 設定ページからのフォーム送信を処理する。
	 */
	public function handle_save() {
		check_admin_referer( 'wsc_license_save' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}

		$action = isset( $_POST['wsc_license_action'] ) ? sanitize_key( $_POST['wsc_license_action'] ) : 'activate';

		if ( 'deactivate' === $action ) {
			delete_option( self::OPTION_KEY );
			delete_option( self::OPTION_STATUS );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'wp-security-checker-settings',
						'wsc_msg' => 'deactivated',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$key = strtoupper( trim( sanitize_text_field( wp_unslash( isset( $_POST['wsc_license_key'] ) ? $_POST['wsc_license_key'] : '' ) ) ) );

		if ( ! self::is_valid_format( $key ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'wp-security-checker-settings',
						'wsc_msg' => 'invalid_format',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Phase 1: フォーマットOKなら仮有効として保存。Phase 2でAPI検証に置き換える。
		update_option( self::OPTION_KEY, $key );
		update_option(
			self::OPTION_STATUS,
			array(
				'valid'      => true,
				'plan'       => 'pro',
				'expires_at' => gmdate( 'Y-m-d', strtotime( '+1 year' ) ),
				'error'      => '',
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'wp-security-checker-settings',
					'wsc_msg' => 'activated',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
