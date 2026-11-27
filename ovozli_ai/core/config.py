"""Sozlamalar: hammasi .env faylidan o'qiladi."""
import os
from pathlib import Path

from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parent.parent / ".env")


def _env(name: str, default: str = "") -> str:
    return os.getenv(name, default).strip()


# Yordamchining shaxsiyati
ASSISTANT_NAME = _env("ASSISTANT_NAME", "Lola")
ASSISTANT_PERSONA = _env(
    "ASSISTANT_PERSONA",
    "Samimiy, aqlli va hazilkash yordamchi. Har doim yordam berishga tayyor.",
)

# AI provayderlar (kalit bo'lmasa, o'sha provayder ro'yxatda ko'rinmaydi)
DEFAULT_PROVIDER = _env("DEFAULT_PROVIDER", "claude")
CLAUDE_MODEL = _env("CLAUDE_MODEL", "claude-opus-5-5")
OPENAI_MODEL = _env("OPENAI_MODEL", "gpt-5")
GEMINI_MODEL = _env("GEMINI_MODEL", "gemini-2.5-flash")

# ElevenLabs: ovoz (TTS) va nutqni matnga aylantirish (STT)
ELEVENLABS_API_KEY = _env("ELEVENLABS_API_KEY")
ELEVENLABS_VOICE_ID = _env("ELEVENLABS_VOICE_ID", "hpp4J3VqNfWAUOO0d1Us")
ELEVENLABS_TTS_MODEL = _env("ELEVENLABS_TTS_MODEL", "eleven_v4")  # o'zbek tilini qo'llaydi
ELEVENLABS_STT_MODEL = _env("ELEVENLABS_STT_MODEL", "scribe_v1")
LANGUAGE_CODE = _env("LANGUAGE_CODE", "uz")

# Telegram
TELEGRAM_BOT_TOKEN = _env("TELEGRAM_BOT_TOKEN")

# Suhbat xotirasi (oxirgi nechta xabar eslab qolinadi)
HISTORY_LIMIT = int(_env("HISTORY_LIMIT", "20"))
