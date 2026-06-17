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
	 * Status icon markup keyed by status.
	 *
	 * @param string $status One of good|attention|recommended.
	 * @return string HTML span, or empty string.
	 */
	public static function status_icon( $status ) {
		$icons = array(
			'good'        => '<span class="wsc-icon wsc-good">✓</span>',
			'attention'   => '<span class="wsc-icon wsc-attention">△</span>',
			'recommended' => '<span class="wsc-icon wsc-recommended">×</span>',
		);
		return isset( $icons[ $status ] ) ? $icons[ $status ] : '';
	}

	/**
	 * Localized status label keyed by status.
	 *
	 * @param string $status One of good|attention|recommended.
	 * @return string Translated label.
	 */
	public static function status_label( $status ) {
		$labels = array(
			'good'        => __( '問題なし', 'wp-security-checker' ),
			'attention'   => __( '改善推奨', 'wp-security-checker' ),
			'recommended' => __( '要対応', 'wp-security-checker' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : '';
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
		$all = array_merge( $results['a'], $results['b'] );
		foreach ( $all as $item ) {
			if ( isset( $counts[ $item['status'] ] ) ) {
				$counts[ $item['status'] ]++;
			}
		}
		return $counts;
	}

	/**
	 * Render a single diagnostic item row.
	 *
	 * @param array $item Check result array.
	 */
	public static function render_item( $item ) {
		$icon         = self::status_icon( $item['status'] );
		$status_label = self::status_label( $item['status'] );
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
			<?php if ( 'a3' === $item['id'] && 'good' !== $item['status'] ) : ?>
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
