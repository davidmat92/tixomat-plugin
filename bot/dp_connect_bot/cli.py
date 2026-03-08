"""
CLI entry point – polling, webhook management, cache testing.
Adapted for Tixomat event ticketing bot.
"""

import json
import sys
import time

import requests

from dp_connect_bot.config import TELEGRAM_API, log
from dp_connect_bot.services.event_cache import cache, ensure_cache
from dp_connect_bot.handlers.unified import unified_handle_message, unified_handle_callback
from dp_connect_bot.adapters.telegram import TelegramAdapter
from dp_connect_bot.services.history import init_history_db


def set_webhook(url):
    """Set Telegram webhook URL."""
    resp = requests.post(f"{TELEGRAM_API}/setWebhook", json={"url": url})
    log.info(f"Webhook -> {url}: {resp.json()}")
    return resp.json()


def delete_webhook():
    """Delete Telegram webhook."""
    return requests.post(f"{TELEGRAM_API}/deleteWebhook").json()


def run_polling():
    """Run bot in Telegram polling mode (for local development)."""
    log.info("Bot startet im Polling-Modus...")
    init_history_db()
    delete_webhook()
    ensure_cache()

    adapter = TelegramAdapter()
    last_id = 0

    while True:
        try:
            resp = requests.get(
                f"{TELEGRAM_API}/getUpdates",
                params={"offset": last_id + 1, "timeout": 30},
                timeout=35,
            )
            for update in resp.json().get("result", []):
                last_id = update["update_id"]

                msg = update.get("message")
                if msg and msg.get("text"):
                    cid = msg["chat"]["id"]
                    user_info = msg.get("from", {})
                    text = msg["text"]
                    log.info(f"[TG:{cid}] {user_info.get('first_name', '?')}: {text}")

                    response = unified_handle_message(
                        f"tg_{cid}", text, user_info, channel="telegram"
                    )
                    adapter.send_response(cid, response)

                elif msg and (msg.get("voice") or msg.get("audio")):
                    cid = msg["chat"]["id"]
                    user_info = msg.get("from", {})
                    voice = msg.get("voice") or msg.get("audio")
                    file_id = voice.get("file_id")
                    log.info(f"[TG:{cid}] Voice message received")

                    adapter._send_message(cid, "🎤 _Sprachnachricht wird verarbeitet..._")

                    from dp_connect_bot.services.voice import transcribe_telegram_voice
                    text = transcribe_telegram_voice(file_id)
                    if text:
                        adapter._send_message(cid, f"🎤 _{text}_")
                        response = unified_handle_message(
                            f"tg_{cid}", text, user_info, channel="telegram"
                        )
                        adapter.send_response(cid, response)
                    else:
                        adapter._send_message(
                            cid,
                            "Sorry, ich konnte die Sprachnachricht nicht verstehen. 😅\n"
                            "Kannst du mir stattdessen schreiben was du brauchst?",
                        )

                cb = update.get("callback_query")
                if cb:
                    cid = cb["message"]["chat"]["id"]
                    data = cb.get("data", "")
                    log.info(f"[TG:{cid}] Callback: {data}")

                    response = unified_handle_callback(
                        f"tg_{cid}", data, channel="telegram"
                    )
                    adapter.send_response(cid, response)
                    adapter.answer_callback(cb["id"], response.answer_callback_text)

        except requests.exceptions.Timeout:
            continue
        except Exception as e:
            log.error(f"Polling error: {e}")
            time.sleep(5)


def test_cache(query=None):
    """Test event cache."""
    ensure_cache()
    events = cache.get_upcoming()
    print(f"Upcoming events: {len(events)}")
    print(f"Locations: {sorted(cache.locations)}")
    if query:
        results = cache.search_events(query)
        print(f"\nSearch '{query}': {len(results)} results")
        for e in results[:10]:
            title = e.get("title", "")
            date = e.get("date_formatted", "")
            cats = len(e.get("categories", []))
            print(f"  - {title} | {date} | {cats} Kategorien [ID:{e['id']}]")


def main():
    if len(sys.argv) < 2:
        print("Usage: python -m dp_connect_bot.cli [polling|set-webhook URL|delete-webhook|webhook-info|test-cache QUERY]")
        sys.exit(1)

    cmd = sys.argv[1]
    if cmd == "polling":
        run_polling()
    elif cmd == "set-webhook" and len(sys.argv) > 2:
        print(set_webhook(sys.argv[2]))
    elif cmd == "delete-webhook":
        print(delete_webhook())
    elif cmd == "webhook-info":
        print(json.dumps(requests.get(f"{TELEGRAM_API}/getWebhookInfo").json(), indent=2))
    elif cmd == "test-cache":
        query = " ".join(sys.argv[2:]) if len(sys.argv) > 2 else None
        test_cache(query)
    else:
        print("Unknown command:", cmd)
        sys.exit(1)


if __name__ == "__main__":
    main()
