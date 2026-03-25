"""
Tixomat Bot – Flask App Factory (Multi-Tenant)
"""

from flask import Flask, request

from dp_connect_bot.config import (
    SESSION_FILE, log,
    WOOCOMMERCE_URL, TIX_BOT_API_URL, WP_BOT_SECRET,
    TELEGRAM_TOKEN, WHATSAPP_TOKEN, WHATSAPP_PHONE_ID,
    WHATSAPP_VERIFY_TOKEN, ANTHROPIC_API_KEY, ADMIN_API_KEY,
)
from dp_connect_bot.models.session import session_manager
from dp_connect_bot.services import tenant_store
from dp_connect_bot.services.history import init_history_db


def _ensure_default_tenant():
    """Create a default tenant from env vars if the tenant store is empty (backward compat)."""
    tenants = tenant_store.get_all_active()
    if tenants:
        return  # Already have tenants

    # Only register default tenant if we have the legacy env vars configured
    if not TIX_BOT_API_URL or not WP_BOT_SECRET:
        log.info("No tenants registered and no legacy env vars – skipping default tenant")
        return

    site_url = WOOCOMMERCE_URL or "https://tixomat.de"
    data = {
        "site_url": site_url,
        "site_name": "Tixomat (Default)",
        "api_url": TIX_BOT_API_URL,
        "api_secret": WP_BOT_SECRET,
        "telegram_token": TELEGRAM_TOKEN,
        "whatsapp_token": WHATSAPP_TOKEN,
        "whatsapp_phone_id": WHATSAPP_PHONE_ID,
        "whatsapp_verify": WHATSAPP_VERIFY_TOKEN,
        "anthropic_key": ANTHROPIC_API_KEY,
        "bot_name": "Ticket-Assistent",
        "channels": {
            "webchat": True,
            "telegram": bool(TELEGRAM_TOKEN),
            "whatsapp": bool(WHATSAPP_TOKEN),
        },
    }
    result = tenant_store.register_tenant(data)
    if result.get("ok"):
        log.info(f"Default tenant created: {result['tenant_id']} ({site_url})")
        # Store admin key for backward compat if ADMIN_API_KEY was set
        if ADMIN_API_KEY:
            log.info(f"Default tenant admin_api_key: {result.get('admin_api_key', '')}")
    else:
        log.error(f"Failed to create default tenant: {result}")


def create_app():
    """Create and configure the Flask application."""
    app = Flask(__name__)

    # --- CORS Middleware (dynamic origins from tenant store) ---
    @app.after_request
    def add_cors_headers(response):
        origin = request.headers.get("Origin", "")
        allowed = tenant_store.get_allowed_origins()
        if any(origin.startswith(a) for a in allowed):
            response.headers["Access-Control-Allow-Origin"] = origin
            response.headers["Access-Control-Allow-Headers"] = "Content-Type, Authorization, X-Admin-Key, X-Hub-Key"
            response.headers["Access-Control-Allow-Methods"] = "POST, GET, OPTIONS"
        return response

    # Register blueprints
    from dp_connect_bot.routes.telegram import telegram_bp
    from dp_connect_bot.routes.whatsapp import whatsapp_bp
    from dp_connect_bot.routes.webchat import webchat_bp
    from dp_connect_bot.routes.admin import admin_bp
    from dp_connect_bot.routes.health import health_bp
    from dp_connect_bot.routes.tenants import tenants_bp

    app.register_blueprint(telegram_bp)
    app.register_blueprint(whatsapp_bp)
    app.register_blueprint(webchat_bp)
    app.register_blueprint(admin_bp)
    app.register_blueprint(health_bp)
    app.register_blueprint(tenants_bp)

    # Startup tasks
    with app.app_context():
        init_history_db()
        tenant_store.init_db()
        _ensure_default_tenant()
        # One-time migration from legacy sessions.json
        session_manager.migrate_from_json(SESSION_FILE)

    log.info("Tixomat Bot app created (multi-tenant)")
    return app
