"""
WhatsApp adapter – renders BotResponse into WhatsApp Cloud API messages.
Adapted for Tixomat event ticketing bot.
"""

import requests

from dp_connect_bot.adapters.base import ChannelAdapter
from dp_connect_bot.config import WHATSAPP_TOKEN, WHATSAPP_PHONE_ID, WHATSAPP_API, log
from dp_connect_bot.models.response import BotResponse, KeyboardType
from dp_connect_bot.utils.formatting import format_price_de


class WhatsAppAdapter(ChannelAdapter):
    @property
    def channel_name(self) -> str:
        return "whatsapp"

    @property
    def chat_id_prefix(self) -> str:
        return "wa_"

    def send_response(self, chat_id, response: BotResponse):
        if response.is_silent:
            return
        if not response.text:
            return

        # Build WhatsApp-specific UI
        buttons = None
        list_menu = None

        for kb in response.keyboards:
            if kb.type == KeyboardType.CATEGORIES:
                # Ticketkategorien als Liste
                list_menu = self._build_category_list(kb)
                break
            elif kb.type == KeyboardType.QUANTITIES:
                # Ticket-Mengen als Liste
                list_menu = self._build_quantity_list(kb)
                break
            elif kb.type == KeyboardType.EVENTS:
                # Events als Liste
                list_menu = self._build_event_list(kb)
                break
            elif kb.type == KeyboardType.CALLBACK:
                buttons = [
                    {"label": "📧 Support", "callback": "cb_support"},
                ]
                break
            elif kb.type == KeyboardType.MODE_CHOICE:
                from dp_connect_bot.services.bot_config import load_bot_config
                buttons = []
                if load_bot_config().get("order_enabled", True):
                    buttons.append({"label": "🎫 Tickets", "callback": "mode_order"})
                buttons.append({"label": "🔍 Meine Tickets", "callback": "mode_tickets"})
                buttons.append({"label": "🎧 Service", "callback": "mode_support"})
                break
            elif kb.type == KeyboardType.LOGIN_OPTIONS:
                buttons = [
                    {"label": btn.text[:20], "callback": btn.callback_data}
                    for btn in kb.buttons[:3]
                ]
                break

        # Clean markdown for WhatsApp (remove unsupported syntax)
        text = self._clean_text(response.text)
        self._send_message(chat_id, text, buttons=buttons, list_menu=list_menu)

    def send_typing(self, chat_id):
        # WhatsApp doesn't support typing indicators via Cloud API
        pass

    def _send_message(self, phone, text, buttons=None, list_menu=None):
        """Send a message via WhatsApp Cloud API."""
        if not WHATSAPP_TOKEN or not WHATSAPP_PHONE_ID:
            log.warning("WhatsApp nicht konfiguriert")
            return

        headers = {"Authorization": f"Bearer {WHATSAPP_TOKEN}", "Content-Type": "application/json"}

        if list_menu:
            payload = {
                "messaging_product": "whatsapp",
                "to": phone,
                "type": "interactive",
                "interactive": {
                    "type": "list",
                    "body": {"text": text[:1024]},
                    "action": {
                        "button": list_menu.get("button_text", "Auswählen")[:20],
                        "sections": list_menu["sections"],
                    },
                },
            }
        elif buttons and len(buttons) <= 3:
            payload = {
                "messaging_product": "whatsapp",
                "to": phone,
                "type": "interactive",
                "interactive": {
                    "type": "button",
                    "body": {"text": text[:1024]},
                    "action": {
                        "buttons": [
                            {"type": "reply", "reply": {"id": b["callback"], "title": b["label"][:20]}}
                            for b in buttons[:3]
                        ],
                    },
                },
            }
        else:
            payload = {
                "messaging_product": "whatsapp",
                "to": phone,
                "type": "text",
                "text": {"body": text[:4096]},
            }

        try:
            resp = requests.post(
                f"{WHATSAPP_API}/{WHATSAPP_PHONE_ID}/messages",
                headers=headers,
                json=payload,
                timeout=10,
            )
            if not resp.ok:
                log.error(f"WhatsApp send error: {resp.text}")
        except Exception as e:
            log.error(f"WhatsApp send error: {e}")

    def _build_category_list(self, kb):
        """Build WhatsApp list menu with ticket categories (max 10 rows)."""
        if not kb.buttons:
            return None

        rows = []
        for btn in kb.buttons[:10]:
            rows.append({
                "id": btn.callback_data,
                "title": btn.text[:24],
                "description": btn.sublabel[:72] if btn.sublabel else "",
            })

        return {
            "button_text": "Kategorie wählen",
            "sections": [{"title": "Ticketkategorien", "rows": rows}],
        }

    def _build_quantity_list(self, kb):
        """Build WhatsApp list menu with ticket quantities (max 10 rows)."""
        if kb.buttons:
            rows = []
            for btn in kb.buttons[:10]:
                desc = ""
                if kb.price:
                    try:
                        qty = int(btn.text) if btn.text.isdigit() else 1
                        price_num = float(kb.price.replace(",", ".").replace("€", "").strip())
                        total = price_num * qty
                        desc = f"= {format_price_de(total)} inkl. MwSt."
                    except (ValueError, TypeError):
                        pass
                rows.append({
                    "id": btn.callback_data,
                    "title": f"{btn.text} Ticket(s)",
                    "description": desc,
                })
            return {
                "button_text": "Menge wählen",
                "sections": [{"title": "Anzahl Tickets", "rows": rows}],
            }

        # Fallback: 1-5 tickets
        rows = [
            {"id": f"tqty_{kb.product_id}_{q}", "title": f"{q} Ticket(s)", "description": ""}
            for q in range(1, 6)
        ]
        return {
            "button_text": "Menge wählen",
            "sections": [{"title": "Anzahl Tickets", "rows": rows}],
        }

    def _build_event_list(self, kb):
        """Build WhatsApp list menu with events (max 10 rows)."""
        if not kb.buttons:
            return None

        rows = []
        for btn in kb.buttons[:10]:
            rows.append({
                "id": btn.callback_data,
                "title": btn.text[:24],
                "description": btn.sublabel[:72] if btn.sublabel else "",
            })

        return {
            "button_text": "Event wählen",
            "sections": [{"title": "Kommende Events", "rows": rows}],
        }

    @staticmethod
    def _clean_text(text):
        """Clean Telegram Markdown for WhatsApp (basic cleanup)."""
        # WhatsApp supports *bold* and _italic_ natively, so most Markdown works
        return text
