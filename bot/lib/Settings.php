<?php

/**
 * Admin paneldan o'zgartiriladigan SOZLAMALAR (config.php ni tahrirlamasdan).
 * Qiymat bazada bo'lsa — u ishlatiladi, bo'lmasa config.php dagi (yoki standart) qiymat.
 * Faqat shu ro'yxatdagi kalitlarni o'zgartirish mumkin (oq ro'yxat), har o'zgarish jurnalga yoziladi.
 * Narxlar: "PRICE:<tur>:<hajm>" kalitlari (masalan PRICE:slayd:10-15).
 */
class Settings
{
    /** kalit => [tur, guruh, nomi, izoh, min, max] */
    public const SCHEMA = [
        'MAINTENANCE_MODE' => ['bool', 'mode', 'Texnik ishlar rejimi', "Yoqilsa, mijozlar buyurtma bera olmaydi (adminlar ishlayveradi)", 0, 1],
        'MAINTENANCE_TEXT' => ['text', 'mode', 'Texnik ishlar xabari', "Mijozga ko'rsatiladigan matn", 0, 500],
        'FREE_TRIAL' => ['bool', 'money', 'Birinchi buyurtma bepul', "Har bir mijozga eng arzon tarif bir marta bepul", 0, 1],
        'TOPUP_BONUS_PERCENT' => ['int', 'money', "To'ldirishga bonus, %", "Hamma uchun aksiya (0 — o'chiq)", 0, 100],
        'TOPUP_BONUS_MIN_AMOUNT' => ['int', 'money', "Bonus uchun eng kam to'lov, so'm", '', 0, 10000000],
        'REFERRAL_BONUS' => ['int', 'money', "Referal bonusi, so'm", "Taklif qilingan do'st birinchi pullik buyurtma berganda", 0, 1000000],
        'MIN_TOPUP_AMOUNT' => ['int', 'money', "Eng kam to'ldirish, so'm", '', 100, 1000000],
        'PRO_SURCHARGE_PERCENT' => ['int', 'money', "PRO taqdimot qo'shimcha narxi, %", '', 0, 500],
        'STARS_RATE' => ['int', 'money', "1 ⭐ = necha so'm", 'Telegram Stars kursi', 50, 2000],
        'PAY_CARD_NUMBER' => ['text', 'money', "💳 Karta raqami (chek orqali to'lov)", "Mijozga ko'rsatiladi. Bo'sh bo'lsa — config.php dagi HUMO_CARD_NUMBER", 0, 30],
        'PAY_CARD_HOLDER' => ['text', 'money', 'Karta egasi (ixtiyoriy)', 'Masalan: Davron J.', 0, 60],
        'PAY_HUMO' => ['bool', 'money', "To'lov: ⚡ Humo / Uzcard (Avto)", "Telegram Business orqali avtomatik tasdiqlash. O'chiq bo'lsa — faqat chek (qo'lda)", 0, 1],
        'PAY_CLICK' => ['bool', 'money', "To'lov: 💳 Click", '', 0, 1],
        'PAY_PAYME' => ['bool', 'money', "To'lov: 💳 Payme", '', 0, 1],
        'PRO_ENABLED' => ['bool', 'feat', '⭐ PRO taqdimotlar', '', 0, 1],
        'KURS_ISHI_ENABLED' => ['bool', 'feat', '🎓 Kurs ishi', '', 0, 1],
        'MAQOLA_ENABLED' => ['bool', 'feat', '📰 Ilmiy maqola', '', 0, 1],
        'TEZIS_ENABLED' => ['bool', 'feat', '🧾 Tezis', '', 0, 1],
        'STARS_ENABLED' => ['bool', 'money', "To'lov: ⭐ Telegram Stars", '', 0, 1],
        'PLAN_REVIEW_ENABLED' => ['bool', 'feat', 'Reja tasdiqlash', 'Mijozga reja ko\'rsatilib, tasdiqlash so\'raladi', 0, 1],
        'REAL_BIBLIO' => ['bool', 'feat', 'Haqiqiy adabiyotlar (Crossref)', '', 0, 1],
        'DOC_IMAGES' => ['bool', 'feat', 'Hujjatga rasm', '', 0, 1],
        'DOC_TABLES' => ['bool', 'feat', 'Hujjatga jadval', '', 0, 1],
        'SECTION_SLIDES' => ['bool', 'feat', "Bo'lim ajratuvchi slaydlar", '', 0, 1],
        'SLIDE_PREVIEW' => ['bool', 'feat', 'Taqdimotdan lavhalar (albom)', '', 0, 1],
        'KONSPEKT_ENABLED' => ['bool', 'feat', 'Himoya konspekti', '', 0, 1],
        'DEFENSE_QA' => ['bool', 'feat', 'Himoya savollari', "Har ishga 10 ta ehtimoliy savol va javob (taqdimotda — konspektda, hujjatda — alohida fayl)", 0, 1],
        'REQUIREMENTS_ENABLED' => ['bool', 'feat', "O'qituvchi talablarini yuklash", "Mijoz topshiriq faylini/rasmini yuborsa, ish shunga moslab yoziladi", 0, 1],
        'TITLE_EXTRAS' => ['bool', 'feat', 'Titulda kafedra va o\'qituvchi', "Ixtiyoriy so'raladi (o'tkazib yuborish mumkin)", 0, 1],
        'UNIQ_CHECK' => ['bool', 'feat', 'Unikallik tekshiruvi', "Bot yozgan ishlar bilan solishtirib, o'xshash qismlarni qayta ifodalaydi (internet antiplagiati emas)", 0, 1],
        'ESSE_ENABLED' => ['bool', 'feat', '✍️ Esse / insho', '', 0, 1],
        'TEST_ENABLED' => ['bool', 'feat', '❓ Test savollari', '', 0, 1],
        'LAB_ENABLED' => ['bool', 'feat', '🔬 Laboratoriya ishi', '', 0, 1],
        'AMALIYOT_ENABLED' => ['bool', 'feat', '🏢 Amaliyot hisoboti', '', 0, 1],
        'VIDEO_ENABLED' => ['bool', 'feat', '🎬 Ovozli videotaqdimot', "Taqdimotdan ovozli MP4 (serverda ffmpeg va TTS kaliti kerak)", 0, 1],
        'MINIAPP_ENABLED' => ['bool', 'feat', '📱 Buyurtma Mini App', "Menyuda «📱 Ilova orqali buyurtma» tugmasi", 0, 1],
        'STD_MAX_IMAGES' => ['int', 'feat', 'Oddiy taqdimotda rasmlar soni (ko\'pi bilan)', 'PRO dizaynda — PRO_MAX_IMAGES', 0, 20],
        'IMAGE_AI_CHECK' => ['bool', 'feat', '🖼 Rasmni AI bilan tekshirish', "Rasm mavzuga mosligini AI ko'rib tasdiqlaydi (bitta rasmga 1-3 kichik so'rov)", 0, 1],
        'IMAGE_AI_FALLBACK' => ['bool', 'feat', '🎨 Mos rasm topilmasa — AI chizsin', 'Pollinations (kalitsiz), faqat oxirgi chora', 0, 1],
        'PART_REWRITE_ENABLED' => ['bool', 'feat', '♻️ Qismini qayta yozish', "Tayyor fayl ostida bitta bob/slaydni qayta yozish tugmasi", 0, 1],
        'PART_REWRITE_PRICE' => ['int', 'money', "Qismini qayta yozish narxi, so'm", '0 — bepul', 0, 1000000],
        'BUTTON_COLORS' => ['bool', 'feat', '🎨 Rangli tugmalar', "Tasdiqlash — yashil, bekor qilish — qizil, tanlash — ko'k (Telegram'ning yangi versiyalarida)", 0, 1],
        'PDF_BUTTON' => ['bool', 'feat', '📄 PDF tugmasi', "Tayyor fayl ostida «📄 PDF» tugmasi — ish PDF nusxada ham yuboriladi", 0, 1],
        'TRANSLATE_ENABLED' => ['bool', 'feat', '🌐 Tarjima', "Tayyor fayl ostida «Boshqa tilga tarjima» tugmasi (dizayn o'zgarmaydi)", 0, 1],
        'TRANSLATE_PRICE' => ['int', 'money', "Tarjima narxi, so'm", '0 — bepul', 0, 1000000],
        'FREE_REWRITE' => ['bool', 'growth', 'Past bahoda bepul qayta yozish', "1-2 ⭐ bergan mijozga bir marta (30 kunda) bepul qayta yozish taklif qilinadi", 0, 1],
        'SUB_ENABLED' => ['bool', 'growth', '💎 «Talaba PRO» obunasi', "Oylik obuna: N ta buyurtma bir narxda", 0, 1],
        'SUB_PRICE' => ['int', 'growth', "Obuna narxi, so'm", '', 1000, 10000000],
        'SUB_CREDITS' => ['int', 'growth', 'Obunadagi buyurtmalar soni', '', 1, 1000],
        'SUB_DAYS' => ['int', 'growth', 'Obuna muddati, kun', '', 1, 365],
        'SUB_MAX_PRICE' => ['int', 'growth', "Obuna qoplaydigan eng qimmat tarif, so'm", "Undan qimmat buyurtmalar balansdan to'lanadi", 0, 10000000],
        'AGENTS_ENABLED' => ['bool', 'growth', '🤝 Hamkorlar (agentlar)', "Hamkor havolasi orqali kelgan mijozlar buyurtmasidan % ulush", 0, 1],
        'MARKETING_ENABLED' => ['bool', 'growth', '📣 Avtomatik marketing', "Buyurtma bermaganlarga eslatma, boshqa turlar haqida xabar, «sog'indik» xabari (har biriga bir marta, 9:00–21:00)", 0, 1],
        'EDITOR_AI_ENABLED' => ['bool', 'feat', '✨ Tahrirlovchida AI', '', 0, 1],
        'DB_BACKUP_DAILY' => ['bool', 'feat', 'Kunlik baza zaxirasi', '', 0, 1],
    ];

