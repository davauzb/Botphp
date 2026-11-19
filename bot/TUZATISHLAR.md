# studybot 14-5 — YANGILIK: ⚡ Humo / Uzcard (Avto) to'ldirish

Mijoz «⚡ Humo / Uzcard (Avto)» ni tanlaydi (kartangiz HUMO bo'lsa ham, mijoz Humo yoki Uzcard dan o'tkaza oladi) → bot **noyob summa** beradi (masalan 10 017 so'm) → mijoz shuni kartangizga o'tkazadi →
@HUMOcardbot sizning akkauntingizga "➕ To'ldirish ..." xabarini yuboradi → bot (Telegram Business orqali) xabarni o'qib, summaga mos so'rovni
topadi va balansni **o'zi** to'ldiradi (bonus/promokod/buyurtmaga qaytish — hammasi avvalgidek).

## O'rnatish (bir marta)
1. `lib/HumoAuto.php` ni yuklang; yangilangan `webhook.php`, `lib/admin_ui/app.js`, `config.php` (yoki `config_yangi_qatorlar.txt` dagi HUMO qatorlarini eski config.php ga qo'shing).
2. @BotFather → botingiz → **Bot Settings → Business Mode** → yoqing.
3. Telegram (Premium akkaunt) → **Sozlamalar → Business → Chatbotlar** → botni qo'shing. Chatlarga kirish: **faqat tanlangan chatlar → @HUMOcardbot**.
   Huquq: «Xabarlarni o'qish» yetarli (javob yozish shart emas).
4. `config.php`: `HUMO_ENABLED=true`, `HUMO_CARD_NUMBER='9860 ... 6287'` (HUMO_CARD_LAST4 bo'sh qolsa shundan olinadi), ixtiyoriy `HUMO_CARD_HOLDER`.
5. **Webhook'ni qayta o'rnating** (business update'lari kelishi uchun):
   `https://api.telegram.org/bot<TOKEN>/setWebhook?url=<URL>&secret_token=<WEBHOOK_SECRET>&allowed_updates=["message","callback_query","business_connection","business_message"]`
6. Botga admin akkauntidan `/humo` yozing — holat, ulanish va oxirgi xabarlar ko'rinadi. Ulanganda sizga «✅ Business ulandi» xabari keladi.

## Qanday himoyalangan
- Faqat **ADMIN** akkauntining Business ulanishi qabul qilinadi (begona odam o'z akkauntini ulab soxta to'lov yarata olmaydi).
- Faqat `@HUMOcardbot` chatidan, **uning o'zidan** kelgan, forward qilinmagan xabar o'qiladi (siz o'zingiz yozgan yoki boshqa chatdagi matn hisoblanmaydi). Boshqa chatlar xabari o'qilmaydi, saqlanmaydi, logga yozilmaydi.
- Faqat "➕" (kirim); karta oxirgi 4 raqami mos bo'lishi shart; summa butun so'm bo'lib, kutilayotgan so'rovga **aynan** teng bo'lishi shart.
- Bitta xabar ikki marta hisoblanmaydi (karta+vaqt+summa+qoldiq kaliti, baza darajasida UNIQUE). Tahrirlangan xabar hisoblanmaydi.
- Noyob summa: bir vaqtda bir xil summali ikkita ochiq so'rov bo'lmaydi (baza darajasida unikal indeks). Summaga 11–99 so'm (10 ga bo'linmaydigan) qo'shiladi, shunda sizga boshqa sabab bilan tushgan "yumaloq" o'tkazmalar tasodifan mos kelmaydi. Mijozga **to'langan summa to'liq** hisobiga yoziladi.
- So'rov 24 soat saqlanadi (30 daqiqa — mijozga aytiladigan muddat), kech to'lagan ham hisoblanadi. Bitta mijozda ko'pi bilan 3 ta ochiq so'rov.
- Mos so'rov topilmasa (mijoz summani xato yozgan) — pul o'zidan berilmaydi; kutayotgan mijoz bo'lsa adminga xabar boradi, panel → Mijozlar orqali qo'lda to'ldirasiz.

## Eslatma
- Tushunilmagan "UZS" xabarlari `/humo` da «❓ tushunilmadi» deb ko'rinadi — HUMOcardbot xabar formatini o'zgartirsa shu yerdan bilasiz.
- Faqat HUMO kartaga tushgan to'lovlar. Click/Payme/chek usullari o'zgarmagan (zaxira sifatida qoladi).

---

# studybot 14-4 — YANGILIKLAR (14-3 dan keyin)

**MUHIM: eski `config.php` ni ALMASHTIRMANG** (kalitlaringiz o'sha yerda). Yangi kod eski config bilan ham ishlaydi (sinalgan).
Yangi imkoniyatlar uchun `config_yangi_qatorlar.txt` dagi qatorlarni o'z config.php ingizga qo'shing. Baza o'zi yangilanadi (eski ma'lumotlar saqlanadi, yarim yo'ldagi buyurtmalar ham tugaydi — sinalgan).

1. **Gemini zaxira AI** (+ `priority`): Groq limitga yetsa Gemini'ga, keyin OpenRouter'ga o'tadi. Kalit: https://aistudio.google.com/apikey
2. **Reja tasdiqlash**: mijoz rejani ko'radi — ✅ Tasdiqlash / ✏️ O'zgartirish / 🔄 Boshqa reja (2 marta). 5 daqiqada javob bo'lmasa o'zi tasdiqlanadi. O'chirish: `PLAN_REVIEW_ENABLED = false`.
3. **Promokodlar**: admin `/promo_add KOD balance 5000 [limit] [kun]` yoki `/promo_add KOD topup_pct 10`; `/promo_list`, `/promo_off KOD`. Mijoz: «🎟 Promokod». Hamma uchun aksiya: `TOPUP_BONUS_PERCENT`.
4. **Buyurtmalarim**: sahifalash, «📥 yuklab olish» (fayl Telegram file_id orqali qayta yuboriladi), «✏️ tahrirlash».
5. **Til tanlash** (/til): o'zbek / rus / ingliz — AI matni, titul varag'i, reja, xulosa, adabiyotlar, slayd teglari shu tilda.
6. **PDF**: tahrirlovchida «Yuborish» → PDF (LibreOffice bo'lsa u, bo'lmasa GD).
7. **✨ AI bilan qayta yozish**: slaydda ham, hujjatda ham (qayta yozish, qisqartirish, kengaytirish, soddalashtirish, rasmiy uslub, xatolarni to'g'rilash, erkin ko'rsatma). Natija «Qo'llash» bosilgunча saqlanmaydi.
8. **Hujjat tahrirlovchisi**: mustaqil ish va referat uchun ham «✏️ Tahrirlash» (bo'limlarni tahrirlash, DOCX/PDF eksport).
9. Referat fayli endi `referat_...docx` deb nomlanadi.
10. **Admin panel (WebApp) → «Promo» bo'limi**: kod yaratish (balans / to'ldirishga %), limit, muddat, yoqish-o'chirish, o'chirib tashlash, kim ishlatgani ro'yxati. Katta summali kod uchun PIN qayta so'raladi, hamma amal «Jurnal»ga yoziladi.
11. **Har buyurtmada talaba ma'lumotlari alohida so'raladi**: ism → familya → guruh → universitet. Oldingi qiymat bo'lsa «↩️ oldingisi» yoki «✅ Ha, shu ma'lumotlar» tugmasi bor. Ma'lumot buyurtmaga nusxalanadi (keyin profil o'zgarsa ham eski titul o'zgarmaydi). Admin panelda buyurtma ichida «Talaba ma'lumotlari» ko'rinadi, buyurtmalarni ism/guruh bo'yicha qidirish mumkin.
12. **Admin xabarlari** (panelda «Xabar» bo'limi, o'qilmaganlar soni bilan + Telegram'ga ham): 🆕 yangi buyurtma (talaba ma'lumotlari bilan), ❌ buyurtma xatosi, 🎟 promokod ishlatildi, 💰 balans to'ldirildi (bonus bilan), 🧾 yangi chek, 👤 yangi mijoz (Telegram'ga default o'chiq).
    Sozlash (config.php, ixtiyoriy): `ADMIN_NOTIFY_ORDERS`, `ADMIN_NOTIFY_FAILS`, `ADMIN_NOTIFY_PROMO`, `ADMIN_NOTIFY_USERS` (default false), `ADMIN_NOTIFY_TOPUPS`.

Sinovdan o'tdi: 3 til × 3 tur buyurtma, reja oqimi, promokod, eski bazani yangilash, eski config bilan ishlash, ikkala tahrirlovchi API + haqiqiy PDF eksport, mobil brauzerda ochilishi.
Sinalmagan (haqiqiy hostingda tekshiring): haqiqiy Telegram/Groq/Gemini, hostingda LibreOffice borligi (bo'lmasa GD ishlaydi).
13. **⭐ PRO taqdimotlar** (dizayn tanlashda «⭐ PRO» tugmasi, 5 ta uslub: Tungi osmon, Shimol shafag'i, Zumrad, Quyosh botishi, Yorug' minimal):
    - 16:9, mavzuga oid **to'liq ekranli foto fon** (muqova va rasmli slaydlarda), matn o'qilishi uchun qoraytirilgan qatlam, **shisha kartalar**;
    - rasm bo'lmasa ham chiroyli: mavzu rangidagi gradient + topografik chiziqlar fon;
    - **o'tish effektlari** (fade/push va h.k.) va elementlarning **ketma-ket paydo bo'lish animatsiyasi**;
    - har slayd uchun **nutq matni** (PowerPoint → «Izohlar»);
    - narx: oddiy narx + `PRO_SURCHARGE_PERCENT`% (standart 60%), bepul urinish PRO'ga tegishli emas;
    - **rasm manbalari**: Unsplash → Pexels → Serper → (kalitsiz zaxira) AI rasm yaratish (Pollinations) → Wikimedia Commons. Hech qaysi kalit bo'lmasa ham PRO ishlaydi.
    - PRO taqdimot PowerPoint/Keynote/Google Slides'da tahrirlanadi (bot ichidagi tahrirlovchiga o'tkazilmaydi). Xato bo'lsa buyurtma yo'qolmaydi — oddiy dizaynda yuboriladi.
    - O'chirish: `PRO_ENABLED = false`.

---

# Tuzatishlar ro'yxati (studybot-14-2 → 14-3)

## Jiddiy
1. **Yangi o'rnatishda har bir buyurtma yiqilardi**: `storage/generated` va `storage/logs` papkalari yo'q edi (zip bo'sh papkani saqlamaydi). DOCX/PPTX yozilmasdi, `close()` natijasi tekshirilmagani uchun xato faqat yuborish bosqichida chiqardi, loglar ham yozilmasdi. Endi `config.php` papkalarni o'zi yaratadi, DOCX/PPTX generator yozish xatosini darhol aniqlaydi.
2. **Ikki marta bosish/parallel so'rov = ikki buyurtma, manfiy balans, ikkita bepul urinish**: balans va bepul urinish endi atomik (`UPDATE ... WHERE balance >= narx`, `free_used = 0`), holat "band qilinadi". Buyurtma ochilmasa pul avtomatik qaytadi. (4 parallel so'rovda 1 ta buyurtma ochilishi sinab ko'rildi.)
3. **Referat uchun balans to'ldirgach buyurtma davom etmasdi** (`resumeOrderIfPending` faqat mustaqil ish/slayd hajmlarini bilardi).
4. **Payme: bekor qilingan (qaytarilgan) to'lov balansdan ayirilmasdi** — endi ayiriladi, pul sarflangan bo'lsa -31007 qaytariladi. `PerformTransaction` avval balansni yozadi, keyin holatni; 12 soatlik muddat tekshiriladi. `GetStatement` millisekund va yaratilgan vaqt bo'yicha ishlaydi.
5. **Xavfsizlik**: `PAYME_KEY` / `CLICK_SECRET_KEY` namuna (`..._HERE`) holatida bo'lsa to'lov so'rovlari rad etiladi; Click `complete` summani tekshiradi.
6. **Authorization sarlavhasi** FastCGI/CGI da PHP'ga yetmasdi (Payme va tahrirlovchi ishlamasdi) — `.htaccess` va kod zaxira usullari qo'shildi.

## O'rta
7. `Engine::kick`: 404/403/3xx javob "muvaffaqiyat" hisoblanardi — ishlar jim qotardi. Endi faqat 2xx.
8. `db.php`: baza "yangi"ligi fayl bo'yicha emas, jadval bo'yicha aniqlanadi (yarim o'rnatilgan baza qotib qolmasligi uchun).
9. Taqdimot "8-10 slayd" tarifida 11 slayd chiqardi — endi tarifdagi son aniq (muqova+reja+rahmat hisobga olinadi).
10. Rasm tarjimasi `max_tokens=30` — reasoning modellarda bo'sh javob berib, AI marshrutlarini 45 soniyaga "sovitib" qo'yishi mumkin edi → 200.
11. Rasm yuklash: faqat http(s), hajm chegarasi (8 MB), HTTP 200 tekshiruvi.
12. DOCX: XML'ga yaroqsiz boshqaruv belgilari tozalanadi (Word "fayl buzilgan" demasligi uchun).
13. Telegram'ga fayl nomi to'liq server yo'li bilan emas, `basename` bilan yuboriladi.

14. **Tahrirlovchi "Forbidden (403)" berardi**: `editor/.htaccess` dagi regex papka soʻrovini (`/editor/`) ham bloklardi. Regex tuzatildi, havola endi aniq `editor/index.php?t=...` ga yoʻnaltiriladi.
15. **Slaydlar bitta zaglushka matn bilan chiqardi** (katta boʻsh karta ichida bitta "Ushbu slaydda ... solishtiriladi" jumlasi): AI javobi buzilsa kod jimgina shu matnni qoʻyardi. Endi yetishmagan slaydlar bittalab qayta soʻraladi va faqat yetishmaganlari qayta uriniladi.
17. **Tahrirlovchi "Havola eskirgan yoki yaroqsiz" der edi** (bot papkada turganda): `editor/api.php` brauzerning `Origin` sarlavhasini (faqat domen) `PUBLIC_BASE_URL` bilan (domen + `/SlayderAi1Bit`) solishtirardi — hech qachon mos kelmasdi. Endi faqat domen qismi solishtiriladi.
18. **Tahrirlovchi: slaydni boshqa o'ringa o'tkazish qiyin edi** (telefonda): sudrash brauzer skrollidan bekor bo'lardi, chetga yaqinlashganda tasma o'zi surilmasdi (faqat ko'rinib turgan 2–4 ta slaydga o'tkazish mumkin edi), slayd barmoq bilan yurmasdi. Endi: slaydni 0,3 s bosib turib sudrang — u barmoq bilan birga yuradi, tasma chetda o'zi suriladi, tushish joyi ko'rsatiladi. Qo'shimcha: tasma yonida ◀ ▶ tugmalari (tanlangan slaydni bir o'ringa surish).
16. Bot kichik papkada (masalan `/SlayderAi1Bit`) turganda zaxira ulanish (127.0.0.1) yoʻlni yoʻqotardi.

## Kichik
- Mavzu uzunligi (3–300), profil maydonlari (120 belgi), to'ldirish summasi chegarasi (`MAX_TOPUP_AMOUNT`, config'da).
- Inline tugmalarga `answerCallbackQuery` (aylanib turgan soat yo'qoladi).
- Admin ikkinchi marta bosganda aniq holat xabari; qo'lda to'ldirishdan keyin foydalanuvchi holati tiklanadi.
- SQL'dagi `"pending"` → `'pending'`; `reset(CONST)`; `PUBLIC_BASE_URL` oxiridagi `/` bardoshli.

## Siz hal qilishingiz kerak
- `WEBHOOK_SECRET` bo'sh bo'lsa, webhook manzilini bilgan kishi admin nomidan soxta update yubora oladi. Albatta to'ldiring va `setWebhook` da `secret_token` bering.
- `REFERRAL_BONUS` (3000) eng arzon pullik buyurtmadan (2000) katta — soxta akkauntlar orqali foydalanish mumkin; narxlarni qayta ko'rib chiqing.

---

# YANGI: Admin panel (Telegram WebApp)

**Ochish:** botga admin akkauntidan `/admin` yozing → «🛡 Panelni ochish» tugmasi keladi (5 daqiqa, bir marta ishlaydi).

**Imkoniyatlar**
- **Bosh:** foydalanuvchilar, buyurtmalar, kirim, xatolar, kutilayotgan to'lovlar + 14 kunlik grafiklar.
- **To'lovlar:** chek bilan kelgan to'lovlarni ko'rish (chek rasmi sizning Telegram chatingizga yuboriladi), tasdiqlash / rad etish.
- **Mijozlar:** ID / @username / ism bo'yicha qidirish; balans qo'shish-ayirish (sabab bilan), xabar yuborish, bloklash.
- **Buyurtmalar:** filtr va qidiruv, xato matni, bekor qilish (pul avtomatik qaytadi).
- **Jurnal:** kirishlar va barcha admin amallari tarixi.

**O'rnatish**
1. `admin/` papkasi, `lib/AdminSecurity.php`, `lib/AdminService.php`, `lib/admin_ui/`, yangilangan `db.php`, `webhook.php` ni yuklang (nginx bo'lsa `nginx.conf.example` dagi `/admin/` blokini qo'shing).
2. `config.php` ga qo'shing (yoki zipdagi qatorlarni ko'chiring): `ADMIN_PATH`, `ADMIN_IDS`, `ADMIN_PIN_HASH`.
3. **PIN yarating:** terminalda `php make_admin_pin.php` → chiqqan `define('ADMIN_PIN_HASH', '...')` qatorini `config.php` ga qo'ying (juda tavsiya etiladi).
4. Telegram'da `/admin`.

**Himoya qatlamlari**
1. Faqat `ADMIN_CHAT_ID` / `ADMIN_IDS` akkauntlari; boshqalarga bot hech narsa demaydi.
2. Havola tokeni: 256 bit, bazada faqat hash, 5 daqiqa, **bir martalik**; ochilgach xabar o'chiriladi.
3. Telegram imzosi (HMAC, 10 daqiqadan eski emas) — havola boshqaga tushsa ham ishlamaydi.
4. PIN (bcrypt) — 5 xatodan keyin 15 daqiqaga bloklanadi, adminlarga ogohlantirish boradi.
5. Sessiya: Bearer token (cookie yo'q → CSRF mumkin emas), bitta faol sessiya, 15 daqiqa harakatsizlik / 2 soat umumiy, brauzer o'zgarsa bekor.
6. Pul amallari: idempotency (qo'sh bosish ikki marta bajarilmaydi), summa chegarasi (1 000 000), sabab majburiy, 200 000 dan katta summada PIN qayta so'raladi, Click/Payme to'lovini qo'lda "to'langan" qilib bo'lmaydi.
7. Har bir kirish va amal jurnalga yoziladi va **barcha adminlarga Telegram'da xabar** boradi (buzib kirishni darhol bilasiz).
8. Qat'iy CSP (nonce), `innerHTML` yo'q (XSS sinovdan o'tdi), tezlik cheklovi, begona Origin rad etiladi, SQL parametrli.
