<?php
/**
 * Category A: バージョン鮮度チェック（設計書カテゴリA・3項目）
 *
 * 判定はサイト内の状態とWordPress組み込みの更新情報の読み取りで完結し、
 * 外部の脆弱性データベースとは突合しない（突合はProの脆弱性アラート）。
 *
 * @package CyberNote_Security_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all version-freshness diagnostic checks (A-1 through A-3).
 */
class CNSC_Category_A {

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
		// 「非表示」にされた更新も含めて判定する（非表示＝適用済みではないため）。
		$updates = get_core_updates( array( 'dismissed' => true ) );

		// WordPressの更新確認が一度も成功していない場合、最新かどうかは分からない。
		// 「問題なし」とは表示せず、確認できなかったことをそのまま伝える。
		if ( empty( $updates ) ) {
			return array(
				'id'      => 'a1',
				'label'   => __( 'WordPress本体', 'cybernote-security-checker' ),
				'status'  => 'attention',
				'message' => __( 'WordPressの更新情報をまだ取得できていないため、最新かどうかを確認できませんでした。「ダッシュボード → 更新」を開くと更新確認が行われます。それでも表示されない場合は、サーバーから WordPress.org への通信が制限されていないかご確認ください', 'cybernote-security-checker' ),
				/* translators: %s: current WordPress version */
				'detail'  => sprintf( __( '現在のバージョン: %s（更新情報は未取得）', 'cybernote-security-checker' ), $current ),
			);
		}

		if ( isset( $updates[0]->response ) && 'latest' === $updates[0]->response ) {
			return array(
				'id'      => 'a1',
				'label'   => __( 'WordPress本体', 'cybernote-security-checker' ),
				'status'  => 'good',
				'message' => '',
				/* translators: %s: current WordPress version */
				'detail'  => sprintf( __( '現在のバージョン: %s（最新）', 'cybernote-security-checker' ), $current ),
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
			$message = __( 'メンテナンス版（セキュリティ・不具合修正）が未適用です。更新画面から早めに本体を更新してください', 'cybernote-security-checker' );
		} elseif ( $has_feature ) {
			$status  = 'attention';
			$message = __( '新しいバージョン（機能更新）が出ています。緊急性は低めですが、更新画面から本体を更新できます', 'cybernote-security-checker' );
		} else {
			$status  = 'good';
			$message = '';
		}

		return array(
			'id'      => 'a1',
			'label'   => __( 'WordPress本体', 'cybernote-security-checker' ),
			'status'  => $status,
			'message' => $message,
			/* translators: 1: current WP version, 2: latest WP version */
			'detail'  => sprintf( __( '現在: %1$s / 最新: %2$s', 'cybernote-security-checker' ), $current, $latest_version ),
		);
	}

	/**
	 * PHP公式のサポート期限（https://www.php.net/supported-versions.php）。
	 *
	 * 系列 => array( 通常サポート終了日, セキュリティサポート終了日 )。
	 * 日付で判定するため、プラグインを更新しなくても期限を過ぎた系列は自動的に
	 * 判定が厳しくなる（固定のしきい値のように判定が古くならない）。
	 * 新しい系列が出たら行を追加する。表にない新しい系列は「サポート中」として扱う。
	 *
	 * @return array
	 */
	private static function php_support_schedule() {
		return array(
			'7.4' => array( '2021-11-28', '2022-11-28' ),
			'8.0' => array( '2022-11-26', '2023-11-26' ),
			'8.1' => array( '2023-11-25', '2025-12-31' ),
			'8.2' => array( '2024-12-31', '2026-12-31' ),
			'8.3' => array( '2025-12-31', '2027-12-31' ),
			'8.4' => array( '2026-12-31', '2028-12-31' ),
			'8.5' => array( '2027-12-31', '2029-12-31' ),
		);
	}

