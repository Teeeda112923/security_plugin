<?php
/**
 * Category A: バージョン鮮度チェック
 *
 * @package WP_Security_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all version-freshness diagnostic checks (A-1 through A-4).
 */
class WSC_Category_A {

	/**
	 * Run all Category A checks.
	 *
	 * @return array Array of check result arrays.
	 */
	public function run() {
		return array(
			$this->check_php_version(),
			$this->check_wp_version(),
			$this->check_plugin_updates(),
			$this->check_theme_updates(),
		);
	}

	/**
	 * A-1: PHPバージョンチェック
	 *
	 * @return array Check result.
	 */
	private function check_php_version() {
		$current = PHP_VERSION;

		if ( version_compare( $current, '8.4', '>=' ) ) {
			$status  = 'good';
			$message = '';
		} elseif ( version_compare( $current, '8.2', '>=' ) ) {
			$status  = 'attention';
			/* translators: %s: current PHP version string */
			$message = sprintf(
				__( 'PHP 8.4以上へのアップデートを推奨します（現在: %s）', 'wp-security-checker' ),
				esc_html( $current )
			);
		} else {
			$status  = 'recommended';
			/* translators: %s: current PHP version string */
			$message = sprintf(
				__( 'PHP 8.2以上へのアップデートが必要です（現在: %s）', 'wp-security-checker' ),
				esc_html( $current )
			);
		}

		return array(
			'id'      => 'a1',
			'label'   => __( 'PHPバージョン', 'wp-security-checker' ),
			'status'  => $status,
			'message' => $message,
			'detail'  => sprintf(
				/* translators: %s: current PHP version string */
				__( '現在のバージョン: %s', 'wp-security-checker' ),
				esc_html( $current )
			),
		);
	}

	/**
	 * A-2: WordPressバージョンチェック
	 *
	 * @return array Check result.
	 */
	private function check_wp_version() {
		global $wp_version;

		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$updates = get_core_updates();
		$current = $wp_version;

		// No update available — already on latest.
		if (
			empty( $updates ) ||
			( isset( $updates[0]->response ) && 'latest' === $updates[0]->response )
		) {
			return array(
				'id'      => 'a2',
				'label'   => __( 'WordPressバージョン', 'wp-security-checker' ),
				'status'  => 'good',
				'message' => '',
				/* translators: %s: current WordPress version */
				'detail'  => sprintf( __( '現在のバージョン: %s（最新）', 'wp-security-checker' ), esc_html( $current ) ),
			);
		}

		$latest = isset( $updates[0]->version ) ? $updates[0]->version : $current;

		$current_parts = explode( '.', $current );
		$latest_parts  = explode( '.', $latest );

		$current_major = (int) ( $current_parts[0] ?? 0 );
		$current_minor = (int) ( $current_parts[1] ?? 0 );
		$latest_major  = (int) ( $latest_parts[0] ?? 0 );
		$latest_minor  = (int) ( $latest_parts[1] ?? 0 );

		if ( $current_major !== $latest_major ) {
			$status  = 'recommended';
			$message = sprintf(
				/* translators: 1: current WP version, 2: latest WP version */
				__( '早急なアップデートを推奨します（現在: %1$s, 最新: %2$s）', 'wp-security-checker' ),
				esc_html( $current ),
				esc_html( $latest )
			);
		} else {
			$minor_diff = $latest_minor - $current_minor;

			if ( $minor_diff >= 2 ) {
				$status  = 'recommended';
				$message = sprintf(
					/* translators: 1: current WP version, 2: latest WP version */
					__( '早急なアップデートを推奨します（現在: %1$s, 最新: %2$s）', 'wp-security-checker' ),
					esc_html( $current ),
					esc_html( $latest )
				);
			} elseif ( $minor_diff === 1 ) {
				$status  = 'attention';
				$message = sprintf(
					/* translators: 1: current WP version, 2: latest WP version */
					__( '最新バージョンが利用可能です（現在: %1$s, 最新: %2$s）', 'wp-security-checker' ),
					esc_html( $current ),
					esc_html( $latest )
				);
			} else {
				// Same minor (could be patch-only update).
				$status  = 'attention';
				$message = sprintf(
					/* translators: 1: current WP version, 2: latest WP version */
					__( '最新バージョンが利用可能です（現在: %1$s, 最新: %2$s）', 'wp-security-checker' ),
					esc_html( $current ),
					esc_html( $latest )
				);
			}
		}

		return array(
			'id'      => 'a2',
			'label'   => __( 'WordPressバージョン', 'wp-security-checker' ),
			'status'  => $status,
			'message' => $message,
			/* translators: 1: current WP version, 2: latest WP version */
			'detail'  => sprintf( __( '現在: %1$s / 最新: %2$s', 'wp-security-checker' ), esc_html( $current ), esc_html( $latest ) ),
		);
	}

	/**
	 * A-3: プラグイン更新数チェック
	 *
	 * @return array Check result.
	 */
	private function check_plugin_updates() {
		$update_data = get_site_transient( 'update_plugins' );
		$count       = 0;
		$names       = array();

		if ( ! empty( $update_data ) && ! empty( $update_data->response ) ) {
			$count = count( $update_data->response );

			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$all_plugins = get_plugins();
			foreach ( array_keys( $update_data->response ) as $plugin_file ) {
				if ( isset( $all_plugins[ $plugin_file ]['Name'] ) ) {
					$names[] = $all_plugins[ $plugin_file ]['Name'];
				} else {
					$names[] = $plugin_file;
				}
			}
		}

		if ( 0 === $count ) {
			$status  = 'good';
			$message = '';
		} elseif ( $count <= 3 ) {
			$status  = 'attention';
			/* translators: %d: number of plugins needing updates */
			$message = sprintf( __( '%d件のプラグインに更新があります', 'wp-security-checker' ), $count );
		} else {
			$status  = 'recommended';
			/* translators: %d: number of plugins needing updates */
			$message = sprintf( __( '%d件のプラグインに更新があります。早急に対応してください', 'wp-security-checker' ), $count );
		}

		$detail = '';
		if ( ! empty( $names ) ) {
			/* translators: %s: comma-separated list of plugin names */
			$detail = sprintf( __( '対象: %s', 'wp-security-checker' ), implode( '、', array_map( 'esc_html', $names ) ) );
		}

		return array(
			'id'      => 'a3',
			'label'   => __( 'プラグイン更新数', 'wp-security-checker' ),
			'status'  => $status,
			'message' => $message,
			'detail'  => $detail,
		);
	}

	/**
	 * A-4: テーマ更新数チェック
	 *
	 * @return array Check result.
	 */
	private function check_theme_updates() {
		$update_data = get_site_transient( 'update_themes' );
		$count       = 0;

		if ( ! empty( $update_data ) && ! empty( $update_data->response ) ) {
			$count = count( $update_data->response );
		}

		if ( 0 === $count ) {
			$status  = 'good';
			$message = '';
		} elseif ( $count <= 2 ) {
			$status  = 'attention';
			/* translators: %d: number of themes needing updates */
			$message = sprintf( __( '%d件のテーマに更新があります', 'wp-security-checker' ), $count );
		} else {
			$status  = 'recommended';
			/* translators: %d: number of themes needing updates */
			$message = sprintf( __( '%d件のテーマに更新があります。早急に対応してください', 'wp-security-checker' ), $count );
		}

		return array(
			'id'      => 'a4',
			'label'   => __( 'テーマ更新数', 'wp-security-checker' ),
			'status'  => $status,
			'message' => $message,
			'detail'  => '',
		);
	}
}
