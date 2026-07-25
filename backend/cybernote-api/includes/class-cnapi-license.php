<?php
/**
 * ライセンスキーの検証（B1段階の暫定実装）。
 *
 * 現段階では管理画面で手動登録したキーの一覧と照合するだけ。
 * B3（Lemon Squeezy連携）で、購入Webhookからの自動発行・有効期限管理に置き換える。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CNAPI_License {

	const OPTION_KEYS = 'cnapi_license_keys';

	/**
	 * キー形式の妥当性（WSC-XXXX-XXXX-XXXX-XXXX）。
	 *
	 * @param string $key License key.
	 * @return bool
	 */
	public static function is_well_formed( $key ) {
		return (bool) preg_match( '/^WSC(-[A-Z0-9]{4}){4}$/', strtoupper( trim( (string) $key ) ) );
	}

	/**
	 * キーが登録済みかどうか。
	 *
	 * @param string $key License key.
	 * @return bool
	 */
	public static function is_valid( $key ) {
		$key = strtoupper( trim( (string) $key ) );
		if ( ! self::is_well_formed( $key ) ) {
			return false;
		}
		return in_array( $key, self::registered_keys(), true );
	}

	/**
	 * 登録済みキーの配列（1行1キーのoptionから）。
	 *
	 * @return string[]
	 */
	public static function registered_keys() {
		$raw  = (string) get_option( self::OPTION_KEYS, '' );
		$keys = array();
		foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
			$line = strtoupper( trim( $line ) );
			if ( '' !== $line && self::is_well_formed( $line ) ) {
				$keys[] = $line;
			}
		}
		return $keys;
	}

	/**
	 * レート制限: 同一キーからのスキャンを1時間に10回まで。
	 *
	 * @param string $key License key.
	 * @return bool true = 実行してよい / false = 制限超過.
	 */
	public static function within_rate_limit( $key ) {
		$bucket = 'cnapi_rate_' . md5( strtoupper( trim( (string) $key ) ) );
		$count  = (int) get_transient( $bucket );
		if ( $count >= 10 ) {
			return false;
		}
		set_transient( $bucket, $count + 1, HOUR_IN_SECONDS );
		return true;
	}
}
