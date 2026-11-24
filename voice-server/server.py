#!/usr/bin/env python3
"""
SlayderAi «Jonli ovoz» serveri — o'zbekcha ifodali nutq (Navoiy TTS, CosyVoice2 asosida).

Bot (PHP hosting) bu serverga matn yuboradi, server uni navbat bilan ovozga aylantiradi (OGG/Opus).
Bir nechta ovoz: voices/<kalit>.wav (10–25 soniyalik toza namuna) + voices/voices.json (nom, jins).

API (hammasi «Authorization: Bearer <VOICE_TOKEN>» bilan, /health dan tashqari):
  GET  /health                 -> {"ok":true,"ready":bool,"queue":n,"voices":[...]}
  GET  /voices                 -> {"ok":true,"voices":[{"key","name","gender"}],"styles":[...]}
  POST /tts   {"text","voice","style","speed"}      -> {"ok":true,"id":"...","position":n}
  GET  /job/<id>               -> {"ok":true,"status":"queued|working|done|error","position":n,"secs":x,"error":""}
  GET  /audio/<id>             -> audio/ogg (bir marta beriladi, keyin o'chiriladi)
  POST /voice {"key","name","gender","audio_b64","consent":true}  -> namuna qo'shish (video ham bo'lishi mumkin)
  POST /voice/delete {"key"}   -> namunani o'chirish

Ishga tushirish: install.sh (systemd xizmati sifatida). Sozlamalar — muhit o'zgaruvchilari:
  VOICE_TOKEN (majburiy), VOICE_PORT (8077), VOICE_HOME (shu papka), VOICE_THREADS (protsessor soni), VOICE_MAX_CHARS (700)
"""
import base64
import hmac
import json
import os
import queue
import random
import re
import subprocess
import sys
import threading
import time
import traceback
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

HOME = os.environ.get("VOICE_HOME") or os.path.dirname(os.path.abspath(__file__))
TOKEN = os.environ.get("VOICE_TOKEN", "")
PORT = int(os.environ.get("VOICE_PORT", "8077"))
BIND = os.environ.get("VOICE_BIND", "127.0.0.1")
THREADS = int(os.environ.get("VOICE_THREADS", str(os.cpu_count() or 2)))
MAX_CHARS = int(os.environ.get("VOICE_MAX_CHARS", "700"))
VOICES_DIR = os.path.join(HOME, "voices")
OUT_DIR = os.path.join(HOME, "out")
MODELS = os.path.join(HOME, "models")

# Uslublar: Navoiy kayfiyatlari + tezlik. «hikoya» — ertak/hikoya aytuvchi kabi iliq va shoshilmasdan.
STYLES = {
    "oddiy": {"emo": "xotirjam", "speed": 1.0, "name": "🙂 Oddiy"},
    "hikoya": {"emo": "mehribon", "speed": 0.92, "name": "📖 Hikoyachi"},
    "mehribon": {"emo": "mehribon", "speed": 1.0, "name": "🤗 Mehribon"},
    "xursand": {"emo": "xursand", "speed": 1.0, "name": "😊 Xursand"},
    "jiddiy": {"emo": "xotirjam", "speed": 0.96, "name": "🎓 Rasmiy"},
}

os.makedirs(VOICES_DIR, exist_ok=True)
os.makedirs(OUT_DIR, exist_ok=True)

JOBS = {}            # id -> dict
JOBS_LOCK = threading.Lock()
Q = queue.Queue()
STATE = {"ready": False, "error": "", "model": None, "emos": {}, "normalize": None, "loaded_at": 0}


def log(*a):
    print(time.strftime("%Y-%m-%d %H:%M:%S"), *a, flush=True)


# ---------------------------------------------------------------- ovozlar

def voices_meta():
    p = os.path.join(VOICES_DIR, "voices.json")
    try:
        with open(p, encoding="utf-8") as f:
            meta = json.load(f)
    except Exception:
        meta = {}
    out = []
    for k, v in meta.items():
        if os.path.isfile(os.path.join(VOICES_DIR, k + ".wav")):
            out.append({"key": k, "name": v.get("name", k), "gender": v.get("gender", ""), "added": v.get("added", 0)})
    return out


