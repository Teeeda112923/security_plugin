# ChatGPT用プロンプト：詳細ガイドをCyberNote固定ページHTMLに変換

`docs/website/` の詳細版MD（6ファイル）を、CyberNoteの既存ガイドと同じデザインの
1ページ完結HTMLに変換するためのプロンプトです。

使い方:
1. 下の「===== プロンプトここから =====」以降をすべてコピーしてChatGPTに貼り付ける
2. 続けて、`docs/website/` の6つのMDファイル（index / getting-started /
   how-to-read / category-a / category-b / faq）の中身を貼り付ける
3. 出力されたHTMLを、CyberNoteの新規固定ページのカスタムHTMLブロックに貼る

---

===== プロンプトここから =====

あなたはWordPressサイト「CyberNote」の編集者兼フロントエンド実装者です。
これから渡す複数のMarkdown文書（WordPressセキュリティ診断プラグイン
「Site Security Checker」の詳細な使い方ガイド）を、CyberNoteの固定ページに
そのまま貼り付けられる **1ページ完結のHTML** に変換してください。

## 最重要の前提

- 出力は `<style>` ブロックから始まり、最後の `</div>` で終わる、貼り付け可能なHTMLのみ。
  前後の説明文・コードフェンス（```）・マークダウンは一切付けない。
- CSSはすべて `.cn-wsc-guide2` という親クラスの中だけに効くようにスコープする
  （CyberNoteの既存ページが `.cn-wsc-guide` を使っているため、衝突を避けて末尾に「2」を付ける）。
- WordPressテーマ（Cocoon等）のH2/H3装飾を引き継がないよう、`.cn-wsc-guide2` 内の
  見出しは装飾をリセットする（`background`/`border`/`box-shadow` を打ち消し、
  `::before`/`::after` を `content: none` で消す）。`.entry-content` と `.article` の
  両方の子孫セレクタでも上書きすること。
- レスポンシブ対応（スマホでカード・表・ボタンが崩れない）。
  グリッドは幅860px以下で1カラムに落とす。表は横スクロール可能にする。
- 外部の画像・フォント・JS・CDNは使わない。素のHTML+CSSのみ。
- 日本語。専門用語はかみ砕く。過度に不安をあおらない。
- 元の詳細MDにあるコード例（wp-config.php の記述や .htaccess、functions.php の
  スニペット等）は省略せず、コードブロックとして見やすく表示する。

## 使うデザイントークン（CyberNote既存ページと色を揃える）

`.cn-wsc-guide2` に以下のCSS変数を定義して使う:

```
--cn-navy:#0B1F4D; --cn-blue:#2563EB; --cn-blue-soft:#EFF6FF;
--cn-green:#16A34A; --cn-green-soft:#ECFDF5;
--cn-orange:#D97706; --cn-orange-soft:#FFFBEB;
--cn-red:#DC2626;  --cn-red-soft:#FEF2F2;
--cn-text:#1F2937; --cn-muted:#5B6475;
--cn-border:#E5E7EB; --cn-border-strong:#CBD5E1;
--cn-bg:#F8FAFC; --cn-card:#FFFFFF;
```

本文: `max-width:1040px; margin:0 auto; font-size:16px; line-height:1.9;`
リンク: 色 `--cn-blue`、太字、下線なし（hoverで下線）。

## 使うコンポーネント（クラス名と役割）

既存ページと同じ構成・見た目に揃えること。必要なら新規クラスを足してよいが、
命名は `cn-wsc-` 接頭辞で統一する。

- `.cn-wsc-hero` … ページ冒頭の導入ブロック。左に6pxのネイビー帯（`::before`）。
  中に `.cn-wsc-eyebrow`（青い丸タグ）、h1、リード文、`.cn-wsc-hero-actions`
  （`.cn-wsc-button` と `.cn-wsc-button-sub` のページ内アンカー）。
- `.cn-wsc-section` … 各セクション。h2は下線（`border-bottom`）付き。
  `.cn-wsc-section-lead` でリード文。
- `.cn-wsc-grid`（3カラム）/ `.cn-wsc-grid-2`（2カラム）＋ `.cn-wsc-card`
  （白カード・角丸・薄い影）。カード内に `.cn-wsc-icon`（数字や記号の角丸バッジ）とh3。
- `.cn-wsc-steps` / `.cn-wsc-step` … 手順。`counter-reset`/`counter-increment` で
  左に連番のネイビー丸バッジ（`::before`）。
- `.cn-wsc-statuses` / `.cn-wsc-status` … 3段階ステータス。
  `.cn-wsc-status-good`（緑）/ `.cn-wsc-status-attention`（橙）/
  `.cn-wsc-status-recommended`（赤）の3バリアント。strongに色を付ける。
- `.cn-wsc-table-wrap` + `.cn-wsc-table` … 表。`.cn-wsc-table-wrap` は
  `overflow-x:auto` で横スクロール。thは薄いグレー背景・ネイビー文字。
- `.cn-wsc-note` … 補足ボックス（青系背景）。
- `.cn-wsc-faq` + `<details>`/`<summary>` … アコーディオン式のFAQ。
- `.cn-wsc-code` …（新規追加）コードブロック。等幅フォント、
  濃色背景に明色文字 or 明色背景に枠線、`overflow-x:auto`、角丸。
  インラインコードは `<code>` に薄い背景。

## ページ構成（この順で。渡すMDの内容を割り当てる）

1. ヒーロー（タイトル＝「Site Security Checkerの使い方（詳細ガイド）」、
   リード文、ページ内アンカーのボタン2つ）
2. インストールから最初の診断まで（getting-started）→ `.cn-wsc-steps`
3. 診断結果の見方（how-to-read）→ 3段階ステータス＋優先順位の考え方
4. カテゴリA：バージョン鮮度（category-a の a1/a2/a3）→
   各項目を `.cn-wsc-card` か小見出し＋判定基準の表＋対処手順。
   PHPのサーバー別変更手順やコード例があれば残す。
5. カテゴリB：ハードニング設定（category-b の b1〜b7）→ 同上。
   wp-config.php / .htaccess / functions.php のコード例は `.cn-wsc-code` で表示。
6. よくある質問（faq）→ `.cn-wsc-faq` の `<details>`。
7. 末尾に `.cn-wsc-note` で「このプラグインは診断と案内に特化（自動変更しない）」の注意書き。

## 文体・内容のルール

- 渡したMDの情報は省略しない（特に対処手順とコード例）。ただし重複する前置きは整理してよい。
- 見出しの粒度はCyberNote読者（非エンジニアの個人ブロガー・小規模事業者）に合わせる。
- 表は「項目／判定基準（good・attention・recommended）／対処の目安」のように
  読み手が迷わない列構成にする。
- 公式ドキュメントへの参考リンクがMDにあれば `<a target="_blank" rel="noopener noreferrer">` で残す。

それでは、この後に貼り付ける6つのMarkdown文書をもとに、上記仕様のHTMLを出力してください。
出力はHTMLのみ。

===== プロンプトここまで =====
