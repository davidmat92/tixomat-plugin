"""
Tenant Management Routes

Auth-Konzept:
  - HUB_MASTER_KEY  → Registrierung + eigene Seite abmelden (hat jede Seite)
  - HUB_ADMIN_KEY   → Tenant-Liste einsehen + beliebige Tenants loeschen (hat nur der Plattform-Admin)
"""

import os
import requests
from flask import Blueprint, request, jsonify

from dp_connect_bot.config import log
from dp_connect_bot.services import tenant_store

tenants_bp = Blueprint("tenants", __name__)

HUB_MASTER_KEY = os.environ.get("HUB_MASTER_KEY", "")
HUB_ADMIN_KEY = os.environ.get("HUB_ADMIN_KEY", "")


def _check_hub_key():
    """Verify hub master key (registration-level access)."""
    key = request.headers.get("X-Hub-Key", "")
    if not HUB_MASTER_KEY or not key:
        return False
    return key == HUB_MASTER_KEY


def _check_admin_key():
    """Verify hub admin key (full tenant management access)."""
    key = request.headers.get("X-Hub-Admin-Key", "")
    if not HUB_ADMIN_KEY or not key:
        return False
    return key == HUB_ADMIN_KEY


@tenants_bp.route("/tenants/register", methods=["POST"])
def register():
    if not _check_hub_key():
        return jsonify(ok=False, error="Unauthorized"), 401

    data = request.get_json() or {}
    result = tenant_store.register_tenant(data)

    if result.get("ok"):
        tenant_id = result["tenant_id"]
        telegram_token = data.get("telegram_token", "")

        # Set up Telegram webhook if token provided
        if telegram_token and data.get("channels", {}).get("telegram"):
            _setup_telegram_webhook(tenant_id, telegram_token)

        return jsonify(result)

    return jsonify(result), 400


@tenants_bp.route("/tenants/unregister", methods=["POST"])
def unregister():
    """Tenant abmelden.

    Zwei Modi:
      1. Admin-Key → kann beliebigen Tenant loeschen
      2. Hub-Key + api_secret → kann nur eigenen Tenant loeschen (Ownership-Pruefung)
    """
    is_admin = _check_admin_key()
    is_hub = _check_hub_key()

    if not is_admin and not is_hub:
        return jsonify(ok=False, error="Unauthorized"), 401

    data = request.get_json() or {}
    tenant_id = data.get("tenant_id", "")
    if not tenant_id:
        return jsonify(ok=False, error="tenant_id required"), 400

    tenant = tenant_store.get_tenant(tenant_id)
    if not tenant:
        return jsonify(ok=False, error="Tenant not found"), 404

    # Wenn kein Admin: Ownership pruefen via api_secret
    if not is_admin:
        provided_secret = data.get("api_secret", "")
        stored_secret = tenant.get("api_secret", "")
        if not provided_secret or provided_secret != stored_secret:
            return jsonify(ok=False, error="Nicht berechtigt. Nur der eigene Tenant kann abgemeldet werden."), 403

    # Telegram Webhook entfernen
    if tenant.get("telegram_token"):
        _remove_telegram_webhook(tenant["telegram_token"])

    result = tenant_store.unregister_tenant(tenant_id)
    log.info(f"Tenant {tenant_id} unregistered by {'admin' if is_admin else 'self'}")
    return jsonify(result)


@tenants_bp.route("/tenants/ping/<tenant_id>", methods=["GET"])
def ping(tenant_id):
    tenant = tenant_store.get_tenant(tenant_id)
    if not tenant:
        return jsonify(ok=False, error="Tenant not found"), 404
    return jsonify(ok=True, tenant_id=tenant_id, site_url=tenant["site_url"])


@tenants_bp.route("/tenants/list", methods=["GET"])
def list_tenants():
    """Alle Tenants auflisten — nur mit Admin-Key."""
    if not _check_admin_key():
        return jsonify(ok=False, error="Admin-Berechtigung erforderlich"), 403

    tenants = tenant_store.get_all_active()
    return jsonify(ok=True, count=len(tenants), tenants=[
        {"tenant_id": t["tenant_id"], "site_url": t["site_url"], "site_name": t["site_name"]}
        for t in tenants
    ])


def _setup_telegram_webhook(tenant_id: str, token: str):
    """Set Telegram webhook URL for this tenant."""
    try:
        base_url = os.environ.get("BOT_BASE_URL", "https://tixomat.pythonanywhere.com")
        webhook_url = f"{base_url}/webhook/{tenant_id}"
        resp = requests.post(
            f"https://api.telegram.org/bot{token}/setWebhook",
            json={"url": webhook_url},
            timeout=10,
        )
        data = resp.json()
        if data.get("ok"):
            log.info(f"Telegram webhook set for tenant {tenant_id}: {webhook_url}")
        else:
            log.error(f"Telegram webhook failed for {tenant_id}: {data}")
    except Exception as e:
        log.error(f"Telegram webhook error for {tenant_id}: {e}")


def _remove_telegram_webhook(token: str):
    """Remove Telegram webhook."""
    try:
        requests.post(f"https://api.telegram.org/bot{token}/deleteWebhook", timeout=10)
    except Exception:
        pass
