from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Literal

from app.bitrix.accounting import AccountingRow
from app.config import config
from app.sheets.client import MappedRow, SheetsClient


@dataclass
class SyncStats:
    added: int = 0
    updated: int = 0
    marked_deleted: int = 0
    unchanged: int = 0


def _rows_equal(a: list[str], b: list[str]) -> bool:
    return len(a) == len(b) and all(x == y for x, y in zip(a, b))


def _is_placeholder_or_empty(values: list[str]) -> bool:
    title = (values[1] if len(values) > 1 else "").strip()
    bitrix_id = (values[9] if len(values) > 9 else "").strip()
    return not title and not bitrix_id


def _to_values(sheets: SheetsClient, item: AccountingRow) -> list[str]:
    return sheets.to_sheet_values(
        MappedRow(
            title=item.title,
            cost_article=item.cost_article,
            supplier=item.supplier,
            amount=item.amount,
            date=item.date,
            comments=item.comments,
            status=item.status,
            bitrix_id=str(item.bitrix_id),
            category_name=item.category_name,
        )
    )


def _load_index(sheets: SheetsClient) -> tuple[dict[int, dict[str, Any]], list[dict[str, Any]]]:
    sheets.ensure_header()
    existing = sheets.read_data_rows()
    id_to_row: dict[int, dict[str, Any]] = {}
    free_slots: list[dict[str, Any]] = []

    for row in existing:
        parsed = sheets.parse_sheet_values(row["values"])
        if parsed.bitrix_id:
            try:
                bitrix_id = int(parsed.bitrix_id)
            except ValueError:
                continue
            id_to_row[bitrix_id] = row
            continue
        if _is_placeholder_or_empty(row["values"]):
            free_slots.append(row)

    return id_to_row, free_slots


def sync_accounting_to_sheet(
    sheets: SheetsClient,
    accounting_rows: list[AccountingRow],
    *,
    dry_run: bool = False,
) -> SyncStats:
    id_to_row, free_slots = _load_index(sheets)
    stats = SyncStats()
    updates: list[dict[str, Any]] = []
    seen_ids: set[int] = set()
    free_index = 0

    for item in accounting_rows:
        seen_ids.add(item.bitrix_id)
        next_values = _to_values(sheets, item)
        current = id_to_row.get(item.bitrix_id)

        if current:
            if _rows_equal(current["values"], next_values):
                stats.unchanged += 1
            else:
                updates.append({"row_number": current["row_number"], "values": next_values})
                stats.updated += 1
            continue

        if free_index >= len(free_slots):
            raise RuntimeError(
                f'No free rows on "{config.sheet_name}" between '
                f"{sheets.layout.data_start_row} and {sheets.layout.data_end_row}. "
                "Add empty data rows above the footer (Примечания) and retry."
            )

        slot = free_slots[free_index]
        free_index += 1
        updates.append({"row_number": slot["row_number"], "values": next_values})
        stats.added += 1

    for bitrix_id, current in id_to_row.items():
        if bitrix_id in seen_ids:
            continue
        parsed = sheets.parse_sheet_values(current["values"])
        if parsed.status == config.deleted_status:
            stats.unchanged += 1
            continue
        marked = list(current["values"])
        marked[8] = config.deleted_status
        updates.append({"row_number": current["row_number"], "values": marked})
        stats.marked_deleted += 1

    if dry_run:
        print(f"[dry-run] would add: {stats.added}")
        print(f"[dry-run] would update: {stats.updated}")
        print(f"[dry-run] would mark deleted: {stats.marked_deleted}")
        print(f"[dry-run] unchanged: {stats.unchanged}")
        return stats

    sheets.batch_update_rows(updates)
    return stats


def upsert_accounting_row(
    sheets: SheetsClient,
    item: AccountingRow,
) -> Literal["added", "updated", "unchanged"]:
    id_to_row, free_slots = _load_index(sheets)
    next_values = _to_values(sheets, item)
    current = id_to_row.get(item.bitrix_id)

    if current:
        if _rows_equal(current["values"], next_values):
            return "unchanged"
        sheets.batch_update_rows([{"row_number": current["row_number"], "values": next_values}])
        return "updated"

    if not free_slots:
        raise RuntimeError(
            f'No free rows on "{config.sheet_name}" between '
            f"{sheets.layout.data_start_row} and {sheets.layout.data_end_row}. "
            "Add empty data rows above the footer (Примечания) and retry."
        )

    sheets.batch_update_rows([{"row_number": free_slots[0]["row_number"], "values": next_values}])
    return "added"


def mark_accounting_row_deleted(
    sheets: SheetsClient,
    bitrix_id: int,
) -> Literal["markedDeleted", "unchanged", "missing"]:
    id_to_row, _ = _load_index(sheets)
    current = id_to_row.get(bitrix_id)
    if not current:
        return "missing"

    parsed = sheets.parse_sheet_values(current["values"])
    if parsed.status == config.deleted_status:
        return "unchanged"

    marked = list(current["values"])
    marked[8] = config.deleted_status
    sheets.batch_update_rows([{"row_number": current["row_number"], "values": marked}])
    return "markedDeleted"
