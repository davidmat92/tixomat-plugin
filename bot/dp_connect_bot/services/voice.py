"""
Voice message transcription via OpenAI Whisper API.
Supports Telegram and WhatsApp voice messages.
"""

import os
import tempfile
import requests

from dp_connect_bot.config import (
    OPENAI_API_KEY, TELEGRAM_API, TELEGRAM_TOKEN,
    WHATSAPP_TOKEN, WHATSAPP_API, log,
)


def transcribe_telegram_voice(file_id):
    """Transkribiert eine Telegram Voice Message via OpenAI Whisper API."""
    if not OPENAI_API_KEY:
        log.warning("OpenAI API Key fehlt - Voice Message kann nicht transkribiert werden")
        return None

    tmp_path = None
    try:
        resp = requests.get(f"{TELEGRAM_API}/getFile", params={"file_id": file_id}, timeout=10)
        resp.raise_for_status()
        file_path = resp.json()["result"]["file_path"]

        file_url = f"https://api.telegram.org/file/bot{TELEGRAM_TOKEN}/{file_path}"
        audio_resp = requests.get(file_url, timeout=30)
        audio_resp.raise_for_status()

        suffix = ".ogg" if "ogg" in file_path else ".mp3"
        with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
            tmp.write(audio_resp.content)
            tmp_path = tmp.name

        with open(tmp_path, "rb") as audio_file:
            whisper_resp = requests.post(
                "https://api.openai.com/v1/audio/transcriptions",
                headers={"Authorization": f"Bearer {OPENAI_API_KEY}"},
                files={"file": (f"voice{suffix}", audio_file, f"audio/{suffix.strip('.')}")},
                data={"model": "whisper-1", "language": "de"},
                timeout=30,
            )
            whisper_resp.raise_for_status()
            text = whisper_resp.json().get("text", "").strip()

        os.unlink(tmp_path)
        log.info(f"Voice transcribed: '{text}'")
        return text

    except Exception as e:
        log.error(f"Voice transcription error: {e}")
        if tmp_path:
            try:
                os.unlink(tmp_path)
            except OSError:
                pass
        return None


def transcribe_whatsapp_voice(media_id):
    """Transkribiert eine WhatsApp Voice Message via OpenAI Whisper API."""
    if not OPENAI_API_KEY:
        log.error("OPENAI_API_KEY fehlt - Voice kann nicht transkribiert werden!")
        return None
    if not WHATSAPP_TOKEN:
        log.error("WHATSAPP_TOKEN fehlt - Voice kann nicht transkribiert werden!")
        return None

    tmp_path = None
    try:
        # Step 1: Get media URL from WhatsApp
        log.info(f"Voice: Lade Media-URL fuer {media_id}")
        resp = requests.get(
            f"{WHATSAPP_API}/{media_id}",
            headers={"Authorization": f"Bearer {WHATSAPP_TOKEN}"},
            timeout=10,
        )
        resp.raise_for_status()
        media_url = resp.json().get("url")
        if not media_url:
            log.error(f"Voice: Keine media_url in Response: {resp.text[:200]}")
            return None

        # Step 2: Download audio file
        log.info(f"Voice: Lade Audio-Datei herunter")
        audio_resp = requests.get(
            media_url,
            headers={"Authorization": f"Bearer {WHATSAPP_TOKEN}"},
            timeout=30,
        )
        audio_resp.raise_for_status()
        log.info(f"Voice: Audio heruntergeladen, {len(audio_resp.content)} bytes")

        with tempfile.NamedTemporaryFile(suffix=".ogg", delete=False) as tmp:
            tmp.write(audio_resp.content)
            tmp_path = tmp.name

        # Step 3: Transcribe via Whisper
        log.info(f"Voice: Sende an Whisper API")
        with open(tmp_path, "rb") as audio_file:
            whisper_resp = requests.post(
                "https://api.openai.com/v1/audio/transcriptions",
                headers={"Authorization": f"Bearer {OPENAI_API_KEY}"},
                files={"file": ("voice.ogg", audio_file, "audio/ogg")},
                data={"model": "whisper-1", "language": "de"},
                timeout=30,
            )
            if not whisper_resp.ok:
                log.error(f"Voice: Whisper API error {whisper_resp.status_code}: {whisper_resp.text[:300]}")
                whisper_resp.raise_for_status()
            text = whisper_resp.json().get("text", "").strip()

        os.unlink(tmp_path)
        log.info(f"Voice transcribed: '{text}'")
        return text if text else None

    except Exception as e:
        log.error(f"WhatsApp voice transcription error: {type(e).__name__}: {e}", exc_info=True)
        if tmp_path:
            try:
                os.unlink(tmp_path)
            except OSError:
                pass
        return None
