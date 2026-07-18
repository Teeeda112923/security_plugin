"""Ingest a vulnerability feed into the local `vulnerabilities` table.

MVP supports two sources (set one in the environment):
  FEED_FILE  — path to a local JSON file (useful for tests / first import)
  FEED_URL   — an HTTPS endpoint returning the same JSON structure

The normalizer below targets a Wordfence-Intelligence-style record. The exact
field names of a live feed MUST be verified against the provider's current
documentation before production use (see docs/cybernote-backend-mvp.md §7),
and the commercial-use terms of the chosen source MUST be confirmed.
"""

import json
import sys

from sqlalchemy import select
from sqlalchemy.orm import Session

from .config import settings
from .db import SessionLocal, init_db
from .models import Vulnerability

# Wordfence software type -> our normalized type
_TYPE_MAP = {"plugin": "plugin", "theme": "theme", "core": "core"}


def _severity_from_rating(rating: str | float | None) -> str:
    """Map a CVSS rating/score to our coarse severity buckets."""
    if rating is None:
        return "high"
    if isinstance(rating, (int, float)):
        score = float(rating)
        if score >= 9.0:
            return "critical"
        if score >= 7.0:
            return "high"
        if score >= 4.0:
            return "medium"
        return "low"
    return str(rating).lower() if str(rating).lower() in {
        "critical",
        "high",
        "medium",
        "low",
    } else "high"


def normalize_record(record: dict) -> list[dict]:
    """Turn one feed record into one or more normalized vulnerability rows.

    A record may affect several pieces of software; we emit one row per
    (software) entry so matching stays a simple slug lookup.
    """
    rows: list[dict] = []
    source_id = str(record.get("id") or record.get("uuid") or "")
    title = record.get("title") or ""
    cve = None
    cves = record.get("cve") or record.get("cves") or []
    if isinstance(cves, str):
        cve = cves
    elif isinstance(cves, list) and cves:
        cve = cves[0]
    severity = _severity_from_rating(
        record.get("cvss", {}).get("score") if isinstance(record.get("cvss"), dict) else record.get("severity")
    )
    references = []
    for ref in record.get("references", []) or []:
        if isinstance(ref, str):
            references.append({"label": "詳細", "url": ref})
        elif isinstance(ref, dict):
            references.append({"label": ref.get("name", "詳細"), "url": ref.get("url", "")})

    for sw in record.get("software", []) or []:
        sw_type = _TYPE_MAP.get(sw.get("type", ""), None)
        slug = sw.get("slug") or ""
        if not sw_type or not slug:
            continue
        ranges = []
        patched_version = None
        affected = sw.get("affected_versions", {}) or {}
        # Wordfence: affected_versions is a dict keyed by a label -> range object
        iterable = affected.values() if isinstance(affected, dict) else affected
        for rng in iterable:
            if not isinstance(rng, dict):
                continue
            ranges.append(
                {
                    "from": rng.get("from_version"),
                    "from_incl": bool(rng.get("from_inclusive", True)),
                    "to": rng.get("to_version"),
                    "to_incl": bool(rng.get("to_inclusive", False)),
                }
            )
            if rng.get("to_inclusive") is False and rng.get("to_version"):
                patched_version = rng.get("to_version")
        if sw.get("patched_versions"):
            patched_version = sw["patched_versions"][0]

        rows.append(
            {
                "source": "wordfence",
                "source_id": f"{source_id}:{slug}",
                "software_type": sw_type,
                "slug": slug,
                "title": title,
                "severity": severity,
                "cve_id": cve,
                "affected_ranges": ranges,
                "patched_version": patched_version,
                "references": references,
            }
        )
    return rows


def upsert_rows(session: Session, rows: list[dict]) -> int:
    """Insert or update normalized rows keyed by (source, source_id)."""
    count = 0
    for row in rows:
        existing = session.scalar(
            select(Vulnerability).where(
                Vulnerability.source == row["source"],
                Vulnerability.source_id == row["source_id"],
            )
        )
        if existing is None:
            session.add(Vulnerability(**row))
        else:
            for key, value in row.items():
                setattr(existing, key, value)
        count += 1
    session.commit()
    return count


def load_feed() -> list[dict]:
    """Load raw feed records from FEED_FILE or FEED_URL."""
    if settings.feed_file:
        with open(settings.feed_file, encoding="utf-8") as fh:
            data = json.load(fh)
    elif settings.feed_url:
        import httpx  # lazy import: only needed for remote feeds

        headers = {"Authorization": f"Bearer {settings.feed_token}"} if settings.feed_token else {}
        resp = httpx.get(settings.feed_url, headers=headers, timeout=60)
        resp.raise_for_status()
        data = resp.json()
    else:
        raise RuntimeError("Set FEED_FILE or FEED_URL to ingest a feed.")
    # Feed may be a list, or a dict keyed by id.
    return list(data.values()) if isinstance(data, dict) else list(data)


def main() -> int:
    init_db()
    session = SessionLocal()
    try:
        records = load_feed()
        rows: list[dict] = []
        for rec in records:
            rows.extend(normalize_record(rec))
        n = upsert_rows(session, rows)
        print(f"Ingested {n} vulnerability rows from {len(records)} records.")
        return 0
    finally:
        session.close()


if __name__ == "__main__":
    sys.exit(main())
