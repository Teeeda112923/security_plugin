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
			__( 'セキュリティ診断', 'cybernote-security-checker' ),
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
			__( 'ダッシュボード', 'cybernote-security-checker' ),
			__( 'ダッシュボード', 'cybernote-security-checker' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard' )
		);
		self::$plugin_hooks[] = $h;

		$h = add_submenu_page(
			self::MENU_SLUG,
			__( '診断結果', 'cybernote-security-checker' ),
			__( '診断結果', 'cybernote-security-checker' ),
			'manage_options',
			self::SLUG_RESULTS,
			array( $this, 'render_results' )
		);
		self::$plugin_hooks[] = $h;

		$h = add_submenu_page(
			self::MENU_SLUG,
			__( 'バージョン鮮度', 'cybernote-security-checker' ),
			__( 'バージョン鮮度', 'cybernote-security-checker' ),
			'manage_options',
			self::SLUG_VERSION,
			array( $this, 'render_version' )
		);
		self::$plugin_hooks[] = $h;

		$h = add_submenu_page(
			self::MENU_SLUG,
			__( 'ハードニング設定', 'cybernote-security-checker' ),
			__( 'ハードニング設定', 'cybernote-security-checker' ),
			'manage_options',
			self::SLUG_HARDENING,
			array( $this, 'render_hardening' )
		);
		self::$plugin_hooks[] = $h;

		$h = add_submenu_page(
			self::MENU_SLUG,
			__( '衛生状態', 'cybernote-security-checker' ),
			__( '衛生状態', 'cybernote-security-checker' ),
			'manage_options',
			self::SLUG_HYGIENE,
			array( $this, 'render_hygiene' )
		);
		self::$plugin_hooks[] = $h;

		// 脆弱性アラートは外部サービス（CyberNote）で提供する機能の案内ページ。
		$h = add_submenu_page(
			self::MENU_SLUG,
			__( '脆弱性アラート Pro', 'cybernote-security-checker' ),
			__( '脆弱性アラート Pro', 'cybernote-security-checker' ),
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
				'refreshingText' => __( '診断中...', 'cybernote-security-checker' ),
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
			__( 'ダッシュボード', 'cybernote-security-checker' ),
			__( 'サイト内の設定とバージョン状態を診断します。外部への通信は行わず、設定の自動変更もしません。', 'cybernote-security-checker' ),
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
			__( '診断結果', 'cybernote-security-checker' ),
			__( '全診断項目の結果をまとめて表示します。', 'cybernote-security-checker' ),
			function () use ( $results, $hardening, $hygiene ) {
				$counts = CNSC_Renderer::severity_counts( $results );
				?>
				<div class="wsc-admin-body">
					<?php $this->render_summary_bar( $results, $counts ); ?>
					<div class="wsc-card wsc-category-card" style="padding:20px">
						<h2 class="wsc-card-title" style="margin-bottom:8px"><?php esc_html_e( 'A. バージョン鮮度', 'cybernote-security-checker' ); ?></h2>
						<div class="wsc-item-list" style="margin-bottom:24px">
							<?php foreach ( $results['a'] as $item ) : ?>
								<?php CNSC_Renderer::render_item( $item ); ?>
							<?php endforeach; ?>
						</div>
						<h2 class="wsc-card-title" style="margin-bottom:8px"><?php esc_html_e( 'B. ハードニング設定', 'cybernote-security-checker' ); ?></h2>
						<div class="wsc-item-list" style="margin-bottom:24px">
							<?php foreach ( $hardening as $item ) : ?>
								<?php CNSC_Renderer::render_item( $item ); ?>
							<?php endforeach; ?>
						</div>
						<h2 class="wsc-card-title" style="margin-bottom:8px"><?php esc_html_e( 'C. 衛生状態', 'cybernote-security-checker' ); ?></h2>
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
			__( 'バージョン鮮度', 'cybernote-security-checker' ),
			__( 'WordPress本体・PHP・プラグイン／テーマの更新状況を確認します。', 'cybernote-security-checker' ),
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
			__( 'ハードニング設定', 'cybernote-security-checker' ),
			__( 'サイトを攻撃に強くするための基本設定を確認します。', 'cybernote-security-checker' ),
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
			__( '衛生状態', 'cybernote-security-checker' ),
			__( '使っていないプラグイン・テーマや、認証まわりの基本設定を確認します。', 'cybernote-security-checker' ),
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
			__( '脆弱性アラート Pro', 'cybernote-security-checker' ),
			__( '使用中のプラグイン・テーマを既知の脆弱性情報と照合する機能の案内です。', 'cybernote-security-checker' ),
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
				'name'  => __( 'フォームプラグインA', 'cybernote-security-checker' ),
				'level' => 'high',
				'sev'   => __( '深刻度：高', 'cybernote-security-checker' ),
				'hint'  => __( '対応目安：早めの更新を推奨', 'cybernote-security-checker' ),
			),
			array(
				'name'  => __( 'SEO補助プラグインB', 'cybernote-security-checker' ),
				'level' => 'mid',
				'sev'   => __( '深刻度：中', 'cybernote-security-checker' ),
				'hint'  => __( '対応目安：更新内容を確認', 'cybernote-security-checker' ),
			),
			array(
				'name'  => __( '予約管理プラグインC', 'cybernote-security-checker' ),
				'level' => 'low',
				'sev'   => __( '深刻度：低', 'cybernote-security-checker' ),
				'hint'  => __( '対応目安：次回メンテナンス時に確認', 'cybernote-security-checker' ),
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
				<p class="wsc-cve-note"><?php esc_html_e( '※これは表示例です。実際の診断結果ではありません。', 'cybernote-security-checker' ); ?></p>
			</div>

			<!-- 外部サービスの案内カード -->
			<div class="wsc-card wsc-pro-card">
				<div class="wsc-pro-badge"><?php esc_html_e( 'CyberNote Pro', 'cybernote-security-checker' ); ?></div>
				<h2 class="wsc-pro-title"><?php esc_html_e( '更新の「緊急度」まで知りたい方へ', 'cybernote-security-checker' ); ?></h2>
				<p class="wsc-pro-desc">
					<?php esc_html_e( '無料版では、WordPress・PHP・プラグインの状態を診断できます。Proではさらに、使用中のプラグイン・テーマを既知の脆弱性情報と照合し、どれを優先して対応すべきかを日本語で確認できます。', 'cybernote-security-checker' ); ?>
				</p>
				<p class="wsc-pro-sub">
					<?php esc_html_e( 'この機能は外部サービスとして提供されます。無料版のプラグイン内には、CVE照合機能やライセンス解除機能は含まれていません。', 'cybernote-security-checker' ); ?>
				</p>
				<a href="<?php echo esc_url( 'https://www.cybernote.click/wp-security-checker-guide/' ); ?>" class="button button-primary wsc-pro-cta" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'CVEアラートを見る', 'cybernote-security-checker' ); ?> ↗
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
						esc_html__( '最終診断: %s', 'cybernote-security-checker' ),
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
					<?php esc_html_e( '再診断する', 'cybernote-security-checker' ); ?>
				</button>
			</div>

			<!-- サマリーカード -->
			<section class="wsc-hero wsc-card wsc-hero-<?php echo esc_attr( $primary_status ); ?>">
				<div class="wsc-hero-status" aria-hidden="true">
					<div class="wsc-hero-ring" style="background:conic-gradient(var(--wsc-blue) 0 <?php echo (int) $issue_deg; ?>deg, var(--wsc-border) <?php echo (int) $issue_deg; ?>deg 360deg)">
						<div class="wsc-ring-inner">
							<span class="wsc-ring-label"><?php esc_html_e( '確認が必要な項目', 'cybernote-security-checker' ); ?></span>
							<span class="wsc-ring-value"><?php echo (int) $issues; ?><small>/ <?php echo (int) $total; ?></small></span>
							<span class="wsc-ring-unit"><?php esc_html_e( '項目', 'cybernote-security-checker' ); ?></span>
						</div>
					</div>
				</div>

				<div class="wsc-hero-copy">
					<h2><?php esc_html_e( 'サイトのセキュリティ状態', 'cybernote-security-checker' ); ?></h2>
					<p>
						<?php
						if ( 0 === $issues ) {
							esc_html_e( '現在の基本設定は良好です。定期的に再診断してください。', 'cybernote-security-checker' );
						} else {
							esc_html_e( '確認が必要な項目があります。まずは「要対応」から確認しましょう。', 'cybernote-security-checker' );
						}
						?>
					</p>
				</div>

				<div class="wsc-hero-counts" aria-label="<?php esc_attr_e( '診断結果の内訳', 'cybernote-security-checker' ); ?>">
					<div class="wsc-count-card wsc-count-recommended">
						<span class="wsc-count-icon">!</span>
						<span class="wsc-count-label"><?php esc_html_e( '要対応', 'cybernote-security-checker' ); ?></span>
						<strong><?php echo esc_html( $counts['recommended'] ); ?><?php esc_html_e( '件', 'cybernote-security-checker' ); ?></strong>
					</div>
					<div class="wsc-count-card wsc-count-attention">
						<span class="wsc-count-icon">△</span>
						<span class="wsc-count-label"><?php esc_html_e( '改善推奨', 'cybernote-security-checker' ); ?></span>
						<strong><?php echo esc_html( $counts['attention'] ); ?><?php esc_html_e( '件', 'cybernote-security-checker' ); ?></strong>
					</div>
					<div class="wsc-count-card wsc-count-good">
						<span class="wsc-count-icon">✓</span>
						<span class="wsc-count-label"><?php esc_html_e( '問題なし', 'cybernote-security-checker' ); ?></span>
						<strong><?php echo esc_html( $counts['good'] ); ?><?php esc_html_e( '件', 'cybernote-security-checker' ); ?></strong>
					</div>
				</div>
			</section>

			<!-- 優先対応カード（全幅） -->
			<section class="wsc-card wsc-priority-card">
				<div class="wsc-card-heading-row">
					<div>
						<h2 class="wsc-card-title">
							<span class="wsc-section-icon wsc-section-icon-alert" aria-hidden="true">!</span>
							<?php esc_html_e( '優先対応が必要な項目', 'cybernote-security-checker' ); ?>
						</h2>
						<p class="wsc-card-desc"><?php esc_html_e( '放置するとリスクが高まります。できるだけ早く確認してください。', 'cybernote-security-checker' ); ?></p>
					</div>
					<?php if ( $issues > 0 ) : ?>
						<span class="wsc-mini-count"><?php echo esc_html( $issues ); ?><?php esc_html_e( '件', 'cybernote-security-checker' ); ?></span>
					<?php endif; ?>
				</div>

				<?php if ( empty( $priority_items ) ) : ?>
					<div class="wsc-empty-state">
						<span class="wsc-empty-icon" aria-hidden="true">✓</span>
						<p><?php esc_html_e( 'すぐに対応が必要な項目はありません。', 'cybernote-security-checker' ); ?></p>
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
							<?php esc_html_e( 'A. バージョン鮮度', 'cybernote-security-checker' ); ?>
						</h2>
						<span class="wsc-cat-count"><?php echo (int) count( $results['a'] ); ?><?php esc_html_e( '項目', 'cybernote-security-checker' ); ?></span>
					</div>
					<p class="wsc-card-desc"><?php esc_html_e( 'WordPress本体・PHP・プラグイン／テーマの更新状況', 'cybernote-security-checker' ); ?></p>
					<div class="wsc-item-list">
						<?php foreach ( $results['a'] as $item ) : ?>
							<?php CNSC_Renderer::render_item( $item ); ?>
						<?php endforeach; ?>
					</div>
					<div class="wsc-card-more">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_VERSION ) ); ?>" class="wsc-detail-link">
							<?php esc_html_e( 'すべての項目を確認する', 'cybernote-security-checker' ); ?> →
						</a>
					</div>
				</section>

				<section class="wsc-card wsc-category-card">
					<div class="wsc-cat-head">
						<h2 class="wsc-card-title">
							<span class="wsc-section-icon wsc-section-icon-blue" aria-hidden="true">B</span>
							<?php esc_html_e( 'B. ハードニング設定', 'cybernote-security-checker' ); ?>
						</h2>
						<span class="wsc-cat-count"><?php echo (int) count( $hardening ); ?><?php esc_html_e( '項目', 'cybernote-security-checker' ); ?></span>
					</div>
					<p class="wsc-card-desc"><?php esc_html_e( '不正アクセスや情報漏えいを防ぐ設定の診断', 'cybernote-security-checker' ); ?></p>
					<div class="wsc-item-list">
						<?php foreach ( $hardening as $item ) : ?>
							<?php CNSC_Renderer::render_item( $item ); ?>
						<?php endforeach; ?>
					</div>
					<div class="wsc-card-more">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_HARDENING ) ); ?>" class="wsc-detail-link">
							<?php esc_html_e( 'すべての項目を確認する', 'cybernote-security-checker' ); ?> →
						</a>
					</div>
				</section>

				<section class="wsc-card wsc-category-card">
					<div class="wsc-cat-head">
						<h2 class="wsc-card-title">
							<span class="wsc-section-icon wsc-section-icon-blue" aria-hidden="true">C</span>
							<?php esc_html_e( 'C. 衛生状態', 'cybernote-security-checker' ); ?>
						</h2>
						<span class="wsc-cat-count"><?php echo (int) count( $hygiene ); ?><?php esc_html_e( '項目', 'cybernote-security-checker' ); ?></span>
					</div>
					<p class="wsc-card-desc"><?php esc_html_e( '不要な拡張の放置や、対策の状況を確認します。', 'cybernote-security-checker' ); ?></p>
					<div class="wsc-item-list">
						<?php foreach ( $hygiene as $item ) : ?>
							<?php CNSC_Renderer::render_item( $item ); ?>
						<?php endforeach; ?>
					</div>
					<div class="wsc-card-more">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_HYGIENE ) ); ?>" class="wsc-detail-link">
							<?php esc_html_e( 'すべての項目を確認する', 'cybernote-security-checker' ); ?> →
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
					esc_html_e( '全項目で問題なし', 'cybernote-security-checker' );
				} else {
					printf(
						/* translators: %d: number of issue items */
						esc_html__( '確認が必要: %d件', 'cybernote-security-checker' ),
						$issues
					);
				}
				?>
			</strong>
			<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;color:var(--wsc-recommended);background:var(--wsc-recommended-bg);border:1px solid var(--wsc-recommended-border)">
				<?php printf( esc_html__( '要対応 %d件', 'cybernote-security-checker' ), (int) $counts['recommended'] ); ?>
			</span>
			<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;color:var(--wsc-attention);background:var(--wsc-attention-bg);border:1px solid var(--wsc-attention-border)">
				<?php printf( esc_html__( '改善推奨 %d件', 'cybernote-security-checker' ), (int) $counts['attention'] ); ?>
			</span>
			<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;color:var(--wsc-good);background:var(--wsc-good-bg);border:1px solid var(--wsc-good-border)">
				<?php printf( esc_html__( '問題なし %d件', 'cybernote-security-checker' ), (int) $counts['good'] ); ?>
			</span>
		</div>
		<?php
	}

	/** 設計方針フッターメッセージ */
	private function render_footer_note() {
		?>
		<p class="wsc-admin-note">
			<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
			<?php esc_html_e( 'このプラグインは診断と情報提供に特化しています。設定の変更や更新の実行は行いません。', 'cybernote-security-checker' ); ?>
			<a href="<?php echo esc_url( 'https://www.cybernote.click/wp-security-checker-guide/' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( '使い方ガイドを見る ↗', 'cybernote-security-checker' ); ?></a>
		</p>
		<?php
	}
}
