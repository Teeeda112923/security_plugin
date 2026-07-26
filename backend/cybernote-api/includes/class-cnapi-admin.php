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
		?>
		<div class="wrap">
			<h1>CyberNote API</h1>
			<p>脆弱性スキャンAPIの設定です。エンドポイント: <code><?php echo esc_html( rest_url( 'cybernote/v1/scan' ) ); ?></code></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'cnapi_settings' ); ?>
				<h2>ライセンスキー（暫定管理）</h2>
				<p>1行に1キー（形式: <code>WSC-XXXX-XXXX-XXXX-XXXX</code>）。決済連携までは、発行したキーをここに手動で登録します。</p>
				<textarea name="<?php echo esc_attr( CNAPI_License::OPTION_KEYS ); ?>" rows="8" cols="40" class="large-text code"><?php echo esc_textarea( (string) get_option( CNAPI_License::OPTION_KEYS, '' ) ); ?></textarea>
				<?php submit_button( 'キーを保存' ); ?>
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
