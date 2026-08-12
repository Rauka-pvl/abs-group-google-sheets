from __future__ import annotations

import argparse
import sys

from app.config import config
from app.service import preview_accounting_rows, run_full_sync


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Bitrix → Google Sheets sync")
    parser.add_argument("--dry-run", action="store_true", help="Compare without writing")
    parser.add_argument("--preview", action="store_true", help="Fetch Bitrix only")
    args = parser.parse_args(argv)

    print("Bitrix → Google Sheets sync")
    print(f"Sheet: {config.sheet_name} ({config.spreadsheet_id})")
    print(
        "Categories: "
        + ", ".join(
            f"{cid} {(config.category_names or {}).get(cid, '')}"
            for cid in config.category_ids
        )
    )

    try:
        if args.preview:
            print("Mode: preview (Bitrix only, no Google Sheets)")
            preview_accounting_rows()
            return 0

        if args.dry_run:
            print("Mode: dry-run (no writes)")

        stats = run_full_sync(dry_run=args.dry_run)
        print("Done.")
        print(
            f"added={stats.added} updated={stats.updated} "
            f"markedDeleted={stats.marked_deleted} unchanged={stats.unchanged}"
        )
        return 0
    except Exception as exc:  # noqa: BLE001
        print(f"Sync failed: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
