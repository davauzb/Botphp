#!/usr/bin/env python3
"""
SlayderAi «Jonli ovoz» ishchisi — o'zbekcha ifodali nutq (Navoiy TTS, CosyVoice2 asosida).

Ishchi o'zi BOTGA ulanadi (kiruvchi port, domen, HTTPS sertifikat kerak emas):
  1) har 5 daqiqada «hello» — holat va o'zidagi ovozlar ro'yxati;
  2) «next» — navbatdagi ishni so'raydi (bot 20 soniyagacha kutib turadi);
  3) ishni bajaradi va «result» bilan natijani (OGG ovoz) yuboradi — bot uni mijozga o'zi jo'natadi.
Ish turlari: tts (matn → ovoz), voice_add (namuna yuklab, eng toza ~11 s qismini olish), voice_delete.

Sozlamalar (systemd xizmatining muhit o'zgaruvchilari, install.sh yozadi):
  VOICE_BOT   — botning voice_api.php manzili, VOICE_TOKEN — ishchi tokeni (bot admin panelida yaratiladi),
  VOICE_HOME  — shu papka, VOICE_THREADS — protsessor oqimlari soni.
"""
import base64
import json
import os
import random
import re
import subprocess
import sys
import threading
import time
import traceback
import urllib.error
import urllib.request
import uuid

HOME = os.environ.get("VOICE_HOME") or os.path.dirname(os.path.abspath(__file__))
BOT = os.environ.get("VOICE_BOT", "")
TOKEN = os.environ.get("VOICE_TOKEN", "")
THREADS = int(os.environ.get("VOICE_THREADS", str(os.cpu_count() or 2)))
VOICES_DIR = os.path.join(HOME, "voices")
OUT_DIR = os.path.join(HOME, "out")
MODELS = os.path.join(HOME, "models")
VERSION = "2.0"

# Uslublar: Navoiy kayfiyatlari + tezlik. «hikoya» — ertak/hikoya aytuvchi kabi iliq va shoshilmasdan.
STYLES = {
    "oddiy": {"emo": "xotirjam", "speed": 1.0},
    "hikoya": {"emo": "mehribon", "speed": 0.92},
    "mehribon": {"emo": "mehribon", "speed": 1.0},
    "xursand": {"emo": "xursand", "speed": 1.0},
    "jiddiy": {"emo": "xotirjam", "speed": 0.96},
}

os.makedirs(VOICES_DIR, exist_ok=True)
os.makedirs(OUT_DIR, exist_ok=True)
STATE = {"ready": False, "error": "", "model": None, "emos": {}, "normalize": None}


def log(*a):
    print(time.strftime("%Y-%m-%d %H:%M:%S"), *a, flush=True)


# ---------------------------------------------------------------- bot bilan aloqa

def api(action, body=None, timeout=40):
    """Botning voice_api.php ga POST (token tanada — sarlavhalarni kesadigan hostinglarda ham ishlaydi)."""
    data = dict(body or {})
    data.update({"a": action, "t": TOKEN})
    req = urllib.request.Request(BOT, data=json.dumps(data).encode("utf-8"), method="POST",
                                 headers={"Content-Type": "application/json", "User-Agent": "SlayderVoice/" + VERSION})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        raw = r.read()
    try:
        return json.loads(raw.decode("utf-8"))
    except Exception:
        return {"ok": False, "error": "bad json: " + raw[:120].decode("utf-8", "ignore")}


# ---------------------------------------------------------------- ovozlar

def voices_meta():
    try:
        with open(os.path.join(VOICES_DIR, "voices.json"), encoding="utf-8") as f:
            meta = json.load(f)
    except Exception:
        meta = {}
    return [{"key": k, "name": v.get("name", k), "gender": v.get("gender", "")}
            for k, v in meta.items() if os.path.isfile(os.path.join(VOICES_DIR, k + ".wav"))]


def save_meta(meta):
    p = os.path.join(VOICES_DIR, "voices.json")
    with open(p + ".tmp", "w", encoding="utf-8") as f:
        json.dump(meta, f, ensure_ascii=False, indent=1)
    os.replace(p + ".tmp", p)


def load_meta():
    try:
        with open(os.path.join(VOICES_DIR, "voices.json"), encoding="utf-8") as f:
            return json.load(f)
    except Exception:
        return {}


