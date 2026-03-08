"""
Tixomat REST API Client – communicates with the tix-bot-api.php mu-plugin.
"""

import requests
from dp_connect_bot.config import TIX_BOT_API_URL, WP_BOT_SECRET, log


def _headers():
    return {
        "X-Bot-Secret": WP_BOT_SECRET,
        "Content-Type": "application/json",
    }


def get_events():
    """Holt alle kommenden Events mit Ticketkategorien.

    Returns:
        list[dict] | None: Events oder None bei Fehler.
    """
    try:
        resp = requests.get(
            f"{TIX_BOT_API_URL}/events",
            headers=_headers(),
            timeout=15,
        )
        resp.raise_for_status()
        data = resp.json()
        if data.get("ok"):
            return data.get("events", [])
        log.error(f"Tixomat API /events: {data}")
        return None
    except Exception as e:
        log.error(f"Tixomat API /events Fehler: {e}")
        return None


def get_event(event_id):
    """Holt ein einzelnes Event.

    Args:
        event_id: Event-Post-ID

    Returns:
        dict | None
    """
    try:
        resp = requests.get(
            f"{TIX_BOT_API_URL}/event/{event_id}",
            headers=_headers(),
            timeout=10,
        )
        resp.raise_for_status()
        data = resp.json()
        if data.get("ok"):
            return data.get("event")
        return None
    except Exception as e:
        log.error(f"Tixomat API /event/{event_id} Fehler: {e}")
        return None


def lookup_tickets(email, verification_type, verification_value):
    """Ticket-Suche mit Verifizierung.

    Args:
        email: E-Mail-Adresse des Kunden
        verification_type: 'order_id' oder 'last_name'
        verification_value: Bestellnummer oder Nachname

    Returns:
        dict: API-Response (ok, tickets, error, message)
    """
    try:
        resp = requests.post(
            f"{TIX_BOT_API_URL}/tickets/lookup",
            headers=_headers(),
            json={
                "email": email,
                "verification_type": verification_type,
                "verification_value": verification_value,
            },
            timeout=15,
        )
        if resp.status_code == 429:
            return {
                "ok": False,
                "error": "rate_limited",
                "message": "Zu viele Versuche. Bitte in 15 Minuten erneut probieren.",
            }
        resp.raise_for_status()
        return resp.json()
    except Exception as e:
        log.error(f"Tixomat API /tickets/lookup Fehler: {e}")
        return {"ok": False, "error": "api_error", "message": "Verbindungsfehler."}


def generate_checkout_url(items):
    """Generiert Checkout-URL fuer Ticket-Kauf.

    Args:
        items: Liste von dicts mit product_id und quantity

    Returns:
        dict: API-Response (ok, checkout_url, items, total)
    """
    try:
        resp = requests.post(
            f"{TIX_BOT_API_URL}/cart/checkout-url",
            headers=_headers(),
            json={"items": items},
            timeout=10,
        )
        resp.raise_for_status()
        return resp.json()
    except Exception as e:
        log.error(f"Tixomat API /cart/checkout-url Fehler: {e}")
        return {"ok": False}


def customer_exists(email):
    """Prueft ob Bestellungen fuer eine E-Mail-Adresse existieren.

    Args:
        email: E-Mail-Adresse

    Returns:
        dict: {ok, exists, first_name}
    """
    try:
        resp = requests.get(
            f"{TIX_BOT_API_URL}/customer/exists",
            headers=_headers(),
            params={"email": email},
            timeout=10,
        )
        resp.raise_for_status()
        return resp.json()
    except Exception as e:
        log.error(f"Tixomat API /customer/exists Fehler: {e}")
        return {"ok": False, "exists": False}
