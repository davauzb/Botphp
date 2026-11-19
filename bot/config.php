<?php
/**
 * StudyBot Pro — konfiguratsiya fayli
 * Barcha maxfiy ma'lumotlarni shu yerda saqlang (yoki .env fayldan o'qing).
 */

// ==== TELEGRAM ====
define('BOT_TOKEN', 'PUT_YOUR_TELEGRAM_BOT_TOKEN_HERE');
define('ADMIN_CHAT_ID', 0); // admin telegram ID (xatolar shu yerga keladi)
define('TELEGRAM_API', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('TELEGRAM_FILE_API', 'https://api.telegram.org/file/bot' . BOT_TOKEN . '/');

// ==== DATABASE ====
define('DB_PATH', __DIR__ . '/storage/studybot.sqlite');

// ==== FAYL YO'LLARI ====
define('GENERATED_DIR', __DIR__ . '/storage/generated/');
define('LOG_DIR', __DIR__ . '/storage/logs/');

// ==== WORKER SOZLAMALARI ====
define('WORKER_BATCH_SIZE', 5);      // bitta cron ishga tushganda nechta job qayta ishlansin
define('WORKER_LOCK_FILE', __DIR__ . '/storage/worker.lock');
define('MAX_RETRIES', 3);            // bitta job necha marta qayta urinsin
define('USER_COOLDOWN_SECONDS', 20); // bitta user ketma-ket nechchi soniyada bitta so'rov yubora oladi (spam himoyasi)

/**
 * AI PROVIDERLAR VA KALITLAR
 * Bir nechta kalit va bir nechta provider qo'shing — biri eskirsa/limit tugasa,
 * tizim avtomatik keyingisiga o'tadi. Kalitlarni xohlagancha ko'paytiring.
 */
// 'priority' — kichik raqam avval ishlatiladi (u band yoki limitda bo'lsa keyingisiga o'tiladi). Bepul limiti katta
// provayderga 1, zaxiralarga 2, 3 bering. Berilmasa — 5.
define('AI_PROVIDERS', [
    [
        'name' => 'groq',
        'priority' => 1,
        'endpoint' => 'https://api.groq.com/openai/v1/chat/completions',
        'model' => 'openai/gpt-oss-120b',
        'keys' => [
            'GROQ_API_KEY_1',
            'GROQ_API_KEY_2',
            // yana kalit qo'shishingiz mumkin
        ],
    ],
    [
        // Google Gemini (AI Studio, https://aistudio.google.com/apikey) — bepul, karta shart emas. OpenAI-mos endpoint.
        // "-latest" nomlari Google model nomini almashtirsa ham o'zi yangisiga ko'chadi. Biri topilmasa (404) faqat
        // o'sha marshrut 6 soatga o'chadi, ikkinchisi ishlayveradi.
        'name' => 'gemini',
        'priority' => 2,
        'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
        'models' => ['gemini-flash-latest', 'gemini-flash-lite-latest'],
        'max_parallel' => 1,
        'keys' => [
            'GEMINI_API_KEY_1',
            'GEMINI_API_KEY_2',
        ],
    ],
    [
        'name' => 'openrouter',
        'priority' => 3,
        'endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
        'model' => 'meta-llama/llama-3.3-70b-instruct:free',
        'keys' => [
            'OPENROUTER_API_KEY_1',
            'OPENROUTER_API_KEY_2',
        ],
    ],
]);

// ==== TO'LOV / HAJM BO'YICHA NARXLASH (so'mda) ====
// Har bir tarif: "necha bet" (mustaqil ish) yoki "necha slayd" bo'yicha AI qancha
// so'z/slayd generatsiya qilishi kerakligini va narxini belgilaydi.
// 1 bet ≈ 250 so'z deb hisoblangan (14pt, 1.5 interval, standart A4 hoshiyalar) —
// shu hisobdan min_words/max_words kelib chiqqan. Narxlarni xohlagancha o'zgartiring.
// Mustaqil ish titul varag'ida universitet nomidan YUQORIDA ko'rsatiladigan vazirlik
// nomi (ixtiyoriy). Bo'sh qoldirilsa umuman ko'rsatilmaydi. Har xil OTM/kollej/litsey
// uchun farq qilishi mumkin — botni sotayotganingiz talabalar auditoriyasiga moslang.
define('MINISTRY_NAME', "O'ZBEKISTON RESPUBLIKASI OLIY TA'LIM, FAN VA INNOVATSIYA VAZIRLIGI");

define('MUSTAQIL_ISH_VOLUMES', [
    '5-10'  => ['label' => '5-10 bet',   'chapters' => 3, 'min_words' => 1250, 'max_words' => 2500, 'price' => 4000],
    '10-15' => ['label' => '10-15 bet',  'chapters' => 4, 'min_words' => 2500, 'max_words' => 3750, 'price' => 5000],
    '15-20' => ['label' => '15-20 bet',  'chapters' => 5, 'min_words' => 3750, 'max_words' => 5000, 'price' => 7000],
    '20-25' => ['label' => '20-25 bet',  'chapters' => 6, 'min_words' => 5000, 'max_words' => 6250, 'price' => 9000],
    '25-30' => ['label' => '25-30 bet',  'chapters' => 7, 'min_words' => 6250, 'max_words' => 7500, 'price' => 12000],
]);

define('SLAYD_VOLUMES', [
    '8-10'  => ['label' => '8-10 slayd',   'slides' => 9,  'price' => 4000],
    '10-15' => ['label' => '10-15 slayd',  'slides' => 12, 'price' => 6000],
    '15-20' => ['label' => '15-20 slayd',  'slides' => 17, 'price' => 8000],
    '20-25' => ['label' => '20-25 slayd',  'slides' => 22, 'price' => 10000],
]);

// Referat — mustaqil ishdan QISQAROQ va ARZONROQ tur. Reja/adabiyotlar bilan
// to'liq andoza saqlanadi, faqat hajm va narx kichikroq.
define('REFERAT_VOLUMES', [
    '2-3' => ['label' => '2-3 bet', 'chapters' => 2, 'min_words' => 500,  'max_words' => 750,  'price' => 2000],
    '3-5' => ['label' => '3-5 bet', 'chapters' => 2, 'min_words' => 750,  'max_words' => 1250, 'price' => 3000],
]);

// Har bir turdan FAQAT eng arzon (kichik) tarif birinchi marta bepul bo'lishi mumkin —
// shu bilan foydalanuvchi katta hajmni bepul olib, xarajat qilib qo'ymaydi.
define('FREE_TIER_KEY', [
    'mustaqil_ish' => '5-10',
    'slayd' => '8-10',
    'referat' => '2-3',
]);
// Eslatma: bepul buyurtma huquqi users.free_used bayrog'i orqali boshqariladi (1 marta, barcha turlar uchun birga).

// ==== REFERAL TIZIMI ====
// Foydalanuvchi o'z havolasini ulashadi (https://t.me/BOT_USERNAME?start=ref<ID>).
// Taklif qilingan kishi BIRINCHI PULLIK (bepul bo'lmagan) buyurtmasini bergan zahoti,
// taklif qilgan kishining balansiga bonus qo'shiladi — shunchaki ro'yxatdan o'tish
// yetarli emas, chunki bu soxta/faol bo'lmagan referallarni oldini oladi.
define('REFERRAL_BONUS', 3000); // so'mda
define('BOT_USERNAME', 'BOT_USERNAME_HERE');

// AI necha marta "hajm juda qisqa chiqdi" holatida qayta urinsin (sifat nazorati)
define('MAX_LENGTH_RETRIES', 1);

/**
 * SLAYD DIZAYNLARI — foydalanuvchi rasm orqali ko'rib, shulardan birini tanlaydi.
 * Har biri boshqa rang + joylashuv ('bars' = yuqori/pastki chiziq, 'sidebar' = chap panel)
 * kombinatsiyasi, shu bilan taqdimotlar bir xil bo'lib qolmaydi.
 */
define('PPTX_DESIGNS', [
    'blue_cards'     => ['label' => "1. Ko'k — Klassik",         'accent' => '4472C4', 'title' => '1F4E79', 'layout' => 'cards', 'skin' => 'classic'],
    'green_cards'    => ['label' => "2. Yashil — Lenta",        'accent' => '2E7D32', 'title' => '1B5E20', 'layout' => 'cards', 'skin' => 'band'],
    'red_cards'      => ['label' => "3. Qizil — Yon panel",         'accent' => 'C0392B', 'title' => '7B241C', 'layout' => 'cards', 'skin' => 'sidebar'],
    'purple_cards'   => ['label' => "4. Binafsha — Tungi",      'accent' => '7E57C2', 'title' => '4527A0', 'layout' => 'cards', 'skin' => 'dark'],
    'orange_cards'   => ['label' => "5. Apelsin — Qirrali",       'accent' => 'E67E22', 'title' => 'A04000', 'layout' => 'cards', 'skin' => 'snip'],
    'teal_cards'     => ['label' => "6. Feruza — Chiziqli",        'accent' => '16A085', 'title' => '0B5345', 'layout' => 'cards', 'skin' => 'underline'],
    'pink_cards'     => ['label' => "7. Pushti — Yumaloq",        'accent' => 'D81B60', 'title' => '880E4F', 'layout' => 'cards', 'skin' => 'bubble'],
    'gray_cards'     => ['label' => "8. Kulrang — Minimal",       'accent' => '607D8B', 'title' => '37474F', 'layout' => 'cards', 'skin' => 'minimal'],
    'gold_cards'     => ['label' => "9. Oltin — Ramkali",         'accent' => 'B7950B', 'title' => '7D6608', 'layout' => 'cards', 'skin' => 'frame'],
    'navy_cards'     => ['label' => "10. Tungi ko'k — Qat'iy",   'accent' => '2C3E50', 'title' => '1A252F', 'layout' => 'cards', 'skin' => 'navy'],
    'brown_cards'    => ['label' => "11. Jigarrang — Daftar",    'accent' => '8D6E63', 'title' => '4E342E', 'layout' => 'cards', 'skin' => 'paper'],
    'cyan_cards'     => ['label' => "12. Havorang — Texno",     'accent' => '00ACC1', 'title' => '006064', 'layout' => 'cards', 'skin' => 'tech'],
]);
define('DEFAULT_PPTX_DESIGN', 'blue_cards');

// ==== TAHRIRLOVCHI (Telegram WebApp) ====
// Tahrirlovchi FAQAT HTTPS bilan ishlaydi. PUBLIC_BASE_URL bo'sh qolsa — "Tahrirlash" tugmasi ko'rinmaydi.
define('PUBLIC_BASE_URL', 'https://69cb436e87b9e.xvest1.ru/SlayderAi1Bit');                 // masalan: https://sizning-domeningiz.uz  (oxirida "/" siz)
define('EDITOR_PATH', 'editor');               // papka nomi. Xavfsizlik uchun tasodifiy nomga o'zgartirish mumkin (papkani ham qayta nomlang)

// ==== ADMIN PANEL (Telegram WebApp) ====
// Botga /admin yozing (faqat ADMIN_CHAT_ID va ADMIN_IDS dagi akkauntlar uchun) — "Panelni ochish" tugmasi keladi.
// Talab: PUBLIC_BASE_URL https:// bilan boshlanishi kerak.
define('ADMIN_PATH', 'admin');       // papka nomi (xavfsizlik uchun tasodifiy nomga o'zgartirib, papkani ham qayta nomlashingiz mumkin)
define('ADMIN_IDS', []);             // qo'shimcha adminlar, masalan: [123456789, 987654321]
// PIN kod (juda tavsiya etiladi!). Hash yaratish: terminalda  php make_admin_pin.php  — chiqqan qatorni shu yerga qo'ying.
// Bo'sh qoldirilsa PIN so'ralmaydi (faqat Telegram imzosi + bir martalik havola bilan himoyalanadi).
define('ADMIN_PIN_HASH', '');
define('EDITOR_STORAGE_DIR', __DIR__ . '/storage/editor/'); // rasmlar shu yerda (imkon bo'lsa web-root'dan TASHQARIGA ko'chiring)
define('EDITOR_LINK_TTL', 900);                // tugma (havola) amal qilish muddati: 15 daqiqa
define('EDITOR_SESSION_IDLE', 2700);           // harakatsiz bo'lsa sessiya 45 daqiqada tugaydi
define('EDITOR_SESSION_MAX', 21600);           // sessiyaning mutlaq muddati: 6 soat
define('EDITOR_INITDATA_MAX_AGE', 3600);       // Telegram imzosi (initData) eskirish muddati
define('EDITOR_RETENTION_DAYS', 30);           // tahrirlanadigan taqdimotlar saqlanish muddati (kun)
define('EDITOR_AI_ENABLED', true);            // tahrirlovchida «✨ AI bilan qayta yozish» (slayd matnini AI o'zgartiradi)
define('EDITOR_AI_PER_HOUR', 20);             // bitta taqdimotda soatiga ko'pi bilan nechta AI so'rovi (xarajatni cheklash)
define('PDF_ENGINE', 'auto');                 // taqdimotni PDF qilish: 'auto' (LibreOffice bo'lsa u, bo'lmasa GD) | 'soffice' | 'gd'
define('EDITOR_MAX_IMAGES', 40);               // bitta taqdimotga ko'pi bilan nechta rasm yuklash mumkin
define('EDITOR_MAX_UPLOAD_BYTES', 8 * 1024 * 1024);
define('EDITOR_TRUSTED_IP_HEADER', '');        // Cloudflare ishlatsangiz: 'HTTP_CF_CONNECTING_IP'


// ==== PROMOKOD VA BONUS ====
// Promokodlarni admin Telegram'da yaratadi: /promo_add KOD balance 5000  yoki  /promo_add KOD topup_pct 10   (/promo_list, /promo_off KOD)
// Hamma uchun aksiya: har bir to'ldirishga TOPUP_BONUS_PERCENT % qo'shimcha (0 = o'chiq). TOPUP_BONUS_MIN_AMOUNT — shu summadan boshlab.
define('TOPUP_BONUS_PERCENT', 0);
define('TOPUP_BONUS_MIN_AMOUNT', 0);
define('PROMO_MAX_BONUS', 100000);     // bitta to'ldirishda berilishi mumkin bo'lgan eng katta bonus (so'm)

// ==== REJA TASDIQLASH ====
// Reja (bob/slayd sarlavhalari) tuzilgach mijozga ko'rsatiladi: ✅ Tasdiqlash / ✏️ O'zgartirish / 🔄 Boshqa reja.
// Javob bo'lmasa PLAN_REVIEW_SECONDS dan keyin reja o'zi tasdiqlanadi (ish qotib qolmaydi). O'chirish: false.
define('PLAN_REVIEW_ENABLED', true);
define('PLAN_REVIEW_SECONDS', 300);

// ==== ISH MEXANIZMI (cron KERAK EMAS) ====
// Bot o'zi tick.php ni chaqirib turadi. SYSDC kabi umumiy hostingda CPU/RAM cheklangan bo'lgani uchun qiymatlar ehtiyotkor.
define('ENGINE_MAX_PARALLEL', 3);        // bir vaqtda ishlaydigan tick soni (kuchli hostingda 5-8 gacha oshiring)
define('ENGINE_MAX_ACTIVE_JOBS', 8);     // bir vaqtda "ishlanayotgan" buyurtmalar (qolganlari navbatda, o'rni ko'rsatiladi)
define('ENGINE_MAX_JOBS_PER_USER', 2);   // bitta foydalanuvchi bir vaqtda ko'pi bilan nechta buyurtma ishlatsin
define('ENGINE_TICK_SECONDS', 40);       // bitta tick yangi qadam boshlay oladigan vaqt (hosting 30s da uzsa — 20 qiling)
define('ENGINE_HARD_SECONDS', 100);      // bitta so'rovning eng uzun umri (hosting 60s da uzsa — 55 qiling)
define('ENGINE_MAX_WAIT_SECONDS', 2400); // AI limiti tufayli ish ko'pi bilan shuncha kutadi, keyin xato + pul qaytariladi
define('ENGINE_BASE_URL', '');           // bo'sh bo'lsa PUBLIC_BASE_URL ishlatiladi

// ==== WEBHOOK HIMOYASI ====
// Telegram so'rovlarini soxtalashtirishdan himoya. Tasodifiy uzun qiymat yozing (A-Z a-z 0-9 _ -), so'ng webhookni
// qayta o'rnating:  https://api.telegram.org/bot<TOKEN>/setWebhook?url=<URL>&secret_token=<SHU_QIYMAT>
define('WEBHOOK_SECRET', '');



// ==== SLAYD TURLARI (xilma-xil maketlar) ====
// AI har slayd uchun shu turlardan birini tanlaydi; har biri PptxLayouts'da alohida shakllarda chiziladi.
define('SLIDE_KINDS', ['matn', 'tarif', 'bosqichlar', 'xronologiya', 'kartalar', 'taqqoslash', 'xulosa']);
// Har bir turdagi slaydda nechta punkt (sarlavha + tushuntirish) bo'lishi kerakligi
define('SLIDE_KIND_POINTS', [
    'matn' => 4, 'tarif' => 3, 'bosqichlar' => 4, 'xronologiya' => 5,
    'kartalar' => 4, 'taqqoslash' => 6, 'xulosa' => 5,
]);


// Taqdimotning muqova va yakuniy slaydida ko'rinadigan ixtiyoriy brend yorlig'i
// (masalan "@sizning_bot_username"). Bo'sh qoldirilsa umuman ko'rsatilmaydi.
define('SLIDE_BRANDING_TAG', '');

// ==== HAMYON BALANSI ====
// Foydalanuvchi hisobini oldindan to'ldirib qo'yishi mumkin — keyingi buyurtmalar
// avtomatik ravishda balansdan yechiladi (Click/Payme'ga har safar qayta o'tishning
// hojati yo'q). Balans yetmasa, to'ldirish oynasi ko'rsatiladi.
define('TOPUP_PRESETS', [10000, 20000, 50000, 100000]); // tezkor tugmalar, so'mda
define('MIN_TOPUP_AMOUNT', 2000); // so'mda
define('MAX_TOPUP_AMOUNT', 5000000); // bir marta to'ldirish mumkin bo'lgan eng katta summa (so'mda)

// ==== CLICK.UZ ====
// Click Merchant Cabinet (my.click.uz) dan olinadi
define('CLICK_MERCHANT_ID', 'CLICK_MERCHANT_ID_HERE');
define('CLICK_SERVICE_ID', 'CLICK_SERVICE_ID_HERE');
define('CLICK_SECRET_KEY', 'CLICK_SECRET_KEY_HERE');
define('CLICK_PAY_URL', 'https://my.click.uz/services/pay');

// ==== PAYME ====
// Payme Merchant Cabinet (business.payme.uz) dan olinadi
define('PAYME_MERCHANT_ID', 'PAYME_MERCHANT_ID_HERE');
define('PAYME_KEY', 'PAYME_KEY_HERE'); // test yoki jonli (prod) key
define('PAYME_CHECKOUT_URL', 'https://checkout.paycom.uz');

/**
 * RASM QIDIRISH PROVIDERLARI (slaydlarga rasm qo'yish uchun)
 * Bir nechtasini to'ldiring — biri ishlamasa (limit/xato) keyingisiga o'tadi.
 * Hech biri topilmasa — rasmsiz, faqat matnli slayd yaratiladi (bot yiqilmaydi).
 */
define('IMAGE_PROVIDERS', [
    'unsplash' => 'UNSPLASH_ACCESS_KEY_HERE',
    'pexels'   => 'PEXELS_API_KEY_HERE',
    'serper'   => 'SERPER_API_KEY_HERE', // serper.dev — google image search
]);

// ==== HUMO AVTO-TO'LDIRISH (Telegram Business + @HUMOcardbot) ====
// Mijoz noyob summani HUMO kartaga o'tkazadi, @HUMOcardbot admin akkauntiga "➕ To'ldirish" xabarini yuboradi,
// bot (Business orqali ulangan) shu xabarni o'qib balansni o'zi to'ldiradi.
// Sozlash: 1) @BotFather → Bot Settings → Business Mode — yoqing.  2) Telegram (Premium) → Sozlamalar → Business → Chatbotlar →
// botni qo'shing: faqat @HUMOcardbot chatiga ruxsat bering, «Xabarlarni o'qish» huquqi yetarli.  3) Quyidagilarni to'ldirib, /humo yozing.
// Muhim: webhook'ni allowed_updates bilan qayta o'rnating (README/TUZATISHLAR da).
define('HUMO_ENABLED', false);
define('HUMO_BOT_USERNAME', 'HUMOcardbot');
define('HUMO_BOT_ID', 0);                 // ixtiyoriy: @HUMOcardbot ning ID si
define('HUMO_CARD_NUMBER', '');           // mijozga ko'rsatiladigan karta raqami, masalan '9860 1234 5678 6287'
define('HUMO_CARD_HOLDER', '');           // karta egasi (ixtiyoriy)
define('HUMO_CARD_LAST4', '');            // bo'sh bo'lsa karta raqamining oxirgi 4 tasi olinadi
define('HUMO_TTL_MINUTES', 30);           // mijozga aytiladigan muddat
define('HUMO_KEEP_HOURS', 24);            // shundan keyin kutilayotgan so'rov yopiladi
define('HUMO_NOTIFY_UNMATCHED', true);    // kutayotgan mijoz bor-u summa mos kelmasa adminga xabar

// ==== PAPKALARNI AVTOMATIK YARATISH ====
// Yangi o'rnatishda storage/logs va storage/generated papkalari yo'q bo'ladi (zip ichida bo'sh papka saqlanmaydi).
// Ularsiz fayl (DOCX/PPTX) yozilmaydi va xatolar logga tushmaydi — shuning uchun har ishga tushishda tekshiramiz.
foreach ([LOG_DIR, GENERATED_DIR, EDITOR_STORAGE_DIR] as $__dir) {
    if (!is_dir($__dir)) {
        @mkdir($__dir, 0775, true);
    }
}
unset($__dir);

// Xatoliklarni PHP error_log ga emas, alohida faylga yozamiz
ini_set('log_errors', 1);
ini_set('error_log', LOG_DIR . 'php_errors.log');
error_reporting(E_ALL & ~E_DEPRECATED);