def save_meta(meta):
    p = os.path.join(VOICES_DIR, "voices.json")
    tmp = p + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(meta, f, ensure_ascii=False, indent=1)
    os.replace(tmp, p)


def add_voice(key, name, gender, raw: bytes):
    """Istalgan audio/video -> 16 kHz mono WAV, 3–25 soniya, shovqin darajasi tekislangan."""
    if not re.fullmatch(r"[a-z0-9_]{2,24}", key or ""):
        return "kalit noto'g'ri (a-z, 0-9, _; 2–24 belgi)"
    if len(raw) < 2000 or len(raw) > 60 * 1024 * 1024:
        return "fayl hajmi noto'g'ri"
    src = os.path.join(OUT_DIR, "up_" + uuid.uuid4().hex)
    dst = os.path.join(VOICES_DIR, key + ".wav")
    with open(src, "wb") as f:
        f.write(raw)
    try:
        # boshidagi 0.5 s tashlanadi, 25 s gacha olinadi, jimlik qirqiladi, ovoz balandligi tekislanadi
        cmd = ["ffmpeg", "-y", "-loglevel", "error", "-ss", "0.5", "-i", src, "-t", "25", "-vn", "-ac", "1", "-ar", "16000",
               "-af", "silenceremove=start_periods=1:start_threshold=-45dB,loudnorm=I=-18:TP=-2", dst + ".tmp.wav"]
        r = subprocess.run(cmd, capture_output=True, timeout=120)
        if r.returncode != 0 or not os.path.isfile(dst + ".tmp.wav"):
            return "ovozni ajratib bo'lmadi: " + r.stderr.decode("utf-8", "ignore")[-200:]
        secs = os.path.getsize(dst + ".tmp.wav") / 32000.0
        if secs < 3:
            os.remove(dst + ".tmp.wav")
            return "namuna juda qisqa (kamida 5–10 soniya toza nutq kerak)"
        os.replace(dst + ".tmp.wav", dst)
    finally:
        try:
            os.remove(src)
        except OSError:
            pass
    try:
        with open(os.path.join(VOICES_DIR, "voices.json"), encoding="utf-8") as f:
            meta = json.load(f)
    except Exception:
        meta = {}
    meta[key] = {"name": (name or key)[:40], "gender": gender if gender in ("m", "f") else "", "added": int(time.time())}
    save_meta(meta)
    return ""


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
        STATE.update(model=model, normalize=normalize, ready=True, loaded_at=int(time.time()))
        log("model tayyor", round(time.time() - t, 1), "s")
    except Exception as e:
        STATE["error"] = type(e).__name__ + ": " + str(e)[:300]
        log("MODEL XATO", traceback.format_exc())


def synth(job):
    import torch
    import torchaudio
    st = STYLES.get(job["style"]) or STYLES["oddiy"]
    ref = os.path.join(VOICES_DIR, job["voice"] + ".wav")
    if not os.path.isfile(ref):
        raise RuntimeError("ovoz topilmadi: " + job["voice"])
    emo = STATE["emos"].get(st["emo"]) or next(iter(STATE["emos"].values()))
    text = STATE["normalize"](job["text"], mode="infer")
    instr = emo["instruct"].strip() + "<|endofprompt|>"
    random.seed(1986)
    torch.manual_seed(1986)
    chunks = []
    with torch.inference_mode():
        for r in STATE["model"].inference_instruct2(text, instr, ref, stream=False, speed=float(job["speed"]) * st["speed"]):
            chunks.append(r["tts_speech"].detach().cpu())
    if not chunks:
        raise RuntimeError("model ovoz qaytarmadi")
    audio = torch.cat(chunks, dim=1)
    wav = os.path.join(OUT_DIR, job["id"] + ".wav")
    ogg = os.path.join(OUT_DIR, job["id"] + ".ogg")
    torchaudio.save(wav, audio, 24000)
    r = subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", wav, "-ac", "1", "-ar", "48000", "-c:a", "libopus", "-b:a", "48k", ogg],
                       capture_output=True, timeout=120)
    os.remove(wav)
    if r.returncode != 0:
        raise RuntimeError("ffmpeg: " + r.stderr.decode("utf-8", "ignore")[-150:])
    return audio.shape[-1] / 24000.0


