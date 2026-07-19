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
	const CACHE_TTL       = DAY_IN_SECONDS;
	const REQUEST_TIMEOUT = 8;   // 1問い合わせあたりの上限秒。
	const TIME_BUDGET     = 20;  // スキャン全体の外部問い合わせ予算秒（接続側の30秒より短く）。

	/** @var int このスキャンで実際に外部へ問い合わせた回数（キャッシュ命中は除く）。 */
	protected $checked = 0;
	/** @var int うち失敗（到達不可・エラー）した回数。 */
	protected $failed = 0;
	/** @var bool 時間予算切れで一部の照合を打ち切ったか。 */
	protected $aborted = false;
	/** @var int 予算の締め切り時刻（Unix秒）。 */
	protected $deadline = 0;

	/**
	 * 直近スキャンの実行統計。照合が本当に効いたかの判断に使う。
	 *
	 * @return array { checked, failed, aborted }
	 */
	public function get_stats() {
		return array(
			'checked' => $this->checked,
			'failed'  => $this->failed,
			'aborted' => $this->aborted,
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
		$slug     = sanitize_key( $slug );
		$response = wp_remote_get(
			self::API_BASE . '/plugin/' . rawurlencode( $slug ) . '/',
			array(
				'timeout'    => self::REQUEST_TIMEOUT,
				'user-agent' => 'CyberNoteAPI/' . CNAPI_VERSION . ' (+https://www.cybernote.click/)',
			)
		);

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
		$this->checked  = 0;
		$this->failed   = 0;
		$this->aborted  = false;
		$this->deadline = time() + self::TIME_BUDGET;

		$found = array();

		foreach ( (array) ( $env['plugins'] ?? array() ) as $item ) {
			$found = array_merge( $found, $this->match_component( 'plugin', $item ) );
		}
		foreach ( (array) ( $env['themes'] ?? array() ) as $item ) {
			$found = array_merge( $found, $this->match_component( 'theme', $item ) );
		}

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
			return array();
		}

		// 時間予算を超えたら以降の外部問い合わせは打ち切る（接続側のタイムアウト回避）。
		if ( time() > $this->deadline ) {
			$this->aborted = true;
			return array();
		}

		if ( 'core' === $type ) {
			$data = $this->fetch_json( '/core/' . rawurlencode( $version ) . '/' );
		} else {
			$data = $this->fetch_json( '/' . $type . '/' . rawurlencode( $slug ) . '/' );
		}
		if ( empty( $data ) || ! is_array( $data ) ) {
			return array();
		}

		$vulns = $data['data']['vulnerability'] ?? array();
		if ( ! is_array( $vulns ) ) {
			return array();
		}

		$results = array();
		foreach ( $vulns as $vuln ) {
			if ( ! is_array( $vuln ) ) {
				continue;
			}
			// coreエンドポイントはバージョン指定で問い合わせるため常に該当扱い。
			if ( 'core' !== $type && ! $this->version_affected( $version, $vuln['operator'] ?? null ) ) {
				continue;
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

		$max    = $operator['max_version'] ?? null;
		$max_op = $this->normalize_operator( $operator['max_operator'] ?? 'le' );
		$min    = $operator['min_version'] ?? null;
		$min_op = $this->normalize_operator( $operator['min_operator'] ?? 'ge' );

		// 修正版が存在せず範囲上限も無い＝全バージョン影響。
		if ( null === $max && ! empty( $operator['unfixed'] ) ) {
			return null === $min || version_compare( $version, $min, $min_op );
		}
		if ( null === $max ) {
			return false;
		}
		if ( ! version_compare( $version, $max, $max_op ) ) {
			return false;
		}
		return null === $min || version_compare( $version, $min, $min_op );
	}

	/**
	 * APIのoperator表記をversion_compare()の演算子へ。
	 *
	 * @param string $op Operator token.
	 * @return string
	 */
	protected function normalize_operator( $op ) {
		$map = array(
			'lt' => '<',
			'le' => '<=',
			'gt' => '>',
			'ge' => '>=',
			'eq' => '==',
		);
		$op  = strtolower( trim( (string) $op ) );
		return $map[ $op ] ?? '<=';
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
		$unfixed = ! empty( $operator['unfixed'] );

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
			'references'        => array_slice( array_values( array_unique( $sources ) ), 0, 3 ),
		);
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
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		++$this->checked;
		$response = wp_remote_get(
			self::API_BASE . $path,
			array(
				'timeout'    => self::REQUEST_TIMEOUT,
				'user-agent' => 'CyberNoteAPI/' . CNAPI_VERSION . ' (+https://www.cybernote.click/)',
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// 到達不可・エラー。失敗として記録し、短時間だけキャッシュして連打を防ぐ。
			++$this->failed;
			set_transient( $cache_key, array(), 5 * MINUTE_IN_SECONDS );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			set_transient( $cache_key, array(), 5 * MINUTE_IN_SECONDS );
			return null;
		}

		set_transient( $cache_key, $body, self::CACHE_TTL );
		return $body;
	}
}
