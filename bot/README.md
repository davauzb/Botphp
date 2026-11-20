# StudyBot Pro — mustaqil ish va slayd generatsiya qiluvchi Telegram bot

## Nima uchun bu arxitektura "qotmaydi"

- **Webhook faqat navbatga qo'yadi, og'ir ishni qilmaydi.** `webhook.php` Telegramga darhol javob beradi (`fastcgi_finish_request`) va faqat bazaga yozadi. Shu tufayli 1000 kishi bir vaqtda yozsa ham webhook sekinlashmaydi.
- **Og'ir ish (AI so'rov + fayl yaratish) endi CRON'SIZ, qadamma-qadam (`lib/Engine.php`, `tick.php`) bajariladi** — pastdagi «Ish mexanizmi» bo'limiga qarang.
- **SQLite WAL + busy_timeout** — bir vaqtda o'qish/yozish bo'lsa "database is locked" xatosi chiqmaydi, kutib bajaradi.
- **`BEGIN IMMEDIATE` bilan atomik "job olish"** — bir nechta worker nusxasi parallel ishlasa ham, ikkitasi bitta ishni ikki marta bajarib qo'ymaydi.
- **AI kalit/provider rotatsiyasi** — Groq, OpenRouter va hokazo bir nechta kalit qo'yasiz. Kalit eskirsa (401/403) — butunlay o'chadi va admin xabar oladi. Limit tugasa (429) — 10 daqiqaga o'chadi, keyin avtomatik qaytadi. Hammasi ishlamasa — jobga qayta urinish (`MAX_RETRIES`) beriladi.
- **Telegram/tarmoq uzilishlarida retry** — `Telegram::call()` 3 marta qayta uradi, oralig'ida kutadi.
- **Hech qachon "qorong'ida qolib ketmaydi"** — har bir xato `storage/logs/app.log`ga yoziladi, kritik holatlarda admin Telegramga xabar oladi.

## O'rnatish

1. `config.php` faylida:
   - `BOT_TOKEN` — @BotFather'dan olingan token
   - `ADMIN_CHAT_ID` — sizning Telegram ID'ingiz (xato xabarlari shu yerga keladi)
   - `AI_PROVIDERS` massivida Groq/OpenRouter kalitlaringizni yozing (bir nechtasini qo'shing — bittasi eskirsa boshqasi ishlaydi)

2. Serverda `storage/` papkasi yozish huquqiga ega bo'lishi kerak:
   ```
   chmod -R 775 storage/
   ```

3. Webhookni o'rnating (bittа marta, brauzerda oching yoki curl bilan):
   ```
   https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://sizning-domeningiz.uz/webhook.php
   ```

4. **Cron kerak emas.** Bot ishlarni o'zi bajaradi (yangi buyurtma, har bir xabar va o'z-o'zini zanjirlash orqali). Admin sifatida botga `/tekshir` yozing — hamma narsa tekshiriladi.
5. Birinchi ishga tushganda `db.php` avtomatik `storage/studybot.sqlite` faylini va jadvallarni yaratadi.

6. To'lov tizimlari uchun `click.php` va `payme.php` fayllari ham public URL orqali ochiladigan bo'lishi kerak (webhook.php bilan bir qatorda).

## Hajm bo'yicha narxlash

Endi narx **hajmga (bet/slayd soniga) qarab** belgilanadi — `config.php`dagi `MUSTAQIL_ISH_VOLUMES` va `SLAYD_VOLUMES` massivlarida:

| Mustaqil ish | Narx | | Slayd | Narx |
|---|---|---|---|---|
| 5-10 bet | 4 000 so'm | | 8-10 slayd | 4 000 so'm |
| 10-15 bet | 5 000 so'm | | 10-15 slayd | 6 000 so'm |
| 15-20 bet | 7 000 so'm | | 15-20 slayd | 8 000 so'm |
| 20-25 bet | 9 000 so'm | | 20-25 slayd | 10 000 so'm |
| 25-30 bet | 12 000 so'm | | | |

Narxlar va bet oralig'i to'liq sozlanadi. Har bir foydalanuvchi **eng arzon tarifni bir marta bepul** olishi mumkin (`users.free_used` bayrog'i orqali nazorat qilinadi).

**Hajm qanday ta'minlanadi:** har bir tarifga mos `chapters` (bob soni) va `min_words`/`max_words` (so'z soni) belgilangan. AI'ga aniq shu ko'rsatkichlar bilan prompt yuboriladi. Agar AI juda qisqa javob bersa (kutilgan hajmning 60%idan kam), tizim **avtomatik ravishda kengaytirilgan so'rov bilan qayta uradi** (`MAX_LENGTH_RETRIES` — sozlanadi). Bu logikani real test bilan tekshirdim: qisqa javob → avtomatik retry → to'liq hajmli natija.

**1 bet ≈ 250 so'z** taxminiga asoslangan (14pt Times New Roman, 1.5 interval — hujjat shu andozada yaratiladi). Bu chama, aniq bet soni matn mazmuniga (jadval, ro'yxat va h.k.) qarab ozgina farq qilishi mumkin.

## Hujjat formatlash (DOCX)

- Shrift: **Times New Roman, 14pt**
- Interval: **1.5 qator oralig'i**
- Matn: **ikki tomonlama tekislangan (justify)**, birinchi qatorda abzats chekinishi
- **Bet raqami** pastki qismda avtomatik (PAGE maydoni)
- Sarlavha markazda, bo'limlar qalin shriftda

## Ma'lumotlar to'g'riligi haqida muhim eslatma

