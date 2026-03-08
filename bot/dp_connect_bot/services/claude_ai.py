"""
Claude AI service – loads system prompt, calls Anthropic API.
Supports both order mode (simple text) and support mode (tool use).
Adapted for Tixomat event ticketing bot.
"""

import json
import os
import requests

from dp_connect_bot.config import ANTHROPIC_API_KEY, log
from dp_connect_bot.utils.formatting import parse_price


# ============================================================
# PROMPTS
# ============================================================

_PROMPT_DIR = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "prompts")
_PROMPT_PATH = os.path.join(_PROMPT_DIR, "system_prompt.md")
_SUPPORT_PROMPT_PATH = os.path.join(_PROMPT_DIR, "support_prompt.md")

try:
    with open(_PROMPT_PATH, "r", encoding="utf-8") as f:
        SYSTEM_PROMPT = f.read()
except FileNotFoundError:
    log.error(f"System prompt nicht gefunden: {_PROMPT_PATH}")
    SYSTEM_PROMPT = "Du bist ein Ticket-Verkaufsassistent fuer Tixomat."

try:
    with open(_SUPPORT_PROMPT_PATH, "r", encoding="utf-8") as f:
        SUPPORT_PROMPT = f.read()
except FileNotFoundError:
    log.error(f"Support prompt nicht gefunden: {_SUPPORT_PROMPT_PATH}")
    SUPPORT_PROMPT = "Du bist der Kundenservice-Bot von Tixomat."


# ============================================================
# ANTHROPIC API HELPERS
# ============================================================

_API_URL = "https://api.anthropic.com/v1/messages"
_API_HEADERS = {
    "content-type": "application/json",
    "anthropic-version": "2023-06-01",
}


def _api_call(system, messages, tools=None, max_tokens=1024):
    """Low-level Anthropic API call. Returns parsed JSON or None."""
    if not ANTHROPIC_API_KEY:
        return None

    headers = {**_API_HEADERS, "x-api-key": ANTHROPIC_API_KEY}
    payload = {
        "model": "claude-sonnet-4-20250514",
        "max_tokens": max_tokens,
        "system": system,
        "messages": messages,
    }
    if tools:
        payload["tools"] = tools

    resp = requests.post(_API_URL, headers=headers, json=payload, timeout=30)
    resp.raise_for_status()
    return resp.json()


# ============================================================
# ORDER MODE (Ticket-Verkauf)
# ============================================================

def call_claude(session, user_message, event_context="", wc_cart=None):
    """Ruft Claude API auf und gibt die Antwort zurueck.

    Args:
        session: Session dict (with conversation, cart)
        user_message: Nachricht des Kunden
        event_context: Eventdaten-String von build_event_context
        wc_cart: WooCommerce Cart vom Frontend (optional, fuer Webchat)

    Returns:
        AI response text string
    """
    if not ANTHROPIC_API_KEY:
        return "Bot ist noch nicht konfiguriert (API Key fehlt). Bitte Admin kontaktieren."

    messages = list(session["conversation"][-16:])

    # Warenkorb IMMER mitschicken
    if wc_cart and isinstance(wc_cart, list) and len(wc_cart) > 0:
        cart_lines = []
        cart_total = 0.0
        for item in wc_cart:
            p = float(item.get("price", 0))
            qty = int(item.get("quantity", 1))
            sub = p * qty
            cart_total += sub
            name = item.get("name", "?")
            pid = item.get("product_id") or item.get("variation_id") or "?"
            cart_lines.append(f"  - {name} x{qty} à {p:.2f}€ = {sub:.2f}€ [ID:{pid}]")
        cart_str = f"AKTUELLER WARENKORB ({len(wc_cart)} Positionen, Gesamt: {cart_total:.2f}€ inkl. MwSt.):\n" + "\n".join(cart_lines)
    elif session["cart"]:
        cart_lines = []
        cart_total = 0.0
        for item in session["cart"]:
            p = parse_price(item.get("price"))
            sub = p * item["quantity"]
            cart_total += sub
            cart_lines.append(f"  - {item['title']} x{item['quantity']} à {p}€ = {sub:.2f}€ [ID:{item['product_id']}]")
        cart_str = "AKTUELLER WARENKORB (" + str(len(session["cart"])) + " Tickets, Gesamt: " + f"{cart_total:.2f}€ inkl. MwSt.):\n" + "\n".join(cart_lines)
    else:
        cart_str = "WARENKORB: Leer"

    if event_context:
        content = f"[EVENTDATEN]\n{event_context}\n\n[{cart_str}]\n\n[KUNDE]\n{user_message}"
    else:
        content = f"[{cart_str}]\n\n[KUNDE]\n{user_message}"

    messages.append({"role": "user", "content": content})

    try:
        data = _api_call(SYSTEM_PROMPT, messages)
        if not data:
            return "Bot ist noch nicht konfiguriert (API Key fehlt). Bitte Admin kontaktieren."

        ai_text = "".join(b["text"] for b in data.get("content", []) if b.get("type") == "text")

        session["conversation"].append({"role": "user", "content": user_message})
        session["conversation"].append({"role": "assistant", "content": ai_text})
        return ai_text

    except Exception as e:
        log.error(f"Claude API Fehler: {e}")
        return "Da ist gerade was schiefgelaufen. Versuch's nochmal!"


