from __future__ import annotations

from typing import Any

import requests


def _append_params(data: dict[str, str], value: Any, prefix: str = "") -> None:
    if value is None:
        return

    if isinstance(value, (list, tuple)):
        for index, item in enumerate(value):
            next_prefix = f"{prefix}[{index}]" if prefix else str(index)
            _append_params(data, item, next_prefix)
        return

    if isinstance(value, dict):
        for key, nested in value.items():
            next_prefix = f"{prefix}[{key}]" if prefix else str(key)
            _append_params(data, nested, next_prefix)
        return

    if not prefix:
        raise ValueError("Cannot append scalar without a key")
    data[prefix] = str(value)


class BitrixClient:
    def __init__(self, webhook_url: str) -> None:
        self.webhook_url = webhook_url
        self.session = requests.Session()

    def call(self, method: str, params: dict[str, Any] | None = None) -> Any:
        payload: dict[str, str] = {}
        _append_params(payload, params or {})
        response = self.session.post(
            f"{self.webhook_url}{method}",
            data=payload,
            timeout=60,
        )
        response.raise_for_status()
        data = response.json()
        if data.get("error"):
            raise RuntimeError(
                f"Bitrix error {data.get('error')}: {data.get('error_description') or 'unknown'}"
            )
        return data.get("result")

    def call_list(self, method: str, params: dict[str, Any] | None = None) -> list[Any]:
        items: list[Any] = []
        start: int | None = 0
        base_params = dict(params or {})

        while start is not None:
            payload: dict[str, str] = {}
            _append_params(payload, {**base_params, "start": start})
            response = self.session.post(
                f"{self.webhook_url}{method}",
                data=payload,
                timeout=60,
            )
            response.raise_for_status()
            data = response.json()
            if data.get("error"):
                raise RuntimeError(
                    f"Bitrix error {data.get('error')}: {data.get('error_description') or 'unknown'}"
                )

            result = data.get("result")
            if isinstance(result, list):
                chunk = result
            elif isinstance(result, dict):
                chunk = result.get("items") or []
            else:
                chunk = []

            items.extend(chunk)
            start = data.get("next")

        return items
