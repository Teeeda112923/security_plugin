<?php
/**
 * 専用管理ページ + サブメニュー構成
 *
 * メニュー: ダッシュボード / 診断結果 / バージョン鮮度 / ハードニング設定 /
 *           衛生状態 / 脆弱性アラート Pro（外部サービスの案内）
 * 設計方針: 診断の提示のみ・自動変更なし・外部通信なし。
 * 表示カテゴリはA/B/Cの3分類（Cはb8・b9を衛生状態として再編。診断ロジックは不変）。
 *
 * @package CyberNote_Security_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the dedicated Security Checker admin pages.
 */
class CNSC_Admin_Page {

	const MENU_SLUG        = 'cybernote-security-checker';
	const SLUG_RESULTS     = 'cybernote-security-checker-results';
	const SLUG_VERSION     = 'cybernote-security-checker-version';
	const SLUG_HARDENING   = 'cybernote-security-checker-hardening';
	const SLUG_HYGIENE     = 'cybernote-security-checker-hygiene';
	const SLUG_CVE         = 'cybernote-security-checker-cve';

	/** Category B check IDs that are displayed under Category C (衛生状態). */
	const HYGIENE_IDS = array( 'b8', 'b9' );

	/** Plugin pages that should enqueue the admin CSS. */
	private static $plugin_hooks = array();

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_cnsc_admin_refresh', array( $this, 'ajax_refresh' ) );
	}

	/**
	 * Register top-level menu and all sub-pages.
	 */
	public function register_menu() {
		$hook = add_menu_page(
			__( 'CyberNote Security Checker', 'cybernote-security-checker' ),
			__( 'Security Checker', 'cybernote-security-checker' ),
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
			__( 'Dashboard', 'cybernote-security-checker' ),
			__( 'Dashboard', 'cybernote-security-checker' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard' )
		);
		self::$plugin_hooks[] = $h;

		$h = add_submenu_page(
			self::MENU_SLUG,
			__( 'Diagnostic results', 'cybernote-security-checker' ),
			__( 'Diagnostic results', 'cybernote-security-checker' ),
			'manage_options',
			self::SLUG_RESULTS,
			array( $this, 'render_results' )
		);
		self::$plugin_hooks[] = $h;

		$h = add_submenu_page(
			self::MENU_SLUG,
			__( 'Version freshness', 'cybernote-security-checker' ),
			__( 'Version freshness', 'cybernote-security-checker' ),
			'manage_options',
			self::SLUG_VERSION,
			array( $this, 'render_version' )
		);
		self::$plugin_hooks[] = $h;

		$h = add_submenu_page(
			self::MENU_SLUG,
			__( 'Hardening settings', 'cybernote-security-checker' ),
			__( 'Hardening settings', 'cybernote-security-checker' ),
			'manage_options',
			self::SLUG_HARDENING,
			array( $this, 'render_hardening' )
		);
		self::$plugin_hooks[] = $h;

		$h = add_submenu_page(
			self::MENU_SLUG,
			__( 'Site hygiene', 'cybernote-security-checker' ),
			__( 'Site hygiene', 'cybernote-security-checker' ),
			'manage_options',
			self::SLUG_HYGIENE,
			array( $this, 'render_hygiene' )
		);
		self::$plugin_hooks[] = $h;

		// 脆弱性アラートは外部サービス（CyberNote）で提供する機能の案内ページ。
		$h = add_submenu_page(
			self::MENU_SLUG,
			__( 'Vulnerability Alerts Pro', 'cybernote-security-checker' ),
			__( 'Vulnerability Alerts Pro', 'cybernote-security-checker' ),
			'manage_options',
			self::SLUG_CVE,
			array( $this, 'render_cve' )
		);
		self::$plugin_hooks[] = $h;
	}

	/**
	 * Split Category B results into hardening (B) and hygiene (C) groups for display.
	 * Diagnostic logic is unchanged; this only regroups items by ID for presentation.
	 *
	 * @param array $results Diagnostic results from CNSC_Diagnostics::run().
	 * @return array{0:array,1:array} [ hardening items, hygiene items ]
	 */
	private function split_hardening_hygiene( $results ) {
		$hardening = array();
		$hygiene   = array();
		$b_items   = isset( $results['b'] ) && is_array( $results['b'] ) ? $results['b'] : array();
		foreach ( $b_items as $item ) {
			$id = isset( $item['id'] ) ? $item['id'] : '';
			if ( in_array( $id, self::HYGIENE_IDS, true ) ) {
				$hygiene[] = $item;
			} else {
				$hardening[] = $item;
			}
		}
		return array( $hardening, $hygiene );
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
			'cnsc-dashboard',
			CNSC_PLUGIN_URL . 'assets/css/dashboard.css',
			array( 'dashicons' ),
			CNSC_VERSION
		);
		wp_enqueue_style(
			'cnsc-admin-page',
			CNSC_PLUGIN_URL . 'assets/css/admin-page.css',
			array( 'cnsc-dashboard' ),
			CNSC_VERSION
		);
		wp_enqueue_script(
			'cnsc-guide',
			CNSC_PLUGIN_URL . 'assets/js/cnsc-guide.js',
			array(),
			CNSC_VERSION,
			true
		);
		wp_enqueue_script(
			'cnsc-admin',
			CNSC_PLUGIN_URL . 'assets/js/cnsc-admin.js',
			array( 'cnsc-guide' ),
			CNSC_VERSION,
			true
		);
		wp_localize_script(
			'cnsc-admin',
			'cnscAdminData',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'refreshingText' => __( 'Checking...', 'cybernote-security-checker' ),
			)
		);
	}

	/**
	 * AJAX: re-run diagnostics and return body HTML.
	 */
	public function ajax_refresh() {
		check_ajax_referer( 'cnsc_admin_refresh_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}
		$results = ( new CNSC_Diagnostics() )->run();
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
		$results = ( new CNSC_Diagnostics() )->run();
		$this->page_wrap(
			__( 'Dashboard', 'cybernote-security-checker' ),
			__( 'Check your site settings and version status without sending data externally or changing settings automatically.', 'cybernote-security-checker' ),
			function () use ( $results ) {
				$this->render_body( $results );
			}
		);
	}

	/** 診断結果 — 全12項目をA/B/Cにまとめて1ページに */
	public function render_results() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$results = ( new CNSC_Diagnostics() )->run();
		list( $hardening, $hygiene ) = $this->split_hardening_hygiene( $results );
		$this->page_wrap(
			__( 'Diagnostic results', 'cybernote-security-checker' ),
			__( 'View the results for all diagnostic checks in one place.', 'cybernote-security-checker' ),
			function () use ( $results, $hardening, $hygiene ) {
				$counts = CNSC_Renderer::severity_counts( $results );
				?>
				<div class="wsc-admin-body">
					<?php $this->render_summary_bar( $results, $counts ); ?>
					<div class="wsc-card wsc-category-card" style="padding:20px">
						<h2 class="wsc-card-title" style="margin-bottom:8px"><?php esc_html_e( 'A. Version freshness', 'cybernote-security-checker' ); ?></h2>
						<div class="wsc-item-list" style="margin-bottom:24px">
							<?php foreach ( $results['a'] as $item ) : ?>
								<?php CNSC_Renderer::render_item( $item ); ?>
							<?php endforeach; ?>
						</div>
						<h2 class="wsc-card-title" style="margin-bottom:8px"><?php esc_html_e( 'B. Hardening settings', 'cybernote-security-checker' ); ?></h2>
						<div class="wsc-item-list" style="margin-bottom:24px">
							<?php foreach ( $hardening as $item ) : ?>
								<?php CNSC_Renderer::render_item( $item ); ?>
							<?php endforeach; ?>
						</div>
						<h2 class="wsc-card-title" style="margin-bottom:8px"><?php esc_html_e( 'C. Site hygiene', 'cybernote-security-checker' ); ?></h2>
						<div class="wsc-item-list">
							<?php foreach ( $hygiene as $item ) : ?>
								<?php CNSC_Renderer::render_item( $item ); ?>
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
		$results = ( new CNSC_Diagnostics() )->run();
		$this->page_wrap(
			__( 'Version freshness', 'cybernote-security-checker' ),
			__( 'Check update status for WordPress core, PHP, plugins, and themes.', 'cybernote-security-checker' ),
			function () use ( $results ) {
				?>
				<div class="wsc-admin-body">
					<div class="wsc-card wsc-category-card" style="padding:20px">
						<div class="wsc-item-list">
							<?php foreach ( $results['a'] as $item ) : ?>
								<?php CNSC_Renderer::render_item( $item ); ?>
							<?php endforeach; ?>
						</div>
					</div>
					<?php $this->render_footer_note(); ?>
				</div>
				<?php
			}
		);
	}

	/** ハードニング設定 — カテゴリB（b1〜b7） */
	public function render_hardening() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$results = ( new CNSC_Diagnostics() )->run();
		list( $hardening ) = $this->split_hardening_hygiene( $results );
		$this->page_wrap(
			__( 'Hardening settings', 'cybernote-security-checker' ),
			__( 'Check basic settings that help protect your site against attacks.', 'cybernote-security-checker' ),
			function () use ( $hardening ) {
				?>
				<div class="wsc-admin-body">
					<div class="wsc-card wsc-category-card" style="padding:20px">
						<div class="wsc-item-list">
							<?php foreach ( $hardening as $item ) : ?>
								<?php CNSC_Renderer::render_item( $item ); ?>
							<?php endforeach; ?>
						</div>
					</div>
					<?php $this->render_footer_note(); ?>
				</div>
				<?php
			}
		);
	}

	/** 衛生状態 — カテゴリC（未使用のプラグイン・テーマ / セキュリティキー） */
	public function render_hygiene() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$results = ( new CNSC_Diagnostics() )->run();
		list( , $hygiene ) = $this->split_hardening_hygiene( $results );
		$this->page_wrap(
			__( 'Site hygiene', 'cybernote-security-checker' ),
			__( 'Check unused plugins and themes and the basic settings that protect authentication.', 'cybernote-security-checker' ),
			function () use ( $hygiene ) {
				?>
				<div class="wsc-admin-body">
					<div class="wsc-card wsc-category-card" style="padding:20px">
						<div class="wsc-item-list">
							<?php foreach ( $hygiene as $item ) : ?>
								<?php CNSC_Renderer::render_item( $item ); ?>
							<?php endforeach; ?>
						</div>
					</div>
					<?php $this->render_footer_note(); ?>
				</div>
				<?php
			}
		);
	}

	/** 脆弱性アラート Pro — 外部サービス CyberNote の案内ページ（ロック解除ではない） */
	public function render_cve() {
		$this->page_wrap(
			__( 'Vulnerability Alerts Pro', 'cybernote-security-checker' ),
			__( 'Learn about the service that checks your installed plugins and themes against known vulnerability information.', 'cybernote-security-checker' ),
			array( $this, 'render_cve_info_body' )
		);
	}

	/**
	 * 脆弱性アラートの案内本体。
	 *
	 * 外部サービス（CyberNote）の紹介ページ。CVE照合・ライセンス判定・ロック解除の
	 * 処理は一切含まない。プレビューは実在名を使わない静的な表示例のみ。
	 */
	public function render_cve_info_body() {
		// 静的な表示例（架空名）。実データではない。
		$samples = array(
			array(
				'name'  => __( 'Form plugin A', 'cybernote-security-checker' ),
				'level' => 'high',
				'sev'   => __( 'Severity: High', 'cybernote-security-checker' ),
				'hint'  => __( 'Suggested action: Update soon', 'cybernote-security-checker' ),
			),
			array(
				'name'  => __( 'SEO helper plugin B', 'cybernote-security-checker' ),
				'level' => 'mid',
				'sev'   => __( 'Severity: Medium', 'cybernote-security-checker' ),
				'hint'  => __( 'Suggested action: Review the update', 'cybernote-security-checker' ),
			),
			array(
				'name'  => __( 'Booking plugin C', 'cybernote-security-checker' ),
				'level' => 'low',
				'sev'   => __( 'Severity: Low', 'cybernote-security-checker' ),
				'hint'  => __( 'Suggested action: Review at the next maintenance window', 'cybernote-security-checker' ),
			),
		);
		?>
		<div class="wsc-admin-body wsc-pro-body">

			<!-- 静的なプレビュー（表示例・ぼかし） -->
			<div class="wsc-cve-preview-wrap">
				<div class="wsc-cve-preview" aria-hidden="true">
					<?php foreach ( $samples as $s ) : ?>
						<div class="wsc-cve-row">
							<span class="wsc-cve-name"><?php echo esc_html( $s['name'] ); ?></span>
							<span class="wsc-cve-sev wsc-cve-sev-<?php echo esc_attr( $s['level'] ); ?>"><?php echo esc_html( $s['sev'] ); ?></span>
							<span class="wsc-cve-hint"><?php echo esc_html( $s['hint'] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<p class="wsc-cve-note"><?php esc_html_e( 'This is a visual example, not an actual diagnostic result.', 'cybernote-security-checker' ); ?></p>
			</div>

			<!-- 外部サービスの案内カード -->
			<div class="wsc-card wsc-pro-card">
				<div class="wsc-pro-badge"><?php esc_html_e( 'CyberNote Pro', 'cybernote-security-checker' ); ?></div>
				<h2 class="wsc-pro-title"><?php esc_html_e( 'Want to know how urgent each update is?', 'cybernote-security-checker' ); ?></h2>
				<p class="wsc-pro-desc">
					<?php esc_html_e( 'The free version checks the status of WordPress, PHP, and your plugins. Pro also checks installed plugins and themes against known vulnerability information so you can see which items to address first.', 'cybernote-security-checker' ); ?>
				</p>
				<p class="wsc-pro-sub">
					<?php esc_html_e( 'This feature is provided as an external service. The free plugin does not include CVE matching or license-unlocking code.', 'cybernote-security-checker' ); ?>
				</p>
				<a href="<?php echo esc_url( 'https://www.cybernote.click/wp-security-checker-guide/' ); ?>" class="button button-primary wsc-pro-cta" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'View vulnerability alerts', 'cybernote-security-checker' ); ?> ↗
				</a>
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
		<?php
	}

	/**
	 * Render the main dashboard body (summary hero + priority + categories).
	 *
	 * @param array $results Diagnostic results from CNSC_Diagnostics::run().
	 */
	private function render_body( $results ) {
		$counts         = CNSC_Renderer::severity_counts( $results );
		$issues         = (int) $results['summary']['issues'];
		$total          = (int) $results['summary']['total'];
		$priority_items = CNSC_Renderer::priority_items( $results, 5 );
		$primary_status = $counts['recommended'] > 0 ? 'recommended' : ( $counts['attention'] > 0 ? 'attention' : 'good' );

		// 表示だけA/B/Cに再編（診断ロジックは不変）。
		list( $hardening, $hygiene ) = $this->split_hardening_hygiene( $results );

		// conic-gradient の角度を問題件数の割合から計算（全12項目）。
		$safe_pct  = $total > 0 ? ( $counts['good'] / $total ) : 1;
		$safe_deg  = round( $safe_pct * 360 );
		$issue_deg = 360 - $safe_deg;
		?>
		<div id="wsc-admin-body" class="wsc-admin-body" aria-live="polite">

			<!-- ツールバー: 最終診断 + 再診断 -->
			<div class="wsc-dash-toolbar">
				<span class="wsc-last-run">
					<span class="dashicons dashicons-clock" aria-hidden="true"></span>
					<?php
					printf(
						/* translators: %s: current date and time */
						esc_html__( 'Last checked: %s', 'cybernote-security-checker' ),
						esc_html( current_time( 'Y-m-d H:i' ) )
					);
					?>
				</span>
				<button
					type="button"
					class="button button-primary wsc-refresh-btn"
					onclick="cnscAdminRefresh(this)"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'cnsc_admin_refresh_nonce' ) ); ?>"
				>
					<span class="dashicons dashicons-update" aria-hidden="true"></span>
					<?php esc_html_e( 'Run diagnostics again', 'cybernote-security-checker' ); ?>
				</button>
			</div>

			<!-- サマリーカード -->
			<section class="wsc-hero wsc-card wsc-hero-<?php echo esc_attr( $primary_status ); ?>">
				<div class="wsc-hero-status" aria-hidden="true">
					<div class="wsc-hero-ring" style="background:conic-gradient(var(--wsc-blue) 0 <?php echo (int) $issue_deg; ?>deg, var(--wsc-border) <?php echo (int) $issue_deg; ?>deg 360deg)">
						<div class="wsc-ring-inner">
							<span class="wsc-ring-label"><?php esc_html_e( 'Items needing attention', 'cybernote-security-checker' ); ?></span>
							<span class="wsc-ring-value"><?php echo (int) $issues; ?><small>/ <?php echo (int) $total; ?></small></span>
							<span class="wsc-ring-unit"><?php esc_html_e( 'items', 'cybernote-security-checker' ); ?></span>
						</div>
					</div>
				</div>

				<div class="wsc-hero-copy">
					<h2><?php esc_html_e( 'Site security status', 'cybernote-security-checker' ); ?></h2>
					<p>
						<?php
						if ( 0 === $issues ) {
							esc_html_e( 'Your basic settings look good. Run the checks regularly.', 'cybernote-security-checker' );
						} else {
							esc_html_e( 'Some items need attention. Start with the items marked Action required.', 'cybernote-security-checker' );
						}
						?>
					</p>
				</div>

				<div class="wsc-hero-counts" aria-label="<?php esc_attr_e( 'Diagnostic result breakdown', 'cybernote-security-checker' ); ?>">
					<div class="wsc-count-card wsc-count-recommended">
						<span class="wsc-count-icon">!</span>
						<span class="wsc-count-label"><?php esc_html_e( 'Action required', 'cybernote-security-checker' ); ?></span>
						<strong><?php echo esc_html( $counts['recommended'] ); ?> <?php esc_html_e( 'items', 'cybernote-security-checker' ); ?></strong>
					</div>
					<div class="wsc-count-card wsc-count-attention">
						<span class="wsc-count-icon">△</span>
						<span class="wsc-count-label"><?php esc_html_e( 'Recommended improvement', 'cybernote-security-checker' ); ?></span>
						<strong><?php echo esc_html( $counts['attention'] ); ?> <?php esc_html_e( 'items', 'cybernote-security-checker' ); ?></strong>
					</div>
					<div class="wsc-count-card wsc-count-good">
						<span class="wsc-count-icon">✓</span>
						<span class="wsc-count-label"><?php esc_html_e( 'No issues', 'cybernote-security-checker' ); ?></span>
						<strong><?php echo esc_html( $counts['good'] ); ?> <?php esc_html_e( 'items', 'cybernote-security-checker' ); ?></strong>
					</div>
				</div>
			</section>

			<!-- 優先対応カード（全幅） -->
			<section class="wsc-card wsc-priority-card">
				<div class="wsc-card-heading-row">
					<div>
						<h2 class="wsc-card-title">
							<span class="wsc-section-icon wsc-section-icon-alert" aria-hidden="true">!</span>
							<?php esc_html_e( 'Items requiring priority action', 'cybernote-security-checker' ); ?>
						</h2>
						<p class="wsc-card-desc"><?php esc_html_e( 'Leaving these unresolved increases risk. Review them as soon as possible.', 'cybernote-security-checker' ); ?></p>
					</div>
					<?php if ( $issues > 0 ) : ?>
						<span class="wsc-mini-count"><?php echo esc_html( $issues ); ?> <?php esc_html_e( 'items', 'cybernote-security-checker' ); ?></span>
					<?php endif; ?>
				</div>

				<?php if ( empty( $priority_items ) ) : ?>
					<div class="wsc-empty-state">
						<span class="wsc-empty-icon" aria-hidden="true">✓</span>
						<p><?php esc_html_e( 'No items require immediate action.', 'cybernote-security-checker' ); ?></p>
					</div>
				<?php else : ?>
					<div class="wsc-priority-list">
						<?php foreach ( $priority_items as $item ) : ?>
							<?php CNSC_Renderer::render_item( $item, array( 'compact' => true ) ); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>

			<!-- A / B / C カテゴリ（3カラム） -->
			<div class="wsc-cat-grid">

				<section class="wsc-card wsc-category-card">
					<div class="wsc-cat-head">
						<h2 class="wsc-card-title">
							<span class="wsc-section-icon wsc-section-icon-blue" aria-hidden="true">A</span>
							<?php esc_html_e( 'A. Version freshness', 'cybernote-security-checker' ); ?>
						</h2>
						<span class="wsc-cat-count"><?php echo (int) count( $results['a'] ); ?> <?php esc_html_e( 'items', 'cybernote-security-checker' ); ?></span>
					</div>
					<p class="wsc-card-desc"><?php esc_html_e( 'Update status for WordPress core, PHP, plugins, and themes', 'cybernote-security-checker' ); ?></p>
					<div class="wsc-item-list">
						<?php foreach ( $results['a'] as $item ) : ?>
							<?php CNSC_Renderer::render_item( $item ); ?>
						<?php endforeach; ?>
					</div>
					<div class="wsc-card-more">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_VERSION ) ); ?>" class="wsc-detail-link">
							<?php esc_html_e( 'Review all items', 'cybernote-security-checker' ); ?> →
						</a>
					</div>
				</section>

				<section class="wsc-card wsc-category-card">
					<div class="wsc-cat-head">
						<h2 class="wsc-card-title">
							<span class="wsc-section-icon wsc-section-icon-blue" aria-hidden="true">B</span>
							<?php esc_html_e( 'B. Hardening settings', 'cybernote-security-checker' ); ?>
						</h2>
						<span class="wsc-cat-count"><?php echo (int) count( $hardening ); ?> <?php esc_html_e( 'items', 'cybernote-security-checker' ); ?></span>
					</div>
					<p class="wsc-card-desc"><?php esc_html_e( 'Checks for settings that help prevent unauthorized access and data exposure', 'cybernote-security-checker' ); ?></p>
					<div class="wsc-item-list">
						<?php foreach ( $hardening as $item ) : ?>
							<?php CNSC_Renderer::render_item( $item ); ?>
						<?php endforeach; ?>
					</div>
					<div class="wsc-card-more">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_HARDENING ) ); ?>" class="wsc-detail-link">
							<?php esc_html_e( 'Review all items', 'cybernote-security-checker' ); ?> →
						</a>
					</div>
				</section>

				<section class="wsc-card wsc-category-card">
					<div class="wsc-cat-head">
						<h2 class="wsc-card-title">
							<span class="wsc-section-icon wsc-section-icon-blue" aria-hidden="true">C</span>
							<?php esc_html_e( 'C. Site hygiene', 'cybernote-security-checker' ); ?>
						</h2>
						<span class="wsc-cat-count"><?php echo (int) count( $hygiene ); ?> <?php esc_html_e( 'items', 'cybernote-security-checker' ); ?></span>
					</div>
					<p class="wsc-card-desc"><?php esc_html_e( 'Review unused extensions and the status of basic protections.', 'cybernote-security-checker' ); ?></p>
					<div class="wsc-item-list">
						<?php foreach ( $hygiene as $item ) : ?>
							<?php CNSC_Renderer::render_item( $item ); ?>
						<?php endforeach; ?>
					</div>
					<div class="wsc-card-more">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_HYGIENE ) ); ?>" class="wsc-detail-link">
							<?php esc_html_e( 'Review all items', 'cybernote-security-checker' ); ?> →
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
					esc_html_e( 'No issues found', 'cybernote-security-checker' );
				} else {
					printf(
						/* translators: %d: number of issue items */
						esc_html__( '%d item(s) need attention', 'cybernote-security-checker' ),
						$issues
					);
				}
				?>
			</strong>
			<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;color:var(--wsc-recommended);background:var(--wsc-recommended-bg);border:1px solid var(--wsc-recommended-border)">
				<?php printf( esc_html__( 'Action required: %d', 'cybernote-security-checker' ), (int) $counts['recommended'] ); ?>
			</span>
			<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;color:var(--wsc-attention);background:var(--wsc-attention-bg);border:1px solid var(--wsc-attention-border)">
				<?php printf( esc_html__( 'Recommended improvement: %d', 'cybernote-security-checker' ), (int) $counts['attention'] ); ?>
			</span>
			<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;color:var(--wsc-good);background:var(--wsc-good-bg);border:1px solid var(--wsc-good-border)">
				<?php printf( esc_html__( 'No issues: %d', 'cybernote-security-checker' ), (int) $counts['good'] ); ?>
			</span>
		</div>
		<?php
	}

	/** 設計方針フッターメッセージ */
	private function render_footer_note() {
		?>
		<p class="wsc-admin-note">
			<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
			<?php esc_html_e( 'This plugin provides diagnostics and information only. It does not change settings or perform updates.', 'cybernote-security-checker' ); ?>
			<a href="<?php echo esc_url( 'https://www.cybernote.click/wp-security-checker-guide/' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View the usage guide ↗', 'cybernote-security-checker' ); ?></a>
		</p>
		<?php
	}
}
