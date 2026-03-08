"""
Fuzzy matching & search – alias resolution, typo correction, search term extraction.
Adapted for Tixomat event ticketing bot.
"""

from dp_connect_bot.config import CONFIRM_ALL, log

try:
    from thefuzz import fuzz
    HAS_FUZZY = True
except ImportError:
    HAS_FUZZY = False
    log.warning("thefuzz nicht installiert – Fuzzy Matching deaktiviert")


# Statische Aliases fuer haeufige Schreibvarianten (Event-Kontext)
ALIASES = {
    "tickets": "ticket",
    "karten": "ticket",
    "karte": "ticket",
    "eintrittskarten": "ticket",
    "eintrittskarte": "ticket",
    "eintritt": "ticket",
    "konzert": "events",
    "konzerte": "events",
    "party": "events",
    "partys": "events",
    "parties": "events",
    "festival": "events",
    "festivals": "events",
    "veranstaltung": "events",
    "veranstaltungen": "events",
    "show": "events",
    "shows": "events",
    "gig": "events",
    "gigs": "events",
    "vip": "vip",
    "stehplatz": "stehplatz",
    "sitzplatz": "sitzplatz",
    "ermäßigt": "ermaessigt",
    "ermaessigt": "ermaessigt",
    "ermässigt": "ermaessigt",
    "rabatt": "ermaessigt",
}


# Vokabular fuer Fuzzy-Korrektur (wird beim Cache-Laden aufgebaut)
_fuzzy_vocab = set()


def _build_fuzzy_vocab():
    """Baut Vokabular aus allen bekannten Woertern auf."""
    global _fuzzy_vocab
    from dp_connect_bot.services.event_cache import cache

    vocab = set()
    for alias, target in ALIASES.items():
        vocab.add(alias)
        for w in target.split():
            vocab.add(w)
    if cache.events:
        for e in cache.events:
            title = e.get("title", "").lower()
            for w in title.split():
                if len(w) >= 3:
                    vocab.add(w)
            loc = e.get("location", "").lower()
            for w in loc.split():
                if len(w) >= 3:
                    vocab.add(w)
            org = e.get("organizer", "").lower()
            for w in org.split():
                if len(w) >= 3:
                    vocab.add(w)
            for cat in e.get("categories", []):
                name = cat.get("name", "").lower()
                for w in name.split():
                    if len(w) >= 3:
                        vocab.add(w)

    stopwords = {"und", "oder", "von", "für", "mit", "ohne", "ich", "mir", "mich",
                 "die", "der", "das", "ein", "eine", "den", "dem", "des",
                 "dann", "noch", "auch", "aber", "mal", "bitte", "gerne",
                 "rein", "dazu", "davon", "alle", "was", "wie", "hab",
                 "zeig", "such", "will", "brauche", "brauch", "möchte",
                 "hätte", "guten", "gute", "guter", "einen", "einer", "einem"}
    _fuzzy_vocab = {w for w in vocab if len(w) >= 3} - stopwords
    log.info(f"Fuzzy-Vocab: {len(_fuzzy_vocab)} Woerter")


def normalize_query(text):
    """Wendet statische Aliases an."""
    text_lower = text.lower().strip()
    for alias in sorted(ALIASES.keys(), key=len, reverse=True):
        if alias in text_lower:
            text_lower = text_lower.replace(alias, ALIASES[alias])
    return text_lower


def fuzzy_correct_text(text):
    """Korrigiert Tippfehler im gesamten Text gegen bekanntes Vokabular."""
    if not HAS_FUZZY or not _fuzzy_vocab:
        return text

    words = text.lower().split()
    corrected = []
    vocab_list = list(_fuzzy_vocab)

    for word in words:
        if len(word) < 3 or word in _fuzzy_vocab:
            corrected.append(word)
            continue
        if word.replace(".", "").replace(",", "").isdigit():
            corrected.append(word)
            continue
        best_score = 0
        best_match = None
        for v in vocab_list:
            if abs(len(word) - len(v)) > 2:
                continue
            score = fuzz.ratio(word, v)
            if score > best_score:
                best_score = score
                best_match = v
        if best_match and 80 <= best_score < 100:
            log.debug(f"Fuzzy-Korrektur: '{word}' -> '{best_match}' (Score: {best_score})")
            corrected.append(best_match)
        else:
            corrected.append(word)

    return " ".join(corrected)


def extract_search_terms(text):
    """Extrahiert Suchbegriffe aus der Nutzer-Nachricht."""
    from dp_connect_bot.services.event_cache import cache

    text_lower = normalize_query(fuzzy_correct_text(text))
    terms = []

    # Event-Signalwoerter (nicht als Suchbegriff nutzen)
    _event_signals = {"events", "event", "ticket", "buchen", "kaufen", "bestellen"}

    # Event-Titel im Text suchen
    for event in cache.events:
        title = event.get("title", "").lower()
        if len(title) >= 4 and title in text_lower:
            terms.append(title)

    # Location im Text suchen
    for loc in cache.locations:
        if len(loc) >= 4 and loc in text_lower:
            if loc not in terms:
                terms.append(loc)

    seen = set()
    unique = []
    for t in terms:
        if t not in seen:
            seen.add(t)
            unique.append(t)

    if not unique:
        # Strip filler/greeting words and use remaining as search
        _filler = {
            "brauche", "brauch", "suche", "such", "möchte", "hätte", "will",
            "bitte", "gerne", "insgesamt", "gesamt", "davon", "was", "wie",
            "kannst", "können", "könntest", "du", "mir", "mich", "denn", "mal",
            "auch", "noch", "dazu", "doch", "einfach", "gibt", "hast", "habt",
            "hab", "habe", "den", "die", "das", "der", "dem", "des", "ein",
            "eine", "einen", "einer", "einem", "nicht", "kein", "keine",
            "keinen", "guten", "gute", "guter", "gutes", "empfehlen",
            "empfehlung", "bestellen", "bestell", "nimm", "gib",
            "zeig", "mein", "meine", "meinen", "meiner", "über", "fuer",
            "für", "von", "bei", "aus", "nach", "jetzt", "gleich", "schnell",
            "wäre", "waere", "gut", "schon", "dann",
            "und", "oder", "bzw", "am", "im", "um",
        }
        _greetings = CONFIRM_ALL | {"hi", "hallo", "hey", "moin", "servus", "na", "yo", "moinsen"}
        _skip = _filler | _greetings | _event_signals

        clean = text_lower.replace(",", " ").replace("?", " ").replace("!", " ").replace(".", " ")
        meaningful = [
            w for w in clean.split()
            if w not in _skip and len(w) >= 3
            and not w.replace(".", "").replace(",", "").isdigit()
        ]
        if meaningful:
            # Versuche als ein zusammenhaengender Suchbegriff
            combined = " ".join(meaningful)
            unique = [combined]

    return unique
