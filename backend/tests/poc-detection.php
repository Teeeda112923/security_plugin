<?php
/**
 * CyberNote 検知エンジン 実証テスト（オフライン／精度編）
 *
 * 目的: 「この製品は脆弱性を正しく見つけられるのか」を、通信なしで数字にする。
 *
 * ここで扱うのは擬似データであり、本物の脆弱性データベースは使わない。
 * したがって、このテストが示すのは判定ロジックの正しさであって、
 * 「実在の脆弱性を何件見つけられるか」ではない。実データでの実証は
 * cybernote.click の「設定 > CyberNote API」→「検知の実証テスト」で行う。
 *
 * このスイートが自分自身を疑うためにやっていること:
 *   - 壊れた検知器（何も返さない／全部返す等）を同じ試験にかけ、
 *     ちゃんと落ちることを確認する（対照実験）
 *   - 判定ロジックをわざと壊した「変異体」を多数作り、
 *     何割を検出できるか（変異検出率）を出す
 *   - 検知できなかった件数を必ず併記し、沈黙を成功として数えない
 *
 * 実行: php backend/tests/poc-detection.php
 */

error_reporting( E_ALL );

// ---- WordPressスタブ ----
define( 'ABSPATH', '/tmp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['_transients']    = array();
$GLOBALS['_http_fixtures'] = array();
$GLOBALS['_http_calls']    = array();

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
function wp_remote_get( $url, $args = array() ) {
	$path = parse_url( $url, PHP_URL_PATH );
	$GLOBALS['_http_calls'][] = array( 'path' => $path, 'timeout' => $args['timeout'] ?? null );
	if ( isset( $GLOBALS['_http_fixtures'][ $path ] ) ) {
		$f = $GLOBALS['_http_fixtures'][ $path ];
		return is_string( $f ) ? array( 'code' => 200, 'body' => $f ) : array( 'code' => 200, 'body' => json_encode( $f ) );
	}
	return array( 'code' => 404, 'body' => '' );
}

require __DIR__ . '/../cybernote-api/includes/class-cnapi-matcher.php';

/** 本番の判定メソッドをそのまま呼ぶための窓口（ロジックは上書きしない）。 */
class POC_Matcher extends CNAPI_Matcher {
	public function affected( $version, $operator ) {
		return $this->version_affected( $version, $operator );
	}
}

/* =====================================================================
 * 独立実装（相互検証用）
 *
 * 本番は PHP の version_compare() を使う。ここではそれを使わず、
 * 「数字の区切りごとに比べ、末尾の 0 は無いものとして扱い、
 *   beta などの符号は正式版より前」という規則で自前に比較する。
 *
 * 限界: 同じ設計判断（空文字は指定なし、範囲外は報告しない等）を
 * 共有しているため、設計そのものの誤りは相互検証では見つからない。
 * それを補うのが後段の変異テストと対照実験。
 * ===================================================================== */

function poc_parse_version( $v ) {
	$v = strtolower( trim( (string) $v ) );
	if ( preg_match( '/^v(?=\d)/', $v ) ) {
		$v = substr( $v, 1 );
	}
	if ( ! preg_match( '/^([0-9]+(?:\.[0-9]+)*)[.\-+_ ]?(dev|alpha|beta|rc|pl|a|b|p)?[.\-]?([0-9]*)/', $v, $m ) ) {
		return null; // 数字で始まらない＝比較できない
	}
	$nums = array_map( 'intval', explode( '.', $m[1] ) );
	while ( count( $nums ) > 1 && 0 === end( $nums ) ) {
		array_pop( $nums );
	}
	$ranks = array( 'dev' => -4, 'alpha' => -3, 'a' => -3, 'beta' => -2, 'b' => -2, 'rc' => -1, 'pl' => 1, 'p' => 1 );
	$tag   = $m[2] ?? '';
	return array(
		'nums' => $nums,
		'rank' => ( '' === $tag ) ? 0 : ( $ranks[ $tag ] ?? 0 ),
		'pre'  => (int) ( $m[3] ?? 0 ),
	);
}

function poc_cmp( $a, $b ) {
	$pa = poc_parse_version( $a );
	$pb = poc_parse_version( $b );
	if ( null === $pa || null === $pb ) {
		return null;
	}
	$n = max( count( $pa['nums'] ), count( $pb['nums'] ) );
	for ( $i = 0; $i < $n; $i++ ) {
		$x = $pa['nums'][ $i ] ?? 0;
		$y = $pb['nums'][ $i ] ?? 0;
		if ( $x !== $y ) {
			return ( $x < $y ) ? -1 : 1;
		}
	}
	if ( $pa['rank'] !== $pb['rank'] ) {
		return ( $pa['rank'] < $pb['rank'] ) ? -1 : 1;
	}
	if ( $pa['pre'] !== $pb['pre'] ) {
		return ( $pa['pre'] < $pb['pre'] ) ? -1 : 1;
	}
	return 0;
}

function poc_satisfies( $v, $op, $bound ) {
	$c = poc_cmp( $v, $bound );
	if ( null === $c ) {
		return false;
	}
	switch ( $op ) {
		case 'lt': return $c < 0;
		case 'le': return $c <= 0;
		case 'gt': return $c > 0;
		case 'ge': return $c >= 0;
		case 'eq': return 0 === $c;
	}
	return false;
}

function poc_token( $raw, $default ) {
	$map = array(
		'lt' => 'lt', '<' => 'lt',
		'le' => 'le', 'lte' => 'le', '<=' => 'le',
		'gt' => 'gt', '>' => 'gt',
		'ge' => 'ge', 'gte' => 'ge', '>=' => 'ge',
		'eq' => 'eq', '=' => 'eq', '==' => 'eq',
	);
	$raw = strtolower( trim( (string) $raw ) );
	return $map[ $raw ] ?? $default;
}

function poc_bound( $raw ) {
	if ( null === $raw || is_array( $raw ) ) {
		return null;
	}
	$raw = trim( (string) $raw );
	return ( '' === $raw ) ? null : $raw;
}

function poc_is_true( $v ) {
	if ( is_string( $v ) ) {
		return ! in_array( strtolower( trim( $v ) ), array( '', '0', 'false', 'no', 'null', 'off' ), true );
	}
	return ! empty( $v );
}

/** 影響範囲に入っているかを、本番とは別の書き方で表現する。 */
function poc_affected( $version, $operator ) {
	if ( ! is_array( $operator ) || ! $operator ) {
		return false;
	}
	if ( null === poc_parse_version( $version ) ) {
		return false;
	}
	// 範囲の配列形式（どれか1つに入れば該当）。
	$is_list = true;
	foreach ( $operator as $k => $v ) {
		if ( ! is_int( $k ) || ! is_array( $v ) ) {
			$is_list = false;
			break;
		}
	}
	if ( $is_list ) {
		foreach ( $operator as $range ) {
			if ( poc_affected( $version, $range ) ) {
				return true;
			}
		}
		return false;
	}

	$max = poc_bound( $operator['max_version'] ?? null );
	$min = poc_bound( $operator['min_version'] ?? null );

	$lower_ok = ( null === $min ) || poc_satisfies( $version, poc_token( $operator['min_operator'] ?? 'ge', 'ge' ), $min );
	$upper_ok = ( null === $max )
		? poc_is_true( $operator['unfixed'] ?? null )
		: poc_satisfies( $version, poc_token( $operator['max_operator'] ?? 'le', 'le' ), $max );

	return $lower_ok && $upper_ok;
}

/* =====================================================================
 * 試験ケース
 * ===================================================================== */

/** 範囲内＝検知すべきもの。 */
function poc_positive_cases() {
	return array(
		array( '修正版の1つ前（5.9.4 < 5.9.5未満）', '5.9.4', array( 'max_version' => '5.9.5', 'max_operator' => 'lt' ) ),
		array( 'かなり古い版（1.0 < 5.9.5未満）', '1.0', array( 'max_version' => '5.9.5', 'max_operator' => 'lt' ) ),
		array( '上限ちょうど（5.5 ≦ 5.5以下）', '5.5', array( 'max_version' => '5.5', 'max_operator' => 'le' ) ),
		array( '範囲の下端（5.0 / 5.0〜5.5）', '5.0', array( 'min_version' => '5.0', 'min_operator' => 'ge', 'max_version' => '5.5', 'max_operator' => 'le' ) ),
		array( '範囲の中（5.3 / 5.0〜5.5）', '5.3', array( 'min_version' => '5.0', 'min_operator' => 'ge', 'max_version' => '5.5', 'max_operator' => 'le' ) ),
		array( '範囲の上端（5.5 / 5.0〜5.5）', '5.5', array( 'min_version' => '5.0', 'min_operator' => 'ge', 'max_version' => '5.5', 'max_operator' => 'le' ) ),
		array( '下限が gt（5.0.1 / 5.0超〜5.5未満）', '5.0.1', array( 'min_version' => '5.0', 'min_operator' => 'gt', 'max_version' => '5.5', 'max_operator' => 'lt' ) ),
		array( '桁の多い版（2.5.1.2 < 2.5.2未満）', '2.5.1.2', array( 'max_version' => '2.5.2', 'max_operator' => 'lt' ) ),
		array( '二桁マイナー（1.9 < 1.10未満）', '1.9', array( 'max_version' => '1.10', 'max_operator' => 'lt' ) ),
		array( 'ベータ版（5.9.5-beta1 < 5.9.5未満）', '5.9.5-beta1', array( 'max_version' => '5.9.5', 'max_operator' => 'lt' ) ),
		array( 'RC版（3.0RC1 < 3.0未満）', '3.0RC1', array( 'max_version' => '3.0', 'max_operator' => 'lt' ) ),
		array( '末尾ゼロ表記ゆれ（2.4.0 ≦ 2.4以下）', '2.4.0', array( 'max_version' => '2.4', 'max_operator' => 'le' ) ),
		array( '下限の桁ゆれ（1.2 / 1.2.0以上〜1.5未満）', '1.2', array( 'min_version' => '1.2.0', 'min_operator' => 'ge', 'max_version' => '1.5', 'max_operator' => 'lt' ) ),
		array( 'DB側の桁ゆれ（1.2.3 は 1.2.3.0未満ではない…の逆: 1.2.2）', '1.2.2', array( 'max_version' => '1.2.3.0', 'max_operator' => 'lt' ) ),
		array( '修正版なし・範囲指定なし（全版影響）', '9.9.9', array( 'unfixed' => true ) ),
		array( '修正版なし・下限あり（2.0以降が影響）', '2.4', array( 'unfixed' => true, 'min_version' => '2.0', 'min_operator' => 'ge' ) ),
		array( '完全一致指定（eq 4.7）', '4.7', array( 'max_version' => '4.7', 'max_operator' => 'eq' ) ),
		array( '完全一致指定・末尾ゼロ表記ゆれ（4.7.0 と 4.7）', '4.7.0', array( 'max_version' => '4.7', 'max_operator' => 'eq' ) ),
		array( '未知の表記 gte（下限の向きを取り違えない）', '5.9', array( 'min_version' => '5.0', 'min_operator' => 'gte', 'max_version' => '6.0', 'max_operator' => 'lte' ) ),
		array( '未知の表記 lte（上限は以下として扱う）', '5.9.5', array( 'max_version' => '5.9.5', 'max_operator' => 'lte' ) ),
		array( '解釈できない下限表記（下限は「以上」に倒す）', '5.9', array( 'min_version' => '5.0', 'min_operator' => 'minimum', 'max_version' => '6.0', 'max_operator' => 'le' ) ),
		array( '解釈できない下限表記・範囲の上端', '6.0', array( 'min_version' => '5.0', 'min_operator' => 'at_least', 'max_version' => '6.0', 'max_operator' => 'le' ) ),
		array( '記号表記（<= と >=）', '5.2', array( 'min_version' => '5.0', 'min_operator' => '>=', 'max_version' => '5.5', 'max_operator' => '<=' ) ),
		array( '記号表記（< 単体）', '5.9.4', array( 'max_version' => '5.9.5', 'max_operator' => '<' ) ),
		array( '上限が空文字＋未修正（空文字を指定なしとみなす）', '3.1', array( 'max_version' => '', 'unfixed' => true ) ),
		array( '範囲が配列形式・2つ目に該当', '7.2', array( array( 'max_version' => '1.0', 'max_operator' => 'lt' ), array( 'min_version' => '7.0', 'min_operator' => 'ge', 'max_version' => '7.5', 'max_operator' => 'le' ) ) ),
		array( '範囲が配列形式・1つ目に該当', '0.5', array( array( 'max_version' => '1.0', 'max_operator' => 'lt' ), array( 'min_version' => '7.0', 'min_operator' => 'ge', 'max_version' => '7.5', 'max_operator' => 'le' ) ) ),
	);
}

/** 範囲外＝検知してはいけないもの。 */
function poc_negative_cases() {
	return array(
		array( '修正版ちょうど（5.9.5 は 5.9.5未満に入らない）', '5.9.5', array( 'max_version' => '5.9.5', 'max_operator' => 'lt' ) ),
		array( '修正版より新しい（6.0）', '6.0', array( 'max_version' => '5.9.5', 'max_operator' => 'lt' ) ),
		array( '上限超え（5.5.1 / 5.5以下）', '5.5.1', array( 'max_version' => '5.5', 'max_operator' => 'le' ) ),
		array( '範囲より古い（4.9 / 5.0〜5.5）', '4.9', array( 'min_version' => '5.0', 'min_operator' => 'ge', 'max_version' => '5.5', 'max_operator' => 'le' ) ),
		array( '範囲より新しい（5.6 / 5.0〜5.5）', '5.6', array( 'min_version' => '5.0', 'min_operator' => 'ge', 'max_version' => '5.5', 'max_operator' => 'le' ) ),
		array( '下限が gt のとき下端ちょうどは対象外', '5.0', array( 'min_version' => '5.0', 'min_operator' => 'gt', 'max_version' => '5.5', 'max_operator' => 'lt' ) ),
		array( '二桁マイナー（1.11 は 1.10未満ではない）', '1.11', array( 'max_version' => '1.10', 'max_operator' => 'lt' ) ),
		array( '末尾ゼロの表記ゆれ（1.0 と 1.0.0 は同じ版）', '1.0', array( 'max_version' => '1.0.0', 'max_operator' => 'lt' ) ),
		array( '末尾ゼロの表記ゆれ（2.0 と 2.0.0 は同じ版）', '2.0', array( 'max_version' => '2.0.0', 'max_operator' => 'lt' ) ),
		array( 'DB側の桁ゆれ（1.2.3 と 1.2.3.0 は同じ版）', '1.2.3', array( 'max_version' => '1.2.3.0', 'max_operator' => 'lt' ) ),
		array( 'v付き表記の新しい版（v9.9.9 は 1.0.0未満ではない）', 'v9.9.9', array( 'max_version' => '1.0.0', 'max_operator' => 'lt' ) ),
		array( '未修正だが下限より古い（1.9 / 2.0以降が影響）', '1.9', array( 'unfixed' => true, 'min_version' => '2.0', 'min_operator' => 'ge' ) ),
		array( '完全一致指定の別版（4.8 ≠ 4.7）', '4.8', array( 'max_version' => '4.7', 'max_operator' => 'eq' ) ),
		array( '正式版はベータ用の範囲に入らない', '3.0', array( 'max_version' => '3.0', 'max_operator' => 'lt' ) ),
		array( '未知の表記 gte で下限より古い版は対象外', '4.0', array( 'min_version' => '5.0', 'min_operator' => 'gte', 'max_version' => '9.9', 'max_operator' => 'lte' ) ),
		array( 'unfixed が文字列 "false"（真偽値として偽）', '1.0', array( 'unfixed' => 'false' ) ),
		array( 'unfixed が文字列 "0"', '1.0', array( 'unfixed' => '0' ) ),
		array( '範囲が配列形式・どれにも該当しない', '3.0', array( array( 'max_version' => '1.0', 'max_operator' => 'lt' ), array( 'min_version' => '7.0', 'min_operator' => 'ge', 'max_version' => '7.5', 'max_operator' => 'le' ) ) ),
		array( 'バージョンが trunk（比較できない表記）', 'trunk', array( 'max_version' => '5.9.5', 'max_operator' => 'lt' ) ),
		array( 'バージョンが latest（比較できない表記）', 'latest', array( 'max_version' => '5.9.5', 'max_operator' => 'lt' ) ),
	);
}

/** 判定材料が足りない＝黙るべきもの。 */
function poc_unknown_cases() {
	return array(
		array( '範囲情報が無い', '5.9', null ),
		array( '範囲情報が空', '5.9', array() ),
		array( '下限だけあって上限も未修正表示も無い', '5.9', array( 'min_version' => '5.0', 'min_operator' => 'ge' ) ),
		array( '範囲情報が配列でない', '5.9', 'unknown' ),
		array( 'バージョンが空', '', array( 'max_version' => '5.9.5', 'max_operator' => 'lt' ) ),
	);
}

/** 相互検証グリッド。 */
function poc_grid_versions() {
	return array(
		'0.9', '1.0', '1.0.0', '1.0.1', '1.2', '1.2.0', '1.2.3', '1.2.3.0', '1.9', '1.10', '1.11',
		'2.0', '2.0.0', '2.4', '2.4.0', '2.5.1.2', '3.0', '3.0RC1', '4.7', '4.7.0', '4.8',
		'5.0', '5.3', '5.5', '5.5.1', '5.9', '5.9.4', '5.9.5', '5.9.5-beta1', '6.0',
		'9.9.9', 'v9.9.9', '10.0', '0.1', 'trunk', 'latest',
	);
}

function poc_grid_ranges() {
	return array(
		array( 'max_version' => '5.9.5', 'max_operator' => 'lt' ),
		array( 'max_version' => '5.9.5', 'max_operator' => 'le' ),
		array( 'max_version' => '1.10', 'max_operator' => 'lt' ),
		array( 'max_version' => '1.0.0', 'max_operator' => 'lt' ),
		array( 'max_version' => '2.0.0', 'max_operator' => 'le' ),
		array( 'max_version' => '4.7', 'max_operator' => 'eq' ),
		array( 'max_version' => '3.0', 'max_operator' => 'lt' ),
		array( 'max_version' => '1.2.3.0', 'max_operator' => 'lt' ),
		array( 'max_version' => '2.4', 'max_operator' => 'le' ),
		array( 'min_version' => '5.0', 'min_operator' => 'ge', 'max_version' => '5.5', 'max_operator' => 'le' ),
		array( 'min_version' => '5.0', 'min_operator' => 'gt', 'max_version' => '5.5', 'max_operator' => 'lt' ),
		array( 'min_version' => '1.0', 'min_operator' => 'ge', 'max_version' => '2.0', 'max_operator' => 'lt' ),
		array( 'min_version' => '1.2.0', 'min_operator' => 'ge', 'max_version' => '1.5', 'max_operator' => 'lt' ),
		// 未知・記号表記（既定値の向きを取り違えると必ず食い違う）
		array( 'min_version' => '0.9', 'min_operator' => 'gte', 'max_version' => '6.0', 'max_operator' => 'lte' ),
		array( 'min_version' => '5.0', 'min_operator' => '>=', 'max_version' => '5.5', 'max_operator' => '<=' ),
		array( 'max_version' => '5.9.5', 'max_operator' => '<' ),
		array( 'max_version' => '5.9.5', 'max_operator' => 'less_than' ),
		array( 'min_version' => '5.0', 'min_operator' => 'GE ', 'max_version' => '5.5', 'max_operator' => 'LE' ),
		array( 'min_version' => '5.0', 'min_operator' => 'minimum', 'max_version' => '6.0', 'max_operator' => 'le' ),
		array( 'min_version' => '1.0', 'min_operator' => 'at_least', 'max_version' => '9.0', 'max_operator' => 'maximum' ),
		// 未修正まわり
		array( 'unfixed' => true ),
		array( 'unfixed' => true, 'min_version' => '2.0', 'min_operator' => 'ge' ),
		array( 'unfixed' => true, 'max_version' => '' ),
		array( 'unfixed' => 'false' ),
		array( 'unfixed' => '0' ),
		array( 'unfixed' => true, 'min_version' => '5.0', 'min_operator' => 'gt' ),
		// 判定不能
		array( 'min_version' => '4.7', 'min_operator' => 'ge' ),
		array( 'max_version' => '10.0', 'max_operator' => 'lt' ),
		array( 'max_version' => '5.9.5', 'max_operator' => 'lt', 'min_version' => '5.9', 'min_operator' => 'ge' ),
		// 範囲の配列形式
		array( array( 'max_version' => '1.0', 'max_operator' => 'lt' ), array( 'min_version' => '7.0', 'min_operator' => 'ge', 'max_version' => '7.5', 'max_operator' => 'le' ) ),
	);
}

/* =====================================================================
 * 試験の実行本体（本物にも変異体にも同じものを流す）
 * ===================================================================== */

function poc_fixtures() {
	$vulns = array(
		array(
			'name'     => 'Vuln Demo < 3.2.0 - Unauthenticated SQL Injection',
			'operator' => array( 'max_version' => '3.2.0', 'max_operator' => 'lt' ),
			'impact'   => array( 'cwe' => array( array( 'cwe' => 'CWE-89' ) ), 'cvss' => array( 'score' => 9.8 ) ),
			'source'   => array( array( 'id' => 'CVE-2025-40001', 'link' => 'https://example.org/a' ) ),
		),
		array(
			'name'     => 'Vuln Demo < 3.0.0 - Stored XSS',
			'operator' => array( 'max_version' => '3.0.0', 'max_operator' => 'lt' ),
			'impact'   => array( 'cwe' => array( 'CWE-79' ), 'cvss' => array( 'score' => 5.4 ) ),
			'source'   => array( array( 'id' => 'CVE-2025-40002', 'link' => 'https://example.org/b' ) ),
		),
		array(
			'name'     => 'Vuln Demo - unfixed CSRF',
			'operator' => array( 'unfixed' => true ),
			'impact'   => array( 'cwe' => array( array( 'cwe' => 'CWE-352' ) ) ),
		),
		array(
			'name'   => 'Vuln Demo - range unknown',
			'impact' => array( 'cvss' => array( 'score' => 7.0 ) ),
		),
		// 同じCVE・同じ見出しが範囲違いで二重に載っている（表示は1件にまとめる）
		array(
			'name'     => 'Vuln Demo < 3.2.0 - Unauthenticated SQL Injection',
			'operator' => array( 'min_version' => '2.0', 'min_operator' => 'ge', 'max_version' => '3.1.9', 'max_operator' => 'le' ),
			'impact'   => array( 'cwe' => array( array( 'cwe' => 'CWE-89' ) ), 'cvss' => array( 'score' => 9.8 ) ),
			'source'   => array( array( 'id' => 'CVE-2025-40001', 'link' => 'https://example.org/a' ) ),
		),
	);

	return array(
		'/plugin/vuln-demo'     => array( 'error' => 0, 'data' => array( 'slug' => 'vuln-demo', 'vulnerability' => $vulns ) ),
		// 相手の仕様が変わり、キー名が違う応答（0件＝安全と誤読してはいけない）
		'/plugin/shape-drift'   => array( 'error' => 0, 'data' => array( 'slug' => 'shape-drift', 'vulnerabilities' => array() ) ),
		// JSONですらない応答
		'/plugin/broken-json'   => '<html><body>Service Unavailable</body></html>',
		'/core/6.5.3/'          => array( 'error' => 0, 'data' => array( 'vulnerability' => array() ) ),
	);
}

/**
 * 1つの検知器に対して全ケースを実行し、結果を返す。
 *
 * @param CNAPI_Matcher $m       検知器（本物または変異体）。
 * @param bool          $verbose 明細を表示するか。
 * @return array
 */
function poc_evaluate( $m, $verbose = false ) {
	$GLOBALS['_transients']    = array();
	$GLOBALS['_http_fixtures'] = poc_fixtures();
	$GLOBALS['_http_calls']    = array();

	$res = array( 'total' => 0, 'pass' => 0, 'miss' => 0, 'false_pos' => 0, 'other' => 0, 'failures' => array(), 'cross' => 0, 'cross_diff' => 0 );

	$judge = function ( $label, $expected, $actual, $kind, $note = '' ) use ( &$res, $verbose ) {
		++$res['total'];
		$ok = ( $expected === $actual );
		if ( $ok ) {
			++$res['pass'];
		} else {
			++$res[ $kind ];
			$res['failures'][] = $label;
		}
		if ( $verbose ) {
			printf(
				"  %-4s %-50s 期待:%-8s 結果:%-8s %s\n",
				$ok ? ' ok ' : 'NG！',
				mb_strimwidth( $label, 0, 50, '…', 'UTF-8' ),
				is_bool( $expected ) ? ( $expected ? '該当' : '非該当' ) : (string) $expected,
				is_bool( $actual ) ? ( $actual ? '該当' : '非該当' ) : (string) $actual,
				$note
			);
		}
	};

	if ( $verbose ) {
		poc_head( '1. 影響バージョンの判定 — 危険なものを危険と言えるか' );
	}
	foreach ( poc_positive_cases() as $c ) {
		$judge( $c[0], true, $m->affected( $c[1], $c[2] ), 'miss', 'v=' . $c[1] );
	}

	if ( $verbose ) {
		poc_head( '2. 誤検知の確認 — 直したサイトを「危険」と言わないか' );
	}
	foreach ( poc_negative_cases() as $c ) {
		$judge( $c[0], false, $m->affected( $c[1], $c[2] ), 'false_pos', 'v=' . $c[1] );
	}

	if ( $verbose ) {
		poc_head( '3. 判定できないときの振る舞い — 分からないのに脅かさないか' );
	}
	foreach ( poc_unknown_cases() as $c ) {
		$judge( $c[0], false, $m->affected( $c[1], $c[2] ), 'false_pos', '判定不能→黙る' );
	}

	/* ---- スキャン全体（表示内容・統計） ---- */
	if ( $verbose ) {
		poc_head( '4. スキャン結果の中身 — 出た結果がそのまま行動につながるか' );
	}

	$scan  = $m->scan( array( 'wp_version' => '6.5.3', 'plugins' => array( array( 'slug' => 'vuln-demo', 'version' => '3.1.0', 'name' => 'Vuln Demo' ) ) ) );
	$stats = $m->get_stats();
	$first = $scan[0] ?? array();
	$titles = array_map( static function ( $r ) { return $r['title'] ?? ''; }, $scan );

	$judge( '3.1.0 で検出される件数（XSSは範囲外・重複は1件に集約）', '2', (string) count( $scan ), 'other' );
	$judge( 'SQLインジェクションを検出', true, in_array( 'Vuln Demo < 3.2.0 - Unauthenticated SQL Injection', $titles, true ), 'miss' );
	$judge( '未修正のCSRFを検出', true, in_array( 'Vuln Demo - unfixed CSRF', $titles, true ), 'miss' );
	$judge( '範囲外のXSSは出さない', false, in_array( 'Vuln Demo < 3.0.0 - Stored XSS', $titles, true ), 'false_pos' );
	$judge( '範囲不明の項目は出さない', false, in_array( 'Vuln Demo - range unknown', $titles, true ), 'false_pos' );
	$judge( '重い順に並ぶ（1件目が critical）', 'critical', (string) ( $first['severity'] ?? '' ), 'other' );
	$judge( '修正版を正しく示す', '3.2.0', (string) ( $first['fixed_version'] ?? '' ), 'other' );
	$judge( 'CVE番号を拾う', 'CVE-2025-40001', (string) ( $first['cve_id'] ?? '' ), 'other' );
	$judge( '専門用語を日本語にする', 'SQLインジェクション', (string) ( $first['vuln_type_ja'] ?? '' ), 'other' );
	$judge( '対応手順に更新先が入る', true, false !== strpos( (string) ( $first['action_ja'] ?? '' ), '3.2.0' ), 'other' );
	$judge( '判定できなかった項目を数えている', true, ( $stats['unevaluated'] ?? 0 ) >= 1, 'other' );
	$judge( '照合できた項目数を数えている', '2', (string) ( $stats['components'] ?? 0 ), 'other' );

	$scan_fixed = $m->scan( array( 'plugins' => array( array( 'slug' => 'vuln-demo', 'version' => '3.2.0', 'name' => 'Vuln Demo' ) ) ) );
	$judge( '更新済み(3.2.0)で残るのは未修正の1件だけ', '1', (string) count( $scan_fixed ), 'false_pos' );

	/* ---- 応答が壊れているとき ---- */
	if ( $verbose ) {
		poc_head( '5. 応答が壊れているとき — 「確認できていない」と「安全」を混ぜないか' );
	}

	$m->scan( array( 'plugins' => array( array( 'slug' => 'shape-drift', 'version' => '1.0', 'name' => 'Shape Drift' ) ) ) );
	$s2 = $m->get_stats();
	$judge( 'キー名が変わった応答を「照合済み」に数えない', '0', (string) ( $s2['components'] ?? -1 ), 'other' );
	$judge( 'キー名が変わった応答を未確認として記録', true, ( $s2['unknown'] ?? 0 ) >= 1, 'other' );

	$GLOBALS['_transients'] = array();
	$m->scan( array( 'plugins' => array( array( 'slug' => 'no-such-plugin-xyz', 'version' => '1.0', 'name' => 'X' ) ) ) );
	$s3 = $m->get_stats();
	$judge( '存在しないスラッグは失敗として記録（安全と言わない）', true, ( $s3['failed'] ?? 0 ) >= 1, 'other' );

	$GLOBALS['_transients'] = array();
	$m->scan( array( 'plugins' => array( array( 'slug' => 'broken-json', 'version' => '1.0', 'name' => 'Broken' ) ) ) );
	$s4 = $m->get_stats();
	$judge( 'JSONでない応答も失敗として記録', true, ( $s4['failed'] ?? 0 ) >= 1, 'other' );

	$m->scan( array( 'plugins' => array( array( 'slug' => 'no-version-plugin', 'version' => '', 'name' => 'No Version' ) ) ) );
	$s5 = $m->get_stats();
	$judge( 'バージョン不明のプラグインを未確認として記録', true, ( $s5['skipped'] ?? 0 ) >= 1, 'other' );

	/* ---- 打ち切りと順序 ---- */
	if ( $verbose ) {
		poc_head( '6. 時間切れへの備え — 最重要のWordPress本体を落とさないか' );
	}

	$GLOBALS['_transients'] = array();
	$GLOBALS['_http_calls'] = array();
	$m->scan(
		array(
			'wp_version' => '6.5.3',
			'plugins'    => array(
				array( 'slug' => 'vuln-demo', 'version' => '3.1.0', 'name' => 'A' ),
				array( 'slug' => 'shape-drift', 'version' => '1.0', 'name' => 'B' ),
			),
		)
	);
	$paths = array_column( $GLOBALS['_http_calls'], 'path' );
	$judge( 'WordPress本体を最初に照合する', true, isset( $paths[0] ) && false !== strpos( $paths[0], '/core/' ), 'other' );
	$timeouts = array_filter( array_column( $GLOBALS['_http_calls'], 'timeout' ), 'is_numeric' );
	$judge( '1回の問い合わせ時間が上限内に収まる', true, ! $timeouts || max( $timeouts ) <= 12, 'other' );
	$judge( '問い合わせ時間が下限を割らない', true, ! $timeouts || min( $timeouts ) >= 3, 'other' );

	/* ---- 相互検証 ---- */
	foreach ( poc_grid_versions() as $v ) {
		foreach ( poc_grid_ranges() as $r ) {
			++$res['cross'];
			if ( $m->affected( $v, $r ) !== poc_affected( $v, $r ) ) {
				++$res['cross_diff'];
			}
		}
	}

	return $res;
}

function poc_head( $t ) {
	echo "\n" . str_repeat( '=', 78 ) . "\n " . $t . "\n" . str_repeat( '=', 78 ) . "\n";
}

/* =====================================================================
 * 変異体（わざと壊した検知器）
 *
 * 試験が「壊れていることに気づけるか」を測るために使う。
 * 1つでも試験に落ちれば、その変異体は「検出できた」とみなす。
 * ===================================================================== */

class Mut_Null extends POC_Matcher {          // 何も検知しない
	protected function match_component( $type, $item ) { return array(); }
}
class Mut_All extends POC_Matcher {           // 何でも検知する
	protected function version_affected( $version, $operator ) { return true; }
}
class Mut_NoMin extends POC_Matcher {         // 下限を見ない
	protected function version_affected( $version, $operator ) {
		if ( ! is_array( $operator ) || ! $operator ) { return false; }
		unset( $operator['min_version'], $operator['min_operator'] );
		return parent::version_affected( $version, $operator );
	}
}
class Mut_SwapLtLe extends POC_Matcher {      // 未満と以下を取り違える
	protected function normalize_operator( $op, $default = '<=' ) {
		$r = parent::normalize_operator( $op, $default );
		return ( '<' === $r ) ? '<=' : ( ( '<=' === $r ) ? '<' : $r );
	}
}
class Mut_BadFallback extends POC_Matcher {   // 未知の表記の既定値を壊す
	protected function normalize_operator( $op, $default = '<=' ) {
		return parent::normalize_operator( $op, '<' );
	}
}
class Mut_MinFallback extends POC_Matcher {   // 下限の既定値を上限向きにする（元の欠陥）
	protected function normalize_operator( $op, $default = '<=' ) {
		return parent::normalize_operator( $op, '<=' );
	}
}
class Mut_NoPad extends POC_Matcher {         // 桁合わせをやめる（元の欠陥）
	protected function compare_versions( $version, $bound, $op ) {
		return version_compare( $version, $bound, $op );
	}
}
class Mut_EmptyBound extends POC_Matcher {    // 空文字を範囲として扱う（元の欠陥）
	protected function range_bound( $value ) {
		return ( null === $value || is_array( $value ) ) ? null : (string) $value;
	}
}
class Mut_LooseTrue extends POC_Matcher {     // 文字列 "false" を真とみなす（元の欠陥）
	protected function is_true( $value ) { return ! empty( $value ); }
}
class Mut_NoList extends POC_Matcher {        // 範囲の配列形式を取り落とす（元の欠陥）
	protected function is_range_list( $operator ) { return false; }
}
class Mut_NoTextGuard extends POC_Matcher {   // trunk 等の表記を弾かない（元の欠陥）
	protected function version_affected( $version, $operator ) {
		if ( ! is_array( $operator ) || ! $operator ) { return false; }
		if ( ! preg_match( '/^[vV]?\d/', trim( (string) $version ) ) ) {
			return version_compare( $version, $operator['max_version'] ?? '0', '<' );
		}
		return parent::version_affected( $version, $operator );
	}
}
class Mut_ShapeBlind extends POC_Matcher {    // 形が違う応答を「0件＝安全」と読む（元の欠陥）
	protected function match_component( $type, $item ) {
		$r = parent::match_component( $type, $item );
		$this->unknown = 0;
		return $r;
	}
}
class Mut_CoreLast extends POC_Matcher {      // 本体を最後に回す（元の順序）
	public function scan( $env ) {
		$core = $env['wp_version'] ?? '';
		unset( $env['wp_version'] );
		$found = parent::scan( $env );
		if ( '' !== $core ) {
			$env2 = array( 'wp_version' => $core );
			$found = array_merge( $found, parent::scan( $env2 ) );
		}
		return $found;
	}
}

/* =====================================================================
 * 実行
 * ===================================================================== */

echo "CyberNote 検知エンジン 実証テスト（オフライン／擬似データ）\n";
echo "本物の脆弱性データベースは使いません。判定ロジックの正しさだけを測ります。\n";

$real = poc_evaluate( new POC_Matcher(), true );

poc_head( '7. 相互検証 — 別実装と総当たりで突き合わせる' );
printf( "  %d 通り（%d バージョン × %d 範囲）を突き合わせ\n", $real['cross'], count( poc_grid_versions() ), count( poc_grid_ranges() ) );
printf( "  %s 結論が食い違った組み合わせ: %d 件\n", $real['cross_diff'] ? 'NG！' : ' ok ', $real['cross_diff'] );
echo "  ※ 別実装とは設計判断を共有しているため、これだけでは設計そのものの誤りは見つかりません。\n";

/* ---- 対照実験 ---- */
poc_head( '8. 対照実験 — この試験は壊れた検知器を見抜けるか' );
$controls = array(
	'何も検知しない検知器' => new Mut_Null(),
	'何でも検知する検知器' => new Mut_All(),
);
foreach ( $controls as $label => $ctl ) {
	$r = poc_evaluate( $ctl );
	printf(
		"  %-24s 正解 %2d/%d（%.1f%%） / 相互検証の食い違い %d 件 → %s\n",
		$label,
		$r['pass'],
		$r['total'],
		100 * $r['pass'] / $r['total'],
		$r['cross_diff'],
		( $r['failures'] || $r['cross_diff'] ) ? '見抜けた' : '見抜けなかった'
	);
}

/* ---- 変異テスト ---- */
poc_head( '9. 変異テスト — 判定ロジックをわざと壊して、気づけるかを測る' );
$mutants = array(
	'下限を見ない'                   => new Mut_NoMin(),
	'未満と以下を取り違える'         => new Mut_SwapLtLe(),
	'未知の表記の既定値が違う'       => new Mut_BadFallback(),
	'下限の既定値が上限向き'         => new Mut_MinFallback(),
	'桁合わせをしない'               => new Mut_NoPad(),
	'空文字を範囲として扱う'         => new Mut_EmptyBound(),
	'文字列 "false" を真とみなす'    => new Mut_LooseTrue(),
	'範囲の配列形式を取り落とす'     => new Mut_NoList(),
	'trunk等の表記を弾かない'        => new Mut_NoTextGuard(),
	'形の違う応答を安全と読む'       => new Mut_ShapeBlind(),
	'本体を最後に照合する'           => new Mut_CoreLast(),
);
$killed = 0;
foreach ( $mutants as $label => $mut ) {
	$r  = poc_evaluate( $mut );
	$ok = ( $r['failures'] || $r['cross_diff'] );
	if ( $ok ) {
		++$killed;
	}
	printf(
		"  %-4s %-30s 落ちた項目 %2d 件 / 相互検証の食い違い %d 件\n",
		$ok ? ' ok ' : 'NG！',
		mb_strimwidth( $label, 0, 30, '…', 'UTF-8' ),
		count( $r['failures'] ),
		$r['cross_diff']
	);
	if ( ! $ok ) {
		echo "       ※ この壊れ方を検出できていません。ケースの追加が必要です。\n";
	}
}
$kill_rate = 100 * $killed / count( $mutants );
printf( "\n  変異検出率: %d/%d（%.1f%%）\n", $killed, count( $mutants ), $kill_rate );

/* ---- まとめ ---- */
poc_head( 'まとめ' );
printf( "  判定ケース                  : %d 件（すべて擬似データ）\n", $real['total'] );
printf( "  正解                        : %d 件（%.1f%%）\n", $real['pass'], 100 * $real['pass'] / $real['total'] );
printf( "  見逃し（危険を見落とし）    : %d 件\n", $real['miss'] );
printf( "  誤検知（安全を危険と誤判定）: %d 件\n", $real['false_pos'] );
printf( "  その他の不一致              : %d 件\n", $real['other'] );
printf( "  相互検証                    : %d 通り中 %d 件が不一致\n", $real['cross'], $real['cross_diff'] );
printf( "  変異検出率                  : %.1f%%（%d/%d）\n", $kill_rate, $killed, count( $mutants ) );

if ( $real['failures'] ) {
	echo "\n  未達のケース:\n";
	foreach ( $real['failures'] as $f ) {
		echo "   - $f\n";
	}
}

$failed = count( $real['failures'] ) + $real['cross_diff'] + ( count( $mutants ) - $killed );
echo "\n" . ( $failed ? "結果: {$failed} 件が未達\n" : "結果: 全項目クリア\n" );
echo "\nこの結果で言えること: 影響バージョンの判定ロジックが、想定した入力に対して正しく動くこと。\n";
echo "言えないこと      : 実在の脆弱性を何件見つけられるか、脆弱性データベースの中身が正しいか。\n";
echo "                    実データでの実証は cybernote.click の「設定 > CyberNote API」→\n";
echo "                    「検知の実証テスト」から実行してください。\n";

exit( $failed ? 1 : 0 );
