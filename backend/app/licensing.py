"""License key generation and verification (MVP: manual issuance)."""

import secrets
from datetime import date, datetime, timedelta, timezone

from sqlalchemy import select
from sqlalchemy.orm import Session

from .config import settings
from .models import License

_ALPHABET = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789"  # no ambiguous 0/O/1/I


def generate_key() -> str:
    """Return a key like CNSC-XXXX-XXXX-XXXX-XXXX."""
    blocks = ["".join(secrets.choice(_ALPHABET) for _ in range(4)) for _ in range(4)]
    return f"{settings.license_prefix}-" + "-".join(blocks)


def issue_license(session: Session, plan: str = "pro", days: int = 365) -> License:
    """Create and persist a new active license."""
    lic = License(
        key=generate_key(),
        plan=plan,
        status="active",
        expires_at=date.today() + timedelta(days=days),
    )
    session.add(lic)
    session.commit()
    session.refresh(lic)
    return lic


def verify_license(session: Session, key: str) -> dict:
    """Check a key. Returns a dict shaped like LicenseVerifyResponse."""
    lic = session.scalar(select(License).where(License.key == key))
    if lic is None:
        return {"valid": False, "error": "not_found"}
    if lic.status == "revoked":
        return {"valid": False, "error": "revoked"}
    if lic.expires_at is not None and lic.expires_at < datetime.now(timezone.utc).date():
        return {"valid": False, "plan": lic.plan, "error": "expired"}
    return {
        "valid": True,
        "plan": lic.plan,
        "expires_at": lic.expires_at.isoformat() if lic.expires_at else None,
        "error": "",
    }