# ============================================================
# SUPPORT MODE (Tool Use)
# ============================================================

SUPPORT_TOOLS = [
    {
        "name": "lookup_order",
        "description": "Sucht eine Bestellung per Bestellnummer oder E-Mail-Adresse. Gibt Bestellstatus, Datum, Artikel und Gesamtbetrag zurueck.",
        "input_schema": {
            "type": "object",
            "properties": {
                "identifier": {
                    "type": "string",
                    "description": "Bestellnummer (z.B. '4521') oder E-Mail-Adresse des Kunden"
                }
            },
            "required": ["identifier"]
        }
    },
    {
        "name": "lookup_tickets_by_email",
        "description": "Sucht Tickets per E-Mail-Adresse mit Verifizierung. Gibt Ticket-Codes und Download-Links zurueck. Benoetigt eine Verifizierung (Bestellnummer oder Nachname). Frage den Kunden IMMER nach der E-Mail UND einer Verifizierung bevor du dieses Tool nutzt.",
        "input_schema": {
            "type": "object",
            "properties": {
                "email": {
                    "type": "string",
                    "description": "E-Mail-Adresse des Kunden"
                },
                "verification_type": {
                    "type": "string",
                    "enum": ["order_id", "last_name"],
                    "description": "Art der Verifizierung: 'order_id' fuer Bestellnummer oder 'last_name' fuer Nachname"
                },
                "verification_value": {
                    "type": "string",
                    "description": "Wert der Verifizierung (die Bestellnummer oder der Nachname)"
                }
            },
            "required": ["email", "verification_type", "verification_value"]
        }
    },
    {
        "name": "check_customer_account",
        "description": "Prueft ob ein Kunden-Account mit dieser E-Mail-Adresse existiert und ob Bestellungen vorliegen.",
        "input_schema": {
            "type": "object",
            "properties": {
                "email": {
                    "type": "string",
                    "description": "E-Mail-Adresse des Kunden"
                }
            },
            "required": ["email"]
        }
    },
    {
        "name": "escalate_to_human",
        "description": "Leitet das Gespraech an einen menschlichen Mitarbeiter weiter. Nutze dieses Tool wenn du das Problem nicht selbst loesen kannst, der Kunde explizit einen Menschen verlangt, oder es um Erstattungen/Stornierungen geht.",
        "input_schema": {
            "type": "object",
            "properties": {
                "reason": {
                    "type": "string",
                    "description": "Kurze Beschreibung warum eskaliert wird"
                },
                "collected_info": {
                    "type": "string",
                    "description": "Alle bisher gesammelten Informationen (Name, E-Mail, Bestellnummer, Problem)"
                }
            },
            "required": ["reason", "collected_info"]
        }
    },
]


