"""
Telegram adapter – renders BotResponse into Telegram messages with inline keyboards.
Adapted for Tixomat event ticketing bot.
"""

import json
import requests

from dp_connect_bot.adapters.base import ChannelAdapter
from dp_connect_bot.config import TELEGRAM_API, log
from dp_connect_bot.models.response import BotResponse, Keyboard, KeyboardType
from dp_connect_bot.services.event_cache import cache
from dp_connect_bot.utils.formatting import format_price_de


class TelegramAdapter(ChannelAdapter):
    @property
    def channel_name(self) -> str:
        return "telegram"

    @property
    def chat_id_prefix(self) -> str:
        return "tg_"

    def send_response(self, chat_id, response: BotResponse):
        if response.is_silent:
            return

        # Build Telegram inline keyboards from BotResponse keyboards
        reply_markup = self._build_reply_markup(response.keyboards)

        # Send text
        if response.text:
            self._send_message(chat_id, response.text, reply_markup=reply_markup)

        # Answer callback query if present
        if response.answer_callback_text:
            # This is handled separately in the route
            pass

    def send_typing(self, chat_id):
        try:
            requests.post(
                f"{TELEGRAM_API}/sendChatAction",
                json={"chat_id": chat_id, "action": "typing"},
                timeout=5,
            )
        except Exception:
            pass

    def answer_callback(self, callback_query_id, text=""):
        """Answer a Telegram callback query."""
        try:
            requests.post(
                f"{TELEGRAM_API}/answerCallbackQuery",
                json={"callback_query_id": callback_query_id, "text": text},
                timeout=5,
            )
        except Exception:
            pass

    def _send_message(self, chat_id, text, parse_mode="Markdown", reply_markup=None):
        """Send a message via Telegram API, with chunking and Markdown fallback."""
        chunks = [text[i:i+4000] for i in range(0, len(text), 4000)]
        for i, chunk in enumerate(chunks):
            payload = {"chat_id": chat_id, "text": chunk, "parse_mode": parse_mode}
            if reply_markup and i == len(chunks) - 1:
                payload["reply_markup"] = (
                    json.dumps(reply_markup) if isinstance(reply_markup, dict) else reply_markup
                )
            try:
                resp = requests.post(f"{TELEGRAM_API}/sendMessage", json=payload, timeout=10)
                if not resp.ok:
                    log.warning(f"Telegram send failed (Markdown): {resp.text}")
                    # Retry without parse_mode (plain text fallback)
                    payload.pop("parse_mode", None)
                    resp2 = requests.post(f"{TELEGRAM_API}/sendMessage", json=payload, timeout=10)
                    if not resp2.ok:
                        log.error(f"Telegram send failed (plain): {resp2.text}")
            except Exception as e:
                log.error(f"Telegram send error: {e}")

    def _build_reply_markup(self, keyboards):
        """Convert list of generic Keyboards to Telegram inline_keyboard format."""
        if not keyboards:
            return None

        all_buttons = []
        for kb in keyboards:
            if kb.type == KeyboardType.CATEGORIES:
                # Ticketkategorien oder Event-Auswahl
                for btn in kb.buttons:
                    label = btn.text
                    if btn.sublabel:
                        label += f" | {btn.sublabel}"
                    all_buttons.append([
                        {"text": label, "callback_data": btn.callback_data}
                    ])
            elif kb.type == KeyboardType.QUANTITIES:
                # Mengen-Buttons fuer Tickets
                markup = self._build_quantity_keyboard(kb)
                if markup:
                    all_buttons.extend(markup.get("inline_keyboard", []))
            elif kb.type == KeyboardType.EVENTS:
                # Event-Auswahl-Buttons
                for btn in kb.buttons:
                    label = btn.text
                    if btn.sublabel:
                        label += f" | {btn.sublabel}"
                    all_buttons.append([
                        {"text": label, "callback_data": btn.callback_data}
                    ])
            elif kb.type == KeyboardType.CALLBACK:
                all_buttons.append([
                    {"text": "📧 Support kontaktieren", "callback_data": "cb_support"},
                ])
            elif kb.type == KeyboardType.MODE_CHOICE:
                from dp_connect_bot.services.bot_config import load_bot_config
                if load_bot_config().get("order_enabled", True):
                    all_buttons.append([
                        {"text": "🎫 Tickets kaufen", "callback_data": "mode_order"},
                    ])
                all_buttons.append([
                    {"text": "🔍 Meine Tickets", "callback_data": "mode_tickets"},
                ])
                all_buttons.append([
                    {"text": "🎧 Kundenservice", "callback_data": "mode_support"},
                ])
            elif kb.type == KeyboardType.LOGIN_OPTIONS:
                for btn in kb.buttons:
                    all_buttons.append([
                        {"text": btn.text, "callback_data": btn.callback_data}
                    ])
            else:
                # Generic buttons
                row = []
                for btn in kb.buttons:
                    row.append({"text": btn.text, "callback_data": btn.callback_data})
                    if len(row) >= 2:
                        all_buttons.append(row)
                        row = []
                if row:
                    all_buttons.append(row)

        if not all_buttons:
            return None
        return {"inline_keyboard": all_buttons}

    def _build_quantity_keyboard(self, kb):
        """Build Telegram quantity inline keyboard for ticket quantities."""
        if kb.buttons:
            # Use pre-built buttons from cart_processing
            buttons = []
            row = []
            for btn in kb.buttons:
                price_str = ""
                if kb.price:
                    try:
                        qty = int(btn.text) if btn.text.isdigit() else 1
                        price_num = float(kb.price.replace(",", ".").replace("€", "").strip())
                        total = price_num * qty
                        price_str = f" ({format_price_de(total)})"
                    except (ValueError, TypeError):
                        pass
                label = f"{btn.text}x{price_str}"
                row.append({"text": label, "callback_data": btn.callback_data})
                if len(row) >= 3:
                    buttons.append(row)
                    row = []
            if row:
                buttons.append(row)

            buttons.append([{"text": "✏️ Andere Menge", "callback_data": f"custom_{kb.product_id}"}])
            return {"inline_keyboard": buttons}

        # Fallback: generate quantities 1-5
        buttons = []
        row = []
        for qty in range(1, 6):
            label = f"{qty}x"
            row.append({"text": label, "callback_data": f"tqty_{kb.product_id}_{qty}"})
            if len(row) >= 3:
                buttons.append(row)
                row = []
        if row:
            buttons.append(row)

        buttons.append([{"text": "✏️ Andere Menge", "callback_data": f"custom_{kb.product_id}"}])
        return {"inline_keyboard": buttons}
