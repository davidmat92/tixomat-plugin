"""
Tenant Context – dataclass threaded through all service calls.
"""

from dataclasses import dataclass, field


@dataclass
class TenantContext:
    """Immutable context for a single tenant, threaded through handler calls."""
    tenant_id: str
    site_url: str = ""
    site_name: str = ""
    api_url: str = ""
    api_secret: str = ""
    telegram_token: str = ""
    whatsapp_token: str = ""
    whatsapp_phone_id: str = ""
    whatsapp_verify_token: str = ""
    anthropic_api_key: str = ""
    bot_name: str = "Ticket-Assistent"
    greeting: str = ""
    personality: str = ""
    channels: dict = field(default_factory=lambda: {"webchat": True, "telegram": False, "whatsapp": False})
    admin_api_key: str = ""

    @classmethod
    def from_dict(cls, d: dict) -> "TenantContext":
        """Create from tenant store dict."""
        return cls(
            tenant_id=d.get("tenant_id", ""),
            site_url=d.get("site_url", ""),
            site_name=d.get("site_name", ""),
            api_url=d.get("api_url", ""),
            api_secret=d.get("api_secret", ""),
            telegram_token=d.get("telegram_token", ""),
            whatsapp_token=d.get("whatsapp_token", ""),
            whatsapp_phone_id=d.get("whatsapp_phone_id", ""),
            whatsapp_verify_token=d.get("whatsapp_verify_token", ""),
            anthropic_api_key=d.get("anthropic_api_key", ""),
            bot_name=d.get("bot_name", "Ticket-Assistent"),
            greeting=d.get("greeting", ""),
            personality=d.get("personality", ""),
            channels=d.get("channels", {"webchat": True}),
            admin_api_key=d.get("admin_api_key", ""),
        )