AI xato qilishi (hallutsinatsiya) mumkin — ayniqsa **aniq raqamlar, sanalar va adabiyotlar ro'yxatida**. Shu sababli:
- Tizim promptida AI'ga aniq bilmagan faktni **o'ylab topmaslik**, o'rniga umumiy tarzda yozish buyurilgan
- Adabiyotlar ro'yxati uchun soxta kitob/muallif nomlari o'rniga umumiy ta'rif ishlatiladi
- Har bir tayyor hujjat bilan birga foydalanuvchiga **"faktlarni tekshirib chiqing"** ogohlantiruvi yuboriladi (worker.php'da caption ichida)

Bu AI yordamida yaratiluvchi har qanday kontent uchun umumiy cheklov — **100% faktik aniqlikni hech qanday AI kafolatlay olmaydi**, shuning uchun foydalanuvchiga xushmuomalalik bilan ogohlantirish berish eng to'g'ri yondashuv.

## Boblar/slaydlar bir-birini takrorlamasligi

Katta hajm so'ralganda (masalan 25-30 bet) AI ko'pincha "hajmni to'ldirish" uchun bir xil fikrni boshqa so'zlar bilan qayta yozadi — natijada 1-bet va 2-bet deyarli bir xil bo'lib qolishi mumkin edi. Bunga qarshi ikki bosqichli himoya qo'shildi:

1. **Oldindan aniq taqsimot** — har bir bobga alohida "burchak" beriladi (`chapterAngles()`): nazariy asoslar → tarixi → amaliyoti → zamonaviy yondashuvlar → muammolari → qiyosiy tahlil → istiqbollari. AI shu tartibda yozishga majburlanadi, shuning uchun boshidanoq boblar boshqa-boshqa mavzuga qaratiladi.
2. **Generatsiyadan keyingi tekshiruv** — `hasRepeatedChapters()` har bir bob juftligini so'z-o'xshashligi bo'yicha solishtiradi (umumiy/qisqa so'zlarsiz). Agar ikkita bob 45%dan ortiq o'xshab qolsa — tizim buni "takrorlangan" deb hisoblab, **aniq shu haqda ogohlantirish bilan qayta so'raydi** (`MAX_LENGTH_RETRIES` marta). Xuddi shu mexanizm slaydlar uchun ham ishlaydi (`hasRepeatedSlides()`).

Bu logikani sun'iy ravishda takrorlangan matn bilan sinab ko'rdim: tizim 0.88 o'xshashlikni to'g'ri ushladi va qayta so'rov natijasida boblar butunlay farqli chiqdi (0-0.09 o'xshashlik).

## Titul varag'i, Reja va talaba ma'lumotlari

Endi mustaqil ish hujjati haqiqiy universitet andozasiga to'liq mos:

1. **Titul varag'i** (1-bet): universitet nomi, "MUSTAQIL ISH", mavzu, bajaruvchi F.I.Sh, guruh, yil
2. **Reja** (2-bet): hujjatning barcha bo'limlari avtomatik ro'yxatlanadi (Kirish → boblar → Xulosa → Foydalanilgan adabiyotlar) — bu ro'yxat AI yaratgan bob sarlavhalaridan **avtomatik** tuziladi, alohida so'rashning hojati yo'q
3. **Foydalanilgan adabiyotlar** bo'limi avtomatik qo'shiladi (soxta kitob/muallif o'ylab topmasdan, umumiy va halol matn bilan)

**F.I.Sh, universitet va guruh faqat BIR MARTA so'raladi** — foydalanuvchi birinchi buyurtma berayotganda hajmni tanlagach, bot ketma-ket 3 ta savol beradi (F.I.Sh → universitet → guruh), so'ng bu ma'lumotlar `users` jadvalida saqlanadi va **barcha keyingi buyurtmalarda avtomatik ishlatiladi** — qayta so'ralmaydi. O'zgartirmoqchi bo'lsa, foydalanuvchi istalgan vaqtda "⚙️ Profil" tugmasi yoki `/profil` buyrug'i orqali qayta kiritishi mumkin.

Taqdimotlarda (slayd) ham xuddi shu ma'lumotlar birinchi slaydga "Bajaruvchi: ..., Guruh: ..." tarzida avtomatik qo'shiladi.

**Eski o'rnatilgan botlar uchun:** agar avval eski versiyani ishga tushirgan bo'lsangiz, `db.php` yangi ustunlarni (`full_name`, `university`, `group_name`) avtomatik va xavfsiz qo'shadi — sqlite faylini o'chirishning hojati yo'q.

## Kengaytirish uchun keyingi qadamlar

- ✅ Click va Payme to'lov integratsiyasi — endi **har bir buyurtma narxi hajmga qarab** hisoblanadi
- ✅ Slaydlarga avtomatik rasm qo'shish
- ✅ Hajm nazorati (so'z/slayd soni) va avtomatik qayta urinish
- Referal tizim, guruh admin panel, PDF eksport — alohida modul sifatida qo'shiladi

## To'lov tizimi (Click + Payme)

1. `config.php`da to'ldiring:
   - `CLICK_MERCHANT_ID`, `CLICK_SERVICE_ID`, `CLICK_SECRET_KEY` — my.click.uz kabinetidan
   - `PAYME_MERCHANT_ID`, `PAYME_KEY` — business.payme.uz kabinetidan
2. Click kabinetida "Webhook URL" sifatida: `https://domeningiz.uz/click.php`
3. Payme kabinetida "Webhook URL" sifatida: `https://domeningiz.uz/payme.php`
4. Foydalanuvchi oqimi: turini tanlaydi (mustaqil ish/slayd) → hajmni tanlaydi (narx shu yerda ko'rinadi) → mavzuni yozadi → buyurtma yaratiladi → Click yoki Payme havolasini bosib to'laydi → to'lov tasdiqlangach buyurtma **avtomatik navbatga qo'yiladi** va tayyor bo'lgach yuboriladi.
5. Har bir buyurtma **alohida** to'lov yozuviga ega (`payments.job_id`) — narx hajmga qarab avtomatik hisoblanadi, umumiy "kredit" tushunchasi yo'q.

**Muhim:** ikkala integratsiya ham **idempotent** qilib yozilgan — Click yoki Payme bir xabarni ikki marta yuborib qo'ysa ham (bu amalda tez-tez bo'ladi), buyurtma ikki marta ishga tushmaydi.

## Slaydlarga avtomatik rasm

`config.php`dagi `IMAGE_PROVIDERS` massiviga Unsplash/Pexels/Serper.dev kalitlaridan kamida bittasini kiriting. Mavzu avval AI orqali inglizchaga tarjima qilinadi, so'ng rasm qidiriladi. **Hech qanday kalit ishlamasa ham bot yiqilmaydi** — rasmsiz, faqat matnli slayd yaratiladi.

## Real audit orqali topilgan va tuzatilgan xatolar

Butun botni Start'dan tortib to hujjat yaratilishigacha real HTTP so'rovlar bilan qadam-baqadam sinab, quyidagi jiddiy xatolarni topib tuzatdim:

1. **API kalitlar keyinroq yangilansa bazaga qo'shilmasdi** — `seedApiKeys()` faqat bazani birinchi yaratishda ishlar edi. Agar botni avval placeholder kalitlar bilan sinab ko'rib, keyin `config.php`ga haqiqiy kalit qo'ysangiz, u hech qachon ishlamasdi. Endi har safar tekshiriladi (real test bilan tasdiqlandi).
2. **Ikki marta to'lov qilish xavfi (moliyaviy xato)** — har bir buyurtma uchun ham Click, ham Payme havolasi berilardi. Agar foydalanuvchi ikkalasidan ham to'lasa, pul ikki marta yechilib ketishi mumkin edi. Endi biri to'langan zahoti ikkinchisi avtomatik bekor qilinadi va **haqiqiy pul yechilishidan OLDIN** (Click Prepare / Payme CheckPerformTransaction bosqichida) rad etiladi — bu ikkala yo'nalishda ham (avval Click, avval Payme) real test bilan tasdiqlandi.
3. **Spam-himoya kodi hech narsa qilmas edi** — cooldown tekshiruvi bor edi, lekin ichi bo'sh qavs bo'lib, hech qanday cheklov qo'ymasdi. Endi haqiqiy buyurtma yaratishdan oldin tekshiradi (real test bilan tasdiqlandi).
4. **Telegram xabarlari mavzuda maxsus belgi bo'lsa yuborilmay qolishi mumkin edi** — foydalanuvchi mavzuda `<`, `>` yoki `&` ishlatsa (masalan "a < b" kabi matematik mavzu), Telegram buni noto'g'ri HTML deb hisoblab, xabarni butunlay rad etardi. Endi barcha foydalanuvchi matni xabarga qo'shishdan oldin to'g'ri escape qilinadi (`Telegram::escape()`) — xavfli belgili mavzu bilan real test qilindi.
5. **Rasm formati har doim .jpg deb belgilanardi** — agar yuklab olingan rasm aslida PNG bo'lsa, noto'g'ri kengaytma bilan saqlanardi. Endi rasmning haqiqiy formati aniqlanadi (`getimagesizefromstring`), qo'llab-quvvatlanmaydigan format bo'lsa xavfsiz tarzda o'tkazib yuboriladi.

Bu audit haqiqiy PHP serverni ishga tushirib, haqiqiy HTTP so'rovlar (webhook, worker, to'lov webhooklari) bilan amalga oshirildi — nazariy emas.

## Hamyon balansi (yangi asosiy to'lov usuli)

Endi botda **ichki hamyon balansi** bor — bu asosiy to'lov usuliga aylandi:

1. Foydalanuvchi hajm tanlaydi → bot avval **balansini tekshiradi**
2. Agar balans yetarli bo'lsa — darhol o'sha summa yechiladi va buyurtma navbatga qo'yiladi (Click/Payme'ga qayta-qayta o'tishning hojati yo'q)
3. Agar balans yetmasa — "Hisobingizni to'ldiring" oynasi chiqadi, kerakli summa (yetishmayotgan qism) avtomatik hisoblab ko'rsatiladi
4. To'ldirish 3 usulda mumkin:
   - **Click** — avtomatik, darhol tasdiqlanadi
   - **Payme** — avtomatik, darhol tasdiqlanadi
   - **🧾 Chek yuborish** — foydalanuvchi to'lov skrinshotini yuboradi, bu **adminga** "✅ Tasdiqlash / ❌ Rad etish" tugmalari bilan yo'naltiriladi. Faqat `ADMIN_CHAT_ID`dagi shaxs tasdiqlay oladi (real test bilan tekshirilgan — oddiy foydalanuvchi tasdiqlay olmaydi)
5. To'ldirish tasdiqlangach (avtomatik yoki admin qo'lda), agar bu aynan bir buyurtma jarayonida boshlangan bo'lsa — **bot avtomatik o'sha buyurtmani davom ettiradi** (qayta hajm tanlashning hojati yo'q, to'g'ridan-to'g'ri mavzu so'raladi)

