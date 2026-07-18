# CyberNote Backend (MVP)

External Pro service for the CyberNote Security Checker WordPress plugin.
It receives a site's plugin/theme/version list and returns known
vulnerabilities matched against a locally-ingested feed.

**This is NOT part of the WordPress.org plugin** and must never be bundled
into the distributed plugin ZIP. See `../docs/cybernote-backend-mvp.md` for
the full design.

## Stack
Python 3.11 · FastAPI · SQLAlchemy 2.0 · SQLite (dev) / Postgres (prod).

## Run locally

```bash
cd backend
python -m venv .venv && source .venv/bin/activate   # Windows: .venv\Scripts\activate
pip install -r requirements.txt

# 1) import the sample feed into a local SQLite DB
FEED_FILE=tests/fixtures/sample_feed.json python -m app.ingest

# 2) issue a test license key
python -m scripts.issue_license --plan pro --days 365
#   -> prints CNSC-XXXX-XXXX-XXXX-XXXX

# 3) start the API
uvicorn app.main:app --reload
```

Then try a scan (use the key from step 2):

```bash
curl -s http://127.0.0.1:8000/api/v1/scan \
  -H 'Content-Type: application/json' \
  -d '{
        "license_key": "CNSC-XXXX-XXXX-XXXX-XXXX",
        "plugins": [ { "slug": "contact-form-7", "version": "5.9" } ]
      }'
```

You should get the sample Contact Form 7 vulnerability back, with
`fixed_version: 5.9.5`.

## Tests

```bash
cd backend
pip install -r requirements.txt
pytest
```

## Deploy (Render)

`render.yaml` is a Blueprint that provisions a web service, a daily ingest
cron, and a managed Postgres database. Connect this repository in the Render
dashboard and it will read the blueprint. Set `FEED_URL` / `FEED_TOKEN` in the
dashboard for the real vulnerability feed.

## Before going live (must-do)

1. **Confirm commercial-use terms** of the chosen vulnerability data source
   (Wordfence Intelligence / Patchstack / WPScan). This is a legal gate.
2. Verify the real feed's JSON schema and adjust `app/ingest.py:normalize_record`.
3. Put the API behind HTTPS (e.g. `api.cybernote.click`).
4. Publish Terms of Use + Privacy Policy (the plugin sends its plugin/theme list).
5. Plan phase 2: Lemon Squeezy webhook -> automatic key issuance; Japanese copy.