def worker():
    load_model()
    while True:
        jid = Q.get()
        with JOBS_LOCK:
            job = JOBS.get(jid)
            if not job:
                continue
            job["status"] = "working"
            job["started"] = time.time()
        try:
            if not STATE["ready"]:
                raise RuntimeError("model yuklanmagan: " + STATE["error"])
            secs = synth(job)
            with JOBS_LOCK:
                job.update(status="done", secs=round(secs, 2), gen=round(time.time() - job["started"], 1), done_at=time.time())
            log("tayyor", jid, job["voice"], job["style"], len(job["text"]), "belgi ->", round(secs, 1), "s ovoz,", job["gen"], "s")
        except Exception as e:
            with JOBS_LOCK:
                job.update(status="error", error=str(e)[:300], done_at=time.time())
            log("xato", jid, traceback.format_exc()[-800:])


def janitor():
    while True:
        time.sleep(300)
        now = time.time()
        with JOBS_LOCK:
            for jid in list(JOBS):
                j = JOBS[jid]
                if j.get("done_at") and now - j["done_at"] > 3600:
                    JOBS.pop(jid, None)
                    try:
                        os.remove(os.path.join(OUT_DIR, jid + ".ogg"))
                    except OSError:
                        pass
        for f in os.listdir(OUT_DIR):
            p = os.path.join(OUT_DIR, f)
            if now - os.path.getmtime(p) > 7200:
                try:
                    os.remove(p)
                except OSError:
                    pass


def position(jid):
    with JOBS_LOCK:
        j = JOBS.get(jid)
        if not j or j["status"] != "queued":
            return 0
        return 1 + sum(1 for x in JOBS.values() if x["status"] in ("queued", "working") and x["created"] < j["created"])


# ---------------------------------------------------------------- HTTP

