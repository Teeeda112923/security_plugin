<?php
/**
 * 実証テスト（CNAPI_Poc）自体の自己検証。
 *
 * 本番サイトで実行する前に、実証テストの手順そのものが正しく働くかを
 * 擬似データで確認する。「正解の作り方」が壊れていると実証の意味が無いため、
 * ここでは次を確かめる。
 *   - 影響範囲から作った検証用バージョンが、本当に範囲の内側／外側になっているか
 *   - 検知できたか・黙れたかの集計が正しいか
 *   - 収録されていないコンポーネントを「照合できた」と数えていないか
 *
 * 実行: php backend/tests/poc-selftest.php
 */

error_reporting( E_ALL );

define( 'ABSPATH', '/tmp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['_transients']    = array();
$GLOBALS['_http_fixtures'] = array();

function get_option( $k, $d = false ) { return $d; }
function get_transient( $k ) { return $GLOBALS['_transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['_transients'][ $k ] = $v; return true; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function esc_url_raw( $s ) { return filter_var( $s, FILTER_VALIDATE_URL ) ? $s : ''; }
function is_wp_error( $x ) { return false; }
function wp_remote_retrieve_response_code( $r ) { return $r['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function wp_remote_retrieve_header( $r, $k ) { return ''; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function get_permalink( $id ) { return ''; }
function get_bloginfo( $what = '' ) { return '6.7.1'; }
function wp_remote_get( $url, $args = array() ) {
	$path = parse_url( $url, PHP_URL_PATH );
	if ( isset( $GLOBALS['_http_fixtures'][ $path ] ) ) {
		return array( 'code' => 200, 'body' => json_encode( $GLOBALS['_http_fixtures'][ $path ] ) );
	}
	return array( 'code' => 404, 'body' => '' );
}

require __DIR__ . '/../cybernote-api/includes/class-cnapi-matcher.php';
require __DIR__ . '/../cybernote-api/includes/class-cnapi-poc.php';

$fails = 0;
function check( $label, $cond, $extra = '' ) {
	global $fails;
	if ( $cond ) {
		echo "  ok: $label\n";
	} else {
		++$fails;
		echo "  FAIL: $label" . ( $extra ? "  ($extra)" : '' ) . "\n";
	}
}

/* ---- 擬似データ（WPVulnerability形式の代表的な形をひととおり用意） ---- */

function vuln( $name, $operator, $score = 7.5, $cwe = 79 ) {
	static $seq = 0;
	++$seq;
	return array(
		'name'     => $name,
		'operator' => $operator,
		'impact'   => array(
			'cwe'  => array( array( 'cwe' => 'CWE-' . $cwe ) ),
			'cvss' => array( 'score' => $score ),
		),
		'source'   => array( array( 'id' => sprintf( 'CVE-2025-%04d', $seq ), 'link' => 'https://example.org/x' ) ),
	);
}

$case_slugs = array( 'contact-form-7', 'woocommerce', 'elementor', 'wordpress-seo', 'jetpack', 'updraftplus', 'all-in-one-seo-pack', 'wpforms-lite' );

// 範囲の形をひととおり網羅させる。
$patterns = array(
	array( 'max_version' => '5.9.5', 'max_operator' => 'lt' ),
	array( 'min_version' => '2.0', 'min_operator' => 'ge', 'max_version' => '2.8.4', 'max_operator' => 'le' ),
	array( 'max_version' => '3.0.0', 'max_operator' => 'lt', 'min_version' => '1.5', 'min_operator' => 'gt' ),
	array( 'unfixed' => true, 'min_version' => '4.0', 'min_operator' => 'ge' ),
	array( 'max_version' => '1.10', 'max_operator' => 'lt' ),
	array( 'max_version' => '7.0', 'max_operator' => 'eq' ),
);

foreach ( $case_slugs as $i => $slug ) {
	$vulns = array();
	foreach ( $patterns as $j => $p ) {
		// スラッグごとに使う範囲パターンをずらし、偏らないようにする。
		if ( ( $i + $j ) % 2 === 0 ) {
			$vulns[] = vuln( strtoupper( $slug ) . " issue #$j", $p, 4.0 + $j, 79 );
		}
	}
	$vulns[] = vuln( strtoupper( $slug ) . ' no-range', array(), 6.0 ); // 範囲情報なし＝検証対象外
	$GLOBALS['_http_fixtures'][ '/plugin/' . $slug ] = array(
		'error' => 0,
		'data'  => array( 'slug' => $slug, 'vulnerability' => $vulns ),
	);
}

// カバー率測定用。収録あり／なしを混ぜる。
$corpus_plugins = array(
	'akismet', 'contact-form-7', 'wordpress-seo', 'elementor', 'woocommerce',
	'jetpack', 'wpforms-lite', 'all-in-one-seo-pack', 'wordfence', 'updraftplus',
	'really-simple-ssl', 'wp-super-cache', 'litespeed-cache', 'autoptimize', 'ewww-image-optimizer',
	'wp-mail-smtp', 'redirection', 'tablepress', 'advanced-custom-fields', 'classic-editor',
	'google-site-kit', 'duplicator', 'backwpup',
);
$corpus_missing = array( 'wp-multibyte-patch', 'siteguard', 'bogo', 'seo-simple-pack', 'xo-security', 'vk-all-in-one-expansion-unit', 'snow-monkey-forms', 'mw-wp-form', 'usces', 'welcart-basic' );
$corpus_themes  = array( 'twentytwentyfour', 'twentytwentyone', 'astra', 'hello-elementor' ); // lightning は未収録扱い

foreach ( $corpus_plugins as $slug ) {
	if ( isset( $GLOBALS['_http_fixtures'][ '/plugin/' . $slug ] ) ) {
		continue;
	}
	$GLOBALS['_http_fixtures'][ '/plugin/' . $slug ] = array(
		'error' => 0,
		'data'  => array( 'slug' => $slug, 'vulnerability' => array() ),
	);
}
foreach ( $corpus_themes as $slug ) {
	$GLOBALS['_http_fixtures'][ '/theme/' . $slug ] = array(
		'error' => 0,
		'data'  => array( 'slug' => $slug, 'vulnerability' => array() ),
	);
}
// $corpus_missing と theme/lightning はフィクスチャを作らない＝404（未収録）。

$GLOBALS['_http_fixtures']['/core/4.7/'] = array(
	'error' => 0,
	'data'  => array( 'vulnerability' => array(
		vuln( 'Core 4.7 XSS', array( 'max_version' => '4.7.1', 'max_operator' => 'lt' ), 6.1 ),
		vuln( 'Core 4.7 SQLi', array( 'max_version' => '4.7.2', 'max_operator' => 'lt' ), 9.8, 89 ),
	) ),
);
$GLOBALS['_http_fixtures']['/core/5.8/'] = array(
	'error' => 0,
	'data'  => array( 'vulnerability' => array( vuln( 'Core 5.8 issue', array( 'max_version' => '5.8.1', 'max_operator' => 'lt' ), 5.3 ) ) ),
);
$GLOBALS['_http_fixtures']['/core/6.7.1/'] = array(
	'error' => 0,
	'data'  => array( 'vulnerability' => array() ),
);

/* ---- 実行 ---- */

echo "== 実証テストの自己検証 ==\n";
$poc = new CNAPI_Poc();
$r   = $poc->run();

echo "\n-- 1. データ形式の確認 --\n";
check( '対象スラッグに全て到達', 8 === $r['shape']['reachable'], '到達=' . $r['shape']['reachable'] );
check( '脆弱性の総数を数えている', $r['shape']['total_vulns'] > 0 );
check( '範囲キーを検出（max_version）', in_array( 'max_version', $r['shape']['range_keys'], true ) );
check( '演算子を収集（lt）', in_array( 'lt', $r['shape']['operators'], true ) );
check( '未知の演算子は無い', array() === $r['shape']['unknown_ops'], implode( ',', $r['shape']['unknown_ops'] ) );

echo "\n-- 2〜4. 実データ突合と相互検証 --\n";
$c = $r['cases'];
check( '検証ケースが作られている', $c['hit_ok'] + $c['hit_ng'] > 0, 'hit=' . ( $c['hit_ok'] + $c['hit_ng'] ) );
check( '影響版を全て検知（見逃し0）', 0 === $c['hit_ng'], '見逃し=' . $c['hit_ng'] );
check( '修正版では全て沈黙（誤検知0）', 0 === $c['clear_ng'], '誤検知=' . $c['clear_ng'] );
check( '相互検証で不一致なし', 0 === $c['cross_diff'], '不一致=' . $c['cross_diff'] );
check( '範囲情報なしを数えて検証対象から外す', 8 === $c['no_range'], 'no_range=' . $c['no_range'] );
check( '検証したコンポーネント数を記録', 8 === $c['components'], (string) $c['components'] );
check( '結果の明細を残している', count( $c['detail'] ) > 0 );

echo "\n-- 5. カバー率と所要時間 --\n";
$cov = $r['coverage'];
check( '要求件数＝プラグイン+テーマ+本体', 39 === $cov['requested'], (string) $cov['requested'] );
check( '未収録を照合済みに数えていない', $cov['matched'] < $cov['requested'], $cov['matched'] . '/' . $cov['requested'] );
check( '未収録の一覧を出す', count( $cov['unknown'] ) === 11, '未収録=' . count( $cov['unknown'] ) );
check( '未収録に日本向けプラグインが含まれる', in_array( 'siteguard', $cov['unknown'], true ) );
check( '収録済みの一覧を出す', in_array( 'akismet', $cov['known'], true ) );
check( '国際/日本を分けて集計', isset( $cov['groups']['international'], $cov['groups']['japan'] ) );
check( '国際グループは全て収録済み', 100.0 === $cov['groups']['international']['rate'], (string) $cov['groups']['international']['rate'] );
check( '日本グループの弱さが数字で出る', 0.0 === $cov['groups']['japan']['rate'], (string) $cov['groups']['japan']['rate'] );
check( '所要時間を記録', is_numeric( $cov['first_sec'] ) && is_numeric( $cov['cached_sec'] ) );

echo "\n-- 6. WordPress本体の扱い --\n";
check( '3バージョンを確認', 3 === count( $r['core'] ) );
$old = $r['core'][0];
check( '4.7 は脆弱性が返る', $old['reachable'] && $old['count'] > 0 );
check( '4.7 は範囲情報も該当している', $old['with_range'] === $old['in_range'], $old['in_range'] . '/' . $old['with_range'] );
check( '最新版は0件', 0 === $r['core'][2]['count'] );

echo "\n-- まとめ --\n";
$s = $r['summary'];
check( '総合判定OK', true === $s['ok'] );
check( '検知率100%', 100.0 === $s['hit_rate'], (string) $s['hit_rate'] );
check( '沈黙率100%', 100.0 === $s['clear_rate'], (string) $s['clear_rate'] );
check( '突合したバージョン数を記録', $s['checked_pairs'] > 0, (string) $s['checked_pairs'] );
check( 'カバー率を算出', is_numeric( $s['coverage_rate'] ), (string) $s['coverage_rate'] );

printf( "\n参考: 検証ペア %d 件 / カバー率 %.1f%% / 実行 %.1f 秒\n", $s['checked_pairs'], $s['coverage_rate'], $r['elapsed'] );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
