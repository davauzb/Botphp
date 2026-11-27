"""AI "miyalar": Claude, ChatGPT (OpenAI) va Gemini.

Har bir provayder bir xil ko'rinishdagi funksiya:
    javob = chat(system, messages)
messages = [{"role": "user" | "assistant", "content": "matn"}, ...]
"""
import os
from typing import Callable

from . import config

Message = dict[str, str]


class LLMError(RuntimeError):
    pass


# --------------------------------------------------------------------------- Claude
def _claude(system: str, messages: list[Message]) -> str:
    import anthropic

    client = anthropic.Anthropic()
    try:
        response = client.beta.messages.create(
            model=config.CLAUDE_MODEL,
            max_tokens=8000,
            system=system,
            messages=messages,
            # Ovozli suhbat: tez javob muhim, shuning uchun "low" effort
            output_config={"effort": "low"},
            # Agar model so'rovni rad etsa, server avtomatik boshqa modelga o'tadi
            betas=["server-side-fallback-2026-07-01"],
            fallbacks="default",
        )
    except anthropic.AuthenticationError as e:
        raise LLMError("Claude: API kalit noto'g'ri (ANTHROPIC_API_KEY).") from e
    except anthropic.RateLimitError as e:
        raise LLMError("Claude: so'rovlar limiti tugadi, birozdan keyin urinib ko'ring.") from e
    except anthropic.APIStatusError as e:
        raise LLMError(f"Claude xatosi ({e.status_code}): {e.message}") from e
    except anthropic.APIConnectionError as e:
        raise LLMError("Claude serveriga ulanib bo'lmadi.") from e

    if response.stop_reason == "refusal":
        return "Kechirasiz, bu savolga javob bera olmayman."
    return "".join(b.text for b in response.content if b.type == "text").strip()


# --------------------------------------------------------------------------- OpenAI
def _openai(system: str, messages: list[Message]) -> str:
    from openai import OpenAI

    client = OpenAI()
    response = client.responses.create(
        model=config.OPENAI_MODEL,
        instructions=system,
        input=[{"role": m["role"], "content": m["content"]} for m in messages],
    )
    return response.output_text.strip()


# --------------------------------------------------------------------------- Gemini
def _gemini(system: str, messages: list[Message]) -> str:
    from google import genai
    from google.genai import types

    client = genai.Client()
    contents = [
        types.Content(
            role="model" if m["role"] == "assistant" else "user",
            parts=[types.Part(text=m["content"])],
        )
        for m in messages
    ]
    response = client.models.generate_content(
        model=config.GEMINI_MODEL,
        contents=contents,
        config=types.GenerateContentConfig(system_instruction=system),
    )
    return (response.text or "").strip()


# --------------------------------------------------------------------------- Ro'yxat
PROVIDERS: dict[str, tuple[str, Callable[[str, list[Message]], str], tuple[str, ...]]] = {
    # nomi: (ko'rinadigan nom, funksiya, kerakli muhit o'zgaruvchilari)
    "claude": ("Claude", _claude, ("ANTHROPIC_API_KEY", "ANTHROPIC_AUTH_TOKEN")),
    "openai": ("ChatGPT", _openai, ("OPENAI_API_KEY",)),
    "gemini": ("Gemini", _gemini, ("GEMINI_API_KEY", "GOOGLE_API_KEY")),
}


def available_providers() -> dict[str, str]:
    """Kaliti sozlangan provayderlar: {"claude": "Claude", ...}"""
    result = {}
    for key, (title, _fn, env_names) in PROVIDERS.items():
        if any(os.getenv(name) for name in env_names):
            result[key] = title
    return result


def default_provider() -> str:
    """.env dagi DEFAULT_PROVIDER, agar uning kaliti bo'lmasa - birinchi mavjud provayder."""
    providers = available_providers()
    if config.DEFAULT_PROVIDER in providers or not providers:
        return config.DEFAULT_PROVIDER
    return next(iter(providers))


def chat(provider: str, system: str, messages: list[Message]) -> str:
    if provider not in PROVIDERS:
        raise LLMError(f"Noma'lum provayder: {provider}")
    _title, fn, _env = PROVIDERS[provider]
    try:
        answer = fn(system, messages)
    except LLMError:
        raise
    except Exception as e:  # boshqa SDK xatolari
        raise LLMError(f"{PROVIDERS[provider][0]} xatosi: {e}") from e
    return answer or "Kechirasiz, javob bo'sh keldi."
