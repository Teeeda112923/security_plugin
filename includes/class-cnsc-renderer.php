<?php
/**
 * 診断結果の共通描画ヘルパー
 *
 * ダッシュボードウィジェットと専用管理ページの両方から利用し、
 * 1項目の表示マークアップを一箇所に集約する（表示の食い違いを防ぐ）。
 *
 * @package CyberNote_Security_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared rendering helpers for diagnostic results.
 */
class CNSC_Renderer {

	/**
	 * Localized status label keyed by status.
	 *
	 * @param string $status One of good|attention|recommended.
	 * @return string Translated label.
	 */
	public static function status_label( $status ) {
		$labels = array(
			'good'        => __( 'No issues', 'cybernote-security-checker' ),
			'attention'   => __( 'Recommended improvement', 'cybernote-security-checker' ),
			'recommended' => __( 'Action required', 'cybernote-security-checker' ),
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
	 * @param array $results Diagnostic results from CNSC_Diagnostics::run().
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
	 * @param array $results Diagnostic results from CNSC_Diagnostics::run().
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
	 * @param array $results Diagnostic results from CNSC_Diagnostics::run().
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
	 * Per-item guide content (steps and risk) keyed by check ID.
	 *
	 * Values may contain <br> and <code> for formatting.
	 *
	 * @return array
	 */
	private static function guide_data() {
		return array(
			'a1' => array(
				'steps' => __( 'Open Dashboard → Updates in WordPress and click Update Now. Making a backup before updating is recommended.', 'cybernote-security-checker' ),
				'risk'  => __( 'Unapplied security fixes will remain on the site. Attackers may exploit known vulnerabilities to deface the site or install malware.', 'cybernote-security-checker' ),
				'links' => array(
					array(
						'label' => __( 'WordPress official: Updating WordPress', 'cybernote-security-checker' ),
						'url'   => 'https://wordpress.org/documentation/article/updating-wordpress/',
					),
				),
			),
			'a2' => array(
				'steps' => __( 'Log in to your hosting control panel, such as cPanel, Sakura Control Panel, or ConoHa Control Panel. Select PHP 8.2 or later from the PHP version menu. We strongly recommend backing up your site first.', 'cybernote-security-checker' ),
				'risk'  => __( 'Unsupported PHP versions do not receive patches for newly discovered vulnerabilities. If attackers exploit them, no fixes may be available and the damage can spread.', 'cybernote-security-checker' ),
				'links' => array(
					array(
						'label' => __( 'WordPress official: Recommended server environment', 'cybernote-security-checker' ),
						'url'   => 'https://wordpress.org/about/requirements/',
					),
					array(
						'label' => __( 'PHP official: Supported versions', 'cybernote-security-checker' ),
						'url'   => 'https://www.php.net/supported-versions.php',
					),
				),
			),
			'a3' => array(
				'steps' => __( 'Open Dashboard → Updates, select the outdated plugins and themes, and click Update Plugins or Update Themes. Making a backup before updating is recommended.', 'cybernote-security-checker' ),
				'risk'  => __( 'Plugin and theme updates may include security fixes. Leaving them outdated increases the risk of attacks that exploit known vulnerabilities.', 'cybernote-security-checker' ),
				'has_update_link' => true,
				'links' => array(
					array(
						'label' => __( 'WordPress official: Managing plugins', 'cybernote-security-checker' ),
						'url'   => 'https://wordpress.org/documentation/article/manage-plugins/',
					),
				),
			),
			'b1' => array(
				'steps' => __( 'Open wp-config.php on the server in a text editor and find the following line:<br><code>define(\'WP_DEBUG\', true);</code><br>Change it to the following and save the file:<br><code>define(\'WP_DEBUG\', false);</code>', 'cybernote-security-checker' ),
				'risk'  => __( 'When debug information is displayed, PHP error messages may reveal server file paths and internal structures. This gives attackers information about the server environment.', 'cybernote-security-checker' ),
				'links' => array(
					array(
						'label' => __( 'WordPress official: Debugging WordPress', 'cybernote-security-checker' ),
						'url'   => 'https://wordpress.org/documentation/article/debugging-in-wordpress/',
					),
				),
			),
			'b2' => array(
				'steps' => __( 'Open wp-config.php and add the following line before the line that says "/* That\'s all you need to edit. */":<br><code>define(\'DISALLOW_FILE_EDIT\', true);</code>', 'cybernote-security-checker' ),
				'risk'  => __( 'If an administrator account is compromised, attackers can directly edit PHP files on the server through the theme and plugin code editor. They may install a backdoor.', 'cybernote-security-checker' ),
				'links' => array(
					array(
						'label' => __( 'WordPress official: Hardening WordPress', 'cybernote-security-checker' ),
						'url'   => 'https://wordpress.org/documentation/article/hardening-wordpress/',
					),
				),
			),
			'b3' => array(
				'steps' => __( 'First create another administrator account and log in with it. Then delete the admin account from Users. When deleting it, you can assign its existing posts to the new account.', 'cybernote-security-checker' ),
				'risk'  => __( 'The username "admin" is one of the most frequently targeted in WordPress. Once the username is known, the risk of a successful password-guessing attack increases significantly.', 'cybernote-security-checker' ),
				'links' => array(
					array(
						'label' => __( 'WordPress official: Hardening WordPress', 'cybernote-security-checker' ),
						'url'   => 'https://wordpress.org/documentation/article/hardening-wordpress/',
					),
				),
			),
			'b4' => array(
				'steps' => __( 'Issue an SSL certificate from your hosting control panel. Many hosting providers offer free certificates through Let\'s Encrypt. After configuring the certificate, go to Settings → General in WordPress and change both the WordPress Address and Site Address to https://.', 'cybernote-security-checker' ),
				'risk'  => __( 'HTTP does not encrypt communications. Passwords and personal information entered into forms may be intercepted while traveling across the network.', 'cybernote-security-checker' ),
				'links' => array(
					array(
						'label' => __( 'WordPress official: Using HTTPS with WordPress', 'cybernote-security-checker' ),
						'url'   => 'https://wordpress.org/documentation/article/https-for-wordpress/',
					),
				),
			),
			'b5' => array(
				'steps' => __( 'Changing this on an existing site requires care. Always make a backup first. In phpMyAdmin or a similar tool, change the "wp_" portion of every database table name to another string, such as "mywp_", and update the <code>$table_prefix</code> value in wp-config.php to match.', 'cybernote-security-checker' ),
				'risk'  => __( 'If table names retain the well-known "wp_" pattern, attackers may find it easier to target the database after a successful SQL injection attack.', 'cybernote-security-checker' ),
				'links' => array(
					array(
						'label' => __( 'WordPress official: Hardening WordPress', 'cybernote-security-checker' ),
						'url'   => 'https://wordpress.org/documentation/article/hardening-wordpress/',
					),
				),
			),
			'b6' => array(
				'steps' => __( 'A free plugin such as "Disable XML-RPC" can disable it easily. Alternatively, add the following to .htaccess to block access to xmlrpc.php:<br><code>&lt;Files xmlrpc.php&gt;<br>Order Deny,Allow<br>Deny from all<br>&lt;/Files&gt;</code>', 'cybernote-security-checker' ),
				'risk'  => __( 'XML-RPC is an older integration feature and is rarely needed in current WordPress sites. When enabled, it allows many login attempts in a single request and can be abused for brute-force attacks.', 'cybernote-security-checker' ),
				'links' => array(
					array(
						'label' => __( 'WordPress official: XML-RPC support', 'cybernote-security-checker' ),
						'url'   => 'https://wordpress.org/documentation/article/xml-rpc-support/',
					),
				),
			),
			'b7' => array(
				'steps' => __( 'A security plugin such as Wordfence or SiteGuard WP Plugin is the easiest option. You can also add the following to your theme\'s functions.php:<br><code>add_filter(\'rest_endpoints\', function($ep) {<br>&nbsp;&nbsp;if (!is_user_logged_in()) {<br>&nbsp;&nbsp;&nbsp;&nbsp;unset($ep[\'/wp/v2/users\']);<br>&nbsp;&nbsp;&nbsp;&nbsp;unset($ep[\'/wp/v2/users/(?P&lt;id&gt;[\\d]+)\']);<br>&nbsp;&nbsp;}<br>&nbsp;&nbsp;return $ep;<br>});</code>', 'cybernote-security-checker' ),
				'risk'  => __( 'If the REST API user list endpoint is publicly available, anyone can collect usernames. Once usernames are known, password-guessing attacks become easier.', 'cybernote-security-checker' ),
				'links' => array(
					array(
						'label' => __( 'WordPress official: REST API handbook', 'cybernote-security-checker' ),
						'url'   => 'https://developer.wordpress.org/rest-api/',
					),
				),
			),
			'b8' => array(
				'steps' => __( 'Open the WordPress Secret Key Service using the link below to generate eight lines of code. Open wp-config.php and replace the eight lines from AUTH_KEY through NONCE_SALT with the generated values. Everyone currently logged in will be logged out once; they can simply log in again.', 'cybernote-security-checker' ),
				'risk'  => __( 'These secret strings encrypt the cookies that store login sessions. If they are empty or left at their defaults, attackers may forge cookies and take over accounts.', 'cybernote-security-checker' ),
				'links' => array(
					array(
						'label' => __( 'WordPress official: Secret Key Service', 'cybernote-security-checker' ),
						'url'   => 'https://api.wordpress.org/secret-key/1.1/salt/',
					),
					array(
						'label' => __( 'WordPress official: Hardening WordPress', 'cybernote-security-checker' ),
						'url'   => 'https://wordpress.org/documentation/article/hardening-wordpress/',
					),
				),
			),
			'b9' => array(
				'steps' => __( 'In Plugins, delete anything that is inactive. In Appearance → Themes, select an unused theme and choose Theme Details → Delete. Confirm that you really do not use it before deleting. Keeping one fallback theme is fine.', 'cybernote-security-checker' ),
				'risk'  => __( 'Even unused plugins and themes can become entry points if an old version contains a vulnerability. Their files may be targeted whether they are active or inactive.', 'cybernote-security-checker' ),
				'links' => array(
					array(
						'label' => __( 'WordPress official: Managing plugins', 'cybernote-security-checker' ),
						'url'   => 'https://wordpress.org/documentation/article/manage-plugins/',
					),
					array(
						'label' => __( 'WordPress official: Working with themes', 'cybernote-security-checker' ),
						'url'   => 'https://wordpress.org/documentation/article/work-with-themes/',
					),
				),
			),
		);
	}

	/**
	 * Topic (logo) icon for a check ID, using WordPress' built-in Dashicons.
	 *
	 * No external assets are loaded. PHP has no official Dashicon, so a short
	 * "PHP" text badge is used ('text:PHP') — recognizable, lightweight, and
	 * avoids bundling a trademarked logo file.
	 *
	 * @param string $id Check ID.
	 * @return string Dashicons class slug, a 'text:LABEL' token, or '' when unmapped.
	 */
	public static function topic_icon( $id ) {
		$map = array(
			'a1' => 'dashicons-wordpress',
			'a2' => 'text:PHP',
			'a3' => 'dashicons-admin-plugins',
			'b1' => 'dashicons-visibility',
			'b2' => 'dashicons-edit',
			'b3' => 'dashicons-admin-users',
			'b4' => 'dashicons-lock',
			'b5' => 'dashicons-database',
			'b6' => 'dashicons-rss',
			'b7' => 'dashicons-rest-api',
			'b8' => 'dashicons-admin-network',
			'b9' => 'dashicons-trash',
		);
		return isset( $map[ $id ] ) ? $map[ $id ] : '';
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
		$id     = isset( $item['id'] ) ? $item['id'] : '';
		$topic  = self::topic_icon( $id );

		$classes = array(
			'wsc-item',
			'wsc-status-' . sanitize_html_class( $status ),
		);
		if ( $args['compact'] ) {
			$classes[] = 'wsc-item-compact';
		}

		$all_guides = self::guide_data();
		$guide      = isset( $all_guides[ $id ] ) ? $all_guides[ $id ] : null;
		$guide_id   = 'wsc-guide-' . esc_attr( $id ) . '-' . wp_rand( 1000, 9999 );

		$allowed_html = array(
			'br'   => array(),
			'code' => array(),
		);
		?>
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
			<div class="wsc-item-icon" aria-hidden="true">
				<?php if ( 0 === strpos( $topic, 'text:' ) ) : ?>
					<span class="wsc-item-icon-label"><?php echo esc_html( substr( $topic, 5 ) ); ?></span>
				<?php elseif ( $topic ) : ?>
					<span class="dashicons <?php echo esc_attr( $topic ); ?>"></span>
				<?php else : ?>
					<?php echo esc_html( self::status_icon_text( $status ) ); ?>
				<?php endif; ?>
			</div>

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

				<?php if ( $args['show_action'] && 'a3' === $id && 'good' !== $status ) : ?>
					<div class="wsc-item-action">
						<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>" class="button button-small wsc-secondary-action">
							<?php esc_html_e( 'Open Updates', 'cybernote-security-checker' ); ?>
						</a>
					</div>
				<?php endif; ?>

				<?php if ( $guide ) : ?>
					<div class="wsc-item-guide" id="<?php echo esc_attr( $guide_id ); ?>" style="display:none">
						<div class="wsc-guide-section">
							<div class="wsc-guide-section-title"><?php esc_html_e( 'What to do', 'cybernote-security-checker' ); ?></div>
							<div class="wsc-guide-steps"><?php echo wp_kses( $guide['steps'], $allowed_html ); ?></div>
						</div>
						<?php if ( ! empty( $guide['has_update_link'] ) ) : ?>
							<div class="wsc-guide-action">
								<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>" class="button button-small wsc-secondary-action">
									<?php esc_html_e( 'Open Updates', 'cybernote-security-checker' ); ?>
								</a>
							</div>
						<?php endif; ?>
						<div class="wsc-guide-section">
							<div class="wsc-guide-section-title"><?php esc_html_e( 'What happens if you do nothing', 'cybernote-security-checker' ); ?></div>
							<div class="wsc-guide-risk"><?php echo wp_kses( $guide['risk'], $allowed_html ); ?></div>
						</div>
						<?php if ( ! empty( $guide['links'] ) ) : ?>
							<div class="wsc-guide-links">
								<div class="wsc-guide-section-title"><?php esc_html_e( 'Learn more', 'cybernote-security-checker' ); ?></div>
								<?php foreach ( $guide['links'] as $link ) : ?>
									<a href="<?php echo esc_url( $link['url'] ); ?>" class="wsc-guide-link" target="_blank" rel="noopener noreferrer">
										<span class="dashicons dashicons-external" aria-hidden="true"></span>
										<?php echo esc_html( $link['label'] ); ?>
									</a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( $guide ) : ?>
				<button
					class="wsc-item-chevron wsc-guide-toggle"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $guide_id ); ?>"
					aria-label="<?php esc_attr_e( 'Show detailed guide', 'cybernote-security-checker' ); ?>"
					onclick="cnscToggleGuide(this)"
				>›</button>
			<?php else : ?>
				<span class="wsc-item-chevron" aria-hidden="true">›</span>
			<?php endif; ?>
		</div>
		<?php

	}
}
