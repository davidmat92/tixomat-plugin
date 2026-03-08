"""
History database – archives sessions, tracks searches and events.
"""

import json
import sqlite3
import threading
from datetime import datetime

from dp_connect_bot.config import HISTORY_DB_PATH, log

_lock = threading.Lock()


def init_history_db():
    """Erstellt die History-Tabellen falls noetig."""
    with sqlite3.connect(HISTORY_DB_PATH) as conn:
        conn.executescript("""
            CREATE TABLE IF NOT EXISTS conversations (
                chat_id TEXT PRIMARY KEY,
                channel TEXT,
                customer_name TEXT,
                user_info TEXT,
                conversation TEXT,
                cart TEXT,
                message_count INTEGER DEFAULT 0,
                created_at TEXT,
                last_activity TEXT,
                archived_at TEXT
            );
            CREATE TABLE IF NOT EXISTS daily_stats (
                date TEXT PRIMARY KEY,
                sessions INTEGER DEFAULT 0,
                messages INTEGER DEFAULT 0,
                web_sessions INTEGER DEFAULT 0,
                telegram_sessions INTEGER DEFAULT 0,
                whatsapp_sessions INTEGER DEFAULT 0,
                sessions_with_cart INTEGER DEFAULT 0,
                unique_users INTEGER DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS search_queries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                query TEXT,
                chat_id TEXT,
                channel TEXT,
                result_count INTEGER DEFAULT 0,
                timestamp TEXT
            );
            CREATE TABLE IF NOT EXISTS events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type TEXT,
                chat_id TEXT,
                channel TEXT,
                data TEXT,
                timestamp TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_conv_last ON conversations(last_activity);
            CREATE INDEX IF NOT EXISTS idx_conv_channel ON conversations(channel);
            CREATE INDEX IF NOT EXISTS idx_conv_name ON conversations(customer_name);
            CREATE INDEX IF NOT EXISTS idx_search_ts ON search_queries(timestamp);
            CREATE INDEX IF NOT EXISTS idx_search_query ON search_queries(query);
            CREATE INDEX IF NOT EXISTS idx_events_ts ON events(timestamp);
            CREATE INDEX IF NOT EXISTS idx_events_type ON events(event_type);
            CREATE INDEX IF NOT EXISTS idx_daily_date ON daily_stats(date);
        """)
    log.info("History DB initialisiert")


def archive_session(chat_id, session_data):
    """Archiviert eine Session in die History-DB."""
    try:
        with _lock:
            with sqlite3.connect(HISTORY_DB_PATH) as conn:
                conn.execute("""
                    INSERT OR REPLACE INTO conversations
                    (chat_id, channel, customer_name, user_info, conversation, cart,
                     message_count, created_at, last_activity, archived_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """, (
                    chat_id,
                    session_data.get("channel", ""),
                    session_data.get("customer_name", ""),
                    json.dumps(session_data.get("user_info", {}), ensure_ascii=False),
                    json.dumps(session_data.get("conversation", []), ensure_ascii=False),
                    json.dumps(session_data.get("cart", []), ensure_ascii=False),
                    session_data.get("message_count", 0),
                    session_data.get("created_at", ""),
                    session_data.get("last_activity", ""),
                    datetime.now().isoformat(),
                ))
    except Exception as e:
        log.error(f"Archive session error: {e}")


def track_search_query(query, chat_id, channel, result_count):
    """Speichert eine Suchanfrage fuer Statistiken."""
    try:
        with _lock:
            with sqlite3.connect(HISTORY_DB_PATH) as conn:
                conn.execute(
                    "INSERT INTO search_queries (query, chat_id, channel, result_count, timestamp) VALUES (?,?,?,?,?)",
                    (query, chat_id, channel, result_count, datetime.now().isoformat())
                )
    except Exception as e:
        log.error(f"Track search error: {e}")


def track_event(event_type, chat_id="", channel="", data=""):
    """Trackt ein Event (session_start, session_end, cart_add, checkout, etc.)."""
    try:
        with _lock:
            with sqlite3.connect(HISTORY_DB_PATH) as conn:
                conn.execute(
                    "INSERT INTO events (event_type, chat_id, channel, data, timestamp) VALUES (?,?,?,?,?)",
                    (event_type, chat_id, channel, data, datetime.now().isoformat())
                )
    except Exception as e:
        log.error(f"Track event error: {e}")


def update_daily_stats(channel="web", has_cart=False):
    """Aktualisiert die Tagesstatistiken."""
    try:
        today = datetime.now().strftime("%Y-%m-%d")
        with _lock:
            with sqlite3.connect(HISTORY_DB_PATH) as conn:
                row = conn.execute("SELECT * FROM daily_stats WHERE date=?", (today,)).fetchone()
                if not row:
                    conn.execute("INSERT INTO daily_stats (date, sessions, messages) VALUES (?, 0, 0)", (today,))
                conn.execute("UPDATE daily_stats SET messages = messages + 1 WHERE date=?", (today,))
                ch_col = f"{channel}_sessions" if channel in ("web", "telegram", "whatsapp") else None
                if ch_col:
                    conn.execute(f"UPDATE daily_stats SET {ch_col} = {ch_col} + 1 WHERE date=?", (today,))
    except Exception as e:
        log.error(f"Update daily stats error: {e}")
