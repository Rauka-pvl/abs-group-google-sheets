import { google, type sheets_v4 } from "googleapis";
import { readFile } from "node:fs/promises";

/**
 * Layout of sheet «Снабжения» (header row 18):
 * A empty | B Наименование | C spacer | D Статья затрат | E Поставщик |
 * F Сумма | G Дата | H Комментарии | I Статус | J Bitrix ID | K Воронка
 */
export const BUSINESS_HEADERS = [
  "Наименование",
  "Статья затрат",
  "Название поставщика",
  "Сумма",
  "Дата",
  "Комментарии",
  "Статус",
] as const;

export const TECH_HEADERS = ["Bitrix ID", "Воронка"] as const;

export type SheetRow = string[];

export type SheetLayout = {
  headerRow: number;
  dataStartRow: number;
  dataEndRow: number;
};

const DEFAULT_LAYOUT: SheetLayout = {
  headerRow: 18,
  dataStartRow: 19,
  dataEndRow: 98,
};

export type MappedRow = {
  title: string;
  costArticle: string;
  supplier: string;
  amount: string;
  date: string;
  comments: string;
  status: string;
  bitrixId: string;
  categoryName: string;
};

export class SheetsClient {
  private sheets: sheets_v4.Sheets | null = null;
  readonly layout: SheetLayout;

  constructor(
    private readonly spreadsheetId: string,
    private readonly sheetName: string,
    private readonly serviceAccountPath: string,
    layout: Partial<SheetLayout> = {},
  ) {
    this.layout = { ...DEFAULT_LAYOUT, ...layout };
  }

  private async api(): Promise<sheets_v4.Sheets> {
    if (this.sheets) {
      return this.sheets;
    }

    const raw = await readFile(this.serviceAccountPath, "utf8");
    const credentials = JSON.parse(raw) as {
      client_email: string;
      private_key: string;
    };

    const auth = new google.auth.JWT({
      email: credentials.client_email,
      key: credentials.private_key,
      scopes: ["https://www.googleapis.com/auth/spreadsheets"],
    });

    this.sheets = google.sheets({ version: "v4", auth });
    return this.sheets;
  }

  private range(a1: string): string {
    const escaped = `'${this.sheetName.replace(/'/g, "''")}'`;
    return `${escaped}!${a1}`;
  }

  toSheetValues(row: MappedRow): SheetRow {
    return [
      "",
      row.title,
      "",
      row.costArticle,
      row.supplier,
      row.amount,
      row.date,
      row.comments,
      row.status,
      row.bitrixId,
      row.categoryName,
    ];
  }

  parseSheetValues(values: SheetRow): MappedRow {
    const normalized = [...values];
    while (normalized.length < 11) {
      normalized.push("");
    }
    return {
      title: normalized[1] ?? "",
      costArticle: normalized[3] ?? "",
      supplier: normalized[4] ?? "",
      amount: normalized[5] ?? "",
      date: normalized[6] ?? "",
      comments: normalized[7] ?? "",
      status: normalized[8] ?? "",
      bitrixId: (normalized[9] ?? "").trim(),
      categoryName: normalized[10] ?? "",
    };
  }

  async ensureHeader(): Promise<void> {
    const sheets = await this.api();
    const { headerRow } = this.layout;
    const response = await sheets.spreadsheets.values.get({
      spreadsheetId: this.spreadsheetId,
      range: this.range(`A${headerRow}:K${headerRow}`),
    });

    const header = response.data.values?.[0] ?? [];
    const checks: Array<[number, string]> = [
      [1, BUSINESS_HEADERS[0]],
      [3, BUSINESS_HEADERS[1]],
      [4, BUSINESS_HEADERS[2]],
      [5, BUSINESS_HEADERS[3]],
      [6, BUSINESS_HEADERS[4]],
      [7, BUSINESS_HEADERS[5]],
      [8, BUSINESS_HEADERS[6]],
    ];

    const businessOk = checks.every(
      ([index, value]) => (header[index] ?? "").trim() === value,
    );

    if (!businessOk) {
      throw new Error(
        `Sheet "${this.sheetName}" row ${headerRow} headers do not match. ` +
          `Expected B/D–I: ${BUSINESS_HEADERS.join(" | ")}`,
      );
    }

    const needTechHeaders =
      (header[9] ?? "").trim() !== TECH_HEADERS[0] ||
      (header[10] ?? "").trim() !== TECH_HEADERS[1];

    if (needTechHeaders) {
      await sheets.spreadsheets.values.update({
        spreadsheetId: this.spreadsheetId,
        range: this.range(`J${headerRow}:K${headerRow}`),
        valueInputOption: "RAW",
        requestBody: { values: [[...TECH_HEADERS]] },
      });
    }
  }

  async readDataRows(): Promise<
    Array<{ rowNumber: number; values: SheetRow }>
  > {
    const sheets = await this.api();
    const { dataStartRow, dataEndRow } = this.layout;
    const response = await sheets.spreadsheets.values.get({
      spreadsheetId: this.spreadsheetId,
      range: this.range(`A${dataStartRow}:K${dataEndRow}`),
    });

    return (response.data.values ?? []).map((row, index) => {
      const normalized = [...row.map((cell) => String(cell ?? ""))];
      while (normalized.length < 11) {
        normalized.push("");
      }
      return {
        rowNumber: dataStartRow + index,
        values: normalized.slice(0, 11),
      };
    });
  }

  async batchUpdateRows(
    updates: Array<{ rowNumber: number; values: SheetRow }>,
  ): Promise<void> {
    if (updates.length === 0) {
      return;
    }
    const sheets = await this.api();
    await sheets.spreadsheets.values.batchUpdate({
      spreadsheetId: this.spreadsheetId,
      requestBody: {
        valueInputOption: "RAW",
        data: updates.map((update) => ({
          range: this.range(`A${update.rowNumber}:K${update.rowNumber}`),
          values: [update.values],
        })),
      },
    });
  }
}