def best_window(wav_path, win):
    """16 kHz mono WAV ichidan eng toza oynani topadi: (boshlanish, uzunlik) soniyada."""
    import array
    import math
    import wave
    with wave.open(wav_path, "rb") as w:
        n = w.getnframes()
        data = array.array("h", w.readframes(n))
    total = n / 16000.0
    if total <= win + 1:
        return (0.3 if total > win else 0.0), min(win, total)
    step = 1600                                  # 0.1 s
    lv = []
    for i in range(0, len(data) - step, step):
        s = 0
        for x in data[i:i + step]:
            s += x * x
        lv.append(10 * math.log10(s / step + 1e-9))
    per = int(win * 10)
    gmed = sorted(lv)[len(lv) // 2]               # butun yozuvdagi odatiy nutq darajasi
    best = (-1e9, 5)
    for st in range(5, len(lv) - per - 15, 5):    # 0.5 s qadam; boshidagi 0.5 s va oxirgi 1.5 s (yakun/musiqa) tashlanadi
        w = lv[st:st + per]
        if sum(1 for x in w if x > gmed - 6) / len(w) < 0.6:      # oyna asosan nutq bo'lsin
            continue
        longest_gap = cur = 0
        for x in w:
            cur = cur + 1 if x < gmed - 15 else 0
            longest_gap = max(longest_gap, cur)
        if longest_gap > 15:                      # 1.5 s dan uzun jimlik — yozuv uzilgan joy
            continue
        seg = sorted(w)
        floor, med = seg[len(seg) // 10], seg[len(seg) // 2]
        score = (med - floor) - abs(med - gmed) * 0.5      # pauzalar chuqur (fon kam) va ovoz balandligi odatiy
        if score > best[0]:
            best = (score, st)
    return best[1] / 10.0, win


def add_voice(key, name, gender, raw: bytes):
    """Istalgan audio/video -> eng toza ~11 s qism, 16 kHz mono WAV, ovoz balandligi tekislangan. Xato bo'lsa — matn."""
    if not re.fullmatch(r"[a-z0-9_]{2,24}", key or ""):
        return "kalit noto'g'ri"
    if len(raw) < 2000 or len(raw) > 60 * 1024 * 1024:
        return "fayl hajmi noto'g'ri"
    src = os.path.join(OUT_DIR, "up_" + uuid.uuid4().hex)
    full = src + ".full.wav"
    dst = os.path.join(VOICES_DIR, key + ".wav")
    with open(src, "wb") as f:
        f.write(raw)
    try:
        r = subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", src, "-t", "600", "-vn", "-ac", "1", "-ar", "16000", full],
                           capture_output=True, timeout=300)
        if r.returncode != 0 or not os.path.isfile(full):
            return "ovozni ajratib bo'lmadi: " + r.stderr.decode("utf-8", "ignore")[-200:]
        total = os.path.getsize(full) / 32000.0
        if total < 4:
            return "namuna juda qisqa (kamida 8–10 soniya toza nutq kerak)"
        # Uzun namuna modelni sekinlashtiradi (20 s ≈ 2× sekin) — 11 s sifat va tezlik muvozanati
        start, win = best_window(full, 11.0)
        r = subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-ss", "%.2f" % start, "-t", "%.2f" % win, "-i", full,
                            "-af", "loudnorm=I=-18:TP=-2", "-ar", "16000", dst + ".tmp.wav"], capture_output=True, timeout=120)
        if r.returncode != 0 or not os.path.isfile(dst + ".tmp.wav"):
            return "namunani qirqib bo'lmadi: " + r.stderr.decode("utf-8", "ignore")[-200:]
        os.replace(dst + ".tmp.wav", dst)
        log("ovoz qo'shildi", key, "%.1f s dan %.1f s (jami %.0f s)" % (start, win, total))
    finally:
        for f in (src, full):
            try:
                os.remove(f)
            except OSError:
                pass
    meta = load_meta()
    meta[key] = {"name": (name or key)[:40], "gender": gender if gender in ("m", "f") else "", "added": int(time.time())}
    save_meta(meta)
    return ""


def delete_voice(key):
    meta = load_meta()
    meta.pop(key, None)
    save_meta(meta)
    try:
        os.remove(os.path.join(VOICES_DIR, key + ".wav"))
    except OSError:
        pass


# ---------------------------------------------------------------- model

def load_model():
    try:
        sys.path.insert(0, os.path.join(HOME, "cosyvoice_src"))
        sys.path.insert(0, os.path.join(HOME, "cosyvoice_src", "third_party", "Matcha-TTS"))
        sys.path.insert(0, os.path.join(HOME, "navoiy"))
        import torch
        torch.set_num_threads(THREADS)
        from cosyvoice.cli.cosyvoice import CosyVoice2
        from uztts.normalize import normalize
        t = time.time()
        model = CosyVoice2(os.path.join(MODELS, "CosyVoice2-0.5B"), load_jit=False, load_trt=False, fp16=False)
        st = torch.load(os.path.join(MODELS, "navoiy", "emotion_600h_joint.pt"), map_location="cpu", weights_only=True)
        for k in ("state_dict", "model", "llm"):
            if isinstance(st, dict) and isinstance(st.get(k), dict):
                st = st[k]
        model.model.llm.load_state_dict(st, strict=False)
        model.model.llm.eval()
        with open(os.path.join(MODELS, "navoiy", "emotions_40h.json"), encoding="utf-8") as f:
            STATE["emos"] = {e["uz"]: e for e in json.load(f)}
        STATE.update(model=model, normalize=normalize, ready=True)
        log("model tayyor", round(time.time() - t, 1), "s")
    except Exception as e:
        STATE["error"] = type(e).__name__ + ": " + str(e)[:300]
        log("MODEL XATO", traceback.format_exc())


