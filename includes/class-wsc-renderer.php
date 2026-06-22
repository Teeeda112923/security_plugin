<?php
/**
 * 診断結果の共通描画ヘルパー
 *
 * ダッシュボードウィジェットと専用管理ページの両方から利用し、
 * 1項目の表示マークアップを一箇所に集約する（表示の食い違いを防ぐ）。
 *
 * @package WP_Security_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared rendering helpers for diagnostic results.
 */
class WSC_Renderer {

	/**
	 * Localized status label keyed by status.
	 *
	 * @param string $status One of good|attention|recommended.
	 * @return string Translated label.
	 */
	public static function status_label( $status ) {
		$labels = array(
			'good'        => __( '問題なし', 'cybernote-security-checker' ),
			'attention'   => __( '改善推奨', 'cybernote-security-checker' ),
			'recommended' => __( '要対応', 'cybernote-security-checker' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : '';
	}

	/**
	 * Status icon character keyed by status.
	 *
	 * @param string $status One of good|attention|recommended.
	 * @return string Icon character.
	 */
	public static function status_icon_text( $status ) {
		$icons = array(
			'good'        => '✓',
			'attention'   => '△',
			'recommended' => '×',
		);
		return isset( $icons[ $status ] ) ? $icons[ $status ] : '•';
	}

	/**
	 * Count results by severity.
	 *
	 * @param array $results Diagnostic results from WSC_Diagnostics::run().
	 * @return array Counts keyed by good|attention|recommended.
	 */
	public static function severity_counts( $results ) {
		$counts = array(
			'recommended' => 0,
			'attention'   => 0,
			'good'        => 0,
		);
		$all = self::flatten_results( $results );
		foreach ( $all as $item ) {
			if ( isset( $counts[ $item['status'] ] ) ) {
				$counts[ $item['status'] ]++;
			}
		}
		return $counts;
	}

	/**
	 * Flatten category results.
	 *
	 * @param array $results Diagnostic results from WSC_Diagnostics::run().
	 * @return array
	 */
	public static function flatten_results( $results ) {
		$a = isset( $results['a'] ) && is_array( $results['a'] ) ? $results['a'] : array();
		$b = isset( $results['b'] ) && is_array( $results['b'] ) ? $results['b'] : array();
		return array_merge( $a, $b );
	}

	/**
	 * Return issue items ordered by urgency.
	 *
	 * @param array $results Diagnostic results from WSC_Diagnostics::run().
	 * @param int   $limit Maximum number of items to return. 0 means unlimited.
	 * @return array
	 */
	public static function priority_items( $results, $limit = 5 ) {
		$items = array_filter(
			self::flatten_results( $results ),
			function ( $item ) {
				return isset( $item['status'] ) && 'good' !== $item['status'];
			}
		);

		$weight = array(
			'recommended' => 1,
			'attention'   => 2,
			'good'        => 3,
		);

		usort(
			$items,
			function ( $a, $b ) use ( $weight ) {
				$a_weight = isset( $weight[ $a['status'] ] ) ? $weight[ $a['status'] ] : 99;
				$b_weight = isset( $weight[ $b['status'] ] ) ? $weight[ $b['status'] ] : 99;

				if ( $a_weight === $b_weight ) {
					return strcmp( $a['id'], $b['id'] );
				}
				return $a_weight - $b_weight;
			}
		);

		if ( $limit > 0 ) {
			return array_slice( $items, 0, $limit );
		}
		return $items;
	}

	/**
	 * Render a status chip.
	 *
	 * @param string $status One of good|attention|recommended.
	 * @param string $extra_class Optional additional class.
	 */
	public static function render_status_badge( $status, $extra_class = '' ) {
		$status_label = self::status_label( $status );
		?>
		<span class="wsc-status-badge wsc-badge-<?php echo esc_attr( $status ); ?> <?php echo esc_attr( $extra_class ); ?>">
			<span class="wsc-badge-dot" aria-hidden="true"></span>
			<?php echo esc_html( $status_label ); ?>
		</span>
		<?php
	}

	/**
	 * Per-item guide content (steps and risk) keyed by check ID.
	 *
	 * Values may contain <br> and <code> for formatting.
	 *
	 * @return array
	 */
	private static function guide_data() {
		return array(
			'a1' => array(
				'steps' => 'WordPress管理画面の「ダッシュボード → 更新」を開き、「今すぐ更新」ボタンをクリックしてください。更新前にサイトのバックアップを取っておくと安心です。',
				'risk'  => '未適用のセキュリティ修正が残ったままになります。公開済みの脆弱性を悪用した攻撃でサイトを改ざんされたり、マルウェアを埋め込まれたりするリスクがあります。',
				'links' => array(
					array(
						'label' => 'WordPress 公式：WordPressの更新方法',
						'url'   => 'https://wordpress.org/documentation/article/updating-wordpress/',
					),
				),
			),
			'a2' => array(
				'steps' => 'ご利用のサーバー管理画面（cPanel・さくらコントロールパネル・ConoHaコントロールパネルなど）にログインし、PHPバージョンの切り替えメニューから PHP 8.2 以上を選択してください。変更前にサイトのバックアップを取ることを強くおすすめします。',
				'risk'  => 'サポートが終了したPHPバージョンには、新たに発見された脆弱性の修正パッチが提供されません。攻撃者に悪用されても修正が受けられず、被害が広がりやすくなります。',
				'links' => array(
					array(
						'label' => 'WordPress 公式：推奨サーバー環境',
						'url'   => 'https://wordpress.org/about/requirements/',
					),
					array(
						'label' => 'PHP 公式：サポート中のバージョン一覧',
						'url'   => 'https://www.php.net/supported-versions.php',
					),
				),
			),
			'a3' => array(
				'steps' => '管理画面の「ダッシュボード → 更新」を開き、未更新のプラグインとテーマにチェックを入れて「プラグインを更新」「テーマを更新」をクリックしてください。更新前にバックアップを取っておくと安心です。',
				'risk'  => 'プラグイン・テーマの更新にはセキュリティ修正が含まれることがあります。更新しないまま放置すると、既知の脆弱性を利用した攻撃を受けるリスクがあります。',
				'has_update_link' => true,
				'links' => array(
					array(
						'label' => 'WordPress 公式：プラグインの管理',
						'url'   => 'https://wordpress.org/documentation/article/manage-plugins/',
					),
				),
			),
			'b1' => array(
				'steps' => 'サーバー上の wp-config.php をテキストエディタで開き、以下の行を探してください。<br><code>define(\'WP_DEBUG\', true);</code><br>これを次のように書き換えて保存します。<br><code>define(\'WP_DEBUG\', false);</code>',
				'risk'  => 'デバッグ情報が画面に表示されると、PHPエラーメッセージにサーバー内のファイルパスや内部構造が含まれることがあります。攻撃者にサーバー環境の情報を与えることになります。',
				'links' => array(
					array(
						'label' => 'WordPress 公式：WordPressのデバッグ',
						'url'   => 'https://wordpress.org/documentation/article/debugging-in-wordpress/',
					),
				),
			),
			'b2' => array(
				'steps' => 'wp-config.php を開き、以下の1行を追加してください（「/* 編集が必要なのはここまでです */」という行より前の位置に記述します）。<br><code>define(\'DISALLOW_FILE_EDIT\', true);</code>',
				'risk'  => '管理者アカウントが乗っ取られた場合、テーマ・プラグインのコードエディターからサーバー上のPHPファイルを直接書き換えられてしまいます。バックドアを仕込まれる可能性があります。',
				'links' => array(
					array(
						'label' => 'WordPress 公式：WordPressのセキュリティ強化',
						'url'   => 'https://wordpress.org/documentation/article/hardening-wordpress/',
					),
				),
			),
			'b3' => array(
				'steps' => 'まず別の管理者アカウントを作成してそちらでログインし直してください。その後「ユーザー一覧」から「admin」アカウントを削除します。削除時に既存の投稿を新しいアカウントに引き継ぐ選択ができます。',
				'risk'  => '「admin」はWordPressで最も狙われるユーザー名です。ユーザー名が判明していると、パスワードを総当たりするだけでログインされてしまうリスクが大幅に上がります。',
				'links' => array(
					array(
						'label' => 'WordPress 公式：WordPressのセキュリティ強化',
						'url'   => 'https://wordpress.org/documentation/article/hardening-wordpress/',
					),
				),
			),
			'b4' => array(
				'steps' => 'ご利用のサーバー管理画面でSSL証明書を発行します（多くのサーバーでLet\'s Encryptによる無料発行が可能です）。証明書の設定が完了したら、WordPressの「設定 → 一般」でサイトアドレスとWordPressアドレスをどちらも https:// に変更してください。',
				'risk'  => 'HTTPのままでは通信が暗号化されません。ログイン時のパスワードや問い合わせフォームに入力した個人情報が、通信経路上で盗み見られる（盗聴）リスクがあります。',
				'links' => array(
					array(
						'label' => 'WordPress 公式：WordPressでHTTPSを使う',
						'url'   => 'https://wordpress.org/documentation/article/https-for-wordpress/',
					),
				),
			),
			'b5' => array(
				'steps' => 'この変更は既存サイトでは慎重な作業が必要です。必ずバックアップを取ってから行ってください。phpMyAdminなどでデータベースの全テーブル名の「wp_」部分を別の文字列（例：mywp_）に変更し、wp-config.php の <code>$table_prefix</code> の値も同じ文字列に更新します。',
				'risk'  => 'テーブル名が「wp_」という既知のパターンのままだと、SQLインジェクション攻撃が成功した際にデータベースを操作されやすくなります。',
				'links' => array(
					array(
						'label' => 'WordPress 公式：WordPressのセキュリティ強化',
						'url'   => 'https://wordpress.org/documentation/article/hardening-wordpress/',
					),
				),
			),
			'b6' => array(
				'steps' => '「Disable XML-RPC」などの無料プラグインを使うと簡単に無効化できます。または .htaccess に以下を追加することで xmlrpc.php へのアクセスをブロックできます。<br><code>&lt;Files xmlrpc.php&gt;<br>Order Deny,Allow<br>Deny from all<br>&lt;/Files&gt;</code>',
				'risk'  => 'XML-RPCは古い連携機能で現在のWordPressではほとんど不要です。有効なままにしておくと、1回のリクエストで大量のログイン試行が可能なため、ブルートフォース攻撃に利用されやすくなります。',
				'links' => array(
					array(
						'label' => 'WordPress 公式：XML-RPC について',
						'url'   => 'https://wordpress.org/documentation/article/xml-rpc-support/',
					),
				),
			),
			'b7' => array(
				'steps' => 'セキュリティプラグイン（例：Wordfence・SiteGuard WP Plugin）を使う方法が手軽です。または、テーマの functions.php に以下を追加する方法もあります。<br><code>add_filter(\'rest_endpoints\', function($ep) {<br>&nbsp;&nbsp;if (!is_user_logged_in()) {<br>&nbsp;&nbsp;&nbsp;&nbsp;unset($ep[\'/wp/v2/users\']);<br>&nbsp;&nbsp;&nbsp;&nbsp;unset($ep[\'/wp/v2/users/(?P&lt;id&gt;[\\d]+)\']);<br>&nbsp;&nbsp;}<br>&nbsp;&nbsp;return $ep;<br>});</code>',
				'risk'  => 'REST APIのユーザー一覧エンドポイントが公開されていると、誰でもユーザー名を取得できます。ユーザー名が判明するとパスワードの総当たり攻撃がしやすくなります。',
				'links' => array(
					array(
						'label' => 'WordPress 公式：REST API ハンドブック',
						'url'   => 'https://developer.wordpress.org/rest-api/',
					),
				),
			),
		);
	}

	/**
	 * Render one diagnostic item as a modern card row.
	 *
	 * @param array $item Check result array.
	 * @param array $args Rendering options.
	 */
	public static function render_item( $item, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'compact'      => false,
				'show_message' => true,
				'show_action'  => true,
			)
		);

		$status = isset( $item['status'] ) ? $item['status'] : 'good';
		$id     = isset( $item['id'] ) ? $item['id'] : '';

		$classes = array(
			'wsc-item',
			'wsc-status-' . sanitize_html_class( $status ),
		);
		if ( $args['compact'] ) {
			$classes[] = 'wsc-item-compact';
		}

		$all_guides = self::guide_data();
		$guide      = isset( $all_guides[ $id ] ) ? $all_guides[ $id ] : null;
		$guide_id   = 'wsc-guide-' . esc_attr( $id ) . '-' . wp_rand( 1000, 9999 );

		$allowed_html = array(
			'br'   => array(),
			'code' => array(),
		);
		?>
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
			<div class="wsc-item-icon" aria-hidden="true"><?php echo esc_html( self::status_icon_text( $status ) ); ?></div>

			<div class="wsc-item-content">
				<div class="wsc-item-topline">
					<span class="wsc-item-label"><?php echo esc_html( $item['label'] ); ?></span>
					<?php self::render_status_badge( $status ); ?>
				</div>

				<?php if ( ! empty( $item['detail'] ) ) : ?>
					<div class="wsc-item-detail"><?php echo esc_html( $item['detail'] ); ?></div>
				<?php endif; ?>

				<?php if ( $args['show_message'] && ! empty( $item['message'] ) ) : ?>
					<div class="wsc-item-message"><?php echo esc_html( $item['message'] ); ?></div>
				<?php endif; ?>

				<?php if ( $args['show_action'] && 'a3' === $id && 'good' !== $status ) : ?>
					<div class="wsc-item-action">
						<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>" class="button button-small wsc-secondary-action">
							<?php esc_html_e( '更新画面を開く', 'cybernote-security-checker' ); ?>
						</a>
					</div>
				<?php endif; ?>

				<?php if ( $guide ) : ?>
					<div class="wsc-item-guide" id="<?php echo esc_attr( $guide_id ); ?>" style="display:none">
						<div class="wsc-guide-section">
							<div class="wsc-guide-section-title"><?php esc_html_e( '対応手順', 'cybernote-security-checker' ); ?></div>
							<div class="wsc-guide-steps"><?php echo wp_kses( $guide['steps'], $allowed_html ); ?></div>
						</div>
						<?php if ( ! empty( $guide['has_update_link'] ) ) : ?>
							<div class="wsc-guide-action">
								<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>" class="button button-small wsc-secondary-action">
									<?php esc_html_e( '更新画面を開く', 'cybernote-security-checker' ); ?>
								</a>
							</div>
						<?php endif; ?>
						<div class="wsc-guide-section">
							<div class="wsc-guide-section-title"><?php esc_html_e( '対応しないと…', 'cybernote-security-checker' ); ?></div>
							<div class="wsc-guide-risk"><?php echo wp_kses( $guide['risk'], $allowed_html ); ?></div>
						</div>
						<?php if ( ! empty( $guide['links'] ) ) : ?>
							<div class="wsc-guide-links">
								<div class="wsc-guide-section-title"><?php esc_html_e( '詳細はこちら', 'cybernote-security-checker' ); ?></div>
								<?php foreach ( $guide['links'] as $link ) : ?>
									<a href="<?php echo esc_url( $link['url'] ); ?>" class="wsc-guide-link" target="_blank" rel="noopener noreferrer">
										<span class="dashicons dashicons-external" aria-hidden="true"></span>
										<?php echo esc_html( $link['label'] ); ?>
									</a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( $guide ) : ?>
				<button
					class="wsc-item-chevron wsc-guide-toggle"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $guide_id ); ?>"
					aria-label="<?php esc_attr_e( '詳細ガイドを表示', 'cybernote-security-checker' ); ?>"
					onclick="wscToggleGuide(this)"
				>›</button>
			<?php else : ?>
				<span class="wsc-item-chevron" aria-hidden="true">›</span>
			<?php endif; ?>
		</div>
		<?php

	}
}
