<?php
/**
 * Integration checks for the free diagnostics, run inside a real WordPress via WP-CLI.
 *
 * Usage (normally through run.sh):
 *   CNSC_CASE=<case> wp eval-file tests/integration/test-diagnostics.php --user=<admin>
 *
 * Each case prepares a site state, runs CNSC_Diagnostics and asserts statuses.
 * Exit code 1 on any failure.
 *
 * @package CyberNote_Security_Checker
 */

// wp eval-file runs this file inside a function, so share state through $GLOBALS.
$GLOBALS['cnsc_case']     = getenv( 'CNSC_CASE' ) ? getenv( 'CNSC_CASE' ) : 'baseline';
$GLOBALS['cnsc_failures'] = 0;
$cnsc_case                = $GLOBALS['cnsc_case'];

/**
 * Record one assertion.
 *
 * @param bool   $ok    Result.
 * @param string $label Description.
 */
function cnsc_t_assert( $ok, $label ) {
	echo ( $ok ? '  ok: ' : '  FAIL: ' ) . '[' . $GLOBALS['cnsc_case'] . '] ' . $label . "\n";
	if ( ! $ok ) {
		$GLOBALS['cnsc_failures']++;
	}
}

/**
 * Run diagnostics and index results by ID.
 *
 * @return array
 */
function cnsc_t_run() {
	$r   = ( new CNSC_Diagnostics() )->run();
	$out = array();
	foreach ( array_merge( $r['a'], $r['b'] ) as $item ) {
		$out[ $item['id'] ] = $item;
	}
	return $out;
}

/**
 * Store fake, freshly-checked update transients.
 *
 * @param array $plugin_response Plugin update responses keyed by plugin file.
 * @param array $core_updates    Core update offers.
 */
function cnsc_t_set_updates( $plugin_response, $core_updates ) {
	$p               = new stdClass();
	$p->last_checked = time();
	$p->checked      = array();
	$p->response     = $plugin_response;
	set_site_transient( 'update_plugins', $p );

	$t               = new stdClass();
	$t->last_checked = time();
	$t->checked      = array();
	$t->response     = array();
	set_site_transient( 'update_themes', $t );

	$c                  = new stdClass();
	$c->last_checked    = time();
	$c->version_checked = get_bloginfo( 'version' );
	$c->updates         = $core_updates;
	set_site_transient( 'update_core', $c );
}

/**
 * Build a core update offer object.
 *
 * @param string $version  Offered version.
 * @param string $response latest|upgrade.
 * @return object
 */
function cnsc_t_offer( $version, $response ) {
	return (object) array(
		'response'        => $response,
		'version'         => $version,
		'current'         => $version,
		'locale'          => 'en_US',
		'packages'        => new stdClass(),
		'php_version'     => '7.4',
		'mysql_version'   => '5.5',
		'new_bundled'     => '',
		'partial_version' => '',
	);
}

// Outbound HTTP must never happen during diagnostics.
$cnsc_http_calls = array();
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$cnsc_http_calls ) {
		$cnsc_http_calls[] = $url;
		return new WP_Error( 'cnsc_test_blocked', 'blocked by test' );
	},
	10,
	3
);

global $wp_version;

