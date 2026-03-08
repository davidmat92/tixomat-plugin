"""
Command handlers – /start, /reset, /warenkorb, /hilfe.
Adapted for Tixomat event ticketing bot.
"""

from dp_connect_bot.config import BETA_HINT, log
from dp_connect_bot.models.response import BotResponse, Keyboard, KeyboardType, Button
from dp_connect_bot.services.cart_processing import format_cart


def handle_start(session):
    """Handle /start command – reset session, show mode choice."""
    name = session.get("customer_name", "")

    # Reset session but keep identity
    session["conversation"] = []
    session["cart"] = []
    session["status"] = "browsing"
    session["pending_selection"] = None
    session["mode"] = None
    session["hints_shown"] = {}
    session["support_step"] = None
    session["human_mode"] = False
    session["ticket_lookup_step"] = None
    session["ticket_lookup_email"] = None

    return BotResponse(
        text=(
            f"Hey{' ' + name if name else ''}! 👋\n\n"
            f"Willkommen bei *Tixomat*! Wie kann ich dir helfen?{BETA_HINT}"
        ),
        keyboards=[Keyboard(type=KeyboardType.MODE_CHOICE)],
    )


def handle_cart_display(session):
    """Handle /warenkorb command."""
    cart_text = format_cart(session)
    if session.get("cart"):
        cart_text += "\n\nSchreib *fertig* zum Bestellen! 🚀"
    return BotResponse(text=cart_text)


def handle_reset(session):
    """Handle /reset command."""
    session["cart"] = []
    session["conversation"] = []
    return BotResponse(text="Warenkorb und Gespräch zurückgesetzt. Was brauchst du?")


def handle_help():
    """Handle /hilfe command."""
    return BotResponse(
        text=(
            "Befehle:\n\n"
            "/start - Neues Gespräch\n"
            "/warenkorb - Warenkorb anzeigen\n"
            "/reset - Warenkorb leeren\n"
            "/hilfe - Diese Hilfe\n\n"
            "Oder schreib einfach was du suchst! 🎫"
        )
    )
