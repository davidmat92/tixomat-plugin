"""
Bot-wide runtime configuration (JSON file-based).
Stores settings like order_enabled that can be toggled from the WordPress admin.
"""

import json
import os
import threading

CONFIG_PATH = os.path.join(
    os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
    "..",
    "bot_config.json",
)

_lock = threading.Lock()

DEFAULTS = {
    "order_enabled": True,
}


def load_bot_config() -> dict:
    """Load the current bot configuration, merged with defaults."""
    with _lock:
        if os.path.exists(CONFIG_PATH):
            try:
                with open(CONFIG_PATH, "r") as f:
                    stored = json.load(f)
                return {**DEFAULTS, **stored}
            except (json.JSONDecodeError, IOError):
                return dict(DEFAULTS)
        return dict(DEFAULTS)


def save_bot_config(config: dict):
    """Persist the bot configuration to disk."""
    with _lock:
        with open(CONFIG_PATH, "w") as f:
            json.dump(config, f, indent=2)
