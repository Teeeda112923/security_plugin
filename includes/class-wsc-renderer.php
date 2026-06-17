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
			'good'        => __( '問題なし', 'wp-security-checker' ),
			'attention'   => __( '改善推奨', 'wp-security-checker' ),
			'recommended' => __( '要対応', 'wp-security-checker' ),
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
		$classes = array(
			'wsc-item',
			'wsc-status-' . sanitize_html_class( $status ),
		);
		if ( $args['compact'] ) {
			$classes[] = 'wsc-item-compact';
		}
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

				<?php if ( $args['show_action'] && 'a3' === $item['id'] && 'good' !== $status ) : ?>
					<div class="wsc-item-action">
						<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>" class="button button-small wsc-secondary-action">
							<?php esc_html_e( '更新画面を開く', 'wp-security-checker' ); ?>
						</a>
					</div>
				<?php endif; ?>
			</div>

			<span class="wsc-item-chevron" aria-hidden="true">›</span>
		</div>
		<?php
	}
}
