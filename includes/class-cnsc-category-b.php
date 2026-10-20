<?php
/**
 * Category B: ハードニング設定チェック（設計書カテゴリB・9項目）
 *
 * recommendedに置くのは「漏れる・壊される・盗まれる」の三つに絞り、残りはattention。
 * 既存サイトで変更にリスクがある項目（ユーザー名・DB接頭辞）は煽らずattention止まり。
 *
 * @package CyberNote_Security_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all hardening diagnostic checks (B-1 through B-9).
 */
class CNSC_Category_B {

	/**
	 * Run all Category B checks.
	 *
	 * @return array Array of check result arrays.
	 */
	public function run() {
		return array(
			$this->check_debug_display(),
			$this->check_file_editing(),
			$this->check_admin_username(),
			$this->check_https(),
			$this->check_db_prefix(),
			$this->check_xmlrpc(),
			$this->check_rest_user_enumeration(),
			$this->check_security_keys(),
			$this->check_unused_extensions(),
		);
	}

	/**
	 * B-1: 本番でのデバッグ表示（WP_DEBUG）— 三段階。
	 *
	 * good: デバッグ無効で、サーバー側のエラー画面表示もオフ
	 * attention: デバッグ有効だが画面には出していない／サーバー設定（php.ini）でエラー画面表示がオン
	 * recommended: WP_DEBUG によってエラーが画面にそのまま表示される状態
	 *
	 * WordPress の挙動（wp_debug_mode()）に合わせて判定する:
	 * - WP_DEBUG_DISPLAY が true なら表示オン、false なら表示オフ、null ならサーバー設定のまま。
	 * - WP_DEBUG が無効なとき、WordPress は display_errors に触れない（サーバー設定のまま）。
	 * - WP_DEBUG_LOG が true のときのログは wp-content/debug.log（外部から閲覧され得る場所）。
	 *
	 * @return array Check result.
	 */
	private function check_debug_display() {
		$debug_on = defined( 'WP_DEBUG' ) && WP_DEBUG;
		// WordPress は WP_DEBUG_DISPLAY を未定義なら true として定義する。
		$wp_display = defined( 'WP_DEBUG_DISPLAY' ) ? WP_DEBUG_DISPLAY : true;

		if ( $debug_on && $wp_display ) {
			$status  = 'recommended';
			$message = __( '本番でエラーが画面に表示される設定です。サーバーの構成やファイルの場所が攻撃者の手がかりになります。wp-config.phpでデバッグの画面表示をオフにしてください', 'cybernote-security-checker' );
			$detail  = __( 'WP_DEBUG: 有効／画面表示: あり', 'cybernote-security-checker' );
		} elseif ( $debug_on && false === $wp_display ) {
			$status = 'attention';
			if ( $this->debug_log_is_public() ) {
				$message = __( 'エラーは画面に出ていませんが、ログが初期設定の場所（wp-content/debug.log）に書き出されています。この場所は外部からURLで開ける場合があり、サーバーの内部情報が漏れる恐れがあります。本番では WP_DEBUG を無効にするか、ログの保存先を公開されない場所に変更してください', 'cybernote-security-checker' );
				$detail  = __( 'WP_DEBUG: 有効／画面表示: なし／ログ: wp-content/debug.log', 'cybernote-security-checker' );
			} else {
				$message = __( 'デバッグは有効ですが画面表示は抑止されています。本番では不要ならWP_DEBUG自体の無効化を検討してください', 'cybernote-security-checker' );
				$detail  = __( 'WP_DEBUG: 有効／画面表示: なし', 'cybernote-security-checker' );
			}
		} elseif ( $this->server_displays_errors() ) {
			// WP_DEBUG 無効、または WP_DEBUG_DISPLAY が null でサーバー設定に任されている。
			$status  = 'attention';
			$message = __( 'サーバーのPHP設定（display_errors）でエラーの画面表示がオンになっています。重大なエラーが起きたときに、サーバー内部の情報が訪問者の画面に表示される恐れがあります。サーバーの管理画面（PHP設定）で display_errors をオフにしてください', 'cybernote-security-checker' );
			$detail  = $debug_on
				? __( 'WP_DEBUG: 有効／画面表示: サーバー設定に従う（オン）', 'cybernote-security-checker' )
				: __( 'WP_DEBUG: 無効／サーバー設定の画面表示: オン', 'cybernote-security-checker' );
		} else {
			$status  = 'good';
			$message = '';
			$detail  = $debug_on
				? __( 'WP_DEBUG: 有効／画面表示: なし', 'cybernote-security-checker' )
				: __( 'WP_DEBUG: 無効（本番環境として正常）', 'cybernote-security-checker' );
		}

		return array(
			'id'      => 'b1',
			'label'   => __( 'デバッグ表示', 'cybernote-security-checker' ),
			'status'  => $status,
			'message' => $message,
			'detail'  => $detail,
		);
	}

