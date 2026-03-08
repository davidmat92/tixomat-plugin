"""
SQLite WAL Session Manager – replaces JSON file approach.
Thread-safe, no race conditions across PythonAnywhere workers.
"""

import json
import sqlite3
import threading
from datetime import datetime, timedelta

from dp_connect_bot.config import SESSION_DB_PATH, SESSION_TIMEOUT_HOURS, log


def _default_session(chat_id):
    """Creates a fresh session dict for a new chat."""
    now = datetime.now().isoformat()
    return {
        "chat_id": chat_id,
        "conversation": [],
        "cart": [],
        "status": "browsing",
        "last_activity": now,
        "created_at": now,
        "customer_name": None,
        "pending_selection": None,
        "channel": None,
        "message_count": 0,
        "user_info": {},
        "mode": None,
        "hints_shown": {},
    }


class SessionManager:
    """SQLite WAL-based session store.

    Each worker gets a thread-local connection.
    WAL mode allows concurrent reads across PythonAnywhere workers.
    """

    def __init__(self, db_path=None):
        self.db_path = db_path or SESSION_DB_PATH
        self._local = threading.local()
        self._init_db()

    def _conn(self):
        """Thread-local SQLite connection."""
        if not hasattr(self._local, "conn") or self._local.conn is None:
            conn = sqlite3.connect(self.db_path, timeout=10)
            conn.execute("PRAGMA journal_mode=WAL")
            conn.execute("PRAGMA busy_timeout=5000")
            conn.row_factory = sqlite3.Row
            self._local.conn = conn
        return self._local.conn

    def _init_db(self):
        """Create sessions table if it doesn't exist."""
        conn = self._conn()
        conn.execute("""
            CREATE TABLE IF NOT EXISTS sessions (
                chat_id TEXT PRIMARY KEY,
                data TEXT NOT NULL,
                last_activity TEXT NOT NULL,
                created_at TEXT NOT NULL
            )
        """)
        conn.execute("""
            CREATE INDEX IF NOT EXISTS idx_sessions_activity
            ON sessions(last_activity)
        """)
        conn.commit()
        log.info("SessionManager SQLite initialisiert")

    def get(self, chat_id, archive_callback=None):
        """Get or create a session. Expires old sessions automatically.

        Args:
            chat_id: Unique chat identifier (e.g. "tg_12345", "wa_49123", "web_abc")
            archive_callback: Optional function(chat_id, session_dict) called for expired sessions
        """
        chat_id = str(chat_id)
        conn = self._conn()

        # Expire old sessions
        self._expire(archive_callback)

        # Try to load existing session
        row = conn.execute(
            "SELECT data FROM sessions WHERE chat_id = ?", (chat_id,)
        ).fetchone()

        if row:
            session = json.loads(row["data"])
        else:
            session = _default_session(chat_id)

        # Update last_activity
        session["last_activity"] = datetime.now().isoformat()
        self.save(chat_id, session)
        return session

    def save(self, chat_id, session):
        """Persist session to SQLite."""
        chat_id = str(chat_id)
        conn = self._conn()
        now = session.get("last_activity", datetime.now().isoformat())
        created = session.get("created_at", now)
        conn.execute(
            """INSERT INTO sessions (chat_id, data, last_activity, created_at)
               VALUES (?, ?, ?, ?)
               ON CONFLICT(chat_id) DO UPDATE SET
                   data = excluded.data,
                   last_activity = excluded.last_activity""",
            (chat_id, json.dumps(session, ensure_ascii=False), now, created),
        )
        conn.commit()

    def delete(self, chat_id):
        """Remove a session."""
        conn = self._conn()
        conn.execute("DELETE FROM sessions WHERE chat_id = ?", (str(chat_id),))
        conn.commit()

    def get_all(self):
        """Return all active sessions as {chat_id: session_dict}."""
        conn = self._conn()
        rows = conn.execute("SELECT chat_id, data FROM sessions").fetchall()
        return {row["chat_id"]: json.loads(row["data"]) for row in rows}

    def get_active_count(self):
        """Return count of active sessions."""
        conn = self._conn()
        row = conn.execute("SELECT COUNT(*) as cnt FROM sessions").fetchone()
        return row["cnt"] if row else 0

    def _expire(self, archive_callback=None):
        """Remove sessions older than SESSION_TIMEOUT_HOURS."""
        conn = self._conn()
        cutoff = (datetime.now() - timedelta(hours=SESSION_TIMEOUT_HOURS)).isoformat()

        if archive_callback:
            # Fetch expired sessions before deleting so we can archive them
            expired = conn.execute(
                "SELECT chat_id, data FROM sessions WHERE last_activity < ?",
                (cutoff,),
            ).fetchall()
            for row in expired:
                try:
                    archive_callback(row["chat_id"], json.loads(row["data"]))
                except Exception as e:
                    log.error(f"Archive callback error for {row['chat_id']}: {e}")

        conn.execute("DELETE FROM sessions WHERE last_activity < ?", (cutoff,))
        conn.commit()

    def migrate_from_json(self, json_path):
        """One-time migration from sessions.json to SQLite."""
        import os
        if not os.path.exists(json_path):
            log.info("Keine sessions.json zum Migrieren gefunden")
            return 0

        try:
            with open(json_path, "r", encoding="utf-8") as f:
                old_sessions = json.load(f)
        except Exception as e:
            log.error(f"Migration-Fehler beim Lesen von {json_path}: {e}")
            return 0

        count = 0
        for chat_id, session in old_sessions.items():
            self.save(chat_id, session)
            count += 1

        log.info(f"Migration abgeschlossen: {count} Sessions von JSON nach SQLite")
        # Rename old file so we don't re-migrate
        backup = json_path + ".migrated"
        try:
            os.rename(json_path, backup)
            log.info(f"sessions.json umbenannt zu {backup}")
        except Exception as e:
            log.error(f"Konnte sessions.json nicht umbenennen: {e}")
        return count


# Singleton instance
session_manager = SessionManager()
