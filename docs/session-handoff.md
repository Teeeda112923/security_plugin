# セッション引き継ぎドキュメント
# WP Security Checker — 開発経緯・現状・Pro版設計

作成日: 2026-06-19  
ブランチ: `claude/wonderful-thompson-qrp9ed`  
リポジトリ: `teeeda112923/security_plugin`  
作業ディレクトリ: `/home/user/security_plugin`

---

## 1. このプロジェクトの目的

WordPressサイトのセキュリティ設定とバージョン状態を診断し、**日本語で改善手順を提示する**WordPressプラグイン。

**重要な設計原則（変えてはいけない）:**
- 診断のみ。自動修正・自動変更は一切しない
- 無料版は外部通信ゼロ（APIキー不要・軽量）
- WAF・ファイアウォール・マルウェアスキャン・ログ監視・ログイン試行制限などの「常駐型セキュリティ機能」は実装しない
- 日本語ネイティブ。平易な言葉で説明する

**ビジネスモデル:** freemium SaaS
- Free: 10項目の診断（外部通信なし）
- Pro: 脆弱性DB突合・自動診断・メール通知（月480円）
- Business: 複数サイト一括管理・PDFレポート（将来）

---

## 2. 開発の経緯（会話の流れ）

### フェーズ1: 初期構築
設計書（ビジネス設計・無料版スコープ・カテゴリA仕様・カテゴリB仕様）をもとにプラグインをゼロから作成。

### フェーズ2: WordPress.orgへの提出準備
readme.txt・uninstall.php・icon.svgを追加。

### フェーズ3: UIの全面リデザイン
ユーザーがモックアップ画像とZIPファイルを提供。SaaS風のモダンUIに全面改修。
- Hero（ゲージ＋カウントカード）
- 優先対応カード（Top5）
- 2カラムグリッド（カテゴリA・B）
- 7つのサブメニューページ

### フェーズ4: 細かい改善（順に実施）
1. 画面スクロール問題 → 2カラムグリッドで解消
2. 「再診断」ボタン内アイコンのズレ → CSSで修正
3. 「CVEアラート」→「脆弱性アラート」に名称変更
4. `›` クリックでアコーディオン展開（対応手順・リスク・参考リンク）
5. アコーディオン内に公式サイトへの参考リンクを追加

### フェーズ5: Pro版設計（現在）
B案（自前SaaSバックエンド経由）で設計書を作成。
価格を「良心的に」という要望で月480円に決定。

---

## 3. ファイル構成（現在）

```
wp-security-checker/
├── wp-security-checker.php          # メインファイル（プラグインヘッダー・クラスロード）
├── uninstall.php                    # アンインストール処理
├── readme.txt                       # WordPress.org形式
├── .gitignore
├── assets/
│   ├── icon.svg                     # プラグインアイコン（シールド）
│   └── css/
│       ├── dashboard.css            # 共有デザイントークン＋ウィジェットスタイル＋アコーディオン
│       └── admin-page.css           # 専用管理画面スタイル
├── includes/
│   ├── class-wsc-category-a.php     # カテゴリA（3項目）
│   ├── class-wsc-category-b.php     # カテゴリB（7項目）
│   ├── class-wsc-diagnostics.php    # オーケストレーター
│   ├── class-wsc-renderer.php       # 共通描画ヘルパー（アコーディオン含む）
│   ├── class-wsc-dashboard-widget.php # WordPressダッシュボードウィジェット
│   └── class-wsc-admin-page.php     # 専用管理ページ（7サブメニュー）
├── languages/
│   └── wp-security-checker-ja.po
└── docs/
    ├── pro-design.md                # Pro版設計書
    └── session-handoff.md           # このファイル
```

---

## 4. 診断項目（全10件）

### カテゴリA: バージョン鮮度チェック
| ID | チェック内容 | good | attention | recommended |
|---|---|---|---|---|
| a1 | WordPress本体 | 最新 | 新メジャーあり | メンテナンス版（セキュリティ修正）あり |
| a2 | PHPバージョン | 8.4以上 | 8.2〜8.3 | 8.1以下 |
| a3 | プラグイン・テーマ更新 | 0件 | 1件以上 | （設けない） |

