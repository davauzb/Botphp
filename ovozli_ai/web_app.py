"""Veb-sayt: brauzerda mikrofon orqali gaplashish.

Ishga tushirish:  python web_app.py   ->  http://localhost:8000
"""
import base64
from pathlib import Path

import uvicorn
from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from fastapi.responses import FileResponse

from core import assistant, config, llm, voice

app = FastAPI(title=f"{config.ASSISTANT_NAME} - ovozli AI")
WEB_DIR = Path(__file__).parent / "web"


@app.get("/")
def index():
    return FileResponse(WEB_DIR / "index.html")


@app.get("/api/info")
def info():
    providers = llm.available_providers()
    default = llm.default_provider() if providers else None
    return {"name": config.ASSISTANT_NAME, "providers": providers, "default": default}


@app.post("/api/talk")
def talk(
    session_id: str = Form(...),
    provider: str = Form(None),
    text: str = Form(None),
    audio: UploadFile | None = File(None),
):
    try:
        if audio is not None:
            reply = assistant.ask_by_voice(session_id, audio.file.read(), audio.filename or "a.webm", provider)
        elif text and text.strip():
            reply = assistant.ask(session_id, text.strip(), provider)
        else:
            raise HTTPException(400, "Matn yoki ovoz yuboring.")
    except (llm.LLMError, voice.VoiceError) as e:
        raise HTTPException(502, str(e)) from e

    return {
        "user_text": reply.user_text,
        "reply": reply.text,
        "audio": base64.b64encode(reply.audio_mp3).decode() if reply.audio_mp3 else None,
        "voice_error": reply.voice_error,
    }


@app.post("/api/reset")
def reset(session_id: str = Form(...)):
    assistant.memory.clear(session_id)
    return {"ok": True}


if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8000)
