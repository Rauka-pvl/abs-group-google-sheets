type BitrixResponse<T> = {
  result: T;
  total?: number;
  next?: number;
  error?: string;
  error_description?: string;
};

export class BitrixClient {
  constructor(private readonly webhookUrl: string) {}

  async call<T>(method: string, params: Record<string, unknown> = {}): Promise<T> {
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

    const data = (await response.json()) as BitrixResponse<T>;
    if (data.error) {
      throw new Error(
        `Bitrix error ${data.error}: ${data.error_description ?? "unknown"}`,
      );
    }

    return data.result;
  }

  async callList<T>(
    method: string,
    params: Record<string, unknown> = {},
  ): Promise<T[]> {
    const items: T[] = [];
    let start: number | undefined = 0;

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

      const data = (await response.json()) as BitrixResponse<T[] | { items: T[] }>;
      if (data.error) {
        throw new Error(
          `Bitrix error ${data.error}: ${data.error_description ?? "unknown"}`,
        );
      }

      const chunk = Array.isArray(data.result)
        ? data.result
        : ((data.result as { items: T[] }).items ?? []);
      items.push(...chunk);
      start = data.next;
    }

    return items;
  }
}

function appendParams(
  body: URLSearchParams,
  value: unknown,
  prefix = "",
): void {
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
    for (const [key, nested] of Object.entries(value as Record<string, unknown>)) {
      const nextPrefix = prefix ? `${prefix}[${key}]` : key;
      appendParams(body, nested, nextPrefix);
    }
    return;
  }

  body.append(prefix, String(value));
}