	/**
	 * デバッグログがWordPressの公開ディレクトリ内に書き出される設定か。
	 *
	 * @return bool
	 */
	private function debug_log_is_public() {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return false;
		}
		if ( in_array( strtolower( (string) WP_DEBUG_LOG ), array( 'true', '1' ), true ) ) {
			return true; // 既定の wp-content/debug.log。
		}
		$path = wp_normalize_path( (string) WP_DEBUG_LOG );
		return 0 === strpos( $path, wp_normalize_path( ABSPATH ) );
	}

	/**
	 * サーバーのPHP設定でエラーの画面表示がオンか。
	 *
	 * AJAX・REST の処理中は WordPress が display_errors を一時的に 0 にするため、
	 * その場合は php.ini 側の値（global_value）を見る。
	 *
	 * @return bool
	 */
	private function server_displays_errors() {
		$value = ini_get( 'display_errors' );
		if ( ( wp_doing_ajax() || wp_is_json_request() || defined( 'REST_REQUEST' ) ) && function_exists( 'ini_get_all' ) ) {
			$all = ini_get_all( null, true );
			if ( isset( $all['display_errors']['global_value'] ) ) {
				$value = $all['display_errors']['global_value'];
			}
		}
		// 'stderr' はサーバーのログ側に出力されるため画面表示ではない。
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'on', 'true', 'yes', 'stdout' ), true );
	}

	/**
	 * B-2: 管理画面からのファイル編集（DISALLOW_FILE_EDIT）。
	 *
	 * good: 編集が無効化されている
	 * recommended: 編集が有効なまま（乗っ取り時の被害が大きい）
	 *
	 * @return array Check result.
	 */
	private function check_file_editing() {
		$editing_disabled = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;
		// DISALLOW_FILE_MODS はファイル編集を含むすべてのファイル変更を禁止する（より強い設定）。
		$mods_disabled = defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS;

		if ( $editing_disabled ) {
			$status  = 'good';
			$message = '';
			$detail  = __( 'DISALLOW_FILE_EDIT: 有効（管理画面からの編集を無効化済み）', 'cybernote-security-checker' );
		} elseif ( $mods_disabled ) {
			$status  = 'good';
			$message = '';
			$detail  = __( 'DISALLOW_FILE_MODS: 有効（ファイル編集を含め、管理画面からのファイル変更を無効化済み）', 'cybernote-security-checker' );
		} else {
			$status  = 'recommended';
			$message = __( "管理者アカウントが乗っ取られるとコードをその場で書き換えられます。wp-config.phpに define('DISALLOW_FILE_EDIT', true); を追加してください", 'cybernote-security-checker' );
			$detail  = __( 'DISALLOW_FILE_EDIT: 未設定（管理画面からファイル編集が可能）', 'cybernote-security-checker' );
		}

		return array(
			'id'      => 'b2',
			'label'   => __( 'ファイル編集機能', 'cybernote-security-checker' ),
			'status'  => $status,
			'message' => $message,
			'detail'  => $detail,
		);
	}

	/**
	 * B-3: 管理者ユーザー名が既定名か。
	 *
	 * good: 既定名の管理者が存在しない
	 * attention: admin / administrator などの既定名が存在する（変更に手間、煽らない）
	 *
	 * @return array Check result.
	 */
	private function check_admin_username() {
		$default_names = array( 'admin', 'administrator' );
		$found         = array();

		foreach ( $default_names as $name ) {
			$user = get_user_by( 'login', $name );
			// 狙われて困るのは管理権限を持つアカウント。購読者などの同名ユーザーは対象外。
			if ( $user && user_can( $user, 'manage_options' ) ) {
				$found[] = $name;
			}
		}

		if ( empty( $found ) ) {
			$status  = 'good';
			$message = '';
			$detail  = __( '推測されやすい既定名の管理者は存在しません（良好）', 'cybernote-security-checker' );
		} else {
			$status  = 'attention';
			$message = __( '攻撃者が最初に試すのが既定の名前で、パスワード総当たりの標的になりやすいです。新しい名前の管理者を作って権限を移し、既定名のアカウントを削除してください（バックアップ後に実施）', 'cybernote-security-checker' );
			/* translators: %s: comma-separated list of default user names found */
			$detail  = sprintf( __( '既定名の管理者が存在: %s', 'cybernote-security-checker' ), implode( '、', $found ) );
		}

		return array(
			'id'      => 'b3',
			'label'   => __( '管理者ユーザー名', 'cybernote-security-checker' ),
			'status'  => $status,
			'message' => $message,
			'detail'  => $detail,
		);
	}

	/**
	 * B-4: 常時HTTPS（SSL）。
	 *
	 * good: サイトアドレス（home）とWordPressアドレス（siteurl）がどちらも https
	 * recommended: どちらかが http のまま（認証情報が平文で流れる）
	 *
	 * 「今の通信が https か」（is_ssl）ではなく、サイトの設定で判定する。is_ssl は
	 * リバースプロキシやロードバランサーでTLSを終端する構成では https でも false を返し、
	 * 逆に管理画面だけ https の構成では公開側が http でも true を返すため。
	 *
	 * @return array Check result.
	 */
	private function check_https() {
		$home_https = 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
		$site_https = 'https' === wp_parse_url( site_url(), PHP_URL_SCHEME );

		if ( $home_https && $site_https ) {
			$status  = 'good';
			$message = '';
			$detail  = __( 'HTTPS: 有効（サイトのURLが https に設定されています）', 'cybernote-security-checker' );
		} elseif ( $home_https || $site_https ) {
			$status  = 'recommended';
			$message = __( 'サイトアドレスとWordPressアドレスの一方だけが https になっています。http 側の通信は暗号化されず、ログイン情報などが盗み見られる恐れがあります。「設定 → 一般」で両方を https:// に揃えてください', 'cybernote-security-checker' );
			$detail  = $home_https
				? __( 'サイトアドレス: https／WordPressアドレス: http', 'cybernote-security-checker' )
				: __( 'サイトアドレス: http／WordPressアドレス: https', 'cybernote-security-checker' );
		} else {
			$status  = 'recommended';
			$message = __( '通信が暗号化されず、ログイン情報などが途中で盗み見られる恐れがあります。SSL証明書を導入しhttpsに切り替えてください。多くのレンタルサーバーで無料の証明書が使えます', 'cybernote-security-checker' );
			$detail  = __( 'HTTPS: 無効（サイトのURLが http に設定されています）', 'cybernote-security-checker' );
		}

		return array(
			'id'      => 'b4',
			'label'   => __( '常時HTTPS', 'cybernote-security-checker' ),
			'status'  => $status,
			'message' => $message,
			'detail'  => $detail,
		);
	}

	/**
	 * B-5: データベースのテーブル接頭辞が既定値か。
	 *
	 * good: wp_ 以外に変更されている
	 * attention: wp_ のまま（効果は限定的、稼働中変更はリスク。煽らない）
	 *
	 * @return array Check result.
	 */
	private function check_db_prefix() {
		global $wpdb;
		// マルチサイトでは子サイトの prefix が wp_2_ のようになるため、元の接頭辞で判定する。
		$prefix = $wpdb->base_prefix;

		if ( 'wp_' === $prefix ) {
			$status  = 'attention';
			$message = __( '既定値のままだと一部の自動化された攻撃で狙いを定められやすくなります。新しくサイトを作る際は別の接頭辞にしてください。稼働中サイトは変更にリスクがあるため無理に変えないでください', 'cybernote-security-checker' );
		} else {
			$status  = 'good';
			$message = '';
		}

		return array(
			'id'      => 'b5',
			'label'   => __( 'データベース接頭辞', 'cybernote-security-checker' ),
			'status'  => $status,
			'message' => $message,
			/* translators: %s: current database table prefix */
			'detail'  => sprintf( __( '現在の接頭辞: %s', 'cybernote-security-checker' ), $prefix ),
		);
	}

	/**
	 * B-6: XML-RPC の状態。
	 *
	 * good: 無効化されている
	 * attention: 有効になっている（用途があり一律に危険ではない。用途確認を促す）
	 *
	 * @return array Check result.
	 */
	private function check_xmlrpc() {
		// xmlrpc_enabled フィルタの評価結果で判定（既定 true）。
		$xmlrpc_enabled = apply_filters( 'xmlrpc_enabled', true );

		if ( ! $xmlrpc_enabled ) {
			$status  = 'good';
			$message = '';
			$detail  = __( 'XML-RPC: 無効化されています', 'cybernote-security-checker' );
		} else {
			$status  = 'attention';
			$message = __( '使っていない場合、総当たり攻撃や踏み台攻撃の入り口になることがあります。外部連携アプリ（Jetpack等）や一部機能で使っていなければ無効化を検討してください。使っている場合はそのままで問題ありません（.htaccess などサーバー側で遮断済みの場合、この診断では検出できないため、その場合も対応は不要です）', 'cybernote-security-checker' );
			$detail  = __( 'XML-RPC: WordPressの設定上は有効', 'cybernote-security-checker' );
		}

		return array(
			'id'      => 'b6',
			'label'   => __( 'XML-RPC', 'cybernote-security-checker' ),
			'status'  => $status,
			'message' => $message,
			'detail'  => $detail,
		);
	}

	/**
	 * B-7: REST API のユーザー名列挙。
	 *
	 * 認証なし（匿名）で /wp/v2/users からユーザー名一覧が取得できるかを、
	 * 内部REST呼び出し（外部通信なし）で実際に確認する。
	 *
	 * good: ユーザー名の列挙が抑止されている
	 * attention: 外部からユーザー名を一覧取得できる
	 *
	 * @return array Check result.
	 */
	private function check_rest_user_enumeration() {
		$enumerable = false;

		if ( function_exists( 'rest_do_request' ) && class_exists( 'WP_REST_Request' ) ) {
			// 匿名ユーザーとして評価し、評価後（例外時も）必ず元のユーザーへ復帰する。
			$saved_user = get_current_user_id();
			wp_set_current_user( 0 );

			try {
				// rest_do_request() は実際のAPI配信時に行う認証チェック
				// （rest_authentication_errors フィルタ）を通らない。匿名アクセスを
				// このフィルタで遮断している設定を見落とさないよう、先に評価する。
				$auth_error = rest_get_server()->check_authentication();

				if ( ! is_wp_error( $auth_error ) ) {
					$request = new WP_REST_Request( 'GET', '/wp/v2/users' );
					$request->set_param( 'per_page', 1 );
					$response = rest_do_request( $request );

					if ( $response && ! $response->is_error() ) {
						$data = $response->get_data();
						if ( is_array( $data ) && ! empty( $data ) ) {
							$enumerable = true;
						}
					}
				}
			} finally {
				wp_set_current_user( $saved_user );
			}
		}

		if ( ! $enumerable ) {
			$status  = 'good';
			$message = '';
			$detail  = __( '匿名でのユーザー名の列挙は抑止されています', 'cybernote-security-checker' );
		} else {
			$status  = 'attention';
			$message = __( 'ユーザーのURL用の名前（多くの場合ログイン名と同じ）が外部から集められ、パスワード総当たり攻撃の準備に使われることがあります。ユーザー名の列挙を無効化する設定の追加を検討してください', 'cybernote-security-checker' );
			$detail  = __( '認証なしで /wp/v2/users からユーザー情報を取得できます', 'cybernote-security-checker' );
		}

		return array(
			'id'      => 'b7',
			'label'   => __( 'REST APIユーザー名列挙', 'cybernote-security-checker' ),
			'status'  => $status,
			'message' => $message,
			'detail'  => $detail,
		);
	}

	/**
	 * B-8: 認証用のユニークキー（SALT）の設定状態。
	 *
	 * WordPress（wp_salt()）は、wp-config.php のキーが未設定・空・初期プレースホルダー・
	 * 他のキーと同じ値のいずれかの場合、その値を使わず、自動生成したランダム値を
	 * データベースに保存して代わりに使う。したがってその状態でも cookie を簡単に偽装
	 * されるわけではないが、秘密の値がデータベースに入っているため、データベースや
	 * バックアップが漏れたときにログイン状態を偽装される余地が残る。
	 * 一方、短すぎる値はそのまま使われるため、推測されやすく実際に弱い。
	 * 値そのものは秘密なので絶対に出力しない。
	 *
	 * good: 8つすべてが十分な長さの固有の値で設定されている
	 * attention: いずれかが未設定・空・初期値・重複で、データベース保存の自動生成値で代用されている
	 * recommended: いずれかが短すぎる値のまま使われている
	 *
	 * @return array Check result.
	 */
	private function check_security_keys() {
		$keys = array(
			'AUTH_KEY',
			'SECURE_AUTH_KEY',
			'LOGGED_IN_KEY',
			'NONCE_KEY',
			'AUTH_SALT',
			'SECURE_AUTH_SALT',
			'LOGGED_IN_SALT',
			'NONCE_SALT',
		);

		// wp-config-sample.php の初期プレースホルダー。
		$placeholder = 'put your unique phrase here';

		$values = array();
		foreach ( $keys as $key ) {
			$values[ $key ] = defined( $key ) ? constant( $key ) : null;
		}
		$occurrences = array_count_values( array_filter( $values, 'is_string' ) );

		$fallback_count = 0; // WordPressが自動生成値で代用しているキーの数。
		$short_count    = 0; // 短すぎる値がそのまま使われているキーの数。

		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || '' === $value || $placeholder === $value || $occurrences[ $value ] > 1 ) {
				$fallback_count++;
			} elseif ( strlen( $value ) < 32 ) {
				$short_count++;
			}
		}

		if ( $short_count > 0 ) {
			$status  = 'recommended';
			$message = __( 'ログイン状態を保護する「秘密の文字列（認証キー）」に、短すぎる値が使われています。短い値は推測されやすく、ログイン状態を偽装されてなりすまされる恐れがあります。wp-config.phpの認証用ユニークキーを再生成して置き換えてください', 'cybernote-security-checker' );
		} elseif ( $fallback_count > 0 ) {
			$status  = 'attention';
			$message = __( 'wp-config.php の「秘密の文字列（認証キー）」の一部が未設定・初期値・重複のため、WordPressが自動生成した値をデータベースに保存して代わりに使っています。すぐに危険な状態ではありませんが、データベースやバックアップが漏れた場合にログイン状態を偽装される余地が残ります。wp-config.phpの認証用ユニークキーを生成して設定することをおすすめします', 'cybernote-security-checker' );
		} else {
			$status  = 'good';
			$message = '';
		}

		if ( 'good' === $status ) {
			$detail = __( '認証用の秘密キー8個はすべて設定されています', 'cybernote-security-checker' );
		} else {
			$parts = array();
			if ( $short_count > 0 ) {
				/* translators: %d: number of keys that are too short */
				$parts[] = sprintf( __( '短すぎる: %d個', 'cybernote-security-checker' ), (int) $short_count );
			}
			if ( $fallback_count > 0 ) {
				/* translators: %d: number of keys that are unset, default or duplicated */
				$parts[] = sprintf( __( '未設定・初期値・重複: %d個', 'cybernote-security-checker' ), (int) $fallback_count );
			}
			/* translators: %s: breakdown of key problems */
			$detail = sprintf( __( '認証用の秘密キー8個のうち %s（値そのものは表示しません）', 'cybernote-security-checker' ), implode( '／', $parts ) );
		}

		return array(
			'id'      => 'b8',
			'label'   => __( 'セキュリティキー（秘密の文字列）', 'cybernote-security-checker' ),
			'status'  => $status,
			'message' => $message,
			'detail'  => $detail,
		);
	}

	/**
	 * B-9: 停止中（未使用）のプラグイン・テーマが残っていないか。
	 *
	 * 停止中でもファイルはサーバーに残るため、脆弱性があると攻撃対象になり得る。
	 * テーマは万一の切り替え用に1つ残すのは許容し、それを超える分を未使用として数える。
	 *
	 * good: 停止中のプラグインがなく、未使用テーマも1つ以内
	 * attention: 停止中のプラグイン、または2つ以上の未使用テーマがある
	 *
	 * @return array Check result.
	 */
	private function check_unused_extensions() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins      = get_plugins();
		$inactive_names   = array();
		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			if ( ! is_plugin_active( $plugin_file ) ) {
				$inactive_names[] = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : $plugin_file;
			}
		}
		$inactive_plugins = count( $inactive_names );

		// アクティブなテーマ（子・親）を除いた数を「未使用テーマ」とする。
		$themes      = wp_get_themes();
		$active      = wp_get_theme();
		$in_use      = array( $active->get_stylesheet() );
		if ( $active->parent() ) {
			$in_use[] = $active->get_template();
		}
		$in_use       = array_unique( $in_use );
		$unused_names = array();
		foreach ( $themes as $stylesheet => $theme ) {
			if ( ! in_array( $stylesheet, $in_use, true ) ) {
				$unused_names[] = $theme->get( 'Name' ) ? $theme->get( 'Name' ) : $stylesheet;
			}
		}
		$unused_themes = count( $unused_names );

		// 万一の切り替え用に1つ残すのは許容し、それを超えた分だけを問題として扱う。
		$excess_themes = max( 0, $unused_themes - 1 );

		if ( 0 === $inactive_plugins && 0 === $excess_themes ) {
			$status  = 'good';
			$message = '';
		} else {
			$status  = 'attention';
			$message = __( '停止中のプラグインやテーマも、ファイルはサーバーに残ります。脆弱性が見つかると、有効化していなくても攻撃の入り口になることがあります。本当に使っていないことを確認したうえで、不要なものは削除してください（テーマは万一の切り替え用に1つ残すのは問題ありません）', 'cybernote-security-checker' );
		}

		$plugin_part = $inactive_plugins > 0
			/* translators: 1: number of inactive plugins, 2: their names */
			? sprintf( __( '停止中のプラグイン（%1$d件）: %2$s', 'cybernote-security-checker' ), (int) $inactive_plugins, self::name_list( $inactive_names ) )
			: __( '停止中のプラグイン: 0件', 'cybernote-security-checker' );
		$theme_part  = $unused_themes > 0
			/* translators: 1: number of unused themes, 2: their names */
			? sprintf( __( '未使用のテーマ（%1$d件）: %2$s', 'cybernote-security-checker' ), (int) $unused_themes, self::name_list( $unused_names ) )
			: __( '未使用のテーマ: 0件', 'cybernote-security-checker' );
		$detail      = $plugin_part . ' / ' . $theme_part;

		return array(
			'id'      => 'b9',
			'label'   => __( '未使用のプラグイン・テーマ', 'cybernote-security-checker' ),
			'status'  => $status,
			'message' => $message,
			'detail'  => $detail,
		);
	}

	/**
	 * 名前の一覧を、長くなりすぎないよう先頭5件までに省略して連結する。
	 *
	 * @param string[] $names Names.
	 * @return string
	 */
	private static function name_list( $names ) {
		$shown = array_slice( $names, 0, 5 );
		$text  = implode( '、', $shown );
		if ( count( $names ) > count( $shown ) ) {
			/* translators: %d: number of omitted items */
			$text .= sprintf( __( ' ほか%d件', 'cybernote-security-checker' ), count( $names ) - count( $shown ) );
		}
		return $text;
	}
}
