import { access } from "node:fs/promises";
import {
  fetchAccountingRowById,
  fetchAccountingRows,
} from "../bitrix/accounting.js";
import { BitrixClient } from "../bitrix/client.js";
import { config } from "../config.js";
import { SheetsClient } from "../sheets/client.js";
import {
  markAccountingRowDeleted,
  syncAccountingToSheet,
  upsertAccountingRow,
  type SyncStats,
} from "../sheets/sync.js";

export function createBitrixClient(): BitrixClient {
  return new BitrixClient(config.bitrixWebhookUrl);
}

export async function createSheetsClient(): Promise<SheetsClient> {
  try {
    await access(config.googleServiceAccountPath);
  } catch {
    throw new Error(
      `Google service account file not found: ${config.googleServiceAccountPath}\n` +
        "Create a service account key, save it there, and share the spreadsheet with the SA email (Editor).",
    );
  }

  return new SheetsClient(
    config.spreadsheetId,
    config.sheetName,
    config.googleServiceAccountPath,
  );
}

export async function runFullSync(options: {
  dryRun?: boolean;
}): Promise<SyncStats> {
  const bitrix = createBitrixClient();
  const rows = await fetchAccountingRows(bitrix);
  console.log(`Fetched ${rows.length} card(s) from Bitrix`);
  const sheets = await createSheetsClient();
  return syncAccountingToSheet(sheets, rows, options);
}

export async function previewAccountingRows(): Promise<void> {
  const bitrix = createBitrixClient();
  const rows = await fetchAccountingRows(bitrix);
  console.log(`Fetched ${rows.length} card(s) from Bitrix`);
  for (const row of rows) {
    console.log(
      `#${row.bitrixId} | ${row.categoryName} | ${row.title} | ${row.amount} | ${row.date} | ${row.status}`,
    );
  }
}

export type WebhookResult =
  | { status: "ignored"; reason: string }
  | { status: "ok"; action: string; bitrixId: number };

const SPA_EVENTS = new Set([
  "ONCRMDYNAMICITEMADD",
  "ONCRMDYNAMICITEMUPDATE",
  "ONCRMDYNAMICITEMDELETE",
]);

const DEAL_EVENTS = new Set([
  "ONCRMDEALADD",
  "ONCRMDEALUPDATE",
  "ONCRMDEALDELETE",
]);

export async function handleBitrixEvent(payload: {
  event?: string;
  data?: { FIELDS?: { ID?: string | number; ENTITY_TYPE_ID?: string | number } };
  auth?: { application_token?: string };
}): Promise<WebhookResult> {
  const event = String(payload.event ?? "").toUpperCase();
  const id = Number(payload.data?.FIELDS?.ID);
  const entityTypeId = Number(payload.data?.FIELDS?.ENTITY_TYPE_ID);

  if (!event || Number.isNaN(id)) {
    return { status: "ignored", reason: "missing event or id" };
  }

  if (DEAL_EVENTS.has(event)) {
    return {
      status: "ignored",
      reason:
        "ONCRMDEAL* is for classic deals. Accounting funnels are SPA «Заявка в бухгалтерию» — use ONCRMDYNAMICITEMADD/UPDATE/DELETE",
    };
  }

  if (!SPA_EVENTS.has(event)) {
    return { status: "ignored", reason: `unsupported event ${event}` };
  }

  if (!Number.isNaN(entityTypeId) && entityTypeId !== config.entityTypeId) {
    return {
      status: "ignored",
      reason: `entityTypeId ${entityTypeId} is not accounting SPA ${config.entityTypeId}`,
    };
  }

  const sheets = await createSheetsClient();
  const bitrix = createBitrixClient();

  if (event === "ONCRMDYNAMICITEMDELETE") {
    const action = await markAccountingRowDeleted(sheets, id);
    return { status: "ok", action, bitrixId: id };
  }

  const row = await fetchAccountingRowById(bitrix, id);
  if (!row) {
    // Item moved out of tracked funnels or not found — soft-delete if present.
    const action = await markAccountingRowDeleted(sheets, id);
    return {
      status: "ok",
      action: action === "missing" ? "skipped_out_of_scope" : action,
      bitrixId: id,
    };
  }

  const action = await upsertAccountingRow(sheets, row);
  return { status: "ok", action, bitrixId: id };
}
