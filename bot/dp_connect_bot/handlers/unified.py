"""
Unified message & callback handlers – single code path for ALL channels.
Adapted for Tixomat event ticketing bot.
"""

from dp_connect_bot.config import (
    CONFIRM_ALL, CHECKOUT_WORDS, CART_DISPLAY_WORDS,
    BROWSE_TRIGGERS, log,
)
from dp_connect_bot.models.response import BotResponse, Keyboard, KeyboardType, WcAction
from dp_connect_bot.models.session import session_manager
from dp_connect_bot.services.claude_ai import call_claude
from dp_connect_bot.services.event_context import build_event_context
from dp_connect_bot.services.event_cache import cache
from dp_connect_bot.services.cart_processing import (
    process_cart_actions, format_cart, format_cart_rich, generate_checkout_url,
)
from dp_connect_bot.services.history import (
    archive_session, update_daily_stats, track_event,
)
from dp_connect_bot.utils.formatting import format_price_de, parse_price
from dp_connect_bot.utils.hints import get_hint
from dp_connect_bot.handlers.commands import (
    handle_start, handle_cart_display, handle_reset, handle_help,
)
from dp_connect_bot.handlers.support import (
    handle_support_step, handle_support_message, handle_ticket_lookup,
)
from dp_connect_bot.handlers.cart import (
    handle_checkout, handle_cart_view,
    handle_browse, handle_pending_quantity,
)
from dp_connect_bot.handlers.mode import (
    detect_mode, handle_whatsapp_mode_choice, is_human_mode,
)


