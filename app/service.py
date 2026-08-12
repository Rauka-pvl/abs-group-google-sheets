from __future__ import annotations

from typing import Any

from app.bitrix.accounting import fetch_accounting_row_by_id, fetch_accounting_rows
from app.bitrix.client import BitrixClient
from app.config import config
from app.sheets.client import SheetsClient
from app.sheets.sync import (
    SyncStats,
    mark_accounting_row_deleted,
    sync_accounting_to_sheet,
    upsert_accounting_row,
)

SPA_EVENTS = {
    "ONCRMDYNAMICITEMADD",
    "ONCRMDYNAMICITEMUPDATE",
    "ONCRMDYNAMICITEMDELETE",
}
DEAL_EVENTS = {
    "ONCRMDEALADD",
    "ONCRMDEALUPDATE",
    "ONCRMDEALDELETE",
}


def create_bitrix_client() -> BitrixClient:
    return BitrixClient(config.bitrix_webhook_url)


def create_sheets_client() -> SheetsClient:
    if not config.google_service_account_path.exists():
        raise RuntimeError(
            f"Google service account file not found: {config.google_service_account_path}\n"
            "Create a service account key, save it there, and share the spreadsheet with the SA email (Editor)."
        )
    return SheetsClient(
        spreadsheet_id=config.spreadsheet_id,
        sheet_name=config.sheet_name,
        service_account_path=config.google_service_account_path,
    )


def run_full_sync(*, dry_run: bool = False) -> SyncStats:
    bitrix = create_bitrix_client()
    rows = fetch_accounting_rows(bitrix)
    print(f"Fetched {len(rows)} card(s) from Bitrix")
    sheets = create_sheets_client()
    return sync_accounting_to_sheet(sheets, rows, dry_run=dry_run)


def preview_accounting_rows() -> None:
    bitrix = create_bitrix_client()
    rows = fetch_accounting_rows(bitrix)
    print(f"Fetched {len(rows)} card(s) from Bitrix")
    for row in rows:
        print(
            f"#{row.bitrix_id} | {row.category_name} | {row.title} | "
            f"{row.amount} | {row.date} | {row.status}"
        )


def handle_bitrix_event(payload: dict[str, Any]) -> dict[str, Any]:
    event = str(payload.get("event") or "").upper()
    fields = ((payload.get("data") or {}).get("FIELDS") or {})
    try:
        item_id = int(fields.get("ID"))
    except (TypeError, ValueError):
        item_id = None

    entity_raw = fields.get("ENTITY_TYPE_ID")
    try:
        entity_type_id = int(entity_raw) if entity_raw is not None else None
    except (TypeError, ValueError):
        entity_type_id = None

    if not event or item_id is None:
        return {"status": "ignored", "reason": "missing event or id"}

    if event in DEAL_EVENTS:
        return {
            "status": "ignored",
            "reason": (
                "ONCRMDEAL* is for classic deals. Accounting funnels are SPA "
                "«Заявка в бухгалтерию» — use ONCRMDYNAMICITEMADD/UPDATE/DELETE"
            ),
        }

    if event not in SPA_EVENTS:
        return {"status": "ignored", "reason": f"unsupported event {event}"}

    if entity_type_id is not None and entity_type_id != config.entity_type_id:
        return {
            "status": "ignored",
            "reason": (
                f"entityTypeId {entity_type_id} is not accounting SPA {config.entity_type_id}"
            ),
        }

    sheets = create_sheets_client()
    bitrix = create_bitrix_client()

    if event == "ONCRMDYNAMICITEMDELETE":
        action = mark_accounting_row_deleted(sheets, item_id)
        return {"status": "ok", "action": action, "bitrixId": item_id}

    row = fetch_accounting_row_by_id(bitrix, item_id)
    if row is None:
        action = mark_accounting_row_deleted(sheets, item_id)
        return {
            "status": "ok",
            "action": "skipped_out_of_scope" if action == "missing" else action,
            "bitrixId": item_id,
        }

    action = upsert_accounting_row(sheets, row)
    return {"status": "ok", "action": action, "bitrixId": item_id}
