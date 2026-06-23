<?php
/**
 * Pro版スキャナー
 *
 * Phase 1: 環境情報収集メソッド + モックデータ返却
 * Phase 2: POST /api/v1/scan エンドポイントとの連携に置き換える
 *
 * @package WP_Security_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects environment info and returns vulnerability scan results.
 */
class WSC_Pro_Scanner {

	/**
	 * インストール済みプラグイン・テーマ・WP/PHPバージョンを収集する。
	 * Phase 2でこのデータを /api/v1/scan に送信する。
	 *
	 * @return array
	 */
	public static function collect_environment() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$slug      = dirname( $file );
			$plugins[] = array(
				'slug'    => '.' === $slug ? basename( $file, '.php' ) : $slug,
				'name'    => $data['Name'],
				'version' => $data['Version'],
			);
		}

		$theme  = wp_get_theme();
		$themes = array(
			array(
				'slug'    => $theme->get_stylesheet(),
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
			),
		);
		if ( $theme->parent() ) {
			$parent   = $theme->parent();
			$themes[] = array(
				'slug'    => $parent->get_stylesheet(),
				'name'    => $parent->get( 'Name' ),
				'version' => $parent->get( 'Version' ),
			);
		}

		return array(
			'license_key' => WSC_Pro_License::get_key(),
			'site_url'    => get_site_url(),
			'wp_version'  => get_bloginfo( 'version' ),
			'php_version' => PHP_VERSION,
			'plugins'     => $plugins,
			'themes'      => $themes,
		);
	}

	/**
	 * スキャン結果を返す。
	 * Phase 1: モックデータを返す。Phase 2でAPIレスポンスに置き換える。
	 *
	 * @return array
	 */
	public static function get_scan_results() {
		return array(
			'status'          => 'ok',
			'scanned_at'      => current_time( 'c' ),
			'is_mock'         => true,
			'vulnerabilities' => array(
				array(
					'type'              => 'plugin',
					'slug'              => 'contact-form-7',
					'name'              => 'Contact Form 7',
					'installed_version' => '5.9',
					'fixed_version'     => '5.9.5',
					'severity'          => 'critical',
					'vuln_type_ja'      => 'クロスサイトスクリプティング（XSS）',
					'title_ja'          => 'Contact Form 7 5.9以前に認証不要XSS',
					'description_ja'    => 'フォームの入力値が適切に無害化されないため、悪意のあるスクリプトを埋め込まれる可能性があります。攻撃者はフォームを通じて任意のスクリプトをサイトに挿入できます。',
					'action_ja'         => 'プラグイン更新画面から Contact Form 7 を 5.9.5 以上に更新してください。',
					'cve_id'            => 'CVE-2026-00001',
					'references'        => array(
						array(
							'label' => 'WPScan 脆弱性詳細',
							'url'   => 'https://wpscan.com/',
						),
					),
				),
				array(
					'type'              => 'plugin',
					'slug'              => 'woocommerce',
					'name'              => 'WooCommerce',
					'installed_version' => '8.8.3',
					'fixed_version'     => '8.9.0',
					'severity'          => 'high',
					'vuln_type_ja'      => '権限昇格',
					'title_ja'          => 'WooCommerce 8.8.x 権限昇格の脆弱性',
					'description_ja'    => '認証済みの低権限ユーザーが、特定の条件下で管理者権限相当の操作を実行できる可能性があります。',
					'action_ja'         => 'WooCommerce を 8.9.0 以上に更新してください。',
					'cve_id'            => 'CVE-2026-00002',
					'references'        => array(
						array(
							'label' => 'Wordfence 脆弱性詳細',
							'url'   => 'https://www.wordfence.com/',
						),
					),
				),
			),
			'next_check_at'   => gmdate( 'c', strtotime( '+1 day' ) ),
		);
	}
}
