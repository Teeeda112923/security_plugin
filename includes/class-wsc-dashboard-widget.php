<?php
/**
 * ダッシュボードウィジェット
 *
 * @package WP_Security_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the Security Checker dashboard widget.
 */
class WSC_Dashboard_Widget {

	public function __construct() {
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'wp_ajax_wsc_refresh', array( $this, 'ajax_refresh' ) );
	}

	public function register_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'wsc_security_checker',
			__( 'セキュリティ診断', 'wp-security-checker' ),
			array( $this, 'render_widget' )
		);
	}

	public function enqueue_styles( $hook ) {
		if ( 'index.php' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'wsc-dashboard',
			WSC_PLUGIN_URL . 'assets/css/dashboard.css',
			array(),
			WSC_VERSION
		);
	}

	public function render_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$results = ( new WSC_Diagnostics() )->run();
		$this->render_html( $results );
	}

	public function ajax_refresh() {
		check_ajax_referer( 'wsc_refresh_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}
		$results = ( new WSC_Diagnostics() )->run();
		$this->render_html( $results );
		wp_die();
	}

	/**
	 * Output the widget HTML.
	 *
	 * @param array $results Diagnostic results from WSC_Diagnostics::run().
	 */
	private function render_html( $results ) {
		$issues         = (int) $results['summary']['issues'];
		$counts         = WSC_Renderer::severity_counts( $results );
		$priority_items = WSC_Renderer::priority_items( $results, 5 );
		?>
		<div class="wsc-widget" id="wsc-widget" aria-live="polite">
			<div class="wsc-widget-head">
				<div class="wsc-widget-brand">
					<span class="wsc-widget-shield" aria-hidden="true">✓</span>
					<div>
						<strong><?php esc_html_e( 'セキュリティ診断', 'wp-security-checker' ); ?></strong>
						<span><?php esc_html_e( 'サイト設定の基本チェック', 'wp-security-checker' ); ?></span>
					</div>
				</div>
				<button
					class="button button-secondary wsc-refresh-btn"
					onclick="wscRefresh(this)"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'wsc_refresh_nonce' ) ); ?>"
				>
					<span class="dashicons dashicons-update" aria-hidden="true"></span>
					<?php esc_html_e( '再診断', 'wp-security-checker' ); ?>
				</button>
			</div>

			<div class="wsc-widget-summary <?php echo $issues > 0 ? 'wsc-has-issues' : 'wsc-all-good'; ?>">
				<div class="wsc-widget-summary-main">
					<span class="wsc-widget-alert" aria-hidden="true"><?php echo 0 === $issues ? '✓' : '!'; ?></span>
					<div>
						<strong>
							<?php
							if ( 0 === $issues ) {
								esc_html_e( '良好です', 'wp-security-checker' );
							} else {
								printf(
									/* translators: %d: number of issues found */
									esc_html__( '要確認 %d件', 'wp-security-checker' ),
									$issues
								);
							}
							?>
						</strong>
						<span><?php esc_html_e( 'まずは要対応の項目から確認してください。', 'wp-security-checker' ); ?></span>
					</div>
				</div>

				<div class="wsc-widget-counts">
					<span class="wsc-widget-chip wsc-chip-recommended"><?php echo esc_html( $counts['recommended'] ); ?> <?php esc_html_e( '要対応', 'wp-security-checker' ); ?></span>
					<span class="wsc-widget-chip wsc-chip-attention"><?php echo esc_html( $counts['attention'] ); ?> <?php esc_html_e( '改善推奨', 'wp-security-checker' ); ?></span>
					<span class="wsc-widget-chip wsc-chip-good"><?php echo esc_html( $counts['good'] ); ?> <?php esc_html_e( '問題なし', 'wp-security-checker' ); ?></span>
				</div>
			</div>

			<div class="wsc-widget-priority">
				<div class="wsc-widget-section-title"><?php esc_html_e( '優先対応が必要な項目', 'wp-security-checker' ); ?></div>
				<?php if ( empty( $priority_items ) ) : ?>
					<div class="wsc-widget-empty"><?php esc_html_e( '確認が必要な項目はありません。', 'wp-security-checker' ); ?></div>
				<?php else : ?>
					<?php foreach ( $priority_items as $item ) : ?>
						<?php WSC_Renderer::render_item( $item, array( 'compact' => true, 'show_message' => false, 'show_action' => false ) ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<div class="wsc-widget-footer">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WSC_Admin_Page::MENU_SLUG ) ); ?>" class="wsc-detail-link">
					<span class="dashicons dashicons-external" aria-hidden="true"></span>
					<?php esc_html_e( '詳細画面を開く', 'wp-security-checker' ); ?>
					<span aria-hidden="true">›</span>
				</a>
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
		</div>

		<script>
		function wscRefresh(btn) {
			var widget = document.getElementById('wsc-widget');
			if ( ! widget ) {
				return;
			}
			btn.disabled = true;
			btn.textContent = '<?php echo esc_js( __( '診断中...', 'wp-security-checker' ) ); ?>';
			var xhr = new XMLHttpRequest();
			xhr.open('POST', '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>');
			xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
			xhr.onload = function() {
				if (xhr.status === 200) {
					widget.outerHTML = xhr.responseText;
				}
			};
			xhr.send('action=wsc_refresh&nonce=' + btn.dataset.nonce);
		}
		</script>
		<?php
	}
}
