from __future__ import annotations

from flask import Flask, jsonify, request

from app.config import config
from app.service import handle_bitrix_event

app = Flask(__name__)


def _extract_token() -> str:
    body = request.form.to_dict(flat=False)
    # Prefer nested auth[application_token] from urlencoded
    token = request.form.get("auth[application_token]")
    if token:
        return token

    # JSON body support
    json_body = request.get_json(silent=True) or {}
    auth = json_body.get("auth") or {}
    if isinstance(auth, dict) and auth.get("application_token"):
        return str(auth["application_token"])

    nested = request.args.get("auth[application_token]")
    if nested:
        return nested

    # Flask parses auth[application_token] sometimes as nested via MultiDict - try getlist patterns
    for key in body:
        if key.endswith("application_token") or key == "application_token":
            values = body.get(key) or []
            if values:
                return str(values[0])

    return ""


def _payload_from_request() -> dict:
    json_body = request.get_json(silent=True)
    if isinstance(json_body, dict) and json_body.get("event"):
        return json_body

    # Bitrix sends application/x-www-form-urlencoded with nested keys
    event = request.form.get("event")
    item_id = request.form.get("data[FIELDS][ID]")
    entity_type_id = request.form.get("data[FIELDS][ENTITY_TYPE_ID]")
    token = request.form.get("auth[application_token]")

    return {
        "event": event,
        "data": {
            "FIELDS": {
                "ID": item_id,
                "ENTITY_TYPE_ID": entity_type_id,
            }
        },
        "auth": {"application_token": token},
    }


@app.get("/health")
def health():
    return jsonify({"ok": True})


@app.post("/action")
def action():
    try:
        token = _extract_token()
        if not token or token != config.outgoing_webhook_token:
            print("Rejected /action: invalid application_token")
            return jsonify({"ok": False, "error": "invalid token"}), 401

        payload = _payload_from_request()
        print(
            "Incoming event="
            f"{payload.get('event') or '?'} "
            f"id={(payload.get('data') or {}).get('FIELDS', {}).get('ID') or '?'} "
            f"entityTypeId={(payload.get('data') or {}).get('FIELDS', {}).get('ENTITY_TYPE_ID') or '?'}"
        )

        result = handle_bitrix_event(payload)
        if result.get("status") == "ignored":
            print(f"Ignored: {result.get('reason')}")
            return jsonify({"ok": True, "ignored": True, "reason": result.get("reason")})

        print(f"Processed #{result.get('bitrixId')}: {result.get('action')}")
        return jsonify(
            {
                "ok": True,
                "bitrixId": result.get("bitrixId"),
                "action": result.get("action"),
            }
        )
    except Exception as exc:  # noqa: BLE001
        print(f"/action failed: {exc}")
        return jsonify({"ok": False, "error": str(exc)}), 500


def main() -> None:
    if not config.outgoing_webhook_token:
        raise SystemExit("Missing OUTGOING_WEBHOOK_TOKEN in .env")

    print(f"Webhook server listening on :{config.port}")
    print("Handler URL path: POST /action")
    print(
        f"Tracked SPA entityTypeId={config.entity_type_id}, "
        f"categories={','.join(str(x) for x in config.category_ids)}"
    )
    print(
        "Subscribe outgoing webhook to "
        "ONCRMDYNAMICITEMADD / ONCRMDYNAMICITEMUPDATE / ONCRMDYNAMICITEMDELETE"
    )
    app.run(host="0.0.0.0", port=config.port)


if __name__ == "__main__":
    main()
