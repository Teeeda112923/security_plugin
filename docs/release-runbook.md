# プラグイン更新・リリース運用ガイド

Site Security Checker を WordPress.org で公開したあと、**セキュリティ修正や機能追加を定期的にリリースしていくための運用手順** をまとめます。

> このドキュメントは開発者（あなた）向けです。利用者向けの使い方ガイドは `docs/website/` を参照してください。

---

## 大前提：WordPress.org は SVN で配布される

GitHubで開発していても、**WordPress.orgのプラグインディレクトリは Subversion（SVN）で管理されます**。承認後、専用のSVNリポジトリが割り当てられます。

```
https://plugins.svn.wordpress.org/site-security-checker/
```

SVNリポジトリの構成は次の4つです。

| フォルダ | 役割 |
|---|---|
| `/trunk` | 開発中の最新コード（次にリリースする内容） |
| `/tags` | リリース済みの各バージョン（`/tags/1.0.0`、`/tags/1.0.1` …） |
| `/assets` | バナー・アイコン・スクリーンショット（**プラグイン本体には含めない**） |
| `/branches` | （任意）作業用ブランチ。通常は使わない |

> **重要：** GitHub（開発）と SVN（配布）は別物です。GitHubで開発を続け、リリースのタイミングだけ成果物をSVNへ反映する、という運用になります。

---

## リリースを行うタイミング

| 種類 | 内容 | 目安 |
|---|---|---|
| **緊急（セキュリティ修正）** | 脆弱性の発見・誤検知の修正 | 発見次第すぐ |
| **定期（互換性維持）** | WordPress新バージョンへの `Tested up to` 更新 | WP本体のメジャー更新ごと（年3〜4回） |
| **機能追加** | 診断項目の追加・UI改善 | 任意のペース |

> **最低限守りたいこと：** WordPressが新メジャー版を出したら `Tested up to` を更新してリリースし直す。これを怠ると「最新版で未検証」と表示され、検索結果での表示順位が下がります。

---

## バージョン番号の付け方（セマンティックバージョニング）

`メジャー.マイナー.パッチ` の3桁で管理します。

| 桁 | 上げるとき | 例 |
|---|---|---|
| パッチ（3桁目） | バグ・セキュリティ修正、文言修正 | `1.0.0` → `1.0.1` |
| マイナー（2桁目） | 後方互換のある機能追加（診断項目の追加など） | `1.0.1` → `1.1.0` |
| メジャー（1桁目） | 大規模な仕様変更・互換性のない変更 | `1.1.0` → `2.0.0` |

---

## バージョンを上げるときに必ず直す3か所

リリースのたびに、以下の3か所のバージョン表記を**必ず一致させて**ください。ここがずれると更新が正しく配信されません。

| ファイル | 行 | 例 |
|---|---|---|
| `wp-security-checker.php` | `* Version:` | `* Version: 1.0.1` |
| `readme.txt` | `Stable tag:` | `Stable tag: 1.0.1` |
| `readme.txt` | `== Changelog ==` | 新バージョンの変更内容を追記 |

> `readme.txt` の **`Stable tag` が、利用者に配信されるバージョンを決定します**。SVNの `/tags/1.0.1/` が存在し、かつ `Stable tag: 1.0.1` になっていて初めて、その版が「最新版」として配信されます。

---

## リリース手順（GitHub → SVN）

### 事前準備（初回のみ）

1. 承認メールに記載されたSVN URLを控える
2. SVNクライアントを用意する（Mac/Linuxは標準の `svn`、Windowsは TortoiseSVN など）
3. ローカルにSVNリポジトリをチェックアウトする

```bash
svn checkout https://plugins.svn.wordpress.org/site-security-checker/ svn-ssc
cd svn-ssc
```

### 毎回のリリース手順

#### ① GitHub側でコードを確定させる

1. 開発ブランチで修正・テストを済ませる
2. 上記「3か所」のバージョン表記を更新する
3. `readme.txt` の Changelog に変更内容を追記する
4. コミット＆プッシュする