### カテゴリB: ハードニング設定チェック
| ID | チェック内容 | good | attention | recommended |
|---|---|---|---|---|
| b1 | WP_DEBUG | off | logのみ | 画面表示on |
| b2 | DISALLOW_FILE_EDIT | true | — | false/未定義 |
| b3 | 管理者ユーザー名 | admin/administratorなし | 存在する | — |
| b4 | HTTPS | https | — | http |
| b5 | DBテーブルプレフィックス | wp_以外 | wp_ | — |
| b6 | XML-RPC | 無効 | 有効 | — |
| b7 | REST APIユーザー列挙 | 不可 | 可能 | — |

**ステータスの意味:**
- `recommended`（要対応）: 赤・×アイコン・最優先
- `attention`（改善推奨）: 橙・△アイコン
- `good`（問題なし）: 緑・✓アイコン

---

## 5. 主要クラスの説明

### `WSC_Diagnostics::run()`
全チェックを実行。戻り値の構造:
```php
[
  'a'       => [ /* カテゴリAの結果配列 */ ],
  'b'       => [ /* カテゴリBの結果配列 */ ],
  'summary' => [ 'total' => 10, 'issues' => 3 ],
]
```

### `WSC_Renderer::render_item($item, $args)`
1件の診断結果をカードHTMLとして出力。
- `$args['compact']`: コンパクト表示（ウィジェット用）
- `$args['show_message']`: メッセージ表示
- `$args['show_action']`: アクションボタン表示
- アコーディオン: `›`ボタンを押すと`guide_data()`のガイド内容が展開

各チェックIDに対応するガイドデータ（`guide_data()`）:
- `steps`: 対応手順（`<br>`・`<code>`タグ使用可）
- `risk`: 対応しないリスク
- `links`: 参考リンク配列（`url`, `label`）
- `has_update_link`: trueならWordPress更新画面へのボタンを表示（a3のみ）

### `WSC_Admin_Page` のサブメニュー定数
```php
MENU_SLUG     = 'wp-security-checker'
SLUG_RESULTS  = 'wp-security-checker-results'
SLUG_VERSION  = 'wp-security-checker-version'
SLUG_HARDENING= 'wp-security-checker-hardening'
SLUG_CVE      = 'wp-security-checker-cve'      ← 脆弱性アラート（URLスラッグ）
SLUG_REPORT   = 'wp-security-checker-report'
SLUG_SETTINGS = 'wp-security-checker-settings'
```

### AJAXアクション
| アクション名 | nonce名 | 場所 |
|---|---|---|
| `wsc_refresh` | `wsc_refresh_nonce` | ダッシュボードウィジェット |
| `wsc_admin_refresh` | `wsc_admin_refresh_nonce` | 専用管理画面 |

---

## 6. CSSデザイントークン（dashboard.css）

```css
--wsc-navy: #0B1F4D
--wsc-blue: #2563EB
--wsc-blue-2: #1D4ED8
--wsc-bg: #F6F8FB
--wsc-card: #FFFFFF
--wsc-border: #E3E8F1
--wsc-border-strong: #CBD5E1
--wsc-text: #0F172A
--wsc-muted: #64748B
--wsc-good: #15803D          / --wsc-good-bg: #ECFDF3    / --wsc-good-border: #BBF7D0
--wsc-attention: #B45309     / --wsc-attention-bg: #FFFBEB / --wsc-attention-border: #FDE68A
--wsc-recommended: #B91C1C   / --wsc-recommended-bg: #FEF2F2 / --wsc-recommended-border: #FECACA
```

---

## 7. Pro版設計（docs/pro-design.md の要約）

### アーキテクチャ（B案：自前SaaS経由）
```
WordPressプラグイン
  → POST https://cybernote.click/api/v1/scan
      （ライセンスキー＋インストール済みプラグイン一覧）
  → cybernote.click サーバー
      → WPScan Vulnerability DB / NIST NVD と突合
      → キャッシュして結果JSON返却
  → WordPress側でバナー表示・メール通知
```

