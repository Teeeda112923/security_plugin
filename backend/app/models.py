"""ORM models for the MVP (licenses, vulnerabilities, scan logs)."""

from datetime import date, datetime, timezone

from sqlalchemy import JSON, Date, DateTime, Integer, String, UniqueConstraint
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column


def _utcnow() -> datetime:
    return datetime.now(timezone.utc)


class Base(DeclarativeBase):
    pass


class License(Base):
    __tablename__ = "licenses"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    key: Mapped[str] = mapped_column(String(64), unique=True, index=True)
    plan: Mapped[str] = mapped_column(String(32), default="pro")
    status: Mapped[str] = mapped_column(String(16), default="active")  # active|expired|revoked
    expires_at: Mapped[date | None] = mapped_column(Date, nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=_utcnow)


class Vulnerability(Base):
    __tablename__ = "vulnerabilities"
    __table_args__ = (UniqueConstraint("source", "source_id", name="uq_source_record"),)

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    source: Mapped[str] = mapped_column(String(32), index=True)
    source_id: Mapped[str] = mapped_column(String(128), index=True)
    software_type: Mapped[str] = mapped_column(String(16), index=True)  # plugin|theme|core
    slug: Mapped[str] = mapped_column(String(191), index=True)
    title: Mapped[str] = mapped_column(String(512), default="")
    severity: Mapped[str] = mapped_column(String(16), default="high")
    cve_id: Mapped[str | None] = mapped_column(String(32), nullable=True)
    # affected_ranges: [{"from":..,"from_incl":bool,"to":..,"to_incl":bool}]
    affected_ranges: Mapped[list] = mapped_column(JSON, default=list)
    patched_version: Mapped[str | None] = mapped_column(String(64), nullable=True)
    references: Mapped[list] = mapped_column(JSON, default=list)  # [{"label":..,"url":..}]
    updated_at: Mapped[datetime] = mapped_column(DateTime, default=_utcnow, onupdate=_utcnow)


class ScanLog(Base):
    __tablename__ = "scan_logs"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    license_key: Mapped[str] = mapped_column(String(64), index=True)
    site_url: Mapped[str] = mapped_column(String(255), default="")
    found_count: Mapped[int] = mapped_column(Integer, default=0)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=_utcnow)
