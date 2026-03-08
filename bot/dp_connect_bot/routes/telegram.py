"""Telegram webhook route blueprint."""

from flask import Blueprint, request, jsonify

from dp_connect_bot.handlers.unified import unified_handle_message, unified_handle_callback
from dp_connect_bot.adapters.telegram import TelegramAdapter
from dp_connect_bot.services.voice import transcribe_telegram_voice
from dp_connect_bot.config import log

telegram_bp = Blueprint("telegram", __name__)
adapter = TelegramAdapter()


@telegram_bp.route("/webhook", methods=["POST"])
def telegram_webhook():
    try:
        update = request.get_json()
        if not update:
            return jsonify(ok=True), 200

        # --- Normal text message ---
        message = update.get("message")
        if message and message.get("text"):
            chat_id = message["chat"]["id"]
            text = message["text"]
            user_info = message.get("from", {})
            log.info(f"[TG:{chat_id}] {user_info.get('first_name', '?')}: {text}")

            prefixed = adapter.prefixed_chat_id(chat_id)
            response = unified_handle_message(prefixed, text, user_info, channel="telegram")
            adapter.send_response(chat_id, response)

        # --- Voice / audio message ---
        elif message and (message.get("voice") or message.get("audio")):
            chat_id = message["chat"]["id"]
            user_info = message.get("from", {})
            voice = message.get("voice") or message.get("audio")
            file_id = voice.get("file_id")
            log.info(f"[TG:{chat_id}] Voice message received")

            adapter._send_message(chat_id, "\U0001f3a4 _Sprachnachricht wird verarbeitet..._")
            text = transcribe_telegram_voice(file_id)
            if text:
                adapter._send_message(chat_id, f"\U0001f3a4 _{text}_")
                prefixed = adapter.prefixed_chat_id(chat_id)
                response = unified_handle_message(prefixed, text, user_info, channel="telegram")
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
            log.info(f"[TG:{chat_id}] Callback: {data}")

            prefixed = adapter.prefixed_chat_id(chat_id)
            response = unified_handle_callback(prefixed, data, channel="telegram")
            adapter.send_response(chat_id, response)
            adapter.answer_callback(callback_id, response.answer_callback_text)

        return jsonify(ok=True), 200
    except Exception as e:
        log.error(f"Telegram webhook error: {e}", exc_info=True)
        return jsonify(ok=True), 200
