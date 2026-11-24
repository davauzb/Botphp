# ⭐ Jonli ovoz serveri (o'zbekcha, haqiqiy odamdek)

Bot uchun alohida server: matnni **Navoiy TTS** modeli (Aisha, Apache-2.0, CosyVoice2 asosida) bilan
ifodali o'zbekcha nutqqa aylantiradi. Hosting (PHP) og'ir modelni ko'tarmaydi, shuning uchun model shu serverda ishlaydi,
bot esa unga HTTPS orqali so'rov yuboradi.

- Bir nechta ovoz: har biri 10–25 soniyalik toza namuna (botda `/ovoz_qosh` → video, audio yoki ovozli xabar).
- Uslublar: 📖 Hikoyachi, 🙂 Oddiy, 🤗 Mehribon, 😊 Xursand, 🎓 Rasmiy.
- Tezlik (GPU'siz, 4 yadro): **1 soniya ovozga ~4 soniya**. Masalan, 130 belgilik ertak gapi (~10 s ovoz) ~40 soniyada tayyor.
  Shuning uchun bot jonli ovozni qisqa matnlar uchun beradi (Sozlamalar → `LIVE_MAX_CHARS`, standart 500).
- Server ishlamasa — bot pulni qaytaradi va oddiy (Microsoft) ovozni taklif qiladi.

## 1. Bepul server ochish (Oracle Cloud «Always Free»)

1. https://cloud.oracle.com → **Sign up**. Karta faqat tekshirish uchun so'raladi (Always Free resurslar uchun pul yechilmaydi).
   Hudud (Home region) tanlang — keyin o'zgartirib bo'lmaydi; Frankfurt yoki boshqa yaqin hudud mos.
2. **Compute → Instances → Create instance**:
   - Image: **Ubuntu 22.04** (yoki 24.04);
   - Shape: **Ampere (VM.Standard.A1.Flex)** — **4 OCPU, 24 GB RAM** (bepul limit ichida);
   - Boot volume: **50 GB** yoki ko'proq (model ~6 GB, kutubxonalar ~3 GB);
   - SSH kalitini yuklab oling (yoki o'zingiznikini qo'ying).
   «Out of capacity» chiqsa — boshqa Availability Domain'ni tanlang yoki birozdan keyin qayta urining.
3. **Networking → Virtual Cloud Network → Security List → Add Ingress Rules**:
   Source `0.0.0.0/0`, TCP, Destination port **80** va **443** (HTTPS sertifikati va bot ulanishi uchun).

## 2. O'rnatish (bitta buyruq)

Serverga SSH orqali kiring va:

```bash
sudo apt-get update && sudo apt-get install -y git
git clone https://github.com/davauzb/Botphp.git
cd Botphp/voice-server
sudo bash install.sh
```

Skript o'zi bajaradi: Python 3.12 (tizimga tegmaydi), PyTorch (CPU), aniq versiyadagi kutubxonalar, CosyVoice runtime,
model fayllari (~6 GB), boshlang'ich ayol ovozi, systemd xizmati (server qayta yoqilsa o'zi ishga tushadi) va
**bepul HTTPS** (`<ip>.sslip.io` domeni + Caddy, Let's Encrypt sertifikati).

Oxirida chiqadi:

```
Manzil: https://1-2-3-4.sslip.io
Token:  sv_xxxxxxxx…
```

Model birinchi marta ~1 daqiqada yuklanadi. Tekshirish: `curl -s https://1-2-3-4.sslip.io/health` → `"ready": true`.

## 3. Botga ulash

1. Admin panel → **Tizim → «⭐ Jonli ovoz serveri»** → manzil va tokenni kiriting → «💾 Saqlash va tekshirish».
2. «🧪 Sinash» — namunaviy ertak ovozi Telegram'ingizga keladi.
3. Mijozlar: «🔊 Matnni ovozga» → ⭐ ovozni tanlaydi → uslub (📖 Hikoyachi…) → matn yuboradi.

## 4. O'z ovozingizni qo'shish (masalan, hikoya aytgan videongizdan)

1. Videodan **10–25 soniyalik toza qismni** tanlang (telefonda qirqib oling): faqat sizning ovozingiz, musiqasiz, shovqinsiz.
   Ertak/hikoya ohangida gapirilgan bo'lsa — natija ham shunday iliq chiqadi.
2. Botga `/ovoz_qosh` yozing → videoni (yoki audio/ovozli xabarni) yuboring → nom bering → 👨/👩 → «✅ Ha, rozilik bor».
3. Server videodan ovozni ajratadi, shovqin darajasini tekislaydi va ovoz darhol mijozlarga ko'rinadi.

⚠️ Faqat **o'zingizning** yoki egasi **yozma rozilik bergan** ovozni qo'shing — begona odam ovozini ruxsatsiz nusxalash qonunga zid.

## Boshqarish

```bash
sudo systemctl status slayder-voice      # holat
sudo journalctl -u slayder-voice -f      # jurnal (har bir tayyorlangan ovoz yoziladi)
sudo systemctl restart slayder-voice     # qayta ishga tushirish
sudo bash install.sh                     # yangilash (token va ovozlar saqlanadi)
cat /opt/slayder-voice/token             # tokenni qayta ko'rish
```

Ovozlar: `/opt/slayder-voice/voices/` (`<kalit>.wav` + `voices.json`).

## Litsenziyalar

- Navoiy TTS (aisha-org/navoiy-tts) — Apache-2.0; CosyVoice2 (FunAudioLLM) — Apache-2.0.
- Fayllar o'rnatish paytida rasmiy manbalardan yuklanadi, repoga kiritilmagan.