def unified_handle_message(chat_id, text, user_info=None, channel="telegram", wc_cart=None):
    """Single entry point for ALL text messages from ALL channels."""
    chat_id = str(chat_id)
    session = session_manager.get(chat_id, archive_callback=archive_session)
    session["channel"] = channel

    # Store user info
    if user_info and not session["customer_name"]:
        name = user_info.get("first_name", "")
        if user_info.get("last_name"):
            name += f" {user_info['last_name']}"
        session["customer_name"] = name.strip()

    if user_info:
        info = session.setdefault("user_info", {})
        if channel == "telegram" and not info.get("tg_username"):
            info.update({
                "tg_username": user_info.get("username", ""),
                "tg_first_name": user_info.get("first_name", ""),
                "tg_last_name": user_info.get("last_name", ""),
                "tg_user_id": user_info.get("id", ""),
            })
        elif channel == "web":
            for key in ("wp_user_id", "wp_email", "wp_name"):
                if user_info.get(key):
                    info[key] = user_info[key]

    session["message_count"] = session.get("message_count", 0) + 1

    # Analytics
    update_daily_stats(channel)
    if session["message_count"] == 1:
        track_event("session_start", chat_id, channel)

    # --- Commands ---
    stripped = text.strip()
    if stripped.startswith("/start"):
        resp = handle_start(session)
        session_manager.save(chat_id, session)
        return resp

    if stripped.startswith("/warenkorb") or stripped.startswith("/cart"):
        resp = handle_cart_display(session)
        session_manager.save(chat_id, session)
        return resp

    if stripped.startswith("/reset"):
        resp = handle_reset(session)
        session_manager.save(chat_id, session)
        return resp

    if stripped.startswith("/hilfe") or stripped.startswith("/help"):
        return handle_help()

    # --- Callback-like strings from WhatsApp/channel button clicks ---
    _CB_PREFIXES = ("mode_", "tcat_", "tqty_", "custom_", "ev_", "tl_", "cb_", "done_")
    if stripped.startswith(_CB_PREFIXES) or stripped == "noop":
        resp = unified_handle_callback(chat_id, stripped, channel=session.get("channel", "telegram"))
        session_manager.save(chat_id, session)
        return resp

    # --- Pending selection (manual quantity input) ---
    pending = session.get("pending_selection")
    if pending and stripped.isdigit():
        resp = handle_pending_quantity(session, stripped)
        session_manager.save(chat_id, session)
        return resp

    # --- Mode gate ---
    mode_response = detect_mode(session, text, channel)
    if mode_response:
        session_manager.save(chat_id, session)
        return mode_response

    # WhatsApp mode choice ("1", "2" or "3" as text fallback)
    wa_mode = handle_whatsapp_mode_choice(session, text)
    if wa_mode:
        session_manager.save(chat_id, session)
        return wa_mode

    # --- Ticket-Lookup Flow ---
    if session.get("mode") == "ticket_lookup":
        resp = handle_ticket_lookup(chat_id, text, session, channel)
        session_manager.save(chat_id, session)
        return resp

    # --- Support flow (legacy step handler – now always returns None) ---
    support_resp = handle_support_step(session, text, channel)
    if support_resp:
        session_manager.save(chat_id, session)
        return support_resp

    # --- AI-First Support ---
    if session.get("mode") == "support" and not session.get("human_mode"):
        resp = handle_support_message(chat_id, text, session, channel)
        session_manager.save(chat_id, session)
        return resp

    # --- Human takeover ---
    if is_human_mode(session):
        lower = text.strip().lower()
        order_intents = {"bestellen", "bestell", "tickets", "events", "event",
                         "buchen", "ticket kaufen", "was gibt es"}
        if lower in order_intents or stripped.startswith("/start"):
            session["human_mode"] = False
            session["mode"] = "order"
            session_manager.save(chat_id, session)
            return BotResponse(text="🎫 Klar! Ticket-Modus ist aktiv. Welches Event interessiert dich?")
        session["conversation"].append({"role": "user", "content": text})
        session_manager.save(chat_id, session)
        return BotResponse(
            text="💬 Deine Nachricht wurde weitergeleitet. Ein Mitarbeiter antwortet dir gleich!\n\nWenn du Tickets kaufen moechtest, schreib einfach /start"
        )

    lower_text = text.strip().lower()

    # --- Checkout shortcut ---
    if lower_text in CHECKOUT_WORDS and session.get("cart"):
        resp = handle_checkout(session, channel)
        if resp:
            track_event("checkout", chat_id, channel)
            session_manager.save(chat_id, session)
            return resp

    # --- Cart display shortcut ---
    if lower_text in CART_DISPLAY_WORDS:
        resp = handle_cart_view(session)
        session_manager.save(chat_id, session)
        return resp

    # --- Browse/events shortcut ---
    if lower_text in BROWSE_TRIGGERS:
        resp = handle_browse(session, channel)
        session_manager.save(chat_id, session)
        return resp

    # --- AI Response ---
    if lower_text in CONFIRM_ALL:
        event_context = ""
    else:
        event_context = build_event_context(text)

    ai_response = call_claude(session, text, event_context, wc_cart=wc_cart)
    clean_text, keyboards, wc_actions = process_cart_actions(session, ai_response)

    session_manager.save(chat_id, session)

    return BotResponse(
        text=clean_text,
        keyboards=keyboards,
        wc_actions=wc_actions,
        cart=session.get("cart", []),
        cart_rich=format_cart_rich(session),
    )