class H(BaseHTTPRequestHandler):
    server_version = "SlayderVoice/1.0"

    def log_message(self, fmt, *args):
        pass

    def send_json(self, code, obj):
        b = json.dumps(obj, ensure_ascii=False).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(b)))
        self.end_headers()
        self.wfile.write(b)

    def authed(self):
        h = self.headers.get("Authorization", "")
        ok = bool(TOKEN) and h.startswith("Bearer ") and hmac.compare_digest(h[7:].strip(), TOKEN)
        if not ok:
            time.sleep(0.5)
            self.send_json(401, {"ok": False, "error": "unauthorized"})
        return ok

    def body(self, limit=80 * 1024 * 1024):
        n = int(self.headers.get("Content-Length") or 0)
        if n <= 0 or n > limit:
            return None
        try:
            return json.loads(self.rfile.read(n).decode("utf-8"))
        except Exception:
            return None

    def do_GET(self):
        p = self.path.split("?")[0]
        if p == "/health":
            with JOBS_LOCK:
                qn = sum(1 for j in JOBS.values() if j["status"] in ("queued", "working"))
            return self.send_json(200, {"ok": True, "ready": STATE["ready"], "error": STATE["error"], "queue": qn,
                                        "voices": len(voices_meta()), "threads": THREADS})
        if not self.authed():
            return
        if p == "/voices":
            return self.send_json(200, {"ok": True, "voices": voices_meta(), "max_chars": MAX_CHARS,
                                        "styles": [{"key": k, "name": v["name"]} for k, v in STYLES.items()]})
        m = re.fullmatch(r"/job/([0-9a-f]{32})", p)
        if m:
            with JOBS_LOCK:
                j = JOBS.get(m.group(1))
                if not j:
                    return self.send_json(404, {"ok": False, "error": "not found"})
                out = {k: j.get(k) for k in ("status", "secs", "gen", "error")}
            out["position"] = position(m.group(1))
            out["ok"] = True
            return self.send_json(200, out)
        m = re.fullmatch(r"/audio/([0-9a-f]{32})", p)
        if m:
            f = os.path.join(OUT_DIR, m.group(1) + ".ogg")
            if not os.path.isfile(f):
                return self.send_json(404, {"ok": False, "error": "not found"})
            with open(f, "rb") as fh:
                data = fh.read()
            self.send_response(200)
            self.send_header("Content-Type", "audio/ogg")
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)
            try:
                os.remove(f)
            except OSError:
                pass
            return
        self.send_json(404, {"ok": False, "error": "not found"})

    def do_POST(self):
        p = self.path.split("?")[0]
        if not self.authed():
            return
        d = self.body()
        if not isinstance(d, dict):
            return self.send_json(400, {"ok": False, "error": "bad json"})
        if p == "/tts":
            text = str(d.get("text") or "").strip()
            voice = str(d.get("voice") or "")
            if not text or len(text) > MAX_CHARS:
                return self.send_json(400, {"ok": False, "error": "matn bo'sh yoki %d belgidan uzun" % MAX_CHARS})
            if not any(v["key"] == voice for v in voices_meta()):
                return self.send_json(400, {"ok": False, "error": "ovoz topilmadi"})
            style = str(d.get("style") or "oddiy")
            speed = max(0.7, min(1.3, float(d.get("speed") or 1.0)))
            with JOBS_LOCK:
                if sum(1 for j in JOBS.values() if j["status"] in ("queued", "working")) >= 50:
                    return self.send_json(503, {"ok": False, "error": "navbat to'la"})
                jid = uuid.uuid4().hex
                JOBS[jid] = {"id": jid, "text": text, "voice": voice, "style": style if style in STYLES else "oddiy",
                             "speed": speed, "status": "queued", "created": time.time()}
            Q.put(jid)
            return self.send_json(200, {"ok": True, "id": jid, "position": position(jid)})
        if p == "/voice":
            if d.get("consent") is not True:
                return self.send_json(400, {"ok": False, "error": "ovoz egasining roziligi tasdiqlanmagan"})
            try:
                raw = base64.b64decode(str(d.get("audio_b64") or ""), validate=True)
            except Exception:
                return self.send_json(400, {"ok": False, "error": "audio_b64 noto'g'ri"})
            err = add_voice(str(d.get("key") or ""), str(d.get("name") or ""), str(d.get("gender") or ""), raw)
            return self.send_json(400 if err else 200, {"ok": not err, "error": err, "voices": voices_meta()})
        if p == "/voice/delete":
            key = str(d.get("key") or "")
            if not re.fullmatch(r"[a-z0-9_]{2,24}", key):
                return self.send_json(400, {"ok": False, "error": "kalit noto'g'ri"})
            try:
                with open(os.path.join(VOICES_DIR, "voices.json"), encoding="utf-8") as f:
                    meta = json.load(f)
            except Exception:
                meta = {}
            meta.pop(key, None)
            save_meta(meta)
            try:
                os.remove(os.path.join(VOICES_DIR, key + ".wav"))
            except OSError:
                pass
            return self.send_json(200, {"ok": True, "voices": voices_meta()})
        self.send_json(404, {"ok": False, "error": "not found"})


def main():
    if len(TOKEN) < 24:
        sys.exit("VOICE_TOKEN kamida 24 belgi bo'lishi kerak (install.sh avtomatik yaratadi)")
    threading.Thread(target=worker, daemon=True).start()
    threading.Thread(target=janitor, daemon=True).start()
    srv = ThreadingHTTPServer((BIND, PORT), H)
    log("server %s:%d da ishga tushdi, protsessor oqimlari: %d" % (BIND, PORT, THREADS))
    srv.serve_forever()


if __name__ == "__main__":
    main()
