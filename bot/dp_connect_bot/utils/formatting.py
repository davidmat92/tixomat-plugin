"""
Formatting helpers – pure functions, no external dependencies.
"""

import re


def kebab_to_readable(text):
    """Konvertiert 'strawberry-ice-cream' zu 'Strawberry Ice Cream'."""
    if not text:
        return ""
    return text.replace("-", " ").title()


def pipe_to_list(text):
    """Konvertiert 'Berry Kush|Black Amnesia' zu ['Berry Kush', 'Black Amnesia']."""
    if not text:
        return []
    return [s.strip() for s in str(text).split("|") if s.strip()]


def get_variant_display_name(product):
    """Ermittelt den besten Anzeigenamen fuer eine Variante."""
    for field in ["geschmack", "sorte", "farbe", "auswahl"]:
        val = product.get(field)
        if val:
            readable = kebab_to_readable(val)
            readable = re.sub(r'\s*\d+[gG]$', '', readable).strip()
            return readable
    if product.get("flavor"):
        return product["flavor"]
    return product.get("title", "Unbekannt")


def get_variant_type_label(product):
    """Gibt das Label fuer den Varianten-Typ zurueck (Geschmack, Sorte, Farbe, Auswahl)."""
    if product.get("geschmack"):
        return "Geschmack"
    if product.get("sorte"):
        return "Sorte"
    if product.get("farbe"):
        return "Farbe"
    if product.get("auswahl"):
        return "Auswahl"
    return "Variante"


def format_price_de(price):
    """Formatiert Preis deutsch: 4.50 -> 4,50€."""
    try:
        p = float(price)
        return f"{p:.2f}".replace(".", ",") + "€"
    except (ValueError, TypeError):
        return ""


def parse_price(price_str):
    """Parst Preis-Strings: '5,30€', '5.30', '5,30', '5.30€' -> 5.3"""
    if not price_str:
        return 0.0
    try:
        cleaned = str(price_str).replace("€", "").replace(" ", "").strip()
        if "," in cleaned and "." in cleaned:
            cleaned = cleaned.replace(".", "").replace(",", ".")
        elif "," in cleaned:
            cleaned = cleaned.replace(",", ".")
        return float(cleaned)
    except (ValueError, TypeError):
        return 0.0


def stock_label(stock):
    """Wandelt exakten Lagerbestand in Kategorie um. NIE genaue Zahlen an Claude."""
    try:
        s = int(stock)
    except (ValueError, TypeError):
        return ""
    if s > 300:
        return "Vorrätig"
    elif s >= 50:
        return "Begrenzt verfügbar"
    elif s >= 1:
        return "Fast ausverkauft"
    else:
        return "Ausverkauft"
