"""
Tenant Store – SQLite-based registry for multi-tenant bot configuration.
"""

import sqlite3
import hashlib
import threading
import json
import os
from datetime import datetime

from dp_connect_bot.config import log

_BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TENANT_DB_PATH = os.path.join(_BASE_DIR, "tenants.db")

_lock = threading.Lock()


def _get_db():
    """Get thread-local database connection."""
    conn = sqlite3.connect(TENANT_DB_PATH, timeout=10)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    return conn


def init_db():
    """Create tenants table if not exists."""
    with _lock:
        conn = _get_db()
        conn.execute("""
            CREATE TABLE IF NOT EXISTS tenants (
                tenant_id TEXT PRIMARY KEY,
                site_url TEXT NOT NULL,
                site_name TEXT DEFAULT '',
                api_url TEXT NOT NULL,
                api_secret TEXT NOT NULL,
                telegram_token TEXT DEFAULT '',
                whatsapp_token TEXT DEFAULT '',
                whatsapp_phone_id TEXT DEFAULT '',
                whatsapp_verify_token TEXT DEFAULT '',
                anthropic_api_key TEXT DEFAULT '',
                bot_name TEXT DEFAULT 'Ticket-Assistent',
                greeting TEXT DEFAULT '',
                personality TEXT DEFAULT '',
                channels TEXT DEFAULT '{"webchat":true,"telegram":false,"whatsapp":false}',
                admin_api_key TEXT DEFAULT '',
                active INTEGER DEFAULT 1,
                registered_at TEXT,
                updated_at TEXT
            )
        """)
        conn.commit()
        conn.close()
        log.info("Tenant store initialized")


def generate_tenant_id(site_url: str) -> str:
    """Generate stable tenant ID from site URL."""
    return hashlib.sha256(site_url.strip().rstrip("/").lower().encode()).hexdigest()[:12]


def register_tenant(data: dict) -> dict:
    """Register or update a tenant."""
    site_url = data.get("site_url", "").strip().rstrip("/")
    if not site_url:
        return {"ok": False, "error": "site_url required"}

    tenant_id = generate_tenant_id(site_url)
    now = datetime.utcnow().isoformat()
    channels = json.dumps(data.get("channels", {"webchat": True, "telegram": False, "whatsapp": False}))

    # Generate admin API key for this tenant
    admin_key = hashlib.sha256(f"{tenant_id}:{data.get('api_secret', '')}:{now}".encode()).hexdigest()[:32]

    with _lock:
        conn = _get_db()
        conn.execute("""
            INSERT INTO tenants (tenant_id, site_url, site_name, api_url, api_secret,
                telegram_token, whatsapp_token, whatsapp_phone_id, whatsapp_verify_token,
                anthropic_api_key, bot_name, greeting, personality, channels,
                admin_api_key, active, registered_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
            ON CONFLICT(tenant_id) DO UPDATE SET
                site_name=excluded.site_name, api_url=excluded.api_url,
                api_secret=excluded.api_secret, telegram_token=excluded.telegram_token,
                whatsapp_token=excluded.whatsapp_token, whatsapp_phone_id=excluded.whatsapp_phone_id,
                whatsapp_verify_token=excluded.whatsapp_verify_token,
                anthropic_api_key=excluded.anthropic_api_key, bot_name=excluded.bot_name,
                greeting=excluded.greeting, personality=excluded.personality,
                channels=excluded.channels, admin_api_key=excluded.admin_api_key,
                active=1, updated_at=excluded.updated_at
        """, (
            tenant_id, site_url, data.get("site_name", ""),
            data.get("api_url", ""), data.get("api_secret", ""),
            data.get("telegram_token", ""), data.get("whatsapp_token", ""),
            data.get("whatsapp_phone_id", ""), data.get("whatsapp_verify", ""),
            data.get("anthropic_key", ""), data.get("bot_name", "Ticket-Assistent"),
            data.get("bot_greeting", ""), data.get("bot_personality", ""),
            channels, admin_key, now, now,
        ))
        conn.commit()
        conn.close()

    log.info(f"Tenant registered: {tenant_id} ({site_url})")
    return {"ok": True, "tenant_id": tenant_id, "admin_api_key": admin_key}


def unregister_tenant(tenant_id: str) -> dict:
    """Deactivate a tenant."""
    with _lock:
        conn = _get_db()
        conn.execute("UPDATE tenants SET active=0, updated_at=? WHERE tenant_id=?",
                      (datetime.utcnow().isoformat(), tenant_id))
        conn.commit()
        conn.close()
    log.info(f"Tenant unregistered: {tenant_id}")
    return {"ok": True}


def get_tenant(tenant_id: str) -> dict | None:
    """Get tenant by ID."""
    conn = _get_db()
    row = conn.execute("SELECT * FROM tenants WHERE tenant_id=? AND active=1", (tenant_id,)).fetchone()
    conn.close()
    if not row:
        return None
    return _row_to_dict(row)


def get_tenant_by_telegram_token(token: str) -> dict | None:
    """Find tenant by Telegram bot token."""
    if not token:
        return None
    conn = _get_db()
    row = conn.execute("SELECT * FROM tenants WHERE telegram_token=? AND active=1", (token,)).fetchone()
    conn.close()
    if not row:
        return None
    return _row_to_dict(row)


def get_all_active() -> list[dict]:
    """Get all active tenants."""
    conn = _get_db()
    rows = conn.execute("SELECT * FROM tenants WHERE active=1").fetchall()
    conn.close()
    return [_row_to_dict(r) for r in rows]


def get_allowed_origins() -> list[str]:
    """Get list of allowed CORS origins from active tenants."""
    conn = _get_db()
    rows = conn.execute("SELECT site_url FROM tenants WHERE active=1").fetchall()
    conn.close()
    origins = []
    for r in rows:
        url = r["site_url"]
        origins.append(url)
        # Also add www variant
        if url.startswith("https://") and not url.startswith("https://www."):
            origins.append(url.replace("https://", "https://www."))
    # Always allow PythonAnywhere itself and localhost
    origins.extend(["http://localhost", "https://tixomat-dpconnect.pythonanywhere.com"])
    return origins


def _row_to_dict(row) -> dict:
    """Convert SQLite Row to dict with parsed JSON fields."""
    d = dict(row)
    try:
        d["channels"] = json.loads(d.get("channels", "{}"))
    except (json.JSONDecodeError, TypeError):
        d["channels"] = {"webchat": True, "telegram": False, "whatsapp": False}
    return d
