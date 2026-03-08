"""
Cart processing – parses cart_actions and keyboard triggers from Claude responses.
Adapted for Tixomat event ticketing bot.
"""

import json
import re

from dp_connect_bot.config import WOOCOMMERCE_URL, log
from dp_connect_bot.models.response import Button, Keyboard, KeyboardType, WcAction
from dp_connect_bot.services.event_cache import cache
from dp_connect_bot.services.history import track_event
from dp_connect_bot.utils.formatting import format_price_de, parse_price


def process_cart_actions(session, ai_response):
    """Verarbeitet cart_actions UND Keyboard-Trigger aus Claude's Antwort.

    Returns: (clean_text, keyboards: list[Keyboard], wc_actions: list[WcAction])
    """
    pattern = r"```cart_action\n(.*?)\n```"
    matches = re.findall(pattern, ai_response, re.DOTALL)
    clean = re.sub(r"\s*```cart_action\n.*?\n```\s*", "", ai_response, flags=re.DOTALL).strip()

    keyboards = []
    wc_actions = []

    # Keyboard-Trigger extrahieren
    category_matches = re.findall(r"\[SHOW_CATEGORIES:(\d+)\]", clean)
    quantity_matches = re.findall(r"\[SHOW_QUANTITY:(\d+)\]", clean)
    has_callback_request = "[REQUEST_CALLBACK]" in clean

    # Tags aus dem Text entfernen
    clean = re.sub(r"\s*\[SHOW_CATEGORIES:\d+\]\s*", "", clean).strip()
    clean = re.sub(r"\s*\[SHOW_QUANTITY:\d+\]\s*", "", clean).strip()
    clean = re.sub(r"\s*\[REQUEST_CALLBACK\]\s*", "", clean).strip()

    for event_id in category_matches:
        kb = build_category_keyboard(event_id)
        if kb:
            keyboards.append(kb)
    for product_id in quantity_matches:
        keyboards.append(build_quantity_keyboard(product_id))

    # Cart Actions verarbeiten
    for match in matches:
        try:
            data = json.loads(match)
            action = data.get("action")

            if action == "add":
                pid = str(data["product_id"])
                qty = data.get("quantity", 1)

                # Verfuegbarkeit pruefen
                cat, event = cache.get_category_by_product_id(int(pid))
                if not cat:
                    clean += f"\n\n⚠️ Dieses Ticket ist leider nicht mehr verfuegbar."
                    continue
                if cat.get("sold_out", False):
                    clean += f"\n\n⚠️ {cat.get('name', 'Diese Kategorie')} ist leider ausverkauft."
                    continue

                available = cat.get("quantity_available", 0)
                if available > 0 and qty > available:
                    qty = available
                    clean += f"\n\n⚠️ Nur noch {available} Tickets verfuegbar – ich nehme {qty}."

                existing = next((i for i in session["cart"] if str(i["product_id"]) == pid), None)
                if existing:
                    existing["quantity"] += qty
                else:
                    event_title = event.get("title", "") if event else ""
                    cat_name = cat.get("name", "") if cat else ""
                    title = f"{event_title} – {cat_name}" if cat_name else event_title

                    session["cart"].append({
                        "product_id": pid,
                        "title": title,
                        "quantity": qty,
                        "price": str(data.get("price", cat.get("price", ""))),
                        "event_id": str(data.get("event_id", event.get("id", "") if event else "")),
                    })
                n = len(session["cart"])
                clean += f"\n\n✅ Im Warenkorb! ({n} Position{'en' if n > 1 else ''})"
                wc_actions.append(WcAction(action="add", product_id=pid, quantity=qty))

            elif action == "remove":
                pid = str(data["product_id"])
                session["cart"] = [i for i in session["cart"] if str(i["product_id"]) != pid]
                wc_actions.append(WcAction(action="remove", product_id=pid))

            elif action == "clear":
                session["cart"] = []
                wc_actions.append(WcAction(action="clear"))

            elif action == "show_cart":
                clean += "\n\n" + format_cart(session)

            elif action == "checkout":
                if session["cart"]:
                    session["status"] = "checkout"
                    session["last_order"] = [dict(i) for i in session["cart"]]
                    url = generate_checkout_url(session["cart"])
                    clean += "\n\n" + format_cart(session)
                    if url:
                        clean += "\n\nDirekt zum Checkout:\n" + url
                else:
                    clean += "\n\nDein Warenkorb ist noch leer."

        except (json.JSONDecodeError, KeyError) as e:
            log.error(f"Cart action error: {e}")

    # Callback-Request
    if has_callback_request:
        keyboards.append(Keyboard(type=KeyboardType.CALLBACK))
        track_event("callback_offered", session.get("chat_id", ""), session.get("channel", ""))

    return clean, keyboards, wc_actions


