import { config } from "../config.js";
const CATEGORY_ENTITY = {
    62: `DYNAMIC_${config.entityTypeId}_STAGE_62`,
    66: `DYNAMIC_${config.entityTypeId}_STAGE_66`,
    68: `DYNAMIC_${config.entityTypeId}_STAGE_68`,
};
const SELECT_FIELDS = [
    "id",
    "title",
    "opportunity",
    "createdTime",
    "stageId",
    "categoryId",
    config.costArticleField,
    config.commentField,
];
let mapsCache = null;
const MAPS_TTL_MS = 5 * 60 * 1000;
export async function getLookupMaps(client) {
    if (mapsCache && mapsCache.expiresAt > Date.now()) {
        return mapsCache.maps;
    }
    const maps = {
        stageMap: await loadStageMap(client),
        costArticleMap: await loadCostArticleMap(client),
    };
    mapsCache = { expiresAt: Date.now() + MAPS_TTL_MS, maps };
    return maps;
}
export async function fetchAccountingRows(client) {
    const maps = await getLookupMaps(client);
    const items = await client.callList("crm.item.list", {
        entityTypeId: config.entityTypeId,
        filter: {
            "@categoryId": [...config.categoryIds],
        },
        select: [...SELECT_FIELDS],
        order: { id: "ASC" },
    });
    return items.map((item) => mapItem(item, maps));
}
export async function fetchAccountingRowById(client, id) {
    const result = await client.call("crm.item.get", {
        entityTypeId: config.entityTypeId,
        id,
    });
    const item = result.item;
    if (!item) {
        return null;
    }
    const categoryId = Number(item.categoryId);
    if (!config.categoryIds.includes(categoryId)) {
        return null;
    }
    const maps = await getLookupMaps(client);
    return mapItem(item, maps);
}
async function loadStageMap(client) {
    const map = new Map();
    for (const categoryId of config.categoryIds) {
        const statuses = await client.callList("crm.status.list", {
            filter: { ENTITY_ID: CATEGORY_ENTITY[categoryId] },
        });
        for (const status of statuses) {
            map.set(status.STATUS_ID, status.NAME);
        }
    }
    return map;
}
async function loadCostArticleMap(client) {
    const result = await client.call("crm.item.fields", { entityTypeId: config.entityTypeId });
    const field = result.fields[config.costArticleField];
    const map = new Map();
    for (const item of field?.items ?? []) {
        const id = String(item.ID ?? item.id ?? "");
        const value = String(item.VALUE ?? item.value ?? "");
        if (id) {
            map.set(id, value);
        }
    }
    return map;
}
function mapItem(item, maps) {
    const categoryId = Number(item.categoryId);
    const costRaw = item[config.costArticleField];
    const commentRaw = item[config.commentField];
    return {
        bitrixId: Number(item.id),
        title: String(item.title ?? ""),
        costArticle: resolveEnum(costRaw, maps.costArticleMap),
        supplier: "",
        amount: formatAmount(item.opportunity),
        date: formatDate(item.createdTime),
        comments: commentRaw == null ? "" : String(commentRaw),
        status: maps.stageMap.get(String(item.stageId ?? "")) ??
            String(item.stageId ?? ""),
        categoryName: config.categoryNames[categoryId] ?? `category ${categoryId}`,
    };
}
function resolveEnum(raw, map) {
    if (raw == null || raw === "") {
        return "";
    }
    if (Array.isArray(raw)) {
        return raw.map((v) => map.get(String(v)) ?? String(v)).join(", ");
    }
    return map.get(String(raw)) ?? String(raw);
}
function formatAmount(value) {
    if (value == null || value === "") {
        return "";
    }
    const num = Number(value);
    if (Number.isNaN(num)) {
        return String(value);
    }
    return num
        .toLocaleString("ru-RU", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })
        .replace(/\u00a0/g, " ");
}
function formatDate(value) {
    if (!value) {
        return "";
    }
    const date = new Date(String(value));
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }
    const dd = String(date.getDate()).padStart(2, "0");
    const mm = String(date.getMonth() + 1).padStart(2, "0");
    const yyyy = date.getFullYear();
    return `${dd}.${mm}.${yyyy}`;
}
//# sourceMappingURL=accounting.js.map