switch ( $cnsc_case ) {
	case 'baseline':
		// Read-only: options must be unchanged by a diagnostics run.
		global $wpdb;
		$hash   = function () use ( $wpdb ) {
			return md5( (string) $wpdb->get_var( "SELECT GROUP_CONCAT(option_name, '=', MD5(option_value) ORDER BY option_name) FROM {$wpdb->options} WHERE option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%' AND option_name <> 'cron'" ) );
		};
		$before = $hash();
		$user   = get_current_user_id();
		$r      = cnsc_t_run();
		cnsc_t_assert( $before === $hash(), 'diagnostics do not change any option' );
		cnsc_t_assert( $user === get_current_user_id(), 'current user restored after REST check' );
		cnsc_t_assert( 12 === count( $r ), '12 checks returned' );
		foreach ( $r as $id => $item ) {
			cnsc_t_assert( in_array( $item['status'], array( 'good', 'attention', 'recommended' ), true ), "$id has a valid status" );
		}
		cnsc_t_assert( empty( $cnsc_http_calls ), 'no outbound HTTP requests (' . implode( ', ', $cnsc_http_calls ) . ')' );
		break;

	case 'updates_unknown':
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );
		delete_site_transient( 'update_core' );
		$r = cnsc_t_run();
		cnsc_t_assert( 'attention' === $r['a1']['status'], 'a1 is not "good" when update info was never retrieved' );
		cnsc_t_assert( false === strpos( $r['a1']['detail'], '最新' ), 'a1 does not claim "latest"' );
		cnsc_t_assert( 'attention' === $r['a3']['status'], 'a3 is not "good" when update info was never retrieved' );
		break;

	case 'updates_latest':
		cnsc_t_set_updates( array(), array( cnsc_t_offer( $wp_version, 'latest' ) ) );
		$r = cnsc_t_run();
		cnsc_t_assert( 'good' === $r['a1']['status'], 'a1 good when WordPress is latest' );
		cnsc_t_assert( 'good' === $r['a3']['status'], 'a3 good when nothing to update' );
		break;

	case 'updates_pending':
		$parts = array_map( 'intval', explode( '.', $wp_version ) );
		$maint = $parts[0] . '.' . $parts[1] . '.' . ( ( $parts[2] ?? 0 ) + 1 );
		$major = $parts[0] . '.' . ( $parts[1] + 1 );
		add_filter(
			'all_plugins',
			function ( $plugins ) {
				if ( isset( $plugins['hello.php'] ) ) {
					$plugins['hello.php']['Name'] = 'Tom & "Jerry"';
				}
				return $plugins;
			}
		);
		wp_cache_delete( 'plugins', 'plugins' );
		cnsc_t_set_updates( array( 'hello.php' => (object) array( 'new_version' => '99' ) ), array( cnsc_t_offer( $maint, 'upgrade' ) ) );
		$r = cnsc_t_run();
		cnsc_t_assert( 'recommended' === $r['a1']['status'], 'a1 recommended for an unapplied maintenance release' );
		cnsc_t_assert( 'attention' === $r['a3']['status'], 'a3 attention with a pending update' );

		cnsc_t_set_updates( array(), array( cnsc_t_offer( $major, 'upgrade' ) ) );
		$r = cnsc_t_run();
		cnsc_t_assert( 'attention' === $r['a1']['status'], 'a1 attention for a feature release only' );

		// Rendering escapes exactly once.
		ob_start();
		CNSC_Renderer::render_item(
			array(
				'id'      => 'a3',
				'label'   => 'x',
				'status'  => 'attention',
				'message' => '',
				'detail'  => '対象: Tom & "Jerry"',
			)
		);
		$html = ob_get_clean();
		cnsc_t_assert( false !== strpos( $html, 'Tom &amp; &quot;Jerry&quot;' ), 'detail escaped once' );
		cnsc_t_assert( false === strpos( $html, '&amp;amp;' ), 'detail not double-escaped' );
		break;

	case 'php_schedule':
		$cases = array(
			array( '8.1', '2026-10-20', 'eol' ),
			array( '8.2', '2026-10-20', 'security' ),
			array( '8.2', '2027-01-01', 'eol' ),
			array( '8.3', '2026-10-20', 'security' ),
			array( '8.4', '2026-10-20', 'active' ),
			array( '8.4', '2027-01-01', 'security' ),
			array( '8.5', '2026-10-20', 'active' ),
			array( '9.0', '2026-10-20', 'active' ),
			array( '7.3', '2026-10-20', 'eol' ),
		);
		foreach ( $cases as $c ) {
			list( $phase ) = CNSC_Category_A::php_support_phase( $c[0], $c[1] );
			cnsc_t_assert( $c[2] === $phase, "PHP {$c[0]} on {$c[1]} is {$c[2]} (got {$phase})" );
		}
		break;

	case 'rest_blocked':
		add_filter(
			'rest_authentication_errors',
			function ( $result ) {
				return is_user_logged_in() ? $result : new WP_Error( 'rest_forbidden', 'blocked', array( 'status' => 401 ) );
			}
		);
		$r = cnsc_t_run();
		cnsc_t_assert( 'good' === $r['b7']['status'], 'b7 good when anonymous REST is blocked by rest_authentication_errors' );
		break;

	case 'rest_open':
		$r = cnsc_t_run();
		cnsc_t_assert( 'attention' === $r['b7']['status'], 'b7 attention when /wp/v2/users is anonymous-readable' );
		break;

	case 'https_urls':
		$force = function ( $url ) {
			return set_url_scheme( $url, 'https' );
		};
		add_filter( 'home_url', $force );
		add_filter( 'site_url', $force );
		$r = cnsc_t_run();
		cnsc_t_assert( 'good' === $r['b4']['status'], 'b4 good when both URLs are https even if this request is not' );
		remove_filter( 'site_url', $force );
		$r = cnsc_t_run();
		cnsc_t_assert( 'recommended' === $r['b4']['status'], 'b4 recommended when only one URL is https' );
		break;

	case 'https_off':
		$_SERVER['HTTPS'] = 'on'; // Current request is https, but the site is configured for http.
		$r                = cnsc_t_run();
		cnsc_t_assert( 'recommended' === $r['b4']['status'], 'b4 recommended when site URLs are http' );
		break;

	case 'admin_names':
		$sub = wp_insert_user(
			array(
				'user_login' => 'admin',
				'user_pass'  => wp_generate_password(),
				'role'       => 'subscriber',
			)
		);
		$r   = cnsc_t_run();
		cnsc_t_assert( 'good' === $r['b3']['status'], 'b3 ignores a subscriber named admin' );
		( new WP_User( $sub ) )->set_role( 'administrator' );
		$r = cnsc_t_run();
		cnsc_t_assert( 'attention' === $r['b3']['status'], 'b3 attention for an administrator named admin' );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $sub );
		break;

	case 'keys_placeholder':
		$r = cnsc_t_run();
		cnsc_t_assert( 'attention' === $r['b8']['status'], 'b8 attention for placeholder keys (WordPress falls back to DB salts)' );
		cnsc_t_assert( false === strpos( $r['b8']['detail'], 'put your unique' ), 'b8 never prints key values' );
		break;

	case 'keys_duplicate':
		$r = cnsc_t_run();
		cnsc_t_assert( 'attention' === $r['b8']['status'], 'b8 attention for duplicated keys' );
		break;

	case 'keys_short':
		$r = cnsc_t_run();
		cnsc_t_assert( 'recommended' === $r['b8']['status'], 'b8 recommended for short unique keys' );
		break;

	case 'file_mods':
		$r = cnsc_t_run();
		cnsc_t_assert( 'good' === $r['b2']['status'], 'b2 good when DISALLOW_FILE_MODS is true' );
		break;

	case 'debug_display':
		$r = cnsc_t_run();
		cnsc_t_assert( 'recommended' === $r['b1']['status'], 'b1 recommended when WP_DEBUG displays errors' );
		break;

	case 'debug_log_public':
		$r = cnsc_t_run();
		cnsc_t_assert( 'attention' === $r['b1']['status'], 'b1 attention for log-only debug' );
		cnsc_t_assert( false !== strpos( $r['b1']['detail'], 'debug.log' ), 'b1 mentions the public debug.log' );
		break;

	case 'server_display_errors':
		ini_set( 'display_errors', '1' ); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed
		$r = cnsc_t_run();
		cnsc_t_assert( 'attention' === $r['b1']['status'], 'b1 attention when php display_errors is on and WP_DEBUG is off' );
		ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed
		$r = cnsc_t_run();
		cnsc_t_assert( 'good' === $r['b1']['status'], 'b1 good when everything is off' );
		break;

	default:
		cnsc_t_assert( false, 'unknown case' );
}

if ( $GLOBALS['cnsc_failures'] > 0 ) {
	exit( 1 );
}
