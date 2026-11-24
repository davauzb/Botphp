# ⭐ Jonli ovoz ishchisi (o'zbekcha, haqiqiy odamdek)

Matnni **Navoiy TTS** modeli (Aisha, Apache-2.0, CosyVoice2 asosida) bilan ifodali o'zbekcha nutqqa aylantiradi.
Hosting (PHP) og'ir modelni ko'tarmaydi, shuning uchun model alohida bepul serverda ishlaydi.

**Ishchi botga O'ZI ulanadi** (`voice_api.php`): navbatdagi ishni so'raydi, bajaradi, natijani yuboradi.
Shuning uchun serverga kiruvchi port, domen, HTTPS sertifikati, SSH va token nusxalash **kerak emas**.

## O'rnatish — bitta skript

1. Admin panel → **Tizim → «⭐ Jonli ovoz» → «🚀 Server skriptini olish»** → «📋 Skriptni nusxalash».
2. https://cloud.oracle.com → **Sign up** (bepul «Always Free»; karta faqat tekshirish uchun).
3. **☰ → Compute → Instances → Create instance**:
   - Image: **Ubuntu 22.04**; Shape: **Ampere → VM.Standard.A1.Flex → 4 OCPU, 24 GB**;
   - Boot volume: «Specify a custom boot volume size» → **50 GB**;
   - pastda **Show advanced options → Management → Paste cloud-init script** → nusxalangan skriptni joylang.
4. **Create**. Server 20–40 daqiqada o'zi o'rnatiladi; bosqichlar admin panelda ko'rinib turadi
   (Python, PyTorch, model ~6 GB, xizmat). Tayyor bo'lganda botga «⭐ Jonli ovoz serveri ulandi!» xabari keladi.

«Out of capacity» chiqsa — boshqa Availability Domain'ni tanlang yoki birozdan keyin qayta urining.

## O'z ovozingizni qo'shish

Botga `/ovoz_qosh` → video (masalan, hikoya aytgan videongiz), audio yoki ovozli xabar → nom → 👨/👩 → «✅ Ha, rozilik bor».
Videoni qirqish shart emas: ishchi undan **eng toza ~11 soniyalik** nutq qismini o'zi tanlaydi (fon musiqasi/shovqin eng kam joy).
Namuna botda ham saqlanadi — server qayta o'rnatilsa, ovozlar avtomatik tiklanadi.

⚠️ Faqat **o'zingizning** yoki egasi **yozma rozilik bergan** ovozni qo'shing.

## Tezlik va narx

- GPU'siz (4 yadro): 10 soniyalik ovoz ~1 daqiqada. Shuning uchun jonli ovoz qisqa matnlar uchun (`LIVE_MAX_CHARS`, 500).
- Ishchi o'chib qolsa yoki ish 10 daqiqada tugamasa — pul qaytariladi, mijozga oddiy ovoz taklif qilinadi.
- Narx: Sozlamalar → `LIVE_FREE_CHARS` (kunlik bepul), `LIVE_PRICE_1000`, `LIVE_TTS_ENABLED`.

## Server boshqaruvi (ixtiyoriy, SSH orqali)

```bash
sudo systemctl status slayder-voice       # holat
sudo journalctl -u slayder-voice -f       # jurnal (har bir tayyorlangan ovoz yoziladi)
sudo tail -50 /var/log/slayder-install.log  # o'rnatish jurnali
```

Tokenni yangilash (admin panel → «🔄 Tokenni yangilash») eski serverni uzadi — yangi skript bilan qayta o'rnatiladi.

## Litsenziyalar

Navoiy TTS (aisha-org/navoiy-tts) — Apache-2.0; CosyVoice2 (FunAudioLLM) — Apache-2.0.
Model fayllari o'rnatish paytida rasmiy manbalardan yuklanadi.
