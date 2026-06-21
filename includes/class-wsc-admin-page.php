<?php
/**
 * 専用管理ページ + サブメニュー構成
 *
 * メニュー: ダッシュボード / 診断結果 / バージョン鮮度 / ハードニング設定 /
 *           脆弱性アラート (Pro) / レポート (Business) / 設定
 * 設計方針: 診断の提示のみ・自動変更なし・外部通信なし。
 *
 * @package WP_Security_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the dedicated Security Checker admin pages.
 */
class WSC_Admin_Page {

	const MENU_SLUG        = 'wp-security-checker';
	const SLUG_RESULTS     = 'wp-security-checker-results';
	const SLUG_VERSION     = 'wp-security-checker-version';
	const SLUG_HARDENING   = 'wp-security-checker-hardening';
	const SLUG_CVE         = 'wp-security-checker-cve';
	const SLUG_REPORT      = 'wp-security-checker-report';
	const SLUG_SETTINGS    = 'wp-security-checker-settings';

	/** Plugin pages that should enqueue the admin CSS. */
	private static $plugin_hooks = array();

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wsc_admin_refresh', array( $this, 'ajax_refresh' ) );
	}

	/**
	 * Register top-level menu and all sub-pages.
	 */
	public function register_menu() {
		$hook = add_menu_page(
			__( 'Site Security Checker', 'site-security-checker' ),
			__( 'セキュリティ診断', 'site-security-checker' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-shield',
			80
		);
		self::$plugin_hooks[] = $hook;

		// ダッシュボード（トップと同一URLだが表示名を上書き）。
		$h = add_submenu_page(
			self::MENU_SLUG,
			__( 'ダッシュボード', 'site-security-checker' ),
			__( 'ダッシュボード', 'site-security-checker' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard' )
		);
		self::$plugin_hooks[] = $h;

		$h = add_submenu_page(
			self::MENU_SLUG,
			__( '診断結果', 'site-security-checker' ),
			__( '診断結果', 'site-security-checker' ),
			'manage_options',
			self::SLUG_RESULTS,
			array( $this, 'render_results' )
		);
		self::$plugin_hooks[] = $h;

		$h = add_submenu_page(
			self::MENU_SLUG,
			__( 'バージョン鮮度', 'site-security-checker' ),
			__( 'バージョン鮮度', 'site-security-checker' ),
			'manage_options',
			self::SLUG_VERSION,
			array( $this, 'render_version' )
		);
		self::$plugin_hooks[] = $h;

		$h = add_submenu_page(
			self::MENU_SLUG,
			__( 'ハードニング設定', 'site-security-checker' ),
			__( 'ハードニング設定', 'site-security-checker' ),
			'manage_options',
			self::SLUG_HARDENING,
			array( $this, 'render_hardening' )
		);
		self::$plugin_hooks[] = $h;

		// Pro版有効時はアラートページ、無効時はアップセル画面。
		$h = add_submenu_page(
			self::MENU_SLUG,
			__( '脆弱性アラート', 'site-security-checker' ),
			/* translators: Pro feature badge appended to menu label */
			__( '脆弱性アラート', 'site-security-checker' ) . ' <span class="wsc-menu-badge">Pro</span>',
			'manage_options',
			self::SLUG_CVE,
			array( $this, 'render_cve' )
		);
		self::$plugin_hooks[] = $h;

		$h = add_submenu_page(
			self::MENU_SLUG,
			__( 'レポート', 'site-security-checker' ),
			__( 'レポート', 'site-security-checker' ) . ' <span class="wsc-menu-badge wsc-menu-badge-biz">Business</span>',
			'manage_options',
			self::SLUG_REPORT,
			array( $this, 'render_report_upsell' )
		);
		self::$plugin_hooks[] = $h;

		$h = add_submenu_page(
			self::MENU_SLUG,
			__( '設定', 'site-security-checker' ),
			__( '設定', 'site-security-checker' ),
			'manage_options',
			self::SLUG_SETTINGS,
			array( $this, 'render_settings' )
		);
		self::$plugin_hooks[] = $h;
	}

	/**
	 * Enqueue assets only on this plugin's pages.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, self::$plugin_hooks, true ) ) {
			return;
		}
		wp_enqueue_style(
			'wsc-dashboard',
			WSC_PLUGIN_URL . 'assets/css/dashboard.css',
			array(),
			WSC_VERSION
		);
		wp_enqueue_style(
			'wsc-admin-page',
			WSC_PLUGIN_URL . 'assets/css/admin-page.css',
			array( 'wsc-dashboard' ),
			WSC_VERSION
		);
	}

	/**
	 * AJAX: re-run diagnostics and return body HTML.
	 */
	public function ajax_refresh() {
		check_ajax_referer( 'wsc_admin_refresh_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}
		$results = ( new WSC_Diagnostics() )->run();
		$this->render_body( $results );
		wp_die();
	}

	/* ------------------------------------------------------------------ */
	/*  Page renderers                                                      */
	/* ------------------------------------------------------------------ */

	/** ダッシュボード */
	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$results = ( new WSC_Diagnostics() )->run();
		$this->page_wrap(
			__( 'ダッシュボード', 'site-security-checker' ),
			__( 'サイト内の設定とバージョン状態を診断します。外部への通信は行わず、設定の自動変更もしません。', 'site-security-checker' ),
			function () use ( $results ) {
				$this->render_body( $results );
			}
		);
	}

	/** 診断結果 — 全10項目を1ページに */
	public function render_results() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$results = ( new WSC_Diagnostics() )->run();
		$this->page_wrap(
			__( '診断結果', 'site-security-checker' ),
			__( '全診断項目の結果をまとめて表示します。', 'site-security-checker' ),
			function () use ( $results ) {
				$counts = WSC_Renderer::severity_counts( $results );
				?>
				<div class="wsc-admin-body">
					<?php $this->render_summary_bar( $results, $counts ); ?>
					<div class="wsc-card wsc-category-card" style="padding:20px">
						<h2 class="wsc-card-title" style="margin-bottom:8px"><?php esc_html_e( 'A. バージョン鮮度', 'site-security-checker' ); ?></h2>
						<div class="wsc-item-list" style="margin-bottom:24px">
							<?php foreach ( $results['a'] as $item ) : ?>
								<?php WSC_Renderer::render_item( $item ); ?>
							<?php endforeach; ?>
						</div>
						<h2 class="wsc-card-title" style="margin-bottom:8px"><?php esc_html_e( 'B. ハードニング設定', 'site-security-checker' ); ?></h2>
						<div class="wsc-item-list">
							<?php foreach ( $results['b'] as $item ) : ?>
								<?php WSC_Renderer::render_item( $item ); ?>
							<?php endforeach; ?>
						</div>
					</div>
					<?php $this->render_footer_note(); ?>
				</div>
				<?php
			}
		);
	}

	/** バージョン鮮度 — カテゴリAのみ */
	public function render_version() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$results = ( new WSC_Diagnostics() )->run();
		$this->page_wrap(
			__( 'バージョン鮮度', 'site-security-checker' ),
			__( 'WordPress本体・PHP・プラグイン／テーマの更新状況を確認します。', 'site-security-checker' ),
			function () use ( $results ) {
				?>
				<div class="wsc-admin-body">
					<div class="wsc-card wsc-category-card" style="padding:20px">
						<div class="wsc-item-list">
							<?php foreach ( $results['a'] as $item ) : ?>
								<?php WSC_Renderer::render_item( $item ); ?>
							<?php endforeach; ?>
						</div>
					</div>
					<?php $this->render_footer_note(); ?>
				</div>
				<?php
			}
		);
	}

	/** ハードニング設定 — カテゴリBのみ */
	public function render_hardening() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$results = ( new WSC_Diagnostics() )->run();
		$this->page_wrap(
			__( 'ハードニング設定', 'site-security-checker' ),
			__( 'サイトを攻撃に強くするための基本設定を確認します。', 'site-security-checker' ),
			function () use ( $results ) {
				?>
				<div class="wsc-admin-body">
					<div class="wsc-card wsc-category-card" style="padding:20px">
						<div class="wsc-item-list">
							<?php foreach ( $results['b'] as $item ) : ?>
								<?php WSC_Renderer::render_item( $item ); ?>
							<?php endforeach; ?>
						</div>
					</div>
					<?php $this->render_footer_note(); ?>
				</div>
				<?php
			}
		);
	}

	/** 脆弱性アラート — Pro有効時はアラートページ、無効時はアップセル */
	public function render_cve() {
		if ( WSC_Pro_License::is_active() ) {
			$this->page_wrap(
				__( '脆弱性アラート', 'site-security-checker' ),
				__( '使用中のプラグイン・テーマに既知の脆弱性がないかスキャンします。', 'site-security-checker' ),
				array( $this, 'render_cve_pro_body' )
			);
		} else {
			$this->page_wrap(
				__( '脆弱性アラート', 'site-security-checker' ),
				'',
				array( $this, 'render_cve_upsell_body' )
			);
		}
	}

	/** 脆弱性アラートページ本体（Pro有効時） */
	public function render_cve_pro_body() {
		$results = WSC_Pro_Scanner::get_scan_results();
		$vulns   = isset( $results['vulnerabilities'] ) ? $results['vulnerabilities'] : array();
		$count   = count( $vulns );
		?>
		<div class="wsc-admin-body">
			<?php if ( ! empty( $results['is_mock'] ) ) : ?>
			<div class="notice notice-warning inline" style="margin-bottom:16px">
				<p>
					<strong><?php esc_html_e( '⚠ これはデモデータです。', 'site-security-checker' ); ?></strong>
					<?php esc_html_e( 'Phase 2でAPIと連携すると、実際のサイト環境に基づくスキャン結果が表示されます。', 'site-security-checker' ); ?>
				</p>
			</div>
			<?php endif; ?>

			<!-- スキャンサマリーヒーロー -->
			<section class="wsc-hero wsc-card <?php echo $count > 0 ? 'wsc-hero-recommended' : 'wsc-hero-good'; ?>" style="margin-bottom:20px">
				<div class="wsc-hero-copy">
					<h2>
						<?php
						if ( 0 === $count ) {
							esc_html_e( '脆弱性は検出されませんでした', 'site-security-checker' );
						} else {
							printf(
								/* translators: %d: number of vulnerabilities found */
								esc_html__( '%d件の脆弱性が検出されました', 'site-security-checker' ),
								$count
							);
						}
						?>
					</h2>
					<p class="wsc-last-run">
						<?php
						printf(
							/* translators: %s: scan date/time */
							esc_html__( '最終スキャン: %s', 'site-security-checker' ),
							esc_html( wp_date( 'Y-m-d H:i', strtotime( $results['scanned_at'] ) ) )
						);
						?>
					</p>
				</div>
				<div class="wsc-hero-actions">
					<button type="button" class="button button-primary wsc-refresh-btn" disabled>
						<span class="dashicons dashicons-update" aria-hidden="true"></span>
						<?php esc_html_e( '今すぐスキャン（Phase 2で有効化）', 'site-security-checker' ); ?>
					</button>
				</div>
			</section>

			<!-- 脆弱性リスト -->
			<div class="wsc-card wsc-category-card" style="padding:20px">
				<?php if ( empty( $vulns ) ) : ?>
					<div class="wsc-empty-state">
						<span class="wsc-empty-icon" aria-hidden="true">✓</span>
						<p><?php esc_html_e( '検出された脆弱性はありません。', 'site-security-checker' ); ?></p>
					</div>
				<?php else : ?>
					<div class="wsc-item-list">
						<?php foreach ( $vulns as $vuln ) : ?>
							<?php $this->render_vuln_item( $vuln ); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<?php $this->render_footer_note(); ?>
		</div>
		<?php
	}

	/** 脆弱性アップセル本体（Pro無効時） */
	public function render_cve_upsell_body() {
		?>
		<div class="wsc-admin-body">
			<div class="wsc-card wsc-upsell-card" style="padding:40px 36px;text-align:center;max-width:640px;margin:0 auto">
				<div class="wsc-upsell-badge">Pro</div>
				<h2 class="wsc-upsell-title"><?php esc_html_e( '脆弱性アラート（日本語）', 'site-security-checker' ); ?></h2>
				<p class="wsc-upsell-desc">
					<?php esc_html_e( '使用中のプラグイン・テーマに既知の脆弱性（CVE）が見つかったとき、「どのプラグインが」「どんな危険で」「今何をすべきか」を平易な日本語で通知します。外部の脆弱性データベースとの突合はPro版でのみ提供予定です。', 'site-security-checker' ); ?>
				</p>
				<div class="wsc-upsell-features">
					<div class="wsc-upsell-feature"><span>✓</span><?php esc_html_e( '使用プラグイン・テーマの脆弱性検知', 'site-security-checker' ); ?></div>
					<div class="wsc-upsell-feature"><span>✓</span><?php esc_html_e( '平易な日本語での危険度・対応手順の提示', 'site-security-checker' ); ?></div>
					<div class="wsc-upsell-feature"><span>✓</span><?php esc_html_e( '管理画面バナー＋メール通知', 'site-security-checker' ); ?></div>
					<div class="wsc-upsell-feature"><span>✓</span><?php esc_html_e( '定期チェック（毎日/週次）', 'site-security-checker' ); ?></div>
				</div>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_SETTINGS ) ); ?>" class="button button-primary" style="margin-top:20px">
					<?php esc_html_e( 'ライセンスキーを入力して有効化する', 'site-security-checker' ); ?>
				</a>
				<p class="wsc-upsell-coming"><?php esc_html_e( '近日公開予定', 'site-security-checker' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * 脆弱性1件をカード形式で描画する（Pro CVEページ専用）。
	 *
	 * @param array $vuln WSC_Pro_Scanner::get_scan_results() の vulnerabilities 要素。
	 */
	private function render_vuln_item( $vuln ) {
		$severity = isset( $vuln['severity'] ) ? $vuln['severity'] : 'high';
		$status   = 'critical' === $severity ? 'recommended' : 'attention';
		$guide_id = 'wsc-guide-cve-' . sanitize_html_class( $vuln['slug'] ) . '-' . wp_rand( 1000, 9999 );
		?>
		<div class="wsc-item wsc-status-<?php echo esc_attr( $status ); ?>">
			<div class="wsc-item-icon" aria-hidden="true"><?php echo esc_html( WSC_Renderer::status_icon_text( $status ) ); ?></div>

			<div class="wsc-item-content">
				<div class="wsc-item-topline">
					<span class="wsc-item-label"><?php echo esc_html( $vuln['name'] ); ?></span>
					<?php WSC_Renderer::render_status_badge( $status ); ?>
				</div>

				<div class="wsc-item-detail">
					<?php
					printf(
						/* translators: 1: installed version, 2: fixed version */
						esc_html__( 'v%1$s → v%2$s に更新が必要', 'site-security-checker' ),
						esc_html( $vuln['installed_version'] ),
						esc_html( $vuln['fixed_version'] )
					);
					?>
					&nbsp;·&nbsp;<?php echo esc_html( $vuln['vuln_type_ja'] ); ?>
					<?php if ( ! empty( $vuln['cve_id'] ) ) : ?>
						&nbsp;·&nbsp;<code><?php echo esc_html( $vuln['cve_id'] ); ?></code>
					<?php endif; ?>
				</div>

				<div class="wsc-item-message"><?php echo esc_html( $vuln['title_ja'] ); ?></div>

				<!-- アコーディオン展開パネル -->
				<div class="wsc-item-guide" id="<?php echo esc_attr( $guide_id ); ?>" style="display:none">
					<div class="wsc-guide-section">
						<div class="wsc-guide-section-title"><?php esc_html_e( '概要', 'site-security-checker' ); ?></div>
						<div class="wsc-guide-steps"><?php echo esc_html( $vuln['description_ja'] ); ?></div>
					</div>
					<div class="wsc-guide-section">
						<div class="wsc-guide-section-title"><?php esc_html_e( '対応手順', 'site-security-checker' ); ?></div>
						<div class="wsc-guide-steps"><?php echo esc_html( $vuln['action_ja'] ); ?></div>
					</div>
					<div class="wsc-guide-action">
						<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>" class="button button-small wsc-secondary-action">
							<?php esc_html_e( '更新画面を開く', 'site-security-checker' ); ?>
						</a>
					</div>
					<?php if ( ! empty( $vuln['references'] ) ) : ?>
					<div class="wsc-guide-links">
						<div class="wsc-guide-section-title"><?php esc_html_e( '詳細はこちら', 'site-security-checker' ); ?></div>
						<?php foreach ( $vuln['references'] as $ref ) : ?>
							<a href="<?php echo esc_url( $ref['url'] ); ?>" class="wsc-guide-link" target="_blank" rel="noopener noreferrer">
								<span class="dashicons dashicons-external" aria-hidden="true"></span>
								<?php echo esc_html( $ref['label'] ); ?>
							</a>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>
				</div>
			</div>

			<button
				class="wsc-item-chevron wsc-guide-toggle"
				aria-expanded="false"
				aria-controls="<?php echo esc_attr( $guide_id ); ?>"
				aria-label="<?php esc_attr_e( '詳細ガイドを表示', 'site-security-checker' ); ?>"
				onclick="wscToggleGuide(this)"
			>›</button>
		</div>
		<?php
	}

	/** レポート — Business予定機能のアップセルページ */
	public function render_report_upsell() {
		$this->page_wrap(
			__( 'レポート', 'site-security-checker' ),
			'',
			function () {
				?>
				<div class="wsc-admin-body">
					<div class="wsc-card wsc-upsell-card" style="padding:40px 36px;text-align:center;max-width:640px;margin:0 auto">
						<div class="wsc-upsell-badge wsc-upsell-badge-biz">Business</div>
						<h2 class="wsc-upsell-title"><?php esc_html_e( '月次セキュリティレポート', 'site-security-checker' ); ?></h2>
						<p class="wsc-upsell-desc">
							<?php esc_html_e( '診断結果をPDF形式でまとめて出力し、クライアントや経営者への報告資料として活用できます。複数サイトをまとめて管理する制作会社・フリーランス向けの機能です。', 'site-security-checker' ); ?>
						</p>
						<div class="wsc-upsell-features">
							<div class="wsc-upsell-feature"><span>✓</span><?php esc_html_e( '診断結果のPDFレポート出力', 'site-security-checker' ); ?></div>
							<div class="wsc-upsell-feature"><span>✓</span><?php esc_html_e( '複数サイトの一括管理', 'site-security-checker' ); ?></div>
							<div class="wsc-upsell-feature"><span>✓</span><?php esc_html_e( '月次サマリーメール', 'site-security-checker' ); ?></div>
						</div>
						<p class="wsc-upsell-coming"><?php esc_html_e( '近日公開予定', 'site-security-checker' ); ?></p>
					</div>
				</div>
				<?php
			}
		);
	}

	/** 設定 */
	public function render_settings() {
		$this->page_wrap(
			__( '設定', 'site-security-checker' ),
			__( 'ライセンスキーの入力・Pro版の有効化ができます。', 'site-security-checker' ),
			array( $this, 'render_settings_body' )
		);
	}

	/** 設定ページ本体 */
	public function render_settings_body() {
		$msg       = isset( $_GET['wsc_msg'] ) ? sanitize_key( $_GET['wsc_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$is_active = WSC_Pro_License::is_active();
		$status    = WSC_Pro_License::get_status();
		$key       = WSC_Pro_License::get_key();
		?>
		<?php if ( 'activated' === $msg ) : ?>
			<div class="notice notice-success is-dismissible" style="margin-top:16px">
				<p><?php esc_html_e( '✓ ライセンスが有効になりました。Pro機能をご利用いただけます。', 'site-security-checker' ); ?></p>
			</div>
		<?php elseif ( 'deactivated' === $msg ) : ?>
			<div class="notice notice-info is-dismissible" style="margin-top:16px">
				<p><?php esc_html_e( 'ライセンスを解除しました。', 'site-security-checker' ); ?></p>
			</div>
		<?php elseif ( 'invalid_format' === $msg ) : ?>
			<div class="notice notice-error is-dismissible" style="margin-top:16px">
				<p>
					<?php esc_html_e( 'ライセンスキーの形式が正しくありません。', 'site-security-checker' ); ?>
					<code>WSC-XXXX-XXXX-XXXX-XXXX</code>
					<?php esc_html_e( 'の形式で入力してください（英数字大文字）。', 'site-security-checker' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<div class="wsc-admin-body">

			<!-- ライセンスセクション -->
			<div class="wsc-card wsc-category-card" style="padding:28px;margin-bottom:20px">
				<h2 class="wsc-card-title" style="margin-bottom:16px">
					<span class="wsc-section-icon wsc-section-icon-blue" aria-hidden="true">🔑</span>
					<?php esc_html_e( 'Pro ライセンス', 'site-security-checker' ); ?>
				</h2>

				<?php if ( $is_active ) : ?>
					<!-- 有効状態の表示 -->
					<div style="display:flex;align-items:center;gap:12px;padding:14px 18px;background:var(--wsc-good-bg);border:1px solid var(--wsc-good-border);border-radius:8px;margin-bottom:20px">
						<span style="color:var(--wsc-good);font-size:20px" aria-hidden="true">✓</span>
						<div>
							<strong style="color:var(--wsc-good)"><?php esc_html_e( '有効', 'site-security-checker' ); ?></strong>
							<?php if ( ! empty( $status['expires_at'] ) ) : ?>
								<span style="color:var(--wsc-muted);font-size:13px;margin-left:8px">
									<?php
									printf(
										/* translators: %s: expiry date */
										esc_html__( '%s まで', 'site-security-checker' ),
										esc_html( $status['expires_at'] )
									);
									?>
								</span>
							<?php endif; ?>
						</div>
					</div>
					<p style="font-size:13px;color:var(--wsc-muted);margin-bottom:16px">
						<?php esc_html_e( '登録済みキー：', 'site-security-checker' ); ?>
						<code><?php echo esc_html( $key ); ?></code>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						onsubmit="return confirm('<?php esc_attr_e( 'ライセンスを解除するとPro機能が無効になります。よろしいですか？', 'site-security-checker' ); ?>')">
						<input type="hidden" name="action" value="wsc_save_license">
						<input type="hidden" name="wsc_license_action" value="deactivate">
						<?php wp_nonce_field( 'wsc_license_save' ); ?>
						<button type="submit" class="button">
							<?php esc_html_e( 'ライセンスを解除する', 'site-security-checker' ); ?>
						</button>
					</form>

				<?php else : ?>
					<!-- 未設定状態 -->
					<p style="color:var(--wsc-muted);margin-bottom:16px">
						<?php esc_html_e( 'ライセンスキーを入力するとPro版の脆弱性アラート機能が有効になります。', 'site-security-checker' ); ?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="wsc_save_license">
						<input type="hidden" name="wsc_license_action" value="activate">
						<?php wp_nonce_field( 'wsc_license_save' ); ?>
						<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
							<input
								type="text"
								name="wsc_license_key"
								placeholder="WSC-XXXX-XXXX-XXXX-XXXX"
								value=""
								class="regular-text"
								style="font-family:monospace;letter-spacing:.04em"
								autocomplete="off"
								spellcheck="false"
							>
							<button type="submit" class="button button-primary">
								<?php esc_html_e( '有効化する', 'site-security-checker' ); ?>
							</button>
						</div>
					</form>
				<?php endif; ?>
			</div>

			<!-- 自動スキャン（Phase 3 予定） -->
			<div class="wsc-card wsc-category-card" style="padding:28px;margin-bottom:20px;opacity:.6">
				<h2 class="wsc-card-title" style="margin-bottom:8px">
					<?php esc_html_e( '自動スキャンの頻度', 'site-security-checker' ); ?>
					<span class="wsc-menu-badge" style="margin-left:8px;vertical-align:middle">Phase 3</span>
				</h2>
				<p style="color:var(--wsc-muted);font-size:13px;margin:0">
					<?php esc_html_e( '毎日または週1回のスキャンスケジュールを設定できます。WP-Cronによる自動診断はPhase 3で実装予定です。', 'site-security-checker' ); ?>
				</p>
			</div>

			<!-- メール通知（Phase 3 予定） -->
			<div class="wsc-card wsc-category-card" style="padding:28px;opacity:.6">
				<h2 class="wsc-card-title" style="margin-bottom:8px">
					<?php esc_html_e( 'メール通知', 'site-security-checker' ); ?>
					<span class="wsc-menu-badge" style="margin-left:8px;vertical-align:middle">Phase 3</span>
				</h2>
				<p style="color:var(--wsc-muted);font-size:13px;margin:0">
					<?php esc_html_e( '新しい脆弱性が検知されたときにメールで通知します。通知先アドレスの設定はPhase 3で実装予定です。', 'site-security-checker' ); ?>
				</p>
			</div>

		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/*  Shared layout helpers                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Wrap a page in the standard header + AJAX script.
	 *
	 * @param string   $title   Page title (h1).
	 * @param string   $lead    Lead paragraph (can be empty).
	 * @param callable $content Callable that outputs the main content.
	 */
	private function page_wrap( $title, $lead, $content ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap wsc-admin-wrap">
			<h1 class="wsc-admin-title">
				<span class="dashicons dashicons-shield" aria-hidden="true"></span>
				<?php echo esc_html( $title ); ?>
			</h1>
			<?php if ( $lead ) : ?>
				<p class="wsc-admin-lead"><?php echo esc_html( $lead ); ?></p>
			<?php endif; ?>
			<?php $content(); ?>
		</div>
		<script>
		function wscAdminRefresh(btn) {
			var body = document.getElementById('wsc-admin-body');
			if ( ! body ) { return; }
			btn.disabled = true;
			btn.textContent = '<?php echo esc_js( __( '診断中...', 'site-security-checker' ) ); ?>';
			var xhr = new XMLHttpRequest();
			xhr.open('POST', '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>');
			xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
			xhr.onload = function() {
				if (xhr.status === 200) { body.outerHTML = xhr.responseText; }
			};
			xhr.send('action=wsc_admin_refresh&nonce=' + btn.dataset.nonce);
		}
		</script>
		<?php
	}

	/**
	 * Render the main dashboard body (summary hero + priority + categories).
	 *
	 * @param array $results Diagnostic results from WSC_Diagnostics::run().
	 */
	private function render_body( $results ) {
		$counts         = WSC_Renderer::severity_counts( $results );
		$issues         = (int) $results['summary']['issues'];
		$total          = (int) $results['summary']['total'];
		$priority_items = WSC_Renderer::priority_items( $results, 5 );
		$primary_status = $counts['recommended'] > 0 ? 'recommended' : ( $counts['attention'] > 0 ? 'attention' : 'good' );

		// conic-gradient の角度を問題件数の割合から計算（全10項目）。
		$safe_pct  = $total > 0 ? ( $counts['good'] / $total ) : 1;
		$safe_deg  = round( $safe_pct * 360 );
		$issue_deg = 360 - $safe_deg;
		?>
		<div id="wsc-admin-body" class="wsc-admin-body" aria-live="polite">

			<!-- サマリーヒーロー -->
			<section class="wsc-hero wsc-card wsc-hero-<?php echo esc_attr( $primary_status ); ?>">
				<div class="wsc-hero-status" aria-hidden="true">
					<div class="wsc-hero-ring"
						style="background:conic-gradient(
							<?php echo 'good' === $primary_status ? '#22C55E' : ( 'recommended' === $primary_status ? '#EF4444' : '#F59E0B' ); ?>
							0 <?php echo (int) $issue_deg; ?>deg,
							<?php echo 'good' === $primary_status ? '#BBF7D0' : ( 'recommended' === $primary_status ? '#FECACA' : '#FDE68A' ); ?>
							<?php echo (int) $issue_deg; ?>deg 360deg)">
						<span><?php echo 0 === $issues ? '✓' : '!'; ?></span>
					</div>
				</div>

				<div class="wsc-hero-copy">
					<h2>
						<?php
						if ( 0 === $issues ) {
							esc_html_e( 'すべての項目で問題は見つかりませんでした', 'site-security-checker' );
						} else {
							printf(
								/* translators: 1: number of issue items, 2: total items */
								esc_html__( '%1$d / %2$d 項目で確認が必要です', 'site-security-checker' ),
								$issues,
								$total
							);
						}
						?>
					</h2>
					<p>
						<?php
						if ( 0 === $issues ) {
							esc_html_e( '現在の基本設定は良好です。定期的に再診断してください。', 'site-security-checker' );
						} else {
							esc_html_e( 'まずは「要対応」の項目から確認し、対応していきましょう。', 'site-security-checker' );
						}
						?>
					</p>
				</div>

				<div class="wsc-hero-counts" aria-label="<?php esc_attr_e( '診断結果の内訳', 'site-security-checker' ); ?>">
					<div class="wsc-count-card wsc-count-recommended">
						<span class="wsc-count-icon">!</span>
						<span class="wsc-count-label"><?php esc_html_e( '要対応', 'site-security-checker' ); ?></span>
						<strong><?php echo esc_html( $counts['recommended'] ); ?><?php esc_html_e( '件', 'site-security-checker' ); ?></strong>
					</div>
					<div class="wsc-count-card wsc-count-attention">
						<span class="wsc-count-icon">△</span>
						<span class="wsc-count-label"><?php esc_html_e( '改善推奨', 'site-security-checker' ); ?></span>
						<strong><?php echo esc_html( $counts['attention'] ); ?><?php esc_html_e( '件', 'site-security-checker' ); ?></strong>
					</div>
					<div class="wsc-count-card wsc-count-good">
						<span class="wsc-count-icon">✓</span>
						<span class="wsc-count-label"><?php esc_html_e( '問題なし', 'site-security-checker' ); ?></span>
						<strong><?php echo esc_html( $counts['good'] ); ?><?php esc_html_e( '件', 'site-security-checker' ); ?></strong>
					</div>
				</div>

				<div class="wsc-hero-actions">
					<button
						type="button"
						class="button button-primary wsc-refresh-btn"
						onclick="wscAdminRefresh(this)"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'wsc_admin_refresh_nonce' ) ); ?>"
					>
						<span class="dashicons dashicons-update" aria-hidden="true"></span>
						<?php esc_html_e( '再診断する', 'site-security-checker' ); ?>
					</button>
					<span class="wsc-last-run">
						<?php
						printf(
							/* translators: %s: current date and time */
							esc_html__( '最終診断: %s', 'site-security-checker' ),
							esc_html( current_time( 'Y-m-d H:i' ) )
						);
						?>
					</span>
				</div>
			</section>

			<!-- 優先対応 + カテゴリ グリッド -->
			<div class="wsc-admin-grid">
				<div class="wsc-admin-main-column">

					<!-- 優先対応カード -->
					<section class="wsc-card wsc-priority-card">
						<div class="wsc-card-heading-row">
							<div>
								<h2 class="wsc-card-title">
									<span class="wsc-section-icon wsc-section-icon-alert" aria-hidden="true">!</span>
									<?php esc_html_e( '優先対応が必要な項目', 'site-security-checker' ); ?>
								</h2>
								<p class="wsc-card-desc"><?php esc_html_e( '放置するとリスクが高まります。できるだけ早く対応してください。', 'site-security-checker' ); ?></p>
							</div>
							<?php if ( $issues > 0 ) : ?>
								<span class="wsc-mini-count"><?php echo esc_html( $issues ); ?><?php esc_html_e( '件', 'site-security-checker' ); ?></span>
							<?php endif; ?>
						</div>

						<?php if ( empty( $priority_items ) ) : ?>
							<div class="wsc-empty-state">
								<span class="wsc-empty-icon" aria-hidden="true">✓</span>
								<p><?php esc_html_e( '優先対応が必要な項目はありません。', 'site-security-checker' ); ?></p>
							</div>
						<?php else : ?>
							<div class="wsc-priority-list">
								<?php foreach ( $priority_items as $item ) : ?>
									<?php WSC_Renderer::render_item( $item, array( 'compact' => true ) ); ?>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</section>

					<!-- カテゴリA -->
					<section class="wsc-card wsc-category-card">
						<h2 class="wsc-card-title">
							<span class="wsc-section-icon wsc-section-icon-blue" aria-hidden="true">A</span>
							<?php esc_html_e( 'A. バージョン鮮度', 'site-security-checker' ); ?>
						</h2>
						<p class="wsc-card-desc">
							<?php esc_html_e( 'WordPress本体・PHP・プラグイン／テーマの更新状況を確認します。', 'site-security-checker' ); ?>
						</p>
						<div class="wsc-item-list">
							<?php foreach ( $results['a'] as $item ) : ?>
								<?php WSC_Renderer::render_item( $item ); ?>
							<?php endforeach; ?>
						</div>
						<div class="wsc-card-more">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_VERSION ) ); ?>" class="wsc-detail-link">
								<?php esc_html_e( 'すべての項目を確認する', 'site-security-checker' ); ?> →
							</a>
						</div>
					</section>
				</div>

				<!-- カテゴリB -->
				<section class="wsc-card wsc-category-card">
					<h2 class="wsc-card-title">
						<span class="wsc-section-icon wsc-section-icon-blue" aria-hidden="true">B</span>
						<?php esc_html_e( 'B. ハードニング設定', 'site-security-checker' ); ?>
					</h2>
					<p class="wsc-card-desc">
						<?php esc_html_e( '不正アクセスや情報漏えいを防ぐ設定の診断', 'site-security-checker' ); ?>
					</p>
					<div class="wsc-item-list">
						<?php foreach ( $results['b'] as $item ) : ?>
							<?php WSC_Renderer::render_item( $item ); ?>
						<?php endforeach; ?>
					</div>
					<div class="wsc-card-more">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_HARDENING ) ); ?>" class="wsc-detail-link">
							<?php esc_html_e( 'すべての項目を確認する', 'site-security-checker' ); ?> →
						</a>
					</div>
				</section>
			</div>

			<?php $this->render_footer_note(); ?>
		</div>
		<?php
	}

	/**
	 * Compact summary bar (used on sub-pages).
	 *
	 * @param array $results Diagnostic results.
	 * @param array $counts  Severity counts.
	 */
	private function render_summary_bar( $results, $counts ) {
		$issues = (int) $results['summary']['issues'];
		?>
		<div class="wsc-summary-bar wsc-card" style="display:flex;align-items:center;gap:18px;padding:14px 20px;margin-bottom:16px;flex-wrap:wrap">
			<strong style="font-size:15px;color:var(--wsc-navy)">
				<?php
				if ( 0 === $issues ) {
					esc_html_e( '全項目で問題なし', 'site-security-checker' );
				} else {
					printf(
						/* translators: %d: number of issue items */
						esc_html__( '確認が必要: %d件', 'site-security-checker' ),
						$issues
					);
				}
				?>
			</strong>
			<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;color:var(--wsc-recommended);background:var(--wsc-recommended-bg);border:1px solid var(--wsc-recommended-border)">
				<?php printf( esc_html__( '要対応 %d件', 'site-security-checker' ), (int) $counts['recommended'] ); ?>
			</span>
			<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;color:var(--wsc-attention);background:var(--wsc-attention-bg);border:1px solid var(--wsc-attention-border)">
				<?php printf( esc_html__( '改善推奨 %d件', 'site-security-checker' ), (int) $counts['attention'] ); ?>
			</span>
			<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;color:var(--wsc-good);background:var(--wsc-good-bg);border:1px solid var(--wsc-good-border)">
				<?php printf( esc_html__( '問題なし %d件', 'site-security-checker' ), (int) $counts['good'] ); ?>
			</span>
		</div>
		<?php
	}

	/** 設計方針フッターメッセージ */
	private function render_footer_note() {
		?>
		<p class="wsc-admin-note">
			<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
			<?php esc_html_e( 'このプラグインは診断と情報提供に特化しています。設定の変更や更新の実行は行いません。', 'site-security-checker' ); ?>
			<a href="<?php echo esc_url( 'https://www.cybernote.click/wp-security-checker-guide/' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( '使い方ガイドを見る ↗', 'site-security-checker' ); ?></a>
		</p>
		<?php
	}
}
