"""
Webchat adapter – builds JSON responses for the web chat frontend.
Unlike Telegram/WhatsApp, webchat doesn't push – it returns JSON in the HTTP response.
Adapted for Tixomat event ticketing bot.
"""

from dp_connect_bot.adapters.base import ChannelAdapter
from dp_connect_bot.config import log
from dp_connect_bot.models.response import BotResponse, KeyboardType
from dp_connect_bot.utils.formatting import format_price_de


class WebchatAdapter(ChannelAdapter):
    """Webchat adapter – builds JSON response dicts instead of sending messages."""

    @property
    def channel_name(self) -> str:
        return "web"

    @property
    def chat_id_prefix(self) -> str:
        return "web_"

    def send_response(self, chat_id, response: BotResponse):
        """Not used for webchat – use build_json_response instead."""
        raise NotImplementedError("Webchat uses build_json_response(), not send_response()")

    def send_typing(self, chat_id):
        """Not applicable for webchat (HTTP request/response)."""
        pass

    def build_json_response(self, response: BotResponse):
        """Build JSON dict for the webchat frontend.

        Returns:
            dict with keys: text, keyboards, wc_actions, checkout_url, cart, cart_rich
        """
        result = {
            "text": response.text,
            "keyboards": [],
            "wc_actions": [],
            "checkout_url": response.checkout_url,
            "cart": response.cart,
            "cart_rich": response.cart_rich,
        }

        # Convert keyboards to webchat format
        for kb in response.keyboards:
            result["keyboards"].append(self._keyboard_to_dict(kb))

        # Convert WcAction dataclasses to dicts
        for wca in response.wc_actions:
            result["wc_actions"].append({
                "action": wca.action,
                "product_id": wca.product_id,
                "quantity": wca.quantity,
            })

        return result

    def _keyboard_to_dict(self, kb):
        """Convert a Keyboard to webchat JSON format."""
        if kb.type == KeyboardType.CATEGORIES:
            return {
                "type": "categories",
                "event_id": kb.parent_id,
                "buttons": [
                    {
                        "text": btn.text,
                        "callback_data": btn.callback_data,
                        "sublabel": btn.sublabel,
                    }
                    for btn in kb.buttons
                ],
            }
        elif kb.type == KeyboardType.QUANTITIES:
            return self._build_quantity_data(kb)
        elif kb.type == KeyboardType.EVENTS:
            return {
                "type": "events",
                "buttons": [
                    {
                        "text": btn.text,
                        "callback_data": btn.callback_data,
                        "sublabel": btn.sublabel,
                    }
                    for btn in kb.buttons
                ],
            }
        elif kb.type == KeyboardType.CALLBACK:
            return {
                "type": "callback",
                "buttons": [
                    {"text": "📧 Support kontaktieren", "callback_data": "cb_support"},
                ],
            }
        elif kb.type == KeyboardType.MODE_CHOICE:
            from dp_connect_bot.services.bot_config import load_bot_config
            buttons = []
            if load_bot_config().get("order_enabled", True):
                buttons.append({"text": "🎫 Tickets kaufen", "callback_data": "mode_order"})
            buttons.append({"text": "🔍 Meine Tickets", "callback_data": "mode_tickets"})
            buttons.append({"text": "🎧 Kundenservice", "callback_data": "mode_support"})
            return {"type": "mode_choice", "buttons": buttons}
        elif kb.type == KeyboardType.LOGIN_OPTIONS:
            return {
                "type": "login_options",
                "buttons": [
                    {"text": btn.text, "callback_data": btn.callback_data}
                    for btn in kb.buttons
                ],
            }
        else:
            return {
                "type": kb.type.value,
                "buttons": [
                    {"text": btn.text, "callback_data": btn.callback_data, "sublabel": btn.sublabel}
                    for btn in kb.buttons
                ],
            }

    def _build_quantity_data(self, kb):
        """Build webchat quantity keyboard data for tickets."""
        buttons = []

        if kb.buttons:
            for btn in kb.buttons:
                sublabel = ""
                if kb.price:
                    try:
                        qty = int(btn.text) if btn.text.isdigit() else 1
                        price_num = float(kb.price.replace(",", ".").replace("€", "").strip())
                        total = price_num * qty
                        sublabel = format_price_de(total)
                    except (ValueError, TypeError):
                        pass
                buttons.append({
                    "text": btn.text,
                    "callback_data": btn.callback_data,
                    "sublabel": sublabel,
                })
        else:
            # Fallback: 1-5 tickets
            for qty in range(1, 6):
                buttons.append({
                    "text": str(qty),
                    "callback_data": f"tqty_{kb.product_id}_{qty}",
                    "sublabel": "",
                })

        return {
            "type": "quantities",
            "product_id": kb.product_id,
            "label": kb.label,
            "price": kb.price,
            "buttons": buttons,
        }
