<?php
/**
 * 検知の実証テスト（実データ編）。
 *
 * 「この製品は本当に脆弱性を見つけられるのか」を、本物の脆弱性データベースを
 * 相手に実行して数字で示す。営業資料や社内説明にそのまま使えるよう、
 * 結論と根拠の両方を残す。
 *
 * 確かめること:
 *   1. 脆弱性DBにつながり、こちらが想定している形のデータが返るか
 *   2. DBが「影響あり」と書いている版を、影響ありと判定できるか（見逃さない）
 *   3. DBが「修正済み」と書いている版を、影響なしと判定できるか（誤検知しない）
 *   4. 判定処理を別実装で書き直しても同じ結論になるか（実装ミスの相互検証）
 *   5. 実際のサイト規模で、何割の項目を照合できて何秒かかるか
 *
 * 判定の正解は、脆弱性DB自身が公開している影響バージョン範囲から機械的に作る。
 * したがってこのテストが示すのは「DBの内容を正しく解釈できていること」であり、
 * 「DBに載っていない脆弱性まで見つけられること」ではない。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CNAPI_Poc extends CNAPI_Matcher {

	/** @var int 実データ突合に使うコンポーネント数の上限（実行時間を抑えるため）。 */
	const MAX_CASE_COMPONENTS = 8;
	/** @var int 1コンポーネントあたりに検証する脆弱性件数の上限。 */
	const MAX_CASE_VULNS = 4;

	/**
	 * 実データ突合に使うコンポーネント（脆弱性が登録されている見込みのある定番）。
	 *
	 * @return array
	 */
	protected function case_slugs() {
		return array( 'contact-form-7', 'woocommerce', 'elementor', 'wordpress-seo', 'jetpack', 'updraftplus', 'all-in-one-seo-pack', 'wpforms-lite' );
	}

	/**
	 * 実サイト規模の測定に使う構成（日本の個人・小規模サイトを想定）。
	 *
	 * 脆弱性の有無は問わない。「何割を照合できるか」「何秒かかるか」を測るためのもの。
	 *
	 * @return array
	 */
	protected function site_corpus() {
		return array(
			// 世界的に使われている定番。ここが照合できるのは当然で、平均値を押し上げる。
			'international' => array(
				'plugins' => array(
					'akismet', 'contact-form-7', 'wordpress-seo', 'elementor', 'woocommerce',
					'jetpack', 'wpforms-lite', 'all-in-one-seo-pack', 'wordfence', 'updraftplus',
					'really-simple-ssl', 'wp-super-cache', 'litespeed-cache', 'autoptimize', 'ewww-image-optimizer',
					'wp-mail-smtp', 'redirection', 'tablepress', 'advanced-custom-fields', 'classic-editor',
					'google-site-kit', 'duplicator', 'backwpup',
				),
				'themes'  => array( 'twentytwentyfour', 'twentytwentyone', 'astra', 'hello-elementor' ),
			),
			// 日本の個人・小規模サイト向け。ここの収録状況が、この製品の弱点になりうる。
			// 平均に混ぜると弱点が隠れるため、必ず分けて出す。
			'japan'         => array(
				'plugins' => array(
					'wp-multibyte-patch', 'siteguard', 'bogo', 'seo-simple-pack', 'xo-security',
					'vk-all-in-one-expansion-unit', 'snow-monkey-forms', 'mw-wp-form', 'usces', 'welcart-basic',
				),
				'themes'  => array( 'lightning' ),
			),
		);
	}

	/**
	 * 測定用コーパスを1本の配列に平す。
	 *
	 * @return array [ [type, slug, group], ... ]
	 */
	protected function corpus_items() {
		$items = array();
		foreach ( $this->site_corpus() as $group => $sets ) {
			foreach ( $sets['plugins'] as $slug ) {
				$items[] = array( 'type' => 'plugin', 'slug' => $slug, 'group' => $group );
			}
			foreach ( $sets['themes'] as $slug ) {
				$items[] = array( 'type' => 'theme', 'slug' => $slug, 'group' => $group );
			}
		}
		return $items;
	}

	/**
	 * 実証テスト一式を実行する。
	 *
	 * @return array レポート用の構造化データ。
	 */
	public function run() {
		@set_time_limit( 300 );

		$started = microtime( true );
		$report  = array();

		$report['shape']    = $this->inspect_shape();
		$report['cases']    = $this->run_real_cases();
		$report['coverage'] = $this->measure_coverage();
		$report['core']     = $this->inspect_core();
		$report['elapsed']  = round( microtime( true ) - $started, 1 );

		$report['summary'] = $this->summarize( $report );

		return $report;
	}

	/* =================================================================
	 * 1. データ形式の確認
	 * ================================================================= */

	/**
	 * 相手DBが返すデータの形（範囲指定のキー名・演算子表記）を実物から調べる。
	 *
	 * こちらの読み取り方が相手の仕様と合っているかを、推測ではなく実データで確認する。
	 *
	 * @return array
	 */
	protected function inspect_shape() {
		$this->deadline = time() + 60;

		$operators    = array();
		$range_keys   = array();
		$vuln_keys    = array();
		$reachable    = 0;
		$unreachable  = array();
		$total_vulns  = 0;
		$no_range     = 0;   // 影響範囲が書かれていない項目
		$list_ranges  = 0;   // 範囲が配列で並んでいる項目
		$unfixed_max  = 0;   // 未修正なのに上限も書かれている項目（解釈が割れる形）
		$nonstring    = 0;   // 端点が文字列でない項目（数値化で桁が落ちうる）

		foreach ( $this->case_slugs() as $slug ) {
			$data = $this->fetch_json( '/plugin/' . rawurlencode( $slug ) );
			if ( ! is_array( $data ) ) {
				$unreachable[] = $slug;
				continue;
			}
			++$reachable;

			$vulns = $data['data']['vulnerability'] ?? array();
			if ( ! is_array( $vulns ) ) {
				continue;
			}
			$total_vulns += count( $vulns );

			foreach ( $vulns as $vuln ) {
				if ( ! is_array( $vuln ) ) {
					continue;
				}
				foreach ( array_keys( $vuln ) as $k ) {
					$vuln_keys[ $k ] = true;
				}
				$op = $vuln['operator'] ?? null;
				if ( ! is_array( $op ) || ! $op ) {
					++$no_range;
					continue;
				}
				if ( $this->is_range_list( $op ) ) {
					++$list_ranges;
					$ranges = $op;
				} else {
					$ranges = array( $op );
				}
				foreach ( $ranges as $range ) {
					if ( ! is_array( $range ) ) {
						continue;
					}
					if ( $this->is_true( $range['unfixed'] ?? null ) && null !== $this->bound_value( $range['max_version'] ?? null ) ) {
						++$unfixed_max;
					}
					foreach ( $range as $k => $v ) {
						$range_keys[ $k ] = true;
						if ( 'min_operator' === $k || 'max_operator' === $k ) {
							$operators[ strtolower( (string) $v ) ] = true;
						}
						if ( ( 'min_version' === $k || 'max_version' === $k ) && null !== $v && ! is_string( $v ) ) {
							++$nonstring;
						}
					}
				}
			}
		}

		$known_ops = array( 'lt', 'le', 'lte', 'gt', 'ge', 'gte', 'eq', '=', '==', '<', '<=', '>', '>=' );

		return array(
			'reachable'     => $reachable,
			'requested'     => count( $this->case_slugs() ),
			'unreachable'   => $unreachable,
			'total_vulns'   => $total_vulns,
			'range_keys'    => array_keys( $range_keys ),
			'vuln_keys'     => array_keys( $vuln_keys ),
			'operators'     => array_keys( $operators ),
			'unknown_ops'   => array_values( array_diff( array_keys( $operators ), $known_ops ) ),
			'no_range'      => $no_range,
			'list_ranges'   => $list_ranges,
			'unfixed_max'   => $unfixed_max,
			'nonstring'     => $nonstring,
		);
	}

	/* =================================================================
	 * 2〜4. 実データ突合＋相互検証
	 * ================================================================= */

	/**
	 * 実データの影響範囲から検証用バージョンを組み立て、判定結果を突き合わせる。
	 *
	 * @return array
	 */
	protected function run_real_cases() {
		$rows = array(
			'detail'      => array(),
			'hit_ok'      => 0,
			'hit_ng'      => 0,
			'clear_ok'    => 0,
			'clear_ng'    => 0,
			'cross_total' => 0,
			'cross_diff'  => 0,
			'skipped'     => 0,  // 範囲はあるが検証用バージョンを作れなかった
			'no_range'    => 0,  // そもそも影響範囲が書かれていない（＝製品は報告しない）
		);

		$components = 0;
		foreach ( $this->case_slugs() as $slug ) {
			if ( $components >= self::MAX_CASE_COMPONENTS ) {
				break;
			}

			$this->deadline = time() + 60;
			$data = $this->fetch_json( '/plugin/' . rawurlencode( $slug ) );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$vulns = $data['data']['vulnerability'] ?? array();
			if ( ! is_array( $vulns ) || ! $vulns ) {
				continue;
			}
			++$components;

			$used = 0;
			foreach ( $vulns as $vuln ) {
				if ( ! is_array( $vuln ) ) {
					continue;
				}
				$op = $vuln['operator'] ?? null;
				// 影響範囲が書かれていない項目は、製品側も報告しない（推測で脅かさない）。
				// 何件そうだったかは、実証の限界として明示するために数えておく。
				if ( ! is_array( $op ) || ! $op ) {
					++$rows['no_range'];
					continue;
				}
				if ( $used >= self::MAX_CASE_VULNS ) {
					continue;
				}

				$inside  = $this->pick_inside( $op );
				$outside = $this->pick_outside( $op );
				if ( null === $inside && null === $outside ) {
					++$rows['skipped'];
					continue;
				}
				++$used;

				$title = (string) ( $vuln['name'] ?? '' );
				$row   = array(
					'slug'    => $slug,
					'title'   => $title,
					'range'   => $this->describe_range( $op ),
					'inside'  => $inside,
					'outside' => $outside,
				);

				if ( null !== $inside ) {
					$hit          = $this->detects( $slug, $inside, $title );
					$row['hit']   = $hit;
					$rows[ $hit ? 'hit_ok' : 'hit_ng' ] += 1;
				}
				if ( null !== $outside ) {
					$quiet          = ! $this->detects( $slug, $outside, $title );
					$row['clear']   = $quiet;
					$rows[ $quiet ? 'clear_ok' : 'clear_ng' ] += 1;
				}

				// 相互検証: 本番の判定と、別実装の判定を同じ入力で比べる。
				foreach ( array( $inside, $outside ) as $v ) {
					if ( null === $v || '' === $v ) {
						continue;
					}
					++$rows['cross_total'];
					if ( $this->version_affected( $v, $op ) !== $this->reference_affected( $v, $op ) ) {
						++$rows['cross_diff'];
						$row['cross_diff'] = true;
					}
				}

				$rows['detail'][] = $row;
			}
		}

		$rows['components'] = $components;
		return $rows;
	}

	/**
	 * 指定バージョンでスキャンしたとき、その脆弱性が結果に出るか。
	 *
	 * 本番と同じ scan() を通すため、整形・並べ替えまで含めた実際の挙動を見ている。
	 *
	 * @param string $slug    Component slug.
	 * @param string $version Version to test.
	 * @param string $title   Vulnerability title to look for.
	 * @return bool
	 */
	protected function detects( $slug, $version, $title ) {
		$found = $this->scan(
			array( 'plugins' => array( array( 'slug' => $slug, 'version' => $version, 'name' => $slug ) ) )
		);
		foreach ( $found as $f ) {
			if ( (string) $f['title'] === (string) $title ) {
				return true;
			}
		}
		return false;
	}

	/* =================================================================
	 * 5. 実サイト規模のカバー率と所要時間
	 * ================================================================= */

	/**
	 * 実サイトを模した構成でスキャンし、照合できた割合と所要時間を測る。
	 *
	 * @return array
	 */
	protected function measure_coverage() {
		$items = $this->corpus_items();

		$env = array( 'wp_version' => get_bloginfo( 'version' ), 'plugins' => array(), 'themes' => array() );
		foreach ( $items as $it ) {
			$env[ 'plugin' === $it['type'] ? 'plugins' : 'themes' ][] = array(
				'slug'    => $it['slug'],
				'version' => '1.0.0',
				'name'    => $it['slug'],
			);
		}
		$requested = count( $items ) + 1;

		// 1回目: キャッシュがある項目はそのまま使う（実運用と同じ条件）。
		$t0 = microtime( true );
		$this->scan( $env );
		$first = round( microtime( true ) - $t0, 1 );
		$stats = $this->get_stats();

		// 2回目: 全項目キャッシュ済みの状態。日々の定期スキャンの体感に近い。
		$t1 = microtime( true );
		$this->scan( $env );
		$second = round( microtime( true ) - $t1, 1 );

		// どのコンポーネントが収録されているかを個別に確認する。
		// 平均値だけだと弱点が隠れるため、国際的な定番と日本向けを分けて数える。
		$groups = array();
		$this->deadline = time() + 180;
		foreach ( $items as $it ) {
			$g = $it['group'];
			if ( ! isset( $groups[ $g ] ) ) {
				$groups[ $g ] = array( 'known' => array(), 'unknown' => array() );
			}
			$data = $this->fetch_json( '/' . $it['type'] . '/' . rawurlencode( $it['slug'] ) );
			$has  = is_array( $data ) && isset( $data['data'] ) && isset( $data['data']['vulnerability'] );
			$groups[ $g ][ $has ? 'known' : 'unknown' ][] = $it['slug'];
		}

		$known   = array();
		$unknown = array();
		foreach ( $groups as $g => $set ) {
			$known   = array_merge( $known, $set['known'] );
			$unknown = array_merge( $unknown, $set['unknown'] );
			$total   = count( $set['known'] ) + count( $set['unknown'] );
			$groups[ $g ]['total'] = $total;
			$groups[ $g ]['rate']  = $total ? round( 100 * count( $set['known'] ) / $total, 1 ) : null;
		}

		return array(
			'requested'   => $requested,
			'matched'     => (int) $stats['components'],
			'failed'      => (int) $stats['failed'],
			'unknown_cnt' => (int) ( $stats['unknown'] ?? 0 ),
			'unevaluated' => (int) ( $stats['unevaluated'] ?? 0 ),
			'aborted'     => (bool) $stats['aborted'],
			'used_stale'  => (bool) $stats['used_stale'],
			'first_sec'   => $first,
			'cached_sec'  => $second,
			'known'       => $known,
			'unknown'     => $unknown,
			'groups'      => $groups,
		);
	}

	/* =================================================================
	 * 6. WordPress本体の扱い
	 * ================================================================= */

	/**
	 * /core/{version}/ が本当にそのバージョン向けに絞られた結果を返すか確認する。
	 *
	 * 本体だけは「問い合わせたバージョンの結果が返る」前提で全件を採用しているため、
	 * その前提が成り立っているかを実データで確かめる。
	 *
	 * @return array
	 */
	protected function inspect_core() {
		$this->deadline = time() + 60;

		$rows = array();
		foreach ( array( '4.7', '5.8', get_bloginfo( 'version' ) ) as $ver ) {
			$ver = trim( (string) $ver );
			if ( '' === $ver ) {
				continue;
			}
			$data  = $this->fetch_json( '/core/' . rawurlencode( $ver ) . '/' );
			$vulns = is_array( $data ) ? ( $data['data']['vulnerability'] ?? null ) : null;

			$with_range = 0;
			$in_range   = 0;
			if ( is_array( $vulns ) ) {
				foreach ( $vulns as $v ) {
					if ( ! is_array( $v ) || empty( $v['operator'] ) || ! is_array( $v['operator'] ) ) {
						continue;
					}
					++$with_range;
					if ( $this->reference_affected( $ver, $v['operator'] ) ) {
						++$in_range;
					}
				}
			}

			$rows[] = array(
				'version'    => $ver,
				'reachable'  => is_array( $vulns ),
				'count'      => is_array( $vulns ) ? count( $vulns ) : null,
				'with_range' => $with_range,
				'in_range'   => $in_range,
			);
		}
		return $rows;
	}

	/* =================================================================
	 * 検証用バージョンの組み立て
	 * ================================================================= */

	/**
	 * 影響範囲の「内側」にあるバージョンを1つ選ぶ。候補は別実装で検算してから返す。
	 *
	 * @param array $op Range info.
	 * @return string|null 作れなければ null。
	 */
	protected function pick_inside( $op ) {
		$min = $this->bound_value( $op['min_version'] ?? null );
		$max = $this->bound_value( $op['max_version'] ?? null );

		$candidates = array();
		if ( null !== $max ) {
			$candidates[] = $this->shift_version( $max, -1 );
			$candidates[] = $max;
		}
		if ( null !== $min ) {
			$candidates[] = $min;
			$candidates[] = $this->shift_version( $min, 1 );
		}
		$candidates[] = '0.0.1';
		$candidates[] = '1.0.0';
		$candidates[] = '99999.0.0';

		return $this->first_matching( $candidates, $op, true );
	}

	/**
	 * 影響範囲の「外側」にあるバージョンを1つ選ぶ（修正済みサイトの再現）。
	 *
	 * @param array $op Range info.
	 * @return string|null 作れなければ null（全バージョン影響の場合など）。
	 */
	protected function pick_outside( $op ) {
		$min = $this->bound_value( $op['min_version'] ?? null );
		$max = $this->bound_value( $op['max_version'] ?? null );

		$candidates = array();
		if ( null !== $max ) {
			$candidates[] = $max;                            // 「〜未満」なら修正版そのもの
			$candidates[] = $this->shift_version( $max, 1 );
			$candidates[] = '99999.0.0';
		}
		if ( null !== $min ) {
			$candidates[] = $this->shift_version( $min, -1 );
			$candidates[] = '0.0.1';
		}

		return $this->first_matching( $candidates, $op, false );
	}

	/**
	 * 候補の中から、別実装の判定が期待どおりになる最初の1つを返す。
	 *
	 * 正解づくりに本番の判定を使わないことで、テストが自作自演にならないようにする。
	 *
	 * @param array $candidates Version candidates.
	 * @param array $op         Range info.
	 * @param bool  $want       true=範囲内 / false=範囲外.
	 * @return string|null
	 */
	protected function first_matching( $candidates, $op, $want ) {
		foreach ( $candidates as $c ) {
			if ( null === $c || '' === $c ) {
				continue;
			}
			if ( $this->reference_affected( $c, $op ) === $want ) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * バージョンの末尾の数字を増減させる（1.2.3 → 1.2.4 / 1.2.2）。
	 *
	 * @param string $version Version.
	 * @param int    $delta   増減。
	 * @return string|null 作れなければ null。
	 */
	protected function shift_version( $version, $delta ) {
		if ( ! preg_match( '/^(\d+(?:\.\d+)*)/', trim( (string) $version ), $m ) ) {
			return null;
		}
		$parts = explode( '.', $m[1] );
		$last  = (int) array_pop( $parts ) + $delta;
		if ( $last < 0 ) {
			return null;
		}
		$parts[] = (string) $last;
		return implode( '.', $parts );
	}

	/**
	 * 範囲の端点を取り出す（空文字は指定なし扱い）。
	 *
	 * @param mixed $v Raw bound.
	 * @return string|null
	 */
	protected function bound_value( $v ) {
		if ( null === $v || is_array( $v ) ) {
			return null;
		}
		$v = trim( (string) $v );
		return ( '' === $v ) ? null : $v;
	}

	/**
	 * 影響範囲を人が読める日本語にする。
	 *
	 * @param array $op Range info.
	 * @return string
	 */
	protected function describe_range( $op ) {
		$min = $this->bound_value( $op['min_version'] ?? null );
		$max = $this->bound_value( $op['max_version'] ?? null );
		$sym = array( 'lt' => '未満', 'le' => '以下', 'gt' => '超', 'ge' => '以上', 'eq' => 'のみ' );

		$parts = array();
		if ( null !== $min ) {
			$parts[] = $min . ( $sym[ strtolower( (string) ( $op['min_operator'] ?? 'ge' ) ) ] ?? '以上' );
		}
		if ( null !== $max ) {
			$parts[] = $max . ( $sym[ strtolower( (string) ( $op['max_operator'] ?? 'le' ) ) ] ?? '以下' );
		}
		if ( ! empty( $op['unfixed'] ) ) {
			$parts[] = '修正版なし';
		}
		return $parts ? implode( ' かつ ', $parts ) : '範囲情報なし';
	}

	/* =================================================================
	 * 別実装（相互検証用）
	 *
	 * 本番は PHP の version_compare() を使う。ここでは使わず、
	 * 「数字の区切りごとに比べ、末尾の 0 は無いものとして扱い、
	 *   beta などの符号は正式版より前」という規則で自前に比較する。
	 * 同じ結論になれば、少なくとも比較処理の実装ミスは無いと言える。
	 * ================================================================= */

	/**
	 * 別実装による影響判定。
	 *
	 * @param string $version Installed version.
	 * @param array  $op      Range info.
	 * @return bool
	 */
	protected function reference_affected( $version, $op ) {
		if ( ! is_array( $op ) || ! $op || '' === trim( (string) $version ) ) {
			return false;
		}
		$min = $this->bound_value( $op['min_version'] ?? null );
		$max = $this->bound_value( $op['max_version'] ?? null );

		$lower_ok = ( null === $min )
			|| $this->ref_satisfies( $version, $this->ref_token( $op['min_operator'] ?? 'ge', 'ge' ), $min );
		$upper_ok = ( null === $max )
			? ! empty( $op['unfixed'] )
			: $this->ref_satisfies( $version, $this->ref_token( $op['max_operator'] ?? 'le', 'le' ), $max );

		return $lower_ok && $upper_ok;
	}

	/**
	 * 別実装の比較。
	 *
	 * @param string $v     Version.
	 * @param string $op    Token (lt|le|gt|ge|eq).
	 * @param string $bound Bound.
	 * @return bool
	 */
	protected function ref_satisfies( $v, $op, $bound ) {
		$c = $this->ref_cmp( $v, $bound );
		switch ( $op ) {
			case 'lt':
				return $c < 0;
			case 'le':
				return $c <= 0;
			case 'gt':
				return $c > 0;
			case 'ge':
				return $c >= 0;
			case 'eq':
				return 0 === $c;
		}
		return false;
	}

	/**
	 * 別実装のバージョン比較（-1 / 0 / 1）。
	 *
	 * @param string $a Version A.
	 * @param string $b Version B.
	 * @return int
	 */
	protected function ref_cmp( $a, $b ) {
		$pa = $this->ref_parse( $a );
		$pb = $this->ref_parse( $b );
		$n  = max( count( $pa['nums'] ), count( $pb['nums'] ) );
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

	/**
	 * 別実装のバージョン解析。
	 *
	 * @param string $v Version.
	 * @return array { nums, rank, pre }
	 */
	protected function ref_parse( $v ) {
		$v = strtolower( trim( (string) $v ) );
		if ( preg_match( '/^v(?=\d)/', $v ) ) {
			$v = substr( $v, 1 );
		}
		if ( ! preg_match( '/^([0-9]+(?:\.[0-9]+)*)[.\-+_ ]?(dev|alpha|beta|rc|pl|a|b|p)?[.\-]?([0-9]*)/', $v, $m ) ) {
			return array( 'nums' => array( 0 ), 'rank' => 0, 'pre' => 0 );
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

	/**
	 * 別実装の演算子解釈。
	 *
	 * @param string $raw     Raw token.
	 * @param string $default Default token.
	 * @return string
	 */
	protected function ref_token( $raw, $default ) {
		$map = array(
			'lt'  => 'lt',
			'<'   => 'lt',
			'le'  => 'le',
			'lte' => 'le',
			'<='  => 'le',
			'gt'  => 'gt',
			'>'   => 'gt',
			'ge'  => 'ge',
			'gte' => 'ge',
			'>='  => 'ge',
			'eq'  => 'eq',
			'='   => 'eq',
			'=='  => 'eq',
		);
		$raw = strtolower( trim( (string) $raw ) );
		return $map[ $raw ] ?? $default;
	}

	/* =================================================================
	 * まとめ
	 * ================================================================= */

	/**
	 * 結果を一言でまとめる（合否と、言えること・言えないこと）。
	 *
	 * @param array $r Report.
	 * @return array
	 */
	protected function summarize( $r ) {
		$c     = $r['cases'];
		$hit   = $c['hit_ok'] + $c['hit_ng'];
		$clear = $c['clear_ok'] + $c['clear_ng'];

		$ok = ( 0 === $c['hit_ng'] && 0 === $c['clear_ng'] && 0 === $c['cross_diff'] && $hit > 0 );

		return array(
			'ok'            => $ok,
			'hit_rate'      => $hit ? round( 100 * $c['hit_ok'] / $hit, 1 ) : null,
			'clear_rate'    => $clear ? round( 100 * $c['clear_ok'] / $clear, 1 ) : null,
			'checked_pairs' => $hit + $clear,
			'coverage_rate' => $r['coverage']['requested']
				? round( 100 * $r['coverage']['matched'] / $r['coverage']['requested'], 1 )
				: null,
		);
	}
}