#### ② trunk に最新コードを反映する

GitHubの配布対象ファイル（`docs/` や `.git` を除いたプラグイン本体）を、SVNの `trunk/` にコピーします。

```bash
# 例：配布物だけを trunk へ反映（docsや開発ファイルは含めない）
cp -r wp-security-checker.php readme.txt uninstall.php includes languages \
      svn-ssc/trunk/
```

> プラグイン本体に含めるのは PHP・readme.txt・languages など。`docs/`・`.git`・`.gitignore`・スクリーンショット画像は trunk に入れません。

#### ③ 新バージョンのタグを切る

trunkの内容を `tags/バージョン番号/` にコピーします。

```bash
svn copy svn-ssc/trunk svn-ssc/tags/1.0.1
```

#### ④ SVNにコミット（＝これがリリース）

```bash
cd svn-ssc
svn add --force trunk tags
svn commit -m "Release 1.0.1: 〇〇を修正"
```

コミットすると、数分〜十数分でWordPress.orgの配信に反映されます。

---

## バナー・アイコン・スクリーンショットの扱い

これらの画像は **プラグイン本体（trunk）ではなく、SVNの `/assets` フォルダ** に置きます。

```bash
cp assets/banner-772x250.png   svn-ssc/assets/
cp assets/icon.svg             svn-ssc/assets/icon.svg
cp assets/screenshot-1.png     svn-ssc/assets/
cp assets/screenshot-2.png     svn-ssc/assets/
cd svn-ssc && svn add --force assets && svn commit -m "Update assets"
```

| ファイル名の規則 | 用途 |
|---|---|
| `banner-772x250.png` | プラグインページ上部のバナー |
| `icon.svg` または `icon-256x256.png` | 検索結果のアイコン |
| `screenshot-1.png`, `screenshot-2.png` … | `readme.txt` の Screenshots の番号と対応 |

> `/assets` に置いた画像は配布ZIPの容量に含まれないため、利用者のダウンロードが軽くなります。現在プラグイン本体にスクリーンショットを同梱していますが、SVN運用に移るタイミングで `/assets` へ移すのが正式な形です。

---

## リリース前チェックリスト

リリースのたびに以下を確認してください。

- [ ] `wp-security-checker.php` の `Version` を更新した
- [ ] `readme.txt` の `Stable tag` を同じ番号に更新した
- [ ] `readme.txt` の `Changelog` に変更内容を追記した
- [ ] WordPress新版が出ていれば `Tested up to` を更新した
- [ ] ローカルのWordPressで有効化し、診断が正常に動くことを確認した
- [ ] [Plugin Check プラグイン](https://wordpress.org/plugins/plugin-check/) でエラーが出ないことを確認した
- [ ] `tags/バージョン番号/` を作成した
- [ ] SVNにコミットした

---

## 自動化のヒント（任意）

毎回手作業でSVNへ反映するのは手間なので、慣れてきたら **GitHub Actions で自動リリース** を組むと便利です。

- [`10up/action-wordpress-plugin-deploy`](https://github.com/10up/action-wordpress-plugin-deploy) … GitHubでタグを打つと自動でSVNへデプロイ
- [`10up/action-wordpress-plugin-asset-update`](https://github.com/10up/action-wordpress-plugin-asset-update) … readme・assetsだけを自動更新

導入すると「GitHubで `v1.0.1` タグを打つ → 自動でWordPress.orgに公開」という流れが作れます。必要になったら設定をお手伝いします。

---

## まとめ：定期運用の最小ルール

1. **WordPressが新メジャー版を出したら** `Tested up to` を上げてリリースし直す
2. **脆弱性・不具合が見つかったら** パッチ版（3桁目）をすぐ出す
3. リリースのたびに **Version / Stable tag / Changelog の3点セット** を必ず揃える

この3つを守るだけで、「放置されていない、信頼できるプラグイン」という評価を保てます。