def synth(job):
    """Matn → OGG/Opus fayl. Qaytadi: (fayl yo'li, soniya)."""
    import torch
    import torchaudio
    st = STYLES.get(job.get("style")) or STYLES["oddiy"]
    ref = os.path.join(VOICES_DIR, str(job.get("voice")) + ".wav")
    if not os.path.isfile(ref):
        raise RuntimeError("ovoz topilmadi: " + str(job.get("voice")))
    emo = STATE["emos"].get(st["emo"]) or next(iter(STATE["emos"].values()))
    text = STATE["normalize"](str(job["text"]), mode="infer")
    instr = emo["instruct"].strip() + "<|endofprompt|>"
    random.seed(1986)
    torch.manual_seed(1986)
    chunks = []
    speed = max(0.7, min(1.3, float(job.get("speed") or 1.0))) * st["speed"]
    with torch.inference_mode():
        for r in STATE["model"].inference_instruct2(text, instr, ref, stream=False, speed=speed):
            chunks.append(r["tts_speech"].detach().cpu())
    if not chunks:
        raise RuntimeError("model ovoz qaytarmadi")
    audio = torch.cat(chunks, dim=1)
    base = os.path.join(OUT_DIR, uuid.uuid4().hex)
    torchaudio.save(base + ".wav", audio, 24000)
    r = subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", base + ".wav", "-ac", "1", "-ar", "48000", "-c:a", "libopus", "-b:a", "48k", base + ".ogg"],
                       capture_output=True, timeout=120)
    os.remove(base + ".wav")
    if r.returncode != 0:
        raise RuntimeError("ffmpeg: " + r.stderr.decode("utf-8", "ignore")[-150:])
    return base + ".ogg", audio.shape[-1] / 24000.0


# ---------------------------------------------------------------- asosiy sikl

def hello():
    return api("hello", {"ready": STATE["ready"], "error": STATE["error"], "voices": voices_meta(), "version": VERSION,
                         "threads": THREADS, "cpu": os.cpu_count() or 0}, timeout=30)


def handle(job):
    kind = job.get("type")
    jid = job.get("id")
    if kind == "tts":
        t = time.time()
        f, secs = synth(job)
        with open(f, "rb") as fh:
            b64 = base64.b64encode(fh.read()).decode()
        os.remove(f)
        log("tayyor #%s" % jid, job.get("voice"), job.get("style"), len(str(job.get("text"))), "belgi ->",
            round(secs, 1), "s ovoz,", round(time.time() - t, 1), "s")
        return {"ok": True, "audio_b64": b64, "secs": round(secs, 2), "gen": round(time.time() - t, 1)}
    if kind == "voice_add":
        d = api("sample", {"id": jid}, timeout=180)
        if not d.get("ok") or not d.get("b64"):
            return {"ok": False, "error": "namunani yuklab bo'lmadi: " + str(d.get("error", ""))}
        err = add_voice(str(job.get("key")), str(job.get("name")), str(job.get("gender")), base64.b64decode(d["b64"]))
        return {"ok": not err, "error": err, "voices": voices_meta()}
    if kind == "voice_delete":
        delete_voice(str(job.get("key")))
        return {"ok": True, "voices": voices_meta()}
    return {"ok": False, "error": "noma'lum ish turi"}


def main():
    if not BOT or len(TOKEN) < 24:
        sys.exit("VOICE_BOT va VOICE_TOKEN sozlanmagan (install.sh yozadi)")
    threading.Thread(target=load_model, daemon=True).start()
    log("ishchi ishga tushdi:", BOT, "· oqimlar:", THREADS)
    last_hello = 0
    told_ready = False
    backoff = 5
    while True:
        try:
            # holat: har 5 daqiqada va model tayyor bo'lgan zahoti
            if time.time() - last_hello > 300 or (STATE["ready"] and not told_ready):
                h = hello()
                last_hello = time.time()
                told_ready = STATE["ready"]
                if not h.get("ok"):
                    log("bot javobi:", h.get("error"))
                    time.sleep(30)
                    continue
            if not STATE["ready"]:
                time.sleep(3)                                    # model yuklanmoqda (~1 daqiqa) yoki xato (hello'da xabar qilinadi)
                continue
            d = api("next", {"ready": STATE["ready"]}, timeout=40)
            backoff = 5
            job = d.get("job")
            if not job:
                continue                                         # bot 20 s kutdi, ish yo'q — qayta so'raymiz
            try:
                res = handle(job)
            except Exception as e:
                log("xato #%s" % job.get("id"), traceback.format_exc()[-600:])
                res = {"ok": False, "error": str(e)[:300]}
            res["id"] = job.get("id")
            for attempt in range(3):
                try:
                    api("result", res, timeout=120)
                    break
                except Exception as e:
                    log("natija yuborilmadi:", e)
                    time.sleep(5 * (attempt + 1))
        except urllib.error.HTTPError as e:
            log("bot HTTP", e.code)
            time.sleep(min(300, backoff))
            backoff *= 2
        except Exception as e:
            log("aloqa xatosi:", str(e)[:200])
            time.sleep(min(300, backoff))
            backoff *= 2


if __name__ == "__main__":
    main()
