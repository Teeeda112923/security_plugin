# WP Security Checker Pro — 設計書

バージョン: 0.1-draft  
作成日: 2026-06-18  
対象: 開発者向け内部ドキュメント

---

## 1. Pro版の位置づけと課金の境界線

### 無料版とProの役割分担

| 軸 | 無料版（Free） | Pro版 |
|---|---|---|
| 情報源 | サイト内の状態のみ | サイト内 ＋ 既知脆弱性DB |
| 更新の伝え方 | 「3件の更新待ちがあります」 | 「Contact Form 7 v5.9 に Critical な XSS があります。今すぐ更新を」 |
| 診断タイミング | 手動（再診断ボタン） | WP-Cron で毎日 / 週次の自動実行 |
| 通知手段 | 管理画面を開いたときのみ | 管理画面バナー ＋ メール |
| 外部通信 | なし | cybernote.click API 経由のみ |
| 価格 | 無料・永続 | 月額 or 年額（サブスクリプション） |

無料版が「更新が来ている事実」までしか言わないのに対し、Pro版は  
**「その更新が危険かどうか・どれだけ緊急か」**を教える。  
ここが課金の境界線であり、価値提案の核心。

---

## 2. アーキテクチャ全体図

```
[WordPress サイト]                  [cybernote.click]            [脆弱性DB]
  WSC_Pro_Scanner
    ├─ installed plugins/themes   ─→  License 検証
    │   (slug, version, type)         ↓
    └─ WP/PHP version            ─→  突合エンジン  ────────────→  WPScan DB
                                      ↓                            Patchstack API
                                  キャッシュ (Redis/DB)           NIST NVD
                                      ↓
                                  結果JSON ◄──────────────────────
                                      ↓
[WordPress サイト]
  結果を保存 (transient)
  バナー表示 / メール送信
```

### 通信の方向

- プラグイン → API: スキャン要求（ライセンスキー＋環境情報）
- API → プラグイン: 脆弱性リスト（JSONレスポンス）
- 外部への直通通信はなし。ユーザーのサイトから脆弱性DBへは直接接続しない。

---

## 3. バックエンドAPI設計（cybernote.click）

### エンドポイント一覧

| メソッド | パス | 役割 |
|---|---|---|
| POST | `/api/v1/scan` | 環境情報を受け取り脆弱性リストを返す（メイン） |
| POST | `/api/v1/license/verify` | ライセンスキーの有効性確認 |
| POST | `/api/v1/license/deactivate` | サイトからのライセンス解除 |

### POST `/api/v1/scan` — リクエスト

```json
{
  "license_key": "WSC-XXXX-XXXX-XXXX-XXXX",
  "site_url": "https://example.com",
  "wp_version": "6.5.3",
  "php_version": "8.2.18",
  "plugins": [
    { "slug": "contact-form-7", "version": "5.9", "name": "Contact Form 7" },
    { "slug": "woocommerce",    "version": "8.8.3","name": "WooCommerce" }
  ],
  "themes": [
    { "slug": "storefront", "version": "4.5.0", "name": "Storefront" }
  ]
}
```

### POST `/api/v1/scan` — レスポンス

```json
{
  "status": "ok",
  "scanned_at": "2026-06-18T10:00:00Z",
  "vulnerabilities": [
    {
      "type": "plugin",
      "slug": "contact-form-7",
      "name": "Contact Form 7",
      "installed_version": "5.9",
      "fixed_version": "5.9.5",
      "severity": "critical",
      "vuln_type": "xss",
      "vuln_type_ja": "クロスサイトスクリプティング（XSS）",
      "title_ja": "Contact Form 7 5.9以前に認証不要XSS",
      "description_ja": "フォームの入力値が適切に無害化されないため、悪意のあるスクリプトを埋め込まれる可能性があります。",
      "action_ja": "管理画面の「更新」から Contact Form 7 を 5.9.5 以上に更新してください。",
      "cve_id": "CVE-2026-XXXXX",
      "references": [
        "https://wpscan.com/vulnerability/xxxxxxxx",
        "https://www.wordfence.com/threat-intel/..."
      ]
    }
  ],
  "next_check_at": "2026-06-19T10:00:00Z"
}
```