    private static ?array $cache = null;

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT, admin INTEGER, updated_at INTEGER)');
    }

    private static function load(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (db()->query('SELECT k, v FROM settings')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    self::$cache[$r['k']] = $r['v'];
                }
            } catch (Throwable $e) {
            }
        }
        return self::$cache;
    }

    /** Bazadagi qiymat (bo'lmasa null). */
    public static function raw(string $k): ?string
    {
        $all = self::load();
        return array_key_exists($k, $all) ? $all[$k] : null;
    }

    /** Engine::c() shu orqali o'tadi: avval baza, keyin config, keyin standart. */
    public static function get(string $k, $default)
    {
        $v = self::raw($k);
        if ($v !== null && isset(self::SCHEMA[$k])) {
            return match (self::SCHEMA[$k][0]) {
                'bool' => $v === '1',
                'int' => (int)$v,
                default => $v,
            };
        }
        return defined($k) ? constant($k) : $default;
    }

    public static function price(string $type, string $vk, int $default): int
    {
        $v = self::raw("PRICE:$type:$vk");
        return $v !== null ? max(0, (int)$v) : $default;
    }

    /** Admin panel uchun: guruhlangan ro'yxat + joriy qiymatlar + narxlar. */
    public static function all(): array
    {
        $defaults = ['MAINTENANCE_MODE' => false, 'MAINTENANCE_TEXT' => "🛠 Botda texnik ishlar olib borilmoqda. Tez orada qaytamiz!", 'FREE_TRIAL' => true,
            'TOPUP_BONUS_PERCENT' => 0, 'TOPUP_BONUS_MIN_AMOUNT' => 0, 'REFERRAL_BONUS' => 3000, 'MIN_TOPUP_AMOUNT' => 1000, 'PRO_SURCHARGE_PERCENT' => 60,
            'STARS_RATE' => 200, 'VIDEO_ENABLED' => false, 'MINIAPP_ENABLED' => false, 'SUB_ENABLED' => false, 'MARKETING_ENABLED' => false,
            'STD_MAX_IMAGES' => 10, 'PART_REWRITE_PRICE' => 1000, 'PAY_CARD_NUMBER' => '', 'PAY_CARD_HOLDER' => '', 'PAY_HUMO' => false, 'PAY_CLICK' => false, 'PAY_PAYME' => false, 'STARS_ENABLED' => false, 'SUB_PRICE' => 49000, 'TRANSLATE_PRICE' => 2000, 'SUB_CREDITS' => 10, 'SUB_DAYS' => 30, 'SUB_MAX_PRICE' => 15000];
        $items = [];
        foreach (self::SCHEMA as $k => [$type, $group, $label, $hint, $min, $max]) {
            $items[] = ['k' => $k, 'type' => $type, 'group' => $group, 'label' => $label, 'hint' => $hint, 'min' => $min, 'max' => $max,
                'value' => self::get($k, $defaults[$k] ?? true), 'custom' => self::raw($k) !== null];
        }
        $prices = [];
        foreach (DocTypes::T as $t => $def) {
            $base = DocTypes::volumes($t, false) ?? [];
            foreach ($base as $vk => $v) {
                $prices[] = ['type' => $t, 'title' => $def['title'], 'vk' => (string)$vk, 'label' => (string)$v['label'], 'base' => (int)$v['price'],
                    'price' => self::price($t, (string)$vk, (int)$v['price']), 'enabled' => DocTypes::enabled($t)];
            }
        }
        return ['items' => $items, 'prices' => $prices];
    }

    /** Qaytadi: o'zgargan kalitlar ro'yxati. $changes = [k => v], narxlar: ['PRICE:slayd:10-15' => 7000]. */
    public static function save(int $admin, array $changes): array
    {
        $done = [];
        $st = db()->prepare('INSERT INTO settings (k, v, admin, updated_at) VALUES (?,?,?,?) ON CONFLICT(k) DO UPDATE SET v = excluded.v, admin = excluded.admin, updated_at = excluded.updated_at');
        foreach ($changes as $k => $v) {
            $k = (string)$k;
            if (preg_match('/^PRICE:([a-z_]+):([0-9A-Za-z_\-]{1,12})$/', $k, $m)) {
                $vols = DocTypes::volumes($m[1], false);
                if (!$vols || !isset($vols[$m[2]]) || !is_numeric($v)) {
                    continue;
                }
                $val = (string)max(0, min(10000000, (int)$v));
            } elseif (isset(self::SCHEMA[$k])) {
                [$type, , , , $min, $max] = self::SCHEMA[$k];
                if ($type === 'bool') {
                    $val = $v ? '1' : '0';
                } elseif ($type === 'int') {
                    if (!is_numeric($v)) {
                        continue;
                    }
                    $val = (string)max($min, min($max, (int)$v));
                } else {
                    $val = mb_substr(trim((string)$v), 0, $max);
                }
            } else {
                continue;
            }
            if (self::raw($k) === $val) {
                continue;
            }
            $st->execute([$k, $val, $admin, time()]);
            $done[$k] = $val;
        }
        self::$cache = null;
        return $done;
    }

    /** Narxni yoki sozlamani config.php dagi asl qiymatga qaytarish. */
    public static function reset(string $k): void
    {
        db()->prepare('DELETE FROM settings WHERE k = ?')->execute([$k]);
        self::$cache = null;
    }

    /** Ro'yxatdan tashqari ichki qiymat (masalan rasm kalitlari: IMGKEY:pexels). '' — o'chirish. */
    public static function setRaw(string $k, string $v, int $admin): void
    {
        if ($v === '') {
            db()->prepare('DELETE FROM settings WHERE k = ?')->execute([$k]);
        } else {
            db()->prepare('INSERT INTO settings (k, v, admin, updated_at) VALUES (?,?,?,?) ON CONFLICT(k) DO UPDATE SET v = excluded.v, admin = excluded.admin, updated_at = excluded.updated_at')
                ->execute([$k, $v, $admin, time()]);
        }
        self::$cache = null;
    }

    /** Webhook maxfiy kaliti: config.php dagi WEBHOOK_SECRET, bo'lmasa /himoya buyrug'i bazaga yozgani. */
    public static function webhookSecret(): string
    {
        if (defined('WEBHOOK_SECRET') && WEBHOOK_SECRET !== '') {
            return (string)WEBHOOK_SECRET;
        }
        try {
            return (string)(self::raw('WEBHOOK_SECRET_DB') ?? '');
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * /himoya: tasodifiy maxfiy kalit yaratib, webhook'ni shu kalit bilan qayta o'rnatadi (manzil va allowed_updates saqlanadi).
     * Avval Telegram'ga o'rnatiladi, keyin bazaga yoziladi — oraliqda kelgan yangilanishlar yo'qolmaydi.
     */
    public static function enableWebhookSecret(int $admin): string
    {
        if (defined('WEBHOOK_SECRET') && WEBHOOK_SECRET !== '') {
            return "✅ Himoya allaqachon yoqilgan (config.php dagi WEBHOOK_SECRET).";
        }
        $info = Telegram::call('getWebhookInfo');
        $url = (string)($info['result']['url'] ?? '');
        if ($url === '') {
            return "❌ Webhook o'rnatilmagan — avval webhook'ni o'rnating.";
        }
        $secret = bin2hex(random_bytes(24));
        $params = ['url' => $url, 'secret_token' => $secret];
        $allowed = $info['result']['allowed_updates'] ?? null;
        if (is_array($allowed) && $allowed) {
            $params['allowed_updates'] = json_encode(array_values($allowed));
        }
        $r = Telegram::call('setWebhook', $params);
        if (empty($r['ok'])) {
            return "❌ Telegram webhook'ni qabul qilmadi: " . Telegram::escape((string)($r['description'] ?? 'nomaʼlum xato'));
        }
        self::setRaw('WEBHOOK_SECRET_DB', $secret, $admin);
        if (class_exists('AdminSecurity')) {
            AdminSecurity::audit('webhook_secret', $admin);
        }
        return "🛡 <b>Webhook himoyasi yoqildi.</b>\nEndi bot faqat Telegram'dan kelgan, maxfiy kalitli so'rovlarni qabul qiladi.";
    }

    public static function maintenance(): bool
    {
        return (bool)self::get('MAINTENANCE_MODE', false);
    }
}
