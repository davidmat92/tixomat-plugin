"""
Tixomat Bot – Configuration
===============================
All environment variables, constants, and word sets.
"""

import os
import logging

# ============================================================
# API KEYS & EXTERNAL SERVICES
# ============================================================

TELEGRAM_TOKEN = os.environ.get("TELEGRAM_TOKEN", "")
ANTHROPIC_API_KEY = os.environ.get("ANTHROPIC_API_KEY", "")
WOOCOMMERCE_URL = os.environ.get("WOOCOMMERCE_URL", "https://tixomat.de")
WC_CONSUMER_KEY = os.environ.get("WC_CONSUMER_KEY", "")
WC_CONSUMER_SECRET = os.environ.get("WC_CONSUMER_SECRET", "")
WP_BOT_SECRET = os.environ.get("WP_BOT_SECRET", "")

# Tixomat REST API (mu-plugin)
TIX_BOT_API_URL = os.environ.get("TIX_BOT_API_URL", "https://tixomat.de/wp-json/tix-bot/v1")

# WhatsApp Config
WHATSAPP_TOKEN = os.environ.get("WHATSAPP_TOKEN", "")
WHATSAPP_PHONE_ID = os.environ.get("WHATSAPP_PHONE_ID", "")
WHATSAPP_VERIFY_TOKEN = os.environ.get("WHATSAPP_VERIFY_TOKEN", "tixomat_bot_verify_2025")
WHATSAPP_API = "https://graph.facebook.com/v18.0"

# Web Chat Config
WEBCHAT_SECRET = os.environ.get("WEBCHAT_SECRET", "tixomat_webchat_secret_2025")

# Admin Dashboard API
ADMIN_API_KEY = os.environ.get("ADMIN_API_KEY", "")

# OpenAI API (Whisper Voice-to-Text)
OPENAI_API_KEY = os.environ.get("OPENAI_API_KEY", "")

# ============================================================
# DERIVED CONFIG
# ============================================================

TELEGRAM_API = f"https://api.telegram.org/bot{TELEGRAM_TOKEN}"
CACHE_REFRESH_MINUTES = 15
SESSION_TIMEOUT_HOURS = 24

# Paths
_BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SESSION_DB_PATH = os.path.join(_BASE_DIR, "sessions.db")
HISTORY_DB_PATH = os.path.join(_BASE_DIR, "bot_history.db")
SESSION_FILE = os.path.join(_BASE_DIR, "sessions.json")  # Legacy, for migration only

# CORS
ALLOWED_ORIGINS = ["https://tixomat.de", "https://www.tixomat.de", "http://localhost", "https://tixomat-dpconnect.pythonanywhere.com"]

# ============================================================
# WORD SETS
# ============================================================

# Zentrale Bestaetigungswoerter
CONFIRM_YES = {"ja", "jo", "jap", "jup", "jop", "jawoll", "jawohl", "yes", "yeah", "yep", "yup",
               "ok", "oke", "okey", "okay", "okk", "okee",
               "passt", "genau", "klar", "sicher", "logo", "gut", "gerne",
               "mach das", "ja bitte", "ja mach", "ja gerne",
               "si", "safe", "alles klar", "geht klar", "mach", "bitte",
               "stimmt", "richtig", "korrekt", "jaa", "jaaa", "joo", "jooo",
               "top", "super", "perfekt", "cool", "nice", "mega", "geil",
               "mach mal", "los", "weiter", "go"}
CONFIRM_NO = {"nein", "nö", "ne", "nee", "nope", "no", "nicht", "lieber nicht",
              "doch nicht", "lass mal", "stop", "stopp", "abbrechen", "cancel"}
CONFIRM_ALL = CONFIRM_YES | CONFIRM_NO | {"danke", "dankeschön", "merci",
              "tschüss", "bye", "ciao", "bis dann"}

# Checkout-Trigger
CHECKOUT_WORDS = {"fertig", "bestellen", "abschließen", "abschliessen", "checkout",
                  "bezahlen", "kaufen", "das wars", "das war's", "das wärs",
                  "reicht", "genug", "bin fertig",
                  "will bestellen", "möchte bestellen", "order", "kasse"}

# Warenkorb-Anzeige Trigger
CART_DISPLAY_WORDS = {"warenkorb", "cart", "was hab ich", "was habe ich", "was ist drin", "übersicht"}

# Browse-Trigger (Events durchstoebern)
BROWSE_TRIGGERS = {"was gibt es", "was gibts", "was gibt's", "welche events",
                   "events", "veranstaltungen", "was steht an", "kommende events",
                   "was laeuft", "was läuft", "programm", "termine",
                   "zeig mir alles", "alle events", "was kann ich buchen"}

# Event-Kategorie-Mapping fuer Buttons
CATEGORY_MAP = {}  # Events haben keine festen Kategorien wie Produkte

# ============================================================
# BETA MODE
# ============================================================

BETA_MODE = True
BETA_HINT = ("\n\n_*Dieser Bot befindet sich in einer fruehen Testphase. "
             "Fehler bitte entschuldigen!_"
             if BETA_MODE else "")
BETA_HINT_PLAIN = ("\n\n*Dieser Bot befindet sich in einer fruehen Testphase. "
                   "Fehler bitte entschuldigen!"
                   if BETA_MODE else "")

# ============================================================
# LOGGING
# ============================================================

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")
log = logging.getLogger("tixomat_bot")