def unified_handle_callback(chat_id, callback_data, channel="telegram"):
    """Single entry point for ALL callback/button clicks from ALL channels."""
    chat_id = str(chat_id)
    session = session_manager.get(chat_id, archive_callback=archive_session)

    if callback_data == "mode_order":
        session["mode"] = "order"
        session["human_mode"] = False
        session["support_step"] = None
        voice_hint = get_hint(session, "voice_available") if channel == "whatsapp" else ""
        session_manager.save(chat_id, session)
        return BotResponse(
            text=(
                "🎫 *Ticket-Assistent* aktiv!\n\n"
                "Sag mir einfach fuer welches Event du Tickets brauchst, z.B.:\n"
                "• \"Was gibt es fuer Events?\"\n"
                "• \"2 VIP-Tickets fuer...\"\n\n"
                f"Welches Event interessiert dich? 🚀{voice_hint}"
            ),
            answer_callback_text="🎫 Ticket-Modus!",
        )

    elif callback_data == "mode_tickets":
        session["mode"] = "ticket_lookup"
        session["ticket_lookup_step"] = "ask_email"
        session_manager.save(chat_id, session)
        return BotResponse(
            text=(
                "🔍 *Meine Tickets finden*\n\n"
                "Klar, ich helfe dir deine Tickets zu finden!\n\n"
                "Was ist deine E-Mail-Adresse, mit der du gebucht hast? ✉️"
            ),
            answer_callback_text="🔍 Tickets finden!",
        )

    elif callback_data == "mode_support":
        session["mode"] = "support"
        session["support_step"] = None
        session_manager.save(chat_id, session)
        return BotResponse(
            text=(
                "🎧 *Kundenservice*\n\n"
                "Klar, wie kann ich dir helfen? Beschreib mir einfach dein Anliegen – "
                "ich kann z.B. Stornierungen nachschauen oder dich an unser Team weiterleiten. ✍️"
            ),
            answer_callback_text="🎧 Kundenservice!",
        )

    # --- Ticket Lookup callbacks ---
    elif callback_data == "tl_order_id":
        session["ticket_lookup_step"] = "verify_order_id"
        session_manager.save(chat_id, session)
        return BotResponse(
            text="Bitte gib deine Bestellnummer ein (z.B. *12345*): 📋",
            answer_callback_text="📋 Bestellnummer",
        )

    elif callback_data == "tl_last_name":
        session["ticket_lookup_step"] = "verify_last_name"
        session_manager.save(chat_id, session)
        return BotResponse(
            text="Bitte gib deinen Nachnamen ein: 👤",
            answer_callback_text="👤 Nachname",
        )

    # --- Event detail callback ---
    elif callback_data.startswith("ev_"):
        event_id = callback_data[3:]
        session["mode"] = "order"
        session_manager.save(chat_id, session)
        event = cache.get_event_by_id(event_id)
        if event:
            return unified_handle_message(chat_id, event.get("title", ""), channel=session.get("channel", "telegram"))
        return BotResponse(text="Event nicht gefunden. Versuch es nochmal!", answer_callback_text="❌")

    # --- Ticket category selection ---
    elif callback_data.startswith("tcat_"):
        return _handle_category_selection(session, chat_id, callback_data)

    # --- Ticket quantity selection ---
    elif callback_data.startswith("tqty_"):
        return _handle_quantity_selection(session, chat_id, callback_data)

    elif callback_data.startswith("custom_"):
        return _handle_custom_quantity(session, chat_id, callback_data)

    elif callback_data == "done_categories":
        return _handle_done_categories(session, chat_id)

    elif callback_data == "noop":
        return BotResponse(is_silent=True, answer_callback_text="")

    elif callback_data.startswith("cb_"):
        return _handle_callback_request(session, chat_id, callback_data)

    return BotResponse(is_silent=True)


def _handle_category_selection(session, chat_id, callback_data):
    """Handle ticket category button click."""
    product_id = callback_data[5:]
    cat, event = cache.get_category_by_product_id(int(product_id))

    if not cat or not event:
        return BotResponse(answer_callback_text="Kategorie nicht gefunden")

    name = cat.get("name", "Ticket")
    price = format_price_de(cat.get("price", 0))
    available = cat.get("quantity_available", 0)
    event_title = event.get("title", "")

    if cat.get("sold_out", False):
        return BotResponse(
            text=f"❌ {name} ist leider ausverkauft!",
            answer_callback_text="❌ Ausverkauft",
        )

    session["pending_selection"] = {
        "product_id": product_id,
        "name": name,
        "event_title": event_title,
        "event_id": str(event.get("id", "")),
        "price": str(cat.get("price", "")),
    }

    avail_hint = f" (noch {available} verfuegbar)" if 0 < available <= 50 else ""

    session_manager.save(chat_id, session)

    return BotResponse(
        text=f"👍 *{name}* – {price} pro Ticket{avail_hint}\n\nWie viele Tickets?",
        keyboards=[Keyboard(
            type=KeyboardType.QUANTITIES,
            product_id=product_id,
            label=name,
            price=str(cat.get("price", "")),
        )],
        answer_callback_text=f"✅ {name}",
    )