### 重要設計方針

- **キャッシュ**: 同一 `(slug, version)` の突合結果は API 側で 24h キャッシュ。ユーザーのスキャンごとに外部DBを叩かない。
- **差分通知**: レスポンスに `is_new: true` フラグを付け、前回から増えた脆弱性のみをメール/バナーで通知（毎回同じ通知でうるさくしない）。
- **レート制限**: ライセンスキーごとに 1 スキャン/時 の制限。WP-Cron の定期実行間隔が 1 日なので通常は問題なし。

---

## 4. 脆弱性データの取得元

| ソース | 特徴 | 用途 |
|---|---|---|
| **WPScan Vulnerability DB** | WordPress 専用・最も網羅的。API: `https://wpscan.com/api/v3/` | プラグイン/テーマの突合（メイン） |
| **Patchstack DB** | 日本語対応の翻訳データあり（将来的に） | セカンダリ |
| **NIST NVD** | CPE ベースで WP 本体・PHP バージョンを突合 | WP 本体 / PHP の CVE チェック |

### WPScan API の利用モデル

- Free tier: 25 リクエスト/日（開発・テスト用）
- Commercial: 月額 $25〜（プラグイン・テーマの大量突合に必要）
- **Pro サブスクリプション収入 → WPScan API 費用** のモデルで成立させる

---

## 5. ライセンス管理

### ライセンスキーのフォーマット

```
WSC-XXXX-XXXX-XXXX-XXXX
```

4 ブロック × 4 文字（英数字大文字）＋ プレフィックス `WSC-`

### ライセンス検証フロー

```
[WordPressプラグイン]
  1. ライセンスキーを入力・保存（wp_options: wsc_license_key）
  2. 有効化リクエスト POST /api/v1/license/verify
     { license_key, site_url, wp_version, plugin_version }
  3. レスポンス: { valid: true, plan: "pro", expires_at: "2027-06-18", ... }
  4. wp_options: wsc_license_status = { valid, expires_at, ... } を保存

[毎回のスキャン時]
  ライセンス有効期限を確認（ローカルキャッシュ）→ 期限切れなら再検証
```

### ライセンス状態

| 状態 | 説明 | 挙動 |
|---|---|---|
| `valid` | 有効 | フルスキャン実行 |
| `expired` | 期限切れ | スキャン不可、設定画面で更新促進バナー表示 |
| `invalid` | キー不正 | スキャン不可、エラーメッセージ |
| `unset` | 未入力 | Pro機能ロック（アップセル表示） |

### 決済・ライセンス発行

- **Lemon Squeezy** を推奨（日本語対応・消費税自動処理・WordPress 向けの事例多数）
- Webhook で購入完了 → cybernote.click でキー生成 → 購入者にメール送信
- 年払い割引（月払い ×12 の 15% 引き）

---

## 6. WordPressプラグイン側の実装設計

### 新規追加クラス

```
includes/
  class-wsc-pro-license.php    ライセンス検証・状態管理
  class-wsc-pro-scanner.php    環境情報収集 → API呼び出し → 結果保存
  class-wsc-pro-notifier.php   バナー表示 / メール送信
  class-wsc-pro-cron.php       WP-Cron スケジュール登録・実行
```

### 既存クラスの変更

| クラス | 変更内容 |
|---|---|
| `class-wsc-admin-page.php` | 設定ページを有効化（ライセンスキー入力欄・通知設定） |
| `class-wsc-admin-page.php` | 脆弱性アラートページを実データで描画 |
| `wp-security-checker.php` | Pro クラスを条件付きロード（ライセンス有効時のみ） |

### Pro 専用設定値（wp_options）

| キー | 内容 |
|---|---|
| `wsc_license_key` | ライセンスキー文字列 |
| `wsc_license_status` | 検証結果 JSON（valid, expires_at 等） |
| `wsc_pro_scan_results` | 最新スキャン結果 JSON |
| `wsc_pro_last_scan` | 最終スキャン日時 (Unix timestamp) |
| `wsc_pro_notified_ids` | 通知済み脆弱性IDセット（差分通知用） |
| `wsc_settings` | 既存。Pro で `scan_schedule`, `notify_email` を追加 |

