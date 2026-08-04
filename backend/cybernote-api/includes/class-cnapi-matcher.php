<?php
/**
 * 突合エンジン: WPVulnerability.com の無料APIと照合し、日本語の脆弱性リストを作る。
 *
 * データ源は差し替え可能にするため、外部APIへの問い合わせは fetch_json() に集約し、
 * レスポンスの形ゆれ（フィールド欠落）に耐える防御的なパースを行う。
 * 同一スラッグの結果は24時間キャッシュし、スキャンのたびに外部DBを叩かない。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CNAPI_Matcher {

	const API_BASE        = 'https://www.wpvulnerability.net'; // 注意: APIは .net（.com は説明サイトで404になる）。
	const CACHE_TTL       = DAY_IN_SECONDS;      // この時間内は再問い合わせしない（新鮮）。
	const STALE_TTL       = 7 * DAY_IN_SECONDS;  // DB不調時は最大この期間、前回の正常データで照合を続行。
	// WAF/ボット判定に弾かれにくい標準的なWordPress形式のUA。
	const USER_AGENT      = 'WordPress/6.5; https://www.cybernote.click/';
	const REQUEST_TIMEOUT = 12;  // 1問い合わせあたりの上限秒（相手DBの一時的な遅延に耐える）。
	const TIME_BUDGET     = 40;  // スキャン全体の外部問い合わせ予算秒（接続側の60秒より短く）。

	/** @var int このスキャンで実際に外部へ問い合わせた回数（キャッシュ命中は除く）。 */
	protected $checked = 0;
	/** @var int うち失敗（到達不可・エラー）した回数。 */
	protected $failed = 0;
	/** @var bool 時間予算切れで一部の照合を打ち切ったか。 */
	protected $aborted = false;
	/** @var bool 相手DB不調のため、期限内の前回データで照合した項目があるか。 */
	protected $used_stale = false;
	/** @var int 実際に照合したコンポーネント数（プラグイン＋テーマ＋本体）。 */
	protected $components = 0;
	/** @var int スラッグ・バージョンが取れず照合できなかったコンポーネント数。 */
	protected $skipped = 0;
	/** @var int 応答は返ったが想定した形でなく、照合できなかったコンポーネント数。 */
	protected $unknown = 0;
	/** @var int 影響範囲が読み取れず判定を見送った脆弱性の件数。 */
	protected $unevaluated = 0;
	/** @var int 時間切れで照合を見送ったコンポーネント数。 */
	protected $aborted_count = 0;
	/** @var int 予算の締め切り時刻（Unix秒）。 */
	protected $deadline = 0;

	/**
	 * 直近スキャンの実行統計。照合が本当に効いたかの判断に使う。
	 *
	 * @return array { checked, failed, aborted }
	 */
	public function get_stats() {
		return array(
			'checked'    => $this->checked,
			'failed'     => $this->failed,
			'aborted'    => $this->aborted,
			'used_stale' => $this->used_stale,
			'components'  => $this->components,
			'skipped'     => $this->skipped,
			'unknown'     => $this->unknown,
			'unevaluated' => $this->unevaluated,
			'aborted_count' => $this->aborted_count,
		);
	}

	/**
	 * 単発の接続テスト（キャッシュを使わず脆弱性DBへ1回だけ問い合わせる）。
	 * cybernote.click から WPVulnerability へ到達できているかの確認用。
	 *
	 * @param string $slug 例: contact-form-7.
	 * @return array { ok, http, error, vuln_count }
	 */
	public function probe( $slug = 'contact-form-7' ) {
		$slug = sanitize_key( $slug );
		// plugin は末尾スラッシュ無しが正（有りは相手サーバーが500を返す）。
		$url  = self::API_BASE . '/plugin/' . rawurlencode( $slug );
		$args = array(
			'timeout'    => self::REQUEST_TIMEOUT,
			'user-agent' => self::USER_AGENT,
			'headers'    => array( 'Accept' => 'application/json' ),
		);

		$response = wp_remote_get( $url, $args );
		// 失敗したらスラッシュ有りでも試す（相手の仕様変更に備える）。
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$response = wp_remote_get( $url . '/', $args );
		}

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'         => false,
				'http'       => 0,
				'error'      => $response->get_error_message(),
				'vuln_count' => 0,
			);
		}

		$code  = (int) wp_remote_retrieve_response_code( $response );
		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$vulns = is_array( $body ) ? ( $body['data']['vulnerability'] ?? array() ) : array();

		return array(
			'ok'         => ( 200 === $code && is_array( $vulns ) ),
			'http'       => $code,
			'error'      => ( 200 === $code ) ? '' : 'HTTP ' . $code,
			'vuln_count' => is_array( $vulns ) ? count( $vulns ) : 0,
		);
	}

	/**
	 * 環境情報一式を突合し、レスポンス用の脆弱性リストを返す。
	 *
	 * @param array $env { wp_version, php_version, plugins:[{slug,version,name}], themes:[{slug,version,name}] }
	 * @return array vulnerabilities配列（scanレスポンスのvulnerabilities要素）.
	 */
	public function scan( $env ) {
		$this->checked    = 0;
		$this->failed     = 0;
		$this->aborted    = false;
		$this->used_stale = false;
		$this->components   = 0;
		$this->skipped      = 0;
		$this->unknown      = 0;
		$this->unevaluated  = 0;
		$this->aborted_count = 0;
		$this->deadline     = time() + self::TIME_BUDGET;

		$found = array();

		// 本体を最初に照合する。時間切れで打ち切られたとき、
		// 最も影響の大きいWordPress本体が落ちるのを避けるため。
		if ( ! empty( $env['wp_version'] ) ) {
			$found = array_merge(
				$found,
				$this->match_component(
					'core',
					array(
						'slug'    => 'wordpress',
						'name'    => 'WordPress本体',
						'version' => $env['wp_version'],
					)
				)
			);
		}

		foreach ( (array) ( $env['plugins'] ?? array() ) as $item ) {
			$found = array_merge( $found, $this->match_component( 'plugin', $item ) );
		}
		foreach ( (array) ( $env['themes'] ?? array() ) as $item ) {
			$found = array_merge( $found, $this->match_component( 'theme', $item ) );
		}

		$found = $this->deduplicate( $found );

		// 深刻度の高い順に並べる。
		usort(
			$found,
			static function ( $a, $b ) {
				$order = array( 'critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'unknown' => 4 );
				return ( $order[ $a['severity'] ] ?? 9 ) <=> ( $order[ $b['severity'] ] ?? 9 );
			}
		);

		return $found;
	}

	/**
	 * 同じ脆弱性が複数の範囲レコードに分かれている場合に、表示を1件にまとめる。
	 *
	 * 利用者から見れば同じ1つの問題なので、まったく同じものを二度並べない。
	 * ただしCVE番号だけで束ねると別の問題を隠しかねないため、見出しも鍵に含める。
	 *
	 * @param array $found Vulnerability rows.
	 * @return array
	 */
	protected function deduplicate( $found ) {
		$seen   = array();
		$unique = array();
		foreach ( $found as $row ) {
			$key = $row['type'] . '|' . $row['slug'] . '|' . $row['cve_id'] . '|' . $row['title'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$unique[]     = $row;
		}
		return $unique;
	}

	/**
	 * 1コンポーネント（プラグイン/テーマ/本体）の突合。
	 *
	 * @param string $type plugin|theme|core.
	 * @param array  $item {slug, version, name}.
	 * @return array
	 */
	protected function match_component( $type, $item ) {
		$slug    = sanitize_key( $item['slug'] ?? '' );
		$version = trim( (string) ( $item['version'] ?? '' ) );
		$name    = trim( (string) ( $item['name'] ?? $slug ) );

		if ( '' === $slug || '' === $version ) {
			// バージョン表記が無いプラグイン（自社開発・受託の独自プラグイン等）は照合できない。
			// 黙って飛ばすと「全部見た」ように見えてしまうため、件数だけ記録しておく。
			++$this->skipped;
			return array();
		}

		// 時間予算を超えたら以降の外部問い合わせは打ち切る（接続側のタイムアウト回避）。
		if ( time() > $this->deadline ) {
			$this->aborted = true;
			// 打ち切った件数も数える。合計が合わないと「何件中何件」を正しく言えない。
			++$this->aborted_count;
			return array();
		}

		if ( 'core' === $type ) {
			$data = $this->fetch_json( '/core/' . rawurlencode( $version ) . '/' );
		} else {
			// plugin/theme は末尾スラッシュ付きだと相手サーバーが500を返すため付けない。
			$data = $this->fetch_json( '/' . $type . '/' . rawurlencode( $slug ) );
		}
		if ( empty( $data ) || ! is_array( $data ) ) {
			return array();
		}

		// 想定した形（data.vulnerability が配列）で返ってこない場合は照合できていない。
		// ここを「0件＝安全」と解釈すると、相手の仕様変更や未収録を安全と誤って伝えてしまう。
		$vulns = ( isset( $data['data'] ) && is_array( $data['data'] ) && isset( $data['data']['vulnerability'] ) )
			? $data['data']['vulnerability']
			: null;
		if ( ! is_array( $vulns ) ) {
			++$this->unknown;
			return array();
		}

		// ここまで来た＝この項目は脆弱性DBと照合できた。
		++$this->components;

		$results = array();
		foreach ( $vulns as $vuln ) {
			if ( ! is_array( $vuln ) ) {
				continue;
			}
			// coreエンドポイントはバージョン指定で問い合わせるため常に該当扱い。
			if ( 'core' !== $type ) {
				$operator = $vuln['operator'] ?? null;
				// 影響範囲が読み取れないものは報告しない。件数だけ記録して見えなくしない。
				if ( ! is_array( $operator ) || ! $operator ) {
					++$this->unevaluated;
					continue;
				}
				if ( ! $this->version_affected( $version, $operator ) ) {
					continue;
				}
			}
			$results[] = $this->format_vulnerability( $type, $slug, $name, $version, $vuln );
		}
		return $results;
	}

	/**
	 * インストール中のバージョンが影響範囲に入っているか。
	 *
	 * operator例: { min_version, min_operator, max_version, max_operator, unfixed }
	 * 範囲情報が全く無い場合は判定できないため「該当しない」に倒す（誤報を避ける）。
	 *
	 * @param string     $version  Installed version.
	 * @param array|null $operator Operator info.
	 * @return bool
	 */
	protected function version_affected( $version, $operator ) {
		if ( empty( $operator ) || ! is_array( $operator ) ) {
			return false;
		}

		$version = trim( (string) $version );
		// 数字で始まらない表記（'trunk' 'latest' 'dev' など）は比較しても意味が無い。
		// PHPの比較では最小値扱いになり、あらゆる「◯◯未満が影響」に当たってしまうため、
		// 判定不能として報告しない（推測で脅かさない）。
		if ( '' === $version || ! preg_match( '/^[vV]?\d/', $version ) ) {
			return false;
		}

		// 影響範囲が複数並んでいる形式（範囲の配列）にも耐える。どれか1つに入れば該当。
		if ( $this->is_range_list( $operator ) ) {
			foreach ( $operator as $range ) {
				if ( $this->version_affected( $version, $range ) ) {
					return true;
				}
			}
			return false;
		}

		// 空文字は「指定なし」と同じに扱う（'' のまま比較すると判定が壊れるため）。
		$max = $this->range_bound( $operator['max_version'] ?? null );
		$min = $this->range_bound( $operator['min_version'] ?? null );
		// 未知の表記に落ちたときの既定値は、上限・下限それぞれの向きに合わせる。
		// 上限に '>=' 等を当ててしまうと範囲が反転し、検知漏れ・誤検知の原因になる。
		$max_op = $this->normalize_operator( $operator['max_operator'] ?? 'le', '<=' );
		$min_op = $this->normalize_operator( $operator['min_operator'] ?? 'ge', '>=' );

		// 修正版が存在せず範囲上限も無い＝全バージョン影響。
		if ( null === $max && $this->is_true( $operator['unfixed'] ?? null ) ) {
			return null === $min || $this->compare_versions( $version, $min, $min_op );
		}
		if ( null === $max ) {
			return false;
		}
		if ( ! $this->compare_versions( $version, $max, $max_op ) ) {
			return false;
		}
		return null === $min || $this->compare_versions( $version, $min, $min_op );
	}

	/**
	 * 影響範囲が「範囲の配列」形式かどうか。
	 *
	 * 1件の脆弱性に複数の影響範囲が並ぶ形（0,1,2… の連番キーで範囲が入る）を、
	 * 単一の範囲オブジェクトと取り違えないための判定。
	 *
	 * @param array $operator Operator info.
	 * @return bool
	 */
	protected function is_range_list( $operator ) {
		if ( ! is_array( $operator ) || ! $operator ) {
			return false;
		}
		foreach ( $operator as $key => $value ) {
			if ( ! is_int( $key ) || ! is_array( $value ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * JSON由来の真偽値を判定する。
	 *
	 * 文字列の "false" や "0" はPHPでは真になってしまい、
	 * 「修正版なし＝全バージョン影響」と誤解して全利用者に警告を出す事故につながる。
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	protected function is_true( $value ) {
		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			return ! in_array( $value, array( '', '0', 'false', 'no', 'null', 'off' ), true );
		}
		return ! empty( $value );
	}

	/**
	 * 範囲の端点を正規化する。空文字・空白のみは「指定なし」(null) とみなす。
	 *
	 * @param mixed $value Raw bound.
	 * @return string|null
	 */
	protected function range_bound( $value ) {
		if ( null === $value || is_array( $value ) ) {
			return null;
		}
		$value = trim( (string) $value );
		return ( '' === $value ) ? null : $value;
	}

	/**
	 * 2つのバージョンを比較する。表記ゆれを吸収してから version_compare() に渡す。
	 *
	 * PHPの version_compare() をそのまま使うと、次の誤判定が起きる。
	 *   - 'v9.9.9' は先頭の v が数字より小さいと解釈され、1.0.0 未満と判定される（誤検知）
	 *   - '1.0' と '1.0.0' が別物になり、修正済みなのに影響ありと判定される（誤検知）
	 *   - '6.0' と '6.0.0' が一致しないため、完全一致指定の脆弱性を取りこぼす（見逃し）
	 * 先頭の v を外し、数字部分の桁数を両側で揃えることで、
	 * 'beta' 等の符号を壊さずに表記ゆれだけを吸収する。
	 *
	 * @param string $version Installed version.
	 * @param string $bound   Range bound.
	 * @param string $op      version_compare() operator.
	 * @return bool
	 */
	protected function compare_versions( $version, $bound, $op ) {
		list( $a_nums, $a_tail ) = $this->split_version( $version );
		list( $b_nums, $b_tail ) = $this->split_version( $bound );

		// '1.0' と '1.0.0' を比べられるよう、短い方を 0 で埋めて桁数を合わせる。
		$depth = max( count( $a_nums ), count( $b_nums ) );
		$a_nums = array_pad( $a_nums, $depth, '0' );
		$b_nums = array_pad( $b_nums, $depth, '0' );

		return version_compare( implode( '.', $a_nums ) . $a_tail, implode( '.', $b_nums ) . $b_tail, $op );
	}

	/**
	 * バージョン文字列を「数字の並び」と「それ以降（beta1 等）」に分ける。
	 *
	 * 例: '5.9.5-beta1' → [ ['5','9','5'], '-beta1' ] / '3.0RC1' → [ ['3','0'], 'RC1' ]
	 *
	 * @param mixed $version Raw version string.
	 * @return array [ 数字部分の配列, 残りの文字列 ]
	 */
	protected function split_version( $version ) {
		$version = trim( (string) $version );
		// 'v1.2.3' → '1.2.3'（v の直後が数字のときだけ外す）。
		if ( preg_match( '/^[vV](?=\d)/', $version ) ) {
			$version = substr( $version, 1 );
		}
		if ( preg_match( '/^(\d+(?:\.\d+)*)(.*)$/', $version, $m ) ) {
			return array( explode( '.', $m[1] ), $m[2] );
		}
		// 数字で始まらない想定外の表記は、桁合わせをせずそのまま比較に回す。
		return array( array( $version ), '' );
	}

	/**
	 * APIのoperator表記をversion_compare()の演算子へ。
	 *
	 * @param string $op      Operator token.
	 * @param string $default 未知の表記だったときの既定値（上限は '<='、下限は '>='）。
	 * @return string
	 */
	protected function normalize_operator( $op, $default = '<=' ) {
		$map = array(
			'lt'  => '<',
			'le'  => '<=',
			'lte' => '<=',
			'gt'  => '>',
			'ge'  => '>=',
			'gte' => '>=',
			'eq'  => '==',
			'=='  => '==',
			'='   => '==',
			'<'   => '<',
			'<='  => '<=',
			'>'   => '>',
			'>='  => '>=',
		);
		$op  = strtolower( trim( (string) $op ) );
		return $map[ $op ] ?? $default;
	}

	/**
	 * 1件の脆弱性をレスポンス形式（日本語込み）へ整形。
	 *
	 * @param string $type    plugin|theme|core.
	 * @param string $slug    Component slug.
	 * @param string $name    Display name.
	 * @param string $version Installed version.
	 * @param array  $vuln    Raw vulnerability entry.
	 * @return array
	 */
	protected function format_vulnerability( $type, $slug, $name, $version, $vuln ) {
		$operator = is_array( $vuln['operator'] ?? null ) ? $vuln['operator'] : array();

		// 修正版: 「x.y.z未満が影響」なら x.y.z が修正版。それ以外は不明。
		$fixed = null;
		if ( ! empty( $operator['max_version'] ) && 'lt' === strtolower( (string) ( $operator['max_operator'] ?? '' ) ) ) {
			$fixed = (string) $operator['max_version'];
		}
		$unfixed = $this->is_true( $operator['unfixed'] ?? null );

		list( $severity, $score ) = $this->extract_severity( $vuln );
		list( $type_slug, $type_ja, $desc_ja ) = $this->classify_cwe( $vuln );

		$sources = array();
		$cve_id  = '';
		foreach ( (array) ( $vuln['source'] ?? array() ) as $src ) {
			if ( ! is_array( $src ) ) {
				continue;
			}
			$id = (string) ( $src['id'] ?? '' );
			if ( '' === $cve_id && 0 === stripos( $id, 'CVE-' ) ) {
				$cve_id = strtoupper( $id );
			}
			if ( ! empty( $src['link'] ) ) {
				$sources[] = esc_url_raw( (string) $src['link'] );
			}
		}

		if ( $unfixed ) {
			$action_ja = 'まだ修正版が公開されていません。開発者の対応が出るまで、一時的な停止・削除も含めて検討してください。';
		} elseif ( $fixed ) {
			$action_ja = sprintf( '管理画面の「更新」から %1$s を %2$s 以上に更新してください。', $name, $fixed );
		} else {
			$action_ja = sprintf( '管理画面の「更新」から %s を最新版に更新してください。', $name );
		}

		return array(
			'type'              => $type,
			'slug'              => $slug,
			'name'              => $name,
			'installed_version' => $version,
			'fixed_version'     => $fixed,
			'unfixed'           => $unfixed,
			'severity'          => $severity,
			'cvss_score'        => $score,
			'vuln_type'         => $type_slug,
			'vuln_type_ja'      => $type_ja,
			'title'             => (string) ( $vuln['name'] ?? '' ),
			'description_ja'    => $desc_ja,
			'action_ja'         => $action_ja,
			'cve_id'            => $cve_id,
			'cybernote_url'     => $this->find_local_article( $cve_id ),
			'references'        => array_slice( array_values( array_unique( $sources ) ), 0, 3 ),
		);
	}

	/**
	 * 自サイト（cybernote.click）に、このCVEの日本語解説記事があればそのURLを返す。
	 *
	 * 記事のスラッグが cve-xxxx-yyyy... で始まる前提で照合する。
	 * APIは cybernote.click 上で動くため、自分の投稿を直接検索できる。
	 *
	 * @param string $cve_id 例: CVE-2026-7467.
	 * @return string 記事URL。無ければ空文字。
	 */
	protected function find_local_article( $cve_id ) {
		global $wpdb;
		if ( '' === $cve_id || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return '';
		}

		$like = $wpdb->esc_like( strtolower( $cve_id ) ) . '%'; // 例: cve-2026-7467%
		$id   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_status = 'publish' AND post_type = 'post' AND post_name LIKE %s
				 ORDER BY post_date DESC LIMIT 1",
				$like
			)
		);

		return $id ? (string) get_permalink( (int) $id ) : '';
	}

	/**
	 * CVSSスコアから深刻度を決める。スコアが無ければ unknown。
	 *
	 * @param array $vuln Raw entry.
	 * @return array [severity, score|null]
	 */
	protected function extract_severity( $vuln ) {
		$score = null;

		$impact = $vuln['impact'] ?? array();
		if ( isset( $impact['cvss']['score'] ) && is_numeric( $impact['cvss']['score'] ) ) {
			$score = (float) $impact['cvss']['score'];
		} elseif ( isset( $vuln['score'] ) && is_numeric( $vuln['score'] ) ) {
			$score = (float) $vuln['score'];
		}

		if ( null === $score ) {
			return array( 'unknown', null );
		}
		if ( $score >= 9.0 ) {
			return array( 'critical', $score );
		}
		if ( $score >= 7.0 ) {
			return array( 'high', $score );
		}
		if ( $score >= 4.0 ) {
			return array( 'medium', $score );
		}
		return array( 'low', $score );
	}

	/**
	 * CWE番号から日本語の分類名と平易な説明を引く。
	 *
	 * @param array $vuln Raw entry.
	 * @return array [type_slug, type_ja, description_ja]
	 */
	protected function classify_cwe( $vuln ) {
		$table = array(
			79  => array( 'xss', 'クロスサイトスクリプティング（XSS）', '入力値の処理に不備があり、閲覧者の画面で悪意のあるスクリプトが実行される可能性があります。' ),
			89  => array( 'sqli', 'SQLインジェクション', 'データベースへの命令文に不正な値を混ぜ込まれ、情報の抜き取りや改ざんにつながる可能性があります。' ),
			352 => array( 'csrf', 'クロスサイトリクエストフォージェリ（CSRF）', 'ログイン中の管理者に気づかれないまま、意図しない操作を実行させられる可能性があります。' ),
			22  => array( 'traversal', 'パストラバーサル', 'サーバー内の本来見えないはずのファイルを読み取られる可能性があります。' ),
			434 => array( 'upload', '危険なファイルのアップロード', '不正なプログラムファイルをアップロードされ、サイトを乗っ取られる可能性があります。' ),
			862 => array( 'authz', 'アクセス制御の不備', '権限のない利用者が、本来できないはずの操作を実行できてしまう可能性があります。' ),
			863 => array( 'authz', 'アクセス制御の不備', '権限のない利用者が、本来できないはずの操作を実行できてしまう可能性があります。' ),
			200 => array( 'disclosure', '情報漏えい', '本来公開されないはずの情報が外部から読み取れてしまう可能性があります。' ),
			502 => array( 'object-injection', 'オブジェクトインジェクション', '不正なデータを流し込まれ、サイト内で任意の処理を実行される可能性があります。' ),
			918 => array( 'ssrf', 'サーバーリクエスト強制（SSRF）', 'サーバーを踏み台にして、内部システムへのアクセスを強制される可能性があります。' ),
		);

		foreach ( (array) ( $vuln['impact']['cwe'] ?? array() ) as $cwe ) {
			$num = null;
			if ( is_array( $cwe ) && isset( $cwe['cwe'] ) ) {
				$num = (int) preg_replace( '/\D/', '', (string) $cwe['cwe'] );
			} elseif ( is_scalar( $cwe ) ) {
				$num = (int) preg_replace( '/\D/', '', (string) $cwe );
			}
			if ( $num && isset( $table[ $num ] ) ) {
				return $table[ $num ];
			}
		}
		return array( 'other', 'セキュリティ上の問題', '既知のセキュリティ上の問題が報告されています。詳細は参考リンクをご確認ください。' );
	}

	/**
	 * 外部APIへの問い合わせ（24hキャッシュ付き）。
	 *
	 * @param string $path 例: /plugin/contact-form-7/
	 * @return array|null デコード済みJSON。失敗時はnull。
	 */
	protected function fetch_json( $path ) {
		$cache_key = 'cnapi_vdb_' . md5( $path );
		$cached    = get_transient( $cache_key );

		// 新鮮なキャッシュがあればそのまま使う（外部問い合わせなし）。
		if ( is_array( $cached ) && isset( $cached['ts'], $cached['body'] )
			&& ( time() - (int) $cached['ts'] ) < self::CACHE_TTL ) {
			return is_array( $cached['body'] ) ? $cached['body'] : null;
		}

		++$this->checked;
		$body = $this->request( $path, $this->remaining_timeout() );

		// 失敗したら末尾スラッシュの有無を反転して1度だけ再試行する。
		// 相手のルーティングはエンドポイントごとに許容する形式が異なり、将来変わる可能性もある。
		if ( null === $body ) {
			$body = $this->request( $this->toggle_trailing_slash( $path ), $this->remaining_timeout() );
		}

		if ( is_array( $body ) ) {
			set_transient( $cache_key, array( 'ts' => time(), 'body' => $body ), self::STALE_TTL );
			return $body;
		}

		// 取得できなかった場合、期限内の「前回の正常データ」があればそれで照合を続ける。
		// これにより相手DBの一時的な不調でスキャン全体が不完全にならない。
		if ( is_array( $cached ) && isset( $cached['body'] ) && is_array( $cached['body'] ) ) {
			$this->used_stale = true;
			return $cached['body'];
		}

		// 前回データも無い＝この項目は本当に照合できていない。
		++$this->failed;
		return null;
	}

	/**
	 * 詳細診断: 複数のURL形式を実際に試し、生の応答を記録して返す。
	 *
	 * 500などで失敗が続くとき、相手が何を返しているかを目視するための機能。
	 *
	 * @param string $slug 例: contact-form-7.
	 * @return array 各候補の { url, http, error, type, excerpt, vuln_count }
	 */
	public function diagnose( $slug = 'contact-form-7' ) {
		$slug = sanitize_key( $slug );

		$candidates = array(
			'https://www.wpvulnerability.net/plugin/' . $slug . '/',
			'https://www.wpvulnerability.net/plugin/' . $slug,
			'https://wpvulnerability.net/plugin/' . $slug . '/',
			'https://www.wpvulnerability.net/core/6.5.3/',
		);

		$results = array();
		foreach ( $candidates as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => self::REQUEST_TIMEOUT,
					'user-agent'  => self::USER_AGENT,
					'headers'     => array( 'Accept' => 'application/json' ),
					'redirection' => 3,
				)
			);

			if ( is_wp_error( $response ) ) {
				$results[] = array(
					'url'        => $url,
					'http'       => 0,
					'error'      => $response->get_error_message(),
					'type'       => '',
					'excerpt'    => '',
					'vuln_count' => null,
				);
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$raw  = (string) wp_remote_retrieve_body( $response );
			$type = (string) wp_remote_retrieve_header( $response, 'content-type' );
			$json = json_decode( $raw, true );
			$cnt  = is_array( $json ) && isset( $json['data']['vulnerability'] ) && is_array( $json['data']['vulnerability'] )
				? count( $json['data']['vulnerability'] )
				: null;

			$results[] = array(
				'url'        => $url,
				'http'       => $code,
				'error'      => '',
				'type'       => $type,
				// 生応答の先頭だけ（HTMLエラーページの中身を確認するため）。
				'excerpt'    => mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $raw ) ) ), 0, 300 ),
				'vuln_count' => $cnt,
			);
		}

		return $results;
	}

	/**
	 * 1回の問い合わせに許す秒数。残り予算を超えないよう切り詰める。
	 *
	 * 予算の判定はコンポーネント単位で行うため、締め切り直前に始まった問い合わせが
	 * 満額（12秒×2回）待つと、接続プラグイン側の60秒を超えて全体が失敗しうる。
	 * 残り時間に合わせて縮めることで、超過を数秒に抑える。
	 *
	 * @return int
	 */
	protected function remaining_timeout() {
		if ( ! $this->deadline ) {
			return self::REQUEST_TIMEOUT;
		}
		$left = $this->deadline - time();
		return (int) max( 3, min( self::REQUEST_TIMEOUT, $left ) );
	}

	/**
	 * パス末尾のスラッシュを反転する（/a/b → /a/b/ 、/a/b/ → /a/b）。
	 *
	 * @param string $path Path.
	 * @return string
	 */
	protected function toggle_trailing_slash( $path ) {
		return ( '/' === substr( $path, -1 ) ) ? rtrim( $path, '/' ) : $path . '/';
	}

	/**
	 * 脆弱性DBへの1回のGET。成功時はデコード済み配列、失敗時はnull。
	 *
	 * @param string $path 例: /plugin/contact-form-7
	 * @return array|null
	 */
	protected function request( $path, $timeout = null ) {
		$response = wp_remote_get(
			self::API_BASE . $path,
			array(
				'timeout'    => ( null === $timeout ) ? self::REQUEST_TIMEOUT : $timeout,
				'user-agent' => self::USER_AGENT,
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) ? $body : null;
	}
}
