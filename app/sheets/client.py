from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Any

from google.oauth2 import service_account
from googleapiclient.discovery import build

BUSINESS_HEADERS = [
    "Наименование",
    "Статья затрат",
    "Название поставщика",
    "Сумма",
    "Дата",
    "Комментарии",
    "Статус",
]
TECH_HEADERS = ["Bitrix ID", "Воронка"]

SCOPES = ["https://www.googleapis.com/auth/spreadsheets"]


@dataclass
class SheetLayout:
    header_row: int = 18
    data_start_row: int = 19
    data_end_row: int = 98


@dataclass
class MappedRow:
    title: str
    cost_article: str
    supplier: str
    amount: str
    date: str
    comments: str
    status: str
    bitrix_id: str
    category_name: str


class SheetsClient:
    def __init__(
        self,
        spreadsheet_id: str,
        sheet_name: str,
        service_account_path: Path,
        layout: SheetLayout | None = None,
    ) -> None:
        self.spreadsheet_id = spreadsheet_id
        self.sheet_name = sheet_name
        self.service_account_path = service_account_path
        self.layout = layout or SheetLayout()
        self._service: Any | None = None

    def _api(self) -> Any:
        if self._service is None:
            credentials = service_account.Credentials.from_service_account_file(
                str(self.service_account_path),
                scopes=SCOPES,
            )
            self._service = build("sheets", "v4", credentials=credentials, cache_discovery=False)
        return self._service

    def _range(self, a1: str) -> str:
        escaped = self.sheet_name.replace("'", "''")
        return f"'{escaped}'!{a1}"

    def to_sheet_values(self, row: MappedRow) -> list[str]:
        return [
            "",
            row.title,
            "",
            row.cost_article,
            row.supplier,
            row.amount,
            row.date,
            row.comments,
            row.status,
            row.bitrix_id,
            row.category_name,
        ]

    def parse_sheet_values(self, values: list[str]) -> MappedRow:
        normalized = list(values)
        while len(normalized) < 11:
            normalized.append("")
        return MappedRow(
            title=normalized[1],
            cost_article=normalized[3],
            supplier=normalized[4],
            amount=normalized[5],
            date=normalized[6],
            comments=normalized[7],
            status=normalized[8],
            bitrix_id=(normalized[9] or "").strip(),
            category_name=normalized[10],
        )

    def ensure_header(self) -> None:
        header_row = self.layout.header_row
        response = (
            self._api()
            .spreadsheets()
            .values()
            .get(
                spreadsheetId=self.spreadsheet_id,
                range=self._range(f"A{header_row}:K{header_row}"),
            )
            .execute()
        )
        header = response.get("values", [[]])[0] if response.get("values") else []
        while len(header) < 11:
            header.append("")

        checks = [
            (1, BUSINESS_HEADERS[0]),
            (3, BUSINESS_HEADERS[1]),
            (4, BUSINESS_HEADERS[2]),
            (5, BUSINESS_HEADERS[3]),
            (6, BUSINESS_HEADERS[4]),
            (7, BUSINESS_HEADERS[5]),
            (8, BUSINESS_HEADERS[6]),
        ]
        business_ok = all((header[index] or "").strip() == value for index, value in checks)
        if not business_ok:
            raise RuntimeError(
                f'Sheet "{self.sheet_name}" row {header_row} headers do not match. '
                f'Expected B/D–I: {" | ".join(BUSINESS_HEADERS)}'
            )

        need_tech = (header[9] or "").strip() != TECH_HEADERS[0] or (header[10] or "").strip() != TECH_HEADERS[1]
        if need_tech:
            (
                self._api()
                .spreadsheets()
                .values()
                .update(
                    spreadsheetId=self.spreadsheet_id,
                    range=self._range(f"J{header_row}:K{header_row}"),
                    valueInputOption="RAW",
                    body={"values": [TECH_HEADERS]},
                )
                .execute()
            )

    def read_data_rows(self) -> list[dict[str, Any]]:
        start = self.layout.data_start_row
        end = self.layout.data_end_row
        response = (
            self._api()
            .spreadsheets()
            .values()
            .get(
                spreadsheetId=self.spreadsheet_id,
                range=self._range(f"A{start}:K{end}"),
            )
            .execute()
        )
        rows = []
        for index, row in enumerate(response.get("values") or []):
            normalized = [str(cell or "") for cell in row]
            while len(normalized) < 11:
                normalized.append("")
            rows.append(
                {
                    "row_number": start + index,
                    "values": normalized[:11],
                }
            )
        return rows

    def batch_update_rows(self, updates: list[dict[str, Any]]) -> None:
        if not updates:
            return
        data = [
            {
                "range": self._range(f"A{item['row_number']}:K{item['row_number']}"),
                "values": [item["values"]],
            }
            for item in updates
        ]
        (
            self._api()
            .spreadsheets()
            .values()
            .batchUpdate(
                spreadsheetId=self.spreadsheet_id,
                body={"valueInputOption": "RAW", "data": data},
            )
            .execute()
        )
