from __future__ import annotations

import time
from dataclasses import dataclass
from datetime import datetime
from typing import Any

from app.bitrix.client import BitrixClient
from app.config import config

CATEGORY_ENTITY = {
    62: f"DYNAMIC_{config.entity_type_id}_STAGE_62",
    66: f"DYNAMIC_{config.entity_type_id}_STAGE_66",
    68: f"DYNAMIC_{config.entity_type_id}_STAGE_68",
}

SELECT_FIELDS = [
    "id",
    "title",
    "opportunity",
    "createdTime",
    "stageId",
    "categoryId",
    config.cost_article_field,
    config.comment_field,
]

MAPS_TTL_SEC = 5 * 60
_maps_cache: dict[str, Any] | None = None


@dataclass
class AccountingRow:
    bitrix_id: int
    title: str
    cost_article: str
    supplier: str
    amount: str
    date: str
    comments: str
    status: str
    category_name: str


def get_lookup_maps(client: BitrixClient) -> tuple[dict[str, str], dict[str, str]]:
    global _maps_cache
    now = time.time()
    if _maps_cache and _maps_cache["expires_at"] > now:
        return _maps_cache["stage_map"], _maps_cache["cost_article_map"]

    stage_map = _load_stage_map(client)
    cost_article_map = _load_cost_article_map(client)
    _maps_cache = {
        "expires_at": now + MAPS_TTL_SEC,
        "stage_map": stage_map,
        "cost_article_map": cost_article_map,
    }
    return stage_map, cost_article_map


def fetch_accounting_rows(client: BitrixClient) -> list[AccountingRow]:
    stage_map, cost_article_map = get_lookup_maps(client)
    items = client.call_list(
        "crm.item.list",
        {
            "entityTypeId": config.entity_type_id,
            "filter": {"@categoryId": list(config.category_ids)},
            "select": SELECT_FIELDS,
            "order": {"id": "ASC"},
        },
    )
    return [_map_item(item, stage_map, cost_article_map) for item in items]


def fetch_accounting_row_by_id(client: BitrixClient, item_id: int) -> AccountingRow | None:
    result = client.call(
        "crm.item.get",
        {"entityTypeId": config.entity_type_id, "id": item_id},
    )
    item = (result or {}).get("item")
    if not item:
        return None

    category_id = int(item.get("categoryId") or 0)
    if category_id not in config.category_ids:
        return None

    stage_map, cost_article_map = get_lookup_maps(client)
    return _map_item(item, stage_map, cost_article_map)


def _load_stage_map(client: BitrixClient) -> dict[str, str]:
    mapping: dict[str, str] = {}
    for category_id in config.category_ids:
        statuses = client.call_list(
            "crm.status.list",
            {"filter": {"ENTITY_ID": CATEGORY_ENTITY[category_id]}},
        )
        for status in statuses:
            mapping[str(status.get("STATUS_ID"))] = str(status.get("NAME") or "")
    return mapping


def _load_cost_article_map(client: BitrixClient) -> dict[str, str]:
    result = client.call("crm.item.fields", {"entityTypeId": config.entity_type_id}) or {}
    fields = result.get("fields") or {}
    field = fields.get(config.cost_article_field) or {}
    mapping: dict[str, str] = {}
    for item in field.get("items") or []:
        item_id = str(item.get("ID") or item.get("id") or "")
        value = str(item.get("VALUE") or item.get("value") or "")
        if item_id:
            mapping[item_id] = value
    return mapping


def _map_item(
    item: dict[str, Any],
    stage_map: dict[str, str],
    cost_article_map: dict[str, str],
) -> AccountingRow:
    category_id = int(item.get("categoryId") or 0)
    stage_id = str(item.get("stageId") or "")
    return AccountingRow(
        bitrix_id=int(item.get("id")),
        title=str(item.get("title") or ""),
        cost_article=_resolve_enum(item.get(config.cost_article_field), cost_article_map),
        supplier="",
        amount=_format_amount(item.get("opportunity")),
        date=_format_date(item.get("createdTime")),
        comments="" if item.get(config.comment_field) is None else str(item.get(config.comment_field)),
        status=stage_map.get(stage_id, stage_id),
        category_name=(config.category_names or {}).get(category_id, f"category {category_id}"),
    )


def _resolve_enum(raw: Any, mapping: dict[str, str]) -> str:
    if raw is None or raw == "":
        return ""
    if isinstance(raw, list):
        return ", ".join(mapping.get(str(v), str(v)) for v in raw)
    return mapping.get(str(raw), str(raw))


def _format_amount(value: Any) -> str:
    if value is None or value == "":
        return ""
    try:
        num = float(value)
    except (TypeError, ValueError):
        return str(value)
    formatted = f"{num:,.2f}"
    return formatted.replace(",", " ").replace(".", ",")


def _format_date(value: Any) -> str:
    if not value:
        return ""
    text = str(value)
    try:
        # Bitrix: 2026-07-23T12:47:33+03:00
        dt = datetime.fromisoformat(text)
        return dt.strftime("%d.%m.%Y")
    except ValueError:
        return text