---

## 7. UIデザイン

### 脆弱性アラートページ（Pro版有効時）

```
┌─────────────────────────────────────────────────────────────┐
│  🛡 脆弱性アラート                          最終スキャン: 今日 10:00 │
├─────────────────────────────────────────────────────────────┤
│  ┌──────────────────────────────────────────────┐           │
│  │ × Contact Form 7 5.9       [Critical 要対応]  │ ›         │
│  │   クロスサイトスクリプティング（XSS）              │           │
│  │   CVE-2026-XXXXX / 修正版: 5.9.5             │           │
│  │   ────────────────────────────── (展開時)    │           │
│  │   【概要】フォームの入力値が...                  │           │
│  │   【対応】プラグイン更新画面から 5.9.5 に更新      │           │
│  │   【リスク】未対応のままだと...                  │           │
│  │   [更新画面を開く]  詳細: wpscan.com/...       │           │
│  └──────────────────────────────────────────────┘           │
│  ┌──────────────────────────────────────────────┐           │
│  │ △ WooCommerce 8.8.3        [High 改善推奨]    │ ›         │
│  │   ...                                        │           │
│  └──────────────────────────────────────────────┘           │
│                                            [今すぐスキャン]    │
└─────────────────────────────────────────────────────────────┘
```

- 無料版の診断項目カードと同じコンポーネント（`render_item()`）を再利用
- 危険度は `critical → recommended`、`high/medium → attention`、`low → attention` にマッピング
- アコーディオン展開（`›` クリック）で詳細・対応手順・参考リンクを表示（無料版と同一パターン）

### 設定ページ（Pro版有効時に有効化）

```
┌─────────────────────────────────┐
│  ライセンス                       │
│  [WSC-XXXX-XXXX-XXXX-XXXX____] │
│  ✓ 有効（2027年6月18日まで）      │
├─────────────────────────────────┤
│  自動スキャンの頻度               │
│  ○ 毎日  ● 週1回（月曜）        │
├─────────────────────────────────┤
│  メール通知                       │
│  ✓ 新しい脆弱性を検知したとき      │
│  送信先: admin@example.com       │
└─────────────────────────────────┘
```

### 管理画面バナー（新規脆弱性検知時）

```
┌──────────────────────────────────────────────────────────────────────┐
│ ⚠ WP Security Checker Pro: 2件の脆弱性が検知されました。 → 詳細を確認   × │
└──────────────────────────────────────────────────────────────────────┘
```

- `admin_notices` フック。1度閉じたら当該スキャン結果に対しては再表示しない。

---

## 8. WP-Cron 自動診断フロー

```
WordPressが管理画面にアクセスされたとき（or外部Cronで定期実行）
  ↓
wp_cron が `wsc_pro_daily_scan` イベントを確認
  ↓ （スケジュール時刻を超えていれば実行）
WSC_Pro_Cron::run_scan()
  ↓
WSC_Pro_Scanner::collect_environment()  … プラグイン一覧等を収集
  ↓
WSC_Pro_Scanner::request_scan()  … POST /api/v1/scan
  ↓
結果を wsc_pro_scan_results に保存
  ↓
WSC_Pro_Notifier::notify_if_needed()
  前回通知済みIDと差分を比較 → 新規脆弱性があれば
    ├─ admin_notices バナーフラグを立てる
    └─ wp_mail() でメール送信
```

---

## 9. セキュリティ考慮事項

### プラグイン側

- ライセンスキーは `wp_options` に保存（平文）。送信時は HTTPS 必須。
- API レスポンスは `wp_remote_post()` で取得し、`wp_remote_retrieve_body()` + `json_decode()` で処理。SSL 検証は無効化しない。
- スキャン結果は `sanitize_*` / `esc_*` 系で適切に無害化してから表示。
- WP-Cron の登録は `activation_hook` で、削除は `deactivation_hook` で行う。

### バックエンド（cybernote.click）側

