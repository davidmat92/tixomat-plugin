"""WhatsApp webhook route blueprint – multi-tenant."""

from flask import Blueprint, request, jsonify

from dp_connect_bot.handlers.unified import unified_handle_message, unified_handle_callback
from dp_connect_bot.adapters.whatsapp import WhatsAppAdapter
from dp_connect_bot.services.voice import transcribe_whatsapp_voice
from dp_connect_bot.services import tenant_store
from dp_connect_bot.models.tenant import TenantContext
from dp_connect_bot.config import WHATSAPP_VERIFY_TOKEN, log

whatsapp_bp = Blueprint("whatsapp", __name__)


def _get_default_ctx() -> TenantContext | None:
    """Get default tenant context for backward compat."""
    tenants = tenant_store.get_all_active()
    if tenants:
        return TenantContext.from_dict(tenants[0])
    return None


@whatsapp_bp.route("/whatsapp/<tenant_id>", methods=["GET"])
def whatsapp_verify_tenant(tenant_id):
    """WhatsApp webhook verification for a specific tenant."""
    tenant = tenant_store.get_tenant(tenant_id)
    if not tenant:
        return "Not Found", 404

    mode = request.args.get("hub.mode")
    token = request.args.get("hub.verify_token")
    challenge = request.args.get("hub.challenge")

    verify_token = tenant.get("whatsapp_verify_token") or WHATSAPP_VERIFY_TOKEN
    if mode == "subscribe" and token == verify_token:
        log.info(f"WhatsApp webhook verified for tenant {tenant_id}")
        return challenge, 200
    return "Forbidden", 403


@whatsapp_bp.route("/whatsapp", methods=["GET"])
def whatsapp_verify():
    """WhatsApp webhook verification (legacy, no tenant_id)."""
    mode = request.args.get("hub.mode")
    token = request.args.get("hub.verify_token")
    challenge = request.args.get("hub.challenge")

    if mode == "subscribe" and token == WHATSAPP_VERIFY_TOKEN:
        log.info("WhatsApp webhook verified (legacy)")
        return challenge, 200
    return "Forbidden", 403


@whatsapp_bp.route("/whatsapp/<tenant_id>", methods=["POST"])
def whatsapp_webhook_tenant(tenant_id):
    """Receive incoming WhatsApp messages for a specific tenant."""
    tenant = tenant_store.get_tenant(tenant_id)
    if not tenant:
        return jsonify(ok=False), 404
    ctx = TenantContext.from_dict(tenant)
    return _handle_whatsapp_payload(ctx)


@whatsapp_bp.route("/whatsapp", methods=["POST"])
def whatsapp_webhook():
    """Receive incoming WhatsApp messages (legacy, no tenant_id)."""
    ctx = _get_default_ctx()
    if not ctx:
        return jsonify(ok=True), 200
    return _handle_whatsapp_payload(ctx)


def _handle_whatsapp_payload(ctx: TenantContext):
    """Process WhatsApp webhook payload with tenant context."""
    try:
        payload = request.get_json()
        if not payload:
            return jsonify(ok=True), 200

        adapter = WhatsAppAdapter(ctx=ctx)

        for entry in payload.get("entry", []):
            for change in entry.get("changes", []):
                value = change.get("value", {})
                messages = value.get("messages", [])
                contacts = value.get("contacts", [])

                for msg in messages:
                    phone = msg.get("from")
                    if not phone:
                        continue

                    name = contacts[0]["profile"]["name"] if contacts else ""
                    user_info = {"first_name": name}

                    # --- Interactive button/list reply (check BEFORE text guard) ---
                    if msg.get("type") == "interactive":
                        interactive = msg.get("interactive", {})
                        btn = interactive.get("button_reply") or interactive.get("list_reply")
                        if btn:
                            text = btn.get("id", "")
                        else:
                            continue

                    elif msg.get("type") == "text":
                        text = msg["text"]["body"]

                    # --- Voice message ---
                    elif msg.get("type") == "audio":
                        log.info(f"[WA:{ctx.tenant_id}:{phone}] Voice message received")
                        adapter._send_message(phone, "\U0001f3a4 _Sprachnachricht wird verarbeitet..._")
                        audio_id = msg.get("audio", {}).get("id")
                        text = transcribe_whatsapp_voice(audio_id, ctx=ctx)
                        if text:
                            adapter._send_message(phone, f"\U0001f3a4 _{text}_")
                        else:
                            adapter._send_message(
                                phone,
                                "Sorry, ich konnte die Sprachnachricht nicht verstehen. \U0001f605\n"
                                "Kannst du mir stattdessen schreiben was du brauchst?",
                            )
                            continue

                    else:
                        continue  # skip images, stickers, etc.

                    log.info(f"[WA:{ctx.tenant_id}:{phone}] Message: {text}")
                    session_key = f"wa_{ctx.tenant_id}_{phone}"
                    response = unified_handle_message(session_key, text, user_info, channel="whatsapp", ctx=ctx)
                    adapter.send_response(phone, response)

        return jsonify(ok=True), 200
    except Exception as e:
        log.error(f"WhatsApp webhook error [{ctx.tenant_id}]: {e}", exc_info=True)
        return jsonify(ok=True), 200
