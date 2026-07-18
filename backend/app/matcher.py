"""Vulnerability matching: given installed software, find affecting vulns."""

from sqlalchemy import select
from sqlalchemy.orm import Session

from .models import Vulnerability
from .versioning import in_range


def find_vulnerabilities(
    session: Session, software_type: str, slug: str, installed_version: str
) -> list[Vulnerability]:
    """Return vulnerabilities of `slug` whose affected range includes the version."""
    stmt = select(Vulnerability).where(
        Vulnerability.software_type == software_type,
        Vulnerability.slug == slug,
    )
    hits: list[Vulnerability] = []
    for vuln in session.scalars(stmt):
        ranges = vuln.affected_ranges or []
        if any(in_range(installed_version, r) for r in ranges):
            hits.append(vuln)
    return hits
