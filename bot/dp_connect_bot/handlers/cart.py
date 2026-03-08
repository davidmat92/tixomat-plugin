"""
Cart handlers – checkout, cart display, browse events, pending quantity.
Adapted for Tixomat event ticketing bot.
"""

from dp_connect_bot.config import CHECKOUT_WORDS, CART_DISPLAY_WORDS, BROWSE_TRIGGERS, log
from dp_connect_bot.models.response import BotResponse, Keyboard, KeyboardType, Button
from dp_connect_bot.services.cart_processing import format_cart, generate_checkout_url
from dp_connect_bot.services.event_cache import cache, ensure_cache
from dp_connect_bot.services.history import track_event
from dp_connect_bot.utils.formatting import format_price_de, parse_price
from dp_connect_bot.utils.hints import get_hint


def handle_checkout(session, channel):
    """Handle checkout request when cart is not empty."""
    if not session.get("cart"):
        return None

    n = len(session["cart"])
    total = sum(parse_price(i.get("price", "0")) * i.get("quantity", 0) for i in session["cart"])
    url = generate_checkout_url(session["cart"])
    session["status"] = "checkout"
    session["last_order"] = list(session["cart"])

    cart_summary = "\n".join(
        f"  ✅ {i['quantity']}x {i['title']}" for i in session["cart"]
    )

    return BotResponse(
        text=(
            f"🛒 *Deine Buchung ({n} Position{'en' if n > 1 else ''}):*\n\n"
            f"{cart_summary}\n\n"
            f"💰 Gesamt: {format_price_de(total)} (inkl. MwSt.)\n\n"
            f"👉 [Jetzt buchen]({url})\n\n"
            f"Der Link bringt dich direkt zum Checkout! 🎫"
        ),
        checkout_url=url or "",
    )


def handle_cart_view(session):
    """Handle cart view request."""
    if session.get("cart"):
        n = len(session["cart"])
        total = sum(parse_price(i.get("price", "0")) * i.get("quantity", 0) for i in session["cart"])
        cart_summary = "\n".join(
            f"  • {i['quantity']}x {i['title']} – {format_price_de(parse_price(i.get('price', '0')) * i.get('quantity', 0))}"
            for i in session["cart"]
        )
        return BotResponse(
            text=(
                f"🛒 *Dein Warenkorb ({n} Position{'en' if n > 1 else ''}):*\n\n"
                f"{cart_summary}\n\n"
                f"💰 Gesamt: {format_price_de(total)} (inkl. MwSt.)\n\n"
                f"Schreib *fertig* zum Buchen oder sag mir was noch dazu soll! 👍"
            )
        )
    else:
        return BotResponse(text="🛒 Dein Warenkorb ist noch leer. Sag mir welches Event dich interessiert! 🎫")


def handle_browse(session, channel):
    """Handle browse/events overview request."""
    ensure_cache()
    events = cache.get_upcoming()

    if not events:
        return BotResponse(text="Aktuell sind leider keine Events geplant. Schau spaeter nochmal vorbei! 😊")

    if channel in ("telegram", "web"):
        # Buttons fuer Events
        buttons = []
        for e in events[:8]:
            title = e.get("title", "")
            date_fmt = e.get("date_formatted", "")
            event_id = e.get("id", 0)
            status = e.get("status", "available")

            label = title
            if status == "sold_out":
                label += " ❌"
            elif status == "few_tickets":
                label += " ⚠️"

            buttons.append(Button(
                text=f"🎫 {label}",
                callback_data=f"ev_{event_id}",
                sublabel=date_fmt,
            ))

        return BotResponse(
            text=f"🎉 *Kommende Events ({len(events)}):*\n\nWaehle ein Event fuer Details:",
            keyboards=[Keyboard(type=KeyboardType.CATEGORIES, buttons=buttons)],
        )
    else:
        # WhatsApp: Text-based event list
        lines = [f"🎉 *Kommende Events ({len(events)}):*\n"]
        for i, e in enumerate(events[:10], 1):
            title = e.get("title", "")
            date_fmt = e.get("date_formatted", "")
            location = e.get("location", "")
            status = e.get("status", "available")
            price_range = ""
            categories = e.get("categories", [])
            if categories:
                prices = [c.get("price", 0) for c in categories if not c.get("sold_out")]
                if prices:
                    min_p = min(prices)
                    price_range = f" | ab {format_price_de(min_p)}"

            status_tag = ""
            if status == "sold_out":
                status_tag = " ❌ AUSVERKAUFT"
            elif status == "few_tickets":
                status_tag = " ⚠️ Wenige Tickets"

            lines.append(f"*{i}. {title}*{status_tag}")
            lines.append(f"   📅 {date_fmt} | 📍 {location}{price_range}\n")

        lines.append("Schreib den Event-Namen um Tickets zu buchen! 🎫")
        return BotResponse(text="\n".join(lines))


def handle_pending_quantity(session, text):
    """Handle manual quantity input when pending_selection is set."""
    pending = session["pending_selection"]
    quantity = int(text)
    pid = pending["product_id"]
    name = pending["name"]
    event_title = pending.get("event_title", "")
    price = pending.get("price", "")
    label = f"{event_title} – {name}" if event_title else name

    # Add to cart
    existing = next((i for i in session["cart"] if str(i["product_id"]) == pid), None)
    if existing:
        existing["quantity"] += quantity
    else:
        session["cart"].append({
            "product_id": pid, "title": label, "quantity": quantity,
            "price": str(price), "event_id": pending.get("event_id", ""),
        })

    session["conversation"].append({"role": "user", "content": f"{quantity}x {name}"})
    session["conversation"].append({"role": "assistant", "content": f"✅ {quantity}x {name} im Warenkorb!"})
    session["pending_selection"] = None

    n = len(session["cart"])
    cart_total = sum(parse_price(i.get("price", "0")) * i.get("quantity", 0) for i in session["cart"])

    onboarding = get_hint(session, "first_cart_add")

    from dp_connect_bot.models.response import WcAction
    return BotResponse(
        text=(
            f"✅ {quantity}x {label} im Warenkorb!\n"
            f"💰 Gesamt: {format_price_de(cart_total)} (inkl. MwSt.) ({n} Position{'en' if n > 1 else ''})\n\n"
            f"Noch Tickets dazu? Oder schreib *fertig* zum Buchen 🎫"
            f"{onboarding}"
        ),
        wc_actions=[WcAction(action="add", product_id=pid, quantity=quantity)],
    )
