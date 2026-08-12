export class BitrixClient {
    webhookUrl;
    constructor(webhookUrl) {
        this.webhookUrl = webhookUrl;
    }
    async call(method, params = {}) {
        const url = `${this.webhookUrl}${method}`;
        const body = new URLSearchParams();
        appendParams(body, params);
        const response = await fetch(url, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body,
        });
        if (!response.ok) {
            throw new Error(`Bitrix HTTP ${response.status} for ${method}`);
        }
        const data = (await response.json());
        if (data.error) {
            throw new Error(`Bitrix error ${data.error}: ${data.error_description ?? "unknown"}`);
        }
        return data.result;
    }
    async callList(method, params = {}) {
        const items = [];
        let start = 0;
        while (start !== undefined) {
            const url = `${this.webhookUrl}${method}`;
            const body = new URLSearchParams();
            appendParams(body, { ...params, start });
            const response = await fetch(url, {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body,
            });
            if (!response.ok) {
                throw new Error(`Bitrix HTTP ${response.status} for ${method}`);
            }
            const data = (await response.json());
            if (data.error) {
                throw new Error(`Bitrix error ${data.error}: ${data.error_description ?? "unknown"}`);
            }
            const chunk = Array.isArray(data.result)
                ? data.result
                : (data.result.items ?? []);
            items.push(...chunk);
            start = data.next;
        }
        return items;
    }
}
function appendParams(body, value, prefix = "") {
    if (value === undefined || value === null) {
        return;
    }
    if (Array.isArray(value)) {
        value.forEach((item, index) => {
            appendParams(body, item, `${prefix}[${index}]`);
        });
        return;
    }
    if (typeof value === "object") {
        for (const [key, nested] of Object.entries(value)) {
            const nextPrefix = prefix ? `${prefix}[${key}]` : key;
            appendParams(body, nested, nextPrefix);
        }
        return;
    }
    body.append(prefix, String(value));
}
//# sourceMappingURL=client.js.map