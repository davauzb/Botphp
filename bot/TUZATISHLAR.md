# studybot 14-14 — TO'LOVLAR: USERBOT ORQALI HUMO AVTO, QO'LDA TO'LOV KARTASI, XAVFSIZLIK

- **👤 Userbot (HUMO avto-to'lov)** — admin panel → Tizim → «Userbot». Hostingda Python bo'lmasa ham «portable» Python va Telethon
  avtomatik o'rnatiladi; api_id/api_hash + telefon + kod (+2FA) bilan kiriladi. Kutilayotgan HUMO so'rovi bo'lsa @HUMOcardbot chati
  har ~35 soniyada o'qiladi va mos to'lov o'zi tasdiqlanadi; «🔄 Hozir o'qish» — darhol. Telegram Business/Premium kerak emas.
  Bekor qilish: panelda «Chiqish» yoki Telegram → Qurilmalar → «SlayderAi Pay».
- **To'lov usullari Sozlamalardan**: Humo avto, Click, Payme, Stars — alohida yoqiladi (sukut bo'yicha o'chiq), chek (qo'lda) doim bor.
- **Chek orqali to'lovda karta raqami** ko'rsatiladi (Sozlamalar → «Karta raqami», bo'sh bo'lsa config HUMO_CARD_NUMBER), bonus aksiyasi summasi bilan.
- **HUMO**: bir nechta karta qabul qilinadi; boshqa kartaga tushgan xabar /humo da 🚫 bilan ko'rinadi; /humo — Telegram'dagi haqiqiy ulanish holati.
- **/himoya** — webhook maxfiy kaliti bir buyruq bilan; **/userbot_tekshir** — hosting tekshiruvi.
- **Mini App**: inline «Ilovani ochish» tugmasi orqali ochiladi (reply-menyudan initData kelmasdi).
- **Reklama**: `start.php` — reklama sahifasi; `assets/promo/` — reklama videolari.

---

# studybot 14-13 — ODDIY TAQDIMOTDA KO'PROQ RASM

- Oddiy (PRO bo'lmagan) dizaynlarda rasm endi faqat «Ma'lumot»/«Ta'rif» slaydlarining har ikkinchisiga emas, balki
  **Ma'lumot, Ta'rif, Kartalar (≤4 ta), Xronologiya (≤4 ta) va Xulosa** slaydlariga qo'yiladi (rasm yon ustunda, matn 64% kenglikda).
  Ko'pi bilan **10 ta** rasm — Sozlamalar → «Oddiy taqdimotda rasmlar soni».
- Tor mavzularda slayd iborasi bo'yicha mos rasm topilmasa — umumiy mavzu bo'yicha qidiriladi, u ham bo'lmasa AI chizadi.
- Rasm baholash qattiqroq: iboradagi so'zlarning kamida 2/3 qismi va asosiy ot (odatda oxirgi so'z) tavsifda bo'lishi shart.

---

# studybot 14-12 — RASMLAR MAVZUGA ANIQ MOS

Yangilash: botga `/yangilash` → «🚀 Yangilash».

- **Kalitsiz manbalar har doim ishlaydi**: Wikipedia (shaxs, joy, hodisa uchun aynan o'sha rasm — Registon, Amir Temur haykali…),
  Openverse (800 mln+ ochiq litsenziyali rasm), Wikimedia Commons. Kalit qo'yilmaguncha rasmlar shulardan topiladi.
- **Kalitli bepul manbalar admin paneldan**: Tizim → «🖼 Rasm manbalari» → Pexels / Pixabay / Unsplash (bepul) yoki Serper (pullik)
  kalitini kiriting → bot darhol sinab ko'radi, ishlasa saqlaydi. config.php dagi eski kalitlar ham ishlayveradi.
- **Aniqlik**: barcha manbalardan bir vaqtda 30-60 ta nomzod olinadi, har biri tavsifi/teglari bo'yicha baholanadi (qidiruv so'zlarining
  kamida yarmi bo'lmasa — chiqarib tashlanadi), keng va katta rasmlar afzal. AI qidiruv iborasini «suratga olsa bo'ladigan aniq narsa»
  ko'rinishida yozadi (mavhum so'zlarsiz), shaxs/joy bo'lsa — uning inglizcha nomi.
