<?php
/**
 * 管理画面: 「脆弱性アラート」ページ（結果表示・ライセンス設定・手動スキャン）。
 *
 * 無料版（CyberNote Security Checker）が有効なら、そのメニュー配下に表示し、
 * 無料版のPro案内ページを実データ画面へ置き換える。無料版が無い場合は
 * 独立したトップレベルメニューとして動作する。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CNSCP_Admin {

	const PAGE_SLUG = 'cnscp-alerts';

	/** @var string 登録されたページのhook名（CSS読み込み判定用） */
	protected static $hook = '';

	/**
	 * メニュー登録（admin_menu priority 20: 無料版の後に実行される）。
	 */
	public static function register_menu() {
		$free_active = class_exists( 'CNSC_Admin_Page' );

		if ( $free_active ) {
			// 無料版の案内ページ（脆弱性アラート Pro）を実データ画面に差し替える。
			remove_submenu_page( 'cybernote-security-checker', 'cybernote-security-checker-cve' );
			self::$hook = add_submenu_page(
				'cybernote-security-checker',
				'脆弱性アラート',
				'脆弱性アラート',
				'manage_options',
				self::PAGE_SLUG,
				array( __CLASS__, 'render_page' )
			);
		} else {
			self::$hook = add_menu_page(
				'脆弱性アラート',
				'脆弱性アラート',
				'manage_options',
				self::PAGE_SLUG,
				array( __CLASS__, 'render_page' ),
				'dashicons-shield-alt',
				81
			);
		}
	}

	/**
	 * CSS読み込み（当ページのみ）。
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style(
			'cnscp-pro',
			CNSCP_PLUGIN_URL . 'assets/css/pro.css',
			array( 'dashicons' ),
			CNSCP_VERSION
		);
	}

	/**
	 * ライセンス保存（admin-post）。保存後に接続テストを兼ねてスキャンを実行。
	 */
	public static function handle_save_license() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'permission denied' );
		}
		check_admin_referer( 'cnscp_save_license' );

		$key = strtoupper( sanitize_text_field( wp_unslash( $_POST['cnscp_license_key'] ?? '' ) ) );
		update_option( CNSCP_Scanner::OPT_LICENSE, $key, false );

		$result = '' === $key ? true : CNSCP_Scanner::run();
		$code   = is_wp_error( $result ) ? 'license_error' : 'license_saved';
		wp_safe_redirect( add_query_arg( 'cnscp_msg', $code, self::page_url() ) );
		exit;
	}

	/**
	 * 手動スキャン（admin-post）。
	 */
	public static function handle_scan_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'permission denied' );
		}
		check_admin_referer( 'cnscp_scan_now' );

		$result = CNSCP_Scanner::run();
		$code   = is_wp_error( $result ) ? 'scan_error' : 'scan_ok';
		wp_safe_redirect( add_query_arg( 'cnscp_msg', $code, self::page_url() ) );
		exit;
	}

	/**
	 * メール通知設定の保存（admin-post）。
	 */
	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'permission denied' );
		}
		check_admin_referer( 'cnscp_save_settings' );

		$enabled = ! empty( $_POST['cnscp_notify_enabled'] );
		$email   = sanitize_email( wp_unslash( $_POST['cnscp_notify_email'] ?? '' ) );

		update_option( CNSCP_Notifier::OPT_ENABLED, $enabled ? 1 : 0, false );
		update_option( CNSCP_Notifier::OPT_EMAIL, $email, false );

		wp_safe_redirect( add_query_arg( 'cnscp_msg', 'settings_saved', self::page_url() ) );
		exit;
	}

	/**
	 * ページURL。
	 *
	 * @return string
	 */
	protected static function page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * メール通知の設定フォーム。
	 */
	protected static function render_notify_form() {
		$enabled = CNSCP_Notifier::is_enabled();
		$email   = (string) get_option( CNSCP_Notifier::OPT_EMAIL, '' );
		$default = (string) get_option( 'admin_email' );
		?>
		<div class="cnscp-license">
			<h2 class="cnscp-license-title">メール通知</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cnscp_save_settings' ); ?>
				<input type="hidden" name="action" value="cnscp_save_settings" />
				<p>
					<label>
						<input type="checkbox" name="cnscp_notify_enabled" value="1" <?php checked( $enabled ); ?> />
						新しい脆弱性が見つかったときにメールで知らせる
					</label>
				</p>
				<p>
					送信先メール:
					<input type="email" name="cnscp_notify_email" class="regular-text" value="<?php echo esc_attr( $email ); ?>" placeholder="<?php echo esc_attr( $default ); ?>（未入力なら管理者メール）" />
				</p>
				<p class="cnscp-license-help">毎回ではなく、<strong>前回から新しく増えた脆弱性だけ</strong>をお知らせします。</p>
				<button type="submit" class="button">保存</button>
			</form>
		</div>
		<?php
	}

	/**
	 * ページ描画。
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$license    = trim( (string) get_option( CNSCP_Scanner::OPT_LICENSE, '' ) );
		$results    = CNSCP_Scanner::latest_results();
		$vulns      = is_array( $results['vulnerabilities'] ?? null ) ? $results['vulnerabilities'] : array();
		$incomplete = ! empty( $results['incomplete'] );
		$components = (int) ( $results['components'] ?? 0 );
		$last_scan  = (int) get_option( CNSCP_Scanner::OPT_LAST_SCAN, 0 );
		?>
		<div class="wrap cnscp-wrap">
			<h1 class="cnscp-title"><span class="dashicons dashicons-shield-alt"></span> 脆弱性アラート</h1>
			<p class="cnscp-lede">使用中のプラグイン・テーマ・WordPress本体を、既知の脆弱性情報（CVE）と毎日自動で照合します。</p>

			<?php self::render_notice(); ?>

			<?php if ( '' === $license ) : ?>
				<?php self::render_license_form( $license, true ); ?>
			<?php else : ?>

				<div class="cnscp-toolbar">
					<span class="cnscp-lastscan">
						<span class="dashicons dashicons-clock"></span>
						最終スキャン:
						<?php echo $last_scan ? esc_html( wp_date( 'Y/m/d H:i', $last_scan ) ) : '未実行'; ?>
						（毎日自動実行）
					</span>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'cnscp_scan_now' ); ?>
						<input type="hidden" name="action" value="cnscp_scan_now" />
						<button type="submit" class="button button-primary">今すぐスキャン</button>
					</form>
				</div>

				<?php if ( $incomplete ) : ?>
					<div class="cnscp-card cnscp-tone-warning">
						<div class="cnscp-card-head">
							<span class="cnscp-name">照合が最後まで完了しませんでした</span>
							<span class="cnscp-sev">要確認</span>
						</div>
						<p class="cnscp-desc">現在、脆弱性データベースとの通信が一時的に不調のため、照合を最後まで完了できませんでした（この結果では「安全」と判断できません）。<strong>数時間ほどおいてから</strong>「今すぐスキャン」で再度お試しください。繰り返し発生する場合は <a href="https://www.cybernote.click/contact/" target="_blank" rel="noopener noreferrer">https://www.cybernote.click/contact/</a> からご連絡ください。</p>
					</div>
				<?php endif; ?>

				<?php if ( empty( $vulns ) ) : ?>
					<?php if ( $last_scan && ! $incomplete ) : ?>
						<div class="cnscp-allclear">
							<span class="dashicons dashicons-yes-alt"></span>
							<div>
								<strong>既知の脆弱性は見つかりませんでした。</strong>
								<p>
								<?php if ( $components > 0 ) : ?>
									プラグイン・テーマ・WordPress本体を<strong><?php echo esc_html( number_format_i18n( $components ) ); ?>件</strong>照合しての結果です。
								<?php endif; ?>
								このまま毎日自動でチェックを続けます。新しく見つかった場合はここに表示されます。
							</p>
							</div>
						</div>
					<?php endif; ?>
				<?php else : ?>
					<p class="cnscp-count"><strong><?php echo esc_html( number_format_i18n( count( $vulns ) ) ); ?>件</strong>の既知の脆弱性が見つかりました。深刻度の高い順に表示しています。</p>
					<?php foreach ( $vulns as $vuln ) : ?>
						<?php self::render_vulnerability( $vuln ); ?>
					<?php endforeach; ?>
				<?php endif; ?>

				<?php self::render_license_form( $license, false ); ?>
				<?php self::render_notify_form(); ?>

			<?php endif; ?>

			<p class="cnscp-note">スキャンで送信するのは、プラグイン・テーマの名前とバージョン、WordPress・PHPのバージョン、サイトURLのみです。個人情報は送信しません。</p>
		</div>
		<?php
	}

	/**
	 * 通知メッセージ。
	 */
	protected static function render_notice() {
		$msg = sanitize_key( wp_unslash( $_GET['cnscp_msg'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示切替のみ。
		if ( '' === $msg ) {
			return;
		}
		$error_detail = (string) get_option( CNSCP_Scanner::OPT_LAST_ERROR, '' );

		$map = array(
			'scan_ok'       => array( 'success', 'スキャンが完了しました。' ),
			'scan_error'    => array( 'error', 'スキャンに失敗しました。' . $error_detail ),
			'license_saved' => array( 'success', 'ライセンスキーを保存し、スキャンを実行しました。' ),
			'license_error' => array( 'error', 'ライセンスキーを保存しましたが、接続確認に失敗しました。' . $error_detail ),
			'settings_saved' => array( 'success', 'メール通知の設定を保存しました。' ),
		);
		if ( ! isset( $map[ $msg ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $map[ $msg ][0] ),
			esc_html( $map[ $msg ][1] )
		);
	}

	/**
	 * 脆弱性1件のカード描画。
	 *
	 * @param array $vuln Sanitized vulnerability.
	 */
	protected static function render_vulnerability( $vuln ) {
		$severity = (string) ( $vuln['severity'] ?? 'unknown' );
		$sev_map  = array(
			'critical' => array( 'critical', '深刻度: 重大' ),
			'high'     => array( 'critical', '深刻度: 高' ),
			'medium'   => array( 'warning', '深刻度: 中' ),
			'low'      => array( 'muted', '深刻度: 低' ),
			'unknown'  => array( 'muted', '深刻度: 調査中' ),
		);
		list( $tone, $sev_label ) = $sev_map[ $severity ] ?? $sev_map['unknown'];

		$type_label = array(
			'plugin' => 'プラグイン',
			'theme'  => 'テーマ',
			'core'   => 'WordPress本体',
		)[ $vuln['type'] ?? '' ] ?? '';
		?>
		<div class="cnscp-card cnscp-tone-<?php echo esc_attr( $tone ); ?>">
			<div class="cnscp-card-head">
				<div>
					<?php if ( $type_label ) : ?>
						<span class="cnscp-type"><?php echo esc_html( $type_label ); ?></span>
					<?php endif; ?>
					<span class="cnscp-name"><?php echo esc_html( $vuln['name'] ); ?></span>
					<?php if ( 'core' !== ( $vuln['type'] ?? '' ) ) : ?>
						<span class="cnscp-version">v<?php echo esc_html( $vuln['installed_version'] ); ?></span>
					<?php endif; ?>
				</div>
				<span class="cnscp-sev"><?php echo esc_html( $sev_label ); ?></span>
			</div>

			<?php if ( ! empty( $vuln['vuln_type_ja'] ) ) : ?>
				<div class="cnscp-vtype"><?php echo esc_html( $vuln['vuln_type_ja'] ); ?><?php echo ! empty( $vuln['cve_id'] ) ? ' / ' . esc_html( $vuln['cve_id'] ) : ''; ?></div>
			<?php endif; ?>

			<?php if ( ! empty( $vuln['description_ja'] ) ) : ?>
				<p class="cnscp-desc"><?php echo esc_html( $vuln['description_ja'] ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $vuln['action_ja'] ) ) : ?>
				<div class="cnscp-action">
					<strong>推奨する対処:</strong> <?php echo esc_html( $vuln['action_ja'] ); ?>
				</div>
			<?php endif; ?>

			<div class="cnscp-card-foot">
				<?php if ( empty( $vuln['unfixed'] ) && in_array( $vuln['type'], array( 'plugin', 'theme' ), true ) ) : ?>
					<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>" class="button">更新画面を開く</a>
				<?php endif; ?>
				<?php if ( ! empty( $vuln['cybernote_url'] ) ) : ?>
					<a href="<?php echo esc_url( $vuln['cybernote_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">CyberNoteで詳しく見る ↗</a>
				<?php endif; ?>
				<?php foreach ( (array) ( $vuln['references'] ?? array() ) as $i => $url ) : ?>
					<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer" class="cnscp-ref">参考情報<?php echo count( (array) $vuln['references'] ) > 1 ? esc_html( (string) ( $i + 1 ) ) : ''; ?> ↗</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * ライセンス設定フォーム。
	 *
	 * @param string $license 現在のキー.
	 * @param bool   $onboarding 初期設定表示（キー未設定）かどうか.
	 */
	protected static function render_license_form( $license, $onboarding ) {
		?>
		<div class="cnscp-license <?php echo $onboarding ? 'cnscp-license-onboarding' : ''; ?>">
			<?php if ( $onboarding ) : ?>
				<h2>はじめに: ライセンスキーを設定してください</h2>
				<p>ご購入時にメールでお送りしたライセンスキー（<code>WSC-XXXX-XXXX-XXXX-XXXX</code>）を入力すると、自動チェックが始まります。</p>
			<?php else : ?>
				<h2 class="cnscp-license-title">ライセンス</h2>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cnscp_save_license' ); ?>
				<input type="hidden" name="action" value="cnscp_save_license" />
				<input type="text" name="cnscp_license_key" class="regular-text code" placeholder="WSC-XXXX-XXXX-XXXX-XXXX" value="<?php echo esc_attr( $license ); ?>" />
				<button type="submit" class="button <?php echo $onboarding ? 'button-primary' : ''; ?>">保存して接続を確認</button>
			</form>
			<?php if ( ! $onboarding ) : ?>
				<p class="cnscp-license-help">ライセンスキーやご契約に関するお問い合わせは <a href="https://www.cybernote.click/contact/" target="_blank" rel="noopener noreferrer">cybernote.click のお問い合わせ</a> へ。</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
