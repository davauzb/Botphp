#!/usr/bin/env bash
# SlayderAi «Jonli ovoz» ishchisini o'rnatish — Ubuntu 22.04/24.04, x86_64 yoki ARM (Oracle Cloud bepul Ampere serveri).
# Odatda qo'lda ishga tushirilmaydi: admin paneldagi «server skripti» (cloud-init) uni botdan yuklab, o'zi ishga tushiradi.
# Kerakli muhit o'zgaruvchilari: VOICE_BOT (botning voice_api.php manzili), VOICE_TOKEN (ishchi tokeni).
# Qayta ishga tushirsa ham bo'ladi (yangilash): ovozlar saqlanadi.
set -uo pipefail

: "${VOICE_BOT:?VOICE_BOT kerak}" "${VOICE_TOKEN:?VOICE_TOKEN kerak}"
DIR=/opt/slayder-voice
COSY_REV=074ca6dc9e80a2f424f1f74b48bdd7d3fea531cc
HF=https://huggingface.co
LOG=/var/log/slayder-install.log

# Bosqichni botga xabar qilish (admin panelda ko'rinadi)
report() {
  local body
  body=$(printf '{"a":"progress","t":"%s","step":%s,"msg":"%s","err":%s}' "$VOICE_TOKEN" "$1" "$2" "${3:-false}")
  curl -fsS -m 20 -X POST -H 'Content-Type: application/json' -d "$body" "$VOICE_BOT" >/dev/null 2>&1 || true
}
fail() {
  local tail_log
  tail_log=$(tail -c 300 "$LOG" 2>/dev/null | tr -d '"\\\r' | tr '\n' ' ')
  report "${STEP:-0}" "XATO: $1 — $tail_log" true
  echo "XATO: $1"; exit 1
}
step() { STEP=$1; echo "[$1/7] $2"; report "$1" "$2"; }
fetch() {   # botdan ishchi fayllarini olish
  curl -fsS -m 120 -X POST -H 'Content-Type: application/json' \
    -d "$(printf '{"a":"file","t":"%s","f":"%s"}' "$VOICE_TOKEN" "$1")" "$VOICE_BOT" -o "$2" || fail "$1 yuklanmadi"
}
dl() {
  [ -s "$2" ] && return 0
  curl -fsSL --retry 3 "$1" -o "$2.part" || fail "yuklanmadi: $1"
  mv -f "$2.part" "$2"
}

[ "$(id -u)" = 0 ] || { echo "root sifatida ishga tushiring"; exit 1; }
ARCH=$(uname -m)
case "$ARCH" in x86_64|aarch64) ;; *) report 0 "Qo'llab-quvvatlanmaydigan protsessor: $ARCH" true; exit 1;; esac
RAM_GB=$(awk '/MemTotal/{print int($2/1024/1024)}' /proc/meminfo)
if [ "$RAM_GB" -lt 6 ]; then
  report 0 "Xotira ${RAM_GB} GB — kamida 8 GB kerak. Oracle'da Ampere A1 (4 OCPU, 24 GB) tanlang." true
  exit 1
fi

step 1 "Tizim paketlari o'rnatilmoqda"
export DEBIAN_FRONTEND=noninteractive
for i in 1 2 3 4 5; do                       # cloud-init paytida apt band bo'lishi mumkin
  apt-get update -qq && apt-get install -y -qq git curl ffmpeg build-essential ca-certificates >/dev/null && break
  sleep 20
done
command -v ffmpeg >/dev/null || fail "ffmpeg o'rnatilmadi"
mkdir -p "$DIR"/{models/CosyVoice2-0.5B/CosyVoice-BlankEN,models/navoiy,navoiy/uztts,voices,out}
fetch worker.py "$DIR/worker.py"
fetch requirements-lock.txt "$DIR/requirements-lock.txt"

step 2 "Python 3.12 yuklanmoqda"
if [ ! -x "$DIR/python/bin/python3" ]; then
  PY_URL=$(curl -fsSL https://api.github.com/repos/astral-sh/python-build-standalone/releases/latest \
    | grep -o "https://[^\"]*cpython-3\.12\.[0-9]*+[0-9]*-${ARCH}-unknown-linux-gnu-install_only\.tar\.gz" | head -1)
  [ -n "$PY_URL" ] || PY_URL="https://github.com/astral-sh/python-build-standalone/releases/download/20241016/cpython-3.12.7%2B20241016-${ARCH}-unknown-linux-gnu-install_only.tar.gz"
  curl -fsSL "$PY_URL" -o /tmp/py.tgz || fail "Python yuklanmadi"
  tar -xzf /tmp/py.tgz -C "$DIR" && rm -f /tmp/py.tgz
fi
[ -x "$DIR/venv/bin/python" ] || "$DIR/python/bin/python3" -m venv "$DIR/venv" || fail "venv yaratilmadi"
PIP="$DIR/venv/bin/pip install -q --disable-pip-version-check --no-warn-script-location"

