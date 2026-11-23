<?php

require_once __DIR__ . '/Telegram.php';

// Eski config.php bilan ham ishlashi uchun standart qiymatlar (config.php dagi qiymat har doim ustun)
foreach ([
    'HUMO_ENABLED' => false,             // true — yoqilgan
    'HUMO_BOT_USERNAME' => 'HUMOcardbot', // to'lov xabarlarini yuboruvchi bot (@ siz)
    'HUMO_BOT_ID' => 0,                  // ixtiyoriy: shu botning Telegram ID si (berilsa username o'rniga ID tekshiriladi)
    'HUMO_CARD_NUMBER' => '',            // mijozga ko'rsatiladigan karta raqami (masalan 9860 1234 5678 6287)
    'HUMO_CARD_HOLDER' => '',            // karta egasi (ixtiyoriy, mijozga ko'rsatiladi)
    'HUMO_CARD_LAST4' => '',             // xabardagi *6287 bilan solishtiriladi ('' — raqamdan olinadi, u ham bo'sh bo'lsa tekshirilmaydi)
    'HUMO_TTL_MINUTES' => 30,            // mijozga aytiladigan to'lov muddati
    'HUMO_KEEP_HOURS' => 24,             // shundan keyin kutilayotgan so'rov yopiladi (kech kelgan to'lov shu vaqtgacha hisoblanadi)
    'HUMO_MAX_PENDING_PER_USER' => 3,    // bitta mijozda bir vaqtda ochiq so'rovlar soni
    'HUMO_NOTIFY_UNMATCHED' => true,     // kutayotgan mijoz bor-u, summa hech kimga mos kelmasa adminga xabar
] as $__k => $__v) {
    if (!defined($__k)) {
        define($__k, $__v);
    }
}

/**
 * HUMO avto-to'ldirish.
 *
 * G'oya: har bir to'ldirish so'rovi uchun NOYOB summa beriladi (masalan 10 017 so'm). Mijoz shu summani HUMO kartaga
 * o'tkazadi, @HUMOcardbot admin akkauntiga "➕ To'ldirish ..." xabarini yuboradi. Admin akkaunti botga Telegram Business
 * orqali ulangan bo'lgani uchun bot bu xabarni (business_message) ko'radi, summani o'qiydi va mos so'rovni tasdiqlaydi.
 *
 * Xavfsizlik:
 *  - faqat ADMIN akkauntining business ulanishi qabul qilinadi (begona odam o'z akkauntini ulab soxta to'lov yarata olmaydi);
 *  - faqat @HUMOcardbot chatidan, uning O'ZIDAN kelgan, forward qilinmagan xabarlar o'qiladi (admin o'zi yozgan matn emas);
 *  - boshqa chatlar xabarlari o'qilmaydi, saqlanmaydi va logga yozilmaydi;
 *  - bitta xabar ikki marta hisoblanmaydi (sana+vaqt+summa+qoldiq bo'yicha yagona kalit);
 *  - summa butun so'm bo'lishi va kutilayotgan so'rovga AYNAN mos kelishi shart.
 */
class HumoAuto
{
    // ------------------------------------------------------------------ holat

    public static function enabled(): bool
    {
        return (bool)HUMO_ENABLED;
    }

