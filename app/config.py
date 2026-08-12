from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path

from dotenv import load_dotenv

load_dotenv()

ROOT_DIR = Path(__file__).resolve().parent.parent


def _required(name: str) -> str:
    value = (os.getenv(name) or "").strip()
    if not value:
        raise RuntimeError(f"Missing required env variable: {name}")
    return value


def _normalize_webhook_url(url: str) -> str:
    return url if url.endswith("/") else f"{url}/"


@dataclass(frozen=True)
class Config:
    bitrix_webhook_url: str
    spreadsheet_id: str
    sheet_name: str
    google_service_account_path: Path
    outgoing_webhook_token: str
    port: int
    entity_type_id: int = 1106
    category_ids: tuple[int, ...] = (62, 66, 68)
    category_names: dict[int, str] | None = None
    cost_article_field: str = "ufCrm38_1786016637295"
    comment_field: str = "ufCrm38_1786016654344"
    deleted_status: str = "Удалено"

    def __post_init__(self) -> None:
        if self.category_names is None:
            object.__setattr__(
                self,
                "category_names",
                {
                    62: "Служебные записки",
                    66: "Счета на оплату",
                    68: "Командировочные",
                },
            )


def load_config() -> Config:
    sa_path = (os.getenv("GOOGLE_SERVICE_ACCOUNT_PATH") or "./credentials/service-account.json").strip()
    path = Path(sa_path)
    if not path.is_absolute():
        path = ROOT_DIR / path

    return Config(
        bitrix_webhook_url=_normalize_webhook_url(_required("BITRIX_WEBHOOK_URL")),
        spreadsheet_id=_required("SPREADSHEET_ID"),
        sheet_name=(os.getenv("SHEET_NAME") or "Снабжения").strip() or "Снабжения",
        google_service_account_path=path,
        outgoing_webhook_token=(os.getenv("OUTGOING_WEBHOOK_TOKEN") or "").strip(),
        port=int((os.getenv("PORT") or "3000").strip() or "3000"),
    )


config = load_config()
