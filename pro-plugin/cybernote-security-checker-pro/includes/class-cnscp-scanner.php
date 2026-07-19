<?php
/**
 * 環境情報の収集 → CyberNote API への送信 → 結果の保存。
 *
 * 送信するのはプラグイン・テーマのスラッグ／バージョン／表示名と、
 * WP本体・PHPのバージョン、サイトURLのみ。個人情報は送らない。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CNSCP_Scanner {

	const OPT_LICENSE   = 'cnscp_license_key';
	const OPT_RESULTS   = 'cnscp_scan_results';
	const OPT_LAST_SCAN = 'cnscp_last_scan';
	const OPT_LAST_ERROR = 'cnscp_last_error';

	/**
	 * スキャンを実行し、結果を保存する。
	 *
	 * @return true|WP_Error 成功時true。失敗時はWP_Error（コード: no_license / api_error 等）。
	 */
	public static function run() {
		$license = trim( (string) get_option( self::OPT_LICENSE, '' ) );
		if ( '' === $license ) {
			return new WP_Error( 'no_license', 'ライセンスキーが設定されていません。' );
		}

		$payload  = self::collect_environment( $license );
		$response = wp_remote_post(
			CNSCP_API_URL,
			array(
				'timeout' => 60,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			// 生のエラー内容（cURLのタイムアウト/SSL/DNS等）を残して原因特定に使う。
			$detail = $response->get_error_message();
			update_option( self::OPT_LAST_ERROR, 'CyberNoteに接続できませんでした。（詳細: ' . $detail . '）', false );
			return new WP_Error( 'api_error', 'CyberNoteに接続できませんでした。時間をおいてお試しください。' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) || 'ok' !== ( $body['status'] ?? '' ) ) {
			$message = is_array( $body ) && ! empty( $body['message'] )
				? (string) $body['message']
				: 'スキャンに失敗しました（コード: ' . $code . '）。';
			update_option( self::OPT_LAST_ERROR, $message, false );
			return new WP_Error( 'api_error', $message );
		}

		update_option(
			self::OPT_RESULTS,
			array(
				'scanned_at'      => (string) ( $body['scanned_at'] ?? '' ),
				'vulnerabilities' => self::sanitize_results( $body['vulnerabilities'] ?? array() ),
				// 一部の照合が失敗/打ち切りなら「0件＝安全」と誤認させない。
				'incomplete'      => ! empty( $body['incomplete'] ),
			),
			false
		);
		update_option( self::OPT_LAST_SCAN, time(), false );
		delete_option( self::OPT_LAST_ERROR );
		return true;
	}

	/**
	 * 送信ペイロードの組み立て。
	 *
	 * @param string $license License key.
	 * @return array
	 */
	public static function collect_environment( $license ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			// "dir/main.php" はディレクトリ名、"hello.php" 単体はファイル名（拡張子抜き）がスラッグ。
			$dir = false !== strpos( $file, '/' ) ? strtok( $file, '/' ) : basename( $file, '.php' );
			$plugins[] = array(
				'slug'    => sanitize_key( $dir ),
				'version' => (string) ( $data['Version'] ?? '' ),
				'name'    => (string) ( $data['Name'] ?? $dir ),
			);
		}

		$themes = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$themes[] = array(
				'slug'    => sanitize_key( $slug ),
				'version' => (string) $theme->get( 'Version' ),
				'name'    => (string) $theme->get( 'Name' ),
			);
		}

		return array(
			'license_key' => $license,
			'site_url'    => home_url(),
			'wp_version'  => get_bloginfo( 'version' ),
			'php_version' => PHP_VERSION,
			'plugins'     => $plugins,
			'themes'      => $themes,
		);
	}

	/**
	 * APIから受け取った結果の無害化（表示前提の保存用）。
	 *
	 * @param mixed $items Raw vulnerabilities.
	 * @return array
	 */
	protected static function sanitize_results( $items ) {
		$clean = array();
		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$refs = array();
			foreach ( (array) ( $item['references'] ?? array() ) as $url ) {
				$url = esc_url_raw( (string) $url );
				if ( '' !== $url ) {
					$refs[] = $url;
				}
			}
			$clean[] = array(
				'type'              => sanitize_key( (string) ( $item['type'] ?? '' ) ),
				'slug'              => sanitize_key( (string) ( $item['slug'] ?? '' ) ),
				'name'              => sanitize_text_field( (string) ( $item['name'] ?? '' ) ),
				'installed_version' => sanitize_text_field( (string) ( $item['installed_version'] ?? '' ) ),
				'fixed_version'     => sanitize_text_field( (string) ( $item['fixed_version'] ?? '' ) ),
				'unfixed'           => ! empty( $item['unfixed'] ),
				'severity'          => sanitize_key( (string) ( $item['severity'] ?? 'unknown' ) ),
				'vuln_type_ja'      => sanitize_text_field( (string) ( $item['vuln_type_ja'] ?? '' ) ),
				'description_ja'    => sanitize_text_field( (string) ( $item['description_ja'] ?? '' ) ),
				'action_ja'         => sanitize_text_field( (string) ( $item['action_ja'] ?? '' ) ),
				'cve_id'            => sanitize_text_field( (string) ( $item['cve_id'] ?? '' ) ),
				'references'        => array_slice( $refs, 0, 3 ),
			);
		}
		return $clean;
	}

	/**
	 * 保存済みの最新結果。
	 *
	 * @return array { scanned_at, vulnerabilities } or 空配列.
	 */
	public static function latest_results() {
		$results = get_option( self::OPT_RESULTS, array() );
		return is_array( $results ) ? $results : array();
	}
}