Mustaqil balans to'ldirish uchun "💰 Balans" tugmasi yoki `/balans` buyrug'i orqali kirish mumkin (tayyor summalar: `TOPUP_PRESETS` da, yoki o'zi kiritishi mumkin).

### Texnik tafsilot: buyurtma to'lovi bilan balans to'ldirish aralashmasligi

Ikkalasi ham Click/Payme orqali ishlagani uchun ID to'qnashmasligi kerak edi:
- Buyurtma to'lovlari (`payments` jadvali): Click'da `"o{id}"` prefiksi, Payme'da `account.order_id`
- Balans to'ldirish (`topups` jadvali): Click'da `"t{id}"` prefiksi, Payme'da `account.topup_id`

`click.php`/`payme.php` kelgan so'rovni shu belgilar bo'yicha to'g'ri sinfga yo'naltiradi — bu real HTTP so'rov bilan sinaldi.

**Eslatma:** eski "har bir buyurtma uchun alohida Click/Payme havolasi" oqimi (`lib/ClickPayment.php`, `lib/PaymePayment.php`, `lib/PaymentHelpers.php`) hozir asosiy oqimda ishlatilmaydi, lekin fayllar o'chirilmagan — xohlasangiz balans tizimi o'rniga/bilan birga qayta yoqish mumkin.

## Muvaffaqiyatsiz buyurtmada avtomatik pul qaytarish

Agar buyurtma barcha qayta urinishlardan keyin ham muvaffaqiyatsiz tugasa (masalan AI uzoq vaqt ishlamasa):
- **Balansdan to'langan bo'lsa** — summa avtomatik balansga qaytariladi (darhol, admin kutmasdan — chunki bu endi ichki DB operatsiyasi, tashqi to'lov tizimidan qaytarish talab qilinmaydi)
- **Bepul urinish ishlatilgan bo'lsa** — bepul urinish huquqi qaytariladi, foydalanuvchi qayta urinishi mumkin

Bu ikkala holat ham sun'iy "AI doim xato beradi" stsenariysi bilan real test qilindi — balans va bepul-huquq to'g'ri qaytarilgani tasdiqlandi.

## Yana bir jiddiy xato: Payme'ning haqiqiy pul yechish tasdig'i ishlamas edi

Real HTTP so'rov bilan sinaganda aniqlandi: Payme'ning `PerformTransaction` (bu — pul haqiqatan yechilganini tasdiqlovchi eng muhim so'rov) hech qachon `account` maydonini yubormaydi, faqat o'zining ichki tranzaksiya ID'sini. `payme.php`dagi dastlabki yo'naltirish mantig'i faqat `account.topup_id`/`account.order_id` mavjudligiga qarab qaror qilardi — shu sabab `PerformTransaction` doim noto'g'ri (buyurtma) sinfga tushib, "tranzaksiya topilmadi" xatosi bilan muvaffaqiyatsiz tugardi.

**Bu degani: agar tuzatilmaganida, Payme orqali hech qanday balans to'ldirish yoki buyurtma to'lovi HAQIQIY HAYOTDA hech qachon yakunlanmas edi** — Click bilan solishtirganda buni sezish qiyinroq, chunki test paytida `CreateTransaction` muvaffaqiyatli javob qaytaradi va faqat keyingi (`PerformTransaction`) bosqichda buziladi.

Tuzatish: endi `account`siz kelgan so'rovlar (`PerformTransaction`, `CancelTransaction`, `CheckTransaction`) uchun tranzaksiya ID'si qaysi jadvalda (`payments` yoki `topups`) ro'yxatdan o'tganini bazadan qidirib, shunga qarab to'g'ri sinfga yo'naltiriladi. Bu ham buyurtma, ham balans to'ldirish uchun alohida-alohida real HTTP so'rovlar bilan qayta tasdiqlandi.

## Dizayn audit: LibreOffice orqali real render qilib tekshirildi

Oddiy python-docx/pptx tekshiruvi faqat XML strukturasini tasdiqlaydi, lekin fayl HAQIQIY dasturda qanday ko'rinishini emas. Shuning uchun LibreOffice (soffice) bilan haqiqiy PDF'ga aylantirib, sahifalarni rasm sifatida ko'zdan kechirdim — va bir nechta real dizayn xatosini topib tuzatdim:

1. **Slaydlar butunlay rangsiz va bo'sh ko'rinishda edi** — endi har slaydda yuqori/pastki ko'k aksent chiziq, sarlavha ko'k rangda, va pastki o'ng burchakda "N / jami" sahifa raqami qo'shildi.
2. **Rasm asl nisbatini yo'qotib, qutini to'liq cho'zib to'ldirardi** — agar haqiqiy fotosurat kvadrat yoki gorizontal bo'lsa, bu uni cho'zib/torayib buzardi. Endi rasm nisbati (`getimagesize` orqali) hisoblab, qutiga MOS RAVISHDA (cho'zmasdan) markazlashtirib joylashtiriladi — bu real fayl bilan tekshirilib, tasdiqlandi.
3. **Mustaqil ish titul varag'ida sahifa raqami ko'rinib turardi** — bu ko'pchilik universitet andozalarida noto'g'ri hisoblanadi. Endi `w:titlePg` orqali faqat 1-betda sahifa raqami yashiriladi, 2-betdan (Reja) boshlab to'g'ri raqamlanish davom etadi (1 dan emas, 2 dan — sahifalar soni buzilmaydi).

Bundan tashqari: 22-25 slaydli haqiqiy hajmdagi taqdimot, 7 bobli 25-30 betlik mustaqil ish, bo'sh punktli slayd, juda uzun sarlavha, va maxsus belgili (`<`, `>`, `&`, qo'shtirnoq) sarlavhali hujjatlar — barchasi LibreOffice orqali xatosiz PDF'ga aylantirildi, ya'ni hech qaysi holatda fayl "buzilgan" deb topilmadi.

## Slayd dizaynlari — 12 xil ko'rinish, 6 xil shakl, rasm orqali tanlash

Endi taqdimotlar nafaqat rang, balki **shakl** jihatidan ham bir-biridan farq qiladi. `config.php`dagi `PPTX_DESIGNS` massivida 12 ta dizayn bor — har biri 6 ta mutlaqo boshqa dekorativ shakldan biriga ega (har biri 2 xil rangda):

1. **Chiziqlar** (`bars`) — yuqori va pastki rangli ingichka chiziq
2. **Panel** (`sidebar`) — chap tomonda qalin rangli vertikal panel
3. **Doira** (`corner_circle`) — yuqori-o'ng va pastki-chap burchaklarda yarim ko'ringan rangli doiralar
4. **Quti** (`title_box`) — sarlavha to'liq rangli fon ustida, oq matn bilan
5. **Ramka** (`frame`) — butun slaydni o'rab turgan rangli kontur
6. **Qalin panel** (`bottom_thick`) — pastda qalin rangli panel + yuqori-chapda kichik uchburchak belgisi

Har bir shakl 2 xil rangda takrorlanadi (masalan "Qizil doira" va "Oltin doira"), shuning uchun 12 ta dizaynning barchasi haqiqatan bir-biridan farq qiladi — na ikkitasi bir xil rangda, na ikkitasi bir xil shaklda.

**Qanday ishlaydi:**
1. Foydalanuvchi "📊 Slayd" tanlab, hajmni tanlagach (va profili to'liq, balansi yetarli bo'lsa) — bot 12 ta dizaynni **bitta oldindan tayyorlangan kollaj rasm** ko'rinishida yuboradi (`assets/design_preview.jpg`, LibreOffice yordamida DEV BOSQICHIDA bir marta yaratilgan — production serverda har safar qayta chizilmaydi, shared hosting'da ham tez ishlaydi)
2. Rasm ostida 1-12 raqamli tugmalar — foydalanuvchi ko'rib, birini bosadi
3. Tanlangan dizayn `users.pending_design_key`ga saqlanadi, keyin mavzu so'raladi, va yaratilgan `jobs.design_key`ga yoziladi
4. Worker shu dizaynni o'qib, `PptxGenerator::generate($slides, $design)`ga uzatadi — rang VA shakl shunga mos generatsiya qilinadi

Har bir yangi shakl (doira, quti, ramka, qalin panel) rasmli va rasmsiz slaydlar bilan, LibreOffice orqali haqiqiy render qilib tekshirildi — matn/rasm joylashuvi hech qaysi shaklda bir-biriga qoplashmaydi.

**Yangi dizayn qo'shish uchun:** `config.php`dagi `PPTX_DESIGNS`ga yangi yozuv qo'shing (kerak bo'lsa `PptxGenerator.php`da yangi `shape*()` funksiyasi bilan yangi shakl ham qo'shish mumkin) va `assets/design_preview.jpg` kollajini yangilang.

## KRITIK XATO: butun bot har bir so'rovda "o'zini buzayotgan" edi

Dizayn tanlash funksiyasini qo'shish jarayonida qilingan bitta tahrirda **funksiya e'lon qatori tasodifan yo'qolib qolgan edi**:

```php
function handleDesignChosen(...) { ... }
{                          // <- BU YERDA "function handleTopupAmountChosen(...): void" YO'QOLGAN EDI!
    if ($val === 'custom') { ...
```

PHP'da bu **sintaksis xatosi HISOBLANMAYDI** — chunki PHP'da funksiyadan tashqarida turgan `{ ... }` bloki oddiy "darhol bajariladigan kod bloki" sifatida qabul qilinadi. Natijada bu blok **HAR BIR so'rovda, har doim, avtomatik ishga tushardi** — undefined o'zgaruvchilar bilan (`$val`, keyin ba'zida `$chatId` ham) va oxir-oqibat foydalanuvchining **endigina to'g'ri o'rnatilgan holatini** (`state`) `"awaiting_topup_method:0:_none:-"` degan noto'g'ri qiymatga almashtirib qo'yardi, hamda ikkinchi (keraksiz va buzuq) xabar yuborishga urinardi.

**Bu eng zararli turdagi xato edi** — chunki:
- Hech qanday PHP sintaksis xatosi bermaydi (`php -l` "No syntax errors" deb ko'rsatadi)
- Har bir so'rovda ishlaydi (faqat bitta funksiyaga emas, BUTUN botga ta'sir qiladi)
- Tashqi ko'rinishda bot "ishlayotgandek" tuyuladi (HTTP 200 qaytaradi, xato bermaydi)

**Qanday topildi:** Yangi dizayn-tanlash oqimini real HTTP so'rovlar bilan sinaganimda, `/start` bosgandan keyin foydalanuvchi holati kutilganidek `idle` emas, balki tasodifiy `awaiting_topup_method:...` bo'lib chiqishini payqadim. Buni maxsus debug-log qatorlari qo'shib, qadam-baqadam kuzatib, aynan shu orfangan blokni aniqladim.

**Ehtiyot chorasi sifatida:** endi butun `webhook.php`, `worker.php` va `lib/*.php` fayllarida shunga o'xshash "funksiya nomisiz qolgan blok" borligini avtomatik qidiradigan tekshiruv (`}` dan keyin darhol `{` kelishi) ishga tushirilib, boshqa hech qanday joyda bu muammo yo'qligi tasdiqlandi.

## Dizayn sifatini oshirish — "cards" uslubi (professional namunalar asosida)

Foydalanuvchi taqdim etgan professional taqdimot namunasi asosida barcha 12 dizaynni yangi, ancha yuqori sifatli **"cards"** uslubiga o'tkazdim:

- **Muqova va yakuniy slayd** — to'liq rangli fon, katta oq sarlavha (masalan mavzu nomi), pastda kichik nuqta bilan ajratilgan qo'shimcha ma'lumot (bajaruvchi/guruh)
- **Ichki (mazmunli) slaydlar** — och tusdagi fon, mazmun OQ, yumaloq burchakli, rangli chegarali "karta" ichida; rasm ham xuddi shunday yumaloq burchakli kartada, **asl nisbati saqlangan holda** (cho'zilmaydi)
- **Avtomatik "Rahmat!" yakuniy slayd** — endi har bir taqdimotga avtomatik qo'shiladi (oldin yo'q edi)
- Sahifa raqami har doim mos rangda (och fonda kulrang, to'q fonda oq) — kontrast muammosi yo'q

Bu o'zgarish LibreOffice orqali real render qilib, barcha 12 rangda, rasmli/rasmsiz, bo'sh punktli holatlarda sinovdan o'tkazildi.

## Mustaqil ish — akademik andozaga yaqinlashtirish

Foydalanuvchi taqdim etgan namuna hujjat asosida quyidagilar qo'shildi:

1. **Vazirlik nomi** — titul varag'ida universitet nomidan yuqorida (`config.php`dagi `MINISTRY_NAME` orqali sozlanadi, kerak bo'lmasa bo'sh qoldiriladi)
2. **"20XX-20XX o'quv yili"** formati — oddiy "2026 - yil" o'rniga, O'zbekiston OTMlari standartiga mos (joriy sanadan avtomatik hisoblanadi, sentyabrdan yangi o'quv yili boshlanadi deb olinadi)
3. **Tartibli (raqamlangan) adabiyotlar ro'yxati** — oldingi bitta umumiy paragraf o'rniga endi 4 ta raqamlangan umumiy toifa (darsliklar, ilmiy maqolalar, me'yoriy hujjatlar, internet resurslari) + eslatma. **Muhim: mazmuni hamon halol** — aniq soxta muallif/kitob nomi/yil hech qachon o'ylab topilmaydi, faqat tashqi ko'rinishi (raqamlangan ro'yxat) professionalroq qilindi. Bu ataylab shunday qilindi: aniq soxta manbalar ko'rsatish ustoz tekshirganda "bunday kitob umuman yo'q" degan yanada jiddiyroq xavf tug'dirar edi.

## JIDDIY XATO: AI javobi kesilib, buzuq JSON hujjatga tushib qolishi mumkin edi

Foydalanuvchi yuborgan real hujjatda aniqlandi: ba'zan "Kirish" bo'limida tayyor matn o'rniga xom JSON matni (`{"kirish": "...", "boblar": [...` kabi) ko'rinib qolardi.

**Sabab:** AI so'roviga `max_tokens` (javob uzunligi chegarasi) umuman belgilanmagan edi. Katta hajmli mustaqil ish (masalan 25-30 bet, 7500 so'zgacha) so'ralganda, provider javobni o'zining standart (ancha kichik) chegarasi bilan **o'rtada kesib qo'yardi** — natijada JSON to'liq yopilmay qolar, `json_decode()` muvaffaqiyatsiz tugar, va eski kod bunday holatda **xom, buzuq matnni to'g'ridan-to'g'ri "Kirish" bo'limiga tashlab qo'yardi**.

**Tuzatish (ikki bosqichli):**
1. **Ildiz sabab tuzatildi** — endi `max_tokens` so'ralgan hajmga qarab avtomatik va yetarlicha katta hisoblanadi (`lib/AIClient.php`, `generateText()`), shuning uchun kesilib qolish ehtimoli keskin kamaydi. Agar provider baribir `finish_reason=length` bilan javob qaytarsa, bu holat diagnostika uchun logga yoziladi.
2. **Himoya qatlami qo'shildi** — agar AI javobi baribir JSON sifatida o'qilmasa (masalan boshqa sababdan), endi bu **hech qachon xom matn sifatida ko'rsatilmaydi**. Buning o'rniga "bo'sh" natija deb hisoblanadi, tizim avtomatik qayta so'raydi (mavjud urinish mexanizmi orqali), va faqat barcha urinishlar tugagandan keyin toza, tushunarli xabar ko'rsatiladi — hech qachon buzuq JSON emas.

Bu ikkala tuzatish ham foydalanuvchi yuborgan aynan shu ssenariyni sun'iy ravishda qayta hosil qilib (JSON o'rtada kesilgan holatda) sinovdan o'tkazildi — birinchi (buzuq) urinishdan keyin tizim avtomatik qayta so'radi va yakuniy natijada hech qanday JSON izi qolmadi.

## Yangi xizmatlar (bu bosqichda qo'shildi)

1. **Referal tizimi** — `/referal` yoki "🎁 Referal" tugmasi orqali shaxsiy havola olinadi (`t.me/BOT_USERNAME?start=ref<ID>`). Taklif qilingan kishi birinchi **pullik** buyurtmasini bergan zahoti, taklif qilgan kishiga `REFERRAL_BONUS` (config.php, standart 3000 so'm) avtomatik balansga qo'shiladi. `config.php`da `BOT_USERNAME`ni to'ldiring.
2. **Referat** — mustaqil ishdan qisqaroq/arzonroq tur (2-3 va 3-5 bet, `REFERAT_VOLUMES`). Xuddi shu andoza (titul, Reja, adabiyotlar), faqat titulda "REFERAT" yoziladi.
3. **Baholash tizimi** — har bir tayyor buyurtmadan keyin ⭐1-5 so'raladi. 1-2 baho kelsa, admin darhol xabar oladi.
4. **Admin `/stats`** — foydalanuvchilar soni, buyurtmalar holati, daromad (buyurtma + balans to'ldirish), o'rtacha baho — faqat `ADMIN_CHAT_ID` uchun ishlaydi.
5. **Menyu/xabarlar dizayni** — barcha xabarlar emoji va `<b>` formatlash bilan qayta ishlandi, tugmalar 2 ustunli qulay joylashuvga o'tkazildi.

Barchasi real HTTP oqim bilan sinaldi: referal bog'lanishi, bonus berilishi, reyting (jumladan begona user boshqa buyurtmani baholay olmasligi), admin statistikasi, va mustaqil ish/slayd regressiyasi.

**Keyingi bosqichda qo'shsa bo'ladi:** VIP obuna, guruh chegirmasi, antiplagiat tekshiruvi, ovozli xabar orqali mavzu, rus tili, himoya uchun savol-javob, broadcast.

## Xilma-xil slayd maketlari va batafsil matn (yangi)

Avval barcha slaydlar bir xil ko'rinishda (sarlavha + 3-5 ta qisqa punkt) chiqar edi. Endi:

**8 xil maket** (`lib/PptxLayouts.php`) — AI har slayd uchun mos turni tanlaydi:

| Tur | Ko'rinishi |
|---|---|
| `matn` | izoh-band + raqamli qatorlar kartasi (+ rasm, chap/o'ng almashinib turadi) |
| `tarif` | katta rangli ta'rif bloki + "asosiy belgilar" mini-kartalari |
| `bosqichlar` | strelka (chevron) shaklidagi gorizontal jarayon |
| `xronologiya` | vertikal chiziq va doira-nuqtali ketma-ketlik |
| `kartalar` | rangli sarlavhali kartalar setkasi (2x2, 3x1, 3+2, 3x2) |
| `taqqoslash` | ikki ustunli "VS" taqqoslash |
| `xulosa` | chap rangli panel + o'ngda raqamli xulosalar |
| `reja` | raqamli reja (slaydlar sarlavhalaridan avtomatik) |

Muqova va "Rahmat!" slaydlari ham dekorativ shakllar bilan yangilandi; muqova endi har doim chiqadi.

**Batafsil matn:** har punkt endi *sarlavha + 1-2 jumlali tushuntirish* (15-28 so'z), har slaydda izoh jumlasi bor.
Generatsiya 2 bosqichli: avval butun taqdimot rejasi (sarlavha + tur + fikr), so'ng slaydlar 4 tadan guruhlab
to'ldiriladi (`generateSlideOutline()` / `generateSlideBatch()`). Bu takrorlanishni kamaytiradi va javob kesilib
qolishining oldini oladi. AI javob bermasa ham bot yiqilmaydi (umumiy reja va rejadagi fikrga tushadi).

Shrift o'lchami matn uzunligiga qarab avtomatik tanlanadi (`PptxLayouts::fit()`), shuning uchun matn qutidan chiqib ketmaydi.
Eski formatdagi (`kind` bermagan) slaydlar avvalgidek ishlayveradi.

## 12 ta dizayn — har biri o'z "terisi" (skin) bilan

Dizaynlar endi faqat rang bilan farq qilmaydi: har birida sarlavha joyi, fon bezaklari, karta shakli, raqam belgisi,
shrift, muqova va "Rahmat!" slaydi alohida. Sozlama — `config.php` → `PPTX_DESIGNS` (`'skin' => '...'`),
shakllar — `lib/PptxLayouts.php` → `SKINS`.

| № | Dizayn | Teri | Xususiyati |
|---|---|---|---|
| 1 | Ko'k | classic | yumaloq kartalar, doira belgilar, strelkali bosqichlar |
| 2 | Yashil | band | tepada keng rangli lenta, kvadrat belgilar, chap chiziqli kartalar |
| 3 | Qizil | sidebar | chap rangli ustun, tepa chiziqli kartalar, qirrali bosqichlar |
| 4 | Binafsha | dark | qora fon, halqa bezaklar, shaffof kartalar |
| 5 | Apelsin | snip | qirrali burchaklar, soyali kartalar, romb belgilar |
| 6 | Feruza | underline | to'lqin, rangli fonli kartalar, kapsula bosqichlar |
| 7 | Pushti | bubble | markazda sarlavha, katta yumaloq kartalar, pufakchalar |
| 8 | Kulrang | minimal | oq fon, serif sarlavha, ingichka chiziqlar |
| 9 | Oltin | frame | qo'sh ramka, serif shrift, markazlashgan sarlavha |
| 10 | Tungi ko'k | navy | qora fon, yorqin ko'k aksent, katta shaffof raqam |
| 11 | Jigarrang | paper | daftar: tilmoch, buklangan burchakli kartalar |
| 12 | Havorang | tech | raqam bloki, olti burchaklar, kesilgan burchakli kartalar |

Yangi dizayn qo'shish: `SKINS` ga yangi teri yozing va `PPTX_DESIGNS` ga `'skin'` bilan qo'shing; namuna rasmini
(`assets/design_preview.jpg`) ham yangilashni unutmang.

Xavfsizlik: juda uzun matn avtomatik qisqartiriladi, shrift kichraytiriladi, bo'sh/noto'g'ri slayd bot'ni to'xtatmaydi.
Fayl PowerPoint talab qiladigan qat'iy OOXML sxemasi bo'yicha tekshirilgan (theme'dagi `ea`/`cs` shrift yetishmasligi ham tuzatildi).

---

# ✏️ Tahrirlovchi (Telegram WebApp) — o'rnatish va xavfsizlik

Tayyor taqdimot fayli ostida **«✏️ Tahrirlash»** tugmasi chiqadi. Bosilganda Telegram ichida to'liq tahrirlovchi ochiladi;
tahrirdan keyin **«Yuborish ➤»** — yangilangan `.pptx` chatga qayta tashlanadi (yana o'sha tugma bilan).
Oldingi taqdimotlar uchun: `/tahrir` buyrug'i (oxirgi 6 ta).

## O'rnatish (3 qadam)
1. `config.php` da **faqat HTTPS** manzilni yozing:
   `define('PUBLIC_BASE_URL', 'https://sizning-domeningiz.uz');`  (bo'sh qolsa — tahrirlash tugmasi ko'rinmaydi)
2. Veb-server: Apache bo'lsa `.htaccess` fayllari tayyor. **Nginx** bo'lsa `nginx.conf.example` dagi qoidalarni qo'shing.
3. PHP kengaytmalari: `dom`, `zip`, `gd`, `pdo_sqlite`, `curl`, `mbstring` (odatda bor).
   Keyin admin sifatida botga `/tekshir` yozing — baza, log va kod internetdan ochiq qolmaganini tekshiradi.

Qo'shimcha (tavsiya): webhook soxtalashtirilmasligi uchun `WEBHOOK_SECRET` yozing va webhookni
`...setWebhook?url=...&secret_token=<SHU_QIYMAT>` bilan qayta o'rnating.

## Xavfsizlik tuzilmasi
| Qatlam | Nima qiladi |
|---|---|
| Havola hech qachon ko'rinmaydi | Manzil faqat Telegram `web_app` tugmasi ichida; xabarda matn sifatida yo'q. Oyna ochilgach tugmali xabar chatdan **o'chiriladi** |
| Qisqa muddatli bir martalik token | 256 bit tasodifiy, bazada faqat SHA-256 hash, 15 daqiqa, yangi so'rov eskisini bekor qiladi |
| Telegram imzosi (HMAC) | Token bor bo'lsa ham, faqat **egasining** Telegram akkauntidan ochilgandagina kirish beriladi — havola boshqaga tushsa ham foydasiz |
| Sessiya | 256 bit token (Authorization header), 45 daqiqa harakatsizlik / 6 soat mutlaq, bitta faol sessiya, brauzer (User-Agent) o'zgarsa bekor |
| Yashirinlik | Token yo'q/yaroqsiz bo'lsa, hamma yo'llar bir xil `404 Not found` qaytaradi; papka nomini `EDITOR_PATH` bilan o'zgartirish mumkin |
| Tezlik cheklovi | IP / token / taqdimot bo'yicha (brute-force → 429), barcha harakatlar `edit_audit` jurnalida |
| Brauzer himoyasi | CSP (`default-src 'none'`, nonce, eval yo'q, faqat o'z serveriga ulanish), faqat Telegram ramkasi, `no-store`, `no-referrer`, `nosniff`, HSTS |
| Kiruvchi ma'lumot | Hamma narsa serverda qat'iy tekshiriladi (rang, shrift, geometriya, uzunlik, miqdor); XML'ga faqat tekshirilgan qiymatlar yoziladi |
| Rasmlar | Turi tekshiriladi va **GD bilan qayta kodlanadi** (zararli qo'shimcha, EXIF, polyglot yo'qoladi), 8 MB / 40 ta chegarasi, tasodifiy nom, yopiq papka |
| Egalik | Har bir so'rovda sessiyadagi taqdimot egasi tekshiriladi; boshqa foydalanuvchining rasm/taqdimotiga yo'l yo'q |

## Tahrirlovchi imkoniyatlari
- **Ikki rejim:** *oddiy* (sarlavha/punkt matnlarini forma orqali tahrirlab, maketni almashtirish) va *erkin* (har bir element ustida to'liq nazorat).
- **Elementlar:** matn qutisi, 26 xil shakl, chiziq/strelka, rasm (yuklash, qirqish, ramka, maska), jadval, soya, gradient.
- **Matn:** shrift (15 ta), o'lcham, qalin/qiya/tagiga chizish, rang, tekislash, ro'yxat, qator va harf oralig'i — so'z darajasida.
- **Joylashtirish:** sudrash, 8 tutqichli o'lcham, burish, snap-yo'naltirgichlar, to'r, tekislash/teng taqsimlash, qatlam tartibi, qulflash/yashirish, ko'p tanlash, nusxa/qo'yish.
- **Slaydlar:** qo'shish (9 xil maket), nusxa, o'chirish, sudrab tartiblash, fon, o'tish effektlari, dizaynni (12 ta) almashtirish.
- **Boshqa:** undo/redo (80 qadam), avtosaqlash, qidirish-almashtirish, namoyish rejimi, tezkor tugmalar, telefon va kompyuter uchun moslashgan.
- **Chiqish fayli** server tomonida qayta yig'iladi va yuborishdan oldin tekshiriladi (XML butunligi, bog'lanishlar) — PowerPoint / LibreOffice / Google Slides'da ochiladi.


---

# ⚙️ Ish mexanizmi (cron'siz) va AI zaxira tizimi

## Qanday ishlaydi
- Har buyurtma kichik **qadamlarga** bo'linadi: reja → bo'limlar (har biri ~500 so'z) → fayl → yuborish. Mustaqil ish endi bitta ulkan
  so'rov emas; har qadam natijasi bazaga yoziladi, **uzilsa ham qolgan joyidan davom etadi** (hosting qayta ishga tushsa ham).
- `tick.php` — qisqa (≈40s) so'rov. Botning o'zi uni chaqiradi: yangi buyurtma kelganda, **har bir Telegram xabarida** (watchdog),
  «🔄 Holatni yangilash» bosilganda va tick tugagach o'zini zanjirlab. Ish kechiksa (AI limiti) «uyquchi» tick belgilangan vaqtda uyg'otadi.
- **Parallel:** bir vaqtda `ENGINE_MAX_PARALLEL` ta tick, `ENGINE_MAX_ACTIVE_JOBS` ta buyurtma ishlaydi; qolganlari **navbatda** (o'rni ko'rsatiladi).
  Adolatli taqsimot: hamma buyurtma navbat bilan oldinga siljiydi, bitta foydalanuvchi ko'pi bilan `ENGINE_MAX_JOBS_PER_USER` ta.
- **Foizli progress:** foydalanuvchi xabarida `▰▰▰▱▱▱▱▱▱▱ 32%`, joriy bosqich, taxminiy qolgan vaqt, «🔄 Yangilash» va «✖️ Bekor qilish» (to'lov qaytariladi).
- **Hech narsa qotib qolmaydi:** tick o'lsa 150s dan keyin boshqa tick ishni davom ettiradi; ish oldinga siljimasa tick aylanib qolmaydi.

## AI zaxira tizimi (`lib/AIClient.php`)
Har bir "marshrut" = provayder + kalit + model. Biri limitga yetsa, **darhol keyingisiga o'tiladi**:

| Xato | Nima qilinadi |
|---|---|
| 429 (limit) | `Retry-After` bo'yicha sovitiladi (yo'q bo'lsa 30s→10 daq); kunlik limit — 30 daq |
| 5xx, timeout, tarmoq | qisqa sovitish (15s→5 daq), keyingisiga o'tiladi |
| 402/403/404 | 6 soatga chetlatiladi |
| 401 (yaroqsiz kalit) | butunlay o'chiriladi, admin'ga xabar |
| Hammasi band/limitda | ish **yiqilmaydi** — «AI band, kutilmoqda» deb kutadi va avtomatik davom etadi (`ENGINE_MAX_WAIT_SECONDS` gacha) |

Har kalitga parallel chaqiruv chegarasi bor (bitta kalitga hamma yopirilmaydi). `config.php` da bir provayderga bir nechta **model** berish mumkin:
```php
['name'=>'groq','endpoint'=>'https://api.groq.com/openai/v1/chat/completions',
 'models'=>['openai/gpt-oss-120b','llama-3.3-70b-versatile'],   // biri limitga yetsa — ikkinchisi
 'keys'=>['gsk_...','gsk_...'], 'max_parallel'=>2],
```

## 🖥 SYSDC hosting (ISPmanager) uchun yo'riqnoma
**Node.js kerak emas** — bot butunlay PHP; tahrirlovchining JavaScript'i foydalanuvchi brauzerida ishlaydi.
1. **PHP versiyasi:** panel → *WWW-domenlar* → saytingiz → PHP: **8.1–8.3**. Kengaytmalar: `curl, dom, zip, gd, mbstring, pdo_sqlite, openssl`.
2. **PHP sozlamalari** (panel → *PHP* → *Sozlamalar*): `memory_limit=256M`, `max_execution_time=120`, `upload_max_filesize=10M`, `post_max_size=12M`.
3. **HTTPS:** panel → *SSL-sertifikatlar* → Let's Encrypt. `config.php`: `PUBLIC_BASE_URL='https://domeningiz.uz'`.
4. Fayllarni sayt papkasiga yuklang, `storage/` ga yozish ruxsati (755/775) bering.
5. Webhook: `https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://domeningiz.uz/webhook.php&secret_token=<WEBHOOK_SECRET>`
6. Botga admin sifatida **`/tekshir`** yozing. U PHP, kengaytma, ruxsat, baza, ish mexanizmi, webhook, AI kalitlar va fayllar ochiqligini tekshiradi.
   **`/tekshir 60`** — hosting 60 soniyalik so'rovni uzmasligini ham sinaydi va `ENGINE_*` qiymatlarini tavsiya qiladi.

**Tarif bo'yicha tavsiya** (umumiy hostingda CPU/RAM cheklangan — bu kod emas, tarif chegarasi):
| Tarif | `ENGINE_MAX_PARALLEL` | `ENGINE_MAX_ACTIVE_JOBS` | Izoh |
|---|---|---|---|
| Start (5% CPU, 100 MB) | — | — | Bot uchun yetmaydi (PHP+GD+DOCX/PPTX) |
| Business (30% CPU, 800 MB) | 2 | 4 | 10 tagacha foydalanuvchi; ishlar navbat bilan, hammasi tugaydi |
| Premium/Ultimate | 3–4 | 8 | Ko'p foydalanuvchi |
| VDS | 5–8 | 12–20 | Eng yaxshi: yuklama oshsa shu tavsiya |

Hosting 30–60s da so'rovni uzsa: `ENGINE_TICK_SECONDS=20`, `ENGINE_HARD_SECONDS=50` qiling (ishlar baribir bajariladi, faqat tick qisqaroq bo'ladi).

## Sinov natijalari (sandbox, soxta AI/Telegram bilan)
- **15 ta katta ish bir vaqtda** (mustaqil ish 25–30 bet ×5, taqdimot ×5, referat…): 15/15 muvaffaqiyatli, xotira barqaror, hosting o'rtada qayta ishga tushirilganda ham davom etdi.
- **30 ta ish bir vaqtda** (tez AI): 30/30, `database is locked` xatosi **0**.
- **Kalit o'ldi (401) + kunlik limit (429) + 5xx:** ish yiqilmadi, qolgan marshrutda davom etdi. **Hamma provayder o'chiq:** ish kutdi va tiklangach avtomatik tugadi.
- Haqiqiy hostingda (SYSDC) va haqiqiy AI kalitlar bilan sinab ko'rmadim — shuning uchun `/tekshir` buyrug'i qo'shildi.
