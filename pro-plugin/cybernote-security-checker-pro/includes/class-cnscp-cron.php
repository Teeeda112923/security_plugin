<?php
/**
 * 毎日の自動スキャン（WP-Cron）。
 *
 * 有効化時に翌日以降の毎日イベントを登録し、無効化時に解除する。
 * 失敗してもサイト動作に影響を出さない（結果は次回スキャンで上書き）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CNSCP_Cron {

	const EVENT = 'cnscp_daily_scan';

	/**
	 * 有効化: 毎日イベントを登録し、初回スキャンを予約する。
	 */
	public static function activate() {
		if ( ! wp_next_scheduled( self::EVENT ) ) {
			// 深夜帯（サーバー時間 3:00 台）に散らして登録。
			$first = strtotime( 'tomorrow 3:00' ) + wp_rand( 0, 3600 );
			wp_schedule_event( $first, 'daily', self::EVENT );
		}
	}

	/**
	 * 無効化: イベント解除。
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::EVENT );
	}

	/**
	 * 毎日の実行本体。
	 */
	public static function run_daily_scan() {
		CNSCP_Scanner::run();
	}
}
