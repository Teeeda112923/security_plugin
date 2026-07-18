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
 * Handles all hardening diagnostic checks (B-1 through B-7).
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
	 * good: デバッグ表示が無効
	 * attention: デバッグ有効だがログのみで画面には出していない
	 * recommended: 本番でエラーが画面にそのまま表示される状態
	 *
	 * @return array Check result.
	 */
	private function check_debug_display() {
		$debug_on = defined( 'WP_DEBUG' ) && WP_DEBUG;

		if ( ! $debug_on ) {
			$status  = 'good';
			$message = '';
			$detail  = __( 'WP_DEBUG: 無効（本番環境として正常）', 'cybernote-security-checker' );
		} else {
			// WP_DEBUG_DISPLAY は未定義のとき既定で true（画面表示あり）。
			$display_on = ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY;

			if ( $display_on ) {
				$status  = 'recommended';
				$message = __( '本番でエラーが画面に表示される設定です。サーバーの構成やファイルの場所が攻撃者の手がかりになります。wp-config.phpでデバッグの画面表示をオフにしてください', 'cybernote-security-checker' );
				$detail  = __( 'WP_DEBUG: 有効／画面表示: あり', 'cybernote-security-checker' );
			} else {
				$status  = 'attention';
				$message = __( 'デバッグは有効ですが画面表示は抑止されています（ログのみ）。本番では不要ならWP_DEBUG自体の無効化を検討してください', 'cybernote-security-checker' );
				$detail  = __( 'WP_DEBUG: 有効／画面表示: なし（ログのみ）', 'cybernote-security-checker' );
			}
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
	 * B-2: 管理画面からのファイル編集（DISALLOW_FILE_EDIT）。
	 *
	 * good: 編集が無効化されている
	 * recommended: 編集が有効なまま（乗っ取り時の被害が大きい）
	 *
	 * @return array Check result.
	 */
	private function check_file_editing() {
		$editing_disabled = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;

		if ( $editing_disabled ) {
			$status  = 'good';
			$message = '';
			$detail  = __( 'DISALLOW_FILE_EDIT: 有効（管理画面からの編集を無効化済み）', 'cybernote-security-checker' );
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
			if ( get_user_by( 'login', $name ) ) {
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
			$detail  = sprintf( __( '既定名のユーザーが存在: %s', 'cybernote-security-checker' ), esc_html( implode( '、', $found ) ) );
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
	 * good: https で配信されている
	 * recommended: http のまま（認証情報が平文で流れる）
	 *
	 * @return array Check result.
	 */
	private function check_https() {
		$is_https = is_ssl();

		if ( $is_https ) {
			$status  = 'good';
			$message = '';
			$detail  = __( 'HTTPS: 有効（SSL証明書が適用されています）', 'cybernote-security-checker' );
		} else {
			$status  = 'recommended';
			$message = __( '通信が暗号化されず、ログイン情報などが途中で盗み見られる恐れがあります。SSL証明書を導入しhttpsに切り替えてください。多くのレンタルサーバーで無料の証明書が使えます', 'cybernote-security-checker' );
			$detail  = __( 'HTTPS: 無効（HTTP接続）', 'cybernote-security-checker' );
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
		$prefix = $wpdb->prefix;

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
			'detail'  => sprintf( __( '現在の接頭辞: %s', 'cybernote-security-checker' ), esc_html( $prefix ) ),
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
			$message = __( '使っていない場合、総当たり攻撃や踏み台攻撃の入り口になることがあります。外部連携アプリ（Jetpack等）や一部機能で使っていなければ無効化を検討してください。使っている場合はそのままで問題ありません', 'cybernote-security-checker' );
			$detail  = __( 'XML-RPC: 有効（xmlrpc.php が利用可能）', 'cybernote-security-checker' );
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
			// 匿名ユーザーとして評価し、評価後に元のユーザーへ復帰する。
			$saved_user = get_current_user_id();
			wp_set_current_user( 0 );

			$request = new WP_REST_Request( 'GET', '/wp/v2/users' );
			$request->set_param( 'per_page', 1 );
			$response = rest_do_request( $request );

			wp_set_current_user( $saved_user );

			if ( $response && ! $response->is_error() ) {
				$data = $response->get_data();
				if ( is_array( $data ) && ! empty( $data ) ) {
					$enumerable = true;
				}
			}
		}

		if ( ! $enumerable ) {
			$status  = 'good';
			$message = '';
			$detail  = __( '匿名でのユーザー名の列挙は抑止されています', 'cybernote-security-checker' );
		} else {
			$status  = 'attention';
			$message = __( 'ログインに使う名前が外部から集められ、総当たり攻撃の準備に使われます。ユーザー名の列挙を無効化する設定の追加を検討してください', 'cybernote-security-checker' );
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
	 * B-8: 認証用のユニークキー（SALT）が初期値・未設定でないか。
	 *
	 * wp-config.php の8つのキー／ソルトが定義済みで、空でも初期プレースホルダーでも
	 * 短すぎもしないかを確認する。値そのものは秘密なので絶対に出力しない。
	 *
	 * good: 8つすべてが適切に設定されている
	 * recommended: いずれかが未設定・空・初期値・短すぎる（cookie偽装のリスク）
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
		$weak_count  = 0;

		foreach ( $keys as $key ) {
			if ( ! defined( $key ) ) {
				$weak_count++;
				continue;
			}
			$value = constant( $key );
			if ( ! is_string( $value ) || '' === $value || $placeholder === $value || strlen( $value ) < 32 ) {
				$weak_count++;
			}
		}

		if ( 0 === $weak_count ) {
			$status  = 'good';
			$message = '';
			$detail  = __( '認証用の秘密キー8個はすべて設定されています', 'cybernote-security-checker' );
		} else {
			$status  = 'recommended';
			$message = __( 'ログイン状態を保護する「秘密の文字列（認証キー）」が初期値のままか未設定です。この値が既定のままだと、ログイン状態を偽装されてなりすまされる恐れがあります。wp-config.phpの認証用ユニークキーを再生成して置き換えてください', 'cybernote-security-checker' );
			/* translators: %d: number of keys that are unset or still default, out of 8 */
			$detail  = sprintf( __( '認証用の秘密キー8個中 %d個 が未設定または初期値のままです', 'cybernote-security-checker' ), (int) $weak_count );
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
		$inactive_plugins = 0;
		foreach ( array_keys( $all_plugins ) as $plugin_file ) {
			if ( ! is_plugin_active( $plugin_file ) ) {
				$inactive_plugins++;
			}
		}

		// アクティブなテーマ（子・親）を除いた数を「未使用テーマ」とする。
		$themes      = wp_get_themes();
		$total_theme = count( $themes );
		$active      = wp_get_theme();
		$in_use      = array( $active->get_stylesheet() );
		if ( $active->parent() ) {
			$in_use[] = $active->get_template();
		}
		$in_use        = array_unique( $in_use );
		$unused_themes = max( 0, $total_theme - count( $in_use ) );

		// 万一の切り替え用に1つ残すのは許容し、それを超えた分だけを問題として扱う。
		$excess_themes = max( 0, $unused_themes - 1 );

		if ( 0 === $inactive_plugins && 0 === $excess_themes ) {
			$status  = 'good';
			$message = '';
		} else {
			$status  = 'attention';
			$message = __( '停止中のプラグインやテーマも、ファイルはサーバーに残ります。脆弱性が見つかると、有効化していなくても攻撃の入り口になることがあります。使わないものは削除してください（テーマは万一の切り替え用に1つ残すのは問題ありません）', 'cybernote-security-checker' );
		}

		return array(
			'id'      => 'b9',
			'label'   => __( '未使用のプラグイン・テーマ', 'cybernote-security-checker' ),
			'status'  => $status,
			'message' => $message,
			/* translators: 1: number of inactive plugins, 2: number of unused themes */
			'detail'  => sprintf( __( '停止中のプラグイン: %1$d件 / 未使用のテーマ: %2$d件', 'cybernote-security-checker' ), (int) $inactive_plugins, (int) $unused_themes ),
		);
	}
}
