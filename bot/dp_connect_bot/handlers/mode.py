"""
Mode detection & gate – determines order vs support vs ticket-lookup mode.
Adapted for Tixomat event ticketing bot.
"""

from dp_connect_bot.config import BETA_HINT, log
from dp_connect_bot.models.response import BotResponse, Keyboard, KeyboardType
from dp_connect_bot.utils.hints import get_hint


# Event/Ticket-Kauf Signale
PRODUCT_KEYWORDS = {
    "ticket", "tickets", "karte", "karten", "eintritt",
    "event", "events", "veranstaltung", "konzert", "party", "festival",
    "buchen", "kaufen", "bestellen", "bestell",
    "was gibt es", "was gibts", "was läuft", "was laeuft", "kommende events",
    "programm", "termine", "was steht an", "welche events",
    "vip", "stehplatz", "sitzplatz", "kategorie",
}

# Ticket-Suche Signale (Kunde sucht seine gekauften Tickets)
TICKET_LOOKUP_KEYWORDS = {
    "wo ist mein ticket", "wo sind meine tickets", "meine tickets",
    "tickets finden", "ticket finden", "meine karten",
    "ticket nicht erhalten", "tickets nicht erhalten",
    "download", "pdf", "ticket download", "ticket pdf",
    "wo finde ich", "wie lade ich", "nochmal herunterladen",
    "bestätigung", "bestaetigung", "buchungsbestätigung",
    "wo sind meine", "ich finde mein ticket nicht",
    "ticket verloren", "tickets verloren",
}

# Support-Signale
SUPPORT_KEYWORDS = {
    "stornierung", "stornieren", "storno",
    "erstattung", "geld zurück", "geld zurueck", "refund",
    "reklamation", "beschwerde",
    "problem", "hilfe", "support", "kundenservice",
    "mit jemandem sprechen", "mitarbeiter",
    "adresse ändern", "adresse aendern",
    "rechnung", "invoice",
}


def detect_mode(session, text, channel):
    """Smart mode detection. Returns BotResponse if mode gate should block, else None.

    Side effect: sets session["mode"] if detected.
    """
    # Handle "choosing" state: user typed text instead of clicking a button
    if session.get("mode") == "choosing":
        lower = text.strip().lower()
        if any(kw in lower for kw in TICKET_LOOKUP_KEYWORDS):
            session["mode"] = "ticket_lookup"
            session["ticket_lookup_step"] = "ask_email"
        elif any(kw in lower for kw in SUPPORT_KEYWORDS):
            session["mode"] = "support"
            session["support_step"] = None
        else:
            session["mode"] = "order"
        return None  # Let the message pass through to the detected handler

    if session.get("mode") is not None:
        return None

    if text.strip().startswith("/"):
        return None

    lower = text.strip().lower()

    # Detect ticket lookup signals (check first – highest priority)
    if any(kw in lower for kw in TICKET_LOOKUP_KEYWORDS):
        session["mode"] = "ticket_lookup"
        session["ticket_lookup_step"] = "ask_email"
        return None

    # Detect support signals
    if any(kw in lower for kw in SUPPORT_KEYWORDS):
        session["mode"] = "support"
        session["support_step"] = None
        return None

    # Detect product/order signals
    order_signals = any(kw in lower for kw in PRODUCT_KEYWORDS)
    if order_signals:
        session["mode"] = "order"
        return None

    if session.get("message_count", 0) <= 1:
        # First message, no signal → show mode choice
        name = session.get("customer_name", "")
        session["mode"] = "choosing"
        return BotResponse(
            text=f"Hey{' ' + name if name else ''}! 👋\n\nWie kann ich dir helfen?{BETA_HINT}",
            keyboards=[Keyboard(type=KeyboardType.MODE_CHOICE)],
        )

    # Subsequent messages without mode → assume order
    session["mode"] = "order"
    return None


def handle_whatsapp_mode_choice(session, text):
    """Handle WhatsApp text-based mode selection (1, 2 or 3) as fallback."""
    if session.get("mode") != "choosing":
        return None

    stripped = text.strip()
    if stripped == "1":
        session["mode"] = "order"
        voice_hint = get_hint(session, "voice_available")
        return BotResponse(
            text=(
                "🎫 *Ticket-Assistent* aktiv!\n\n"
                "Sag mir einfach fuer welches Event du Tickets brauchst, z.B.:\n"
                "• \"Was gibt es fuer Events?\"\n"
                "• \"2 VIP-Tickets fuer...\"\n\n"
                f"Welches Event interessiert dich? 🚀{voice_hint}"
            )
        )
    elif stripped == "2":
        session["mode"] = "ticket_lookup"
        session["ticket_lookup_step"] = "ask_email"
        return BotResponse(
            text=(
                "🔍 *Meine Tickets finden*\n\n"
                "Klar, ich helfe dir deine Tickets zu finden!\n\n"
                "Was ist deine E-Mail-Adresse, mit der du gebucht hast? ✉️"
            )
        )
    elif stripped == "3":
        session["mode"] = "support"
        session["support_step"] = None
        return BotResponse(
            text=(
                "🎧 *Kundenservice*\n\n"
                "Klar, wie kann ich dir helfen? Beschreib mir einfach dein Anliegen. ✍️"
            )
        )

    return None


def is_human_mode(session):
    """Check if the session is in human takeover mode."""
    return session.get("human_mode", False)
