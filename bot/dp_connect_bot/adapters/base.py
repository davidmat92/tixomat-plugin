"""
Channel adapter base class – defines the interface all adapters must implement.
"""

from abc import ABC, abstractmethod
from dp_connect_bot.models.response import BotResponse


class ChannelAdapter(ABC):
    """Abstract base class for channel adapters.

    Each adapter knows how to:
    1. Send a BotResponse to the channel
    2. Build channel-specific keyboards from generic Keyboard objects
    """

    @abstractmethod
    def send_response(self, chat_id, response: BotResponse):
        """Render and send a BotResponse to the channel.

        Args:
            chat_id: Channel-specific chat identifier (raw, without prefix)
            response: Channel-agnostic BotResponse from unified handlers
        """

    @abstractmethod
    def send_typing(self, chat_id):
        """Send typing indicator."""

    @property
    @abstractmethod
    def channel_name(self) -> str:
        """Return the channel name (telegram, whatsapp, web)."""

    @property
    def chat_id_prefix(self) -> str:
        """Return the prefix for session chat IDs (e.g. 'tg_', 'wa_', 'web_')."""
        return ""

    def prefixed_chat_id(self, raw_id) -> str:
        """Return the session key for this channel."""
        return f"{self.chat_id_prefix}{raw_id}"
