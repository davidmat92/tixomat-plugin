"""
WooCommerce REST API Client – lightweight wrapper for support tools.
"""

import re
import requests
from requests.auth import HTTPBasicAuth

from dp_connect_bot.config import WOOCOMMERCE_URL, WC_CONSUMER_KEY, WC_CONSUMER_SECRET, WP_BOT_SECRET, log


class WooCommerceClient:
    """Lightweight WooCommerce REST API v3 Client."""

    def __init__(self):
        self.base_url = f"{WOOCOMMERCE_URL}/wp-json/wc/v3"
        self.auth = HTTPBasicAuth(WC_CONSUMER_KEY, WC_CONSUMER_SECRET)
        self.timeout = 15

    def _get(self, endpoint, params=None):
        """Make authenticated GET request to WC API."""
        url = f"{self.base_url}/{endpoint}"
        try:
            resp = requests.get(url, auth=self.auth, params=params or {}, timeout=self.timeout)
            resp.raise_for_status()
            return resp.json()
        except requests.exceptions.HTTPError as e:
            log.error(f"WooCommerce API HTTP error: {e} - {e.response.text if e.response else ''}")
            return None
        except Exception as e:
            log.error(f"WooCommerce API error: {e}")
            return None

    def lookup_order(self, identifier: str) -> dict | None:
        """Findet Bestellung per Order-ID, E-Mail oder Telefon.

        Returns dict with: order_id, status, status_display, date, items, total,
                          billing, shipping, payment_method
        or None if not found.
        """
        identifier = identifier.strip()

        # Try by order ID (numeric)
        if identifier.isdigit():
            order = self._get(f"orders/{identifier}")
            if order and not order.get("code"):  # WC returns {"code": "...", "message": "..."} on error
                return self._format_order(order)

        # Try by email
        if "@" in identifier:
            orders = self._get("orders", {"search": identifier, "per_page": 5, "orderby": "date", "order": "desc"})
            if orders and isinstance(orders, list) and len(orders) > 0:
                return self._format_order(orders[0])

        # Try by phone (search in billing phone)
        phone = re.sub(r"[^\d+]", "", identifier)
        if len(phone) >= 6:
            # WC search doesn't natively search phone, so search broadly and filter
            orders = self._get("orders", {"search": phone, "per_page": 10, "orderby": "date", "order": "desc"})
            if orders and isinstance(orders, list):
                for order in orders:
                    billing_phone = re.sub(r"[^\d+]", "", order.get("billing", {}).get("phone", ""))
                    if phone in billing_phone or billing_phone in phone:
                        return self._format_order(order)

        return None

    def check_customer(self, email: str) -> dict | None:
        """Prueft ob Kunden-Account existiert.

        Returns dict with: exists, customer_id, name, email, date_created, orders_count
        or None on API error.
        """
        email = email.strip().lower()
        customers = self._get("customers", {"email": email})

        if customers is None:
            return None

        if isinstance(customers, list) and len(customers) > 0:
            c = customers[0]
            return {
                "exists": True,
                "customer_id": c.get("id"),
                "name": f"{c.get('first_name', '')} {c.get('last_name', '')}".strip(),
                "email": c.get("email", ""),
                "date_created": c.get("date_created", ""),
                "orders_count": c.get("orders_count", 0),
                "role": c.get("role", ""),
            }

        return {"exists": False, "email": email}

    def get_order_tracking(self, order_id) -> dict | None:
        """Holt Tracking-Info aus Order-Notes.

        DHL Tracking-Nummern werden oft in Order Notes hinterlegt.
        Returns dict with: carrier, tracking_number, tracking_url
        or None if no tracking found.
        """
        order_id = str(order_id)
        notes = self._get(f"orders/{order_id}/notes")

        if not notes or not isinstance(notes, list):
            return None

        # Search through notes for tracking info
        for note in notes:
            note_text = note.get("note", "")

            # Common patterns for DHL tracking numbers
            # DHL: 00340434161094015063 (20 digits) or JJD... or similar
            dhl_match = re.search(r"(?:tracking|sendungsnummer|trackingnummer|dhl)[:\s]*(\d{12,20})", note_text, re.IGNORECASE)
            if dhl_match:
                tracking_number = dhl_match.group(1)
                return {
                    "carrier": "DHL",
                    "tracking_number": tracking_number,
                    "tracking_url": f"https://www.dhl.de/de/privatkunden/dhl-sendungsverfolgung.html?piececode={tracking_number}",
                }

            # Direct URL pattern
            dhl_url_match = re.search(r"(https?://\S*dhl\S*)", note_text, re.IGNORECASE)
            if dhl_url_match:
                tracking_url = dhl_url_match.group(1)
                # Try to extract tracking number from URL
                num_match = re.search(r"piececode=(\w+)", tracking_url)
                tracking_number = num_match.group(1) if num_match else ""
                return {
                    "carrier": "DHL",
                    "tracking_number": tracking_number,
                    "tracking_url": tracking_url,
                }

            # Generic tracking number pattern (long number in a note mentioning shipping/versand)
            if any(kw in note_text.lower() for kw in ["versand", "versendet", "shipped", "tracking", "sendung"]):
                num_match = re.search(r"\b(\d{12,20})\b", note_text)
                if num_match:
                    tracking_number = num_match.group(1)
                    return {
                        "carrier": "DHL",
                        "tracking_number": tracking_number,
                        "tracking_url": f"https://www.dhl.de/de/privatkunden/dhl-sendungsverfolgung.html?piececode={tracking_number}",
                    }

        return None

    def get_recent_orders(self, email: str, limit: int = 5) -> list[dict]:
        """Letzte Bestellungen eines Kunden.

        Returns list of dicts with: order_id, status, status_display, date, total, items_summary
        """
        email = email.strip().lower()
        orders = self._get("orders", {
            "search": email,
            "per_page": min(limit, 10),
            "orderby": "date",
            "order": "desc",
        })

        if not orders or not isinstance(orders, list):
            return []

        result = []
        for order in orders[:limit]:
            items_summary = ", ".join(
                f"{item.get('quantity', 1)}x {item.get('name', '?')}"
                for item in order.get("line_items", [])[:5]
            )
            if len(order.get("line_items", [])) > 5:
                items_summary += f" (+{len(order['line_items']) - 5} weitere)"

            result.append({
                "order_id": order.get("id"),
                "status": order.get("status", ""),
                "status_display": self._translate_status(order.get("status", "")),
                "date": order.get("date_created", "")[:10],
                "total": order.get("total", "0"),
                "currency": order.get("currency", "EUR"),
                "items_summary": items_summary,
            })

        return result

    @staticmethod
    def get_magic_login_url() -> str:
        """Returns the passwordless magic login URL."""
        return "https://dpconnect.de/anmelden/?action=magic_login"

    @staticmethod
    def get_login_url() -> str:
        """Returns the login URL."""
        return "https://dpconnect.de/anmelden/"

    @staticmethod
    def get_register_url() -> str:
        """Returns the registration URL for new customers."""
        return "https://dpconnect.de/kunde-werden/"

    @staticmethod
    def get_address_edit_url() -> str:
        """Returns the WooCommerce address edit URL."""
        return "https://dpconnect.de/mein-konto/edit-address/"

    def send_new_password(self, email: str) -> dict:
        """Generiert ein neues Passwort und sendet es per CI-Mail an den Kunden.

        Ruft den WordPress REST Endpoint /dp/v1/bot-reset-password auf.
        Returns dict with: success, message (or error)
        """
        email = email.strip().lower()
        url = f"{WOOCOMMERCE_URL}/wp-json/dp/v1/bot-reset-password"

        try:
            resp = requests.post(
                url,
                json={"email": email},
                headers={"X-Bot-Secret": WP_BOT_SECRET, "Content-Type": "application/json"},
                timeout=15,
            )

            data = resp.json()

            if resp.status_code == 200 and data.get("success"):
                return {
                    "success": True,
                    "message": "Neues Passwort wurde generiert und per E-Mail versendet.",
                    "mail_sent": data.get("mail_sent", False),
                }
            elif resp.status_code == 404:
                return {"success": False, "error": "Kein Account mit dieser E-Mail gefunden."}
            elif resp.status_code == 400:
                return {"success": False, "error": data.get("error", "Ungueltige E-Mail-Adresse.")}
            else:
                return {"success": False, "error": data.get("error", "Unbekannter Fehler.")}

        except Exception as e:
            log.error(f"WP bot-reset-password error: {e}")
            return {"success": False, "error": "Konnte den Password-Reset gerade nicht durchfuehren."}

    def _format_order(self, order) -> dict:
        """Format a WC order into a clean dict for Claude."""
        items = []
        for item in order.get("line_items", []):
            items.append({
                "name": item.get("name", ""),
                "quantity": item.get("quantity", 1),
                "total": item.get("total", "0"),
            })

        billing = order.get("billing", {})
        shipping = order.get("shipping", {})

        return {
            "order_id": order.get("id"),
            "status": order.get("status", ""),
            "status_display": self._translate_status(order.get("status", "")),
            "date": order.get("date_created", "")[:10],
            "items": items,
            "total": order.get("total", "0"),
            "currency": order.get("currency", "EUR"),
            "billing": {
                "name": f"{billing.get('first_name', '')} {billing.get('last_name', '')}".strip(),
                "email": billing.get("email", ""),
                "phone": billing.get("phone", ""),
            },
            "shipping": {
                "name": f"{shipping.get('first_name', '')} {shipping.get('last_name', '')}".strip(),
                "address": f"{shipping.get('address_1', '')} {shipping.get('address_2', '')}".strip(),
                "city": shipping.get("city", ""),
                "postcode": shipping.get("postcode", ""),
            },
            "payment_method": order.get("payment_method_title", ""),
            "customer_note": order.get("customer_note", ""),
        }

    @staticmethod
    def _translate_status(status: str) -> str:
        """Translate WooCommerce order status to German."""
        status_map = {
            "pending": "Ausstehende Zahlung",
            "processing": "In Bearbeitung",
            "on-hold": "Wartend",
            "completed": "Abgeschlossen",
            "cancelled": "Storniert",
            "refunded": "Erstattet",
            "failed": "Fehlgeschlagen",
            "trash": "Geloescht",
        }
        return status_map.get(status, status)


# Singleton instance
wc_client = WooCommerceClient()
