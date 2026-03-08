import hashlib
import time

from flask import Blueprint, request, jsonify

from dp_connect_bot.config import BETA_HINT_PLAIN, log
from dp_connect_bot.handlers.unified import unified_handle_message, unified_handle_callback
from dp_connect_bot.adapters.webchat import WebchatAdapter
from dp_connect_bot.models.session import session_manager

webchat_bp = Blueprint("webchat", __name__)


@webchat_bp.route("/chat/init", methods=["POST", "OPTIONS"])
def webchat_init():
    if request.method == "OPTIONS":
        return "", 204
    try:
        data = request.get_json() or {}
        visitor_id = data.get("visitor_id", str(time.time()))
        chat_id = f"web_{hashlib.md5(visitor_id.encode()).hexdigest()[:12]}"

        session = session_manager.get(chat_id)
        session["channel"] = "web"

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
        welcome = (
            f"Hey{' ' + name if name else ''}! 👋\n\n"
            f"Ich bin dein Tixomat Ticket-Assistent.\n\n"
            f"Sag mir einfach welches Event dich interessiert!{BETA_HINT_PLAIN}"
        )
        return jsonify(ok=True, chat_id=chat_id, message=welcome)
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

        text = data["message"]
        wc_cart = data.get("wc_cart")

        log.info(f"[WEB:{chat_id}] {text}")

        response = unified_handle_message(chat_id, text, channel="web", wc_cart=wc_cart)
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

        log.info(f"[WEB:{chat_id}] Action: {callback}")

        response = unified_handle_callback(chat_id, callback, channel="web")
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
