# CyberNote バックエンド（cybernote.click 用）

このフォルダは **cybernote.click（WordPressサイト・レンタルサーバー）に設置する
内部プラグイン**の開発場所です。WordPress.org に公開する無料版プラグインとは別物で、
配布版ZIPには**同梱しません**。

（旧: Python/FastAPI + Render 構成のMVPがここにあったが、運用先が
レンタルサーバーに決まったため、WordPressプラグイン型のPHP実装に置き換えた。）

## 構成

```
backend/
  cybernote-api/     … cybernote.click に設置するプラグイン本体
  tests/run-tests.php … オフラインテスト（WPスタブ＋擬似フィード）
```

## cybernote-api プラグインの機能（B1: 実装済み）

- `POST /wp-json/cybernote/v1/scan` — プラグイン・テーマ・WP本体の一覧を受け取り、
  WPVulnerability.com（無料・APIキー不要）と突合して日本語の脆弱性リストを返す
  - 深刻度（CVSSスコア→重大/高/中/低）、CWE→平易な日本語分類（XSS等）、
    修正版と対応文、CVE番号、参考リンク
  - バージョン範囲が不明な報告は除外（誤報を避ける）
- ライセンスキー検証（暫定: 設定 > CyberNote API で手動登録・1行1キー、
  形式 `WSC-XXXX-XXXX-XXXX-XXXX`）
- レート制限（1キーにつき10スキャン/時）・突合結果の24時間キャッシュ

## テスト

```bash
php backend/tests/run-tests.php   # 17項目・WordPress不要で動く
```

## デプロイ手順

1. `cybernote-api/` フォルダをZIP化する
2. cybernote.click の管理画面 > プラグイン > 新規追加 > アップロードで
   インストール・有効化
3. 設定 > CyberNote API を開き、テスト用キーを登録（英数大文字4×4、例は下記curl）
4. 手元のPCから動作確認:

```bash
curl -X POST https://www.cybernote.click/wp-json/cybernote/v1/scan \
  -H "Content-Type: application/json" \
  -d '{
    "license_key": "WSC-AAAA-BBBB-CCCC-DDDD",
    "site_url": "https://example.com",
    "wp_version": "6.5.3",
    "php_version": "8.2.18",
    "plugins": [{"slug": "contact-form-7", "version": "5.9", "name": "Contact Form 7"}],
    "themes": []
  }'
```

該当する既知脆弱性があれば `vulnerabilities` 配列に日本語の説明付きで返ります。

## 公開前の必須確認

1. 脆弱性データ源（WPVulnerability.com）の商用利用条件の確認
2. 実データでのレスポンス形式の最終確認（設置後に上記curlで）
3. 利用規約・プライバシーポリシーの掲載（利用者サイトのプラグイン一覧を受け取るため）

## 今後のフェーズ

- B2: Pro接続プラグイン（利用者サイト側。毎日自動送信し、管理画面の
  「脆弱性アラート」に結果表示。cybernote.click から配布・WP.org外）
- B3: Lemon Squeezy 決済連携（購入 → キー自動発行・有効期限管理）＋メール通知

## メモ

- 脆弱性データ源への問い合わせは `CNAPI_Matcher::fetch_json()` に集約してあり、
  将来 WPScan 等の有料DBへ差し替え・併用が可能
