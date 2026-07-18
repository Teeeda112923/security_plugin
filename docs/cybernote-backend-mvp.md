# CyberNote Backend — MVP設計書

バージョン: 0.1（MVP）
作成日: 2026-07-03
対象: cybernote.click 側の外部サービス（Pro機能のバックエンド）

この文書は docs/pro-design.md を「実際に動かす最小構成（MVP）」へ落とし込んだもの。
無料プラグイン（WordPress.org 配布版）には一切同梱しない。Proのスキャン・ライセンスは
すべてこのバックエンドが担い、プラグインは HTTPS API 経由で連携する（= serviceware。
WP.org規約で許可される形）。

---

## 1. MVPのゴールと非ゴール

### やること（MVP）
- プラグイン一覧・テーマ一覧・WP/PHPバージョンを受け取り、既知脆弱性のリストを返す **スキャンAPI**
- **ライセンスキーの検証**（MVPでは手動発行したキーをDBで照合）
- 脆弱性データを **自前DBに取り込み**、ローカルで突合（外部APIを毎回叩かない）

### やらないこと（フェーズ2以降）
- 決済連携（Lemon Squeezy Webhook での自動キー発行）
- WP-Cron 自動スキャン・メール通知・差分通知
- 管理ダッシュボード（キー発行は当面CLIスクリプト）

---

## 2. 技術スタック（推奨・変更可）

| 層 | 採用 | 理由 |
|---|---|---|
| 言語/FW | **Python 3.11 + FastAPI** | JSON APIが簡潔。型・バリデーションが Pydantic で堅い |
| DB | **SQLite（MVP）→ PostgreSQL（本番）** | SQLAlchemyで抽象化し `DATABASE_URL` で切替 |
| ホスティング | **Render**（or Railway/Fly.io） | GitHub連携でデプロイ。Web Service ＋ Cron ＋ Managed Postgres |
| 脆弱性データ | **Wordfence Intelligence 等の無料フィード**（※後述の要確認） | 従量課金を避け原価を抑える |

> Renderの無料枠はディスクが揮発するため、本番は **Render Postgres**（または永続ディスク）を使う。

---

## 3. アーキテクチャ

```
[WordPress + Proプラグイン]
   └─ POST /api/v1/scan (license_key + 環境情報)
        │  HTTPS
        ▼
[cybernote.click バックエンド (FastAPI on Render)]
   ├─ license 照合 (DB)
   ├─ 突合エンジン (installed_version が脆弱範囲に入るか)
   │     └─ vulnerabilities テーブル(自前DB)
   └─ 結果JSONを返す
        ▲
        │ （1日1回のCronで更新）
[取り込みジョブ ingest]
   └─ Wordfence等のフィードを取得 → vulnerabilities テーブルへ正規化保存
```

**サイトから外部脆弱性DBへ直接接続しない**（プラグイン → 当社API のみ）。個人情報は送らない。

---

## 4. API仕様

### `GET /healthz`
死活監視。`{"status":"ok"}` を返す。

### `POST /api/v1/license/verify`
```json
// req
{ "license_key": "CNSC-XXXX-XXXX-XXXX-XXXX", "site_url": "https://example.com" }
// res
{ "valid": true, "plan": "pro", "expires_at": "2027-07-03", "error": "" }
```

### `POST /api/v1/scan`
```json
// req
{
  "license_key": "CNSC-XXXX-XXXX-XXXX-XXXX",
  "site_url": "https://example.com",
  "wp_version": "6.5.3",
  "php_version": "8.2.18",
  "plugins": [ { "slug": "contact-form-7", "version": "5.9" } ],
  "themes":  [ { "slug": "storefront", "version": "4.5.0" } ]
}
// res
{
  "status": "ok",
  "scanned_at": "2026-07-03T10:00:00Z",
  "vulnerabilities": [
    {
      "type": "plugin", "slug": "contact-form-7", "name": "Contact Form 7",
      "installed_version": "5.9", "fixed_version": "5.9.5",
      "severity": "critical", "cve_id": "CVE-2026-XXXXX",
      "title_ja": "…", "description_ja": "…", "action_ja": "…",
      "vuln_type_ja": "クロスサイトスクリプティング（XSS）",
      "references": [ { "label": "WPScan", "url": "https://…" } ]
    }
  ]
}
```
- ライセンスキーが無効なら `401`。
- 日本語文面（title_ja 等）はMVPでは英語原文をそのまま入れ、翻訳はフェーズ2で拡充。

---

## 5. データモデル（MVP）