### 無料版との差別化
- 無料版: 「3件の更新待ちがあります」
- Pro版: 「Contact Form 7 5.9 にCriticalなXSSがあります。今すぐ更新を」

### スキャンAPIリクエスト/レスポンス
リクエスト:
```json
{
  "license_key": "WSC-XXXX-XXXX-XXXX-XXXX",
  "site_url": "https://example.com",
  "wp_version": "6.5.3",
  "php_version": "8.2.18",
  "plugins": [{ "slug": "contact-form-7", "version": "5.9", "name": "Contact Form 7" }],
  "themes": [{ "slug": "storefront", "version": "4.5.0", "name": "Storefront" }]
}
```

レスポンスの`vulnerabilities`配列の各要素:
```json
{
  "type": "plugin",
  "slug": "contact-form-7",
  "installed_version": "5.9",
  "fixed_version": "5.9.5",
  "severity": "critical",
  "vuln_type_ja": "クロスサイトスクリプティング（XSS）",
  "title_ja": "...",
  "description_ja": "...",
  "action_ja": "...",
  "cve_id": "CVE-2026-XXXXX",
  "references": ["https://wpscan.com/..."]
}
```

### 価格（確定）
| プラン | 料金 |
|---|---|
| Free | 永続無料 |
| Pro 月払い | 480円/月（1サイト） |
| Pro 年払い | 3,800円/年（月換算317円、約34%引き） |
| Pro マルチサイト | 1,480円/月（最大5サイト） |

根拠: 「セキュリティ対策をわかりやすくする」目的のため価格の壁を作らない。コンビニコーヒー2杯分。

### ライセンスキー
- フォーマット: `WSC-XXXX-XXXX-XXXX-XXXX`
- 決済: Lemon Squeezy（日本語対応・消費税自動処理）
- 保存: `wp_options`の`wsc_license_key`

### 実装フェーズ
1. Phase 1: プラグイン骨組み（ライセンスキー入力欄・設定ページ有効化・脆弱性アラートページをモックデータで表示）
2. Phase 2: cybernote.click バックエンドAPI＋脆弱性突合＋Lemon Squeezy連携
3. Phase 3: WP-Cron自動診断＋メール差分通知

### 新規追加予定クラス（Phase 1〜）
```
includes/
  class-wsc-pro-license.php    ライセンス検証・状態管理
  class-wsc-pro-scanner.php    環境情報収集→API呼び出し→結果保存
  class-wsc-pro-notifier.php   バナー表示/メール送信
  class-wsc-pro-cron.php       WP-Cronスケジュール登録・実行
```

---

## 8. これまでに解決した主なバグ・問題

1. **GitHubからのZIPがインストールできない** → ブランチ名がフォルダ名に入るため、正しいフォルダ名`wp-security-checker/`で手動ZIP作成
2. **git push が non-fast-forward で失敗** → `git pull --rebase`で解決
3. **再診断ボタン内のアイコンがずれる** → `.wsc-refresh-btn .dashicons`に`display:inline-flex; align-items:center; justify-content:center; width:18px; height:18px; font-size:18px; line-height:1`を追加
4. **`render_item`の引数シグネチャ変更** → 旧: `render_item($item, $results)` → 新: `render_item($item, $args=[])`

---

## 9. 現在のgitブランチ状態

ブランチ: `claude/wonderful-thompson-qrp9ed`  
リモート: `origin/claude/wonderful-thompson-qrp9ed`（最新をプッシュ済み）

最近のコミット（新しい順）:
1. `bb0b202` Revise Pro pricing to be more accessible
2. `b93d1cb` Add Pro version design document
3. `954c84a` Add reference links to accordion guide panels
4. `e5b75a2` Add .gitignore to exclude build artifacts
5. `0f00bc5` Add accordion guide panel to diagnostic items
6. `97850da` Rename "CVEアラート" to "脆弱性アラート" in UI
7. `427fb0a` Fix refresh button icon vertical alignment
8. `51524de` Complete UI redesign: SaaS-style dashboard + 7 submenu pages

