import { config } from "../config.js";
function rowsEqual(a, b) {
    return a.length === b.length && a.every((value, index) => value === b[index]);
}
function isPlaceholderOrEmpty(values) {
    const title = (values[1] ?? "").trim();
    const bitrixId = (values[9] ?? "").trim();
    return !title && !bitrixId;
}
function toValues(sheets, item) {
    return sheets.toSheetValues({
        title: item.title,
        costArticle: item.costArticle,
        supplier: item.supplier,
        amount: item.amount,
        date: item.date,
        comments: item.comments,
        status: item.status,
        bitrixId: String(item.bitrixId),
        categoryName: item.categoryName,
    });
}
async function loadIndex(sheets) {
    await sheets.ensureHeader();
    const existing = await sheets.readDataRows();
    const idToRow = new Map();
    const freeSlots = [];
    for (const row of existing) {
        const parsed = sheets.parseSheetValues(row.values);
        if (parsed.bitrixId) {
            const bitrixId = Number(parsed.bitrixId);
            if (!Number.isNaN(bitrixId)) {
                idToRow.set(bitrixId, row);
            }
            continue;
        }
        if (isPlaceholderOrEmpty(row.values)) {
            freeSlots.push(row);
        }
    }
    return { idToRow, freeSlots };
}
export async function syncAccountingToSheet(sheets, accountingRows, options = {}) {
    const dryRun = Boolean(options.dryRun);
    const { idToRow, freeSlots } = await loadIndex(sheets);
    const stats = {
        added: 0,
        updated: 0,
        markedDeleted: 0,
        unchanged: 0,
    };
    const updates = [];
    const seenIds = new Set();
    let freeIndex = 0;
    for (const item of accountingRows) {
        seenIds.add(item.bitrixId);
        const next = toValues(sheets, item);
        const current = idToRow.get(item.bitrixId);
        if (current) {
            if (rowsEqual(current.values, next)) {
                stats.unchanged += 1;
            }
            else {
                updates.push({ rowNumber: current.rowNumber, values: next });
                stats.updated += 1;
            }
            continue;
        }
        const slot = freeSlots[freeIndex++];
        if (!slot) {
            throw new Error(`No free rows on "${config.sheetName}" between ` +
                `${sheets.layout.dataStartRow} and ${sheets.layout.dataEndRow}. ` +
                "Add empty data rows above the footer (Примечания) and retry.");
        }
        updates.push({ rowNumber: slot.rowNumber, values: next });
        stats.added += 1;
    }
    for (const [bitrixId, current] of idToRow.entries()) {
        if (seenIds.has(bitrixId)) {
            continue;
        }
        const parsed = sheets.parseSheetValues(current.values);
        if (parsed.status === config.deletedStatus) {
            stats.unchanged += 1;
            continue;
        }
        const marked = [...current.values];
        marked[8] = config.deletedStatus;
        updates.push({ rowNumber: current.rowNumber, values: marked });
        stats.markedDeleted += 1;
    }
    if (dryRun) {
        console.log("[dry-run] would add:", stats.added);
        console.log("[dry-run] would update:", stats.updated);
        console.log("[dry-run] would mark deleted:", stats.markedDeleted);
        console.log("[dry-run] unchanged:", stats.unchanged);
        return stats;
    }
    await sheets.batchUpdateRows(updates);
    return stats;
}
export async function upsertAccountingRow(sheets, item) {
    const { idToRow, freeSlots } = await loadIndex(sheets);
    const next = toValues(sheets, item);
    const current = idToRow.get(item.bitrixId);
    if (current) {
        if (rowsEqual(current.values, next)) {
            return "unchanged";
        }
        await sheets.batchUpdateRows([
            { rowNumber: current.rowNumber, values: next },
        ]);
        return "updated";
    }
    const slot = freeSlots[0];
    if (!slot) {
        throw new Error(`No free rows on "${config.sheetName}" between ` +
            `${sheets.layout.dataStartRow} and ${sheets.layout.dataEndRow}. ` +
            "Add empty data rows above the footer (Примечания) and retry.");
    }
    await sheets.batchUpdateRows([{ rowNumber: slot.rowNumber, values: next }]);
    return "added";
}
export async function markAccountingRowDeleted(sheets, bitrixId) {
    const { idToRow } = await loadIndex(sheets);
    const current = idToRow.get(bitrixId);
    if (!current) {
        return "missing";
    }
    const parsed = sheets.parseSheetValues(current.values);
    if (parsed.status === config.deletedStatus) {
        return "unchanged";
    }
    const marked = [...current.values];
    marked[8] = config.deletedStatus;
    await sheets.batchUpdateRows([
        { rowNumber: current.rowNumber, values: marked },
    ]);
    return "markedDeleted";
}
//# sourceMappingURL=sync.js.map