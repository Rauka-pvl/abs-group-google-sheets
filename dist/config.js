import { config as loadEnv } from "dotenv";
import path from "node:path";
loadEnv();
function required(name) {
    const value = process.env[name]?.trim();
    if (!value) {
        throw new Error(`Missing required env variable: ${name}`);
    }
    return value;
}
function normalizeWebhookUrl(url) {
    return url.endsWith("/") ? url : `${url}/`;
}
export const config = {
    bitrixWebhookUrl: normalizeWebhookUrl(required("BITRIX_WEBHOOK_URL")),
    spreadsheetId: required("SPREADSHEET_ID"),
    sheetName: process.env.SHEET_NAME?.trim() || "Снабжения",
    googleServiceAccountPath: path.resolve(process.env.GOOGLE_SERVICE_ACCOUNT_PATH?.trim() ||
        "./credentials/service-account.json"),
    outgoingWebhookToken: process.env.OUTGOING_WEBHOOK_TOKEN?.trim() || "",
    port: Number(process.env.PORT?.trim() || "3000"),
    entityTypeId: 1106,
    categoryIds: [62, 66, 68],
    categoryNames: {
        62: "Служебные записки",
        66: "Счета на оплату",
        68: "Командировочные",
    },
    costArticleField: "ufCrm38_1786016637295",
    commentField: "ufCrm38_1786016654344",
    deletedStatus: "Удалено",
};
//# sourceMappingURL=config.js.map