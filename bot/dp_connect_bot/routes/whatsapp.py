"""WhatsApp webhook route blueprint."""

from flask import Blueprint, request, jsonify

from dp_connect_bot.handlers.unified import unified_handle_message, unified_handle_callback
from dp_connect_bot.adapters.whatsapp import WhatsAppAdapter
from dp_connect_bot.services.voice import transcribe_whatsapp_voice
from dp_connect_bot.config import WHATSAPP_VERIFY_TOKEN, log

whatsapp_bp = Blueprint("whatsapp", __name__)
adapter = WhatsAppAdapter()


@whatsapp_bp.route("/whatsapp", methods=["GET"])
def whatsapp_verify():
    """WhatsApp webhook verification (hub challenge)."""
    mode = request.args.get("hub.mode")
    token = request.args.get("hub.verify_token")
    challenge = request.args.get("hub.challenge")

    if mode == "subscribe" and token == WHATSAPP_VERIFY_TOKEN:
        log.info("WhatsApp webhook verified")
        return challenge, 200
    return "Forbidden", 403


@whatsapp_bp.route("/whatsapp", methods=["POST"])
def whatsapp_webhook():
    """Receive incoming WhatsApp messages and callbacks."""
    try:
        payload = request.get_json()
        if not payload:
            return jsonify(ok=True), 200

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
                        log.info(f"[WA:{phone}] Voice message received")
                        adapter._send_message(phone, "\U0001f3a4 _Sprachnachricht wird verarbeitet..._")
                        audio_id = msg.get("audio", {}).get("id")
                        text = transcribe_whatsapp_voice(audio_id)
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

                    log.info(f"[WA:{phone}] Message: {text}")
                    prefixed = adapter.prefixed_chat_id(phone)
                    response = unified_handle_message(prefixed, text, user_info, channel="whatsapp")
                    adapter.send_response(phone, response)

        return jsonify(ok=True), 200
    except Exception as e:
        log.error(f"WhatsApp webhook error: {e}", exc_info=True)
        return jsonify(ok=True), 200