- **🖼 AI tekshiruvi** (Sozlamalar → «Rasmni AI bilan tekshirish», standart yoqilgan): Gemini eng yaxshi 3 nomzodni ko'rib, mos emasini rad etadi.
- **🎨 AI chizgan rasm** faqat hech narsa topilmasa (Sozlamalar → «Mos rasm topilmasa — AI chizsin»).
- Natijalar 30 kun keshlanadi, katta rasmlar 2000 px gacha kichraytiriladi.

---

# studybot 14-11 — RANGLI TUGMALAR, TARJIMA, «MIJOZ KO'ZI BILAN»

Yangilash: botga `/yangilash` → «🚀 Yangilash». Baza o'zgarmaydi.

- **🎨 Rangli tugmalar** (Telegram Bot API 10.4): tasdiqlash/to'lash — 🟢 yashil, bekor qilish/rad etish — 🔴 qizil, tanlash
  (hajm, til, tahrirlash, holat, ilova) — 🔵 ko'k; pastki menyuda buyurtma turlari ko'k, «💰 Balans» yashil.
  Rang avtomatik beriladi (bitta joyda — `Telegram::colorize`). Telegram rangni qabul qilmasa, xabar rangsiz qayta yuboriladi.
  Eski Telegram ilovalarida tugmalar oddiy ko'rinadi. O'chirish: Sozlamalar → «🎨 Rangli tugmalar».