- ライセンスキーは HTTPS でのみ受付、HTTP をリダイレクト。
- `site_url` はスキャン結果と紐付けるが、脆弱性DBには送信しない。
- 個人情報（メールアドレス等）はスキャン要求に含まない。
- WPScan API キーはサーバー環境変数で管理（コードに直書きしない）。

---

## 10. 実装フェーズ計画

### Phase 1: プラグイン骨組み（バックエンドなし）

**目標**: Pro UI がモックデータで動く状態。設定ページが有効化されること。

- [ ] `class-wsc-pro-license.php` — ライセンスキー入力欄、保存、状態表示（モック）
- [ ] `class-wsc-pro-scanner.php` — 環境情報収集メソッド（API呼び出しはスタブ）
- [ ] 設定ページを有効化（ライセンスキー欄 / スケジュール選択 / 通知メール入力）
- [ ] 脆弱性アラートページにモックデータで表示（カードUIの完成確認）
- [ ] メインファイルにライセンス状態を見てProクラスを条件付きロードするロジック

### Phase 2: バックエンドAPI + ライセンス検証

**目標**: 実際のライセンスキーでスキャンが動作すること。

- [x] cybernote.click 用WordPressプラグイン（backend/cybernote-api）としてスキャンAPI実装済み
- [ ] WPScan API との突合ロジック実装（キャッシュ込み）
- [ ] NIST NVD との WP 本体 / PHP 突合
- [ ] ライセンス発行・検証エンドポイント実装
- [ ] Lemon Squeezy Webhook 連携（購入 → キー発行）

### Phase 3: 自動診断 + 通知

**目標**: 何もしなくても毎日スキャンされ、新規脆弱性をメールで知らせること。

- [ ] `class-wsc-pro-cron.php` 実装（WP-Cron スケジュール登録・解除）
- [ ] `class-wsc-pro-notifier.php` 実装（バナー・メール・差分判定）
- [ ] 設定ページのスケジュール変更でCronを再登録するロジック

---

## 11. 価格設計案

### 基本方針

このプラグインの目的は「セキュリティ対策をわかりやすくすること」であり、価格の壁で諦める人を出さないことが重要。  
**「払えるかどうか迷わない金額」** を最優先とし、サブスクリプションの解約率を下げることにもつながる。

### 価格表

| プラン | 料金 | 対象 |
|---|---|---|
| Free | 永続無料 | すべてのWordPressサイト |
| Pro 月払い | **480円/月** | 1サイト |
| Pro 年払い | **3,800円/年**（約34%引き・月換算317円） | 1サイト |
| Pro マルチサイト | **1,480円/月**（最大5サイト） | 副業・小規模制作者 |

### 価格の根拠

- **480円** はコンビニコーヒー2杯分。「サイトを守るために月500円未満」は多くの個人・スモールビジネスが払える水準。
- **年払い3,800円** は「年間サポート費用」として心理的に受け入れやすく、LTV（顧客生涯価値）を安定させる。
- 類似サービス（Patchstack $5/月・Wordfence Premium $119/年）より安く、かつ **日本語完結・平易な説明** で差別化。
- 値上げは機能が充実してから。初期ユーザーは「応援価格」として獲得し、口コミ・レビューで広げる。

### 収支の目安（参考）

| 契約者数 | 月次収益（月払い480円換算） | 備考 |
|---|---|---|
| 100サイト | 約48,000円/月 | WPScan API費用（~$25/月）を十分カバー |
| 500サイト | 約240,000円/月 | 安定フェーズ |
| 1,000サイト | 約480,000円/月 | Business版の開発投資が可能に |

---

## 12. 今後の検討事項

- **Business版（複数サイト一括管理・PDFレポート）** は Pro が安定してから設計
- WordPress.org への掲載継続可否: Pro版はプレミアムプラグイン扱いになるため、無料版と Pro 版のリポジトリを分けるか、Freemium として1リポジトリで管理するか（Freemium が WordPress.org ガイドラインに合致）
- データ保護: スキャン時に収集するプラグイン一覧はユーザーのサイト環境情報。プライバシーポリシーへの記載と同意取得が必要
