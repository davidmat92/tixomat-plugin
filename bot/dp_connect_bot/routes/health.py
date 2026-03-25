"""Health check route blueprint – multi-tenant."""

from datetime import datetime

from flask import Blueprint, jsonify

from dp_connect_bot.config import log
from dp_connect_bot.services.event_cache import get_cache
from dp_connect_bot.services import tenant_store
from dp_connect_bot.models.session import session_manager

health_bp = Blueprint("health", __name__)


@health_bp.route("/health", methods=["GET"])
def health():
    tenants = tenant_store.get_all_active()
    tenant_info = []
    total_events = 0

    for t in tenants:
        tid = t["tenant_id"]
        tc = get_cache(tid)
        events_count = len(tc.events) if tc else 0
        cache_age = str(datetime.now() - tc.last_loaded) if tc and tc.last_loaded else "not loaded"
        total_events += events_count
        tenant_info.append({
            "tenant_id": tid,
            "site_url": t["site_url"],
            "site_name": t.get("site_name", ""),
            "events": events_count,
            "cache_age": cache_age,
            "channels": t.get("channels", {}),
        })

    return jsonify(
        status="ok",
        tenants=len(tenants),
        tenant_details=tenant_info,
        total_events=total_events,
        active_sessions=session_manager.get_active_count(),
    )
