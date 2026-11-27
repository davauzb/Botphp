"""O'zbekcha ovoz: ElevenLabs orqali nutq -> matn (STT) va matn -> nutq (TTS)."""
import shutil
import subprocess

import httpx

from . import config

API = "https://api.elevenlabs.io/v1"


class VoiceError(RuntimeError):
    pass


def _headers() -> dict[str, str]:
    return {"xi-api-key": config.ELEVENLABS_API_KEY or "proxy"}


def _check(resp: httpx.Response, what: str) -> None:
    if resp.status_code == 200:
        return
    try:
        detail = resp.json().get("detail", {})
        msg = detail.get("message") if isinstance(detail, dict) else str(detail)
    except ValueError:
        msg = resp.text[:200]
    if "quota" in (msg or "").lower():
        raise VoiceError(f"{what}: ElevenLabs kreditlari tugagan. {msg}")
    raise VoiceError(f"{what} xatosi ({resp.status_code}): {msg}")


def speech_to_text(audio: bytes, filename: str = "audio.webm") -> str:
    """Ovozli xabarni o'zbekcha matnga aylantiradi."""
    resp = httpx.post(
        f"{API}/speech-to-text",
        headers=_headers(),
        data={"model_id": config.ELEVENLABS_STT_MODEL, "language_code": config.LANGUAGE_CODE},
        files={"file": (filename, audio)},
        timeout=120,
    )
    _check(resp, "Nutqni aniqlash")
    return (resp.json().get("text") or "").strip()


def text_to_speech(text: str) -> bytes:
    """Matnni o'zbekcha ovozga aylantiradi (MP3)."""
    resp = httpx.post(
        f"{API}/text-to-speech/{config.ELEVENLABS_VOICE_ID}",
        headers={**_headers(), "Content-Type": "application/json"},
        json={
            "text": text,
            "model_id": config.ELEVENLABS_TTS_MODEL,
            "language_code": config.LANGUAGE_CODE,
        },
        timeout=120,
    )
    _check(resp, "Ovoz yaratish")
    return resp.content


def mp3_to_ogg_opus(mp3: bytes) -> bytes | None:
    """Telegram "voice" xabari uchun OGG/Opus kerak. ffmpeg bo'lmasa None qaytaradi."""
    if not shutil.which("ffmpeg"):
        return None
    proc = subprocess.run(
        ["ffmpeg", "-loglevel", "error", "-i", "pipe:0", "-c:a", "libopus", "-b:a", "48k",
         "-f", "ogg", "pipe:1"],
        input=mp3, capture_output=True, check=False,
    )
    return proc.stdout if proc.returncode == 0 and proc.stdout else None


def clone_voice(name: str, sample_paths: list[str]) -> str:
    """Ovoz namunalaridan shaxsiy ovoz yaratadi va voice_id qaytaradi."""
    files = [("files", (p.rsplit("/", 1)[-1], open(p, "rb"))) for p in sample_paths]
    try:
        resp = httpx.post(
            f"{API}/voices/add", headers=_headers(), data={"name": name}, files=files, timeout=300
        )
    finally:
        for _field, (_n, fh) in files:
            fh.close()
    _check(resp, "Ovozni klonlash")
    return resp.json()["voice_id"]
