<?php
/**
 * cybernote-api のオフラインテスト。
 *
 * WordPress関数をスタブし、WPVulnerability APIの擬似レスポンスで
 * 突合エンジン・ライセンス検証・入力無害化を検証する。
 *
 * 実行: php backend/tests/run-tests.php
 */

error_reporting( E_ALL );

// ---- WordPressスタブ ----
define( 'ABSPATH', '/tmp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'CNAPI_VERSION', 'test' );

$GLOBALS['_options']    = array();
$GLOBALS['_transients'] = array();
$GLOBALS['_http_fixtures'] = array();

function get_option( $k, $d = false ) { return $GLOBALS['_options'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['_options'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['_transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['_transients'][ $k ] = $v; return true; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function esc_url_raw( $s ) { return filter_var( $s, FILTER_VALIDATE_URL ) ? $s : ''; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_remote_retrieve_response_code( $r ) { return $r['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function wp_remote_retrieve_header( $r, $k ) { return $r['headers'][ $k ] ?? ''; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
class WP_Error {}
// 自サイトのCVE解説記事検索用スタブ。cve-2026-11111 の記事だけ存在する想定。
function get_permalink( $id ) { return 'https://www.cybernote.click/article/' . (int) $id . '/'; }
class Fake_WPDB {
	public $posts = 'wp_posts';
	public function esc_like( $s ) { return $s; }
	public function prepare( $q, $arg ) { return str_replace( '%s', "'" . $arg . "'", $q ); }
	public function get_var( $q ) { return ( false !== strpos( $q, 'cve-2026-11111' ) ) ? 55 : null; }
}
$GLOBALS['wpdb'] = new Fake_WPDB();
function wp_remote_get( $url, $args = array() ) {
	$path = parse_url( $url, PHP_URL_PATH );
	if ( isset( $GLOBALS['_http_fixtures'][ $path ] ) ) {
		return array( 'code' => 200, 'body' => json_encode( $GLOBALS['_http_fixtures'][ $path ] ) );
	}
	return array( 'code' => 404, 'body' => '' );
}

require __DIR__ . '/../cybernote-api/includes/class-cnapi-license.php';
require __DIR__ . '/../cybernote-api/includes/class-cnapi-matcher.php';

$fails = 0;
function check( $label, $cond ) {
	global $fails;
	if ( $cond ) {
		echo "  ok: $label\n";
	} else {
		$fails++;
		echo "  FAIL: $label\n";
	}
}

// ---- フィクスチャ（WPVulnerability形式・フィールドゆれ込み） ----
$GLOBALS['_http_fixtures']['/plugin/contact-form-7/'] = array(
	'error' => 0,
	'data'  => array(
		'slug'          => 'contact-form-7',
		'vulnerability' => array(
			array( // 5.9.5未満が影響 / CVSS 9.8 / XSS
				'name'     => 'Contact Form 7 < 5.9.5 - Unauthenticated XSS',
				'operator' => array( 'max_version' => '5.9.5', 'max_operator' => 'lt' ),
				'impact'   => array(
					'cwe'  => array( array( 'cwe' => 'CWE-79', 'name' => 'XSS' ) ),
					'cvss' => array( 'score' => 9.8, 'vector' => 'AV:N/AC:L' ),
				),
				'source'   => array(
					array( 'id' => 'CVE-2026-11111', 'link' => 'https://example.com/cve-2026-11111' ),
				),
			),
			array( // 5.0〜5.5のみ影響（5.9は非該当のはず）
				'name'     => 'Old range bug',
				'operator' => array( 'min_version' => '5.0', 'min_operator' => 'ge', 'max_version' => '5.5', 'max_operator' => 'le' ),
				'impact'   => array( 'cvss' => array( 'score' => 5.0 ) ),
			),
			array( // operator無し → 判定不能なので除外されるはず
				'name'   => 'No range info',
				'impact' => array( 'cvss' => array( 'score' => 7.0 ) ),
			),
		),
	),
);
$GLOBALS['_http_fixtures']['/theme/twentytwenty/'] = array(
	'error' => 0,
	'data'  => array(
		'slug'          => 'twentytwenty',
		'vulnerability' => array(
			array( // 修正版なし（unfixed）/ スコア無し / CWE-89
				'name'     => 'Unfixed SQLi',
				'operator' => array( 'unfixed' => true ),
				'impact'   => array( 'cwe' => array( 'CWE-89' ) ),
				'source'   => array( array( 'id' => 'WPVDB-123', 'link' => 'https://example.com/wpvdb-123' ) ),
			),
		),
	),
);
$GLOBALS['_http_fixtures']['/core/6.5.3/'] = array(
	'error' => 0,
	'data'  => array( 'vulnerability' => array() ),
);

// ---- 突合エンジン ----
echo "== Matcher ==\n";
$m = new CNAPI_Matcher();
$out = $m->scan(
	array(
		'wp_version' => '6.5.3',
		'plugins'    => array( array( 'slug' => 'contact-form-7', 'version' => '5.9', 'name' => 'Contact Form 7' ) ),
		'themes'     => array( array( 'slug' => 'twentytwenty', 'version' => '2.1', 'name' => 'Twenty Twenty' ) ),
	)
);
check( '検出件数=2（範囲外・判定不能は除外）', 2 === count( $out ) );
$cf7 = $out[0];
check( '深刻度順: critical が先頭', 'critical' === $cf7['severity'] );
check( 'CF7: 修正版5.9.5', '5.9.5' === $cf7['fixed_version'] );
check( 'CF7: CVE取得', 'CVE-2026-11111' === $cf7['cve_id'] );
check( 'CF7: XSS日本語分類', 'クロスサイトスクリプティング（XSS）' === $cf7['vuln_type_ja'] );
check( 'CF7: 対応文に5.9.5', false !== strpos( $cf7['action_ja'], '5.9.5' ) );
check( 'CF7: CyberNote解説記事URLを付与', 'https://www.cybernote.click/article/55/' === $cf7['cybernote_url'] );
$sqli = $out[1];
check( 'テーマ: unfixed=true', true === $sqli['unfixed'] );
check( 'テーマ: severity=unknown（スコア無し）', 'unknown' === $sqli['severity'] );
check( 'テーマ: SQLi日本語分類（数値CWE表記）', 'SQLインジェクション' === $sqli['vuln_type_ja'] );
check( 'テーマ: 未修正の対応文', false !== strpos( $sqli['action_ja'], '修正版が公開されていません' ) );
check( 'テーマ(CVE無し)は記事URL空', '' === $sqli['cybernote_url'] );

// バージョン非該当（更新済みサイト）
$out2 = $m->scan( array( 'plugins' => array( array( 'slug' => 'contact-form-7', 'version' => '5.9.5', 'name' => 'CF7' ) ) ) );
check( '5.9.5（修正済み）は0件', 0 === count( $out2 ) );

// キャッシュが効いている（fixtureを消しても結果が返る）
unset( $GLOBALS['_http_fixtures']['/plugin/contact-form-7/'] );
$out3 = $m->scan( array( 'plugins' => array( array( 'slug' => 'contact-form-7', 'version' => '5.9', 'name' => 'CF7' ) ) ) );
check( 'キャッシュから再スキャン可能', 1 === count( $out3 ) );

// ---- 統計・接続テスト ----
echo "== Matcher: stats & probe ==\n";
// フィクスチャを戻して統計を確認。
$GLOBALS['_http_fixtures']['/plugin/contact-form-7/'] = array(
	'error' => 0,
	'data'  => array( 'vulnerability' => array(
		array( 'name' => 'x', 'operator' => array( 'max_version' => '5.9.5', 'max_operator' => 'lt' ), 'impact' => array( 'cvss' => array( 'score' => 9.8 ) ) ),
	) ),
);
$GLOBALS['_transients'] = array(); // キャッシュ全消しでネットワーク経路を通す。
$m2 = new CNAPI_Matcher();
$m2->scan( array(
	'wp_version' => '6.5.3',
	'plugins'    => array( array( 'slug' => 'contact-form-7', 'version' => '5.9', 'name' => 'CF7' ) ),
) );
$st = $m2->get_stats();
check( '統計: 到達失敗0', 0 === $st['failed'] );
check( '統計: 打ち切り無し', false === $st['aborted'] );
check( '統計: 問い合わせ回数を記録', $st['checked'] >= 1 );

// 到達不可（存在しないfixture）は failed としてカウント。
$GLOBALS['_transients'] = array();
$m3 = new CNAPI_Matcher();
$m3->scan( array( 'plugins' => array( array( 'slug' => 'no-such-plugin-xyz', 'version' => '1.0', 'name' => 'X' ) ) ) );
check( '統計: 到達不可を failed に計上', $m3->get_stats()['failed'] >= 1 );

// DB不調でも「前回の正常データ」で照合を続け、不完全にしない。
$GLOBALS['_transients'] = array();
$m4 = new CNAPI_Matcher();
$env_cf7 = array( 'plugins' => array( array( 'slug' => 'contact-form-7', 'version' => '5.9', 'name' => 'CF7' ) ) );
$m4->scan( $env_cf7 ); // 1回目: 正常データを取得してキャッシュ。
$saved_fixture = $GLOBALS['_http_fixtures']['/plugin/contact-form-7/'];
unset( $GLOBALS['_http_fixtures']['/plugin/contact-form-7/'] ); // 相手DBが落ちた状況を再現。
// キャッシュを「古い」状態にして再問い合わせを強制する。
foreach ( $GLOBALS['_transients'] as $k => $v ) {
	if ( is_array( $v ) && isset( $v['ts'] ) ) {
		$GLOBALS['_transients'][ $k ]['ts'] = time() - ( 2 * 86400 );
	}
}
$m5  = new CNAPI_Matcher();
$out4 = $m5->scan( $env_cf7 );
$st5  = $m5->get_stats();
check( 'DB不調でも前回データで検出継続', 1 === count( $out4 ) );
check( 'DB不調でも失敗0（不完全にしない）', 0 === $st5['failed'] );
check( '前回データ使用を記録', true === $st5['used_stale'] );
$GLOBALS['_http_fixtures']['/plugin/contact-form-7/'] = $saved_fixture;

// probe: 接続テスト（キャッシュ非依存）。
$probe = ( new CNAPI_Matcher() )->probe( 'contact-form-7' );
check( 'probe: 接続OK', true === $probe['ok'] );
check( 'probe: 脆弱性件数を取得', $probe['vuln_count'] >= 1 );
$probe2 = ( new CNAPI_Matcher() )->probe( 'no-such-plugin-xyz' );
check( 'probe: 到達不可はok=false', false === $probe2['ok'] );

// ---- ライセンス ----
echo "== License ==\n";
update_option( CNAPI_License::OPTION_KEYS, "WSC-AAAA-BBBB-CCCC-DDDD\nbadline\nwsc-1111-2222-3333-4444\n" );
check( '登録キーは有効', CNAPI_License::is_valid( 'WSC-AAAA-BBBB-CCCC-DDDD' ) );
check( '小文字入力でも有効', CNAPI_License::is_valid( 'wsc-1111-2222-3333-4444' ) );
check( '未登録キーは無効', ! CNAPI_License::is_valid( 'WSC-ZZZZ-ZZZZ-ZZZZ-ZZZZ' ) );
check( '形式不正は無効', ! CNAPI_License::is_valid( 'HELLO' ) );
$okcount = 0;
for ( $i = 0; $i < 12; $i++ ) {
	if ( CNAPI_License::within_rate_limit( 'WSC-AAAA-BBBB-CCCC-DDDD' ) ) { $okcount++; }
}
check( 'レート制限: 12回中10回まで', 10 === $okcount );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