	/**
	 * PHP系列のサポート段階を判定する。
	 *
	 * @param string $branch PHP系列（例: 8.3）。
	 * @param string $today  判定日（Y-m-d）。
	 * @return array array( 'active'|'security'|'eol', セキュリティサポート終了日 or '' )
	 */
	public static function php_support_phase( $branch, $today ) {
		$schedule = self::php_support_schedule();

		if ( ! isset( $schedule[ $branch ] ) ) {
			$oldest = array_keys( $schedule )[0];
			return array( version_compare( $branch, $oldest, '<' ) ? 'eol' : 'active', '' );
		}

		list( $active_end, $eol_date ) = $schedule[ $branch ];
		if ( $today > $eol_date ) {
			return array( 'eol', $eol_date );
		}
		if ( $today > $active_end ) {
			return array( 'security', $eol_date );
		}
		return array( 'active', $eol_date );
	}

	/**
	 * A-2: PHPバージョンがサポート対象か
	 *
	 * good: 通常サポート中 / attention: セキュリティ修正のみの期間 / recommended: サポート終了。
	 *
	 * @return array Check result.
	 */
	private function check_php_version() {
		$current = PHP_VERSION;
		list( $phase, $eol_date ) = self::php_support_phase( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, gmdate( 'Y-m-d' ) );

		if ( 'active' === $phase ) {
			$status  = 'good';
			$message = '';
		} elseif ( 'security' === $phase ) {
			$status  = 'attention';
			$message = sprintf(
				/* translators: %s: security support end date */
				__( 'セキュリティ修正だけが提供される期間に入っている系列です（%s にサポート終了）。期限までに、サーバーの管理画面から新しいPHPへの切り替えを計画してください（変更前にバックアップ推奨）', 'cybernote-security-checker' ),
				$eol_date
			);
		} else {
			$status  = 'recommended';
			$message = __( 'サポートが終了した系列です。新しい脆弱性が見つかっても修正されません。サーバーの管理画面からPHPのバージョンを上げてください（変更前にバックアップ推奨）', 'cybernote-security-checker' );
		}

		$detail = $eol_date
			/* translators: 1: current PHP version, 2: security support end date */
			? sprintf( __( '現在のバージョン: %1$s（サポート終了日: %2$s）', 'cybernote-security-checker' ), $current, $eol_date )
			/* translators: %s: current PHP version string */
			: sprintf( __( '現在のバージョン: %s', 'cybernote-security-checker' ), $current );

		return array(
			'id'      => 'a2',
			'label'   => __( 'PHPバージョン', 'cybernote-security-checker' ),
			'status'  => $status,
			'message' => $message,
			'detail'  => $detail,
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

		// 更新確認が一度も成功していない場合、「更新待ち0件」とは言えない。
		$checked = is_object( $plugin_updates ) && ! empty( $plugin_updates->last_checked )
			&& is_object( $theme_updates ) && ! empty( $theme_updates->last_checked );

		if ( ! $checked ) {
			$status  = 'attention';
			$message = __( 'プラグイン・テーマの更新情報をまだ取得できていないため、更新待ちがあるか確認できませんでした。「ダッシュボード → 更新」を開くと更新確認が行われます', 'cybernote-security-checker' );
		} elseif ( 0 === $count ) {
			$status  = 'good';
			$message = '';
		} else {
			$status  = 'attention';
			/* translators: %d: number of plugins/themes needing updates */
			$message = sprintf( __( '%d件の更新待ちがあります。更新画面から最新版に更新してください（更新前のバックアップ推奨）', 'cybernote-security-checker' ), $count );
		}

		$detail = '';
		if ( ! empty( $names ) ) {
			/* translators: %s: comma-separated list of plugin/theme names */
			$detail = sprintf( __( '対象: %s', 'cybernote-security-checker' ), implode( '、', $names ) );
		} elseif ( ! $checked ) {
			$detail = __( '更新情報: 未取得', 'cybernote-security-checker' );
		}

		return array(
			'id'      => 'a3',
			'label'   => __( 'プラグイン・テーマの更新', 'cybernote-security-checker' ),
			'status'  => $status,
			'message' => $message,
			'detail'  => $detail,
		);
	}
}
