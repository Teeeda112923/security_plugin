---
name: CyberNote Security Checker
description: WordPress security diagnostic plugin for Japanese individual bloggers and small business owners.
version: alpha
colors:
  navy: "#1a2740"
  navy-light: "#243352"
  blue: "#2563eb"
  blue-light: "#eff6ff"
  good: "#16a34a"
  good-light: "#f0fdf4"
  attention: "#d97706"
  attention-light: "#fffbeb"
  recommended: "#dc2626"
  recommended-light: "#fef2f2"
  surface: "#f8fafc"
  surface-card: "#ffffff"
  border: "#e2e8f0"
  border-subtle: "#f1f5f9"
  text-primary: "#0f172a"
  text-secondary: "#64748b"
  text-disabled: "#94a3b8"
  on-navy: "#ffffff"
  on-blue: "#ffffff"
  on-good: "#ffffff"
  on-attention: "#ffffff"
  on-recommended: "#ffffff"
typography:
  heading-lg:
    fontFamily: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"
    fontSize: 1.5rem
    fontWeight: "700"
    lineHeight: 2rem
    letterSpacing: -0.01em
  heading-md:
    fontFamily: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"
    fontSize: 1.125rem
    fontWeight: "600"
    lineHeight: 1.75rem
  body:
    fontFamily: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"
    fontSize: 0.875rem
    fontWeight: "400"
    lineHeight: 1.5rem
  body-sm:
    fontFamily: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"
    fontSize: 0.8125rem
    fontWeight: "400"
    lineHeight: 1.25rem
  label:
    fontFamily: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"
    fontSize: 0.75rem
    fontWeight: "600"
    lineHeight: 1rem
    letterSpacing: 0.02em
  mono:
    fontFamily: "'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace"
    fontSize: 0.8125rem
rounded:
  sm: 6px
  md: 10px
  lg: 14px
  xl: 20px
  full: 9999px
spacing:
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 32px
  2xl: 48px
components:
  card:
    backgroundColor: "{colors.surface-card}"
    textColor: "{colors.text-primary}"
    rounded: "{rounded.lg}"
    padding: "{spacing.lg}"
  card-hover:
    backgroundColor: "#fafbfc"
  status-badge-good:
    backgroundColor: "{colors.good-light}"
    textColor: "{colors.good}"
    typography: "{typography.label}"
    rounded: "{rounded.full}"
    padding: 3px 10px
  status-badge-attention:
    backgroundColor: "{colors.attention-light}"
    textColor: "{colors.attention}"
    typography: "{typography.label}"
    rounded: "{rounded.full}"
    padding: 3px 10px
  status-badge-recommended:
    backgroundColor: "{colors.recommended-light}"
    textColor: "{colors.recommended}"
    typography: "{typography.label}"
    rounded: "{rounded.full}"
    padding: 3px 10px
  diagnostic-item:
    backgroundColor: "{colors.surface-card}"
    textColor: "{colors.text-primary}"
    rounded: "{rounded.md}"
    padding: 14px 16px
  diagnostic-item-hover:
    backgroundColor: "{colors.surface}"
  guide-panel:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text-secondary}"
    rounded: "{rounded.md}"
    padding: "{spacing.md}"
  button-primary:
    backgroundColor: "{colors.blue}"
    textColor: "{colors.on-blue}"
    typography: "{typography.label}"
    rounded: "{rounded.sm}"
    padding: 8px 16px
  button-primary-hover:
    backgroundColor: "#1d4ed8"
  button-secondary:
    backgroundColor: "{colors.surface-card}"
    textColor: "{colors.text-secondary}"
    typography: "{typography.label}"
    rounded: "{rounded.sm}"
    padding: 6px 14px
  button-secondary-hover:
    backgroundColor: "{colors.surface}"
  summary-hero-good:
    backgroundColor: "{colors.good-light}"
    textColor: "{colors.good}"
    rounded: "{rounded.lg}"
  summary-hero-issues:
    backgroundColor: "{colors.recommended-light}"
    textColor: "{colors.recommended}"
    rounded: "{rounded.lg}"
  chip-recommended:
    backgroundColor: "{colors.recommended}"
    textColor: "{colors.on-recommended}"
    typography: "{typography.label}"
    rounded: "{rounded.full}"
    padding: 2px 10px
  chip-attention:
    backgroundColor: "{colors.attention}"
    textColor: "{colors.on-attention}"
    typography: "{typography.label}"
    rounded: "{rounded.full}"
    padding: 2px 10px
  chip-good:
    backgroundColor: "{colors.good}"
    textColor: "{colors.on-good}"
    typography: "{typography.label}"
    rounded: "{rounded.full}"
    padding: 2px 10px
  sidebar-nav:
    backgroundColor: "{colors.navy}"
    textColor: "{colors.on-navy}"
  sidebar-nav-active:
    backgroundColor: "{colors.navy-light}"
    textColor: "{colors.on-navy}"
