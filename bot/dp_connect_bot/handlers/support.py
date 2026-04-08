"""
Support flow handler – AI-First support + Ticket-Lookup Flow.
Multi-tenant: accepts TenantContext (ctx) parameter.
"""

from dp_connect_bot.config import log
from dp_connect_bot.models.response import BotResponse, Button, Keyboard, KeyboardType
from dp_connect_bot.services.claude_ai import call_claude_support
from dp_connect_bot.services.tixomat_api import customer_exists, lookup_tickets, get_my_tickets
from dp_connect_bot.services.history import track_event


def handle_support_message(chat_id, text, session, channel, ctx=None):
    """AI-First Support: Claude versucht selbst zu loesen."""
    log.info(f"Support message from {chat_id}: {text[:80]}...")

    response_text, escalated, escalation_info = call_claude_support(session, text, ctx=ctx)

    if escalated and escalation_info:
        log.info(f"Support escalated for {chat_id}: {escalation_info.get('reason', '?')}")
        session["human_mode"] = True
        ticket_info = (
            f"[SUPPORT-ESKALATION]\n"
            f"Grund: {escalation_info.get('reason', 'Nicht angegeben')}\n"
            f"Infos: {escalation_info.get('collected_info', 'Keine')}"
        )
        session["conversation"].append({"role": "assistant", "content": ticket_info})
        track_event("support_escalated", chat_id, channel, escalation_info.get("reason", ""))

        return BotResponse(
            text=response_text,
            keyboards=[Keyboard(type=KeyboardType.MODE_CHOICE)],
        )

    return BotResponse(text=response_text)


def handle_support_step(session, text, channel):
    """Legacy support step handler – kept for backward compatibility."""
    return None


# ============================================================
# TICKET-LOOKUP FLOW (structured, multi-step)
# ============================================================

def handle_ticket_lookup(chat_id, text, session, channel, ctx=None):
    """Ticket-Lookup Flow: Hilft Kunden ihre gekauften Tickets zu finden.

    Eingeloggte User: Sofort Tickets abrufen (kein Email/Verifizierung noetig).
    Nicht eingeloggte User: Email fragen, dann direkt Tickets abrufen.
    """
    step = session.get("ticket_lookup_step", "init")

    # ── Eingeloggter User: Sofort Tickets abrufen ──
    if step == "init":
        user_info = session.get("user_info", {})
        if user_info.get("wp_user_id") or user_info.get("wp_email"):
            return _lookup_logged_in(session, ctx=ctx)
        # Nicht eingeloggt: nach Email fragen
        session["ticket_lookup_step"] = "ask_email"
        return _lookup_ask_email(session, text, ctx=ctx)

    if step == "ask_email":
        return _lookup_ask_email(session, text, ctx=ctx)
    elif step == "ask_email_retry":
        return _lookup_ask_email_retry(session, text, ctx=ctx)

    return BotResponse(text="Etwas ist schiefgelaufen. Schreib /start um neu zu beginnen.")


def _lookup_logged_in(session, ctx=None):
    """Eingeloggter User: Sofort Tickets abrufen."""
    user_info = session.get("user_info", {})
    wp_user_id = user_info.get("wp_user_id")
    wp_email = user_info.get("wp_email")
    name = user_info.get("wp_display_name", "")

    result = get_my_tickets(ctx, wp_user_id=wp_user_id, email=wp_email) if ctx else {"ok": False}

    if not result.get("ok"):
        session["ticket_lookup_step"] = None
        session["mode"] = None
        return BotResponse(
            text="❌ Da ist leider etwas schiefgelaufen. Bitte versuch es spaeter nochmal.",
            keyboards=[Keyboard(type=KeyboardType.MODE_CHOICE)],
        )

    tickets = result.get("tickets", [])
    session["ticket_lookup_step"] = None
    session["mode"] = None

    if not tickets:
        greeting = f" {name}" if name else ""
        return BotResponse(
            text=f"Hey{greeting}! Ich konnte leider keine aktiven Tickets fuer dich finden.",
            keyboards=[Keyboard(type=KeyboardType.MODE_CHOICE)],
        )

    track_event("ticket_lookup_success", session.get("chat_id", ""), session.get("channel", ""))
    return _format_tickets_response(tickets, name)


def _lookup_ask_email(session, text, ctx=None):
    """Nicht eingeloggt: E-Mail abfragen oder verarbeiten."""
    email = text.strip().lower()

    if "@" not in email or "." not in email:
        return BotResponse(
            text=(
                "🔍 *Tickets finden*\n\n"
                "Um deine Tickets zu finden, brauche ich deine E-Mail-Adresse.\n\n"
                "Bitte gib die E-Mail ein, mit der du gebucht hast: ✉️"
            )
        )

    # E-Mail eingegeben -> direkt Tickets abrufen
    return _lookup_by_email(session, email, ctx=ctx)


def _lookup_ask_email_retry(session, text, ctx=None):
    """Erneuter Versuch mit anderer E-Mail."""
    email = text.strip().lower()

    if "@" not in email or "." not in email:
        return BotResponse(text="Bitte gib eine gueltige E-Mail-Adresse ein: ✉️")

    return _lookup_by_email(session, email, ctx=ctx)


def _lookup_by_email(session, email, ctx=None):
    """Tickets per E-Mail abrufen (kein Verifizierung)."""
    result = get_my_tickets(ctx, email=email) if ctx else {"ok": False}

    if not result.get("ok"):
        session["ticket_lookup_step"] = None
        session["mode"] = None
        return BotResponse(
            text="❌ Da ist leider etwas schiefgelaufen. Bitte versuch es spaeter nochmal.",
            keyboards=[Keyboard(type=KeyboardType.MODE_CHOICE)],
        )

    tickets = result.get("tickets", [])

    if not tickets:
        session["ticket_lookup_step"] = "ask_email_retry"
        return BotResponse(
            text=(
                f"❌ Fuer *{email}* wurden leider keine Tickets gefunden.\n\n"
                "Hast du vielleicht mit einer anderen E-Mail-Adresse gebucht?\n"
                "Gib eine andere E-Mail ein oder schreib /start fuer ein neues Gespraech."
            )
        )

    session["ticket_lookup_step"] = None
    session["mode"] = None

    track_event("ticket_lookup_success", session.get("chat_id", ""), session.get("channel", ""))
    return _format_tickets_response(tickets)


def _format_tickets_response(tickets, name=""):
    """Formatiert Ticket-Ergebnisse als BotResponse."""
    greeting = f" Hey {name}!" if name else ""
    lines = [f"🎫{greeting} *Deine Tickets ({len(tickets)}):*\n"]

    # Gruppiert nach Event
    events = {}
    for t in tickets:
        event_name = t.get("event_name", "Unbekannt")
        events.setdefault(event_name, []).append(t)

    for event_name, event_tickets in events.items():
        event_date = event_tickets[0].get("event_date", "")
        date_str = f" – {event_date}" if event_date else ""
        lines.append(f"\n*{event_name}*{date_str}")
        for t in event_tickets:
            code = t.get("ticket_code", "")
            category = t.get("category", "")
            download = t.get("download_url", "")

            cat_str = f" ({category})" if category else ""

            if download:
                lines.append(f"  🎟️ {code}{cat_str}")
                lines.append(f"     📥 [Download]({download})")
            else:
                lines.append(f"  🎟️ {code}{cat_str}")

    lines.append("\n✅ Klicke auf die Download-Links um deine Tickets als PDF herunterzuladen.")

    return BotResponse(
        text="\n".join(lines),
        keyboards=[Keyboard(type=KeyboardType.MODE_CHOICE)],
    )
