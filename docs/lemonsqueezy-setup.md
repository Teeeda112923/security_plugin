# Lemon Squeezy 設定手順（Pro版の販売開始まで）

コード側（cybernote.click のAPI・Pro接続プラグイン）は実装済みです。
あとは Lemon Squeezy 側で商品を作り、ライセンスキー発行をONにするだけで販売できます。

## 全体像

```
購入者が Lemon Squeezy で購入
        ↓
Lemon Squeezy がライセンスキーを自動発行してメール送信
        ↓
購入者が Pro接続プラグインにキーを入力
        ↓
cybernote.click が Lemon Squeezy にキーの有効性を問い合わせ
        ↓
有効なら脆弱性スキャンを実行
```

**cybernote.click に Lemon Squeezy のAPIキーを置く必要はありません。**
検証用エンドポイントは認証不要の公開APIのため、漏えいリスクを増やしません。

## 手順

### 1. アカウント作成

https://www.lemonsqueezy.com/ で登録し、ストアを作成する。
販売者情報・入金先（銀行口座）の登録が必要。

### 2. 商品（Product）を作る

「Products」→「New Product」で、以下の3つを作成する。

| 商品名 | 種別 | 価格 |
|---|---|---|
| CyberNote Pro（月額） | Subscription / Monthly | 480円 |
| CyberNote Pro（年額） | Subscription / Yearly | 3,800円 |
| CyberNote Pro マルチサイト（月額） | Subscription / Monthly | 1,480円 |

通貨は JPY を選ぶ。
※ 価格は docs/pro-design.md の設計値。変更する場合はここだけ直せばよい。

### 3. ライセンスキー発行をONにする（最重要）

各商品の編集画面で **「License keys」→「Generate license keys」を有効化**する。

- **Activation limit（利用可能サイト数）**
  - 月額・年額 → `1`
  - マルチサイト → `5`
- **License length** → サブスクリプションに合わせる（期限切れが自動反映される）

これをONにしないとキーが発行されず、購入者がProを使えません。

### 4. cybernote.click 側をONにする

「設定 > CyberNote API」→ **「購入時に Lemon Squeezy が発行したライセンスキーを有効にする」にチェック** → 保存。

### 5. 動作確認

1. Lemon Squeezy のテストモードで自分で購入する
2. 届いたメールのライセンスキーをコピー
3. 「設定 > CyberNote API」の「キーの動作確認」に貼り付けて **✓有効** を確認
4. テストサイトの Pro接続プラグインに入力してスキャンできることを確認

## 購入者のマイページについて

Lemon Squeezy の **Customer Portal** が標準で用意されており、購入者は自分で

- ライセンスキーの確認
- プラン変更・支払い方法の変更
- 解約

ができます。**自作は不要**です。

購入完了メールにポータルへのリンクが自動で入ります。
Pro接続プラグインの「お問い合わせ」リンクを、このポータルURLに差し替えると更に親切です
（`class-cnscp-admin.php` の `render_license_form()` 内のリンク1箇所）。

## 解約・期限切れの扱い

Lemon Squeezy 側でキーの状態が `expired` / `disabled` になると、
cybernote.click は最大12時間以内にそれを検知してスキャンを停止します。

利用者の画面には「ライセンスの有効期限が切れています。」と表示されます。

## 障害時の考え方

Lemon Squeezy に一時的につながらない場合、**直前まで有効だった人は最大3日間そのまま使えます**。
支払っている人を障害で締め出さないための猶予です。
（存在しないキーは猶予なく即座に拒否されます。）

## 無償提供・検証用キー

決済を通さずにProを使わせたい場合（レビュー用・知人への提供など）は、
「設定 > CyberNote API」の **手動登録キー** に `WSC-XXXX-XXXX-XXXX-XXXX` 形式で1行1キー追加する。
Lemon Squeezy 連携をONにしていても併用できます。