---

## Overview

CyberNote Security Checker は「頼りになる身近な専門家」のUIを目指す。脅かすのではなく、かかりつけ医の健康診断のように、現状を落ち着いた言葉で伝え、何をすればいいかを明確に示す。

対象ユーザーは日本の個人ブロガーと小規模事業者。専門用語を読まずに画面を「見るだけ」で状況が分かることが最優先。

参照ビジュアル: Linear や Notion の管理画面。落ち着いたネイビーと白を基調とし、ステータスカラー（緑・橙・赤）だけが意味を持つ。派手さより「ちゃんとしてる感」。

## Colors

パレットは2つの軸で構成される。「ベース（ネイビー×ホワイト）」と「ステータス（緑・橙・赤）」。

- **Navy {colors.navy}:** ブランドの背骨。サイドバー・ヘッダー・バッジのアクセント。信頼感と専門性を担う。紺が濃いほど落ち着く。
- **Blue {colors.blue}:** 操作の色。リンク・プライマリボタン・フォーカスリングにのみ使う。コンテンツ側には混入させない。
- **Good {colors.good}:** 問題なし。安心の緑。テキストラベルと薄いライト背景 {colors.good-light} をセットで使い、「通過」を柔らかく表現する。
- **Attention {colors.attention}:** 改善推奨。アンバー（琥珀）。「危険」ではなく「確認してほしい」のトーン。警告灯ではなく注意書き。
- **Recommended {colors.recommended}:** 要対応。赤は最小限に抑える。この色が出たとき、ユーザーは最初にここを見る。乱用しない。
- **Surface {colors.surface}:** ページ背景。青みのある薄いグレー。純白より柔らかく、コンテンツが浮き上がって見える。
- **Border {colors.border}:** カード・リストの区切り線。存在は感じるが主張しない。

ステータスカラーは **バッジとアイコンだけ** に使う。カード全体を赤く染めない。「赤いエリア」は圧迫感が強く、ユーザーを不安にさせる。

## Typography

フォントは OS の標準 UI フォントスタック（Inter/SF Pro/Segoe UI）に統一する。外部フォントは読み込まない。WordPress 管理画面のパフォーマンスを損なわないため。

- **Heading** は診断カテゴリ名・ページタイトルにのみ使う。1ページに最大2段階（lg と md）で収める。
- **Body** が基本。診断結果のラベル・メッセージはこのサイズで読みやすく。
- **Label** は小さな補足情報（ステータスバッジ・日時）に使う。大文字化（text-transform: uppercase）は避ける。日本語が読みにくくなるため。
- **Mono** はコードスニペット（`define('WP_DEBUG', false);` のような例示）専用。インラインとブロック両方で使う。

行間（line-height）は日本語の詰まりを防ぐため、英字基準より1割ほど広めに取る。

## Layout & Spacing

8px グリッドを基準とする。全ての余白は 4・8・16・24・32・48px のいずれか。

- **カード内余白:** 24px (lg)。窮屈に感じさせない。
- **リストアイテム余白:** 縦 14px、横 16px。コンパクトだが触りやすい。
- **セクション間余白:** 32px (xl)。カテゴリAとBの間など、区切りが必要な場所。
- **ウィジェット:** ダッシュボードウィジェットは幅が制約されるため、余白を md (16px) に落とす。

管理画面は 2カラム（メイン＋ WordPress サイドバー）で動作する。メインコンテンツ幅は最大 960px で収める。それ以上広げてもスキャンしにくくなる。

## Elevation & Depth

