<?php
/**
 * Pro接続プラグインのオフラインテスト。
 *
 * WordPress関数をスタブし、APIレスポンスを擬似して
 * スキャナー（送信・保存・エラー処理）と管理画面の描画を検証する。
 *
 * 実行: php pro-plugin/tests/run-tests.php
 */

error_reporting( E_ALL );

// ---- WordPressスタブ ----
define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'CNSCP_VERSION', 'test' );
define( 'CNSCP_PLUGIN_DIR', __DIR__ . '/../cybernote-security-checker-pro/' );
define( 'CNSCP_PLUGIN_URL', 'https://example.com/wp-content/plugins/cnscp/' );
define( 'CNSCP_API_URL', 'https://api.test/scan' );

$GLOBALS['_options']       = array();
$GLOBALS['_http_response'] = null;
$GLOBALS['_http_log']      = array();

function get_option( $k, $d = false ) { return $GLOBALS['_options'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['_options'][ $k ] ); return true; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $s ) ) ); }
function esc_url_raw( $s ) { return filter_var( $s, FILTER_VALIDATE_URL ) ? $s : ''; }
function esc_url( $s ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_remote_retrieve_response_code( $r ) { return $r['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function home_url() { return 'https://mysite.example'; }
function get_bloginfo( $k ) { return '6.5.3'; }
function admin_url( $p = '' ) { return 'https://mysite.example/wp-admin/' . $p; }
function wp_nonce_field( $a ) { echo '<!-- nonce -->'; }
function number_format_i18n( $n ) { return number_format( $n ); }
function wp_date( $f, $t ) { return gmdate( $f, $t ); }
function wp_unslash( $v ) { return $v; }
function current_user_can( $c ) { return true; }
function sanitize_email( $s ) { return filter_var( trim( (string) $s ), FILTER_VALIDATE_EMAIL ) ?: ''; }
function checked( $a, $b = true ) { echo $a == $b ? 'checked' : ''; }
$GLOBALS['_mail'] = array();
function wp_mail( $to, $subject, $body ) { $GLOBALS['_mail'][] = compact( 'to', 'subject', 'body' ); return true; }

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['_http_log'][] = array( 'url' => $url, 'body' => json_decode( $args['body'] ?? '', true ) );
	return $GLOBALS['_http_response'];
}

function get_plugins() {
	return array(
		'contact-form-7/wp-contact-form-7.php' => array( 'Name' => 'Contact Form 7', 'Version' => '5.9' ),
		'hello.php'                            => array( 'Name' => 'Hello Dolly', 'Version' => '1.7.2' ),
	);
}

class Stub_Theme {
	private $data;
	public function __construct( $data ) { $this->data = $data; }
	public function get( $k ) { return $this->data[ $k ] ?? ''; }
}
function wp_get_themes() {
	return array( 'twentytwenty' => new Stub_Theme( array( 'Name' => 'Twenty Twenty', 'Version' => '2.1' ) ) );
}

require CNSCP_PLUGIN_DIR . 'includes/class-cnscp-scanner.php';
require CNSCP_PLUGIN_DIR . 'includes/class-cnscp-notifier.php';
require CNSCP_PLUGIN_DIR . 'includes/class-cnscp-admin.php';

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

// ---- 環境収集 ----
echo "== Scanner: collect ==\n";
$env = CNSCP_Scanner::collect_environment( 'WSC-AAAA-BBBB-CCCC-DDDD' );
check( 'プラグイン2件収集', 2 === count( $env['plugins'] ) );
check( 'ディレクトリ型スラッグ', 'contact-form-7' === $env['plugins'][0]['slug'] );
check( '単一ファイル型スラッグ', 'hello' === $env['plugins'][1]['slug'] );
check( 'テーマ収集', 'twentytwenty' === $env['themes'][0]['slug'] );
check( 'WP/PHPバージョン込み', '6.5.3' === $env['wp_version'] && '' !== $env['php_version'] );

// ---- スキャン成功 ----
echo "== Scanner: run ok ==\n";
update_option( CNSCP_Scanner::OPT_LICENSE, 'WSC-AAAA-BBBB-CCCC-DDDD' );
$GLOBALS['_http_response'] = array(
	'code' => 200,
	'body' => json_encode(
		array(
			'status'          => 'ok',
			'scanned_at'      => '2026-07-19T10:00:00+00:00',
			'vulnerabilities' => array(
				array(
					'type'              => 'plugin',
					'slug'              => 'contact-form-7',
					'name'              => 'Contact Form 7',
					'installed_version' => '5.9',
					'fixed_version'     => '5.9.5',
					'unfixed'           => false,
					'severity'          => 'critical',
					'vuln_type_ja'      => 'クロスサイトスクリプティング（XSS）',
					'description_ja'    => '入力値の処理に不備があります。',
					'action_ja'         => 'Contact Form 7 を 5.9.5 以上に更新してください。',
					'cve_id'            => 'CVE-2026-11111',
					'cybernote_url'     => 'https://www.cybernote.click/2026/07/02/cve-2026-11111-foo/',
					'references'        => array( 'https://example.com/ref', 'javascript:alert(1)' ),
				),
			),
		)
	),
);
$r = CNSCP_Scanner::run();
check( 'run()成功', true === $r );
$saved = CNSCP_Scanner::latest_results();
check( '結果保存', 1 === count( $saved['vulnerabilities'] ) );
check( '不正URLは除去', array( 'https://example.com/ref' ) === $saved['vulnerabilities'][0]['references'] );
check( '最終スキャン時刻を記録', 0 !== (int) get_option( CNSCP_Scanner::OPT_LAST_SCAN, 0 ) );
$sent = $GLOBALS['_http_log'][0]['body'];
check( '送信ペイロードにライセンス', 'WSC-AAAA-BBBB-CCCC-DDDD' === $sent['license_key'] );
check( '送信ペイロードにプラグイン', 2 === count( $sent['plugins'] ) );

// ---- スキャン失敗（ライセンス無効） ----
echo "== Scanner: run error ==\n";
$GLOBALS['_http_response'] = array(
	'code' => 403,
	'body' => json_encode( array( 'status' => 'error', 'code' => 'invalid_license', 'message' => 'ライセンスキーが無効です。' ) ),
);
$r = CNSCP_Scanner::run();
check( 'エラーはWP_Error', is_wp_error( $r ) );
check( 'API日本語メッセージを引き継ぐ', false !== strpos( $r->get_error_message(), 'ライセンスキーが無効' ) );
check( '前回の正常結果は保持', 1 === count( CNSCP_Scanner::latest_results()['vulnerabilities'] ) );
check( 'エラー詳細を保存', '' !== (string) get_option( CNSCP_Scanner::OPT_LAST_ERROR, '' ) );

// ---- キー未設定 ----
update_option( CNSCP_Scanner::OPT_LICENSE, '' );
$r = CNSCP_Scanner::run();
check( 'キー未設定はno_license', is_wp_error( $r ) && 'no_license' === $r->get_error_code() );

// ---- 管理画面描画（スモーク） ----
echo "== Admin: render ==\n";
update_option( CNSCP_Scanner::OPT_LICENSE, 'WSC-AAAA-BBBB-CCCC-DDDD' );
ob_start();
CNSCP_Admin::render_page();
$html = ob_get_clean();
check( 'タイトル表示', false !== strpos( $html, '脆弱性アラート' ) );
check( '検出プラグイン名表示', false !== strpos( $html, 'Contact Form 7' ) );
check( '深刻度: 重大 表示', false !== strpos( $html, '深刻度: 重大' ) );
check( '対処文表示', false !== strpos( $html, '5.9.5 以上に更新' ) );
check( 'CVE表示', false !== strpos( $html, 'CVE-2026-11111' ) );
check( '種別ラベル(プラグイン)表示', false !== strpos( $html, 'cnscp-type' ) && false !== strpos( $html, 'プラグイン' ) );
check( 'CyberNote解説リンク表示', false !== strpos( $html, 'CyberNoteで詳しく見る' ) );
check( 'CyberNoteリンク先URL', false !== strpos( $html, 'cve-2026-11111-foo' ) );
check( '更新画面ボタン', false !== strpos( $html, 'update-core.php' ) );
check( 'プライバシー注記', false !== strpos( $html, '個人情報は送信しません' ) );

// キー未設定時はオンボーディング表示
update_option( CNSCP_Scanner::OPT_LICENSE, '' );
ob_start();
CNSCP_Admin::render_page();
$html2 = ob_get_clean();
check( '未設定時はオンボーディング', false !== strpos( $html2, 'ライセンスキーを設定してください' ) );
check( '未設定時はスキャンボタン非表示', false === strpos( $html2, '今すぐスキャン' ) );

// 結果ゼロ件時は「問題なし」
update_option( CNSCP_Scanner::OPT_LICENSE, 'WSC-AAAA-BBBB-CCCC-DDDD' );
update_option( CNSCP_Scanner::OPT_RESULTS, array( 'scanned_at' => 'x', 'vulnerabilities' => array() ) );
ob_start();
CNSCP_Admin::render_page();
$html3 = ob_get_clean();
check( '0件時は問題なし表示', false !== strpos( $html3, '既知の脆弱性は見つかりませんでした' ) );

// 照合が不完全（incomplete=true）なら「安全」と誤表示せず警告を出す。
echo "== Admin: incomplete ==\n";
$GLOBALS['_http_response'] = array(
	'code' => 200,
	'body' => json_encode( array(
		'status'          => 'ok',
		'scanned_at'      => 'x',
		'vulnerabilities' => array(),
		'incomplete'      => true,
	) ),
);
CNSCP_Scanner::run();
check( 'incompleteフラグを保存', true === ( CNSCP_Scanner::latest_results()['incomplete'] ?? false ) );
ob_start();
CNSCP_Admin::render_page();
$html4 = ob_get_clean();
check( '不完全時は警告を表示', false !== strpos( $html4, '照合が最後まで完了しませんでした' ) );
check( '不完全時は「問題なし」を出さない', false === strpos( $html4, '既知の脆弱性は見つかりませんでした' ) );

// ---- メール通知（差分のみ・不完全時は送らない） ----
echo "== Notifier ==\n";
$GLOBALS['_options'] = array( 'admin_email' => 'owner@example.com' );
$GLOBALS['_mail']    = array();
$v_cf7  = array( 'type' => 'plugin', 'slug' => 'contact-form-7', 'name' => 'Contact Form 7', 'severity' => 'critical', 'cve_id' => 'CVE-2026-11111', 'vuln_type_ja' => 'XSS', 'action_ja' => '更新してください。', 'title' => 'x' );
$v_woo  = array( 'type' => 'plugin', 'slug' => 'woocommerce', 'name' => 'WooCommerce', 'severity' => 'high', 'cve_id' => 'CVE-2026-22222', 'action_ja' => '更新', 'title' => 'y' );

$n1 = CNSCP_Notifier::maybe_notify( array( $v_cf7 ), false );
check( '初回: 新規1件を通知', 1 === $n1 && 1 === count( $GLOBALS['_mail'] ) );
check( '宛先は管理者メール', 'owner@example.com' === $GLOBALS['_mail'][0]['to'] );
check( '本文にCVEと対処', false !== strpos( $GLOBALS['_mail'][0]['body'], 'CVE-2026-11111' ) );

$GLOBALS['_mail'] = array();
$n2 = CNSCP_Notifier::maybe_notify( array( $v_cf7 ), false );
check( '同じ内容の再スキャンは通知しない', 0 === $n2 && 0 === count( $GLOBALS['_mail'] ) );

$GLOBALS['_mail'] = array();
$n3 = CNSCP_Notifier::maybe_notify( array( $v_cf7, $v_woo ), false );
check( '新たに増えた分だけ通知', 1 === $n3 && false !== strpos( $GLOBALS['_mail'][0]['body'], 'CVE-2026-22222' ) );

$GLOBALS['_mail'] = array();
$n4 = CNSCP_Notifier::maybe_notify( array( $v_cf7 ), true );
check( '不完全スキャンでは通知しない', 0 === $n4 && 0 === count( $GLOBALS['_mail'] ) );

// 送信先指定・OFF。
$GLOBALS['_options']['cnscp_notify_email']   = 'me@example.com';
$GLOBALS['_options']['cnscp_notified_ids']   = array();
$GLOBALS['_mail']                            = array();
CNSCP_Notifier::maybe_notify( array( $v_cf7 ), false );
check( '送信先指定が反映', 'me@example.com' === $GLOBALS['_mail'][0]['to'] );

$GLOBALS['_options']['cnscp_notify_enabled'] = 0;
$GLOBALS['_options']['cnscp_notified_ids']   = array();
$GLOBALS['_mail']                            = array();
CNSCP_Notifier::maybe_notify( array( $v_woo ), false );
check( 'OFFなら送らない', 0 === count( $GLOBALS['_mail'] ) );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
