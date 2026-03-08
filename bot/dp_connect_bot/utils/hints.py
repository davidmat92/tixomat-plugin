"""
Onboarding hint system – shows contextual tips once per session.
Adapted for Tixomat event ticketing bot.
"""


HINTS = {
    "first_cart_add": "\n\n💡 _Tipp: Schreib *fertig* wenn du zur Kasse willst!_",
    "checkout_done": "\n\n💡 _Tipp: Schau auf der Event-Seite vorbei fuer weitere Veranstaltungen!_",
    "voice_available": "\n\n🎤 _Du kannst mir auch eine Sprachnachricht schicken!_",
    "search_hint": "\n\n💡 _Tipp: Sag mir einfach welches Event dich interessiert – z.B. \"Konzert\", \"Party\" oder den Event-Namen_",
    "multi_order": "\n\n💡 _Tipp: Du kannst auch mehrere Tickets auf einmal bestellen!_",
}


def get_hint(session, hint_key):
    """Gibt einen kontextuellen Hint zurueck, aber nur EINMAL pro Session.

    Returns: Hint-Text oder "" wenn bereits gezeigt.
    """
    hints = session.setdefault("hints_shown", {})
    if hints.get(hint_key):
        return ""
    hints[hint_key] = True
    return HINTS.get(hint_key, "")