影は最小限に抑える。「浮いている」演出より「整理されている」演出を優先する。

- **カード:** `box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04)` — ほぼ見えない、存在だけを示す。
- **ホバー:** 背景色を `{colors.surface}` に変えるだけ。影を増やさない。
- **アコーディオンパネル（ガイド）:** カード内の凹み要素。背景を `{colors.surface}` にして区別する。ボーダーは不要。

Glassmorphism・グラデーション・アニメーションは使わない。WordPress 管理画面の既存UIと違和感なく共存することを優先する。

## Shapes

角丸は「柔らかさと整頓感」のバランスを取る。丸すぎると玩具っぽくなり、鋭すぎると冷たくなる。

- **カード:** 14px (lg) — 診断カードの外側。存在感がある丸み。
- **リストアイテム:** 10px (md) — カード内の各診断項目。カードより小さく、入れ子感を演出。
- **バッジ・チップ:** 9999px (full) — ステータスバッジは完全な丸型ピル。ラベルとして読まれる形。
- **ボタン:** 6px (sm) — WordPress の標準ボタンに近い丸み。違和感なく馴染む。

## Components

### 診断カード（Diagnostic Item）

診断1件ずつを表すリスト行。左から「ステータスアイコン → 項目名・メッセージ → ステータスバッジ → アコーディオン展開ボタン」の順。

- ステータスアイコン（✓ / △ / ×）は色と記号の両方で伝える。色覚多様性への配慮。
- アコーディオンは「ガイド」として機能する。対応手順・リスク説明・参考リンクを格納。
- コンパクトモード（ウィジェット用）では、メッセージとアコーディオンを非表示にしてアイコン＋ラベルのみ表示。

### ステータスバッジ

3種類のみ。「問題なし（緑）」「改善推奨（橙）」「要対応（赤）」。

- バッジの文字は短く統一。8文字以内。
- 背景色（light）＋ テキスト色の組み合わせのみ。ボーダーは不要。

### サマリーヒーロー

ページ上部またはウィジェット上部に配置する全体サマリー。

- 問題なし: 緑ライト背景 + チェックマーク + 「良好です」
- 問題あり: 赤ライト背景 + 感嘆符 + 「要確認 N件」
- 件数チップ（赤・橙・緑）を横並びで表示。視覚的なスコアカード。

### アコーディオンガイド

各診断項目に紐づく補足情報。「›」ボタンで展開。

- 背景: `{colors.surface}` — 本文領域とのコントラストで「補足情報」感を出す。
- セクション: 「対応手順」「対応しないと…」「詳細はこちら」の3部構成。
- コードブロック: `{typography.mono}` で視覚的に区別。背景はやや暗い `#f1f5f9`。

### プロバッジ・ビジネスバッジ

メニューラベルに付く小さなタグ。

- Pro: ネイビー背景 + 白テキスト。目立つが主張しすぎない。
- Business: グレー背景 + 白テキスト。将来機能であることをトーンダウンして表現。

## Do's and Don'ts

- **Do** 3色のステータスカラーを一貫して使う。診断結果のどこを見ても同じ色が同じ意味を持つこと。
- **Do** 日本語テキストの行間を英字より広めにとる。詰まっていると非エンジニアには読みにくい。
- **Do** ホバー状態は色の明度変化だけで表現する。影や枠線を増やさない。
- **Do** 「要対応（赤）」が出たときに視線が最初にそこへ向かうようにする。赤は希少価値を保つ。
- **Don't** カード全体を赤や橙で塗りつぶす。圧迫感が出てユーザーが不安になる。バッジとアイコンだけで十分。
- **Don't** アニメーションを多用する。診断結果を確認するUIに「動き」は必要ない。再診断のローディング時だけ許容する。
- **Don't** 外部フォントを読み込む。WordPress 管理画面の速度とオフライン環境への配慮。
- **Don't** ダークモードに対応しようとする。WordPress 管理画面自体がダークモード対応を持つ。プラグイン側で独自対応すると競合する。
- **Don't** アイコン（dashicons）を装飾目的で多用する。機能と紐づくアイコンだけ使う。
- **Don't** 「要対応」「改善推奨」以外の独自ステータスを増やす。3段階で十分。判断を迷わせない。
