"""Telegram bot: ovozli yoki matnli xabarga o'zbekcha ovoz bilan javob beradi.

Ishga tushirish:  python telegram_bot.py
"""
import asyncio
import io
import logging

from telegram import InlineKeyboardButton, InlineKeyboardMarkup, Update
from telegram.constants import ChatAction
from telegram.ext import (Application, CallbackQueryHandler, CommandHandler, ContextTypes,
                          MessageHandler, filters)

from core import assistant, config, llm, voice

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("bot")


def _provider(context: ContextTypes.DEFAULT_TYPE) -> str:
    return context.chat_data.get("provider") or llm.default_provider()


async def start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    await update.message.reply_text(
        f"Assalomu alaykum! Men {config.ASSISTANT_NAME}, sizning ovozli yordamchingizman.\n\n"
        "Menga ovozli xabar yoki matn yuboring, men o'zbekcha ovoz bilan javob beraman.\n\n"
        "/model - AI modelini tanlash (Claude, ChatGPT, Gemini)\n"
        "/yangi - suhbatni yangidan boshlash"
    )


async def choose_model(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    providers = llm.available_providers()
    if not providers:
        await update.message.reply_text("Hech qaysi AI kaliti sozlanmagan (.env).")
        return
    current = _provider(context)
    buttons = [[InlineKeyboardButton(("✅ " if k == current else "") + title, callback_data=f"p:{k}")]
               for k, title in providers.items()]
    await update.message.reply_text("Qaysi AI bilan gaplashamiz?", reply_markup=InlineKeyboardMarkup(buttons))


async def on_model_chosen(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    query = update.callback_query
    key = query.data.split(":", 1)[1]
    context.chat_data["provider"] = key
    await query.answer()
    await query.edit_message_text(f"Tanlandi: {llm.PROVIDERS[key][0]}")


async def reset(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    assistant.memory.clear(str(update.effective_chat.id))
    await update.message.reply_text("Suhbat tozalandi. Yangidan boshlaymiz!")


async def _respond(update: Update, context: ContextTypes.DEFAULT_TYPE, audio: bytes | None, text: str | None):
    chat_id = str(update.effective_chat.id)
    await context.bot.send_chat_action(update.effective_chat.id, ChatAction.RECORD_VOICE)
    try:
        if audio is not None:
            reply = await asyncio.to_thread(assistant.ask_by_voice, chat_id, audio, "voice.ogg", _provider(context))
        else:
            reply = await asyncio.to_thread(assistant.ask, chat_id, text, _provider(context))
    except (llm.LLMError, voice.VoiceError) as e:
        await update.message.reply_text(f"⚠️ {e}")
        return

    caption = reply.text if audio is None else f"🗣 {reply.user_text}\n\n{reply.text}"
    if reply.audio_mp3:
        ogg = await asyncio.to_thread(voice.mp3_to_ogg_opus, reply.audio_mp3)
        if ogg:
            await update.message.reply_voice(io.BytesIO(ogg), caption=caption[:1024])
        else:
            await update.message.reply_audio(io.BytesIO(reply.audio_mp3), caption=caption[:1024],
                                             filename="javob.mp3")
        if len(caption) > 1024:
            await update.message.reply_text(caption)
    else:
        await update.message.reply_text(caption + (f"\n\n(Ovoz: {reply.voice_error})" if reply.voice_error else ""))


async def on_voice(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    media = update.message.voice or update.message.audio
    file = await media.get_file()
    data = bytes(await file.download_as_bytearray())
    await _respond(update, context, data, None)


async def on_text(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    await _respond(update, context, None, update.message.text)


def main() -> None:
    if not config.TELEGRAM_BOT_TOKEN:
        raise SystemExit("TELEGRAM_BOT_TOKEN .env faylida yo'q. @BotFather'dan token oling.")
    app = Application.builder().token(config.TELEGRAM_BOT_TOKEN).build()
    app.add_handler(CommandHandler("start", start))
    app.add_handler(CommandHandler("model", choose_model))
    app.add_handler(CommandHandler("yangi", reset))
    app.add_handler(CallbackQueryHandler(on_model_chosen, pattern=r"^p:"))
    app.add_handler(MessageHandler(filters.VOICE | filters.AUDIO, on_voice))
    app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, on_text))
    log.info("%s boti ishga tushdi", config.ASSISTANT_NAME)
    app.run_polling()


if __name__ == "__main__":
    main()
