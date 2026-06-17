<?php
/**
 * Category A: バージョン鮮度チェック（設計書カテゴリA・3項目）
 *
 * 判定はサイト内の状態とWordPress組み込みの更新情報の読み取りで完結し、
 * 外部の脆弱性データベースとは突合しない（突合はProの脆弱性アラート）。
 *
 * @package WP_Security_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all version-freshness diagnostic checks (A-1 through A-3).
 */
class WSC_Category_A {

	/**
	 * Run all Category A checks.
	 *
	 * @return array Array of check result arrays.
	 */
	public function run() {
		return array(
			$this->check_wp_version(),
			$this->check_php_version(),
			$this->check_core_update_count(),
		);
	}

	/**
	 * A-1: WordPress本体が最新か
	 *
	 * WordPressのバージョンは「X.Y＝機能更新（メジャー）」「X.Y.Z＝主にセキュリティ
	 * と不具合修正（メンテナンス版）」という構造。末尾の更新が未適用のときだけ
	 * recommendedに上げ、機能更新（新メジャー）は急かさない。
	 *
	 * @return array Check result.
	 */
	private function check_wp_version() {
		global $wp_version;

		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$current = $wp_version;
		$updates = get_core_updates();

		// 更新情報が取得できない、または最新の場合は good。
		if (
			empty( $updates ) ||
			( isset( $updates[0]->response ) && 'latest' === $updates[0]->response )
		) {
			return array(
				'id'      => 'a1',
				'label'   => __( 'WordPress本体', 'wp-security-checker' ),
				'status'  => 'good',
				'message' => '',
				/* translators: %s: current WordPress version */
				'detail'  => sprintf( __( '現在のバージョン: %s（最新）', 'wp-security-checker' ), esc_html( $current ) ),
			);
		}

		$current_parts = array_map( 'intval', explode( '.', $current ) );
		$current_major = $current_parts[0] ?? 0;
		$current_minor = $current_parts[1] ?? 0;
		$current_patch = $current_parts[2] ?? 0;

		$has_maintenance = false; // 同一メジャー系列のメンテナンス版（セキュリティ修正）が未適用。
		$has_feature     = false; // 新しいメジャー版（機能更新）が利用可能。
		$latest_version  = $current;

		foreach ( $updates as $update ) {
			if ( empty( $update->version ) ) {
				continue;
			}
			if ( version_compare( $update->version, $latest_version, '>' ) ) {
				$latest_version = $update->version;
			}

			$u_parts = array_map( 'intval', explode( '.', $update->version ) );
			$u_major = $u_parts[0] ?? 0;
			$u_minor = $u_parts[1] ?? 0;
			$u_patch = $u_parts[2] ?? 0;

			if ( $u_major === $current_major && $u_minor === $current_minor ) {
				// 同じ X.Y 系列で末尾だけ新しい＝メンテナンス（セキュリティ）版。
				if ( $u_patch > $current_patch ) {
					$has_maintenance = true;
				}
			} elseif (
				$u_major > $current_major ||
				( $u_major === $current_major && $u_minor > $current_minor )
			) {
				// より新しい X.Y＝機能更新（メジャー）。
				$has_feature = true;
			}
		}

		if ( $has_maintenance ) {
			$status  = 'recommended';
			$message = __( 'メンテナンス版（セキュリティ・不具合修正）が未適用です。更新画面から早めに本体を更新してください', 'wp-security-checker' );
		} elseif ( $has_feature ) {
			$status  = 'attention';
			$message = __( '新しいバージョン（機能更新）が出ています。緊急性は低めですが、更新画面から本体を更新できます', 'wp-security-checker' );
		} else {
			$status  = 'good';
			$message = '';
		}

		return array(
			'id'      => 'a1',
			'label'   => __( 'WordPress本体', 'wp-security-checker' ),
			'status'  => $status,
			'message' => $message,
			/* translators: 1: current WP version, 2: latest WP version */
			'detail'  => sprintf( __( '現在: %1$s / 最新: %2$s', 'wp-security-checker' ), esc_html( $current ), esc_html( $latest_version ) ),
		);
	}

