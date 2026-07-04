from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker

from app.matcher import find_vulnerabilities
from app.models import Base, Vulnerability


def _session():
    engine = create_engine("sqlite:///:memory:", future=True)
    Base.metadata.create_all(engine)
    return sessionmaker(bind=engine, expire_on_commit=False)()


def test_matcher_finds_affected_plugin():
    session = _session()
    session.add(
        Vulnerability(
            source="test",
            source_id="1:contact-form-7",
            software_type="plugin",
            slug="contact-form-7",
            title="XSS in CF7",
            severity="critical",
            cve_id="CVE-2026-0001",
            affected_ranges=[{"from": None, "to": "5.9.5", "to_incl": False}],
            patched_version="5.9.5",
            references=[{"label": "WPScan", "url": "https://example.com"}],
        )
    )
    session.commit()

    hits = find_vulnerabilities(session, "plugin", "contact-form-7", "5.9")
    assert len(hits) == 1
    assert hits[0].patched_version == "5.9.5"

    # patched version is not affected
    assert find_vulnerabilities(session, "plugin", "contact-form-7", "5.9.5") == []
    # different slug is not affected
    assert find_vulnerabilities(session, "plugin", "akismet", "5.9") == []
