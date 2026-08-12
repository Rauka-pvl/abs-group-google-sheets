import express from "express";
import { config } from "./config.js";
import { handleBitrixEvent } from "./sync/service.js";

type BitrixWebhookBody = {
  event?: string;
  data?: {
    FIELDS?: {
      ID?: string | number;
      ENTITY_TYPE_ID?: string | number;
    };
  };
  auth?: {
    application_token?: string;
  };
};

function extractToken(req: express.Request): string {
  const body = req.body as BitrixWebhookBody;
  const fromBody = body?.auth?.application_token;
  if (typeof fromBody === "string" && fromBody) {
    return fromBody;
  }

  const fromQuery = req.query.auth;
  if (typeof fromQuery === "string" && fromQuery) {
    return fromQuery;
  }

  const nested = req.query["auth[application_token]"];
  if (typeof nested === "string" && nested) {
    return nested;
  }

  return "";
}

const app = express();
app.use(express.urlencoded({ extended: true }));
app.use(express.json());

app.get("/health", (_req, res) => {
  res.status(200).json({ ok: true });
});

app.post("/action", async (req, res) => {
  try {
    const token = extractToken(req);
    if (!token || token !== config.outgoingWebhookToken) {
      console.warn("Rejected /action: invalid application_token");
      res.status(401).json({ ok: false, error: "invalid token" });
      return;
    }

    const body = req.body as BitrixWebhookBody;
    console.log(
      `Incoming event=${body.event ?? "?"} id=${body.data?.FIELDS?.ID ?? "?"} entityTypeId=${body.data?.FIELDS?.ENTITY_TYPE_ID ?? "?"}`,
    );

    const result = await handleBitrixEvent(body);
    if (result.status === "ignored") {
      console.log(`Ignored: ${result.reason}`);
      res.status(200).json({ ok: true, ignored: true, reason: result.reason });
      return;
    }

    console.log(
      `Processed #${result.bitrixId}: ${result.action}`,
    );
    res.status(200).json({
      ok: true,
      bitrixId: result.bitrixId,
      action: result.action,
    });
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : String(error);
    console.error(`/action failed: ${message}`);
    res.status(500).json({ ok: false, error: message });
  }
});

if (!config.outgoingWebhookToken) {
  console.error("Missing OUTGOING_WEBHOOK_TOKEN in .env");
  process.exit(1);
}

app.listen(config.port, () => {
  console.log(`Webhook server listening on :${config.port}`);
  console.log(`Handler URL path: POST /action`);
  console.log(
    `Tracked SPA entityTypeId=${config.entityTypeId}, categories=${config.categoryIds.join(",")}`,
  );
  console.log(
    "Subscribe outgoing webhook to ONCRMDYNAMICITEMADD / ONCRMDYNAMICITEMUPDATE / ONCRMDYNAMICITEMDELETE",
  );
});
