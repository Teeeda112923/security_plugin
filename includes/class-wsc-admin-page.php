<?php
/**
 * 専用管理ページ + サブメニュー構成
 *
 * メニュー: ダッシュボード / 診断結果 / バージョン鮮度 / ハードニング設定 /
 *           CVEアラート (Pro) / レポート (Business) / 設定
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
			__( 'WP Security Checker', 'wp-security-checker' ),
			__( 'セキュリティ診断', 'wp-security-checker' ),
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
			__( 'ダッシュボード', 'wp-security-checker' ),
			__( 'ダッシュボード', 'wp-security-checker' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard' )
		);
		self::$plugin_hooks[] = $h;

		$h = add_submenu_page(
			self::MENU_SLUG,
			__( '診断結果', 'wp-security-checker' ),
			__( '診断結果', 'wp-security-checker' ),
			'manage_options',
			self::SLUG_RESULTS,
			array( $this, 'render_results' )
		);
		self::$plugin_hooks[] = $h;

		$h = add_submenu_page(
			self::MENU_SLUG,
			__( 'バージョン鮮度', 'wp-security-checker' ),
			__( 'バージョン鮮度', 'wp-security-checker' ),
			'manage_options',
			self::SLUG_VERSION,
			array( $this, 'render_version' )
		);
		self::$plugin_hooks[] = $h;

		$h = add_submenu_page(
			self::MENU_SLUG,
			__( 'ハードニング設定', 'wp-security-checker' ),
			__( 'ハードニング設定', 'wp-security-checker' ),
			'manage_options',
			self::SLUG_HARDENING,
			array( $this, 'render_hardening' )
		);
		self::$plugin_hooks[] = $h;

		// Pro/Business 予定機能（クリックするとロック画面）。
		$h = add_submenu_page(
			self::MENU_SLUG,
			__( 'CVEアラート', 'wp-security-checker' ),
			/* translators: Pro feature badge appended to menu label */
			__( 'CVEアラート', 'wp-security-checker' ) . ' <span class="wsc-menu-badge">Pro</span>',
			'manage_options',
			self::SLUG_CVE,
			array( $this, 'render_cve_upsell' )
		);
		self::$plugin_hooks[] = $h;

		$h = add_submenu_page(
			self::MENU_SLUG,
			__( 'レポート', 'wp-security-checker' ),
			__( 'レポート', 'wp-security-checker' ) . ' <span class="wsc-menu-badge wsc-menu-badge-biz">Business</span>',
			'manage_options',
			self::SLUG_REPORT,
			array( $this, 'render_report_upsell' )
		);
		self::$plugin_hooks[] = $h;

		$h = add_submenu_page(
			self::MENU_SLUG,
			__( '設定', 'wp-security-checker' ),
			__( '設定', 'wp-security-checker' ),
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
			__( 'ダッシュボード', 'wp-security-checker' ),
			__( 'サイト内の設定とバージョン状態を診断します。外部への通信は行わず、設定の自動変更もしません。', 'wp-security-checker' ),
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
			__( '診断結果', 'wp-security-checker' ),
			__( '全診断項目の結果をまとめて表示します。', 'wp-security-checker' ),
			function () use ( $results ) {
				$counts = WSC_Renderer::severity_counts( $results );
				?>
				<div class="wsc-admin-body">
					<?php $this->render_summary_bar( $results, $counts ); ?>
					<div class="wsc-card wsc-category-card" style="padding:20px">
						<h2 class="wsc-card-title" style="margin-bottom:8px"><?php esc_html_e( 'A. バージョン鮮度', 'wp-security-checker' ); ?></h2>
						<div class="wsc-item-list" style="margin-bottom:24px">
							<?php foreach ( $results['a'] as $item ) : ?>
								<?php WSC_Renderer::render_item( $item ); ?>
							<?php endforeach; ?>
						</div>
						<h2 class="wsc-card-title" style="margin-bottom:8px"><?php esc_html_e( 'B. ハードニング設定', 'wp-security-checker' ); ?></h2>
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
			__( 'バージョン鮮度', 'wp-security-checker' ),
			__( 'WordPress本体・PHP・プラグイン／テーマの更新状況を確認します。', 'wp-security-checker' ),
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
			__( 'ハードニング設定', 'wp-security-checker' ),
			__( 'サイトを攻撃に強くするための基本設定を確認します。', 'wp-security-checker' ),
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

	/** CVEアラート — Pro予定機能のアップセルページ */
	public function render_cve_upsell() {
		$this->page_wrap(
			__( 'CVEアラート', 'wp-security-checker' ),
			'',
			function () {
				?>
				<div class="wsc-admin-body">
					<div class="wsc-card wsc-upsell-card" style="padding:40px 36px;text-align:center;max-width:640px;margin:0 auto">
						<div class="wsc-upsell-badge">Pro</div>
						<h2 class="wsc-upsell-title"><?php esc_html_e( 'CVE日本語アラート', 'wp-security-checker' ); ?></h2>
						<p class="wsc-upsell-desc">
							<?php esc_html_e( '使用中のプラグイン・テーマに既知の脆弱性（CVE）が見つかったとき、「どのプラグインが」「どんな危険で」「今何をすべきか」を平易な日本語で通知します。外部の脆弱性データベースとの突合はPro版でのみ提供予定です。', 'wp-security-checker' ); ?>
						</p>
						<div class="wsc-upsell-features">
							<div class="wsc-upsell-feature"><span>✓</span><?php esc_html_e( '使用プラグイン・テーマのCVE検知', 'wp-security-checker' ); ?></div>
							<div class="wsc-upsell-feature"><span>✓</span><?php esc_html_e( '平易な日本語での危険度・対応手順の提示', 'wp-security-checker' ); ?></div>
							<div class="wsc-upsell-feature"><span>✓</span><?php esc_html_e( '管理画面バナー＋メール通知', 'wp-security-checker' ); ?></div>
							<div class="wsc-upsell-feature"><span>✓</span><?php esc_html_e( '定期チェック（毎日/週次）', 'wp-security-checker' ); ?></div>
						</div>
						<p class="wsc-upsell-coming"><?php esc_html_e( '近日公開予定', 'wp-security-checker' ); ?></p>
					</div>
				</div>
				<?php
			}
		);
	}

	/** レポート — Business予定機能のアップセルページ */
	public function render_report_upsell() {
		$this->page_wrap(
			__( 'レポート', 'wp-security-checker' ),
			'',
			function () {
				?>
				<div class="wsc-admin-body">
					<div class="wsc-card wsc-upsell-card" style="padding:40px 36px;text-align:center;max-width:640px;margin:0 auto">
						<div class="wsc-upsell-badge wsc-upsell-badge-biz">Business</div>
						<h2 class="wsc-upsell-title"><?php esc_html_e( '月次セキュリティレポート', 'wp-security-checker' ); ?></h2>
						<p class="wsc-upsell-desc">
							<?php esc_html_e( '診断結果をPDF形式でまとめて出力し、クライアントや経営者への報告資料として活用できます。複数サイトをまとめて管理する制作会社・フリーランス向けの機能です。', 'wp-security-checker' ); ?>
						</p>
						<div class="wsc-upsell-features">
							<div class="wsc-upsell-feature"><span>✓</span><?php esc_html_e( '診断結果のPDFレポート出力', 'wp-security-checker' ); ?></div>
							<div class="wsc-upsell-feature"><span>✓</span><?php esc_html_e( '複数サイトの一括管理', 'wp-security-checker' ); ?></div>
							<div class="wsc-upsell-feature"><span>✓</span><?php esc_html_e( '月次サマリーメール', 'wp-security-checker' ); ?></div>
						</div>
						<p class="wsc-upsell-coming"><?php esc_html_e( '近日公開予定', 'wp-security-checker' ); ?></p>
					</div>
				</div>
				<?php
			}
		);
	}

	/** 設定 */
	public function render_settings() {
		$this->page_wrap(
			__( '設定', 'wp-security-checker' ),
			__( 'プラグインの表示設定を変更します。', 'wp-security-checker' ),
			function () {
				?>
				<div class="wsc-admin-body">
					<div class="wsc-card wsc-category-card" style="padding:24px">
						<p style="color:var(--wsc-muted);font-size:13px">
							<?php esc_html_e( '現バージョン（無料版）では変更可能な設定はありません。Pro版では通知のオン・オフや診断スケジュールを設定できるようになる予定です。', 'wp-security-checker' ); ?>
						</p>
					</div>
				</div>
				<?php
			}
		);
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
			btn.textContent = '<?php echo esc_js( __( '診断中...', 'wp-security-checker' ) ); ?>';
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
							esc_html_e( 'すべての項目で問題は見つかりませんでした', 'wp-security-checker' );
						} else {
							printf(
								/* translators: 1: number of issue items, 2: total items */
								esc_html__( '%1$d / %2$d 項目で確認が必要です', 'wp-security-checker' ),
								$issues,
								$total
							);
						}
						?>
					</h2>
					<p>
						<?php
						if ( 0 === $issues ) {
							esc_html_e( '現在の基本設定は良好です。定期的に再診断してください。', 'wp-security-checker' );
						} else {
							esc_html_e( 'まずは「要対応」の項目から確認し、対応していきましょう。', 'wp-security-checker' );
						}
						?>
					</p>
				</div>

				<div class="wsc-hero-counts" aria-label="<?php esc_attr_e( '診断結果の内訳', 'wp-security-checker' ); ?>">
					<div class="wsc-count-card wsc-count-recommended">
						<span class="wsc-count-icon">!</span>
						<span class="wsc-count-label"><?php esc_html_e( '要対応', 'wp-security-checker' ); ?></span>
						<strong><?php echo esc_html( $counts['recommended'] ); ?><?php esc_html_e( '件', 'wp-security-checker' ); ?></strong>
					</div>
					<div class="wsc-count-card wsc-count-attention">
						<span class="wsc-count-icon">△</span>
						<span class="wsc-count-label"><?php esc_html_e( '改善推奨', 'wp-security-checker' ); ?></span>
						<strong><?php echo esc_html( $counts['attention'] ); ?><?php esc_html_e( '件', 'wp-security-checker' ); ?></strong>
					</div>
					<div class="wsc-count-card wsc-count-good">
						<span class="wsc-count-icon">✓</span>
						<span class="wsc-count-label"><?php esc_html_e( '問題なし', 'wp-security-checker' ); ?></span>
						<strong><?php echo esc_html( $counts['good'] ); ?><?php esc_html_e( '件', 'wp-security-checker' ); ?></strong>
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
						<?php esc_html_e( '再診断する', 'wp-security-checker' ); ?>
					</button>
					<span class="wsc-last-run">
						<?php
						printf(
							/* translators: %s: current date and time */
							esc_html__( '最終診断: %s', 'wp-security-checker' ),
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
									<?php esc_html_e( '優先対応が必要な項目', 'wp-security-checker' ); ?>
								</h2>
								<p class="wsc-card-desc"><?php esc_html_e( '放置するとリスクが高まります。できるだけ早く対応してください。', 'wp-security-checker' ); ?></p>
							</div>
							<?php if ( $issues > 0 ) : ?>
								<span class="wsc-mini-count"><?php echo esc_html( $issues ); ?><?php esc_html_e( '件', 'wp-security-checker' ); ?></span>
							<?php endif; ?>
						</div>

						<?php if ( empty( $priority_items ) ) : ?>
							<div class="wsc-empty-state">
								<span class="wsc-empty-icon" aria-hidden="true">✓</span>
								<p><?php esc_html_e( '優先対応が必要な項目はありません。', 'wp-security-checker' ); ?></p>
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
							<?php esc_html_e( 'A. バージョン鮮度', 'wp-security-checker' ); ?>
						</h2>
						<p class="wsc-card-desc">
							<?php esc_html_e( 'WordPress本体・PHP・プラグイン／テーマの更新状況を確認します。', 'wp-security-checker' ); ?>
						</p>
						<div class="wsc-item-list">
							<?php foreach ( $results['a'] as $item ) : ?>
								<?php WSC_Renderer::render_item( $item ); ?>
							<?php endforeach; ?>
						</div>
						<div class="wsc-card-more">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_VERSION ) ); ?>" class="wsc-detail-link">
								<?php esc_html_e( 'すべての項目を確認する', 'wp-security-checker' ); ?> →
							</a>
						</div>
					</section>
				</div>

				<!-- カテゴリB -->
				<section class="wsc-card wsc-category-card">
					<h2 class="wsc-card-title">
						<span class="wsc-section-icon wsc-section-icon-blue" aria-hidden="true">B</span>
						<?php esc_html_e( 'B. ハードニング設定', 'wp-security-checker' ); ?>
					</h2>
					<p class="wsc-card-desc">
						<?php esc_html_e( '不正アクセスや情報漏えいを防ぐ設定の診断', 'wp-security-checker' ); ?>
					</p>
					<div class="wsc-item-list">
						<?php foreach ( $results['b'] as $item ) : ?>
							<?php WSC_Renderer::render_item( $item ); ?>
						<?php endforeach; ?>
					</div>
					<div class="wsc-card-more">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_HARDENING ) ); ?>" class="wsc-detail-link">
							<?php esc_html_e( 'すべての項目を確認する', 'wp-security-checker' ); ?> →
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
					esc_html_e( '全項目で問題なし', 'wp-security-checker' );
				} else {
					printf(
						/* translators: %d: number of issue items */
						esc_html__( '確認が必要: %d件', 'wp-security-checker' ),
						$issues
					);
				}
				?>
			</strong>
			<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;color:var(--wsc-recommended);background:var(--wsc-recommended-bg);border:1px solid var(--wsc-recommended-border)">
				<?php printf( esc_html__( '要対応 %d件', 'wp-security-checker' ), (int) $counts['recommended'] ); ?>
			</span>
			<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;color:var(--wsc-attention);background:var(--wsc-attention-bg);border:1px solid var(--wsc-attention-border)">
				<?php printf( esc_html__( '改善推奨 %d件', 'wp-security-checker' ), (int) $counts['attention'] ); ?>
			</span>
			<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;color:var(--wsc-good);background:var(--wsc-good-bg);border:1px solid var(--wsc-good-border)">
				<?php printf( esc_html__( '問題なし %d件', 'wp-security-checker' ), (int) $counts['good'] ); ?>
			</span>
		</div>
		<?php
	}

	/** 設計方針フッターメッセージ */
	private function render_footer_note() {
		?>
		<p class="wsc-admin-note">
			<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
			<?php esc_html_e( 'このプラグインは診断と情報提供に特化しています。設定の変更や更新の実行は行いません。', 'wp-security-checker' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php esc_html_e( '使い方ガイドを見る ↗', 'wp-security-checker' ); ?></a>
		</p>
		<?php
	}
}
