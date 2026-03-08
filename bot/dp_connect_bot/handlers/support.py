"""
Support flow handler – AI-First support + Ticket-Lookup Flow.
Adapted for Tixomat event ticketing bot.
"""

from dp_connect_bot.config import log
from dp_connect_bot.models.response import BotResponse, Button, Keyboard, KeyboardType
from dp_connect_bot.services.claude_ai import call_claude_support
from dp_connect_bot.services.tixomat_api import customer_exists, lookup_tickets
from dp_connect_bot.services.history import track_event


def handle_support_message(chat_id, text, session, channel):
    """AI-First Support: Claude versucht selbst zu loesen."""
    log.info(f"Support message from {chat_id}: {text[:80]}...")

    response_text, escalated, escalation_info = call_claude_support(session, text)

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

def handle_ticket_lookup(chat_id, text, session, channel):
    """Ticket-Lookup Flow: Hilft Kunden ihre gekauften Tickets zu finden.

    Steps:
        1. ask_email: E-Mail-Adresse abfragen
        2. verify_exists: Pruefen ob Bestellungen existieren
        3. ask_verification: Bestellnr oder Nachname abfragen
        4. verify_order_id / verify_last_name: Verifizierung durchfuehren
        5. show_tickets: Tickets anzeigen
    """
    step = session.get("ticket_lookup_step", "ask_email")

    if step == "ask_email":
        return _lookup_ask_email(session, text)
    elif step == "verify_exists":
        return _lookup_verify_exists(session, text)
    elif step == "ask_verification":
        return _lookup_handle_verification_choice(session, text)
    elif step == "verify_order_id":
        return _lookup_verify_order_id(session, text)
    elif step == "verify_last_name":
        return _lookup_verify_last_name(session, text)

    return BotResponse(text="Etwas ist schiefgelaufen. Schreib /start um neu zu beginnen.")


def _lookup_ask_email(session, text):
    """Step 1: E-Mail abfragen oder verarbeiten."""
    email = text.strip().lower()

    if "@" not in email or "." not in email:
        return BotResponse(
            text=(
                "🔍 *Tickets finden*\n\n"
                "Um deine Tickets zu finden, brauche ich deine E-Mail-Adresse.\n\n"
                "Bitte gib die E-Mail ein, mit der du gebucht hast: ✉️"
            )
        )

    session["ticket_lookup_email"] = email
    session["ticket_lookup_step"] = "verify_exists"

    # Sofort pruefen ob Bestellungen existieren
    return _lookup_verify_exists(session, email)


def _lookup_verify_exists(session, text):
    """Step 2: Pruefen ob E-Mail Bestellungen hat."""
    email = session.get("ticket_lookup_email", "")
    if not email:
        session["ticket_lookup_step"] = "ask_email"
        return BotResponse(text="Bitte gib zuerst deine E-Mail-Adresse ein: ✉️")

    result = customer_exists(email)

    if not result.get("ok"):
        return BotResponse(
            text=(
                "❌ Da ist leider etwas schiefgelaufen. Bitte versuch es spaeter nochmal "
                "oder wende dich an unseren Support."
            ),
            keyboards=[Keyboard(type=KeyboardType.MODE_CHOICE)],
        )

    if not result.get("exists"):
        session["ticket_lookup_step"] = "ask_email"
        return BotResponse(
            text=(
                f"❌ Fuer *{email}* wurden leider keine Bestellungen gefunden.\n\n"
                "Hast du vielleicht mit einer anderen E-Mail-Adresse gebucht?\n"
                "Gib eine andere E-Mail ein oder schreib /start fuer ein neues Gespraech."
            )
        )

    first_name = result.get("first_name", "")
    greeting = f" {first_name}" if first_name else ""

    session["ticket_lookup_step"] = "ask_verification"

    return BotResponse(
        text=(
            f"✅ Hey{greeting}! Bestellungen fuer *{email}* gefunden.\n\n"
            "Zu deiner Sicherheit muss ich dich kurz verifizieren. "
            "Wie moechtest du dich identifizieren?"
        ),
        keyboards=[Keyboard(
            type=KeyboardType.LOGIN_OPTIONS,
            buttons=[
                Button(text="📋 Bestellnummer", callback_data="tl_order_id"),
                Button(text="👤 Nachname", callback_data="tl_last_name"),
            ],
        )],
    )


def _lookup_handle_verification_choice(session, text):
    """Step 3: Verifizierungsmethode waehlen (Text-Fallback)."""
    lower = text.strip().lower()

    if "bestellnummer" in lower or "bestell" in lower or "nummer" in lower or lower == "1":
        session["ticket_lookup_step"] = "verify_order_id"
        return BotResponse(text="Bitte gib deine Bestellnummer ein (z.B. *12345*): 📋")

    if "nachname" in lower or "name" in lower or lower == "2":
        session["ticket_lookup_step"] = "verify_last_name"
        return BotResponse(text="Bitte gib deinen Nachnamen ein: 👤")

    return BotResponse(
        text="Bitte waehle eine Option: Bestellnummer oder Nachname.",
        keyboards=[Keyboard(
            type=KeyboardType.LOGIN_OPTIONS,
            buttons=[
                Button(text="📋 Bestellnummer", callback_data="tl_order_id"),
                Button(text="👤 Nachname", callback_data="tl_last_name"),
            ],
        )],
    )