def _execute_support_tool(tool_name, tool_input):
    """Execute a support tool and return the result as a dict."""
    from dp_connect_bot.services.woocommerce import wc_client
    from dp_connect_bot.services.tixomat_api import lookup_tickets, customer_exists

    try:
        if tool_name == "lookup_order":
            result = wc_client.lookup_order(tool_input["identifier"])
            if result:
                return {"success": True, "order": result}
            return {"success": False, "error": "Keine Bestellung gefunden mit dieser Angabe."}

        elif tool_name == "lookup_tickets_by_email":
            email = tool_input["email"]
            v_type = tool_input["verification_type"]
            v_value = tool_input["verification_value"]
            result = lookup_tickets(email, v_type, v_value)
            if result.get("ok"):
                tickets = result.get("tickets", [])
                if tickets:
                    return {
                        "success": True,
                        "tickets": tickets,
                        "count": len(tickets),
                        "message": f"{len(tickets)} Ticket(s) gefunden.",
                    }
                return {
                    "success": True,
                    "tickets": [],
                    "count": 0,
                    "message": "Verifizierung erfolgreich, aber keine aktiven Tickets gefunden.",
                }
            error = result.get("error", "")
            message = result.get("message", "Ein Fehler ist aufgetreten.")
            if error == "verification_failed":
                return {"success": False, "error": "Verifizierung fehlgeschlagen. Die Angaben passen nicht zur E-Mail-Adresse."}
            if error == "rate_limited":
                return {"success": False, "error": message}
            return {"success": False, "error": message}

        elif tool_name == "check_customer_account":
            result = customer_exists(tool_input["email"])
            if result.get("ok"):
                return {
                    "success": True,
                    "exists": result.get("exists", False),
                    "first_name": result.get("first_name", ""),
                }
            return {"success": False, "error": "Konnte den Account gerade nicht pruefen. Bitte spaeter erneut versuchen."}

        elif tool_name == "escalate_to_human":
            return {
                "success": True,
                "message": "Eskalation erfolgreich. Ein Mitarbeiter wird sich kuemmern.",
                "reason": tool_input.get("reason", ""),
                "collected_info": tool_input.get("collected_info", ""),
            }

        else:
            return {"success": False, "error": f"Unbekanntes Tool: {tool_name}"}

    except Exception as e:
        log.error(f"Support tool '{tool_name}' error: {e}")
        return {"success": False, "error": "Interner Fehler beim Abrufen der Daten."}


def call_claude_support(session, user_message):
    """Claude mit Tool Use fuer Support-Anfragen.

    Args:
        session: Session dict (with conversation)
        user_message: Nachricht des Kunden

    Returns:
        tuple: (response_text, escalated, escalation_info)
        - response_text: AI response text
        - escalated: bool, True if escalation was triggered
        - escalation_info: dict with reason and collected_info if escalated
    """
    if not ANTHROPIC_API_KEY:
        return "Bot ist noch nicht konfiguriert (API Key fehlt). Bitte Admin kontaktieren.", False, None

    # Build conversation messages (last 20 for support – may need more context)
    messages = list(session["conversation"][-20:])
    messages.append({"role": "user", "content": user_message})

    escalated = False
    escalation_info = None
    max_tool_rounds = 5  # Safety limit

    try:
        # First API call with tools
        data = _api_call(SUPPORT_PROMPT, messages, tools=SUPPORT_TOOLS)
        if not data:
            return "Bot ist noch nicht konfiguriert (API Key fehlt). Bitte Admin kontaktieren.", False, None

        rounds = 0
        while data.get("stop_reason") == "tool_use" and rounds < max_tool_rounds:
            rounds += 1

            # Extract tool calls and execute them
            tool_results = []
            assistant_content = data.get("content", [])

            for block in assistant_content:
                if block.get("type") == "tool_use":
                    tool_name = block["name"]
                    tool_input = block["input"]
                    tool_id = block["id"]

                    log.info(f"Support tool call: {tool_name}({json.dumps(tool_input, ensure_ascii=False)})")
                    result = _execute_support_tool(tool_name, tool_input)
                    log.info(f"Support tool result: {tool_name} -> success={result.get('success')}")

                    # Check for escalation
                    if tool_name == "escalate_to_human" and result.get("success"):
                        escalated = True
                        escalation_info = {
                            "reason": result.get("reason", ""),
                            "collected_info": result.get("collected_info", ""),
                        }

                    tool_results.append({
                        "type": "tool_result",
                        "tool_use_id": tool_id,
                        "content": json.dumps(result, ensure_ascii=False),
                    })

            # Next API call with tool results
            messages.append({"role": "assistant", "content": assistant_content})
            messages.append({"role": "user", "content": tool_results})

            data = _api_call(SUPPORT_PROMPT, messages, tools=SUPPORT_TOOLS)
            if not data:
                break

        # Extract final text response
        ai_text = ""
        for block in data.get("content", []):
            if block.get("type") == "text":
                ai_text += block["text"]

        if not ai_text:
            ai_text = "Da konnte ich gerade nicht richtig drauf zugreifen. Soll ich das an unser Support-Team weiterleiten?"

        # Save to conversation
        session["conversation"].append({"role": "user", "content": user_message})
        session["conversation"].append({"role": "assistant", "content": ai_text})

        return ai_text, escalated, escalation_info

    except Exception as e:
        log.error(f"Claude Support API Fehler: {e}")
        return "Da ist gerade was schiefgelaufen. Versuch's nochmal!", False, None
