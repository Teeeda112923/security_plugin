<?php
/**
 * 専用管理ページ（左サイドバーのトップレベルメニュー）
 *
 * ダッシュボードウィジェットより広い画面で、診断結果をゆったり一覧表示する。
 * 設計方針どおり、設定項目は持たず診断結果の提示のみ・自動変更なし。
 *
 * @package WP_Security_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the dedicated Security Checker admin page.
 */
class WSC_Admin_Page {

	const MENU_SLUG = 'wp-security-checker';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wsc_admin_refresh', array( $this, 'ajax_refresh' ) );
	}

	/**
	 * Add the top-level menu page.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'セキュリティ診断', 'wp-security-checker' ),
			__( 'セキュリティ診断', 'wp-security-checker' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-shield',
			80
		);
	}

	/**
	 * Enqueue page-specific styles only on this screen.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
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
	 * Render the full admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$results = ( new WSC_Diagnostics() )->run();
		?>
		<div class="wrap wsc-admin-wrap">
			<h1 class="wsc-admin-title">
				<span class="dashicons dashicons-shield"></span>
				<?php esc_html_e( 'セキュリティ診断', 'wp-security-checker' ); ?>
			</h1>
			<p class="wsc-admin-lead">
				<?php esc_html_e( 'サイト内の設定とバージョン状態を診断します。外部への通信は行わず、設定の自動変更もしません。', 'wp-security-checker' ); ?>
			</p>
			<?php $this->render_body( $results ); ?>
		</div>

		<script>
		function wscAdminRefresh(btn) {
			var body = document.getElementById('wsc-admin-body');
			if ( ! body ) {
				return;
			}
			btn.disabled = true;
			btn.textContent = '<?php echo esc_js( __( '診断中...', 'wp-security-checker' ) ); ?>';
			var xhr = new XMLHttpRequest();
			xhr.open('POST', '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>');
			xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
			xhr.onload = function() {
				if (xhr.status === 200) {
					body.outerHTML = xhr.responseText;
				}
			};
			xhr.send('action=wsc_admin_refresh&nonce=' + btn.dataset.nonce);
		}
		</script>
		<?php
	}

	/**
	 * AJAX handler for the "再診断" button.
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

	/**
	 * Render the swappable body (summary + category cards).
	 *
	 * @param array $results Diagnostic results from WSC_Diagnostics::run().
	 */
	private function render_body( $results ) {
		$counts = WSC_Renderer::severity_counts( $results );
		$issues = $results['summary']['issues'];
		?>
		<div id="wsc-admin-body" class="wsc-admin-body">

			<div class="wsc-card wsc-summary-card <?php echo $issues > 0 ? 'wsc-has-issues' : 'wsc-all-good'; ?>">
				<div class="wsc-summary-main">
					<?php if ( 0 === $issues ) : ?>
						<span class="wsc-summary-icon">✓</span>
						<span class="wsc-summary-text"><?php esc_html_e( 'すべての項目で問題は見つかりませんでした', 'wp-security-checker' ); ?></span>
					<?php else : ?>
						<span class="wsc-summary-icon">!</span>
						<span class="wsc-summary-text">
							<?php
							printf(
								/* translators: %d: number of issues found */
								esc_html( _n( '%d件の確認したい項目があります', '%d件の確認したい項目があります', $issues, 'wp-security-checker' ) ),
								(int) $issues
							);
							?>
						</span>
					<?php endif; ?>
				</div>
				<div class="wsc-chips">
					<span class="wsc-chip wsc-chip-recommended">
						<?php
						/* translators: %d: number of "要対応" items */
						printf( esc_html__( '要対応 %d件', 'wp-security-checker' ), (int) $counts['recommended'] );
						?>
					</span>
					<span class="wsc-chip wsc-chip-attention">
						<?php
						/* translators: %d: number of "改善推奨" items */
						printf( esc_html__( '改善推奨 %d件', 'wp-security-checker' ), (int) $counts['attention'] );
						?>
					</span>
					<span class="wsc-chip wsc-chip-good">
						<?php
						/* translators: %d: number of "問題なし" items */
						printf( esc_html__( '問題なし %d件', 'wp-security-checker' ), (int) $counts['good'] );
						?>
					</span>
				</div>
				<div class="wsc-summary-actions">
					<button
						type="button"
						class="button button-primary wsc-refresh-btn"
						onclick="wscAdminRefresh(this)"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'wsc_admin_refresh_nonce' ) ); ?>"
					>
						<?php esc_html_e( '再診断', 'wp-security-checker' ); ?>
					</button>
					<span class="wsc-last-run">
						<?php
						printf(
							/* translators: %s: current date and time */
							esc_html__( '診断日時: %s', 'wp-security-checker' ),
							esc_html( current_time( 'Y-m-d H:i' ) )
						);
						?>
					</span>
				</div>
			</div>

			<div class="wsc-card wsc-category-card">
				<h2 class="wsc-card-title"><?php esc_html_e( 'A. バージョン鮮度', 'wp-security-checker' ); ?></h2>
				<p class="wsc-card-desc"><?php esc_html_e( 'WordPress本体・PHP・プラグイン／テーマの更新状況を確認します。', 'wp-security-checker' ); ?></p>
				<?php foreach ( $results['a'] as $item ) : ?>
					<?php WSC_Renderer::render_item( $item ); ?>
				<?php endforeach; ?>
			</div>

			<div class="wsc-card wsc-category-card">
				<h2 class="wsc-card-title"><?php esc_html_e( 'B. ハードニング設定', 'wp-security-checker' ); ?></h2>
				<p class="wsc-card-desc"><?php esc_html_e( 'サイトを攻撃に強くするための基本設定を確認します。', 'wp-security-checker' ); ?></p>
				<?php foreach ( $results['b'] as $item ) : ?>
					<?php WSC_Renderer::render_item( $item ); ?>
				<?php endforeach; ?>
			</div>

			<p class="wsc-admin-note">
				<?php esc_html_e( '※ この診断は「更新が来ているか」「設定が安全か」までを確認します。その更新が既知の脆弱性の修正かどうかなど、危険度の詳しい判定は今後のPro版で提供予定です。', 'wp-security-checker' ); ?>
			</p>

		</div>
		<?php
	}
}
