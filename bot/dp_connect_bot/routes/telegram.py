"""Telegram webhook route blueprint – multi-tenant."""

from flask import Blueprint, request, jsonify

from dp_connect_bot.handlers.unified import unified_handle_message, unified_handle_callback
from dp_connect_bot.adapters.telegram import TelegramAdapter
from dp_connect_bot.services.voice import transcribe_telegram_voice
from dp_connect_bot.services import tenant_store
from dp_connect_bot.models.tenant import TenantContext
from dp_connect_bot.config import log

telegram_bp = Blueprint("telegram", __name__)


def _get_ctx(tenant_id: str) -> TenantContext | None:
    """Resolve tenant context from tenant_id."""
    tenant = tenant_store.get_tenant(tenant_id)
    if not tenant:
        return None
    return TenantContext.from_dict(tenant)


def _get_default_ctx() -> TenantContext | None:
    """Get default tenant context (first active tenant) for backward compat."""
    tenants = tenant_store.get_all_active()
    if tenants:
        return TenantContext.from_dict(tenants[0])
    return None


@telegram_bp.route("/webhook/<tenant_id>", methods=["POST"])
def telegram_webhook(tenant_id):
    ctx = _get_ctx(tenant_id)
    if not ctx:
        log.warning(f"Telegram webhook: unknown tenant {tenant_id}")
        return jsonify(ok=False, error="Unknown tenant"), 404

    return _handle_telegram_update(ctx)


@telegram_bp.route("/webhook", methods=["POST"])
def telegram_webhook_legacy():
    """Legacy webhook route (no tenant_id) – uses default tenant."""
    ctx = _get_default_ctx()
    if not ctx:
        log.warning("Telegram webhook (legacy): no default tenant")
        return jsonify(ok=True), 200

    return _handle_telegram_update(ctx)


def _handle_telegram_update(ctx: TenantContext):
    """Process a Telegram update with the given tenant context."""
    try:
        update = request.get_json()
        if not update:
            return jsonify(ok=True), 200

        adapter = TelegramAdapter(ctx=ctx)

        # --- Normal text message ---
        message = update.get("message")
        if message and message.get("text"):
            chat_id = message["chat"]["id"]
            text = message["text"]
            user_info = message.get("from", {})
            log.info(f"[TG:{ctx.tenant_id}:{chat_id}] {user_info.get('first_name', '?')}: {text}")

            prefixed = adapter.prefixed_chat_id(chat_id)
            session_key = f"tg_{ctx.tenant_id}_{chat_id}"
            response = unified_handle_message(session_key, text, user_info, channel="telegram", ctx=ctx)
            adapter.send_response(chat_id, response)

        # --- Voice / audio message ---
        elif message and (message.get("voice") or message.get("audio")):
            chat_id = message["chat"]["id"]
            user_info = message.get("from", {})
            voice = message.get("voice") or message.get("audio")
            file_id = voice.get("file_id")
            log.info(f"[TG:{ctx.tenant_id}:{chat_id}] Voice message received")

            adapter._send_message(chat_id, "\U0001f3a4 _Sprachnachricht wird verarbeitet..._")
            text = transcribe_telegram_voice(file_id, ctx=ctx)
            if text:
                adapter._send_message(chat_id, f"\U0001f3a4 _{text}_")
                session_key = f"tg_{ctx.tenant_id}_{chat_id}"
                response = unified_handle_message(session_key, text, user_info, channel="telegram", ctx=ctx)
                adapter.send_response(chat_id, response)
            else:
                adapter._send_message(
                    chat_id,
                    "Sorry, ich konnte die Sprachnachricht nicht verstehen. \U0001f605\n"
                    "Kannst du mir stattdessen schreiben was du brauchst?",
                )

        # --- Callback from inline keyboard ---
        callback = update.get("callback_query")
        if callback:
            chat_id = callback["message"]["chat"]["id"]
            data = callback.get("data", "")
            callback_id = callback["id"]
            user_info = callback.get("from", {})
            log.info(f"[TG:{ctx.tenant_id}:{chat_id}] Callback: {data}")

            session_key = f"tg_{ctx.tenant_id}_{chat_id}"
            response = unified_handle_callback(session_key, data, channel="telegram", ctx=ctx)
            adapter.send_response(chat_id, response)
            adapter.answer_callback(callback_id, response.answer_callback_text)

        return jsonify(ok=True), 200
    except Exception as e:
        log.error(f"Telegram webhook error [{ctx.tenant_id}]: {e}", exc_info=True)
        return jsonify(ok=True), 200
