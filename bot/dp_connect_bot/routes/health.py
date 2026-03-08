"""Health check route blueprint."""

from datetime import datetime

from flask import Blueprint, jsonify

from dp_connect_bot.config import TELEGRAM_TOKEN, WHATSAPP_TOKEN, log
from dp_connect_bot.services.event_cache import cache
from dp_connect_bot.models.session import session_manager

health_bp = Blueprint("health", __name__)


@health_bp.route("/health", methods=["GET"])
def health():
    return jsonify(
        status="ok",
        channels={
            "telegram": bool(TELEGRAM_TOKEN),
            "whatsapp": bool(WHATSAPP_TOKEN),
            "webchat": True,
        },
        upcoming_events=len(cache.events),
        locations=len(cache.locations),
        active_sessions=session_manager.get_active_count(),
        cache_age=str(datetime.now() - cache.last_loaded) if cache.last_loaded else "not loaded",
    )