def _handle_quantity_selection(session, chat_id, callback_data):
    """Handle quantity button click for tickets."""
    parts = callback_data.split("_")
    if len(parts) != 3:
        return BotResponse(is_silent=True)

    product_id = parts[1]
    quantity = int(parts[2])

    cat, event = cache.get_category_by_product_id(int(product_id))
    if not cat or not event:
        return BotResponse(answer_callback_text="Nicht mehr verfuegbar")

    name = cat.get("name", "Ticket")
    price = cat.get("price", "")
    event_title = event.get("title", "")
    label = f"{event_title} – {name}"

    # Add to cart
    existing = next((i for i in session["cart"] if str(i["product_id"]) == product_id), None)
    if existing:
        existing["quantity"] += quantity
    else:
        session["cart"].append({
            "product_id": product_id,
            "title": label,
            "quantity": quantity,
            "price": str(price),
            "event_id": str(event.get("id", "")),
        })

    session["conversation"].append({"role": "user", "content": f"[Button: {quantity}x {name}]"})
    session["conversation"].append({"role": "assistant", "content": f"✅ {quantity}x {name} im Warenkorb!"})
    session["pending_selection"] = None

    n = len(session["cart"])
    cart_total = sum(parse_price(i.get("price", "0")) * i.get("quantity", 0) for i in session["cart"])

    onboarding = get_hint(session, "first_cart_add")

    wc_actions = [WcAction(action="add", product_id=product_id, quantity=quantity)]

    text = (
        f"✅ *{quantity}x {label}* im Warenkorb!\n"
        f"💰 Gesamt: {format_price_de(cart_total)} (inkl. MwSt.) ({n} Position{'en' if n > 1 else ''})\n\n"
        f"Noch Tickets dazu? Oder schreib *fertig* zum Buchen 🎫"
        f"{onboarding}"
    )

    session_manager.save(chat_id, session)

    return BotResponse(
        text=text,
        wc_actions=wc_actions,
        answer_callback_text=f"✅ {quantity}x hinzugefuegt!",
        cart=session.get("cart", []),
        cart_rich=format_cart_rich(session),
    )


def _handle_custom_quantity(session, chat_id, callback_data):
    """Handle 'custom quantity' button click."""
    product_id = callback_data[7:]
    cat, event = cache.get_category_by_product_id(int(product_id))

    if not cat or not event:
        return BotResponse(answer_callback_text="Produkt nicht gefunden")

    name = cat.get("name", "Ticket")
    event_title = event.get("title", "")
    label = f"{event_title} – {name}"

    session["pending_selection"] = {
        "product_id": product_id,
        "name": name,
        "event_title": event_title,
        "event_id": str(event.get("id", "")),
        "price": str(cat.get("price", "")),
    }
    session_manager.save(chat_id, session)

    return BotResponse(
        text=f"Schreib mir die Anzahl fuer *{label}*:",
        answer_callback_text="Menge eingeben",
    )


def _handle_done_categories(session, chat_id):
    """Handle 'done with category selection' button."""
    n = len(session.get("cart", []))
    if n > 0:
        total = sum(parse_price(i.get("price", "0")) * i.get("quantity", 0) for i in session["cart"])
        msg = (
            f"👍 Alles klar! {n} Position{'en' if n > 1 else ''} im Warenkorb.\n"
            f"💰 Gesamt: {format_price_de(total)} (inkl. MwSt.)\n\n"
            f"Noch was anderes? Oder schreib *fertig* zum Buchen 🎫"
        )
    else:
        msg = "Welches Event interessiert dich? 😊"

    session_manager.save(chat_id, session)
    return BotResponse(text=msg, answer_callback_text="👍")


def _handle_callback_request(session, chat_id, callback_data):
    """Handle callback/contact request buttons."""
    contact_type = callback_data[3:]
    channel = session.get("channel", "telegram")

    if contact_type in ("email", "phone", "support"):
        session["mode"] = "support"
        session["support_step"] = None
        session_manager.save(chat_id, session)
        return BotResponse(
            text=(
                "📧 Klar! Beschreib mir dein Anliegen – ich versuche dir direkt zu helfen. "
                "Falls noetig, leite ich dich an unser Support-Team weiter. ✍️"
            ),
            answer_callback_text="✅ Support aktiv!",
        )

    return BotResponse(is_silent=True)
