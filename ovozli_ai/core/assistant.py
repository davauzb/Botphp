"""Yordamchining "yuragi": shaxsiyat, suhbat xotirasi va to'liq ovozli sikl."""
import threading
from dataclasses import dataclass

from . import config, llm, voice


def system_prompt() -> str:
    return (
        f"Sening isming {config.ASSISTANT_NAME}. {config.ASSISTANT_PERSONA}\n"
        "Qoidalar:\n"
        "- Har doim o'zbek tilida, lotin alifbosida javob ber (foydalanuvchi boshqa tilda "
        "so'ramasa).\n"
        "- Javobing ovoz bilan o'qiladi: qisqa va tabiiy gapir, odatda 1-4 gap.\n"
        "- Markdown, ro'yxat belgilari, emoji va jadval ishlatma.\n"
        "- Seni kim yaratganini so'rashsa, o'z egangning shaxsiy yordamchisi ekaningni ayt."
    )


class Memory:
    """Har bir foydalanuvchi (sessiya) uchun alohida suhbat tarixi."""

    def __init__(self) -> None:
        self._data: dict[str, list[llm.Message]] = {}
        self._lock = threading.Lock()

    def get(self, session: str) -> list[llm.Message]:
        with self._lock:
            return list(self._data.get(session, []))

    def add(self, session: str, user: str, assistant: str) -> None:
        with self._lock:
            history = self._data.setdefault(session, [])
            history += [{"role": "user", "content": user}, {"role": "assistant", "content": assistant}]
            del history[: max(0, len(history) - config.HISTORY_LIMIT)]

    def clear(self, session: str) -> None:
        with self._lock:
            self._data.pop(session, None)


memory = Memory()


@dataclass
class Reply:
    user_text: str
    text: str
    audio_mp3: bytes | None
    voice_error: str | None = None


def ask(session: str, user_text: str, provider: str | None = None, speak: bool = True) -> Reply:
    provider = provider or llm.default_provider()
    messages = memory.get(session) + [{"role": "user", "content": user_text}]
    answer = llm.chat(provider, system_prompt(), messages)
    memory.add(session, user_text, answer)

    audio, err = None, None
    if speak:
        try:
            audio = voice.text_to_speech(answer)
        except Exception as e:  # ovoz ishlamasa ham matn qaytadi
            err = str(e)
    return Reply(user_text, answer, audio, err)


def ask_by_voice(session: str, audio: bytes, filename: str, provider: str | None = None) -> Reply:
    user_text = voice.speech_to_text(audio, filename)
    if not user_text:
        raise voice.VoiceError("Ovozingizni tushuna olmadim, qaytadan gapiring.")
    return ask(session, user_text, provider)