---

## 10. 次のアクション候補

以下のどれかを進めるか、ユーザーに確認する:

**A. Pro版 Phase 1 の実装開始**
- `class-wsc-pro-license.php`作成（ライセンスキー入力・検証UI）
- 設定ページを有効化（現在はグレーアウト）
- 脆弱性アラートページにモックデータで実際のUI表示

**B. 無料版の追加改善**
- 診断項目の追加（例: ファイルパーミッション、wp-config.phpの保護など）
- 診断結果のCSVエクスポート

**C. WordPress.org への申請**
- 現在の無料版を提出できる状態。申請手順の確認・実施。

**D. cybernote.click バックエンドの構築（Pro版 Phase 2）**
- スキャンAPIは cybernote.click 用WordPressプラグイン（backend/cybernote-api）として実装済み（B1）
- データ源はWPVulnerability.com（無料）で開始、キャッシュ実装済み。将来WPScan等へ差し替え可能
- 次: Pro接続プラグイン（B2）→ Lemon Squeezy決済・メール通知（B3）

---

## 11. 将来アイデアメモ（未設計・検討候補）

### OSINT機能

外部情報との突合によるセキュリティ診断。設計はまだ不要、思いつきメモ。

**無料でできるもの（サイト内完結）**
- `readme.html` / `license.txt` の公開によるWPバージョン漏洩チェック
- `robots.txt` への内部パス記載チェック
- `wp-login.php` / `xmlrpc.php` の公開状態チェック（b6・b7の延長）

**Pro向け（外部API必要）**
- **HaveIBeenPwned** — 管理者メールアドレスが流出DBに含まれているか（最優先候補）
- **Google Safe Browsing API** — サイトがフィッシング・マルウェアとして報告されているか
- Shodan / Censys — サーバーの公開情報の過剰露出チェック

> 実装するなら HaveIBeenPwned 一本から。非エンジニアにも伝わりやすく、無料API枠あり。Pro Phase 2〜3 のタイミングで検討。

---

## 12. SOC Agent的アプローチの応用（Pro Phase 2〜3 設計メモ）

参考: [ZOZOテックブログ「Claude CodeがSOC業務を全自動でやってくれるってさ」](https://techblog.zozo.com/entry/soc-claude-agent)

ZOZOはClaude Codeを使ってSplunk・OpenCTIと連携したSOCアラート自動トリアージを構築。
この発想をこのプラグインのスケール（個人ブロガー・中小規模）に落とし込むと以下の対応になる。

| ZOZOの要素 | このプラグインの対応 |
|-----------|-----------------|
| /loop 1h の定期実行 | WordPress Cron（Pro Phase 3） |
| Splunk MCPでログ取得 | cybernote.click APIで脆弱性取得 |
| Slackへのレポート投稿 | メール通知（Pro Phase 3） |
| 優先度判定・日本語説明生成 | Claude APIで重大度を日本語化（バックエンド側） |
| SubAgentの並列調査 | 複数プラグイン・テーマを一括スキャン |

**設計方針：**
- Claude APIはcybernote.clickのバックエンド側で呼ぶ（プラグインは結果を受け取るだけ）
- プラグインが直接Claude APIを叩くとAPIキー管理・コスト・レートリミットが問題になる
- cybernote.click側で「スキャン → Claude APIで日本語分析 → 結果をキャッシュ → プラグインに返す」が正しいアーキテクチャ
- WordPressのCron（wp-cron）で定期スキャンをトリガーし、結果をメール通知する（Phase 3）

**ZOZOの実装で参考にできる点：**
- Hooksで破壊的操作を禁止する設計思想（Read権限のみで完結）→ 診断のみ・自動変更なしの設計原則と一致
- memoryで過去の調査履歴を活用 → 「前回スキャンから新たに追加された脆弱性のみ通知」に応用可能
- SubAgentの並列起動 → cybernote.click側でプラグインごとに並列スキャンする構成に応用可能
