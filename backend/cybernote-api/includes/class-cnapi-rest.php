<?php
/**
 * REST APIエンドポイント。
 *
 * POST /wp-json/cybernote/v1/scan
 *   { license_key, site_url, wp_version, php_version, plugins: [...], themes: [...] }
 * → { status, scanned_at, vulnerabilities: [...] }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CNAPI_Rest {

	/**
	 * ルート登録。
	 */
	public static function register_routes() {
		register_rest_route(
			'cybernote/v1',
			'/scan',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_scan' ),
				'permission_callback' => '__return_true', // 認可はライセンスキーで行う。
			)
		);
	}

	/**
	 * スキャン要求の処理。
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_scan( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return self::error( 'invalid_request', 'リクエスト形式が不正です。', 400 );
		}

		$license_key = (string) ( $params['license_key'] ?? '' );
		if ( ! CNAPI_License::is_valid( $license_key ) ) {
			return self::error( 'invalid_license', 'ライセンスキーが無効です。', 403 );
		}
		if ( ! CNAPI_License::within_rate_limit( $license_key ) ) {
			return self::error( 'rate_limited', 'スキャン回数の上限に達しました。しばらくしてからお試しください。', 429 );
		}

		$input_skipped = 0;
		$plugins       = self::sanitize_components( $params['plugins'] ?? array(), $input_skipped );
		$themes        = self::sanitize_components( $params['themes'] ?? array(), $input_skipped );

		$env = array(
			'wp_version'  => sanitize_text_field( (string) ( $params['wp_version'] ?? '' ) ),
			'php_version' => sanitize_text_field( (string) ( $params['php_version'] ?? '' ) ),
			'plugins'     => $plugins,
			'themes'      => $themes,
		);

		if ( count( $env['plugins'] ) + count( $env['themes'] ) > 200 ) {
			return self::error( 'too_many_items', '対象が多すぎます（最大200件）。', 400 );
		}

		$matcher         = new CNAPI_Matcher();
		$vulnerabilities = $matcher->scan( $env );
		$stats           = $matcher->get_stats();

		// 入力の無害化時に除外した対象も、未照合としてカウントする。
		$stats['skipped'] += $input_skipped;

		// 照合失敗・時間切れ・対象情報不足・応答形式不正は「不完全」として扱う。
		// 未照合項目が残る場合、結果0件でも「脆弱性なし」と表示してはいけない。
		$incomplete = (
			$stats['failed'] > 0 ||
			$stats['aborted'] ||
			$stats['skipped'] > 0 ||
			$stats['parse_failed'] > 0
		);

		return new WP_REST_Response(
			array(
				'status'          => 'ok',
				'scanned_at'      => gmdate( 'c' ),
				'vulnerabilities' => $vulnerabilities,
				'incomplete'      => $incomplete,
				'stats'           => $stats,
				'next_check_at'   => gmdate( 'c', time() + DAY_IN_SECONDS ),
			),
			200
		);
	}

	/**
	 * plugins/themes配列の無害化。
	 *
	 * @param mixed $items Raw input.
	 * @return array
	 */
	protected static function sanitize_components( $items, &$skipped = 0 ) {
		$clean = array();
		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) ) {
				++$skipped;
				continue;
			}
			$slug    = sanitize_key( (string) ( $item['slug'] ?? '' ) );
			$version = sanitize_text_field( (string) ( $item['version'] ?? '' ) );
			if ( '' === $slug || '' === $version ) {
				++$skipped;
				continue;
			}
			$clean[] = array(
				'slug'    => $slug,
				'version' => $version,
				'name'    => sanitize_text_field( (string) ( $item['name'] ?? $slug ) ),
			);
		}
		return $clean;
	}

	/**
	 * エラーレスポンス。
	 *
	 * @param string $code    Error code.
	 * @param string $message Japanese message.
	 * @param int    $status  HTTP status.
	 * @return WP_REST_Response
	 */
	protected static function error( $code, $message, $status ) {
		return new WP_REST_Response(
			array(
				'status'  => 'error',
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}
}