	/**
	 * A-2: PHPバージョンがサポート対象か
	 *
	 * しきい値は日付の直書きを避け、メジャー番号ベースの緩い基準にする
	 * （プラグイン更新時に見直す前提）。2026年6月時点の公式サポート状況に基づく:
	 * 8.4/8.5以上 = good, 8.2/8.3 = attention, 8.1以下 = recommended。
	 *
	 * @return array Check result.
	 */
	private function check_php_version() {
		$current = PHP_VERSION;

		if ( version_compare( $current, '8.4', '>=' ) ) {
			$status  = 'good';
			$message = '';
		} elseif ( version_compare( $current, '8.2', '>=' ) ) {
			$status  = 'attention';
			$message = __( 'セキュリティ修正のみ、または間もなく終了する系列です。サーバーの管理画面からPHPのバージョンアップを検討してください（変更前にバックアップ推奨）', 'wp-security-checker' );
		} else {
			$status  = 'recommended';
			$message = __( 'サポートが終了した系列です。新しい脆弱性が見つかっても修正されません。サーバーの管理画面からPHPのバージョンを上げてください（変更前にバックアップ推奨）', 'wp-security-checker' );
		}

		return array(
			'id'      => 'a2',
			'label'   => __( 'PHPバージョン', 'wp-security-checker' ),
			'status'  => $status,
			'message' => $message,
			/* translators: %s: current PHP version string */
			'detail'  => sprintf( __( '現在のバージョン: %s', 'wp-security-checker' ), esc_html( $current ) ),
		);
	}

	/**
	 * A-3: プラグイン・テーマの未更新件数
	 *
	 * good / attention の二段階のみ（recommendedは設けない）。その更新が脆弱性修正か
	 * どうか・どれだけ危険かの判定には外部の脆弱性データとの突合が必要で、それはProの
	 * 脆弱性アラートの役割。無料版は「更新が来ている」事実を伝えるところまでにとどめる。
	 *
	 * @return array Check result.
	 */
	private function check_core_update_count() {
		$names = array();

		// プラグインの更新待ち。
		$plugin_updates = get_site_transient( 'update_plugins' );
		if ( ! empty( $plugin_updates ) && ! empty( $plugin_updates->response ) ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$all_plugins = get_plugins();
			foreach ( array_keys( $plugin_updates->response ) as $plugin_file ) {
				$names[] = isset( $all_plugins[ $plugin_file ]['Name'] )
					? $all_plugins[ $plugin_file ]['Name']
					: $plugin_file;
			}
		}

		// テーマの更新待ち。
		$theme_updates = get_site_transient( 'update_themes' );
		if ( ! empty( $theme_updates ) && ! empty( $theme_updates->response ) ) {
			$themes = wp_get_themes();
			foreach ( array_keys( $theme_updates->response ) as $theme_slug ) {
				if ( isset( $themes[ $theme_slug ] ) ) {
					$names[] = $themes[ $theme_slug ]->get( 'Name' );
				} else {
					$names[] = $theme_slug;
				}
			}
		}

		$count = count( $names );

		if ( 0 === $count ) {
			$status  = 'good';
			$message = '';
		} else {
			$status  = 'attention';
			/* translators: %d: number of plugins/themes needing updates */
			$message = sprintf( __( '%d件の更新待ちがあります。更新画面から最新版に更新してください（更新前のバックアップ推奨）', 'wp-security-checker' ), $count );
		}

		$detail = '';
		if ( ! empty( $names ) ) {
			/* translators: %s: comma-separated list of plugin/theme names */
			$detail = sprintf( __( '対象: %s', 'wp-security-checker' ), implode( '、', array_map( 'esc_html', $names ) ) );
		}

		return array(
			'id'      => 'a3',
			'label'   => __( 'プラグイン・テーマの更新', 'wp-security-checker' ),
			'status'  => $status,
			'message' => $message,
			'detail'  => $detail,
		);
	}
}