step 3 "PyTorch va kutubxonalar o'rnatilmoqda (5–15 daqiqa)"
if [ "$ARCH" = x86_64 ]; then
  $PIP torch==2.3.1 torchaudio==2.3.1 --index-url https://download.pytorch.org/whl/cpu || fail "PyTorch o'rnatilmadi"
else
  $PIP torch==2.3.1 torchaudio==2.3.1 || fail "PyTorch o'rnatilmadi"
fi
$PIP --no-deps -r "$DIR/requirements-lock.txt" || fail "kutubxonalar o'rnatilmadi"
$PIP --no-deps pyworld==0.3.4 || fail "pyworld o'rnatilmadi"
$PIP --no-deps --no-build-isolation openai-whisper==20250625 || fail "whisper o'rnatilmadi"
"$DIR/venv/bin/python" -c "import torch, torchaudio, numpy; assert numpy.__version__.startswith('1.26')" || fail "kutubxonalar mos emas"

step 4 "CosyVoice runtime kodi yuklanmoqda"
[ -d "$DIR/cosyvoice_src/.git" ] || git clone -q https://github.com/FunAudioLLM/CosyVoice.git "$DIR/cosyvoice_src" || fail "CosyVoice yuklanmadi"
git -C "$DIR/cosyvoice_src" fetch -q origin "$COSY_REV" 2>/dev/null || true
git -C "$DIR/cosyvoice_src" checkout -q "$COSY_REV" || fail "CosyVoice versiyasi topilmadi"
git -C "$DIR/cosyvoice_src" submodule update -q --init --recursive || fail "Matcha-TTS yuklanmadi"

step 5 "Model fayllari yuklanmoqda (~6 GB)"
B="$HF/FunAudioLLM/CosyVoice2-0.5B/resolve/main"
for f in cosyvoice2.yaml campplus.onnx speech_tokenizer_v2.onnx flow.pt hift.pt llm.pt \
         CosyVoice-BlankEN/config.json CosyVoice-BlankEN/generation_config.json CosyVoice-BlankEN/merges.txt \
         CosyVoice-BlankEN/tokenizer_config.json CosyVoice-BlankEN/vocab.json CosyVoice-BlankEN/model.safetensors; do
  dl "$B/$f" "$DIR/models/CosyVoice2-0.5B/$f"
done
N="$HF/aisha-org/navoiy-tts/resolve/main"
dl "$N/emotion_600h_joint.pt" "$DIR/models/navoiy/emotion_600h_joint.pt"
dl "$N/emotions_40h.json" "$DIR/models/navoiy/emotions_40h.json"
dl "$N/uztts/normalize.py" "$DIR/navoiy/uztts/normalize.py"
dl "$N/uztts/__init__.py" "$DIR/navoiy/uztts/__init__.py"
dl "$N/LICENSE" "$DIR/models/navoiy/LICENSE"

step 6 "Boshlang'ich ovoz tayyorlanmoqda"
if [ ! -s "$DIR/voices/voices.json" ]; then
  dl "$N/demo/calm_intro.wav" "$DIR/out/ayol_src.wav"
  ffmpeg -y -loglevel error -i "$DIR/out/ayol_src.wav" -ac 1 -ar 16000 "$DIR/voices/ayol.wav" && rm -f "$DIR/out/ayol_src.wav"
  printf '{"ayol": {"name": "Ayol ovozi", "gender": "f", "added": %s}}\n' "$(date +%s)" > "$DIR/voices/voices.json"
fi

step 7 "Xizmat ishga tushirilmoqda"
id -u slayder >/dev/null 2>&1 || useradd -r -s /usr/sbin/nologin -d "$DIR" slayder
umask 077
printf 'VOICE_BOT=%s\nVOICE_TOKEN=%s\n' "$VOICE_BOT" "$VOICE_TOKEN" > "$DIR/env"
umask 022
chown -R slayder:slayder "$DIR"
chmod 600 "$DIR/env"
cat > /etc/systemd/system/slayder-voice.service <<EOF
[Unit]
Description=SlayderAi jonli ovoz ishchisi (Navoiy TTS)
After=network-online.target
Wants=network-online.target

[Service]
User=slayder
WorkingDirectory=$DIR
EnvironmentFile=$DIR/env
Environment=VOICE_HOME=$DIR PYTHONUNBUFFERED=1
ExecStart=$DIR/venv/bin/python -I $DIR/worker.py
Restart=always
RestartSec=10
Nice=5

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable -q slayder-voice
systemctl restart slayder-voice || fail "xizmat ishga tushmadi"
report 8 "O'rnatildi — model yuklanmoqda (1–2 daqiqa)"
echo "✅ Tayyor. Holat: admin panel → Tizim → «⭐ Jonli ovoz»"
