"""Pydantic request/response schemas for the API (v1)."""

from pydantic import BaseModel, Field


class SoftwareItem(BaseModel):
    slug: str
    version: str


class ScanRequest(BaseModel):
    license_key: str
    site_url: str = ""
    wp_version: str | None = None
    php_version: str | None = None
    plugins: list[SoftwareItem] = Field(default_factory=list)
    themes: list[SoftwareItem] = Field(default_factory=list)


class Reference(BaseModel):
    label: str = ""
    url: str = ""


class VulnerabilityOut(BaseModel):
    type: str
    slug: str
    name: str = ""
    installed_version: str
    fixed_version: str | None = None
    severity: str = "high"
    cve_id: str | None = None
    title_ja: str = ""
    description_ja: str = ""
    action_ja: str = ""
    vuln_type_ja: str = ""
    references: list[Reference] = Field(default_factory=list)


class ScanResponse(BaseModel):
    status: str = "ok"
    scanned_at: str
    vulnerabilities: list[VulnerabilityOut] = Field(default_factory=list)


class LicenseVerifyRequest(BaseModel):
    license_key: str
    site_url: str | None = None


class LicenseVerifyResponse(BaseModel):
    valid: bool
    plan: str | None = None
    expires_at: str | None = None
    error: str = ""
