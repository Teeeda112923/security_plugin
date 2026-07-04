"""Loose version comparison for WordPress plugin/theme/core versions.

WordPress versions are not strict semver (e.g. "5.9", "8.8.3", "1.0"), so we
compare the numeric segments only. Non-numeric suffixes (beta/RC) are ignored
in the MVP; precise pre-release handling is a phase-2 refinement.
"""

import re


def _parts(version: str | None) -> list[int]:
    return [int(tok) for tok in re.findall(r"\d+", version or "")]


def compare(a: str | None, b: str | None) -> int:
    """Return -1 if a < b, 0 if equal, 1 if a > b (numeric segments only)."""
    pa, pb = _parts(a), _parts(b)
    length = max(len(pa), len(pb))
    pa += [0] * (length - len(pa))
    pb += [0] * (length - len(pb))
    for x, y in zip(pa, pb):
        if x != y:
            return -1 if x < y else 1
    return 0


def in_range(installed: str, rng: dict) -> bool:
    """True if `installed` falls inside a single affected range.

    rng keys: from, from_incl, to, to_incl. A None/"*" bound means unbounded.
    """
    lo = rng.get("from")
    hi = rng.get("to")

    if lo not in (None, "", "*"):
        c = compare(installed, lo)
        if c < 0 or (c == 0 and not rng.get("from_incl", True)):
            return False

    if hi not in (None, "", "*"):
        c = compare(installed, hi)
        if c > 0 or (c == 0 and not rng.get("to_incl", False)):
            return False

    return True
