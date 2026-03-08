"""
Event context builder – creates the event context string for Claude AI.
Replaces product_context.py for the Tixomat bot.
"""

from dp_connect_bot.services.event_cache import cache, ensure_cache
from dp_connect_bot.services.fuzzy_matching import extract_search_terms
from dp_connect_bot.utils.formatting import format_price_de


def build_event_context(user_message):
    """Baut den Event-Kontext fuer Claude basierend auf der Nutzer-Nachricht."""
    ensure_cache()
    search_terms = extract_search_terms(user_message)
    parts = []

    if not search_terms:
        # Kein spezifischer Suchbegriff -> alle kommenden Events zeigen
        parts.append(get_event_overview())
        return "\n".join(parts)

    for term in search_terms:
        found = cache.search_events(term)

        if found:
            parts.append(f"\n=== EVENTS fuer '{term}' ===")
            for e in found:
                parts.append(format_event(e))
        else:
            parts.append(f"\nKeine Events gefunden fuer '{term}'.")

    return "\n".join(parts)


def format_event(event):
    """Formatiert ein einzelnes Event fuer den Claude-Kontext."""
    lines = []
    event_id = event.get("id", 0)
    title = event.get("title", "")
    date_fmt = event.get("date_formatted", "")
    time_start = event.get("time_start", "")
    time_doors = event.get("time_doors", "")
    location = event.get("location", "")
    address = event.get("address", "")
    organizer = event.get("organizer", "")
    status = event.get("status", "available")
    url = event.get("url", "")

    # Kopfzeile
    status_tag = ""
    if status == "sold_out":
        status_tag = " ❌AUSVERKAUFT"
    elif status == "few_tickets":
        status_tag = " ⚠️WENIGE TICKETS"
    elif status == "cancelled":
        status_tag = " ❌ABGESAGT"
    elif status == "postponed":
        status_tag = " ⏸️VERSCHOBEN"

    lines.append(f"\nEvent: {title} [EVENT:{event_id}]{status_tag}")

    # Details
    date_line = f"  Datum: {date_fmt}"
    if time_doors:
        date_line += f" | Einlass: {time_doors} Uhr"
    if time_start:
        date_line += f" | Beginn: {time_start} Uhr"
    lines.append(date_line)

    if location:
        loc_line = f"  Location: {location}"
        if address:
            loc_line += f" ({address})"
        lines.append(loc_line)

    if organizer:
        lines.append(f"  Veranstalter: {organizer}")

    if url:
        lines.append(f"  URL: {url}")

    # Ticketkategorien
    categories = event.get("categories", [])
    if categories and status not in ("cancelled", "postponed"):
        lines.append(f"\n  Ticketkategorien ({len(categories)}):")
        for cat in categories:
            cat_name = cat.get("name", "Standard")
            price = format_price_de(cat.get("price", 0))
            product_id = cat.get("product_id", 0)
            available = cat.get("quantity_available", 0)
            total = cat.get("quantity_total", 0)
            sold_out = cat.get("sold_out", False)
            desc = cat.get("description", "")

            if sold_out:
                lines.append(f"    - {cat_name} | {price} | AUSVERKAUFT [PRODUCT:{product_id}]")
            else:
                avail_str = f"{available} von {total} verfuegbar" if total > 0 else "verfuegbar"
                lines.append(f"    - {cat_name} | {price} | {avail_str} [PRODUCT:{product_id}]")

            if desc:
                lines.append(f"      Beschreibung: {desc}")

        # Show categories button tag
        lines.append(f"  [SHOW_CATEGORIES:{event_id}]")
    elif status in ("cancelled", "postponed"):
        lines.append(f"\n  Keine Tickets verfuegbar ({status}).")

    return "\n".join(lines)


def get_event_overview():
    """Uebersicht aller kommenden Events."""
    ensure_cache()
    events = cache.get_upcoming()

    if not events:
        return "Aktuell sind keine kommenden Events geplant."

    lines = [f"=== KOMMENDE EVENTS ({len(events)}) ==="]
    for e in events:
        title = e.get("title", "")
        date_fmt = e.get("date_formatted", "")
        location = e.get("location", "")
        event_id = e.get("id", 0)
        status = e.get("status", "available")
        total_available = e.get("total_available", 0)

        # Kompakte Darstellung
        status_tag = ""
        if status == "sold_out":
            status_tag = " | AUSVERKAUFT"
        elif status == "cancelled":
            status_tag = " | ABGESAGT"
        elif status == "few_tickets":
            status_tag = " | WENIGE TICKETS"

        cat_count = len(e.get("categories", []))
        price_range = get_price_range(e)

        line = f"\n  {title} [EVENT:{event_id}]"
        line += f"\n    {date_fmt} | {location}"
        if price_range:
            line += f" | {price_range}"
        if cat_count > 0:
            line += f" | {cat_count} Kategorie{'n' if cat_count > 1 else ''}"
        line += status_tag

        lines.append(line)

    return "\n".join(lines)


def get_price_range(event):
    """Gibt den Preisbereich eines Events zurueck."""
    categories = event.get("categories", [])
    if not categories:
        return ""

    prices = [c.get("price", 0) for c in categories if not c.get("sold_out", False)]
    if not prices:
        return ""

    min_price = min(prices)
    max_price = max(prices)

    if min_price == max_price:
        return format_price_de(min_price)
    return f"ab {format_price_de(min_price)}"
