"""Runtime configuration read from environment variables.

Kept dependency-free (plain os.getenv) so the MVP has no extra settings library.
"""

import os


def _normalize_db_url(url: str) -> str:
    # Managed Postgres often hands out "postgres://"; SQLAlchemy wants a driver.
    if url.startswith("postgres://"):
        return "postgresql+psycopg://" + url[len("postgres://") :]
    return url


class Settings:
    # SQLite by default for local dev; set DATABASE_URL to a Postgres URL in prod.
    database_url: str = _normalize_db_url(
        os.getenv("DATABASE_URL", "sqlite:///./cybernote.db")
    )

    # Vulnerability feed ingestion source (see app/ingest.py).
    # Either a local JSON file (FEED_FILE) or an HTTPS endpoint (FEED_URL).
    feed_file: str = os.getenv("FEED_FILE", "")
    feed_url: str = os.getenv("FEED_URL", "")
    feed_token: str = os.getenv("FEED_TOKEN", "")

    # License key prefix (brand-consistent with the plugin's CNSC_ prefix).
    license_prefix: str = os.getenv("LICENSE_PREFIX", "CNSC")


settings = Settings()
