#!/usr/bin/env bash
# SlayderAi «Jonli ovoz» serverini o'rnatish (Ubuntu 22.04/24.04, x86_64 yoki ARM/aarch64 — masalan Oracle Cloud bepul serveri).
# Ishlatish (root sifatida):   sudo bash install.sh
# Qayta ishga tushirsa ham bo'ladi (yangilash): mavjud token va ovozlar saqlanadi.
set -euo pipefail

DIR=/opt/slayder-voice
PORT=8077
COSY_REV=074ca6dc9e80a2f424f1f74b48bdd7d3fea531cc
HF=https://huggingface.co
SRC="$(cd "$(dirname "$0")" && pwd)"

[ "$(id -u)" = 0 ] || { echo "root sifatida ishga tushiring: sudo bash install.sh"; exit 1; }
ARCH=$(uname -m)
case "$ARCH" in x86_64|aarch64) ;; *) echo "Qo'llab-quvvatlanmaydigan protsessor: $ARCH"; exit 1;; esac
RAM_GB=$(awk '/MemTotal/{print int($2/1024/1024)}' /proc/meminfo)
[ "$RAM_GB" -ge 6 ] || echo "⚠️  Xotira ${RAM_GB} GB — kamida 8 GB tavsiya etiladi (model ~5 GB egallaydi)."

echo "[1/7] Tizim paketlari…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq git curl ffmpeg build-essential ca-certificates iptables-persistent >/dev/null || \
  apt-get install -y -qq git curl ffmpeg build-essential ca-certificates >/dev/null

mkdir -p "$DIR"/{models/CosyVoice2-0.5B/CosyVoice-BlankEN,models/navoiy,navoiy/uztts,voices,out}
cp "$SRC/server.py" "$SRC/requirements-lock.txt" "$DIR/"

echo "[2/7] Python 3.12 (alohida, tizimga tegmaydi)…"
if [ ! -x "$DIR/python/bin/python3" ]; then
  PY_URL=$(curl -fsSL https://api.github.com/repos/astral-sh/python-build-standalone/releases/latest \
    | grep -o "https://[^\"]*cpython-3\.12\.[0-9]*+[0-9]*-${ARCH}-unknown-linux-gnu-install_only\.tar\.gz" | head -1)
  [ -n "$PY_URL" ] || PY_URL="https://github.com/astral-sh/python-build-standalone/releases/download/20241016/cpython-3.12.7%2B20241016-${ARCH}-unknown-linux-gnu-install_only.tar.gz"
  curl -fsSL "$PY_URL" -o /tmp/py.tgz && tar -xzf /tmp/py.tgz -C "$DIR" && rm -f /tmp/py.tgz
fi
[ -x "$DIR/venv/bin/python" ] || "$DIR/python/bin/python3" -m venv "$DIR/venv"
PIP="$DIR/venv/bin/pip install -q --disable-pip-version-check --no-warn-script-location"

echo "[3/7] PyTorch (CPU) va kutubxonalar (5–15 daqiqa)…"
if [ "$ARCH" = x86_64 ]; then
  $PIP torch==2.3.1 torchaudio==2.3.1 --index-url https://download.pytorch.org/whl/cpu
else
  $PIP torch==2.3.1 torchaudio==2.3.1          # ARM uchun PyPI'dagi g'ildiraklar CPU versiyasi
fi
$PIP --no-deps -r "$DIR/requirements-lock.txt"
$PIP --no-deps pyworld==0.3.4
$PIP --no-deps --no-build-isolation openai-whisper==20250625
"$DIR/venv/bin/python" -c "import torch, torchaudio, numpy; assert numpy.__version__.startswith('1.26'); print('  torch', torch.__version__)"

echo "[4/7] CosyVoice runtime kodi…"
if [ ! -d "$DIR/cosyvoice_src/.git" ]; then
  git clone -q https://github.com/FunAudioLLM/CosyVoice.git "$DIR/cosyvoice_src"
fi
git -C "$DIR/cosyvoice_src" fetch -q origin "$COSY_REV" 2>/dev/null || true
git -C "$DIR/cosyvoice_src" checkout -q "$COSY_REV"
git -C "$DIR/cosyvoice_src" submodule update -q --init --recursive

