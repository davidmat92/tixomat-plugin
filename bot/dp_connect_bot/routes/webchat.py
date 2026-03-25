"""Webchat route blueprint – multi-tenant."""

import hashlib
import time

from flask import Blueprint, request, jsonify

from dp_connect_bot.config import BETA_HINT_PLAIN, log
from dp_connect_bot.handlers.unified import unified_handle_message, unified_handle_callback
from dp_connect_bot.adapters.webchat import WebchatAdapter
from dp_connect_bot.models.session import session_manager
from dp_connect_bot.models.tenant import TenantContext
from dp_connect_bot.services import tenant_store

webchat_bp = Blueprint("webchat", __name__)


def _resolve_ctx(data: dict) -> TenantContext | None:
    """Resolve tenant context from request data.

    The webchat widget sends tenant_id with every request.
    Falls back to the first active tenant for backward compatibility.
    """
    tenant_id = data.get("tenant_id", "")
    if tenant_id:
        tenant = tenant_store.get_tenant(tenant_id)
        if tenant:
            return TenantContext.from_dict(tenant)

    # Backward compat: use first active tenant
    tenants = tenant_store.get_all_active()
    if tenants:
        return TenantContext.from_dict(tenants[0])
    return None


def _resolve_ctx_from_args() -> TenantContext | None:
    """Resolve tenant context from query params."""
    tenant_id = request.args.get("tenant_id", "")
    if tenant_id:
        tenant = tenant_store.get_tenant(tenant_id)
        if tenant:
            return TenantContext.from_dict(tenant)
    tenants = tenant_store.get_all_active()
    if tenants:
        return TenantContext.from_dict(tenants[0])
    return None


@webchat_bp.route("/chat/init", methods=["POST", "OPTIONS"])
def webchat_init():
    if request.method == "OPTIONS":
        return "", 204
    try:
        data = request.get_json() or {}
        ctx = _resolve_ctx(data)
        if not ctx:
            return jsonify(ok=False, error="No tenant configured"), 400

        visitor_id = data.get("visitor_id", str(time.time()))
        chat_id = f"web_{ctx.tenant_id}_{hashlib.md5(visitor_id.encode()).hexdigest()[:12]}"

        session = session_manager.get(chat_id)
        session["channel"] = "web"
        session["tenant_id"] = ctx.tenant_id

        if data.get("customer_name"):
            session["customer_name"] = data["customer_name"]

        if data.get("wp_user_id"):
            session.setdefault("user_info", {}).update({
                "wp_user_id": data.get("wp_user_id"),
                "wp_display_name": data.get("wp_display_name", ""),
                "wp_email": data.get("wp_email", ""),
                "wp_username": data.get("wp_username", ""),
            })
            if not session.get("customer_name") and data.get("wp_display_name"):
                session["customer_name"] = data["wp_display_name"]

        session_manager.save(chat_id, session)

        name = session.get("customer_name", "")
        bot_name = ctx.bot_name or "Ticket-Assistent"
        greeting = ctx.greeting
        if not greeting:
            greeting = (
                f"Hey{' ' + name if name else ''}! \U0001f44b\n\n"
                f"Ich bin dein {bot_name}.\n\n"
                f"Sag mir einfach welches Event dich interessiert!{BETA_HINT_PLAIN}"
            )
        else:
            # Substitute placeholders in custom greeting
            greeting = greeting.replace("{name}", name or "").replace("{bot_name}", bot_name)

        return jsonify(ok=True, chat_id=chat_id, message=greeting, tenant_id=ctx.tenant_id)
    except Exception as e:
        log.error(f"[webchat_init] Error: {e}")
        return jsonify(ok=False, error="Internal error"), 500


@webchat_bp.route("/chat/send", methods=["POST", "OPTIONS"])
def webchat_send():
    if request.method == "OPTIONS":
        return "", 204
    try:
        data = request.get_json()
        if not data or not data.get("chat_id") or not data.get("message"):
            return jsonify(ok=False, error="Missing chat_id or message"), 400

        chat_id = data["chat_id"]
        if not chat_id.startswith("web_"):
            return jsonify(ok=False, error="Invalid chat_id"), 400

        ctx = _resolve_ctx(data)
        if not ctx:
            return jsonify(ok=False, error="No tenant configured"), 400

        text = data["message"]
        wc_cart = data.get("wc_cart")

        log.info(f"[WEB:{ctx.tenant_id}:{chat_id}] {text}")

        response = unified_handle_message(chat_id, text, channel="web", wc_cart=wc_cart, ctx=ctx)
        adapter = WebchatAdapter()
        result = adapter.build_json_response(response)
        return jsonify(ok=True, **result)
    except Exception as e:
        log.error(f"[webchat_send] Error: {e}")
        return jsonify(ok=False, error="Internal error"), 500


@webchat_bp.route("/chat/action", methods=["POST", "OPTIONS"])
def webchat_action():
    if request.method == "OPTIONS":
        return "", 204
    try:
        data = request.get_json()
        chat_id = data.get("chat_id")
        callback = data.get("callback", "")

        if not chat_id or not chat_id.startswith("web_"):
            return jsonify(ok=False, error="Invalid chat_id"), 400

        ctx = _resolve_ctx(data)
        if not ctx:
            return jsonify(ok=False, error="No tenant configured"), 400

        log.info(f"[WEB:{ctx.tenant_id}:{chat_id}] Action: {callback}")

        response = unified_handle_callback(chat_id, callback, channel="web", ctx=ctx)
        adapter = WebchatAdapter()
        result = adapter.build_json_response(response)
        return jsonify(ok=True, **result)
    except Exception as e:
        log.error(f"[webchat_action] Error: {e}")
        return jsonify(ok=False, error="Internal error"), 500


@webchat_bp.route("/chat/has_last_order", methods=["GET", "OPTIONS"])
def chat_has_last_order():
    if request.method == "OPTIONS":
        return "", 204
    try:
        chat_id = request.args.get("chat_id", "")
        if not chat_id:
            return jsonify(ok=False)

        session = session_manager.get(chat_id)
        last_order = session.get("last_order")

        if not last_order:
            return jsonify(ok=True, has_last_order=False, items=[])

        return jsonify(ok=True, has_last_order=True, items=[
            {"title": i.get("title", ""), "quantity": i.get("quantity", 0)}
            for i in last_order
        ])
    except Exception as e:
        log.error(f"[chat_has_last_order] Error: {e}")
        return jsonify(ok=False, error="Internal error"), 500
