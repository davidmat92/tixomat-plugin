"""
Tixomat REST API Client – Multi-Tenant
Communicates with the tix-bot-api.php mu-plugin.
"""

import requests
from dp_connect_bot.config import log


def _headers(ctx):
    """Build auth headers from tenant context."""
    return {
        "X-Bot-Secret": ctx.api_secret,
        "Content-Type": "application/json",
    }


def get_events(ctx):
    """Fetch upcoming events for a tenant.

    Args:
        ctx: TenantContext with api_url and api_secret

    Returns:
        list[dict] | None: Events or None on error.
    """
    try:
        resp = requests.get(f"{ctx.api_url}/events", headers=_headers(ctx), timeout=15)
        resp.raise_for_status()
        data = resp.json()
        if data.get("ok"):
            return data.get("events", [])
        log.error(f"[{ctx.tenant_id}] API /events: {data}")
        return None
    except Exception as e:
        log.error(f"[{ctx.tenant_id}] API /events error: {e}")
        return None


def get_event(ctx, event_id):
    """Fetch single event for a tenant.

    Args:
        ctx: TenantContext
        event_id: Event-Post-ID

    Returns:
        dict | None
    """
    try:
        resp = requests.get(f"{ctx.api_url}/event/{event_id}", headers=_headers(ctx), timeout=10)
        resp.raise_for_status()
        data = resp.json()
        if data.get("ok"):
            return data.get("event")
        return None
    except Exception as e:
        log.error(f"[{ctx.tenant_id}] API /event/{event_id} error: {e}")
        return None


def lookup_tickets(ctx, email, verification_type, verification_value):
    """Ticket lookup for a tenant.

    Args:
        ctx: TenantContext
        email: E-Mail-Adresse des Kunden
        verification_type: 'order_id' oder 'last_name'
        verification_value: Bestellnummer oder Nachname

    Returns:
        dict: API-Response (ok, tickets, error, message)
    """
    try:
        resp = requests.post(
            f"{ctx.api_url}/tickets/lookup",
            headers=_headers(ctx),
            json={"email": email, "verification_type": verification_type, "verification_value": verification_value},
            timeout=15,
        )
        if resp.status_code == 429:
            return {"ok": False, "error": "rate_limited", "message": "Zu viele Versuche."}
        resp.raise_for_status()
        return resp.json()
    except Exception as e:
        log.error(f"[{ctx.tenant_id}] API /tickets/lookup error: {e}")
        return {"ok": False, "error": "api_error", "message": "Verbindungsfehler."}


def generate_checkout_url(ctx, items):
    """Generate checkout URL for a tenant.

    Args:
        ctx: TenantContext
        items: Liste von dicts mit product_id und quantity

    Returns:
        dict: API-Response (ok, checkout_url, items, total)
    """
    try:
        resp = requests.post(
            f"{ctx.api_url}/cart/checkout-url",
            headers=_headers(ctx),
            json={"items": items},
            timeout=10,
        )
        resp.raise_for_status()
        return resp.json()
    except Exception as e:
        log.error(f"[{ctx.tenant_id}] API /cart/checkout-url error: {e}")
        return {"ok": False}


def get_my_tickets(ctx, wp_user_id=None, email=None):
    """Tickets eines eingeloggten Users abrufen – keine Verifizierung noetig.

    Args:
        ctx: TenantContext
        wp_user_id: WordPress User-ID (bevorzugt)
        email: Fallback E-Mail

    Returns:
        dict: {ok, count, tickets}
    """
    payload = {}
    if wp_user_id:
        payload["wp_user_id"] = int(wp_user_id)
    if email:
        payload["email"] = email

    try:
        resp = requests.post(
            f"{ctx.api_url}/tickets/my",
            headers=_headers(ctx),
            json=payload,
            timeout=15,
        )
        resp.raise_for_status()
        return resp.json()
    except Exception as e:
        log.error(f"[{ctx.tenant_id}] API /tickets/my error: {e}")
        return {"ok": False, "error": "api_error", "message": "Verbindungsfehler."}


def customer_exists(ctx, email):
    """Check if customer exists for a tenant.

    Args:
        ctx: TenantContext
        email: E-Mail-Adresse

    Returns:
        dict: {ok, exists, first_name}
    """
    try:
        resp = requests.get(
            f"{ctx.api_url}/customer/exists",
            headers=_headers(ctx),
            params={"email": email},
            timeout=10,
        )
        resp.raise_for_status()
        return resp.json()
    except Exception as e:
        log.error(f"[{ctx.tenant_id}] API /customer/exists error: {e}")
        return {"ok": False, "exists": False}
