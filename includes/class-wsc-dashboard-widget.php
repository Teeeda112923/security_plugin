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
		$issues = $results['summary']['issues'];
		$total  = $results['summary']['total'];
		?>
		<div class="wsc-widget" id="wsc-widget">

			<div class="wsc-summary <?php echo $issues > 0 ? 'wsc-has-issues' : 'wsc-all-good'; ?>">
				<?php if ( 0 === $issues ) : ?>
					<span class="wsc-summary-icon">✓</span>
					<?php esc_html_e( 'すべての項目で問題は見つかりませんでした', 'wp-security-checker' ); ?>
				<?php else : ?>
					<span class="wsc-summary-icon">!</span>
					<?php
					printf(
						/* translators: %d: number of issues found */
						esc_html( _n( '%d件の問題が見つかりました', '%d件の問題が見つかりました', $issues, 'wp-security-checker' ) ),
						(int) $issues
					);
					?>
				<?php endif; ?>
			</div>

			<div class="wsc-section">
				<h4 class="wsc-section-title"><?php esc_html_e( 'A. バージョン鮮度', 'wp-security-checker' ); ?></h4>
				<?php foreach ( $results['a'] as $item ) : ?>
					<?php $this->render_item( $item, $results ); ?>
				<?php endforeach; ?>
			</div>

			<div class="wsc-section">
				<h4 class="wsc-section-title"><?php esc_html_e( 'B. ハードニング設定', 'wp-security-checker' ); ?></h4>
				<?php foreach ( $results['b'] as $item ) : ?>
					<?php $this->render_item( $item, $results ); ?>
				<?php endforeach; ?>
			</div>

			<div class="wsc-footer">
				<button
					class="button button-secondary wsc-refresh-btn"
					onclick="wscRefresh(this)"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'wsc_refresh_nonce' ) ); ?>"
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

		<script>
		function wscRefresh(btn) {
			var widget = document.getElementById('wsc-widget');
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

	/**
	 * Render a single diagnostic item row.
	 *
	 * @param array $item    Check result array.
	 * @param array $results Full results (for context like plugin names).
	 */
	private function render_item( $item, $results ) {
		$icons = array(
			'good'        => '<span class="wsc-icon wsc-good">✓</span>',
			'attention'   => '<span class="wsc-icon wsc-attention">△</span>',
			'recommended' => '<span class="wsc-icon wsc-recommended">×</span>',
		);
		$icon = isset( $icons[ $item['status'] ] ) ? $icons[ $item['status'] ] : '';

		$status_labels = array(
			'good'        => __( '問題なし', 'wp-security-checker' ),
			'attention'   => __( '改善推奨', 'wp-security-checker' ),
			'recommended' => __( '要対応', 'wp-security-checker' ),
		);
		$status_label = isset( $status_labels[ $item['status'] ] ) ? $status_labels[ $item['status'] ] : '';
		?>
		<div class="wsc-item wsc-status-<?php echo esc_attr( $item['status'] ); ?>">
			<div class="wsc-item-header">
				<?php echo wp_kses_post( $icon ); ?>
				<span class="wsc-item-label"><?php echo esc_html( $item['label'] ); ?></span>
				<span class="wsc-status-badge wsc-badge-<?php echo esc_attr( $item['status'] ); ?>">
					<?php echo esc_html( $status_label ); ?>
				</span>
			</div>
			<?php if ( ! empty( $item['detail'] ) ) : ?>
				<div class="wsc-item-detail"><?php echo esc_html( $item['detail'] ); ?></div>
			<?php endif; ?>
			<?php if ( ! empty( $item['message'] ) ) : ?>
				<div class="wsc-item-message"><?php echo esc_html( $item['message'] ); ?></div>
			<?php endif; ?>
			<?php if ( in_array( $item['id'], array( 'a3', 'a4' ), true ) && 'good' !== $item['status'] ) : ?>
				<div class="wsc-update-link">
					<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>" class="button button-small">
						<?php esc_html_e( '更新画面を開く', 'wp-security-checker' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
