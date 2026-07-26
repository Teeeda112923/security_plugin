<?php
/**
 * ライセンスキーの検証。
 *
 * 2系統をこの順で判定する。
 *  1) 手動登録キー（WSC-XXXX-XXXX-XXXX-XXXX）… 検証用・無償提供用。決済を通さず発行できる。
 *  2) Lemon Squeezy のライセンスキー … 購入時に自動発行されたものを本家APIで検証する。
 *
 * Lemon Squeezy の /v1/licenses/validate は認証不要の公開エンドポイントなので、
 * ストアのAPIキーをこのサーバーに置く必要がない（漏えい面を増やさない）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CNAPI_License {

	const OPTION_KEYS   = 'cnapi_license_keys';
	const OPTION_LS_ON  = 'cnapi_ls_enabled';   // Lemon Squeezy 検証を使うか。
	const VALIDATE_URL  = 'https://api.lemonsqueezy.com/v1/licenses/validate';
	const CACHE_TTL     = 12 * HOUR_IN_SECONDS; // 検証結果の保持時間。
	const GRACE_TTL     = 3 * DAY_IN_SECONDS;   // LS障害時に前回結果を使える猶予。

	/**
	 * 手動登録キーの形式（WSC-XXXX-XXXX-XXXX-XXXX）。
	 *
	 * @param string $key License key.
	 * @return bool
	 */
	public static function is_well_formed( $key ) {
		return (bool) preg_match( '/^WSC(-[A-Z0-9]{4}){4}$/', strtoupper( trim( (string) $key ) ) );
	}

	/**
	 * Lemon Squeezy 形式のキーか（UUID風）。
	 *
	 * @param string $key License key.
	 * @return bool
	 */
	public static function looks_like_ls_key( $key ) {
		return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim( (string) $key ) );
	}

	/**
	 * Lemon Squeezy 検証が有効か。
	 *
	 * @return bool
	 */
	public static function ls_enabled() {
		return (bool) get_option( self::OPTION_LS_ON, false );
	}

	/**
	 * キーが有効か。
	 *
	 * @param string $key License key.
	 * @return bool
	 */
	public static function is_valid( $key ) {
		$key = trim( (string) $key );
		if ( '' === $key ) {
			return false;
		}

		// 手動登録キー（検証・無償提供用）。
		if ( self::is_well_formed( $key ) ) {
			return in_array( strtoupper( $key ), self::registered_keys(), true );
		}

		// Lemon Squeezy 発行キー。
		if ( self::ls_enabled() && self::looks_like_ls_key( $key ) ) {
			$result = self::validate_remote( $key );
			return ! empty( $result['valid'] );
		}

		return false;
	}

	/**
	 * 無効だった理由（利用者向けの日本語）。is_valid() が false のときに使う。
	 *
	 * @param string $key License key.
	 * @return string
	 */
	public static function invalid_reason( $key ) {
		$key = trim( (string) $key );

		if ( self::ls_enabled() && self::looks_like_ls_key( $key ) ) {
			$result = self::validate_remote( $key );
			$status = (string) ( $result['status'] ?? '' );
			if ( 'expired' === $status ) {
				return 'ライセンスの有効期限が切れています。';
			}
			if ( 'disabled' === $status ) {
				return 'このライセンスは無効化されています。';
			}
			if ( ! empty( $result['unreachable'] ) ) {
				return 'ライセンスの確認に一時的に失敗しました。時間をおいてお試しください。';
			}
		}

		return 'ライセンスキーが無効です。';
	}

	/**
	 * Lemon Squeezy にキーの状態を問い合わせる（結果はキャッシュ）。
	 *
	 * LS側の障害でスキャンが止まらないよう、失敗時は猶予期間内の前回結果を使う。
	 *
	 * @param string $key License key.
	 * @return array { valid:bool, status:string, unreachable:bool }
	 */
	public static function validate_remote( $key ) {
		$cache_key = 'cnapi_ls_' . md5( strtolower( trim( $key ) ) );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) && isset( $cached['ts'] )
			&& ( time() - (int) $cached['ts'] ) < self::CACHE_TTL ) {
			return $cached['result'];
		}

		$response = wp_remote_post(
			self::VALIDATE_URL,
			array(
				'timeout' => 12,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => array( 'license_key' => trim( $key ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::fallback_or_unreachable( $cached );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// 404 は「そのキーは存在しない」＝確定的に無効。5xx等は一時障害として扱う。
		if ( ! is_array( $body ) || ( 200 !== $code && 404 !== $code ) ) {
			return self::fallback_or_unreachable( $cached );
		}

		$result = array(
			'valid'       => ! empty( $body['valid'] ),
			'status'      => (string) ( $body['license_key']['status'] ?? '' ),
			'unreachable' => false,
		);

		set_transient( $cache_key, array( 'ts' => time(), 'result' => $result ), self::GRACE_TTL );
		return $result;
	}

	/**
	 * LSに到達できないときの扱い。猶予内の前回結果があればそれを使う。
	 *
	 * @param mixed $cached キャッシュ内容.
	 * @return array
	 */
	protected static function fallback_or_unreachable( $cached ) {
		if ( is_array( $cached ) && isset( $cached['result'] ) && is_array( $cached['result'] ) ) {
			// 前回の判定を引き継ぐ（有効だった人を障害中に締め出さない）。
			return $cached['result'];
		}
		return array(
			'valid'       => false,
			'status'      => '',
			'unreachable' => true,
		);
	}

	/**
	 * 手動登録キーの配列（1行1キーのoptionから）。
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
