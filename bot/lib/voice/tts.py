# SlayderAi — matnni ovozga aylantirish (Microsoft Edge neyron ovozlari, edge-tts paketi orqali).
# Kirish (STDIN, JSON): {"text": "...", "voice": "uz-UZ-MadinaNeural", "rate": "+0%", "out": "/yo'l/fayl.mp3", "deadline": 60}
# Chiqish (STDOUT, JSON): {"ok": true, "bytes": N} yoki {"ok": false, "error": "..."}
import asyncio, json, os, sys

def out(d):
    sys.stdout.write(json.dumps(d, ensure_ascii=False))
    sys.stdout.flush()

async def run(cfg):
    import edge_tts
    proxy = cfg.get("proxy") or os.environ.get("HTTPS_PROXY") or os.environ.get("https_proxy") or None
    c = edge_tts.Communicate(cfg["text"], cfg.get("voice") or "uz-UZ-MadinaNeural",
                             rate=cfg.get("rate") or "+0%", proxy=proxy)
    n = 0
    tmp = cfg["out"] + ".part"
    with open(tmp, "wb") as f:
        async for ch in c.stream():
            if ch.get("type") == "audio" and ch.get("data"):
                f.write(ch["data"])
                n += len(ch["data"])
    if n < 1000:
        try:
            os.remove(tmp)
        except OSError:
            pass
        return {"ok": False, "error": "audio bo'sh"}
    os.replace(tmp, cfg["out"])
    return {"ok": True, "bytes": n}

def main():
    try:
        cfg = json.loads(sys.stdin.read() or "{}")
    except Exception:
        out({"ok": False, "error": "bad input"}); return
    if cfg.get("cmd") == "version":
        try:
            import edge_tts
            out({"ok": True, "version": getattr(edge_tts, "__version__", "?")})
        except Exception as e:
            out({"ok": False, "error": type(e).__name__ + ": " + str(e)[:150]})
        return
    if not (cfg.get("text") or "").strip() or not cfg.get("out"):
        out({"ok": False, "error": "matn yoki fayl yo'li yo'q"}); return
    try:
        res = asyncio.run(asyncio.wait_for(run(cfg), timeout=float(cfg.get("deadline", 60))))
    except asyncio.TimeoutError:
        res = {"ok": False, "error": "vaqt tugadi"}
    except Exception as e:
        res = {"ok": False, "error": type(e).__name__ + ": " + str(e)[:200]}
    out(res)

main()