- **🌐 Tarjima**: tayyor fayl ostida «🌐 Boshqa tilga tarjima» → til (o'zbek / rus / ingliz) → narx balansdan (Sozlamalar → «Tarjima narxi»,
  standart 2 000 so'm). Taqdimot (oddiy va PRO) va hujjatlarning MATNI o'giriladi — dizayn, rasmlar, animatsiyalar, reja va bet raqamlari
  o'zgarmaydi. Ismlar, universitet, guruh, URL/DOI va adabiyotlar o'zgartirilmaydi. Server faylni o'chirgan bo'lsa — Telegram'dan olinadi.
- **👁 Mijoz ko'zi bilan** (admin panel → buyurtma): mijozga borgan fayl sahifalari/slaydlari rasm ko'rinishida (taqdimot — 6 slayd,
  hujjat — 4 bet, serverda LibreOffice bo'lsa), mijozga borgan izoh va bahosi; «📲 Faylni Telegram'imga yuborish».

---

# studybot 14-10 — YANGI TURLAR, TALABLAR, HIMOYA SAVOLLARI, OBUNA, HAMKORLAR, MINI APP

Yangilash: botga `/yangilash` yozing → «🚀 Yangilash». Baza o'zi yangilanadi (sxema 20), config.php o'zgarmaydi.
Hamma yangi funksiyalar **admin panel → Sozlamalar** dan yoqiladi/o'chiriladi.

## 📚 Mijozlar uchun
- **🎓 Himoya savollari** — har ishga 10 ta ehtimoliy savol va qisqa javob (bitta kichik AI so'rovi). Taqdimotda — konspektga qo'shiladi,
  mustaqil ish / kurs ishi / referat / maqola / laboratoriya / amaliyotda — alohida «Himoya savollari» DOCX bo'lib keladi.
- **📎 O'qituvchi talablari** — mavzu so'ralganda topshiriq faylini (DOCX, PDF, TXT) yoki rasmini yuborish mumkin, yoki `Talab: ...` deb yozish.
  Ish boshida talablar qisqa bandlarga keltiriladi va HAMMA bosqichlarda (reja, boblar, slaydlar) hisobga olinadi. `/talab_ochir` — bekor qilish.
  Rasmni o'qish uchun rasmni tushunadigan model kerak (Gemini — bor). Skaner qilingan PDF o'qilmaydi — mijozga rasm yuborish aytiladi.
- **Titul**: ixtiyoriy **kafedra** va **o'qituvchi / ilmiy rahbar** («⏭ O'tkazib yuborish» bor); pastda **shahar** universitet nomidan
  aniqlanadi («Samarqand – 2026–2027 o'quv yili»). Kurs ishida «Ilmiy rahbar», boshqalarida «Qabul qildi».
- **Yangi turlar**: ✍️ **Esse / insho**, ❓ **Test savollari** (20/30/50 ta, A–D, variantlar aralashtiriladi, oxirida javoblar kaliti jadvali),
  🔬 **Laboratoriya ishi**, 🏢 **Amaliyot hisoboti** (tuzilmasi tayyor — reja uchun AI so'rovi yo'q, tejamkor).
- **🔎 Unikallik** — har ish bot avval yozgan barcha ishlar bilan solishtiriladi; o'xshash qism (ko'pi bilan 3 ta) qayta ifodalanadi,
  izohda «Unikallik: 96%». ⚠️ Bu internet antiplagiati (Antiplagiat.ru / Turnitin) EMAS — faqat bot bazasi bo'yicha.
- **😔 Past baho (1–2 ⭐)** — bot sababini so'raydi (panelning «Yordam» bo'limiga tushadi) va bir martalik **bepul qayta yozish**ni taklif qiladi
  (izoh hisobga olinadi; 30 kunda bir marta, 7 kun ichidagi buyurtma uchun).
- **📱 Ilova (Mini App)** — menyudagi tugma Telegram ichida chiroyli forma ochadi: tur → hajm → dizayn (rangli namunalar) → mavzu → titul → narx.
- **🎬 Ovozli videotaqdimot** (ixtiyoriy) — slaydlar + ovoz (Gemini TTS) → MP4. Serverda **ffmpeg** va LibreOffice bo'lishi shart;
  bo'lmasa panel «Hamkorlar» bo'limida nima yetishmayotgani ko'rinadi. O'zbekcha talaffuz mukammal bo'lmasligi mumkin.

## 💰 Daromad uchun
- **💎 «Talaba PRO» obunasi** — masalan 49 000 so'mga 30 kunda 10 ta buyurtma (narx, soni, muddat, qoplanadigan eng qimmat tarif — sozlamalarda).
  `/obuna` yoki menyudagi «💎 Obuna». Buyurtma bekor bo'lsa, kredit qaytadi.
- **🤝 Hamkorlar (agentlar)** — panelda Telegram ID va % kiritasiz → odam `/agent` buyrug'ida o'z havolasini va hisobotini ko'radi.
  Havola orqali kelgan YANGI mijozlarning har pullik buyurtmasidan ulush yoziladi; «💵 To'landi» bilan to'lovni belgilaysiz.
- **📣 Avtomatik marketing** (standart — o'chiq): 1-3 kun buyurtma bermaganlarga (bepul urinish eslatmasi), birinchi buyurtmadan 2-5 kun keyin
  (boshqa turlar haqida), 30-60 kun kelmaganlarga («sog'indik»). Har xabar bir kishiga BIR marta, faqat 9:00–21:00. Natijasi panelda.

## 🛡 Admin panel
- **Tizim: AI va xatolar** — 🔑 AI kalitlarini qo'shish (gemini, groq, cerebras, openrouter, openai, deepseek yoki istalgan OpenAI-mos),
  qo'shishdan oldin avtomatik sinov, yoqish/o'chirish/o'chirib tashlash, har kalit holati (faqat egasi). 🧯 **Xatolar jurnali** — qidiruv,
  guruhlar, maxfiy qiymatlar (token, kalitlar) yashiriladi; tozalash (arxivga) — faqat egasi.
- **Hamkorlar va o'sish** — hamkorlar ro'yxati, savdosi, qoldiq; obunalar; marketing natijalari.
- Sozlamalarda yangi «🚀 O'sish» guruhi va yangi funksiyalar.

---

# studybot 14-9 — ADMIN PANEL PRO

Yangilash: botga `/yangilash` yozing → «🚀 Yangilash». Baza o'zi yangilanadi, config.php o'zgarmaydi.

## Yangi bo'limlar
- **💬 Yordam qutisi** — mijoz menyudagi «💬 Yordam» orqali yozadi → panelda chat ko'rinishida; javobingiz mijozga Telegram'da boradi
  («✍️ Javob yozish» tugmasi bilan), tayyor javob shablonlari, suhbatni yopish, o'qilmaganlar soni.
- **📈 Hisobotlar**: 💰 *Moliya* (3/6/12 oy: kirim, sotuv, o'rtacha chek, qaytarilganlar, yangi mijozlar; oylar grafigi; to'lov usullari va
  buyurtma turlari ulushi; eng ko'p sotilgan tariflar; jadval; **Excel'ga eksport** — fayl Telegram'ingizga keladi),
  🔻 *Voronka* (ro'yxatdan o'tdi → buyurtma boshladi → fayl oldi → to'ladi → qayta buyurtma; eng zaif bosqich va maslahat),
  🤖 *AI sarfi* (kunlik token/so'rov, provayder/model bo'yicha, har tur bo'yicha bitta buyurtmaga o'rtacha).
- **⚙️ Sozlamalar** (config.php ni ochmasdan): **narxlar** (har tur va hajm), bepul birinchi buyurtma, to'ldirish bonusi, referal bonusi,
  eng kam to'lov, PRO narxi, Stars kursi, funksiyalarni yoqish/o'chirish, **🛠 texnik ishlar rejimi** (mijozlar buyurtma bera olmaydi,
  «Yordam» ishlaydi). Har o'zgarish jurnalga yoziladi va adminlarga xabar boradi; «↺» — config qiymatiga qaytarish.
- **🛡 Admin va operatorlar** (faqat egasi): Telegram ID bo'yicha qo'shish/olib tashlash. *Operator* — faqat cheklar, buyurtmalar, mijozlar va yordam.

## Mavjud bo'limlarga qo'shimchalar
- Bosh sahifa: **jonli navbat** (qaysi buyurtma qaysi bosqichda, foiz, avtomatik yangilanadi), texnik rejim ogohlantirishi, tezkor tugmalar.
- Buyurtma: **📎 faylni qayta yuborish**, **🔁 qayta ishga tushirish** (mijoz balansidan yoki 🎁 bepul), AI tokenlari soni.
- Mijoz: **teglar** (#vip, #shubhali …) va **ichki eslatma**; qidiruvda `#vip`; yozishmalarga o'tish.
- Mobil: pastda 5 asosiy bo'lim + «Ko'proq» menyusi.

## Tuzatish
- 14-8 da qo'shilgan **rassilka** amallari API ruxsat ro'yxatiga kiritilmagan edi (panelda rassilka ochilmas edi) — tuzatildi.

---

# studybot 14-8 — YANGI TURLAR, HAQIQIY ADABIYOTLAR, RASSILKA, STARS, AVTO-YANGILASH

Eski `config.php` bilan ishlaydi (yangi sozlamalar ixtiyoriy — `config_yangi_qatorlar.txt` oxirida). Baza o'zi yangilanadi.

## 📚 Hujjatlar
- **Yangi turlar:** 🎓 **Kurs ishi** (25-30 / 30-40 bet, 3 bob × 3 band), 📰 **Ilmiy maqola** (annotatsiya, kalit so'zlar, metodologiya,
  natijalar va muhokama; titulsiz jurnal andozasi), 🧾 **Tezis** (konferensiya uchun). Narxlar `lib/DocTypes.php` da, config'dan o'zgartiriladi.
- **Haqiqiy adabiyotlar ro'yxati** — Crossref ochiq ilmiy bazasidan mavzu bo'yicha *haqiqatda mavjud* maqola/kitoblar (muallif, nom, jurnal,
  yil, tom, sahifa, DOI), alifbo tartibida. Topilmasa — avvalgi umumiy ro'yxat. Kalit kerak emas, 30 kun keshlanadi.
- **Kichik bo'limlar (1.1, 1.2, 1.3)** — mustaqil ish (10+ bet) va kurs ishida; Rejada bandlar ham bet raqami bilan.
- **Rasm va jadval** — boblarga mavzuga oid rasm («1-rasm. …» izohi bilan), 2-bobda bitta taqqoslash jadvali («1-jadval»).
  Tahrirlovchidan qayta eksport qilinganda ham rasmlar saqlanadi.

## 📊 Taqdimot
- **Oldindan ko'rinish:** fayldan oldin 4 ta slayd rasmi albom bo'lib keladi (LibreOffice bo'lsa — barcha dizaynlarda; bo'lmasa — oddiy dizaynlarda).

## 🛡 Admin panel
- **📣 Rassilka:** segmentlar (hamma / 7 va 30 kunda faol / buyurtma berganlar / bermaganlar / balansi borlar), **qalin**/__kursiv__,
  havola tugmasi, «Menga sinov», jonli progress (yetkazildi / bloklagan / xato), to'xtatish. Fon rejimida yuboriladi, tugagach hisobot keladi.

## 💳 To'lov va mijoz
- **⭐ Telegram Stars** orqali balansni to'ldirish (kartasiz). Webhook sozlamasi kerak bo'lsa bot o'zi tuzatadi.
- **🔁 Qayta buyurtma** («Buyurtmalarim»da): tur, hajm, dizayn va titul ma'lumotlari eslab qolinadi; «♻️ o'sha mavzu — qayta» tugmasi ham bor.

## 🔧 Xizmat
- **Kunlik baza zaxirasi** adminga Telegram orqali (03:00 dan keyin), oxirgi 3 tasi serverda.
- **Avtomatik tozalash** (soatiga): eski fayllar, katta loglar, eski zaxiralar, eski hujjat rasmlari.
- **/yangilash** — botni GitHub'dan bir tugma bilan yangilash (zaxira bilan; config va baza o'zgarmaydi).

---

# studybot 14-7 — TEZROQ, ARZONROQ (kamroq AI token) VA YANGI IMKONIYATLAR

Eski `config.php` bilan ishlaydi, baza o'zgarmaydi.

## ⚡ Tezlik va AI token tejash
- **«O'ylash» tokenlari kamaytirildi**: gpt-oss (Groq) va Gemini modellariga `reasoning_effort: low` yuboriladi — javob odatda bir necha
  baravar tezroq, yashirin "o'ylash"ga ketadigan tokenlar ancha kam. Model bu sozlamani tanimasa (400 xato) — bir marta eslab qolinadi va usiz so'raladi.
  O'zgartirish: `AI_PROVIDERS` dagi provayderga `'reasoning' => 'low'|'medium'|'high'|''`.
- **Rasm qidiruvi uchun AI tarjima so'rovlari olib tashlandi**: inglizcha qidiruv iboralari endi taqdimot rejasi bilan BIRGA keladi
  (har taqdimotda ~5-8 ta alohida AI so'rovi kamaydi). Zaxira tarjima keshlanadi (30 kun).
- Slayd matni so'rovlarida boshqa slaydlar faqat sarlavhasi bilan yuboriladi — har so'rovning kirish tokenlari kamaydi.

## 🎨 Taqdimot
- **Bo'lim ajratuvchi slaydlar** (katta «01», bo'lim nomi, ichidagi mavzular) — 10-15 slaydda 2 ta, 15-20 va 20-25 da 3 ta.
  AI so'ralmaydi (bo'lim nomlari rejaga qo'shimcha), slaydlar soni tarif oralig'idan chiqmaydi. 12 oddiy + 5 PRO dizaynda, tahrirlovchida ham
  («§ Bo'lim ajratuvchi» turi). O'chirish: `define('SECTION_SLIDES', false);`
- **🎤 Himoya uchun konspekt (DOCX)** — taqdimot bilan birga ikkinchi fayl: har slaydning asosiy fikrlari, PRO'da og'zaki nutq matni ham.
  AI'siz yig'iladi (qo'shimcha token yo'q). O'chirish: `define('KONSPEKT_ENABLED', false);`

## Qo'shilmaganlari va sababi
- **Diagramma/grafiklar**: AI raqamlarni o'ylab topadi (hallyutsinatsiya) — ilmiy ishda soxta statistika talabaga zarar. Shuning uchun qo'shilmadi.
- **Tayyor .pptx shablonlar**: internetdagi shablonlarning ko'pchiligi mualliflik huquqi bilan himoyalangan, botdagi 17 ta o'z dizayni esa
  tahrirlovchi bilan to'liq mos. Shablonlar tahrirlovchida ishlamaydi.

---

# studybot 14-6 — PRO DIZAYN YANGILANISHI

**Eski `config.php` ni almashtirmang** — yangi kod eski config bilan ishlaydi, baza o'zgarmaydi, yangi sozlama kerak emas.

## 📄 Mustaqil ish / referat (DOCX) — butunlay yangi andoza
- Haqiqiy Word uslublari (Normal, Heading 1/2, Title, TOC): Word'dagi «Navigatsiya» paneli va avtomatik mundarija ishlaydi.
- **Titul varag'i**: faqat 1-betda ikki chiziqli ramka, vazirlik → universitet → ajratuvchi chiziq → katta «MUSTAQIL ISH» → mavzu
  → o'ngda «Bajaruvchi / Guruh / Qabul qildi» bloki → eng pastda o'quv yili (har doim bet tagida).
- **Reja** endi nuqtali chiziq va **bet raqami** bilan (PAGEREF + xatcho'p). Raqam oldindan hisoblanadi, LibreOffice/PDF'da o'zi aniqlanadi,
  Word'da `Ctrl+A → F9` bilan yangilanadi.
- Har asosiy bo'lim (Kirish, boblar, Xulosa, Adabiyotlar) **yangi betdan**; boblar raqami bilan, Kirish/Xulosa katta harflarda.
- Hoshiyalar: chap 3 sm, o'ng 1.5 sm, tepa/past 2 sm; abzats 1.25 sm; «yetim» qatorlar nazorati.
- AI matnidagi ro'yxatlar (•) haqiqiy Word ro'yxatiga aylanadi; «Atama — izoh» boshlanishi qalin; adabiyotlar — raqamlangan ro'yxat; «Eslatma» alohida blokda.
- Hujjat xossalari (sarlavha, muallif, til) to'ldiriladi.

## 🎨 Slaydlar
- Rasm endi ajratilgan joyni **to'liq to'ldiradi** (markazdan qirqiladi) — kichkina «pochta markasi» rasm yo'q.
- Matn shrifti kattaroq (sig'magandagina kichrayadi), «Bosqichlar» kartalari matn hajmiga moslashadi (bo'm-bo'sh baland kartalar yo'q),
  «Taqqoslash» punktlari yumshoq fonli qatorlarda, «Reja» butun slaydni muvozanatli egallaydi.
- PRO: «Ta'rif» slaydi qayta qurildi (tepada keng ta'rif bloki, pastda belgilar kartalari), «Reja» va «Bosqichlar» markazlashtirildi,
  muqovada matn tomoni kuchliroq qoraytiriladi (har qanday rasmda o'qiladi), yon rasm paneli torroq — matnga ko'proq joy.

## 🛡 Admin panel
- Yangi dizayn: gradient «Bugungi kirim» kartasi (sparkline bilan), ikonli KPI kartalar, skeleton yuklanish, haptik javob.
- **Interaktiv grafiklar** (barmoq/sichqoncha bilan kun qiymati), **7 / 14 / 30 kun** tanlash, oldingi davrga nisbatan **o'sish foizi**.
- **Tizim holati**: AI kalitlar (har biri tayyor/limitda/yaroqsiz), navbat, disk joyi, PDF eksport rejimi.
- Bajarilgan buyurtmalar turi va **mashhur dizaynlar** diagrammasi.
- Kompyuter/planshetda chap yon menyu, 4 ustunli ko'rsatkichlar, oynalar markazda.

## ✏️ Tahrirlovchilar
- Hujjat tahrirlovchisi: **«👁 Ko'rinish» rejimi** — A4 betlar (titul ramkasi, reja, bo'limlar) Word andozasiga yaqin ko'rinishda;
  bo'limlar bo'yicha tezkor navigatsiya, umumiy statistika kartalari, `Ctrl+S` / `Ctrl+Z`, yangi uslub.
- Slayd tahrirlovchisi: yagona dizayn tili (rang, tugmalar, sahna soyasi).

## 🐞 Tuzatishlar
- AI matnidagi `* punkt` ko'rinishidagi ro'yxatlar yulduzcha olib tashlanganda buzilib qolardi — endi to'g'ri ro'yxatga aylanadi.
- Titulda uzun «Qabul qildi» qatori ikki tomonlama tekislanib, so'zlar orasida katta bo'shliq paydo bo'lardi.
- Admin grafiklarida oxirgi sanalar ustma-ust tushardi; katta summalar KPI kartadan chiqib ketardi (endi «28,5 mln so'm»).

---

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