def build_category_keyboard(event_id):
    """Baut ein Kategorie-Keyboard fuer ein Event."""
    event = cache.get_event_by_id(event_id)
    if not event:
        return None

    categories = event.get("categories", [])
    if not categories:
        return None

    buttons = []
    for cat in categories:
        if cat.get("sold_out", False):
            continue
        name = cat.get("name", "Standard")
        price = format_price_de(cat.get("price", 0))
        product_id = cat.get("product_id", 0)
        available = cat.get("quantity_available", 0)

        sublabel = price
        if 0 < available <= 20:
            sublabel += f" (noch {available})"

        buttons.append(Button(
            text=name,
            callback_data=f"tcat_{product_id}",
            sublabel=sublabel,
        ))

    if not buttons:
        return None

    return Keyboard(
        type=KeyboardType.CATEGORIES,
        buttons=buttons,
        parent_id=str(event_id),
    )


def build_quantity_keyboard(product_id):
    """Baut ein Mengen-Keyboard fuer ein Ticket-Produkt."""
    cat, event = cache.get_category_by_product_id(int(product_id))
    if not cat:
        return Keyboard(type=KeyboardType.QUANTITIES, product_id=str(product_id))

    name = cat.get("name", "Ticket")
    price = format_price_de(cat.get("price", 0))
    available = cat.get("quantity_available", 0)

    # Ticket-Mengen: 1-5 (Tickets werden einzeln verkauft)
    max_qty = min(10, available) if available > 0 else 10
    quantities = [q for q in [1, 2, 3, 4, 5] if q <= max_qty]

    buttons = [
        Button(text=str(q), callback_data=f"tqty_{product_id}_{q}")
        for q in quantities
    ]

    return Keyboard(
        type=KeyboardType.QUANTITIES,
        buttons=buttons,
        product_id=str(product_id),
        label=name,
        price=price,
    )


def format_cart(session):
    """Formatiert den Warenkorb als Text."""
    if not session["cart"]:
        return "Warenkorb ist leer."
    lines = ["🛒 Dein Warenkorb:\n"]
    total = 0.0
    for item in session["cart"]:
        price = parse_price(item.get("price"))
        subtotal = price * item["quantity"]
        total += subtotal
        line = f"• {item['quantity']}x {item['title']}"
        if price:
            line += f" - {format_price_de(subtotal)}"
        lines.append(line)
    lines.append(f"\nGesamt: {format_price_de(total)} (inkl. MwSt.)")
    return "\n".join(lines)


def format_cart_rich(session):
    """Reichhaltige Warenkorb-Daten fuer Web-Channel."""
    if not session["cart"]:
        return {"items": [], "total": 0, "total_formatted": "0,00€"}
    items = []
    total = 0.0
    for item in session["cart"]:
        price = parse_price(item.get("price"))
        subtotal = price * item["quantity"]
        total += subtotal
        items.append({
            "product_id": item["product_id"],
            "title": item["title"],
            "quantity": item["quantity"],
            "price": price,
            "price_formatted": format_price_de(price),
            "subtotal": subtotal,
            "subtotal_formatted": format_price_de(subtotal),
            "image_url": "",
        })
    return {
        "items": items,
        "total": total,
        "total_formatted": format_price_de(total),
    }


def generate_checkout_url(cart):
    """Generiert den WooCommerce Checkout URL."""
    if not cart:
        return None
    base = WOOCOMMERCE_URL.rstrip("/")
    if len(cart) == 1:
        item = cart[0]
        return base + "/checkout/?add-to-cart=" + str(item["product_id"]) + "&quantity=" + str(item["quantity"])
    items = "|".join(str(i["product_id"]) + ":" + str(i["quantity"]) for i in cart)
    return base + "/?tixbot_cart=" + items
