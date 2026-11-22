<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/Telegram.php';
require_once __DIR__ . '/lib/Queue.php';
require_once __DIR__ . '/lib/TopupHelpers.php';
require_once __DIR__ . '/lib/HumoAuto.php';
require_once __DIR__ . '/lib/StarsPay.php';
require_once __DIR__ . '/lib/Updater.php';
require_once __DIR__ . '/lib/ClickTopupPayment.php';
require_once __DIR__ . '/lib/PaymeTopupPayment.php';
require_once __DIR__ . '/lib/EditorStore.php';
require_once __DIR__ . '/lib/AIClient.php';
require_once __DIR__ . '/lib/DocxGenerator.php';
require_once __DIR__ . '/lib/PptxGenerator.php';
require_once __DIR__ . '/lib/ImageSearch.php';
require_once __DIR__ . '/lib/JobSteps.php';
require_once __DIR__ . '/lib/Diagnostics.php';

// Soxta so'rovlardan himoya: WEBHOOK_SECRET o'rnatilgan bo'lsa, Telegram yuborgan maxfiy sarlavha mos kelishi shart
if (WEBHOOK_SECRET !== '') {
    $hdr = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    if (!hash_equals(WEBHOOK_SECRET, $hdr)) {
        http_response_code(403);
        exit;
    }
}

// Telegramga darhol 200 OK qaytaramiz — u qayta-qayta jo'natishga urinmasin.
// Og'ir ish (AI so'rov, fayl generatsiya) BU YERDA QILINMAYDI — worker.php buni qiladi.
http_response_code(200);
echo 'ok';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
}

// Cron kerak emas: har bir kelgan so'rov ish mexanizmini "uyg'otib" qo'yadi (ish bor-u hech kim bajarmayotgan bo'lsa)
register_shutdown_function(function () {
    try {
        Engine::watchdog();
        Engine::inlineIfNeeded();
        Maintenance::run(microtime(true) + 3);       // soatlik tozalash, kunlik baza zaxirasi (rassilkani asosan tick yuboradi)
    } catch (Throwable $e) {
    }
});

try {
    $update = json_decode(file_get_contents('php://input'), true);
    if (!$update) {
        exit;
    }

    // ⭐ Telegram Stars: to'lovdan oldingi tekshiruv va muvaffaqiyatli to'lov
    if (StarsPay::handleUpdate($update)) {
        exit;
    }

    // Telegram Business (HUMO avto-to'ldirish): business_connection / business_message update'lari
    if (HumoAuto::handleUpdate($update)) {
        exit;
    }

    if (isset($update['callback_query'])) {
        handleCallbackQuery($update['callback_query']);
        exit;
    }

    $message = $update['message'] ?? null;
    if (!$message) {
        exit;
    }

    $chatId = (int)$message['chat']['id'];
    $text = trim($message['text'] ?? '');
    $username = $message['from']['username'] ?? null;
    $photo = $message['photo'] ?? null; // massiv turli o'lchamda, oxirgisi eng katta hajmli

    // "/start ref12345" — referal havolasi orqali kelgan bo'lsa, taklif qilgan
    // kishining ID'sini ajratib olamiz (faqat YANGI foydalanuvchi uchun ishlatiladi).
    $referrerId = null;
    if (str_starts_with($text, '/start ') || $text === '/start') {
        $payload = trim(substr($text, 6));
        if (str_starts_with($payload, 'ref')) {
            $candidate = (int)substr($payload, 3);
            if ($candidate > 0 && $candidate !== $chatId) {
                $referrerId = $candidate;
            }
        }
        $agentRef = str_starts_with($payload, 'agt') ? (int)substr($payload, 3) : 0;
        $text = '/start';
    }

    $user = getOrCreateUser($chatId, $username, $referrerId);
    if (!empty($agentRef)) {
        Growth::attachAgent($chatId, $agentRef);     // hamkor havolasi
    }

    // Admin panel orqali bloklangan foydalanuvchi botdan foydalana olmaydi (adminlar bundan mustasno)
    if (!empty($user['banned']) && !AdminSecurity::isAdmin($chatId)) {
        exit;
    }

    // --- To'lov cheki (rasm) kutilmoqda ---
    if ($photo && str_starts_with($user['state'], 'awaiting_receipt:')) {
        $fileId = end($photo)['file_id'] ?? null;
        if ($fileId) {
            handleReceiptSubmission($chatId, $user['state'], $fileId);
        }
        exit;
    }

    // O'qituvchi talablari: mavzu kutilayotganda fayl, rasm yoki «Talab: ...» matni
    if (str_starts_with((string)$user['state'], 'awaiting_topic:') && Requirements::handle($chatId, $message)) {
        exit;
    }
    // Yomon bahodan keyin sabab yozilmoqda
    $menuTexts = array_merge(...array_map(fn($row) => array_column($row, 'text'), mainMenu()['keyboard']));
    if (str_starts_with((string)$user['state'], 'awaiting_feedback:') && $text !== '' && $text[0] !== '/' && !in_array($text, $menuTexts, true)) {
        handleFeedbackText($chatId, (int)substr((string)$user['state'], strlen('awaiting_feedback:')), $text);
        exit;
    }

    handleMessage($user, $chatId, $text);
} catch (Throwable $e) {
    logError('webhook', $e->getMessage());
}

// ============================================================
// YORDAMCHI FUNKSIYALAR
// ============================================================