echo "[5/7] Model fayllari (~6 GB, bir marta)…"
dl() {
  [ -s "$2" ] && return 0
  curl -fsSL --retry 3 "$1" -o "$2.part" || { echo "Yuklanmadi: $1"; exit 1; }
  mv -f "$2.part" "$2"
}
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
# Boshlang'ich ayol ovozi — Navoiy modelining o'z namunasi (Apache-2.0)
if [ ! -s "$DIR/voices/voices.json" ]; then
  dl "$N/demo/calm_intro.wav" "$DIR/out/ayol_src.wav"
  ffmpeg -y -loglevel error -i "$DIR/out/ayol_src.wav" -ac 1 -ar 16000 "$DIR/voices/ayol.wav" && rm -f "$DIR/out/ayol_src.wav"
  printf '{"ayol": {"name": "Ayol ovozi", "gender": "f", "added": %s}}\n' "$(date +%s)" > "$DIR/voices/voices.json"
fi

echo "[6/7] Xizmat (systemd)…"
id -u slayder >/dev/null 2>&1 || useradd -r -s /usr/sbin/nologin -d "$DIR" slayder
[ -s "$DIR/token" ] || "$DIR/venv/bin/python" -c "import secrets;print('sv_'+secrets.token_hex(24))" > "$DIR/token"
printf 'VOICE_TOKEN=%s\n' "$(cat "$DIR/token")" > "$DIR/env"
chmod 600 "$DIR/token" "$DIR/env"
chown -R slayder:slayder "$DIR"
cat > /etc/systemd/system/slayder-voice.service <<EOF
[Unit]
Description=SlayderAi jonli ovoz serveri (Navoiy TTS)
After=network-online.target

[Service]
User=slayder
WorkingDirectory=$DIR
EnvironmentFile=$DIR/env
ExecStart=$DIR/venv/bin/python -I $DIR/server.py
Environment=VOICE_HOME=$DIR VOICE_PORT=$PORT VOICE_BIND=127.0.0.1 PYTHONUNBUFFERED=1
Restart=always
RestartSec=5
MemoryMax=90%
Nice=5

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable -q slayder-voice
systemctl restart slayder-voice

echo "[7/7] HTTPS (Caddy + bepul sslip.io domeni)…"
IP=$(curl -fsS https://api.ipify.org || true)
if [ -n "$IP" ]; then
  HOST="$(echo "$IP" | tr . -).sslip.io"
  if ! command -v caddy >/dev/null 2>&1; then
    apt-get install -y -qq debian-keyring debian-archive-keyring apt-transport-https gnupg >/dev/null
    curl -fsSL 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor --yes -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
    curl -fsSL 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' > /etc/apt/sources.list.d/caddy-stable.list
    apt-get update -qq && apt-get install -y -qq caddy >/dev/null
  fi
  printf '%s {\n  reverse_proxy 127.0.0.1:%s\n  request_body {\n    max_size 80MB\n  }\n}\n' "$HOST" "$PORT" > /etc/caddy/Caddyfile
  # Oracle Ubuntu tasvirida 80/443 portlar iptables bilan yopiq bo'ladi — ochamiz
  for p in 80 443; do iptables -C INPUT -p tcp --dport $p -j ACCEPT 2>/dev/null || iptables -I INPUT 5 -p tcp --dport $p -j ACCEPT; done
  command -v netfilter-persistent >/dev/null 2>&1 && netfilter-persistent save >/dev/null 2>&1 || true
  systemctl restart caddy
  URL="https://$HOST"
else
  URL="http://SERVER_IP:$PORT  (tashqi IP aniqlanmadi — HTTPS sozlanmadi)"
fi

echo
echo "✅ Tayyor. Model birinchi marta ~1 daqiqada yuklanadi."
echo "   Tekshirish:  curl -s $URL/health"
echo
echo "   Admin panel → Tizim → «⭐ Jonli ovoz serveri» ga kiriting:"
echo "   Manzil: $URL"
echo "   Token:  $(cat "$DIR/token")"
echo
echo "   (Oracle Cloud'da: VCN → Security List'da 80 va 443 portlarini ochishni unutmang.)"
