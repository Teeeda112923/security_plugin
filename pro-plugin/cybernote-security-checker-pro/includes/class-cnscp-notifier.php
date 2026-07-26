<?php
/**
 * メール通知: スキャンで「新しく」見つかった脆弱性だけを管理者にメールする。
 *
 * 毎回の全件通知はうるさいので、前回通知済みとの差分だけを送る。
 * 不完全スキャン（DB不調で照合が最後まで終わらなかった）では通知しない。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CNSCP_Notifier {

	const OPT_EMAIL    = 'cnscp_notify_email';   // 送信先（空なら管理者メール）。
	const OPT_ENABLED  = 'cnscp_notify_enabled'; // 通知ON/OFF。
	const OPT_NOTIFIED = 'cnscp_notified_ids';   // 通知済みキーの集合。

	/**
	 * 通知が有効か（既定はON）。
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) get_option( self::OPT_ENABLED, true );
	}

	/**
	 * 送信先メール（未設定ならサイト管理者メール）。
	 *
	 * @return string
	 */
	public static function email() {
		$email = trim( (string) get_option( self::OPT_EMAIL, '' ) );
		return '' !== $email ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * スキャン成功後に呼ぶ。新規脆弱性があればメールする。
	 *
	 * @param array $vulns      無害化済み脆弱性配列.
	 * @param bool  $incomplete 照合が不完全だったか.
	 * @return int 送信した新規件数（0=送らず）.
	 */
	public static function maybe_notify( $vulns, $incomplete ) {
		if ( $incomplete ) {
			return 0; // 結果が信頼できないので通知しない。
		}

		$current = array();
		foreach ( (array) $vulns as $v ) {
			$current[ self::key( $v ) ] = $v;
		}

		$notified = array_values( (array) get_option( self::OPT_NOTIFIED, array() ) );

		// 前回通知に無い＝新規。
		$new = array();
		foreach ( $current as $k => $v ) {
			if ( ! in_array( $k, $notified, true ) ) {
				$new[] = $v;
			}
		}

		// 通知済み集合は「今回も存在するもの」に絞ってから今回分を足す（無限に増やさない）。
		$kept   = array_intersect( $notified, array_keys( $current ) );
		$merged = array_values( array_unique( array_merge( $kept, array_keys( $current ) ) ) );
		update_option( self::OPT_NOTIFIED, $merged, false );

		if ( empty( $new ) || ! self::is_enabled() ) {
			return 0;
		}
		$to = self::email();
		if ( '' === $to ) {
			return 0;
		}

		self::send( $to, $new );
		return count( $new );
	}

	/**
	 * 脆弱性の識別キー（CVEがあればCVE、無ければ種別+スラッグ+題名）。
	 *
	 * @param array $v Vulnerability.
	 * @return string
	 */
	protected static function key( $v ) {
		$cve = trim( (string) ( $v['cve_id'] ?? '' ) );
		if ( '' !== $cve ) {
			return $cve;
		}
		return ( $v['type'] ?? '' ) . ':' . ( $v['slug'] ?? '' ) . ':' . ( $v['title'] ?? '' );
	}

	/**
	 * メール送信。
	 *
	 * @param string $to  宛先.
	 * @param array  $new 新規脆弱性配列.
	 */
	protected static function send( $to, $new ) {
		$site  = (string) get_bloginfo( 'name' );
		$count = count( $new );

		$subject = sprintf( '[%s] 新しい脆弱性が%d件見つかりました', $site, $count );

		$lines   = array();
		$lines[] = sprintf( '%s で、使用中のプラグイン・テーマ・WordPress本体に、新しい既知の脆弱性が見つかりました。', $site );
		$lines[] = '';
		foreach ( $new as $v ) {
			$lines[] = sprintf(
				'■ %s（%s）%s',
				(string) ( $v['name'] ?? '' ),
				self::type_label( (string) ( $v['type'] ?? '' ) ),
				self::sev_label( (string) ( $v['severity'] ?? '' ) )
			);
			if ( ! empty( $v['vuln_type_ja'] ) ) {
				$lines[] = '　種類: ' . $v['vuln_type_ja'];
			}
			if ( ! empty( $v['cve_id'] ) ) {
				$lines[] = '　CVE: ' . $v['cve_id'];
			}
			if ( ! empty( $v['cybernote_url'] ) ) {
				$lines[] = '　解説: ' . $v['cybernote_url'];
			}
			if ( ! empty( $v['action_ja'] ) ) {
				$lines[] = '　対処: ' . $v['action_ja'];
			}
			$lines[] = '';
		}
		$lines[] = '詳しくは管理画面の「脆弱性アラート」をご確認ください:';
		$lines[] = admin_url( 'admin.php?page=cnscp-alerts' );

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	/**
	 * 種別ラベル。
	 *
	 * @param string $type plugin|theme|core.
	 * @return string
	 */
	protected static function type_label( $type ) {
		$map = array(
			'plugin' => 'プラグイン',
			'theme'  => 'テーマ',
			'core'   => 'WordPress本体',
		);
		return $map[ $type ] ?? '';
	}

	/**
	 * 深刻度ラベル。
	 *
	 * @param string $severity Severity.
	 * @return string
	 */
	protected static function sev_label( $severity ) {
		$map = array(
			'critical' => '深刻度: 重大',
			'high'     => '深刻度: 高',
			'medium'   => '深刻度: 中',
			'low'      => '深刻度: 低',
			'unknown'  => '深刻度: 調査中',
		);
		return $map[ $severity ] ?? '';
	}
}
