"""
Channel-agnostic response models.
Every handler returns BotResponse, every adapter renders it.
Adapted for Tixomat event ticketing bot.
"""

from dataclasses import dataclass, field
from enum import Enum


class KeyboardType(Enum):
    CATEGORIES = "categories"           # Ticketkategorien fuer ein Event
    QUANTITIES = "quantities"           # Mengenauswahl fuer eine Kategorie
    CALLBACK = "callback"               # Generic callback buttons
    MODE_CHOICE = "mode_choice"         # Modus-Auswahl (Kaufen/Tickets/Support)
    EVENTS = "events"                   # Event-Auswahl-Buttons
    LOGIN_OPTIONS = "login_options"     # Verifizierungs-Optionen (Ticket-Lookup)


@dataclass
class Button:
    """A channel-agnostic button."""
    text: str                  # Display label
    callback_data: str         # Callback identifier (e.g. "tcat_123", "tqty_456_2")
    sublabel: str = ""         # Secondary text (e.g. price) -- used by webchat


@dataclass
class Keyboard:
    """A channel-agnostic keyboard."""
    type: KeyboardType
    buttons: list[Button] = field(default_factory=list)
    parent_id: str = ""        # Event-ID fuer CATEGORIES type
    product_id: str = ""       # Product-ID fuer QUANTITIES type
    label: str = ""            # Category label for QUANTITIES
    price: str = ""            # Display price for QUANTITIES


@dataclass
class WcAction:
    """A WooCommerce cart sync action for webchat."""
    action: str                # "add", "remove", "clear"
    product_id: str = ""
    quantity: int = 0


@dataclass
class BotResponse:
    """Channel-agnostic response from unified handlers."""
    text: str = ""
    keyboards: list[Keyboard] = field(default_factory=list)
    wc_actions: list[WcAction] = field(default_factory=list)
    checkout_url: str = ""
    cart: list[dict] = field(default_factory=list)
    cart_rich: dict = field(default_factory=dict)
    is_silent: bool = False            # True = don't send anything
    answer_callback_text: str = ""     # For Telegram answerCallbackQuery