function getOrCreateUser(int $chatId, ?string $username, ?int $referrerId = null): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE telegram_id = :id');
    $stmt->execute(['id' => $chatId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Taklif qilgan foydalanuvchi haqiqatan mavjudligini tekshiramiz —
        // aks holda tasodifiy/xato raqam bilan referal bog'lanib qolmasin.
        $validReferrer = null;
        if ($referrerId) {
            $check = $pdo->prepare('SELECT telegram_id FROM users WHERE telegram_id = :id');
            $check->execute(['id' => $referrerId]);
            if ($check->fetchColumn()) {
                $validReferrer = $referrerId;
            }
        }

        $ins = $pdo->prepare(
            'INSERT INTO users (telegram_id, username, referred_by, created_at) VALUES (:id, :u, :r, :t)'
        );
        $ins->execute(['id' => $chatId, 'u' => $username, 'r' => $validReferrer, 't' => nowTs()]);
        AdminEvents::newUser($chatId, $username, $validReferrer);
        $stmt->execute(['id' => $chatId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    return $user;
}

function setUserState(int $chatId, string $state): void
{
    $stmt = db()->prepare('UPDATE users SET state = :s, last_request_at = :t WHERE telegram_id = :id');
    $stmt->execute(['s' => $state, 't' => nowTs(), 'id' => $chatId]);
}

function markFreeUsed(int $chatId): void
{
    $stmt = db()->prepare('UPDATE users SET free_used = 1 WHERE telegram_id = :id');
    $stmt->execute(['id' => $chatId]);
}

/** Job/volume "type" kalitini odam o'qiy oladigan nomga aylantiradi. */
function typeLabel(string $type): string
{
    return DocTypes::label($type);
}

/** "type" kaliti bo'yicha tegishli hajm/narx jadvalini qaytaradi (yoki topilmasa null). */
function volumesFor(string $type): ?array
{
    return DocTypes::exists($type) ? DocTypes::volumes($type) : null;
}

function isProfileComplete(array $user): bool
{
    return !empty($user['full_name']) && !empty($user['university']) && !empty($user['group_name']);
}

function saveProfileField(int $chatId, string $field, string $value): void
{
    // Ustun nomini whitelist orqali tekshiramiz — SQL in'ektsiyaning oldini olish uchun
    $allowed = ['full_name', 'university', 'group_name'];
    if (!in_array($field, $allowed, true)) {
        return;
    }
    $stmt = db()->prepare("UPDATE users SET $field = :v WHERE telegram_id = :id");
    // Titul varag'iga tushadigan maydon — haddan tashqari uzun matn hujjat ko'rinishini buzmasligi uchun kesamiz
    $stmt->execute(['v' => mb_substr(trim($value), 0, 120), 'id' => $chatId]);
}

// ============================================================
// KLAVIATURALAR
// ============================================================

function mainMenu(): array
{
    return [
        'keyboard' => array_merge(
            array_chunk(array_map(fn($t) => ['text' => DocTypes::T[$t]['menu']], DocTypes::all()), 2),
            [
                [['text' => '📋 Buyurtmalarim'], ['text' => '💰 Balans']],
                [['text' => '🎁 Referal'], ['text' => '🎟 Promokod']],
                [['text' => '⚙️ Profil'], ['text' => '🌐 Til']],
                array_values(array_filter([
                    Growth::subEnabled() ? ['text' => '💎 Obuna'] : null,
                    ['text' => '💬 Yordam'],
                    MiniApp::enabled() ? ['text' => '📱 Ilova'] : null,
                ])),
            ]
        ),
        'resize_keyboard' => true,
    ];
}

function volumeKeyboard(string $type, array $user): array
{
    $volumes = volumesFor($type) ?? [];
    $freeKey = DocTypes::freeKey($type);
    $freeAvailable = (int)$user['free_used'] === 0;

    $buttons = [];
    foreach ($volumes as $key => $v) {
        $isFree = $freeAvailable && $key === $freeKey;
        $label = $isFree
            ? "🎁 {$v['label']} — BEPUL (birinchi marta)"
            : "{$v['label']} — " . number_format($v['price'], 0, '', ' ') . " so'm";
        $buttons[] = [['text' => $label, 'callback_data' => "vol:{$type}:{$key}"]];
    }
    return ['inline_keyboard' => $buttons];
}

function topupAmountKeyboard(): array
{
    $buttons = [];
    foreach (TOPUP_PRESETS as $amt) {
        $buttons[] = [['text' => number_format($amt, 0, '', ' ') . " so'm", 'callback_data' => "topup_amount:{$amt}"]];
    }
    $buttons[] = [['text' => "✏️ Boshqa summa", 'callback_data' => 'topup_amount:custom']];
    return ['inline_keyboard' => $buttons];
}

function topupMethodKeyboard(): array
{
    // Qaysi usullar ko'rinishi — admin panel → Sozlamalar → «To'lov usullari»
    $rows = [];
    if (payMethodOn('humo') && HumoAuto::ready()) {
        $rows[] = [['text' => '⚡ Humo / Uzcard (Avto)', 'callback_data' => 'topup_method:humo']];
    }
    if (payMethodOn('click')) {
        $rows[] = [['text' => '💳 Click orqali', 'callback_data' => 'topup_method:click']];
    }
    if (payMethodOn('payme')) {
        $rows[] = [['text' => '💳 Payme orqali', 'callback_data' => 'topup_method:payme']];
    }
    $rows[] = [['text' => "🧾 Kartaga o'tkazib, chek yuborish", 'callback_data' => 'topup_method:manual']];
    if (payMethodOn('stars') && StarsPay::enabled()) {
        $rows[] = [['text' => '⭐ Telegram Stars (kartasiz)', 'callback_data' => 'topup_method:stars']];
    }
    return ['inline_keyboard' => $rows];
}

/** To'lov usuli yoqilganmi (qo'lda — chek yuborish — har doim yoqilgan). */
function payMethodOn(string $m): bool
{
    return match ($m) {
        'manual' => true,
        'humo' => (bool)Engine::c('PAY_HUMO', false),
        'click' => (bool)Engine::c('PAY_CLICK', false),
        'payme' => (bool)Engine::c('PAY_PAYME', false),
        'stars' => (bool)Engine::c('STARS_ENABLED', false),
        default => false,
    };
}

// ============================================================
// XABARLARNI BOSHQARISH
// ============================================================

function handleMessage(array $user, int $chatId, string $text): void
{
    $chatId = (int)$chatId;

    if ($text === '/start') {
        setUserState($chatId, 'idle');
        Telegram::sendMessage(
            $chatId,
            "👋 <b>Assalomu alaykum!</b>\n\n"
            . "Men — <b>StudyBot</b>. Sizga bir necha daqiqada tayyor <b>mustaqil ish</b>, <b>referat</b>, "
            . "<b>kurs ishi</b>, <b>ilmiy maqola</b>, <b>tezis</b> va <b>taqdimot (slayd)</b> yaratib beraman.\n\n"
            . "📏 Narx hajmga (bet/slayd soniga) qarab belgilanadi\n"
            . "🎁 Har bir tur bo'yicha birinchi buyurtma <b>bepul</b>\n"
            . "🎨 Taqdimotda 17 xil professional dizayn (5 tasi ⭐ PRO)\n"
            . "📚 Haqiqiy ilmiy manbalardan adabiyotlar ro'yxati\n\n"
            . "Quyidagi menyudan boshlang 👇",
            mainMenu()
        );
        return;
    }

    // 🛠 Texnik ishlar rejimi (admin paneldan yoqiladi): mijozlar yangi buyurtma bera olmaydi, «💬 Yordam» ishlayveradi
    if (Settings::maintenance() && !AdminSecurity::isAdmin($chatId) && $text !== '💬 Yordam' && $text !== '/yordam' && !str_starts_with((string)$user['state'], 'awaiting_support')) {
        Telegram::sendMessage($chatId, Telegram::escape((string)Engine::c('MAINTENANCE_TEXT', "🛠 Botda texnik ishlar olib borilmoqda. Tez orada qaytamiz!")), ['keyboard' => [[['text' => '💬 Yordam']]], 'resize_keyboard' => true]);
        return;
    }

    if ($text === '💬 Yordam' || $text === '/yordam') {
        setUserState($chatId, 'awaiting_support');
        Telegram::sendMessage($chatId, "💬 <b>Yordam</b>\n\nSavolingiz yoki muammoingizni bitta xabarda yozing (buyurtma raqami bo'lsa, uni ham qo'shing). Administrator shu yerda javob beradi.",
            ['inline_keyboard' => [[['text' => '✖️ Bekor qilish', 'callback_data' => 'sup:cancel']]]]);
        return;
    }

    // 📱 Mini App: pastki menyu tugmasidan ochilgan ilovaga Telegram foydalanuvchi ma'lumotini (initData) bermaydi —
    // shuning uchun ilova xabar ichidagi (inline) web_app tugmasi orqali ochiladi
    if (($text === '📱 Ilova' || $text === '/ilova') && MiniApp::enabled()) {
        Telegram::sendMessage($chatId, "📱 <b>Buyurtma ilovasi</b>\nTur, hajm, dizayn va mavzuni bitta oynada tanlang 👇",
            ['inline_keyboard' => [[['text' => '📱 Ilovani ochish', 'web_app' => ['url' => MiniApp::url()]]]]]);
        return;
    }

    if ($text === '💎 Obuna' || $text === '/obuna') {
        if (!Growth::subEnabled()) {
            Telegram::sendMessage($chatId, "Obuna hozircha mavjud emas.", mainMenu());
            return;
        }
        Telegram::sendMessage($chatId, Growth::subText($chatId), Growth::subKeyboard());
        return;
    }

    if ($text === '/agent' || $text === '/hamkor') {
        Telegram::sendMessage($chatId, Growth::agentText($chatId, defined('BOT_USERNAME') && BOT_USERNAME !== 'BOT_USERNAME_HERE' ? (string)BOT_USERNAME : ''));
        return;
    }

    if ($text === '/talab_ochir') {
        Requirements::clear($chatId);
        Telegram::sendMessage($chatId, "🗑 Yuklangan talablar o'chirildi.");
        return;
    }

    if ($text === '/tahrir' || $text === '✏️ Tahrirlash') {
        sendEditList($chatId);
        return;
    }

    if (preg_match('#^/tekshir(?:\s+(\d+))?$#', $text, $mm) && ADMIN_CHAT_ID && $chatId === (int)ADMIN_CHAT_ID) {
        Diagnostics::run($chatId, (int)($mm[1] ?? 0));   // /tekshir — umumiy; /tekshir 60 — hosting 60s lik so'rovni uzmasligini ham sinaydi
        return;
    }

    // /yangilash — botni GitHub'dan yangilash (faqat asosiy admin)
    if ($text === '/yangilash' && ADMIN_CHAT_ID && $chatId === (int)ADMIN_CHAT_ID && Updater::enabled()) {
        $l = Updater::latest();
        $cur = Updater::current();
        if (!$l) {
            Telegram::sendMessage($chatId, "⚠️ GitHub'ga ulanib bo'lmadi. Keyinroq urinib ko'ring.");
            return;
        }
        $same = $cur !== '' && $cur === $l['sha'];
        Telegram::sendMessage($chatId, "🔄 <b>Bot yangilanishi</b>\n\nO'rnatilgan: <code>" . ($cur ?: "noma'lum") . "</code>\nGitHub'dagi oxirgi: <code>{$l['sha']}</code> · "
            . Telegram::escape(mb_substr($l['date'], 0, 10)) . "\n«" . Telegram::escape(mb_substr($l['msg'], 0, 160)) . "»\n\n"
            . ($same ? "✅ Siz allaqachon oxirgi versiyadasiz." : "Yangilashdan oldin avtomatik zaxira olinadi. config.php va baza o'zgarmaydi."),
            $same ? null : ['inline_keyboard' => [[['text' => '🚀 Yangilash', 'callback_data' => 'upd:go']]]]);
        return;
    }

    if (preg_match('#^/promo_(add|list|off)\b#', $text) && AdminSecurity::isAdmin($chatId)) {
        Telegram::sendMessage($chatId, Promo::adminHandle($text));
        return;
    }
    if ($text === '/promo_help' && AdminSecurity::isAdmin($chatId)) {
        Telegram::sendMessage($chatId, Promo::adminHelp());
        return;
    }

    if ($text === '🎟 Promokod' || $text === '/promo') {
        setUserState($chatId, 'awaiting_promo');
        Telegram::sendMessage($chatId, "🎟 Promokodingizni yozing:");
        return;
    }
    if (preg_match('#^/promo\s+(\S+)$#u', $text, $pm)) {
        setUserState($chatId, 'idle');
        [, $msg] = Promo::redeem($chatId, $pm[1]);
        Telegram::sendMessage($chatId, $msg, mainMenu());
        return;
    }

    if ($text === '🌐 Til' || $text === '/til') {
        Telegram::sendMessage($chatId, "🌐 Hujjat tilini tanlang (mustaqil ish, referat va taqdimot shu tilda yoziladi):", I18n::keyboard(I18n::userLang($chatId)));
        return;
    }

    if ($text === '/humo' && AdminSecurity::isAdmin($chatId)) {
        Telegram::sendMessage($chatId, HumoAuto::adminReport());
        return;
    }

    if ($text === '/stats' && ADMIN_CHAT_ID && $chatId === (int)ADMIN_CHAT_ID) {
        sendAdminStats($chatId);
        return;
    }

    // Admin panel (WebApp). Faqat adminning shaxsiy chatida ishlaydi; boshqalarga hech qanday javob/izoh berilmaydi.
    if ($text === '/admin' && AdminSecurity::isAdmin($chatId)) {
        sendAdminPanelLink($chatId);
        return;
    }

    if (($orderType = DocTypes::fromText($text)) !== null) {
        setUserState($chatId, 'idle');
        $intro = [
            'kurs_ishi' => "🎓 <b>Kurs ishi</b>: titul, reja, kirish, 3 bob (har biri 1.1, 1.2, 1.3 bandlar bilan), xulosa va haqiqiy adabiyotlar ro'yxati.\n\n",
            'maqola' => "📰 <b>Ilmiy maqola</b>: sarlavha, muallif, annotatsiya, kalit so'zlar, kirish, metodologiya, natijalar va muhokama, xulosa, haqiqiy adabiyotlar.\n\n",
            'tezis' => "🧾 <b>Tezis</b> (konferensiya uchun): sarlavha, muallif, qisqa ilmiy matn va adabiyotlar.\n\n",
            'esse' => "✍️ <b>Esse / insho</b>: asosiy g'oya, dalillar va misollar, shaxsiy mulohaza, xulosa — ravon uslubda.\n\n",
            'test' => "❓ <b>Test savollari</b>: har savolda 4 ta variant (A–D), oxirida javoblar kaliti jadvali. O'qituvchilar va imtihonga tayyorlanish uchun.\n\n",
            'lab' => "🔬 <b>Laboratoriya ishi hisoboti</b>: titul, ishning maqsadi va nazariy asoslari, jihozlar va bajarish tartibi, natijalar (jadval bilan), xulosa, nazorat savollari.\n\n",
            'amaliyot' => "🏢 <b>Amaliyot hisoboti</b>: titul, tashkilot haqida ma'lumot, tuzilmasi va faoliyati, bajarilgan ishlar, muammolar va takliflar, xulosa.\n"
                . "<i>Tashkilot haqidagi aniq ma'lumotlarni (manzil, raqamlar) tayyor fayldan o'zingiz to'g'rilab qo'ying.</i>\n\n",
        ][$orderType] ?? '';
        Telegram::sendMessage($chatId, $intro . "📏 Kerakli hajmni tanlang:", volumeKeyboard($orderType, $user));
        return;
    }

    if ($text === '📋 Buyurtmalarim' || $text === '/buyurtmalar') {
        sendOrderList($chatId);
        return;
    }

    if ($text === '💰 Balans' || $text === '/balans') {
        $balance = getUserBalance($chatId);
        setUserState($chatId, 'idle');
        Telegram::sendMessage(
            $chatId,
            "💰 Joriy balansingiz: <b>" . number_format($balance, 0, '', ' ') . " so'm</b>\n\nTo'ldirish summasini tanlang:",
            topupAmountKeyboard()
        );
        return;
    }

    if ($text === '🎁 Referal' || $text === '/referal') {
        sendReferralInfo($chatId);
        return;
    }

    if ($text === '⚙️ Profil' || $text === '/profil') {
        StudentFlow::begin($chatId, '_edit', '-');
        return;
    }

    // --- Talaba ma'lumotlari (ism, familya, guruh, universitet) — HAR buyurtmada so'raladi: state = "awaiting_stu:<maydon>:<type>:<volume_key>" ---
    if (str_starts_with($user['state'], 'awaiting_stu:') && $text !== '') {
        if (StudentFlow::handleText($chatId, $user['state'], $text)) {
            return;
        }
    }

    // --- Profil ma'lumotlari bosqichma-bosqich so'ralmoqda: state = "awaiting_profile_<bosqich>:<type>:<volume_key>" ---
    if (str_starts_with($user['state'], 'awaiting_profile_name:') && $text !== '') {
        [, $type, $volumeKey] = array_pad(explode(':', $user['state'], 3), 3, null);
        saveProfileField($chatId, 'full_name', $text);
        setUserState($chatId, "awaiting_profile_university:{$type}:{$volumeKey}");
        Telegram::sendMessage($chatId, "🏫 Universitet nomini kiriting (masalan: Toshkent Davlat Transport Universiteti):");
        return;
    }
    if (str_starts_with($user['state'], 'awaiting_profile_university:') && $text !== '') {
        [, $type, $volumeKey] = array_pad(explode(':', $user['state'], 3), 3, null);
        saveProfileField($chatId, 'university', $text);
        setUserState($chatId, "awaiting_profile_group:{$type}:{$volumeKey}");
        Telegram::sendMessage($chatId, "👥 Guruh raqamingizni kiriting (masalan: GGI-1-24):");
        return;
    }
    if (str_starts_with($user['state'], 'awaiting_profile_group:') && $text !== '') {
        [, $type, $volumeKey] = array_pad(explode(':', $user['state'], 3), 3, null);
        saveProfileField($chatId, 'group_name', $text);

        if ($type === '_edit') {
            setUserState($chatId, 'idle');
            Telegram::sendMessage($chatId, "✅ Ma'lumotlaringiz yangilandi.", mainMenu());
        } else {
            Telegram::sendMessage($chatId, "✅ Ma'lumotlar saqlandi (keyingi buyurtmalarda qayta so'ralmaydi).");
            proceedAfterProfileReady($chatId, $type, $volumeKey);
        }
        return;
    }

    // --- Balansni to'ldirish: summa qo'lda kiritilmoqda ---
    if (str_starts_with($user['state'], 'awaiting_topup_custom_amount:') && $text !== '') {
        [, $type, $volumeKey] = array_pad(explode(':', $user['state'], 3), 3, null);
        $digits = ltrim((string)preg_replace('/\D/', '', $text), '0');
        $maxTopup = defined('MAX_TOPUP_AMOUNT') ? (int)MAX_TOPUP_AMOUNT : 5000000;
        // Juda uzun raqam (int overflow) va haddan katta summalarni rad etamiz
        $amount = strlen($digits) > 12 ? PHP_INT_MAX : (int)$digits;
        if ($amount < (int)Engine::c('MIN_TOPUP_AMOUNT', 1000)) {
            Telegram::sendMessage($chatId, "⚠️ Juda kichik summa. Kamida " . number_format((int)Engine::c('MIN_TOPUP_AMOUNT', 1000), 0, '', ' ') . " so'm kiriting:");
            return;
        }
        if ($amount > $maxTopup) {
            Telegram::sendMessage($chatId, "⚠️ Juda katta summa. Bir marta ko'pi bilan " . number_format($maxTopup, 0, '', ' ') . " so'm to'ldirish mumkin. Qaytadan kiriting:");
            return;
        }
        setUserState($chatId, "awaiting_topup_method:{$amount}:{$type}:{$volumeKey}");
        Telegram::sendMessage($chatId, number_format($amount, 0, '', ' ') . " so'm to'lash usulini tanlang:", topupMethodKeyboard());
        return;
    }

    // --- Promokod kutilmoqda ---
    if ($user['state'] === 'awaiting_promo' && $text !== '') {
        setUserState($chatId, 'idle');
        [, $msg] = Promo::redeem($chatId, $text);
        Telegram::sendMessage($chatId, $msg, mainMenu());
        return;
    }

    // --- Reja sarlavhalarini o'zgartirish: state = "awaiting_plan_edit:<jobId>" ---
    if (str_starts_with($user['state'], 'awaiting_plan_edit:') && $text !== '') {
        PlanReview::submitEdit($chatId, (int)substr($user['state'], strlen('awaiting_plan_edit:')), $text);
        return;
    }

    // --- Chek kutilmoqda, lekin user rasm o'rniga matn yubordi ---
    if (str_starts_with($user['state'], 'awaiting_receipt:')) {
        Telegram::sendMessage($chatId, "📸 Iltimos, to'lov chekining SKRINSHOTINI (rasm sifatida) yuboring.");
        return;
    }

    // --- Dizayn kutilmoqda, lekin user tugma bosish o'rniga matn yubordi ---
    if (str_starts_with($user['state'], 'awaiting_design:')) {
        Telegram::sendMessage($chatId, "👆 Iltimos, yuqoridagi rasmdagi raqamlardan birini tugma orqali tanlang.");
        return;
    }

    // --- Yordam: mijoz savoli → admin panelning «Yordam» bo'limiga ---
    if ($user['state'] === 'awaiting_support' && $text !== '') {
        setUserState($chatId, 'idle');
        AdminPro::supportIncoming($chatId, $text);
        Telegram::sendMessage($chatId, "✅ Xabaringiz administratorga yetkazildi. Javob shu chatga keladi.", mainMenu());
        return;
    }

    // --- Mavzu kutilayotgan holat: state = "awaiting_topic:<type>:<volume_key>" ---
    if (str_starts_with($user['state'], 'awaiting_topic:') && $text !== '') {
        [, $type, $volumeKey] = array_pad(explode(':', $user['state'], 3), 3, null);
        if ($type && $volumeKey) {
            handleTopicSubmission($user, $chatId, $type, $volumeKey, $text);
            return;
        }
    }

    Telegram::sendMessage($chatId, "Quyidagi menyudan tanlang 👇", mainMenu());
}

function handleTopicSubmission(array $user, int $chatId, string $type, string $volumeKey, string $topic): void
{
    $volumes = volumesFor($type);
    if (!$volumes || !isset($volumes[$volumeKey])) {
        Telegram::sendMessage($chatId, "⚠️ Xatolik, iltimos qaytadan boshlang: /start");
        setUserState($chatId, 'idle');
        return;
    }

    // Mavzuni tozalaymiz va uzunligini tekshiramiz (juda uzun matn AI so'roviga to'liq tushib, xarajatni oshirardi)
    $topic = trim(preg_replace('/\s+/u', ' ', $topic) ?? $topic);
    if (mb_strlen($topic) < 3) {
        Telegram::sendMessage($chatId, "✏️ Mavzu juda qisqa. Iltimos, mavzuni to'liqroq yozing:");
        return;
    }
    if (mb_strlen($topic) > 300) {
        Telegram::sendMessage($chatId, "✏️ Mavzu juda uzun (" . mb_strlen($topic) . " belgi, ko'pi bilan 300). Iltimos, qisqartirib qayta yuboring:");
        return;
    }

    // Spam himoyasi: bir user ketma-ket juda tez-tez YANGI BUYURTMA ochib
    // AI/worker resurslarini band qilib qo'ymasligi uchun, oxirgi buyurtmadan
    // beri kamida USER_COOLDOWN_SECONDS o'tgan bo'lishi kerak.
    $lastJob = db()->prepare('SELECT created_at FROM jobs WHERE telegram_id = :id ORDER BY id DESC LIMIT 1');
    $lastJob->execute(['id' => $chatId]);
    $lastCreatedAt = (int)($lastJob->fetchColumn() ?: 0);
    if (nowTs() - $lastCreatedAt < USER_COOLDOWN_SECONDS) {
        Telegram::sendMessage($chatId, "⏳ Iltimos, biroz kuting va keyin qayta urinib ko'ring.");
        return;
    }

    // Holatni ATOMIK ravishda "band qilamiz": bir xil xabar ikki marta kelsa (Telegram qayta yuborishi yoki
    // foydalanuvchi ikki marta bosishi) faqat bittasi o'tadi — aks holda ikkala so'rov ham pulni yechib,
    // ikkita buyurtma ochib yuborardi.
    $claimState = db()->prepare("UPDATE users SET state = 'idle', last_request_at = :t WHERE telegram_id = :id AND state = :s");
    $claimState->execute(['t' => nowTs(), 'id' => $chatId, 's' => (string)$user['state']]);
    if ($claimState->rowCount() === 0) {
        return;
    }

    $volume = $volumes[$volumeKey];
    $price = (int)$volume['price'];
    $freeKey = DocTypes::freeKey($type);
    $isFree = false;
    $isPro = false;
    if ($type === 'slayd') {
        $dkq = db()->prepare('SELECT pending_design_key FROM users WHERE telegram_id = :id');
        $dkq->execute(['id' => $chatId]);
        $isPro = isProDesign((string)($dkq->fetchColumn() ?: ''));
        if ($isPro) {
            $price = ProDeck::price($price);        // PRO — qo'shimcha haq, bepul urinish PRO'ga tegishli emas
        }
    }
    if (!$isPro && (int)$user['free_used'] === 0 && $volumeKey === $freeKey) {
        // Bepul urinishni atomik band qilamiz (ikki parallel so'rov ikkalasi ham bepul olib ketmasligi uchun)
        $freeClaim = db()->prepare('UPDATE users SET free_used = 1 WHERE telegram_id = :id AND free_used = 0');
        $freeClaim->execute(['id' => $chatId]);
        $isFree = $freeClaim->rowCount() > 0;
    }

    $paidBy = $isFree ? 'free' : 'balance';
    if (!$isFree && Growth::subCovers($chatId, $price) && Growth::subTake($chatId)) {
        $paidBy = 'sub';                            // 💎 obunadan 1 kredit
    } elseif (!$isFree) {
        // Balansdan ATOMIK yechamiz: "balance >= narx" sharti UPDATE ning o'zida — parallel so'rovlarda
        // balans manfiyga tushib ketmaydi.
        $balUpd = db()->prepare('UPDATE users SET balance = balance - :p WHERE telegram_id = :id AND balance >= :p');
        $balUpd->execute(['p' => $price, 'id' => $chatId]);
        if ($balUpd->rowCount() === 0) {
            $balance = getUserBalance($chatId);
            $needed = max(0, $price - $balance);
            setUserState($chatId, "awaiting_topup_method:{$needed}:{$type}:{$volumeKey}");
            Telegram::sendMessage(
                $chatId,
                "⚠️ Balansingiz yetarli emas (kerak: " . number_format($price, 0, '', ' ') . " so'm, "
                . "mavjud: " . number_format($balance, 0, '', ' ') . " so'm).\n\n"
                . "Hisobingizni to'ldiring (yana " . number_format($needed, 0, '', ' ') . " so'm kerak):",
                topupMethodKeyboard()
            );
            return;
        }
    }

    $designKey = null;
    if ($type === 'slayd') {
        $dk = db()->prepare('SELECT pending_design_key FROM users WHERE telegram_id = :id');
        $dk->execute(['id' => $chatId]);
        $designKey = (string)($dk->fetchColumn() ?: '');
        if (!isset(pptxDesigns()[$designKey])) {
            $designKey = DEFAULT_PPTX_DESIGN;
        }
    }

    try {
        $student = StudentFlow::forJob($chatId);
        $jobId = Queue::createJob($chatId, $type, $topic, $volumeKey, $price, $isFree, !$isFree, $designKey, I18n::userLang($chatId), $student ?: null);
        db()->prepare('UPDATE jobs SET paid_by = ? WHERE id = ?')->execute([$paidBy, $jobId]);
        Requirements::attach($chatId, $jobId);
    } catch (Throwable $e) {
        // Buyurtma ochilmadi — olingan pul/bepul urinishni darhol qaytaramiz
        logError('webhook', 'createJob xato: ' . $e->getMessage());
        if ($isFree) {
            db()->prepare('UPDATE users SET free_used = 0 WHERE telegram_id = :id')->execute(['id' => $chatId]);
        } elseif ($paidBy === 'sub') {
            Growth::subGive($chatId);
        } else {
            db()->prepare('UPDATE users SET balance = balance + :p WHERE telegram_id = :id')->execute(['p' => $price, 'id' => $chatId]);
        }
        Telegram::sendMessage($chatId, "⚠️ Buyurtmani qabul qilib bo'lmadi, pulingiz qaytarildi. Iltimos, birozdan keyin qayta urinib ko'ring.");
        return;
    }

    if ($isFree) {
        $header = "✅ Bepul buyurtmangiz qabul qilindi!\n📏 Hajm: {$volume['label']}";
    } elseif ($paidBy === 'sub') {
        $header = "✅ Buyurtma qabul qilindi!\n📏 Hajm: {$volume['label']}  ·  💎 Obunadan (qoldi: " . Growth::sub($chatId)['credits'] . " ta)";
    } else {
        $header = "✅ Buyurtma qabul qilindi!\n📏 Hajm: {$volume['label']}  ·  💳 Balansdan yechildi: " . number_format($price, 0, '', ' ') . " so'm";
    }
    // Foizli progress xabari + ishni darhol ishga tushirish (cron kerak emas)
    Engine::onJobPending($jobId, $header);

    // Adminlarga xabar (Telegram + panelning «Xabarlar» bo'limi)
    try {
        $un = db()->prepare('SELECT username FROM users WHERE telegram_id = ?');
        $un->execute([$chatId]);
        AdminEvents::newOrder($jobId, $chatId, $type, $topic, $volumeKey, $price, $isFree, $student ?: [], ($un->fetchColumn() ?: null));
    } catch (Throwable $e) {
        logError('webhook', 'admin xabari: ' . $e->getMessage());
    }
}

// ============================================================
// CALLBACK (INLINE TUGMA) MARSHRUTLASH
// ============================================================

function handleCallbackQuery(array $cb): void
{
    $chatId = $cb['message']['chat']['id'] ?? null;
    $data = $cb['data'] ?? '';
    if (!$chatId) {
        return;
    }
    $chatId = (int)$chatId;

    if (!AdminSecurity::isAdmin($chatId)) {
        $bn = db()->prepare('SELECT banned FROM users WHERE telegram_id = ?');
        $bn->execute([$chatId]);
        if ((int)$bn->fetchColumn() === 1) {
            if (!empty($cb['id'])) {
                Telegram::answerCallbackQuery((string)$cb['id']);
            }
            return;
        }
    }

    // Tugma bosilganda Telegram'dagi "aylanayotgan soat" belgisini o'chiramiz (job:/edit: o'zlari javob beradi)
    if (!str_starts_with($data, 'job:') && !str_starts_with($data, 'edit:') && !str_starts_with($data, 'plan:') && !str_starts_with($data, 'dl:') && !str_starts_with($data, 'humo_') && !empty($cb['id'])) {
        Telegram::answerCallbackQuery((string)$cb['id']);
    }

    if (str_starts_with($data, 'job:')) {
        [, $act, $jid] = array_pad(explode(':', $data, 3), 3, '');
        $uid = (int)($cb['from']['id'] ?? 0);
        if ($act === 'r') {
            Telegram::answerCallbackQuery((string)($cb['id'] ?? ''), Engine::refreshNow((int)$jid, $uid));
        } elseif ($act === 'c') {
            Telegram::answerCallbackQuery((string)($cb['id'] ?? ''), Engine::cancel((int)$jid, $uid), true);
        }
        return;
    }
    if (str_starts_with($data, 'stu:')) {
        StudentFlow::handleCallback($chatId, $data);
        return;
    }
    if (str_starts_with($data, 'dl:')) {
        resendJobFile($chatId, (int)($cb['from']['id'] ?? 0), (int)substr($data, 3), (string)($cb['id'] ?? ''));
        return;
    }
    if ($data === 'sup:reply' || $data === 'sup:cancel') {
        if ($data === 'sup:cancel') {
            setUserState($chatId, 'idle');
            Telegram::sendMessage($chatId, "Bekor qilindi.", mainMenu());
        } else {
            setUserState($chatId, 'awaiting_support');
            Telegram::sendMessage($chatId, "✍️ Javobingizni yozing:", ['inline_keyboard' => [[['text' => '✖️ Bekor qilish', 'callback_data' => 'sup:cancel']]]]);
        }
        return;
    }
    if (Settings::maintenance() && !AdminSecurity::isAdmin($chatId) && preg_match('/^(vol|design|designpro|designstd|ro|rt):/', $data)) {
        Telegram::sendMessage($chatId, Telegram::escape((string)Engine::c('MAINTENANCE_TEXT', "🛠 Botda texnik ishlar olib borilmoqda. Tez orada qaytamiz!")));
        return;
    }
    if ($data === 'upd:go') {
        if (ADMIN_CHAT_ID && $chatId === (int)ADMIN_CHAT_ID && Updater::enabled()) {
            if (!empty($cb['message']['message_id'])) {
                Telegram::call('editMessageReplyMarkup', ['chat_id' => $chatId, 'message_id' => (int)$cb['message']['message_id'], 'reply_markup' => json_encode(['inline_keyboard' => []])]);
            }
            Telegram::sendMessage($chatId, '⏳ Yangilanmoqda… (10-30 soniya)');
            [, $msg] = Updater::run();
            Telegram::sendMessage($chatId, $msg . "\n\nTekshirish: /tekshir");
        }
        return;
    }
    if (str_starts_with($data, 'ro:') || str_starts_with($data, 'rt:')) {
        handleReorder($chatId, $data);
        return;
    }
    if (str_starts_with($data, 'ord:')) {
        sendOrderList($chatId, (int)substr($data, 4), (int)($cb['message']['message_id'] ?? 0) ?: null);
        return;
    }
    if (str_starts_with($data, 'plan:')) {
        [, $act, $jid] = array_pad(explode(':', $data, 3), 3, '');
        PlanReview::handleCallback($act, (int)$jid, (int)($cb['from']['id'] ?? 0), (string)($cb['id'] ?? ''));
        return;
    }
    if (str_starts_with($data, 'lang:')) {
        $l = substr($data, 5);
        $uid = (int)($cb['from']['id'] ?? 0);
        if (I18n::valid($l) && $uid > 0) {
            I18n::setUserLang($uid, $l);
            $mid = (int)($cb['message']['message_id'] ?? 0);
            $txt = "🌐 Hujjat tili: <b>" . I18n::NAMES[$l] . "</b>\nEndi mustaqil ish, referat va taqdimotlar shu tilda yoziladi.";
            if ($mid > 0) {
                Telegram::editQuick($chatId, $mid, $txt, I18n::keyboard($l));
            } else {
                Telegram::sendMessage($chatId, $txt, I18n::keyboard($l));
            }
        }
        return;
    }
    if (str_starts_with($data, 'edit:')) {
        handleEditRequest($chatId, (int)($cb['from']['id'] ?? 0), substr($data, 5), (string)($cb['id'] ?? ''));
        return;
    }
    if (str_starts_with($data, 'vol:')) {
        handleVolumeSelected($chatId, $data);
        return;
    }
    if (str_starts_with($data, 'design:')) {
        handleDesignChosen($chatId, $data);
        return;
    }
    if (str_starts_with($data, 'designpro:')) {
        sendProDesignPicker($chatId, substr($data, strlen('designpro:')));
        return;
    }
    if (str_starts_with($data, 'designstd:')) {
        sendDesignPicker($chatId, substr($data, strlen('designstd:')));
        return;
    }
    if (str_starts_with($data, 'topup_amount:')) {
        handleTopupAmountChosen($chatId, substr($data, strlen('topup_amount:')));
        return;
    }
    if (str_starts_with($data, 'topup_method:')) {
        handleTopupMethodChosen($chatId, substr($data, strlen('topup_method:')));
        return;
    }
    if (HumoAuto::onCallback($chatId, $data, (string)($cb['id'] ?? ''))) {
        return;
    }
    if (str_starts_with($data, 'topup_approve:') || str_starts_with($data, 'topup_reject:')) {
        handleAdminTopupDecision($chatId, $data);
        return;
    }
    if (str_starts_with($data, 'rate:')) {
        handleRating($chatId, $data);
        return;
    }
    if (str_starts_with($data, 'tr:') || str_starts_with($data, 'trl:')) {   // 🌐 tarjima
        Telegram::answerCallbackQuery((string)$cb['id']);
        $pp = explode(':', $data);
        if ($pp[0] === 'tr') {
            Translate::ask($chatId, (int)($pp[1] ?? 0));
        } else {
            Translate::order($chatId, (int)($pp[1] ?? 0), (string)($pp[2] ?? ''));
        }
        return;
    }
    if ($data === 'sub:buy') {
        Telegram::answerCallbackQuery((string)$cb['id']);
        Telegram::sendMessage($chatId, Growth::subBuy($chatId), mainMenu());
        return;
    }
    if (str_starts_with($data, 'rw:')) {             // past bahodan keyin bepul qayta yozish
        Telegram::answerCallbackQuery((string)$cb['id']);
        setUserState($chatId, 'idle');
        $msg = Growth::rewrite($chatId, (int)substr($data, 3));
        if ($msg !== '') {
            Telegram::sendMessage($chatId, $msg);
        }
        return;
    }
    if ($data === 'fb:skip') {
        Telegram::answerCallbackQuery((string)$cb['id']);
        setUserState($chatId, 'idle');
        Telegram::sendMessage($chatId, "Rahmat! Fikringiz uchun tashakkur.", mainMenu());
        return;
    }
}

function handleVolumeSelected(int $chatId, string $data): void
{
    [, $type, $volumeKey] = array_pad(explode(':', $data, 3), 3, null);
    $volumes = volumesFor((string)$type);
    if (!$volumes || !isset($volumes[$volumeKey])) {
        Telegram::sendMessage($chatId, "⚠️ Bu tarif topilmadi, /start orqali qayta boshlang.");
        return;
    }

    // Titul varag'i uchun ism/familya/guruh/universitet HAR buyurtmada alohida so'raladi
    // (oldingi ma'lumot bo'lsa — bir tugma bilan tasdiqlanadi). Tugagach proceedAfterProfileReady() chaqiriladi.
    StudentFlow::begin($chatId, $type, $volumeKey);
}

/**
 * Profil (F.I.Sh/universitet/guruh) tayyor bo'lgach chaqiriladi — bepul tarif
 * huquqi va balansni tekshirib, mavzu so'rashga yoki hisobni to'ldirishga yo'naltiradi.
 * Ikkala kirish nuqtasi (yangi profil to'ldirilgandan keyin va profil ALLAQACHON
 * to'liq bo'lgan holatda hajm tanlanganda) shu bitta funksiyadan o'tadi — shu bilan
 * balans tekshiruvi hech qachon "chetlab o'tilmaydi".
 */
function proceedAfterProfileReady(int $chatId, string $type, string $volumeKey): void
{
    $volumes = volumesFor($type);
    if (!$volumes || !isset($volumes[$volumeKey])) {
        Telegram::sendMessage($chatId, "⚠️ Xatolik, iltimos qaytadan boshlang: /start");
        setUserState($chatId, 'idle');
        return;
    }

    $stmt = db()->prepare('SELECT free_used, balance FROM users WHERE telegram_id = :id');
    $stmt->execute(['id' => $chatId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $freeKey = DocTypes::freeKey($type);
    $isFreeEligible = (int)$user['free_used'] === 0 && $volumeKey === $freeKey;

    if (!$isFreeEligible && !Growth::subCovers($chatId, (int)$volumes[$volumeKey]['price'])) {
        $balance = (int)$user['balance'];
        $price = $volumes[$volumeKey]['price'];
        if ($balance < $price) {
            $needed = $price - $balance;
            setUserState($chatId, "awaiting_topup_method:{$needed}:{$type}:{$volumeKey}");
            Telegram::sendMessage(
                $chatId,
                "⚠️ Balansingizda " . number_format($balance, 0, '', ' ') . " so'm bor, bu buyurtma uchun "
                . number_format($price, 0, '', ' ') . " so'm kerak (yana " . number_format($needed, 0, '', ' ') . " so'm kerak).\n\n"
                . "Hisobingizni to'ldiring:",
                topupMethodKeyboard()
            );
            return;
        }
    }

    if ($type === 'slayd') {
        // Slayd uchun — mavzudan oldin DIZAYN tanlanadi, shunda foydalanuvchi
        // rasm orqali ko'rib tanlaydi va har safar bir xil ko'rinishda chiqmaydi.
        sendDesignPicker($chatId, $volumeKey);
        return;
    }

    setUserState($chatId, "awaiting_topic:{$type}:{$volumeKey}");
    $ask = [
        'amaliyot' => "✏️ Amaliyot o'tgan <b>tashkilot nomi</b> va yo'nalishingizni yozing (masalan: «Toshkent shahar Soliq boshqarmasi, buxgalteriya hisobi»):",
        'lab' => "✏️ <b>Fan va laboratoriya ishi nomini</b> yozing (masalan: «Fizika: Om qonunini tajribada tekshirish»):",
        'test' => "✏️ Test qaysi <b>fan / mavzu</b> bo'yicha? (masalan: «Biologiya: hujayra tuzilishi»):",
    ][$type] ?? "✏️ " . ucfirst(typeLabel($type)) . " mavzusini yozing:";
    Telegram::sendMessage($chatId, $ask . I18n::hintLine($chatId) . ($type !== 'test' ? Requirements::hint() : ''));
}

/**
 * 12 xil dizayn namunasini BITTA kollaj rasm sifatida yuboradi (oldindan tayyorlangan,
 * server har safar qayta chizmaydi — bu shared hosting'da ham tez ishlaydi) va
 * pastida raqamli tugmalar bilan tanlash imkonini beradi.
 */
function sendDesignPicker(int $chatId, string $volumeKey): void
{
    if (!isset(volumesFor('slayd')[$volumeKey])) {
        return;
    }
    setUserState($chatId, "awaiting_design:{$volumeKey}");
    $kb = designPickerKeyboard($volumeKey);

    $sent = Telegram::sendPhoto(
        $chatId,
        __DIR__ . '/assets/design_preview.jpg',
        "🎨 Taqdimot uchun dizaynni tanlang (raqamlar rasmdagi tartib bilan mos)." . (ProDeck::enabled()
            ? "\n\n⭐ <b>PRO</b>: mavzuga oid katta rasmli fon, o'tish effektlari, animatsiya va har slayd uchun nutq matni — pastdagi tugmada."
            : ''),
        $kb
    );

    if (!$sent) {
        // Rasm yuborilmasa ham (masalan tarmoq muammosi) — bot to'xtab qolmasin,
        // standart dizayn bilan davom etish imkonini beramiz
        Telegram::sendMessage(
            $chatId,
            "Dizayn namunalarini yuborishda muammo yuz berdi. Standart dizayn bilan davom etamiz.",
            ['inline_keyboard' => [[['text' => 'Davom etish', 'callback_data' => "design:" . DEFAULT_PPTX_DESIGN . ":{$volumeKey}"]]]]
        );
    }
}

/** PRO dizaynlar kollaji (oldindan tayyorlangan assets/pro_preview.jpg) + narx. */
function sendProDesignPicker(int $chatId, string $volumeKey): void
{
    if (!ProDeck::enabled() || !isset(volumesFor('slayd')[$volumeKey])) {
        return;
    }
    setUserState($chatId, "awaiting_design:{$volumeKey}");
    $base = (int)volumesFor('slayd')[$volumeKey]['price'];
    $price = ProDeck::price($base);
    $cap = "⭐ <b>PRO taqdimot</b> — 16:9 keng ekran\n"
        . "• mavzuga oid katta rasmli fonlar\n• slaydlar orasida o'tish effekti va elementlar animatsiyasi\n"
        . "• har slayd uchun nutq matni (PowerPoint «Izohlar»da)\n\n"
        . "💰 Narxi: <b>" . number_format($price, 0, '', ' ') . " so'm</b> (oddiy: " . number_format($base, 0, '', ' ') . " so'm)\n"
        . "ℹ️ PRO taqdimot PowerPoint/Keynote/Google Slides'da tahrirlanadi.";
    $sent = Telegram::sendPhoto($chatId, __DIR__ . '/assets/pro_preview.jpg', $cap, proPickerKeyboard($volumeKey));
    if (!$sent) {
        Telegram::sendMessage($chatId, $cap, proPickerKeyboard($volumeKey));
    }
}

function handleDesignChosen(int $chatId, string $data): void
{
    // data = "design:{key}:{volumeKey}"
    $rest = substr($data, strlen('design:'));
    $lastColon = strrpos($rest, ':');
    if ($lastColon === false) {
        return;
    }
    $designKey = substr($rest, 0, $lastColon);
    $volumeKey = substr($rest, $lastColon + 1);

    if (!isset(pptxDesigns()[$designKey]) || !isset(volumesFor('slayd')[$volumeKey])) {
        Telegram::sendMessage($chatId, "⚠️ Xatolik, /start orqali qaytadan boshlang.");
        return;
    }

    // Ikki marta bosish (yoki Telegram qayta yuborishi) — ikkinchi xabar chiqmasin
    $cur = db()->prepare('SELECT state, pending_design_key FROM users WHERE telegram_id = :id');
    $cur->execute(['id' => $chatId]);
    $curRow = $cur->fetch(PDO::FETCH_ASSOC) ?: [];
    if (($curRow['state'] ?? '') === "awaiting_topic:slayd:{$volumeKey}" && ($curRow['pending_design_key'] ?? '') === $designKey) {
        return;
    }

    $stmt = db()->prepare('UPDATE users SET pending_design_key = :d WHERE telegram_id = :id');
    $stmt->execute(['d' => $designKey, 'id' => $chatId]);

    if (isProDesign($designKey)) {
        // PRO — qimmatroq va bepul tarifga kirmaydi: balans yetmasa, buyurtma tugamasdan oldin to'ldirishga yo'naltiramiz
        $price = ProDeck::price((int)volumesFor('slayd')[$volumeKey]['price']);
        $balance = getUserBalance($chatId);
        if ($balance < $price) {
            $needed = $price - $balance;
            setUserState($chatId, "awaiting_topup_method:{$needed}:slayd:{$volumeKey}");
            Telegram::sendMessage(
                $chatId,
                "⭐ PRO taqdimot narxi: " . number_format($price, 0, '', ' ') . " so'm, balansingizda: " . number_format($balance, 0, '', ' ')
                . " so'm (yana " . number_format($needed, 0, '', ' ') . " so'm kerak).\n\nHisobingizni to'ldiring:",
                topupMethodKeyboard()
            );
            return;
        }
    }

    setUserState($chatId, "awaiting_topic:slayd:{$volumeKey}");
    Telegram::sendMessage(
        $chatId,
        "✅ Dizayn tanlandi: " . pptxDesigns()[$designKey]['label'] . "\n\n✏️ Taqdimot mavzusini yozing:" . I18n::hintLine($chatId) . Requirements::hint()
    );
}

function handleTopupAmountChosen(int $chatId, string $val): void
{
    if ($val === 'custom') {
        setUserState($chatId, 'awaiting_topup_custom_amount:_none:-');
        Telegram::sendMessage(
            $chatId,
            "✏️ To'ldirish summasini raqamda kiriting (so'mda, kamida " . number_format((int)Engine::c('MIN_TOPUP_AMOUNT', 1000), 0, '', ' ') . " so'm):"
        );
        return;
    }
    $amount = (int)$val;
    setUserState($chatId, "awaiting_topup_method:{$amount}:_none:-");
    Telegram::sendMessage($chatId, number_format($amount, 0, '', ' ') . " so'm to'lash usulini tanlang:", topupMethodKeyboard());
}

function handleTopupMethodChosen(int $chatId, string $method): void
{
    $stmt = db()->prepare('SELECT state FROM users WHERE telegram_id = :id');
    $stmt->execute(['id' => $chatId]);
    $state = (string)$stmt->fetchColumn();

    if (!str_starts_with($state, 'awaiting_topup_method:')) {
        Telegram::sendMessage($chatId, "Bu so'rov eskirgan, /balans orqali qaytadan boshlang.");
        return;
    }
    if (!payMethodOn($method)) {           // o'chirilgan usul (eski xabardagi tugma)
        Telegram::sendMessage($chatId, "Bu to'lov usuli hozircha o'chirilgan. Quyidagisidan foydalaning:", topupMethodKeyboard());
        return;
    }
    [, $amountStr, $resumeType, $resumeVolumeKey] = array_pad(explode(':', $state, 4), 4, null);
    $amount = (int)$amountStr;
    $maxTopup = defined('MAX_TOPUP_AMOUNT') ? (int)MAX_TOPUP_AMOUNT : 5000000;
    if ($amount < (int)Engine::c('MIN_TOPUP_AMOUNT', 1000) || $amount > $maxTopup) {
        Telegram::sendMessage($chatId, "Xatolik yuz berdi, /balans orqali qaytadan boshlang.");
        return;
    }

    if ($method === 'manual') {
        $topupId = createPendingTopup($chatId, $amount, 'manual', $resumeType, $resumeVolumeKey);
        setUserState($chatId, "awaiting_receipt:{$topupId}");
        Telegram::sendMessage(
            $chatId,
            "📸 " . number_format($amount, 0, '', ' ') . " so'mlik to'lov chekining (skrinshot) rasmini yuboring.\n"
            . "Administrator tekshirib, tasdiqlagach balansingiz to'ldiriladi."
        );
        return;
    }

    if ($method === 'humo') {
        HumoAuto::startTopup($chatId, $amount, $resumeType, $resumeVolumeKey);
        return;
    }

    if ($method === 'stars' && StarsPay::enabled()) {
        StarsPay::start($chatId, $amount, $resumeType, $resumeVolumeKey);
        return;
    }

    if (!in_array($method, ['click', 'payme'], true)) {
        return;
    }

    $topupId = createPendingTopup($chatId, $amount, $method, $resumeType, $resumeVolumeKey);
    $link = $method === 'click'
        ? ClickTopupPayment::generatePayLink($topupId, $amount)
        : PaymeTopupPayment::generatePayLink($topupId, $amount);
    $methodLabel = $method === 'click' ? 'Click' : 'Payme';

    Telegram::sendMessage(
        $chatId,
        "💳 " . number_format($amount, 0, '', ' ') . " so'm to'lash uchun havola. To'lov tasdiqlangach balansingiz avtomatik to'ldiriladi:",
        ['inline_keyboard' => [[['text' => "💳 $methodLabel orqali to'lash", 'url' => $link]]]]
    );
}

/**
 * Foydalanuvchi chek (rasm) yuborganda — buni bazaga saqlaymiz va ADMINga
 * tasdiqlash/rad etish tugmalari bilan yo'naltiramiz.
 */
function handleReceiptSubmission(int $chatId, string $state, string $fileId): void
{
    $topupId = (int)substr($state, strlen('awaiting_receipt:'));
    $stmt = db()->prepare(
        "UPDATE topups SET receipt_file_id = :f, updated_at = :now WHERE id = :id AND telegram_id = :tid AND status = 'pending'"
    );
    $stmt->execute(['f' => $fileId, 'now' => nowTs(), 'id' => $topupId, 'tid' => $chatId]);
    if ($stmt->rowCount() === 0) {
        // Bunday kutilayotgan to'ldirish yo'q (allaqachon ko'rib chiqilgan yoki boshqa foydalanuvchiniki)
        setUserState($chatId, 'idle');
        Telegram::sendMessage($chatId, "Bu to'ldirish so'rovi eskirgan. /balans orqali qaytadan boshlang.", mainMenu());
        return;
    }

    $topup = db()->prepare('SELECT * FROM topups WHERE id = :id');
    $topup->execute(['id' => $topupId]);
    $t = $topup->fetch(PDO::FETCH_ASSOC);
    if (!$t) {
        return;
    }

    setUserState($chatId, "awaiting_admin_review:{$topupId}");
    Telegram::sendMessage($chatId, "✅ Chekingiz qabul qilindi, admin tomonidan tekshirilmoqda. Iltimos kuting.");

    AdminEvents::receipt($topupId, $chatId, (int)$t['amount']);
    if (ADMIN_CHAT_ID) {
        Telegram::sendPhoto(
            ADMIN_CHAT_ID,
            $fileId,
            "🧾 Yangi to'lov cheki\nFoydalanuvchi ID: {$t['telegram_id']}\nSumma: " . number_format((int)$t['amount'], 0, '', ' ') . " so'm\nTo'ldirish #$topupId",
            [
                'inline_keyboard' => [[
                    ['text' => '✅ Tasdiqlash', 'callback_data' => "topup_approve:{$topupId}"],
                    ['text' => '❌ Rad etish', 'callback_data' => "topup_reject:{$topupId}"],
                ]],
            ]
        );
    }
}

/**
 * Faqat ADMIN bosgan taqdirda ishlaydi — boshqa hech kim (hatto callback_data'ni
 * bilsa ham) balansni tasdiqlay olmaydi, chunki tugma faqat admin xabarida bor
 * va biz baribir chatId'ni qat'iy tekshiramiz.
 */
function handleAdminTopupDecision(int $chatId, string $data): void
{
    if (!ADMIN_CHAT_ID || $chatId !== (int)ADMIN_CHAT_ID) {
        return;
    }

    $isApprove = str_starts_with($data, 'topup_approve:');
    $topupId = (int)substr($data, strlen($isApprove ? 'topup_approve:' : 'topup_reject:'));
    $cur = db()->prepare('SELECT status FROM topups WHERE id = :id');
    $cur->execute(['id' => $topupId]);
    $status = $cur->fetchColumn();
    if ($status === false) {
        Telegram::sendMessage($chatId, "To'ldirish #$topupId topilmadi.");
        return;
    }
    if ($status !== 'pending') {
        Telegram::sendMessage($chatId, "ℹ️ To'ldirish #$topupId allaqachon ko'rib chiqilgan (holati: $status).");
        return;
    }

    if ($isApprove) {
        markTopupPaid($topupId);
        Telegram::sendMessage($chatId, "✅ To'ldirish #$topupId tasdiqlandi.");
    } else {
        markTopupRejected($topupId);
        Telegram::sendMessage($chatId, "❌ To'ldirish #$topupId rad etildi.");
    }
}

/**
 * Foydalanuvchi tayyor hujjatni 1-5 yulduz bilan baholaydi. Past baho (1-2)
 * kelsa — admin darhol xabar oladi, tezroq aralashish imkoni bo'lsin.
 */
function handleRating(int $chatId, string $data): void
{
    // data = "rate:{jobId}:{stars}"
    $parts = explode(':', $data, 3);
    $jobId = (int)($parts[1] ?? 0);
    $stars = (int)($parts[2] ?? 0);
    if ($jobId <= 0 || $stars < 1 || $stars > 5) {
        return;
    }

    $stmt = db()->prepare('SELECT telegram_id, topic, rating FROM jobs WHERE id = :id');
    $stmt->execute(['id' => $jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job || (int)$job['telegram_id'] !== $chatId) {
        return; // faqat o'z buyurtmasini baholay oladi
    }
    if ($job['rating'] !== null) {
        Telegram::sendMessage($chatId, "Siz bu buyurtmani allaqachon baholagansiz, rahmat!");
        return;
    }

    $upd = db()->prepare('UPDATE jobs SET rating = :r WHERE id = :id');
    $upd->execute(['r' => $stars, 'id' => $jobId]);

    if ($stars <= 2) {
        // Past baho: sababini so'raymiz (admin «Yordam» bo'limida ko'radi) va bir martalik bepul qayta yozishni taklif qilamiz
        setUserState($chatId, "awaiting_feedback:$jobId");
        $full = db()->prepare('SELECT * FROM jobs WHERE id = ?');
        $full->execute([$jobId]);
        $fj = $full->fetch(PDO::FETCH_ASSOC) ?: [];
        $kb = [[['text' => "⏭ Izohsiz", 'callback_data' => 'fb:skip']]];
        $can = $fj && Growth::canRewrite($chatId, $fj);
        Telegram::sendMessage($chatId, "😔 Kechirasiz, natija sizni qoniqtirmadi.\n\n<b>Nima yoqmadi?</b> Bitta xabarda yozing (masalan: «mavzudan chetga chiqqan», «2-bob juda qisqa»)."
            . ($can ? "\nIzohingizdan keyin ishni <b>bepul qayta yozib beramiz</b> — izohingiz hisobga olinadi." : ''), ['inline_keyboard' => $kb]);
        if (ADMIN_CHAT_ID) {
            Telegram::notifyAdmin("😟 Past baho: buyurtma #$jobId (\"" . Telegram::escape($job['topic']) . "\") " . str_repeat('⭐', $stars) . ". Sababi so'raldi.");
        }
        return;
    }
    Telegram::sendMessage($chatId, str_repeat('⭐', $stars) . " uchun rahmat! Fikringiz biz uchun muhim.");
}

/** Past bahodan keyingi izoh: adminning «Yordam» bo'limiga tushadi + bepul qayta yozish tugmasi. */
function handleFeedbackText(int $chatId, int $jobId, string $text): void
{
    setUserState($chatId, 'idle');
    $text = mb_substr(trim($text), 0, 1000);
    try {
        db()->prepare('INSERT INTO engine_kv (k, v, ts) VALUES (?, ?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v, ts = excluded.ts')->execute(['fb:' . $jobId, $text, time()]);
        AdminPro::supportIncoming($chatId, "⭐ Past baho, buyurtma #$jobId: " . $text);
    } catch (Throwable $e) {
        logError('webhook', 'feedback: ' . $e->getMessage());
    }
    $st = db()->prepare('SELECT * FROM jobs WHERE id = ?');
    $st->execute([$jobId]);
    $j = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($j && Growth::canRewrite($chatId, $j)) {
        Telegram::sendMessage($chatId, "🙏 Rahmat, izohingiz qabul qilindi va administratorga yetkazildi.\n\nIshni izohingizni hisobga olib <b>bepul</b> qayta yozib beraylikmi?",
            ['inline_keyboard' => [[['text' => '🔁 Ha, bepul qayta yozing', 'callback_data' => 'rw:' . $jobId]], [['text' => "Kerak emas", 'callback_data' => 'fb:skip']]]]);
        return;
    }
    Telegram::sendMessage($chatId, "🙏 Rahmat, izohingiz administratorga yetkazildi. Kerak bo'lsa, shu chatda javob beramiz.", mainMenu());
}

// ============================================================
// REFERAL
// ============================================================

function sendReferralInfo(int $chatId): void
{
    $stmt = db()->prepare('SELECT referral_count, referral_earned FROM users WHERE telegram_id = :id');
    $stmt->execute(['id' => $chatId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    $botUsername = (defined('BOT_USERNAME') && BOT_USERNAME !== '' && BOT_USERNAME !== 'BOT_USERNAME_HERE')
        ? BOT_USERNAME
        : null;

    if (!$botUsername) {
        Telegram::sendMessage($chatId, "Referal tizimi hali sozlanmagan (admin BOT_USERNAME'ni to'ldirishi kerak).");
        return;
    }

    $link = "https://t.me/{$botUsername}?start=ref{$chatId}";

    Telegram::sendMessage(
        $chatId,
        "🎁 <b>Do'stlaringizni taklif qiling — pul ishlang!</b>\n\n"
        . "Har bir do'stingiz taklifingiz orqali kirib, birinchi PULLIK buyurtmasini bersa — "
        . "sizning balansingizga <b>" . number_format((int)Engine::c('REFERRAL_BONUS', 3000), 0, '', ' ') . " so'm</b> tushadi.\n\n"
        . "🔗 Sizning havolangiz:\n<code>{$link}</code>\n\n"
        . "📊 Statistikangiz:\n"
        . "— Taklif qilinganlar: <b>{$u['referral_count']}</b>\n"
        . "— Jami ishlangan: <b>" . number_format((int)$u['referral_earned'], 0, '', ' ') . " so'm</b>"
    );
}

// ============================================================
// ADMIN STATISTIKASI
// ============================================================

function sendAdminPanelLink(int $chatId): void
{
    if (!AdminSecurity::enabled()) {
        Telegram::sendMessage($chatId, "⚠️ Admin panel o'chirilgan: config.php da PUBLIC_BASE_URL https:// bilan boshlanishi kerak.");
        return;
    }
    if (!AdminSecurity::rate("tgadmin:$chatId", 6, 600)) {
        Telegram::sendMessage($chatId, "Juda tez-tez so'rayapsiz. Bir necha daqiqadan keyin qayta urinib ko'ring.");
        return;
    }
    // Avvalgi ochilmagan havola xabarlarini chatdan o'chiramiz
    $old = db()->prepare('SELECT chat_id, message_id FROM admin_tokens WHERE tg_id = ? AND used_at IS NULL AND revoked = 0 AND message_id IS NOT NULL');
    $old->execute([$chatId]);
    foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $o) {
        Telegram::deleteMessage((int)$o['chat_id'], (int)$o['message_id']);
    }
    $token = AdminSecurity::createLaunchToken($chatId, $chatId);
    $url = rtrim(PUBLIC_BASE_URL, '/') . '/' . ADMIN_PATH . '/index.php?t=' . $token;
    $mid = Telegram::sendMessage(
        $chatId,
        "🛡 <b>Admin panel</b>\nTugmani bosing — panel Telegram ichida ochiladi.\n⏱ Tugma " . (int)(ADMIN_LINK_TTL / 60) . " daqiqa amal qiladi va faqat bir marta ishlaydi."
        . (AdminSecurity::pinRequired() ? "\n🔑 PIN kod so'raladi." : "\n⚠️ PIN o'rnatilmagan (config.php → ADMIN_PIN_HASH) — o'rnatish tavsiya etiladi."),
        ['inline_keyboard' => [[['text' => '🛡 Panelni ochish', 'web_app' => ['url' => $url]]]]]
    );
    if ($mid) {
        AdminSecurity::setTokenMessage($token, $mid);
    }
    AdminSecurity::audit('link_issued', $chatId);
}

function sendAdminStats(int $chatId): void
{
    $pdo = db();
    $totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $todayStart = strtotime('today');
    $newToday = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= $todayStart")->fetchColumn();

    $jobsByStatus = $pdo->query('SELECT status, COUNT(*) as c FROM jobs GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $jobsToday = (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE created_at >= $todayStart")->fetchColumn();

    $revenueTotal = (int)$pdo->query("SELECT COALESCE(SUM(price),0) FROM jobs WHERE is_free = 0 AND status IN ('pending','processing','done')")->fetchColumn();
    $revenueToday = (int)$pdo->query("SELECT COALESCE(SUM(price),0) FROM jobs WHERE is_free = 0 AND status IN ('pending','processing','done') AND created_at >= $todayStart")->fetchColumn();
    $topupRevenue = (int)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM topups WHERE status = 'approved'")->fetchColumn();

    $avgRating = $pdo->query('SELECT AVG(rating) FROM jobs WHERE rating IS NOT NULL')->fetchColumn();
    $ratingCount = (int)$pdo->query('SELECT COUNT(*) FROM jobs WHERE rating IS NOT NULL')->fetchColumn();

    $lines = [];
    $lines[] = "📊 <b>Statistika</b>\n";
    $lines[] = "👥 Foydalanuvchilar: <b>$totalUsers</b> (bugun: +$newToday)";
    $lines[] = "";
    $lines[] = "📦 Buyurtmalar (bugun: $jobsToday):";
    foreach (['pending' => '🕐 Navbatda', 'processing' => '⚙️ Ishlanmoqda', 'done' => '✅ Tayyor', 'failed' => '❌ Xato', 'awaiting_payment' => "⏳ To'lov kutilmoqda"] as $key => $lbl) {
        if (!empty($jobsByStatus[$key])) {
            $lines[] = "  $lbl: {$jobsByStatus[$key]}";
        }
    }
    $lines[] = "";
    $lines[] = "💰 Daromad (buyurtmalardan): <b>" . number_format($revenueTotal, 0, '', ' ') . " so'm</b> (bugun: " . number_format($revenueToday, 0, '', ' ') . " so'm)";
    $lines[] = "💳 Balans to'ldirishlar (jami tasdiqlangan): <b>" . number_format($topupRevenue, 0, '', ' ') . " so'm</b>";
    $lines[] = "";
    $lines[] = $ratingCount > 0
        ? "⭐ O'rtacha baho: <b>" . round((float)$avgRating, 1) . "</b> ($ratingCount ta baho)"
        : "⭐ Hali baho yo'q";

    Telegram::sendMessage($chatId, implode("\n", $lines));
}

/**
 * «🔁 Qayta buyurtma»: oldingi buyurtmaning turi, hajmi, dizayni va talaba ma'lumotlari bilan yangi buyurtma.
 * ro:<id> — yangi mavzu so'raladi; rt:<id> — xuddi shu mavzu bilan (yangi matn) darhol buyurtma.
 * Narx va balans tekshiruvi odatdagidek handleTopicSubmission() da (atomik) bajariladi.
 */
function handleReorder(int $chatId, string $data): void
{
    [$kind, $id] = explode(':', $data, 2);
    $st = db()->prepare('SELECT * FROM jobs WHERE id = ? AND telegram_id = ?');
    $st->execute([(int)$id, $chatId]);
    $job = $st->fetch(PDO::FETCH_ASSOC);
    $vols = $job ? volumesFor((string)$job['type']) : null;
    if (!$job || !$vols || !isset($vols[$job['volume_key']])) {
        Telegram::sendMessage($chatId, "⚠️ Bu buyurtmani takrorlab bo'lmaydi (tarif o'zgargan bo'lishi mumkin). Menyudan yangidan boshlang.", mainMenu());
        return;
    }
    $type = (string)$job['type'];
    $vk = (string)$job['volume_key'];
    // talaba ma'lumotlari — o'sha buyurtmadagidek (titul varag'i)
    $stu = json_decode((string)($job['student'] ?? ''), true);
    if (is_array($stu) && !empty($stu['full_name'])) {
        $draft = ['first' => (string)($stu['first_name'] ?? ''), 'last' => (string)($stu['last_name'] ?? $stu['full_name']), 'group' => (string)($stu['group_name'] ?? ''), 'univ' => (string)($stu['university'] ?? '')];
        db()->prepare('UPDATE users SET order_student = ? WHERE telegram_id = ?')->execute([json_encode($draft, JSON_UNESCAPED_UNICODE), $chatId]);
    }
    if ($type === 'slayd') {
        $dk = (string)($job['design_key'] ?? '');
        db()->prepare('UPDATE users SET pending_design_key = ? WHERE telegram_id = ?')->execute([isset(pptxDesigns()[$dk]) ? $dk : DEFAULT_PPTX_DESIGN, $chatId]);
    }
    $state = "awaiting_topic:{$type}:{$vk}";
    setUserState($chatId, $state);
    if ($kind === 'rt') {
        $u = db()->prepare('SELECT * FROM users WHERE telegram_id = ?');
        $u->execute([$chatId]);
        $user = $u->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            handleTopicSubmission($user, $chatId, $type, $vk, (string)$job['topic']);
        }
        return;
    }
    $price = (int)$vols[$vk]['price'];
    if ($type === 'slayd' && isProDesign((string)($job['design_key'] ?? ''))) {
        $price = ProDeck::price($price);
    }
    $design = $type === 'slayd' ? "\n🎨 Dizayn: " . Telegram::escape(preg_replace('/^\d+\.\s*/u', '', (string)(pptxDesigns()[$job['design_key']]['label'] ?? '')) ?? '') : '';
    Telegram::sendMessage(
        $chatId,
        "🔁 <b>Qayta buyurtma</b>: " . DocTypes::label($type) . " · " . Telegram::escape((string)$vols[$vk]['label']) . " · " . number_format($price, 0, '', ' ') . " so'm"
        . $design . "\n👤 Titul ma'lumotlari — o'sha buyurtmadagidek.\n\n✏️ Yangi mavzuni yozing" . I18n::hintLine($chatId)
        . "\n\nYoki o'sha mavzuni yangi matn bilan qayta tayyorlash:",
        ['inline_keyboard' => [[['text' => '♻️ «' . mb_substr((string)$job['topic'], 0, 40) . '» — qayta', 'callback_data' => 'rt:' . (int)$job['id']]]]]
    );
}

function sendOrderList(int $chatId, int $page = 0, ?int $editMessageId = null): void
{
    $perPage = 5;
    $page = max(0, $page);
    $cnt = db()->prepare('SELECT COUNT(*) FROM jobs WHERE telegram_id = :id');
    $cnt->execute(['id' => $chatId]);
    $total = (int)$cnt->fetchColumn();

    if ($total === 0) {
        Telegram::sendMessage($chatId, "Sizda hali buyurtmalar yo'q.", mainMenu());
        return;
    }
    $pages = (int)ceil($total / $perPage);
    $page = min($page, $pages - 1);

    $stmt = db()->prepare(
        'SELECT id, type, topic, volume_key, price, status, tg_file_id, pres_id, created_at FROM jobs WHERE telegram_id = :id ORDER BY id DESC LIMIT :n OFFSET :o'
    );
    $stmt->bindValue(':id', $chatId, PDO::PARAM_INT);
    $stmt->bindValue(':n', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':o', $page * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $statusLabels = [
        'awaiting_payment' => "⏳ To'lov kutilmoqda",
        'pending' => '🕐 Navbatda',
        'processing' => '⚙️ Tayyorlanmoqda',
        'done' => '✅ Tayyor',
        'failed' => '❌ Xatolik',
        'cancelled' => '🚫 Bekor qilingan',
    ];

    $lines = ["📋 <b>Buyurtmalaringiz</b> (" . ($page + 1) . "/$pages-sahifa, jami: $total)\n"];
    $kb = [];
    foreach ($rows as $r) {
        $status = $statusLabels[$r['status']] ?? $r['status'];
        $typeLabel = ucfirst(typeLabel($r['type']));
        $topic = Telegram::escape(mb_substr((string)$r['topic'], 0, 60));
        $lines[] = "#{$r['id']} · " . date('d.m.Y', (int)$r['created_at']) . " — {$typeLabel} «{$topic}» — $status";
        if ($r['status'] === 'done') {
            $btns = [];
            if (!empty($r['tg_file_id'])) {
                $btns[] = ['text' => "📥 #{$r['id']} yuklab olish", 'callback_data' => 'dl:' . $r['id']];
            }
            if (!empty($r['pres_id'])) {
                $btns[] = ['text' => "✏️ #{$r['id']} tahrirlash", 'callback_data' => 'edit:' . $r['pres_id']];
            }
            if ($btns) {
                $kb[] = $btns;
            }
        }
        if (in_array($r['status'], ['done', 'failed', 'cancelled'], true) && DocTypes::exists((string)$r['type'])) {
            $kb[] = [['text' => "🔁 #{$r['id']} kabi yangi buyurtma", 'callback_data' => 'ro:' . $r['id']]];
        }
    }
    $nav = [];
    if ($page > 0) {
        $nav[] = ['text' => '⬅️ Oldingi', 'callback_data' => 'ord:' . ($page - 1)];
    }
    if ($page < $pages - 1) {
        $nav[] = ['text' => 'Keyingi ➡️', 'callback_data' => 'ord:' . ($page + 1)];
    }
    if ($nav) {
        $kb[] = $nav;
    }
    $markup = $kb ? ['inline_keyboard' => $kb] : null;

    if ($editMessageId !== null) {
        $res = Telegram::editQuick($chatId, $editMessageId, implode("\n", $lines), $markup ?? ['inline_keyboard' => []]);
        if ($res['ok'] || $res['same']) {
            return;
        }
    }
    Telegram::sendMessage($chatId, implode("\n", $lines), $markup ?? mainMenu());
}

/** «📥 yuklab olish»: tayyor faylni file_id orqali qayta yuboradi. */
function resendJobFile(int $chatId, int $uid, int $jobId, string $callbackId): void
{
    $st = db()->prepare("SELECT * FROM jobs WHERE id = ? AND telegram_id = ? AND status = 'done'");
    $st->execute([$jobId, $uid]);
    $job = $st->fetch(PDO::FETCH_ASSOC);
    if (!$job || empty($job['tg_file_id'])) {
        Telegram::answerCallbackQuery($callbackId, "Bu buyurtmaning fayli saqlanmagan (eski buyurtma).", true);
        return;
    }
    Telegram::answerCallbackQuery($callbackId, 'Yuborilmoqda…');
    $topic = Telegram::escape(mb_substr((string)$job['topic'], 0, 120));
    if (!Telegram::sendDocumentById($uid, (string)$job['tg_file_id'], "📥 Buyurtma #{$jobId}: «{$topic}»")) {
        Telegram::sendMessage($uid, "⚠️ Faylni yuborib bo'lmadi. Keyinroq qayta urinib ko'ring yoki administrator bilan bog'laning.");
    }
}


// ======================= TAHRIRLOVCHI (WebApp) =======================

/**
 * "✏️ Tahrirlash" bosilganda: yangi, qisqa muddatli havola yaratadi va uni FAQAT web_app tugmasi ichida yuboradi.
 * Havola matn sifatida hech qachon ko'rsatilmaydi; tugma bosilib, oyna ochilgach xabar chatdan o'chiriladi.
 */
function handleEditRequest(int $chatId, int $fromId, string $presId, string $callbackId): void
{
    if ($callbackId !== '') {
        Telegram::answerCallbackQuery($callbackId);
    }
    if ($fromId <= 0 || !EditorSecurity::enabled()) {
        Telegram::sendMessage($chatId, "Tahrirlash hozircha mavjud emas.");
        return;
    }
    $isDoc = false;
    $pres = EditorStore::validPid($presId) ? EditorStore::getOwned($presId, $fromId) : null;
    if (!$pres && EditorStore::validPid($presId)) {
        $pres = DocStore::getOwned($presId, $fromId);          // mustaqil ish / referat
        $isDoc = (bool)$pres;
    }
    if (!$pres) {
        Telegram::sendMessage($chatId, "Bu hujjat topilmadi yoki saqlash muddati tugagan (" . EDITOR_RETENTION_DAYS . " kun).");
        return;
    }
    if (!EditorSecurity::rate("tgedit:$fromId", 12, 600)) {
        Telegram::sendMessage($chatId, "Juda tez-tez so'rayapsiz. Bir necha daqiqadan keyin qayta urinib ko'ring.");
        return;
    }
    // Avvalgi (hali ochilmagan) tugmali xabarlarni chatdan o'chiramiz
    $old = db()->prepare('SELECT chat_id, message_id FROM edit_tokens WHERE pres_id = ? AND telegram_id = ? AND revoked = 0 AND opened_at IS NULL AND message_id IS NOT NULL');
    $old->execute([$presId, $fromId]);
    foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $o) {
        Telegram::deleteMessage((int)$o['chat_id'], (int)$o['message_id']);
    }
    $chatId = $fromId; // havola faqat egasining shaxsiy chatiga yuboriladi (soxta chat id'ga emas)
    $token = EditorSecurity::createLaunchToken($presId, $fromId, $chatId);
    $url = rtrim(PUBLIC_BASE_URL, '/') . '/' . EDITOR_PATH . '/index.php?t=' . $token;
    $mid = Telegram::sendMessage(
        $chatId,
        "✏️ <b>" . ($isDoc ? 'Hujjatni tahrirlash' : 'Tahrirlash') . " oynasi tayyor</b>\nTugmani bosing — oyna Telegram ichida ochiladi.\n⏱ Tugma " . (int)(EDITOR_LINK_TTL / 60) . " daqiqa amal qiladi.",
        ['inline_keyboard' => [[['text' => '✏️ Tahrirlash oynasini ochish', 'web_app' => ['url' => $url]]]]]
    );
    if ($mid) {
        EditorSecurity::setTokenMessage($token, $mid);
    }
    EditorSecurity::audit('link_issued', $fromId, $presId);
}

/** /tahrir — oxirgi taqdimotlar ro'yxati (har biri uchun tahrirlash tugmasi). */
function sendEditList(int $chatId): void
{
    if (!EditorSecurity::enabled()) {
        Telegram::sendMessage($chatId, "Tahrirlash hozircha mavjud emas.");
        return;
    }
    $rows = [];
    foreach (EditorStore::listForUser($chatId, 6) as $r) {
        $rows[] = $r + ['icon' => '📊'];
    }
    if (class_exists('DocStore')) {
        foreach (DocStore::listForUser($chatId, 6) as $r) {
            $rows[] = $r + ['icon' => '📄'];
        }
    }
    usort($rows, fn($a, $b) => (int)$b['created_at'] <=> (int)$a['created_at']);
    $rows = array_slice($rows, 0, 8);
    if (!$rows) {
        Telegram::sendMessage($chatId, "Tahrirlash uchun hujjat topilmadi. Avval «📊 Slayd», «📄 Mustaqil ish» yoki «📝 Referat» orqali buyurtma bering.");
        return;
    }
    $kb = [];
    foreach ($rows as $r) {
        $title = (string)$r['title'];
        $title = function_exists('mb_substr') ? mb_substr($title, 0, 26) : substr($title, 0, 26);
        $kb[] = [['text' => $r['icon'] . ' ' . $title . ' · ' . date('d.m', (int)$r['created_at']), 'callback_data' => 'edit:' . $r['id']]];
    }
    Telegram::sendMessage($chatId, "Qaysi ishni tahrirlaysiz? (oxirgi " . count($rows) . " tasi)", ['inline_keyboard' => $kb]);
}
