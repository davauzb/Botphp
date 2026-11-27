# Ovozli AI: o'zbekcha ovozli shaxsiy yordamchi

Siz o'zbekcha gapirasiz, yordamchi tanlangan AI (Claude, ChatGPT yoki Gemini) bilan o'ylaydi va
o'zbekcha ovozda javob beradi. Ovoz sizning ovozingiz (klon) yoki tanlangan boshqa ovoz bo'lishi mumkin.

Dastur ikki joyda ishlaydi:
- **Veb-sayt.** Brauzerda mikrofon tugmasini bosib gapirasiz.
- **Telegram bot.** Botga ovozli xabar yuborasiz, u ovozli xabar bilan javob qaytaradi.

## Qanday ishlaydi

```
Sizning ovozingiz -> [ElevenLabs Scribe: nutq -> matn]
                  -> [Claude / ChatGPT / Gemini: javob o'ylaydi]
                  -> [ElevenLabs eleven_v4: matn -> o'zbekcha ovoz] -> Javob
```

| Fayl | Vazifasi |
|---|---|
| `core/config.py` | Sozlamalar (`.env` dan) |
| `core/llm.py` | AI "miyalar": Claude, ChatGPT, Gemini |
| `core/voice.py` | O'zbekcha ovoz: STT, TTS, ovoz klonlash |
| `core/assistant.py` | Yordamchining ismi, xarakteri va suhbat xotirasi |
| `web_app.py` + `web/index.html` | Veb-sayt |
| `telegram_bot.py` | Telegram bot |
| `clone_voice.py` | O'z ovozingizni klonlash |

## O'rnatish

```bash
cd ovozli_ai
python -m venv .venv && source .venv/bin/activate   # Windows: .venv\Scripts\activate
pip install -r requirements.txt
cp .env.example .env        # keyin .env ni to'ldiring
```

`.env` faylida kamida quyidagilar bo'lishi kerak:
- bitta AI kaliti: `ANTHROPIC_API_KEY`, `OPENAI_API_KEY` yoki `GEMINI_API_KEY`;
- `ELEVENLABS_API_KEY` (ovoz uchun);
- Telegram uchun `TELEGRAM_BOT_TOKEN` (@BotFather'dan olinadi).

Qaysi AI kaliti bo'lsa, faqat o'sha model ro'yxatda chiqadi.

## Ishga tushirish

```bash
python web_app.py        # veb-sayt: http://localhost:8000
python telegram_bot.py   # Telegram bot
```

Telegram buyruqlari:
- `/start`: salomlashish;
- `/model`: AI'ni tanlash;
- `/yangi`: suhbatni tozalash.

## O'z ovozingizni qo'shish

1. Shovqinsiz joyda 1-3 daqiqa o'zbekcha gapirib, ovozingizni yozib oling.
2. `python clone_voice.py "Mening ovozim" namuna1.mp3 namuna2.mp3` buyrug'ini ishga tushiring.
3. Chiqqan `voice_id` ni `.env` dagi `ELEVENLABS_VOICE_ID` ga yozing.

Ovoz klonlash ElevenLabs'ning pullik tarifida ishlaydi. Faqat o'z ovozingizni yoki ruxsat bergan
odamning ovozini klonlang.

## Yordamchining ismi va xarakteri

`.env` faylida quyidagilarni o'zgartiring:

```
ASSISTANT_NAME=Lola
ASSISTANT_PERSONA=Samimiy, aqlli va hazilkash yordamchi.
```

## Eslatmalar

- Suhbat xotirasi kompyuter xotirasida saqlanadi: dastur qayta ishga tushsa, tozalanadi.
- Telegram'da ovozli xabar yuborish uchun `ffmpeg` kerak. U bo'lmasa, javob MP3 fayl bo'lib keladi.
- Claude ovozli suhbatda tez javob berishi uchun `effort: low` rejimida ishlaydi. Agar Claude
  so'rovni rad etsa, server avtomatik ravishda boshqa Claude modeliga o'tadi (`fallbacks`).
