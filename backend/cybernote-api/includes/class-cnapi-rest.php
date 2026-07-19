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

		$env = array(
			'wp_version'  => sanitize_text_field( (string) ( $params['wp_version'] ?? '' ) ),
			'php_version' => sanitize_text_field( (string) ( $params['php_version'] ?? '' ) ),
			'plugins'     => self::sanitize_components( $params['plugins'] ?? array() ),
			'themes'      => self::sanitize_components( $params['themes'] ?? array() ),
		);

		if ( count( $env['plugins'] ) + count( $env['themes'] ) > 200 ) {
			return self::error( 'too_many_items', '対象が多すぎます（最大200件）。', 400 );
		}

		$matcher         = new CNAPI_Matcher();
		$vulnerabilities = $matcher->scan( $env );

		return new WP_REST_Response(
			array(
				'status'          => 'ok',
				'scanned_at'      => gmdate( 'c' ),
				'vulnerabilities' => $vulnerabilities,
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
	protected static function sanitize_components( $items ) {
		$clean = array();
		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$slug    = sanitize_key( (string) ( $item['slug'] ?? '' ) );
			$version = sanitize_text_field( (string) ( $item['version'] ?? '' ) );
			if ( '' === $slug || '' === $version ) {
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
