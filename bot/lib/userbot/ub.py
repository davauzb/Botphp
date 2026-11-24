# SlayderAi userbot (Telethon): HUMO bildirishnomalarini o'qish uchun.
# Buyruq va maxfiy qiymatlar STDIN orqali JSON bilan beriladi (jarayonlar ro'yxatida ko'rinmasin), natija — bitta JSON qator.
import sys, json, asyncio

def out(d):
    sys.stdout.write(json.dumps(d, ensure_ascii=False) + "\n")
    sys.stdout.flush()

async def main():
    try:
        cfg = json.loads(sys.stdin.read() or "{}")
    except Exception:
        out({"ok": False, "error": "bad input"}); return
    try:
        await asyncio.wait_for(run(cfg), timeout=float(cfg.get("deadline", 30)))
    except asyncio.TimeoutError:
        out({"ok": False, "error": "Telegram serveriga ulanib bo'lmadi (vaqt tugadi)"})

async def run(cfg):
    try:
        from telethon import TelegramClient
        from telethon import errors
    except Exception as e:
        out({"ok": False, "error": "telethon yo'q: " + str(e)[:120]}); return
    c = TelegramClient(cfg["session"], int(cfg["api_id"]), cfg["api_hash"],
                       device_model="SlayderAi Pay", system_version="server", app_version="1.0", lang_code="uz",
                       connection_retries=1, retry_delay=1, timeout=10, request_retries=2)
    cmd = cfg.get("cmd")
    try:
        await c.connect()
        if cmd == "status":
            if await c.is_user_authorized():
                me = await c.get_me()
                out({"ok": True, "auth": True, "name": (me.first_name or "") + (" " + me.last_name if me.last_name else ""), "id": me.id})
            else:
                out({"ok": True, "auth": False})
        elif cmd == "send_code":
            r = await c.send_code_request(cfg["phone"])
            out({"ok": True, "hash": r.phone_code_hash})
        elif cmd == "sign_in":
            try:
                await c.sign_in(phone=cfg["phone"], code=cfg["code"], phone_code_hash=cfg["hash"])
            except errors.SessionPasswordNeededError:
                out({"ok": False, "need_password": True}); return
            me = await c.get_me()
            out({"ok": True, "name": me.first_name or "", "id": me.id})
        elif cmd == "password":
            await c.sign_in(password=cfg["password"])
            me = await c.get_me()
            out({"ok": True, "name": me.first_name or "", "id": me.id})
        elif cmd == "fetch":
            if not await c.is_user_authorized():
                out({"ok": False, "error": "not_authorized"}); return
            ent = await c.get_entity(cfg["peer"])
            if not getattr(ent, "bot", False):
                out({"ok": False, "error": "peer is not a bot"}); return
            msgs = []
            async for m in c.iter_messages(ent, limit=int(cfg.get("limit", 15)), min_id=int(cfg.get("min_id", 0))):
                if m.out or m.fwd_from is not None or m.via_bot_id:
                    continue
                if m.sender_id != ent.id:
                    continue
                msgs.append({"id": m.id, "date": int(m.date.timestamp()), "text": m.message or ""})
            out({"ok": True, "peer_id": ent.id, "messages": msgs})
        elif cmd == "fetch_multi":
            # bir ulanishda bir nechta bank botini o'qish: {"peers": {"@bot": min_id, ...}}
            if not await c.is_user_authorized():
                out({"ok": False, "error": "not_authorized"}); return
            res = {}
            for peer, min_id in (cfg.get("peers") or {}).items():
                try:
                    ent = await c.get_entity(peer)
                    if not getattr(ent, "bot", False):
                        res[peer] = {"ok": False, "error": "peer is not a bot"}; continue
                    msgs = []
                    async for m in c.iter_messages(ent, limit=int(cfg.get("limit", 20)), min_id=int(min_id or 0)):
                        if m.out or m.fwd_from is not None or m.via_bot_id or m.sender_id != ent.id:
                            continue
                        msgs.append({"id": m.id, "date": int(m.date.timestamp()), "text": m.message or ""})
                    res[peer] = {"ok": True, "peer_id": ent.id, "messages": msgs}
                except errors.FloodWaitError:
                    raise
                except Exception as e:
                    res[peer] = {"ok": False, "error": type(e).__name__ + ": " + str(e)[:120]}
            out({"ok": True, "peers": res})
        elif cmd == "logout":
            await c.log_out()
            out({"ok": True})
        else:
            out({"ok": False, "error": "unknown cmd"})
    except errors.FloodWaitError as e:
        out({"ok": False, "error": "flood", "wait": e.seconds})
    except (errors.PhoneCodeInvalidError, errors.PhoneCodeEmptyError):
        out({"ok": False, "error": "Kod noto'g'ri"})
    except errors.PhoneCodeExpiredError:
        out({"ok": False, "error": "Kod eskirgan — qaytadan kod so'rang"})
    except errors.PasswordHashInvalidError:
        out({"ok": False, "error": "2FA parol noto'g'ri"})
    except errors.PhoneNumberInvalidError:
        out({"ok": False, "error": "Telefon raqam noto'g'ri (+998901234567 ko'rinishida)"})
    except errors.ApiIdInvalidError:
        out({"ok": False, "error": "api_id / api_hash noto'g'ri"})
    except Exception as e:
        out({"ok": False, "error": type(e).__name__ + ": " + str(e)[:200]})
    finally:
        try:
            await c.disconnect()
        except Exception:
            pass

asyncio.run(main())
