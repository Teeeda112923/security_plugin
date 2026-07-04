"""FastAPI application exposing the scan and license endpoints (v1)."""

from datetime import datetime, timezone

from fastapi import Depends, FastAPI, HTTPException
from sqlalchemy.orm import Session

from .db import get_session, init_db
from .licensing import verify_license
from .matcher import find_vulnerabilities
from .models import ScanLog
from .schemas import (
    LicenseVerifyRequest,
    LicenseVerifyResponse,
    ScanRequest,
    ScanResponse,
    VulnerabilityOut,
)

app = FastAPI(title="CyberNote API", version="0.1.0")


@app.on_event("startup")
def _startup() -> None:
    init_db()


@app.get("/healthz")
def healthz() -> dict:
    return {"status": "ok"}


@app.post("/api/v1/license/verify", response_model=LicenseVerifyResponse)
def license_verify(
    req: LicenseVerifyRequest, session: Session = Depends(get_session)
) -> LicenseVerifyResponse:
    result = verify_license(session, req.license_key)
    return LicenseVerifyResponse(**result)


@app.post("/api/v1/scan", response_model=ScanResponse)
def scan(req: ScanRequest, session: Session = Depends(get_session)) -> ScanResponse:
    check = verify_license(session, req.license_key)
    if not check.get("valid"):
        raise HTTPException(status_code=401, detail=check.get("error", "invalid_license"))

    found: list[VulnerabilityOut] = []
    targets = [("plugin", req.plugins), ("theme", req.themes)]
    for software_type, items in targets:
        for item in items:
            for vuln in find_vulnerabilities(session, software_type, item.slug, item.version):
                found.append(
                    VulnerabilityOut(
                        type=software_type,
                        slug=item.slug,
                        name=item.slug,
                        installed_version=item.version,
                        fixed_version=vuln.patched_version,
                        severity=vuln.severity,
                        cve_id=vuln.cve_id,
                        title_ja=vuln.title,  # MVP: English title until JA copy exists
                        references=vuln.references or [],
                    )
                )

    session.add(
        ScanLog(license_key=req.license_key, site_url=req.site_url, found_count=len(found))
    )
    session.commit()

    return ScanResponse(
        status="ok",
        scanned_at=datetime.now(timezone.utc).isoformat(),
        vulnerabilities=found,
    )
