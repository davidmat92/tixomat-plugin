"""
Event Cache – loads and indexes events from the Tixomat REST API.
Replaces product_cache.py for the Tixomat bot.
"""

from datetime import datetime
from threading import Lock

from dp_connect_bot.config import CACHE_REFRESH_MINUTES, log
from dp_connect_bot.services.tixomat_api import get_events

# Callback for _build_fuzzy_vocab – set by fuzzy_matching module to avoid circular import
_on_cache_loaded = None


def set_on_cache_loaded(callback):
    """Register a callback that is called after cache.load() finishes."""
    global _on_cache_loaded
    _on_cache_loaded = callback


class EventCache:
    def __init__(self):
        self.events = []          # Alle kommenden Events
        self.event_names = set()  # Dynamisch aus Daten
        self.locations = set()
        self.last_loaded = None
        self.lock = Lock()

    def needs_refresh(self):
        if not self.last_loaded:
            return True
        return (datetime.now() - self.last_loaded).total_seconds() / 60 > CACHE_REFRESH_MINUTES

    def load(self):
        log.info("Lade Events von Tixomat API...")
        events = get_events()

        if events is None:
            log.error("Event-Cache: Konnte Events nicht laden.")
            return

        with self.lock:
            self.events = events
            self._build_indices()
            self.last_loaded = datetime.now()

        log.info(f"Geladen: {len(self.events)} kommende Events")
        if _on_cache_loaded:
            _on_cache_loaded()

    def _build_indices(self):
        """Baut Event-Name und Location-Index aus den echten Daten."""
        self.event_names = set()
        self.locations = set()
        for e in self.events:
            title = e.get("title", "")
            if title:
                self.event_names.add(title.lower())
                # Einzelne Woerter des Titels
                for w in title.lower().split():
                    if len(w) >= 3:
                        self.event_names.add(w)
            loc = e.get("location", "")
            if loc:
                self.locations.add(loc.lower())

    def get_upcoming(self):
        """Alle kommenden Events."""
        return list(self.events)

    def search_events(self, query, max_results=10):
        """Sucht Events per Text-Query."""
        query_lower = query.lower().strip()
        query_parts = query_lower.split()
        scored = []

        for e in self.events:
            searchable = " ".join([
                e.get("title", "").lower(),
                e.get("location", "").lower(),
                e.get("organizer", "").lower(),
                e.get("excerpt", "").lower(),
                e.get("date_formatted", "").lower(),
                " ".join(c.get("name", "").lower() for c in e.get("categories", [])),
            ])

            # Exaktes Matching
            if all(part in searchable for part in query_parts):
                score = sum(1 for part in query_parts if part in e.get("title", "").lower())
                if query_lower in e.get("title", "").lower():
                    score += 5
                scored.append((score, e))

        # Fuzzy-Fallback
        if not scored:
            try:
                from thefuzz import fuzz
                for e in self.events:
                    searchable = " ".join([
                        e.get("title", "").lower(),
                        e.get("location", "").lower(),
                        e.get("organizer", "").lower(),
                    ])
                    score = fuzz.token_set_ratio(query_lower, searchable)
                    if score >= 65:
                        scored.append((score, e))
                scored.sort(key=lambda x: -x[0])
                if scored:
                    log.info(f"Fuzzy-Fallback fuer '{query}': {len(scored)} Treffer (top: {scored[0][0]})")
            except ImportError:
                pass

        scored.sort(key=lambda x: -x[0])
        return [e for _, e in scored[:max_results]]

    def get_event_by_id(self, event_id):
        """Findet ein Event per ID im Cache."""
        event_id = int(event_id)
        for e in self.events:
            if e.get("id") == event_id:
                return e
        return None

    def get_category_by_product_id(self, product_id):
        """Findet Kategorie + Event per WooCommerce product_id."""
        product_id = int(product_id)
        for e in self.events:
            for cat in e.get("categories", []):
                if cat.get("product_id") == product_id:
                    return cat, e
        return None, None


cache = EventCache()


def ensure_cache():
    if cache.needs_refresh():
        try:
            cache.load()
        except Exception as e:
            log.error(f"Event-Cache-Refresh fehlgeschlagen: {e}")
