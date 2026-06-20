# WP Security Checker — プロジェクトコンテキスト

このファイルはClaude Code用の恒久的な指示書です。毎セッションの開始時に読み込まれます。

## プロジェクト概要

WordPress向けの軽量セキュリティプラグイン。コンセプトは「使っているプラグインの危険を、専門用語なしの日本語で教えてくれる、軽いセキュリティ番」。
フリーミアムのサブスク事業。無料版は10項目の設定診断（外部通信なし）、Pro版は脆弱性DB突合・自動診断・メール通知を担う。
ターゲットは日本の個人ブロガー・小規模事業者・Web制作会社（非エンジニア中心）。

## リポジトリ規約

- リポジトリ: teeeda112923/security_plugin
- メインファイル: wp-security-checker.php
- 接頭辞: `WSC_`（クラス・定数）/ `wsc-`（スラッグ・CSSクラス・ファイル名）/ `wsc_`（関数・wp_options・AJAX）
- Text Domain: wp-security-checker
- 配布フォルダ名: wp-security-checker/（GitHubのブランチ名が混ざらないよう注意）

## 設計方針（必ず守る）

- **自動で変更しない**: サイトの設定やファイルを勝手に書き換えない。診断と案内にとどめ、判断は利用者に委ねる。
- **平易な日本語**: 専門用語を避ける。使う場合はかっこ書きで噛み砕く。過度に不安をあおらない。
- **軽量設計**: WAF・ファイアウォール・マルウェアスキャン・ログ監視・ログイン試行制限などの常駐型機能は作らない。サイト速度を落とさない。
- **無料版は外部通信なし**: 無料の診断はサイト内の状態とWordPress組み込み情報の読み取りだけで完結させる（APIキー不要）。
- **推測で実装しない**: DB設計・通知の仕組み・Pro版の内部実装は、仕様が確定するまでコードを書かない。不明点は実装せず確認する。

## 無料版とProの線引き（設計の背骨）

判定に必要なデータの所在で機能の所属を決める。

- サイト内の状態だけで判定できる → 無料
- 外部の脆弱性データベースとの突合が必要 → Pro
- 定期実行・メール通知が必要 → Pro

例: 「更新が来ているか」は無料。「その更新が脆弱性修正か・どれだけ危険か」はPro。

## 無料版の診断項目（全10項目）

すべて good / attention / recommended の三段階で表示する。

カテゴリA（バージョン鮮度・class-wsc-category-a.php / WSC_Category_A）
- a1 WordPress本体（最新=good / 新メジャーあり=attention / メンテナンス版未適用=recommended）
- a2 PHPバージョン（8.4以上=good / 8.2〜8.3=attention / 8.1以下=recommended）
- a3 プラグイン・テーマ更新（0件=good / 1件以上=attention。recommendedは設けない。標準の更新画面へ案内し、プラグイン自身は更新を実行しない）

カテゴリB（ハードニング設定・class-wsc-category-b.php / WSC_Category_B）
- b1 WP_DEBUG（off=good / logのみ=attention / 画面表示on=recommended）
- b2 DISALLOW_FILE_EDIT（true=good / false・未定義=recommended）
- b3 管理者ユーザー名（admin/administratorなし=good / 存在する=attention）
- b4 HTTPS（https=good / http=recommended）
- b5 DBテーブルプレフィックス（wp_以外=good / wp_=attention）
- b6 XML-RPC（無効=good / 有効=attention）
- b7 REST APIユーザー列挙（不可=good / 可能=attention）

recommended（強い警告）は「漏れる・壊される・盗まれる」に該当するものだけ（a1メンテナンス版・b1画面表示・b2・b4）。既存サイトで変更にリスクがある項目（b3・b5）は attention 止まりにし、変更を急かさない。

## 実装構造（既存コードに合わせる）

- `WSC_Diagnostics::run()` が全チェックを実行し、`['a'=>[...], 'b'=>[...], 'summary'=>['total'=>10,'issues'=>N]]` を返す。
- `WSC_Renderer::render_item($item, $args)` が1件をカードHTMLとして描画。`$args` は compact / show_message / show_action。
- アコーディオン（`›`）は `guide_data()` の内容を展開: `steps`（対応手順）/ `risk`（対応しないリスク）/ `links`（参考リンク url・label）/ `has_update_link`（a3のみ更新画面ボタン）。
- ステータス表示: recommended=赤・×・最優先 / attention=橙・△ / good=緑・✓。
- 管理画面は `WSC_Admin_Page`（7サブメニュー）、ダッシュボードは `WSC_Dashboard_Widget`。
- AJAX: `wsc_refresh`（ウィジェット）/ `wsc_admin_refresh`（管理画面）。nonceは各 `wsc_refresh_nonce` / `wsc_admin_refresh_nonce`。
- CSSデザイントークンは dashboard.css の `--wsc-*`（navy/blue/good/attention/recommended 等）。

## Pro版（概要）

- ライセンスキー形式 `WSC-XXXX-XXXX-XXXX-XXXX`、wp_options の `wsc_license_key` に保存。決済は Lemon Squeezy。
- スキャンは cybernote.click 経由（B案：自前SaaS）。プラグインは外部脆弱性DBへ直接接続しない。
- 価格: Pro 月480円 / 年3,800円 / マルチサイト月1,480円（最大5サイト）。
- 詳細は docs/pro-design.md を参照。

## 設計ドキュメント

詳細はリポジトリの設計書を参照（必要なときに読むこと。常時読み込みは不要）。
- docs/PROJECT_OVERVIEW.md … プロダクト全体ビジョン（無料/Proの振り分け、5段階ロードマップ、表示テンプレート、安全実装基準）
- docs/pro-design.md … Pro版の技術設計（API・ライセンス・課金・Cron・実装フェーズ）
- docs/session-handoff.md … 開発経緯・現状スナップショット
- docs/wsc-business-design.md … 事業設計（市場・価格・ロードマップ）
- docs/wsc-free-tier-scope.md … 無料版スコープと無料/Proの線引き
- docs/wsc-category-a-spec.md … バージョン鮮度の判定しきい値と文面
- docs/wsc-category-b-spec.md … ハードニング設定の判定しきい値と文面

## 現在の実装状況

- 無料版は全10項目の診断・SaaS風UI・7サブメニュー・ダッシュボードウィジェット・アコーディオンまで実装済み。WordPress.org申請が可能な状態。
- Pro版は設計済み（docs/pro-design.md）。未実装。
- 次の候補: Pro版 Phase 1（ライセンスUI・設定ページ有効化・脆弱性アラートをモックデータ表示）/ 無料版の項目追加 / WordPress.org申請 / cybernote.click バックエンド構築。
