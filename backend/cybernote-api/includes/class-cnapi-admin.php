<?php
/**
 * 管理画面: ライセンスキーの手動登録（B1暫定）とキャッシュ削除。
 *
 * 設定 > CyberNote API に表示。B3でLemon Squeezy連携に置き換えるまでのつなぎ。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CNAPI_Admin {

	/**
	 * メニュー登録。
	 */
	public static function register_menu() {
		add_options_page(
			'CyberNote API',
			'CyberNote API',
			'manage_options',
			'cybernote-api',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * 設定登録。
	 */
	public static function register_settings() {
		register_setting(
			'cnapi_settings',
			CNAPI_License::OPTION_KEYS,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_keys' ),
				'default'           => '',
			)
		);

		register_setting(
			'cnapi_settings',
			CNAPI_License::OPTION_LS_ON,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => static function ( $v ) {
					return empty( $v ) ? 0 : 1;
				},
				'default'           => 0,
			)
		);
	}

	/**
	 * キー一覧の無害化（形式に合う行だけ残す）。
	 *
	 * @param string $raw Raw textarea input.
	 * @return string
	 */
	public static function sanitize_keys( $raw ) {
		$lines = array();
		foreach ( preg_split( '/[\r\n]+/', (string) $raw ) as $line ) {
			$line = strtoupper( trim( $line ) );
			if ( '' === $line ) {
				continue;
			}
			if ( CNAPI_License::is_well_formed( $line ) ) {
				$lines[] = $line;
			}
		}
		return implode( "\n", array_values( array_unique( $lines ) ) );
	}

	/**
	 * 設定ページの描画。
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['cnapi_clear_cache'] ) && check_admin_referer( 'cnapi_clear_cache' ) ) {
			self::clear_vdb_cache();
			echo '<div class="notice notice-success"><p>脆弱性データのキャッシュを削除しました。</p></div>';
		}

		$probe = null;
		if ( isset( $_POST['cnapi_probe'] ) && check_admin_referer( 'cnapi_probe' ) ) {
			$matcher = new CNAPI_Matcher();
			$probe   = $matcher->probe( 'contact-form-7' );
		}

		$diag = null;
		if ( isset( $_POST['cnapi_diagnose'] ) && check_admin_referer( 'cnapi_diagnose' ) ) {
			$matcher = new CNAPI_Matcher();
			$diag    = $matcher->diagnose( 'contact-form-7' );
		}

		$poc = null;
		if ( isset( $_POST['cnapi_poc'] ) && check_admin_referer( 'cnapi_poc' ) ) {
			$runner = new CNAPI_Poc();
			$poc    = $runner->run();
		}

		$keycheck = null;
		if ( isset( $_POST['cnapi_check_key'] ) && check_admin_referer( 'cnapi_check_key' ) ) {
			$test_key = sanitize_text_field( wp_unslash( $_POST['cnapi_test_key'] ?? '' ) );
			$keycheck = array(
				'key'    => $test_key,
				'valid'  => CNAPI_License::is_valid( $test_key ),
				'reason' => CNAPI_License::invalid_reason( $test_key ),
			);
		}
		?>
		<div class="wrap">
			<h1>CyberNote API</h1>
			<p>脆弱性スキャンAPIの設定です。エンドポイント: <code><?php echo esc_html( rest_url( 'cybernote/v1/scan' ) ); ?></code></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'cnapi_settings' ); ?>
				<h2>ライセンスキー（暫定管理）</h2>
				<p>1行に1キー（形式: <code>WSC-XXXX-XXXX-XXXX-XXXX</code>）。決済連携までは、発行したキーをここに手動で登録します。</p>
				<textarea name="<?php echo esc_attr( CNAPI_License::OPTION_KEYS ); ?>" rows="8" cols="40" class="large-text code"><?php echo esc_textarea( (string) get_option( CNAPI_License::OPTION_KEYS, '' ) ); ?></textarea>

				<h2>Lemon Squeezy 連携</h2>
				<p>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( CNAPI_License::OPTION_LS_ON ); ?>" value="1" <?php checked( CNAPI_License::ls_enabled() ); ?> />
						購入時に Lemon Squeezy が発行したライセンスキーを有効にする
					</label>
				</p>
				<p class="description">
					有効にすると、購入者のキーを Lemon Squeezy に問い合わせて検証します（有効期限・解約も自動で反映）。<br>
					ストアのAPIキーをこのサーバーに置く必要はありません。上の手動登録キーは検証用・無償提供用として引き続き使えます。
				</p>
				<?php submit_button( '保存' ); ?>
			</form>

			<h3>キーの動作確認</h3>
			<p>ライセンスキーを入力すると、実際の判定結果を表示します。</p>
			<?php if ( is_array( $keycheck ) ) : ?>
				<div class="notice notice-<?php echo $keycheck['valid'] ? 'success' : 'error'; ?> inline"><p>
					<?php if ( $keycheck['valid'] ) : ?>
						✓ 有効なライセンスキーです。
					<?php else : ?>
						× <?php echo esc_html( $keycheck['reason'] ); ?>
					<?php endif; ?>
				</p></div>
			<?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( 'cnapi_check_key' ); ?>
				<input type="text" name="cnapi_test_key" class="regular-text code" placeholder="キーを貼り付け" value="" />
				<button type="submit" name="cnapi_check_key" value="1" class="button">このキーを確認</button>
			</form>

			<hr />
			<h2>脆弱性DBへの接続テスト</h2>
			<p>このサーバー（cybernote.click）から脆弱性データベース（WPVulnerability）へ実際に1回だけ問い合わせ、照合が機能しているかを確認します。</p>
			<?php if ( is_array( $probe ) ) : ?>
				<?php if ( ! empty( $probe['ok'] ) ) : ?>
					<div class="notice notice-success inline"><p>
						✓ 接続成功（HTTP <?php echo esc_html( (string) $probe['http'] ); ?>）。
						テスト用プラグイン「contact-form-7」について
						<strong><?php echo esc_html( (string) $probe['vuln_count'] ); ?>件</strong>
						の脆弱性データを取得できました。照合は正常に機能しています。
					</p></div>
				<?php else : ?>
					<div class="notice notice-error inline"><p>
						<?php $ph = (int) ( $probe['http'] ?? 0 ); ?>
						<?php echo esc_html( ( $ph >= 500 || 429 === $ph ) ? '△ 脆弱性データベース側が一時的にエラー／混雑しています。' : ( 0 === $ph ? '× 到達できませんでした。このサーバーから外部への通信が成立していません。' : '× 想定外の応答が返りました。' ) ); ?>
						<?php if ( ! empty( $probe['error'] ) ) : ?>
							（詳細: <?php echo esc_html( (string) $probe['error'] ); ?>）
						<?php endif; ?><br>
						<?php echo esc_html( ( $ph >= 500 || 429 === $ph ) ? 'このサーバーの設定は正常です（相手には届いています）。少し時間をおいて再度お試しください。頻発する場合のみサポートへご連絡ください。' : 'サーバーの外部通信（アウトバウンド）・SSL設定が許可されているか、ホスティング事業者にご確認ください。' ); ?>
					</p></div>
				<?php endif; ?>
			<?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( 'cnapi_probe' ); ?>
				<button type="submit" name="cnapi_probe" value="1" class="button button-primary">接続テストを実行</button>
			</form>

			<h3>詳細診断（うまくいかないとき）</h3>
			<p>複数のURL形式を実際に試し、相手サーバーが返している内容をそのまま表示します。原因の特定に使います。</p>
			<?php if ( is_array( $diag ) ) : ?>
				<table class="widefat striped" style="max-width:100%;margin-bottom:12px">
					<thead><tr><th>URL</th><th>応答</th><th>種類</th><th>脆弱性件数</th><th>本文（先頭300文字）</th></tr></thead>
					<tbody>
					<?php foreach ( $diag as $row ) : ?>
						<tr>
							<td><code style="word-break:break-all"><?php echo esc_html( $row['url'] ); ?></code></td>
							<td><?php echo esc_html( $row['http'] ? 'HTTP ' . $row['http'] : ( '通信失敗: ' . $row['error'] ) ); ?></td>
							<td><?php echo esc_html( $row['type'] ); ?></td>
							<td><?php echo null === $row['vuln_count'] ? '—' : esc_html( (string) $row['vuln_count'] ); ?></td>
							<td style="word-break:break-all"><?php echo esc_html( $row['excerpt'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( 'cnapi_diagnose' ); ?>
				<button type="submit" name="cnapi_diagnose" value="1" class="button">詳細診断を実行</button>
			</form>

			<hr />
			<h2>検知の実証テスト</h2>
			<p>
				本物の脆弱性データベースを相手に、<strong>影響のあるバージョンを検知できるか</strong>・
				<strong>更新済みのサイトを誤って危険と言わないか</strong>を実際に試し、結果を数字で出します。
				営業資料や社内説明の根拠として使えます。<br>
				初回は取得に時間がかかります（おおよそ30〜90秒）。2回目以降はキャッシュが効くため短時間で終わります。
			</p>
			<?php if ( is_array( $poc ) ) : ?>
				<?php self::render_poc( $poc ); ?>
			<?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( 'cnapi_poc' ); ?>
				<button type="submit" name="cnapi_poc" value="1" class="button button-primary">実証テストを実行</button>
			</form>

			<hr />
			<h2>キャッシュ</h2>
			<p>脆弱性データベースへの問い合わせ結果は24時間キャッシュされます。</p>
			<form method="post">
				<?php wp_nonce_field( 'cnapi_clear_cache' ); ?>
				<button type="submit" name="cnapi_clear_cache" value="1" class="button">キャッシュを削除</button>
			</form>
		</div>
		<?php
	}

	/**
	 * 実証テストの結果を描画する。
	 *
	 * 数字だけでなく「この結果で言えないこと」も併記し、過大な期待を持たせない。
	 *
	 * @param array $poc CNAPI_Poc::run() の戻り値.
	 */
	protected static function render_poc( $poc ) {
		$s   = $poc['summary'];
		$c   = $poc['cases'];
		$cov = $poc['coverage'];
		?>
		<div class="notice notice-<?php echo $s['ok'] ? 'success' : 'error'; ?> inline"><p>
			<?php if ( $s['ok'] ) : ?>
				<strong>✓ 検知は正しく機能しています。</strong><br>
				脆弱性データベースが「影響あり」としている
				<strong><?php echo esc_html( (string) $c['hit_ok'] ); ?>件</strong>すべてを検知し、
				更新済みに相当する<strong><?php echo esc_html( (string) $c['clear_ok'] ); ?>件</strong>では
				正しく何も報告しませんでした（合計<?php echo esc_html( (string) $s['checked_pairs'] ); ?>通りを検証）。
			<?php else : ?>
				<strong>× 想定どおりに動いていない箇所があります。</strong>
				見逃し <?php echo esc_html( (string) $c['hit_ng'] ); ?>件 /
				誤検知 <?php echo esc_html( (string) $c['clear_ng'] ); ?>件 /
				判定の食い違い <?php echo esc_html( (string) $c['cross_diff'] ); ?>件。
				下の明細をご確認ください。
			<?php endif; ?>
			<br><span class="description">所要時間 <?php echo esc_html( (string) $poc['elapsed'] ); ?> 秒</span>
		</p></div>

		<h3>① 脆弱性データベースの応答</h3>
		<table class="widefat striped" style="max-width:900px;margin-bottom:16px">
			<tbody>
				<tr><td style="width:230px">問い合わせ / 到達</td><td><?php echo esc_html( $poc['shape']['reachable'] . ' / ' . $poc['shape']['requested'] . ' 件' ); ?></td></tr>
				<tr><td>取得できた脆弱性の総数</td><td><?php echo esc_html( (string) $poc['shape']['total_vulns'] ); ?> 件</td></tr>
				<tr><td>影響範囲の項目名</td><td><code><?php echo esc_html( implode( ', ', $poc['shape']['range_keys'] ) ); ?></code></td></tr>
				<tr><td>使われている比較表記</td><td><code><?php echo esc_html( implode( ', ', $poc['shape']['operators'] ) ); ?></code></td></tr>
				<tr>
					<td>こちらが解釈できない表記</td>
					<td>
						<?php if ( empty( $poc['shape']['unknown_ops'] ) ) : ?>
							なし（すべて解釈できています）
						<?php else : ?>
							<strong style="color:#B91C1C"><?php echo esc_html( implode( ', ', $poc['shape']['unknown_ops'] ) ); ?></strong>
							… この表記に対応していないため、取りこぼす可能性があります。
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td>影響範囲が書かれていない項目</td>
					<td>
						<?php echo esc_html( (string) $poc['shape']['no_range'] ); ?> 件
						<span class="description">（範囲が分からないため報告しません。多い場合は取りこぼしが増えます）</span>
					</td>
				</tr>
				<tr>
					<td>範囲が複数並ぶ形式</td>
					<td><?php echo esc_html( (string) $poc['shape']['list_ranges'] ); ?> 件<span class="description">（どれか1つに入れば該当として扱います）</span></td>
				</tr>
				<tr>
					<td>未修正なのに上限もある項目</td>
					<td>
						<?php echo esc_html( (string) $poc['shape']['unfixed_max'] ); ?> 件
						<?php if ( $poc['shape']['unfixed_max'] > 0 ) : ?>
							<span class="description" style="color:#B45309">
								… 解釈が割れる形です。現在は上限を優先し、上限より新しい版は報告していません。
								件数が多いようなら方針の見直しが必要です。
							</span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td>端点が文字列でない項目</td>
					<td>
						<?php echo esc_html( (string) $poc['shape']['nonstring'] ); ?> 件
						<?php if ( $poc['shape']['nonstring'] > 0 ) : ?>
							<span class="description" style="color:#B45309">… 数値として届くと「1.10」が「1.1」に化ける恐れがあります。</span>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<h3>② 実データでの突合結果</h3>
		<p class="description">
			脆弱性データベースが公開している影響バージョン範囲から、「範囲の内側」と「範囲の外側（更新済み）」の
			バージョンを機械的に作り、実際にスキャンして結果を確かめています。
		</p>
		<table class="widefat striped" style="max-width:1100px;margin-bottom:8px">
			<thead><tr><th>対象</th><th>影響範囲</th><th>範囲内で検知</th><th>更新後は沈黙</th><th>脆弱性</th></tr></thead>
			<tbody>
			<?php foreach ( $c['detail'] as $row ) : ?>
				<tr>
					<td><code><?php echo esc_html( $row['slug'] ); ?></code></td>
					<td><?php echo esc_html( $row['range'] ); ?></td>
					<td>
						<?php if ( ! isset( $row['hit'] ) ) : ?>
							—
						<?php else : ?>
							<?php echo $row['hit'] ? '✓' : '<strong style="color:#B91C1C">見逃し</strong>'; ?>
							<span class="description">(<?php echo esc_html( (string) $row['inside'] ); ?>)</span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( ! isset( $row['clear'] ) ) : ?>
							—
						<?php else : ?>
							<?php echo $row['clear'] ? '✓' : '<strong style="color:#B91C1C">誤検知</strong>'; ?>
							<span class="description">(<?php echo esc_html( (string) $row['outside'] ); ?>)</span>
						<?php endif; ?>
					</td>
					<td style="word-break:break-all"><?php echo esc_html( mb_strimwidth( $row['title'], 0, 70, '…', 'UTF-8' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			別実装で同じ判定をやり直す相互検証: <?php echo esc_html( (string) $c['cross_total'] ); ?> 通り中
			<strong><?php echo esc_html( (string) $c['cross_diff'] ); ?> 件</strong>が不一致。<br>
			影響範囲が書かれておらず判定できない項目: <?php echo esc_html( (string) $c['no_range'] ); ?> 件
			（推測で警告しない方針のため、これらは報告しません）。
		</p>

		<h3>③ 実サイト規模でのカバー率と速度</h3>
		<p class="description">日本の個人・小規模サイトでよく使われる構成（<?php echo esc_html( (string) $cov['requested'] ); ?>項目）で実測しています。</p>
		<table class="widefat striped" style="max-width:900px;margin-bottom:16px">
			<tbody>
				<tr><td style="width:230px">照合できた項目</td><td><strong><?php echo esc_html( $cov['matched'] . ' / ' . $cov['requested'] ); ?></strong>（<?php echo esc_html( (string) $s['coverage_rate'] ); ?>%）</td></tr>
				<tr><td>到達できなかった項目</td><td><?php echo esc_html( (string) $cov['failed'] ); ?> 件<?php echo $cov['aborted'] ? '（時間切れで打ち切りあり）' : ''; ?></td></tr>
				<tr><td>応答の形が想定と違った項目</td><td><?php echo esc_html( (string) $cov['unknown_cnt'] ); ?> 件<span class="description">（安全とは言えないため未確認として扱います）</span></td></tr>
				<tr><td>所要時間（初回 / キャッシュ後）</td><td><?php echo esc_html( $cov['first_sec'] . ' 秒 / ' . $cov['cached_sec'] . ' 秒' ); ?></td></tr>
			</tbody>
		</table>

		<p class="description">
			平均値だけを見ると弱点が隠れるため、世界的な定番と日本向けを分けています。
		</p>
		<table class="widefat striped" style="max-width:900px;margin-bottom:16px">
			<thead><tr><th>区分</th><th>収録あり</th><th>収録率</th><th>未収録のもの</th></tr></thead>
			<tbody>
			<?php
			$labels = array( 'international' => '世界的な定番', 'japan' => '日本向け' );
			foreach ( $cov['groups'] as $key => $g ) :
				?>
				<tr>
					<td><?php echo esc_html( $labels[ $key ] ?? $key ); ?></td>
					<td><?php echo esc_html( count( $g['known'] ) . ' / ' . $g['total'] ); ?></td>
					<td><strong><?php echo esc_html( (string) $g['rate'] ); ?>%</strong></td>
					<td style="word-break:break-all">
						<?php if ( empty( $g['unknown'] ) ) : ?>
							—
						<?php else : ?>
							<code><?php echo esc_html( implode( ', ', $g['unknown'] ) ); ?></code>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			未収録のものは「安全」ではなく「<strong>判断できない</strong>」です。
			日本向けの収録率が低い場合、そこがこの製品の弱点であり、独自に情報を補う価値がある部分になります。
		</p>

		<h3>④ WordPress本体の扱い</h3>
		<table class="widefat striped" style="max-width:900px;margin-bottom:16px">
			<thead><tr><th>バージョン</th><th>取得</th><th>返ってきた件数</th><th>範囲情報あり</th><th>うち該当</th></tr></thead>
			<tbody>
			<?php foreach ( $poc['core'] as $row ) : ?>
				<tr>
					<td><code><?php echo esc_html( $row['version'] ); ?></code></td>
					<td><?php echo $row['reachable'] ? '✓' : '×'; ?></td>
					<td><?php echo null === $row['count'] ? '—' : esc_html( (string) $row['count'] ); ?></td>
					<td><?php echo esc_html( (string) $row['with_range'] ); ?></td>
					<td><?php echo esc_html( (string) $row['in_range'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			「範囲情報あり」と「うち該当」が一致していれば、本体はバージョンごとに絞られた結果が返っており、
			全件をそのまま採用している現在の実装で問題ありません。数が食い違う場合は絞り込みの追加が必要です。
		</p>

		<div class="notice notice-info inline"><p>
			<strong>この結果で言えること・言えないこと</strong><br>
			言えること: 脆弱性データベースに載っている情報を、<u>正しく解釈して過不足なく伝えられている</u>こと。<br>
			言えないこと: データベースに載っていない脆弱性まで見つけられること、および未収録のプラグインが安全であること。
		</p></div>
		<?php
	}

	/**
	 * 脆弱性DBキャッシュ（cnapi_vdb_*）の全削除。
	 */
	protected static function clear_vdb_cache() {
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '\_transient\_cnapi\_vdb\_%'
			    OR option_name LIKE '\_transient\_timeout\_cnapi\_vdb\_%'"
		);
	}
}
