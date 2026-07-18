"""Manually issue a license key (MVP; payment automation comes in phase 2).

Usage:
    python -m scripts.issue_license --plan pro --days 365
"""

import argparse

from app.db import SessionLocal, init_db
from app.licensing import issue_license


def main() -> int:
    parser = argparse.ArgumentParser(description="Issue a CyberNote license key.")
    parser.add_argument("--plan", default="pro")
    parser.add_argument("--days", type=int, default=365)
    args = parser.parse_args()

    init_db()
    session = SessionLocal()
    try:
        lic = issue_license(session, plan=args.plan, days=args.days)
        print("Issued license:")
        print(f"  key:        {lic.key}")
        print(f"  plan:       {lic.plan}")
        print(f"  expires_at: {lic.expires_at}")
        return 0
    finally:
        session.close()


if __name__ == "__main__":
    raise SystemExit(main())