def _lookup_verify_order_id(session, text):
    """Step 4a: Verifizierung per Bestellnummer."""
    order_id = text.strip().replace("#", "")

    if not order_id:
        return BotResponse(text="Bitte gib deine Bestellnummer ein: 📋")

    email = session.get("ticket_lookup_email", "")
    return _do_ticket_lookup(session, email, "order_id", order_id)


def _lookup_verify_last_name(session, text):
    """Step 4b: Verifizierung per Nachname."""
    last_name = text.strip()

    if not last_name or len(last_name) < 2:
        return BotResponse(text="Bitte gib deinen Nachnamen ein: 👤")

    email = session.get("ticket_lookup_email", "")
    return _do_ticket_lookup(session, email, "last_name", last_name)


def _do_ticket_lookup(session, email, verification_type, verification_value):
    """Fuehrt den eigentlichen Ticket-Lookup durch."""
    result = lookup_tickets(email, verification_type, verification_value)

    if not result.get("ok"):
        error = result.get("error", "")
        message = result.get("message", "")

        if error == "rate_limited":
            session["ticket_lookup_step"] = None
            session["mode"] = None
            return BotResponse(
                text=f"⏳ {message}",
                keyboards=[Keyboard(type=KeyboardType.MODE_CHOICE)],
            )

        if error == "verification_failed":
            if verification_type == "order_id":
                session["ticket_lookup_step"] = "ask_verification"
                return BotResponse(
                    text=(
                        "❌ Die Bestellnummer passt leider nicht zur E-Mail-Adresse.\n\n"
                        "Moechtest du es mit dem Nachnamen versuchen?"
                    ),
                    keyboards=[Keyboard(
                        type=KeyboardType.LOGIN_OPTIONS,
                        buttons=[
                            Button(text="👤 Nachname versuchen", callback_data="tl_last_name"),
                            Button(text="📋 Andere Bestellnr.", callback_data="tl_order_id"),
                        ],
                    )],
                )
            else:
                session["ticket_lookup_step"] = "ask_verification"
                return BotResponse(
                    text=(
                        "❌ Der Nachname passt leider nicht zur E-Mail-Adresse.\n\n"
                        "Moechtest du es mit der Bestellnummer versuchen?"
                    ),
                    keyboards=[Keyboard(
                        type=KeyboardType.LOGIN_OPTIONS,
                        buttons=[
                            Button(text="📋 Bestellnummer versuchen", callback_data="tl_order_id"),
                            Button(text="👤 Anderen Nachnamen", callback_data="tl_last_name"),
                        ],
                    )],
                )

        if error == "no_tickets":
            session["ticket_lookup_step"] = None
            session["mode"] = None
            return BotResponse(
                text=(
                    "✅ Verifizierung erfolgreich, aber es wurden keine aktiven Tickets gefunden.\n\n"
                    "Moegliche Gruende:\n"
                    "• Die Tickets wurden noch nicht erstellt (Bestellung wird noch verarbeitet)\n"
                    "• Die Tickets wurden bereits storniert\n\n"
                    "Bei Fragen wende dich bitte an unseren Support."
                ),
                keyboards=[Keyboard(type=KeyboardType.MODE_CHOICE)],
            )

        return BotResponse(
            text=f"❌ {message or 'Ein Fehler ist aufgetreten. Bitte versuch es spaeter nochmal.'}",
            keyboards=[Keyboard(type=KeyboardType.MODE_CHOICE)],
        )

    # Erfolg! Tickets anzeigen
    tickets = result.get("tickets", [])
    session["ticket_lookup_step"] = None
    session["mode"] = None

    lines = [f"🎫 *Deine Tickets ({len(tickets)}):*\n"]

    # Gruppiert nach Event
    events = {}
    for t in tickets:
        event_name = t.get("event_name", "Unbekannt")
        events.setdefault(event_name, []).append(t)

    for event_name, event_tickets in events.items():
        lines.append(f"\n*{event_name}*")
        for t in event_tickets:
            code = t.get("ticket_code", "")
            category = t.get("category", "")
            download = t.get("download_url", "")
            order_date = t.get("order_date", "")

            cat_str = f" ({category})" if category else ""
            date_str = f" | Bestellt: {order_date}" if order_date else ""

            if download:
                lines.append(f"  🎟️ {code}{cat_str}{date_str}")
                lines.append(f"     📥 [Download]({download})")
            else:
                lines.append(f"  🎟️ {code}{cat_str}{date_str}")

    lines.append("\n✅ Klicke auf die Download-Links um deine Tickets als PDF herunterzuladen.")
    lines.append("Falls ein Download nicht funktioniert, wende dich an unseren Support.")

    track_event("ticket_lookup_success", session.get("chat_id", ""), session.get("channel", ""))

    return BotResponse(
        text="\n".join(lines),
        keyboards=[Keyboard(type=KeyboardType.MODE_CHOICE)],
    )