### `licenses`
| 列 | 型 | 備考 |
|---|---|---|
| id | int | PK |
| key | str(unique) | `CNSC-XXXX-XXXX-XXXX-XXXX` |
| plan | str | `pro` |
| status | str | `active` / `expired` / `revoked` |
| expires_at | date | 期限 |
| created_at | datetime | |

### `vulnerabilities`（フィードから取り込み・正規化）
| 列 | 型 | 備考 |
|---|---|---|
| id | int | PK |
| source | str | `wordfence` 等 |
| source_id | str | フィード側ID（重複取り込み防止） |
| software_type | str | `plugin` / `theme` / `core` |
| slug | str(index) | 突合キー |
| title | str | |
| severity | str | `critical`/`high`/`medium`/`low` |
| cve_id | str/null | |
| affected_ranges | json | `[{from,from_incl,to,to_incl}]` |
| patched_version | str/null | 修正版 |
| references | json | `[{label,url}]` |

### `scan_logs`（任意・利用状況把握）
| id / license_id / site_url / created_at / found_count |

---

## 6. 突合ロジック（matcher）

入力: `slug` と `installed_version`。
`vulnerabilities` から同じ `slug` を引き、各 `affected_ranges` について
「installed が下限（from/from_incl）以上 かつ 上限（to/to_incl）以下」なら該当。
`patched_version` を修正版として返す。

WPのバージョンは厳密なsemverでないため、数字区切りで数値比較する緩い比較器を用いる
（例 `5.9` < `5.9.5`）。プレリリース等の稀なケースはフェーズ2で精緻化。

---

## 7. 脆弱性データ源（★要確認の重要事項）

無料/低コスト源として **Wordfence Intelligence の脆弱性データフィード** と **Patchstack** を候補とする。
MVPコードは「フィードのJSONを取り込んで正規化する importer」を用意し、取り込み元は差し替え可能にする。

> ⚠️ **商用利用（有料Proでの利用）の可否は各社の利用規約で必ず確認すること。**
> - Wordfence Intelligence: データフィードの提供条件・商用条項を確認
> - Patchstack: API/データ提供条件を確認
> - 折り合わなければ WPScan 有料API（$25/月〜）に切替
> これは法務判断のため、コードを本番に載せる前に必ずクリアする。

---

## 8. デプロイ（Render 想定）

- **Web Service**: `uvicorn app.main:app --host 0.0.0.0 --port $PORT`
- **Cron Job**: 1日1回 `python -m app.ingest`（フィード更新）
- **Postgres**: Render Managed Postgres を作成し `DATABASE_URL` を環境変数に設定
- 秘密情報（フィードのAPIキー等）は環境変数で管理（コードに直書きしない）
- `render.yaml`（Blueprint）で web + cron + db を一括定義

---

## 9. ライセンス発行（MVP・手動）

`scripts/issue_license.py` で手動発行:
```
python -m scripts.issue_license --plan pro --days 365
# → CNSC-XXXX-XXXX-XXXX-XXXX を生成しDBに保存、標準出力に表示
```
購入者へは当面メールで手動送付。フェーズ2で Lemon Squeezy Webhook から自動化。

---

## 10. プラグイン側（フェーズ2で実装・非同梱の考え方）

- Pro連携コードは **無料配布版には含めない**（同梱するとトライアルウェア扱いで再リジェクト）。
- 実装時は「有効なライセンス＋当社APIに接続して実処理する serviceware」の形にし、
  readmeに **利用規約・プライバシーポリシー** のリンクを明記（送信データ＝プラグイン/テーマ一覧）。
- 配布は cybernote.click からの別ダウンロード等を想定（WP.org本体には載せない）。

---

## 11. ロードマップ

- **MVP（今回）**: scan / license verify / ingest / 手動発行。ローカルで動作。
- **フェーズ2**: 決済（Lemon Squeezy）自動発行、日本語文面の充実、レート制限。
- **フェーズ3**: WP-Cron自動スキャン、メール通知、差分通知、管理ダッシュボード。

---

## 12. 本番前の必須チェックリスト（運用開始の条件）

- [ ] 脆弱性データ源の**商用利用可否**を規約で確認・合意
- [ ] Render に web/cron/postgres を構築、`DATABASE_URL` 等を設定
- [ ] HTTPS（cybernote.click のサブドメイン例 `api.cybernote.click`）とSSL
- [ ] プライバシーポリシー・利用規約ページを用意（送信データの明示）
- [ ] ライセンスキーの安全な保管、キー漏洩時の失効手順
- [ ] 障害時の連絡先・監視（/healthz）