    /** Mijozga HUMO tugmasi ko'rsatilsinmi: yoqilgan, karta raqami berilgan va admin ulangan. */
    public static function ready(): bool
    {
        if (!self::enabled() || trim((string)HUMO_CARD_NUMBER) === '') {
            return false;
        }
        try {
            return self::activeConnectionCount() > 0 || (class_exists('Userbot') && Userbot::active());
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function ensure(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS humo_conn (
            id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, updated_at INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS humo_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ekey TEXT NOT NULL UNIQUE,
            ts INTEGER NOT NULL,
            msg_date INTEGER,
            amount INTEGER,
            tiyin INTEGER DEFAULT 0,
            card TEXT,
            source TEXT,
            status TEXT NOT NULL,        -- matched | unmatched | unparsed | error
            topup_id INTEGER,
            raw TEXT                     -- faqat tushunilmagan xabarlar uchun (qisqartirilgan)
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_humo_events_ts ON humo_events(ts)');
        // Bir vaqtda bir xil summali ikkita ochiq HUMO so'rovi bo'lishi mumkin emas (poyga holatidan himoya)
        try {
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_topups_humo_pending ON topups(amount) WHERE method = 'humo' AND status = 'pending'");
        } catch (Throwable $e) {
            logError('humo', 'unikal indeks yaratilmadi: ' . $e->getMessage());
        }
        $done = true;
    }

    // ------------------------------------------------------------------ Telegram update'lari

    /** Business bilan bog'liq update bo'lsa qayta ishlaydi va true qaytaradi (webhook.php shuni chaqiradi). */
    public static function handleUpdate(array $u): bool
    {
        if (isset($u['business_connection'])) {
            try {
                self::onConnection((array)$u['business_connection']);
            } catch (Throwable $e) {
                logError('humo', 'business_connection: ' . $e->getMessage());
            }
            return true;
        }
        if (isset($u['business_message'])) {
            try {
                self::noteSeen((array)$u['business_message']);

                self::onMessage((array)$u['business_message']);
            } catch (Throwable $e) {
                logError('humo', 'business_message: ' . $e->getMessage());
            }
            return true;
        }
        // Tahrirlangan/o'chirilgan business xabarlar hech qachon to'lov sifatida hisoblanmaydi
        return isset($u['edited_business_message']) || isset($u['deleted_business_messages']);
    }

    private static function onConnection(array $c): void
    {
        $id = (string)($c['id'] ?? '');
        $uid = (int)($c['user']['id'] ?? 0);
        if ($id === '' || $uid <= 0) {
            return;
        }
        $enabled = !array_key_exists('is_enabled', $c) || !empty($c['is_enabled']);
        if (!AdminSecurity::isAdmin($uid)) {
            return;     // admin bo'lmagan akkaunt ulanishi e'tiborga olinmaydi
        }
        $pdo = db();
        self::ensure($pdo);
        $pdo->prepare('INSERT INTO humo_conn (id, user_id, enabled, updated_at) VALUES (?,?,?,?)
                       ON CONFLICT(id) DO UPDATE SET user_id = excluded.user_id, enabled = excluded.enabled, updated_at = excluded.updated_at')
            ->execute([$id, $uid, $enabled ? 1 : 0, time()]);
        $canRead = true;
        if (isset($c['rights']) && is_array($c['rights']) && array_key_exists('can_read_messages', $c['rights'])) {
            $canRead = !empty($c['rights']['can_read_messages']);
        }
        if ($enabled && !$canRead) {
            Telegram::sendMessage($uid, "⚠️ <b>HUMO avto:</b> bot ulandi, lekin «Xabarlarni o'qish» huquqi berilmagan. Telegram → Sozlamalar → Business → Chatbotlar da huquqni yoqing.");
        } elseif ($enabled) {
            Telegram::sendMessage($uid, "✅ <b>HUMO avto:</b> Business ulandi. " . (self::enabled() ? "@" . HUMO_BOT_USERNAME . " xabarlari kuzatiladi." : "Lekin <code>HUMO_ENABLED</code> hali o'chiq (config.php)."));
        } else {
            Telegram::sendMessage($uid, "ℹ️ <b>HUMO avto:</b> Business ulanishi o'chirildi — avto-to'ldirish to'xtadi.");
        }
    }

    private static function onMessage(array $m): void
    {
        if (!self::enabled()) {
            return;
        }
        $connId = (string)($m['business_connection_id'] ?? '');
        if ($connId === '' || !self::connectionTrusted($connId)) {
            return;
        }
        if (!self::isFromHumoBot($m)) {
            return;     // boshqa chatlar xabariga umuman tegilmaydi
        }
        $text = (string)($m['text'] ?? $m['caption'] ?? '');
        if ($text === '') {
            return;
        }
        self::ingest($text, (int)($m['date'] ?? time()), (int)($m['chat']['id'] ?? 0), (int)($m['message_id'] ?? 0), 'business');
    }

    /**
     * HUMO bot xabarini qayta ishlash (Business orqali ham, Userbot orqali ham shu yerga keladi).
     * Chaqiruvchi xabar haqiqatan HUMO botidan ekanini tekshirgan bo'lishi shart.
     */
    public static function ingest(string $text, int $date, int $chatId, int $msgId, string $via = 'business'): void
    {
        if (!self::enabled() || trim($text) === '') {
            return;
        }
        $pdo = db();
        self::ensure($pdo);
        self::expireOld($pdo);
        if ($via === 'userbot') {
            try {
                $pdo->prepare('INSERT INTO engine_kv (k, v, ts) VALUES (?, ?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v, ts = excluded.ts')
                    ->execute(['humo_seen_bot', 'userbot: @' . HUMO_BOT_USERNAME . (stripos($text, 'UZS') !== false ? ' (UZS bor)' : ''), time()]);
            } catch (Throwable $e) {
            }
        }
        $p = self::parse($text);
        if ($p === null) {
            // Chiqim (➖) va boshqa xabarlar jim o'tkaziladi; "UZS" bor, lekin tushunilmagan kirimni admin ko'rishi uchun yozamiz
            if (stripos($text, 'UZS') !== false && mb_strpos($text, '➖') === false) {
                $pdo->prepare("INSERT OR IGNORE INTO humo_events (ekey, ts, msg_date, status, raw) VALUES (?,?,?,'unparsed',?)")
                    ->execute(['u:' . $chatId . ':' . $msgId, time(), $date, mb_substr($text, 0, 300)]);
            }
            return;
        }
        $cards = self::cards();
        if ($cards && !in_array($p['card'], $cards, true)) {
            // boshqa kartaga tushgan pul — hisoblanmaydi, lekin /humo da ko'rinsin (sozlama xatosini topish uchun)
            $pdo->prepare("INSERT OR IGNORE INTO humo_events (ekey, ts, msg_date, amount, tiyin, card, source, status) VALUES (?,?,?,?,?,?,?,'othercard')")
                ->execute(['c:' . $p['key'], time(), $date, $p['amount'], $p['tiyin'], $p['card'], $p['source']]);
            return;
        }
        self::credit($p, $date, $chatId, $msgId);
    }

    /**
     * Qabul qilinadigan kartalar (oxirgi 4 raqam): HUMO_CARD_LAST4, HUMO_CARD_NUMBER va Sozlamalardagi PAY_CARD_NUMBER.
     * Bir nechta bo'lishi mumkin — eski karta raqami config'da qolib ketsa ham yangi kartaga tushgan pul hisoblanadi.
     */
    public static function cards(): array
    {
        $out = [];
        foreach ([(string)HUMO_CARD_LAST4, (string)HUMO_CARD_NUMBER, class_exists('Engine') ? (string)Engine::c('PAY_CARD_NUMBER', '') : ''] as $v) {
            foreach (preg_split('/[,;]+/', $v) ?: [] as $one) {
                $d = preg_replace('/\D+/', '', $one);
                if (strlen($d) >= 4) {
                    $out[] = substr($d, -4);
                }
            }
        }
        return array_values(array_unique($out));
    }

    // ------------------------------------------------------------------ tekshiruvlar

    /** Xabar rostdan ham @HUMOcardbot dan kelganmi (admin o'zi yozgan yoki forward qilingan emas). */
    private static function isFromHumoBot(array $m): bool
    {
        $chat = (array)($m['chat'] ?? []);
        $from = (array)($m['from'] ?? []);
        if (($chat['type'] ?? '') !== 'private') {
            return false;
        }
        $chatId = (int)($chat['id'] ?? 0);
        if ($chatId <= 0) {
            return false;
        }
        $botId = (int)HUMO_BOT_ID;
        if ($botId > 0) {
            if ($chatId !== $botId) {
                return false;
            }
        } else {
            $want = strtolower(ltrim((string)HUMO_BOT_USERNAME, '@'));
            if ($want === '' || strtolower((string)($chat['username'] ?? '')) !== $want) {
                return false;
            }
        }
        // Yuboruvchi — shu botning o'zi bo'lishi shart (chat ID == from ID va u bot)
        if ((int)($from['id'] ?? 0) !== $chatId || empty($from['is_bot'])) {
            return false;
        }
        if (isset($m['forward_origin']) || isset($m['forward_date']) || isset($m['forward_from']) || isset($m['via_bot'])) {
            return false;
        }
        return true;
    }

    /** Business ulanishi ADMIN akkauntiga tegishli va yoqilganmi. */
    private static function connectionTrusted(string $connId): bool
    {
        $pdo = db();
        self::ensure($pdo);
        $st = $pdo->prepare('SELECT user_id, enabled FROM humo_conn WHERE id = ?');
        $st->execute([$connId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            // Ulanish haqidagi update o'tkazib yuborilgan bo'lishi mumkin — Telegram'dan so'raymiz
            $r = Telegram::call('getBusinessConnection', ['business_connection_id' => $connId]);
            $c = $r['result'] ?? null;
            if (!is_array($c) || empty($c['user']['id'])) {
                return false;
            }
            $uid = (int)$c['user']['id'];
            if (!AdminSecurity::isAdmin($uid)) {
                return false;
            }
            $en = !array_key_exists('is_enabled', $c) || !empty($c['is_enabled']);
            $pdo->prepare('INSERT OR REPLACE INTO humo_conn (id, user_id, enabled, updated_at) VALUES (?,?,?,?)')
                ->execute([$connId, $uid, $en ? 1 : 0, time()]);
            $row = ['user_id' => $uid, 'enabled' => $en ? 1 : 0];
        }
        return (int)$row['enabled'] === 1 && AdminSecurity::isAdmin((int)$row['user_id']);
    }

    private static function activeConnectionCount(): int
    {
        $pdo = db();
        self::ensure($pdo);
        $n = 0;
        foreach ($pdo->query('SELECT user_id FROM humo_conn WHERE enabled = 1')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (AdminSecurity::isAdmin((int)$r['user_id'])) {
                $n++;
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------ xabarni o'qish

    /**
     * @return array{amount:int,tiyin:int,card:string,ts:?int,balance:?int,source:string,key:string}|null
     * Faqat KIRIM ("➕ ... UZS") xabarlari. Chiqim yoki tushunarsiz matn uchun null.
     */
    public static function parse(string $text): ?array
    {
        $t = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}"], ' ', $text);
        if (mb_strpos($t, '➖') !== false) {
            return null;
        }
        if (!preg_match('/➕\s*([0-9][0-9 .,]*?)\s*UZS/iu', $t, $m)) {
            return null;
        }
        $money = self::money($m[1]);
        if ($money === null) {
            return null;
        }
        if (!preg_match('/\*\s*(\d{4})\b/u', $t, $cm)) {
            return null;    // karta raqami (oxirgi 4 ta) yo'q — ishonchsiz xabar
        }
        $ts = null;
        $tsRaw = '';
        if (preg_match('/(\d{1,2}):(\d{2})\s+(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $t, $tm)) {
            $tsRaw = $tm[0];
            // Xabardagi vaqt Toshkent (UTC+5) bo'yicha
            $ts = gmmktime((int)$tm[1] - 5, (int)$tm[2], 0, (int)$tm[4], (int)$tm[3], (int)$tm[5]) ?: null;
        }
        $balance = null;
        $balRaw = '';
        if (preg_match('/💰\s*([0-9][0-9 .,]*?)\s*UZS/iu', $t, $bm)) {
            $b = self::money($bm[1]);
            if ($b !== null) {
                $balance = $b[0] * 100 + $b[1];
                $balRaw = (string)$balance;
            }
        }
        $source = '';
        if (preg_match('/📍\s*([^\n\r]+)/u', $t, $sm)) {
            $source = trim($sm[1]);
        }
        $key = sha1($cm[1] . '|' . $tsRaw . '|' . ($money[0] * 100 + $money[1]) . '|' . $balRaw);
        return [
            'amount' => $money[0], 'tiyin' => $money[1], 'card' => $cm[1],
            'ts' => $ts, 'balance' => $balance, 'source' => mb_substr($source, 0, 80),
            'key' => $key, 'has_balance' => $balRaw !== '' && $tsRaw !== '',
        ];
    }

    /** "1.000,00" / "1 000,00" / "1,000.00" / "1000" -> [so'm, tiyin] */
    public static function money(string $s): ?array
    {
        $s = preg_replace('/\s+/u', '', trim($s));
        if ($s === '' || !preg_match('/^[0-9.,]+$/', $s)) {
            return null;
        }
        if (preg_match('/^(.*)[.,](\d{1,2})$/', $s, $mm) && $mm[1] !== '') {
            $int = preg_replace('/[.,]/', '', $mm[1]);
            $frac = str_pad($mm[2], 2, '0');
        } else {
            $int = preg_replace('/[.,]/', '', $s);
            $frac = '00';
        }
        if ($int === '' || strlen($int) > 12) {
            return null;
        }
        return [(int)$int, (int)$frac];
    }

    // ------------------------------------------------------------------ hisoblash

    private static function credit(array $p, int $msgDate, int $chatId, int $msgId): void
    {
        $pdo = db();
        // Qoldiq/vaqt bo'lmasa kalitga xabar identifikatori qo'shiladi (aks holda bir xil summali ikki to'lov bitta bo'lib qolardi)
        $ekey = $p['has_balance'] ? $p['key'] : sha1($p['key'] . '|m' . $chatId . ':' . $msgId);
        $status = 'unmatched';
        $topupId = null;

        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $ins = $pdo->prepare('INSERT OR IGNORE INTO humo_events (ekey, ts, msg_date, amount, tiyin, card, source, status) VALUES (?,?,?,?,?,?,?,?)');
            $ins->execute([$ekey, time(), $msgDate, $p['amount'], $p['tiyin'], $p['card'], $p['source'], 'unmatched']);
            if ($ins->rowCount() === 0) {
                $pdo->exec('COMMIT');
                return;     // bu to'lov allaqachon hisoblangan
            }
            $eventId = (int)$pdo->lastInsertId();

            if ($p['tiyin'] === 0 && $p['amount'] > 0) {
                // So'rov to'lovdan OLDIN yaratilgan bo'lishi kerak (soat farqi uchun 2 daqiqa zaxira)
                $q = $pdo->prepare("SELECT id FROM topups WHERE method = 'humo' AND status = 'pending' AND amount = :a AND created_at <= :d ORDER BY id LIMIT 1");
                $q->execute(['a' => $p['amount'], 'd' => $msgDate + 120]);
                $topupId = (int)($q->fetchColumn() ?: 0);
            }
            if ($topupId > 0) {
                $pdo->prepare('UPDATE humo_events SET status = ?, topup_id = ? WHERE id = ?')->execute(['matched', $topupId, $eventId]);
                $pdo->prepare('UPDATE topups SET external_trans_id = ?, updated_at = ? WHERE id = ?')->execute([$ekey, time(), $topupId]);
                $status = 'matched';
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable $e2) {
            }
            logError('humo', 'credit xato: ' . $e->getMessage());
            return;
        }

        if ($status === 'matched' && $topupId) {
            markTopupPaid((int)$topupId);       // atomik va ikki marta chaqirilsa ham xavfsiz
            $chk = $pdo->prepare('SELECT status FROM topups WHERE id = ?');
            $chk->execute([$topupId]);
            if ((string)$chk->fetchColumn() !== 'approved') {
                $pdo->prepare("UPDATE humo_events SET status = 'error' WHERE ekey = ?")->execute([$ekey]);
                Telegram::notifyAdmin("HUMO: to'ldirish #{$topupId} avto-tasdiqlanmadi (logni tekshiring). Summa: " . number_format($p['amount'], 0, '', ' ') . " so'm");
            }
            return;
        }

        // Mos so'rov topilmadi
        if (HUMO_NOTIFY_UNMATCHED) {
            $w = $pdo->prepare("SELECT COUNT(*) FROM topups WHERE method = 'humo' AND status = 'pending' AND created_at > ?");
            $w->execute([time() - (int)HUMO_TTL_MINUTES * 60 - 300]);
            if ((int)$w->fetchColumn() > 0) {
                $frac = $p['tiyin'] !== 0 ? ',' . str_pad((string)$p['tiyin'], 2, '0', STR_PAD_LEFT) : '';
                Telegram::notifyAdmin(
                    "HUMO: <b>" . number_format($p['amount'], 0, '', ' ') . $frac . " so'm</b> tushdi (💳 *{$p['card']}"
                    . ($p['source'] !== '' ? ', ' . Telegram::escape($p['source']) : '') . "), lekin hech bir kutilayotgan so'rovga mos kelmadi.\n"
                    . "Mijoz summani boshqacha o'tkazgan bo'lishi mumkin — Panel → Mijozlar orqali qo'lda to'ldiring. Ro'yxat: /humo"
                );
            }
        }
    }

    /** Muddati o'tgan kutilayotgan so'rovlarni yopadi. */
    private static function expireOld(PDO $pdo): void
    {
        $pdo->prepare("UPDATE topups SET status = 'rejected', admin_note = 'humo: muddat tugadi', updated_at = ? WHERE method = 'humo' AND status = 'pending' AND created_at < ?")
            ->execute([time(), time() - (int)HUMO_KEEP_HOURS * 3600]);
        if (random_int(1, 50) === 1) {
            $pdo->prepare('DELETE FROM humo_events WHERE ts < ?')->execute([time() - 90 * 86400]);
        }
    }

    // ------------------------------------------------------------------ mijoz tomoni

    /** Mijoz "HUMO" usulini tanlaganda: noyob summa beradi va ko'rsatma yuboradi. */
    public static function startTopup(int $chatId, int $base, ?string $resumeType, ?string $resumeVolumeKey): void
    {
        if (!self::ready()) {
            Telegram::sendMessage($chatId, "⚠️ Humo / Uzcard (Avto) to'lovi hozir mavjud emas. Boshqa usulni tanlang.");
            return;
        }
        $pdo = db();
        self::ensure($pdo);
        self::expireOld($pdo);

        $res = null;
        $capHit = false;
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $c = $pdo->prepare("SELECT COUNT(*) FROM topups WHERE method = 'humo' AND status = 'pending' AND telegram_id = ?");
            $c->execute([$chatId]);
            if ((int)$c->fetchColumn() >= (int)HUMO_MAX_PENDING_PER_USER) {
                $capHit = true;
            } else {
                $u = $pdo->prepare("SELECT amount FROM topups WHERE method = 'humo' AND status = 'pending' AND amount BETWEEN ? AND ?");
                $u->execute([$base, $base + 400]);
                $used = array_flip(array_map('intval', array_column($u->fetchAll(PDO::FETCH_ASSOC), 'amount')));
                $amount = null;
                foreach (self::offsets() as $off) {
                    if (!isset($used[$base + $off])) {
                        $amount = $base + $off;
                        break;
                    }
                }
                if ($amount !== null) {
                    $pdo->prepare("INSERT INTO topups (telegram_id, amount, method, status, resume_type, resume_volume_key, created_at, updated_at)
                                   VALUES (?, ?, 'humo', 'pending', ?, ?, ?, ?)")
                        ->execute([$chatId, $amount, $resumeType, $resumeVolumeKey, time(), time()]);
                    $res = [(int)$pdo->lastInsertId(), $amount];
                }
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable $e2) {
            }
            logError('humo', 'startTopup xato: ' . $e->getMessage());
            Telegram::sendMessage($chatId, "⚠️ Xatolik yuz berdi. Birozdan keyin qayta urinib ko'ring yoki boshqa usulni tanlang.");
            return;
        }

        if ($capHit) {
            Telegram::sendMessage($chatId, "⚠️ Sizda tugallanmagan Humo / Uzcard so'rovlari ko'p. Avval ularni to'lang yoki «❌ Bekor qilish» tugmasini bosing.");
            return;
        }
        if (!$res) {
            Telegram::sendMessage($chatId, "⚠️ Hozir to'lov so'rovlari band. Bir necha daqiqadan keyin qayta urinib ko'ring yoki boshqa usulni tanlang.");
            return;
        }
        [$topupId, $amount] = $res;
        $pdo->prepare("UPDATE users SET state = 'idle' WHERE telegram_id = ?")->execute([$chatId]);

        $card = trim((string)HUMO_CARD_NUMBER);
        $holder = trim((string)HUMO_CARD_HOLDER);
        Telegram::sendMessage(
            $chatId,
            "💳 <b>Humo / Uzcard (Avto) orqali to'lov</b>\n\n"
            . "Humo yoki Uzcard kartangizdan quyidagi summani <b>aynan shu miqdorda</b> o'tkazing:\n"
            . "💰 <b>" . number_format($amount, 0, '', ' ') . " so'm</b>  (nusxalash: <code>{$amount}</code>)\n\n"
            . "💳 Karta: <code>" . Telegram::escape($card) . "</code>" . ($holder !== '' ? "\n👤 " . Telegram::escape($holder) : '') . "\n\n"
            . "⏱ " . (int)HUMO_TTL_MINUTES . " daqiqa ichida to'lang.\n"
            . "⚠️ Summa <b>1 so'm ham farq qilmasin</b> — aks holda to'lov avtomatik tanilmaydi.\n\n"
            . "To'lov tushgach balansingiz 1–2 daqiqada o'zi to'ldiriladi.",
            ['inline_keyboard' => [[
                ['text' => '🔄 Tekshirish', 'callback_data' => "humo_check:{$topupId}"],
                ['text' => '❌ Bekor qilish', 'callback_data' => "humo_cancel:{$topupId}"],
            ]]]
        );
    }

    /** Tugmalar: humo_check:ID / humo_cancel:ID. true — shu modul qayta ishladi. */
    public static function onCallback(int $chatId, string $data, string $cbId = ''): bool
    {
        $isCheck = str_starts_with($data, 'humo_check:');
        $isCancel = str_starts_with($data, 'humo_cancel:');
        if (!$isCheck && !$isCancel) {
            return false;
        }
        $id = (int)substr($data, strpos($data, ':') + 1);
        $st = db()->prepare("SELECT * FROM topups WHERE id = ? AND telegram_id = ? AND method = 'humo'");
        $st->execute([$id, $chatId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) {
            Telegram::answerCallbackQuery($cbId, "So'rov topilmadi", true);
            return true;
        }
        if ($isCheck) {
            $msg = match ($t['status']) {
                'approved' => "✅ To'lov qabul qilingan, balans to'ldirilgan.",
                'pending' => "⏳ To'lov hali tushmadi. O'tkazgan bo'lsangiz 1–2 daqiqa kuting.",
                default => "❌ Bu so'rov yopilgan. /balans orqali yangisini oching.",
            };
            Telegram::answerCallbackQuery($cbId, $msg, true);
            return true;
        }
        $upd = db()->prepare("UPDATE topups SET status = 'rejected', admin_note = 'humo: mijoz bekor qildi', updated_at = ? WHERE id = ? AND status = 'pending'");
        $upd->execute([time(), $id]);
        if ($upd->rowCount() > 0) {
            Telegram::answerCallbackQuery($cbId, "Bekor qilindi", false);
            Telegram::sendMessage($chatId, "❌ Humo / Uzcard so'rovi bekor qilindi. Agar pul o'tkazib yuborgan bo'lsangiz — administrator bilan bog'laning.");
        } else {
            Telegram::answerCallbackQuery($cbId, "So'rov allaqachon yopilgan", true);
        }
        return true;
    }

    /**
     * Diagnostika: oxirgi kelgan business xabar haqida faqat METAMA'LUMOT (vaqt, chat bot bo'lsa — uning @username'i).
     * Odamlar bilan yozishmalar matni yoki ismi saqlanmaydi.
     */
    private static function noteSeen(array $m): void
    {
        try {
            $chat = (array)($m['chat'] ?? []);
            $from = (array)($m['from'] ?? []);
            $who = !empty($from['is_bot']) ? '@' . (string)($chat['username'] ?? $from['username'] ?? '?') : 'odam bilan chat';
            $hasUzs = stripos((string)($m['text'] ?? $m['caption'] ?? ''), 'UZS') !== false ? ' (UZS bor)' : '';
            db()->prepare('INSERT INTO engine_kv (k, v, ts) VALUES (?, ?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v, ts = excluded.ts')
                ->execute(['humo_seen', mb_substr($who . $hasUzs, 0, 80), time()]);
            if (!empty($from['is_bot'])) {
                db()->prepare('INSERT INTO engine_kv (k, v, ts) VALUES (?, ?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v, ts = excluded.ts')
                    ->execute(['humo_seen_bot', mb_substr($who . $hasUzs, 0, 80), time()]);
            }
        } catch (Throwable $e) {
        }
    }

    /** Webhook business xabarlarni oladimi; olmasa — mavjud manzil va maxfiy kalit bilan tuzatadi. Qaytadi: holat matni. */
    private static function webhookCheck(): string
    {
        $info = Telegram::call('getWebhookInfo');
        $r = (array)($info['result'] ?? []);
        $url = (string)($r['url'] ?? '');
        if ($url === '') {
            return "❌ webhook o'rnatilmagan";
        }
        $allowed = $r['allowed_updates'] ?? null;
        $need = ['business_connection', 'business_message', 'edited_business_message', 'deleted_business_messages'];
        $err = !empty($r['last_error_message']) ? " · oxirgi xato: " . Telegram::escape(mb_substr((string)$r['last_error_message'], 0, 60))
            . ' (' . gmdate('d.m H:i', (int)($r['last_error_date'] ?? 0) + 5 * 3600) . ')' : '';
        if (!is_array($allowed) || !$allowed) {
            return '✅ hamma turdagi xabarlar qabul qilinadi' . $err;
        }
        $missing = array_values(array_diff($need, $allowed));
        if (!$missing) {
            return '✅ business xabarlar qabul qilinadi' . $err;
        }
        $params = ['url' => $url, 'allowed_updates' => json_encode(array_values(array_unique(array_merge($allowed, $need, ['pre_checkout_query']))))];
        if (Settings::webhookSecret() !== '') {
            $params['secret_token'] = Settings::webhookSecret();
        }
        $ok = !empty(Telegram::call('setWebhook', $params)['ok']);
        return ($ok ? "🔧 TUZATILDI: webhook business xabarlarni olmayotgan edi (" : "❌ webhook business xabarlarni olmaydi, tuzatib bo'lmadi (")
            . implode(', ', $missing) . ')';
    }

    // ------------------------------------------------------------------ admin /humo

    public static function adminReport(): string
    {
        $pdo = db();
        self::ensure($pdo);
        $lines = ["💳 <b>Humo / Uzcard avto-to'ldirish</b>"];
        $lines[] = 'Holat: ' . (self::enabled() ? '✅ yoqilgan' : "⛔ o'chiq (config.php: HUMO_ENABLED)");
        $lines[] = 'Karta: ' . (trim((string)HUMO_CARD_NUMBER) !== '' ? '✅ berilgan' : "❌ HUMO_CARD_NUMBER bo'sh");
        $n = self::activeConnectionCount();
        $lines[] = 'Business ulanishi: ' . ($n > 0 ? "✅ ({$n})" : "❌ yo'q — Telegram → Sozlamalar → Business → Chatbotlar");
        if (class_exists('Userbot')) {
            $u = Userbot::status();
            $lines[] = '👤 Userbot: ' . ($u['auth'] ? ($u['enabled'] ? '✅ ulangan (' . Telegram::escape($u['name']) . ')' : "⏸ to'xtatilgan") : "— ulanmagan (panel → Tizim)")
                . ($u['last_poll'] ? ' · oxirgi o\'qish: ' . gmdate('d.m H:i', $u['last_poll'] + 5 * 3600) . ' — ' . Telegram::escape($u['last_result']) : '');
        }
        // Telegram'dagi HAQIQIY holat (bazadagi yozuv eskirgan bo'lishi mumkin)
        try {
            foreach ($pdo->query('SELECT id FROM humo_conn ORDER BY updated_at DESC LIMIT 3')->fetchAll(PDO::FETCH_COLUMN) as $cid) {
                $r = Telegram::call('getBusinessConnection', ['business_connection_id' => (string)$cid]);
                $c = $r['result'] ?? null;
                if (!is_array($c)) {
                    $lines[] = "  ↳ Telegram: ulanish topilmadi ❌ (" . Telegram::escape(mb_substr((string)($r['description'] ?? ''), 0, 60)) . ')';
                    continue;
                }
                $rights = (array)($c['rights'] ?? []);
                $read = array_key_exists('can_read_messages', $rights) ? !empty($rights['can_read_messages']) : true;
                $lines[] = '  ↳ Telegram: ' . (!empty($c['is_enabled']) ? 'yoqilgan ✅' : "O'CHIQ ❌") . ' · xabarlarni o\'qish: ' . ($read ? '✅' : '❌')
                    . ' · ulangan: ' . gmdate('d.m.Y', (int)($c['date'] ?? 0) + 5 * 3600);
            }
        } catch (Throwable $e) {
        }
        $lines[] = 'Qabul qilinadigan karta(lar): ' . (self::cards() ? '*' . implode(', *', self::cards()) : 'hammasi');
        $lines[] = 'Kuzatiladigan bot: @' . Telegram::escape((string)HUMO_BOT_USERNAME) . ((int)HUMO_BOT_ID > 0 ? ' (ID ' . (int)HUMO_BOT_ID . ')' : '');
        try {
            $lines[] = 'Webhook: ' . self::webhookCheck();
        } catch (Throwable $e) {
            $lines[] = 'Webhook: tekshirib bo\'lmadi';
        }
        $seen = function (string $k) use ($pdo) {
            $st = $pdo->prepare('SELECT v, ts FROM engine_kv WHERE k = ?');
            $st->execute([$k]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ? gmdate('d.m H:i', (int)$r['ts'] + 5 * 3600) . ' — ' . Telegram::escape((string)$r['v']) : "hali yo'q";
        };
        $lines[] = 'Oxirgi business xabar: ' . $seen('humo_seen');
        $lines[] = 'Oxirgi bot xabari: ' . $seen('humo_seen_bot');
        $last = $pdo->query("SELECT id, amount, status, created_at FROM topups WHERE method = 'humo' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($last) {
            $lines[] = "Oxirgi HUMO so'rovi: #{$last['id']} · " . number_format((int)$last['amount'], 0, '', ' ') . " so'm · {$last['status']} · "
                . gmdate('d.m H:i', (int)$last['created_at'] + 5 * 3600);
        }
        $p = $pdo->query("SELECT COUNT(*) FROM topups WHERE method = 'humo' AND status = 'pending'")->fetchColumn();
        $lines[] = "Kutilayotgan so'rovlar: <b>" . (int)$p . '</b>';
        $ev = $pdo->query('SELECT ts, amount, tiyin, status, topup_id, raw, card FROM humo_events ORDER BY id DESC LIMIT 8')->fetchAll(PDO::FETCH_ASSOC);
        if ($ev) {
            $lines[] = "\n<b>Oxirgi xabarlar:</b>";
            $icons = ['matched' => '✅', 'unmatched' => '⚠️', 'unparsed' => '❓', 'error' => '❌', 'othercard' => '🚫'];
            foreach ($ev as $e) {
                $when = gmdate('d.m H:i', (int)$e['ts'] + 5 * 3600);
                if ($e['status'] === 'unparsed') {
                    $lines[] = "❓ {$when} tushunilmadi: " . Telegram::escape(mb_substr(str_replace(["\n", "\r"], ' ', (string)$e['raw']), 0, 70));
                } else {
                    $lines[] = ($icons[$e['status']] ?? '•') . " {$when} +" . number_format((int)$e['amount'], 0, '', ' ')
                        . ($e['topup_id'] ? " → #{$e['topup_id']}" : ($e['status'] === 'unmatched' ? ' (mos so\'rov yo\'q)'
                            : ($e['status'] === 'othercard' ? ' (*' . $e['card'] . ' — boshqa karta)' : '')));
                }
            }
        }
        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------ yordamchi

    /** Noyob summa uchun qo'shiladigan miqdorlar: avval 11..99 (10 ga bo'linmaydiganlar), keyin 101..399. Aralashtiriladi. */
    private static function offsets(): array
    {
        $a = [];
        $b = [];
        for ($i = 11; $i <= 99; $i++) {
            if ($i % 10 !== 0) {
                $a[] = $i;
            }
        }
        for ($i = 101; $i <= 399; $i++) {
            if ($i % 10 !== 0) {
                $b[] = $i;
            }
        }
        shuffle($a);
        shuffle($b);
        return array_merge($a, $b);
    }
}
