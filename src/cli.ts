import { config } from "./config.js";
import {
  previewAccountingRows,
  runFullSync,
} from "./sync/service.js";

async function main(): Promise<void> {
  const dryRun = process.argv.includes("--dry-run");
  const preview = process.argv.includes("--preview");

  console.log("Bitrix → Google Sheets sync");
  console.log(`Sheet: ${config.sheetName} (${config.spreadsheetId})`);
  console.log(
    `Categories: ${config.categoryIds
      .map((id) => `${id} ${config.categoryNames[id]}`)
      .join(", ")}`,
  );
  if (dryRun) {
    console.log("Mode: dry-run (no writes)");
  }
  if (preview) {
    console.log("Mode: preview (Bitrix only, no Google Sheets)");
    await previewAccountingRows();
    return;
  }

  const stats = await runFullSync({ dryRun });
  console.log("Done.");
  console.log(
    `added=${stats.added} updated=${stats.updated} markedDeleted=${stats.markedDeleted} unchanged=${stats.unchanged}`,
  );
}

main().catch((error: unknown) => {
  const message = error instanceof Error ? error.message : String(error);
  console.error(`Sync failed: ${message}`);
  process.exitCode = 1;
});
