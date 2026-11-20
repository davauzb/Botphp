<?php

foreach (['ADMIN_NOTIFY_ORDERS' => true, 'ADMIN_NOTIFY_FAILS' => true, 'ADMIN_NOTIFY_PROMO' => true, 'ADMIN_NOTIFY_USERS' => false, 'ADMIN_NOTIFY_TOPUPS' => true] as $__k => $__v) {
    if (!defined($__k)) {
        define($__k, $__v);
    }
}

/**
 * Admin xabarlari (bildirishnomalar): yangi buyurtma, buyurtma xatosi, promokod ishlatildi.
 * Har bir hodisa admin_events jadvaliga yoziladi (panelda «Xabarlar» bo'limi, o'qilmaganlar soni bilan) va
 * (config flaglari yoqilgan bo'lsa) barcha adminlarga Telegram'da ham yuboriladi.
 * Hech qachon asosiy oqimni buzmaydi — xatolar yutiladi.
 */
class AdminEvents
{
    public const KINDS = ['order' => '🆕', 'order_fail' => '❌', 'promo' => '🎟', 'user' => '👤', 'topup' => '💰', 'receipt' => '🧾'];
    private const KEEP = 500;

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL, kind TEXT NOT NULL, title TEXT, body TEXT,
            ref_id INTEGER, tg_id INTEGER, is_read INTEGER DEFAULT 0)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admev ON admin_events(is_read, id)');
    }

    private static function flag(string $kind): bool
    {
        return match ($kind) {
            'order' => (bool)ADMIN_NOTIFY_ORDERS,
            'order_fail' => (bool)ADMIN_NOTIFY_FAILS,
            'promo' => (bool)ADMIN_NOTIFY_PROMO,
            'user' => (bool)ADMIN_NOTIFY_USERS,
            'topup' => (bool)ADMIN_NOTIFY_TOPUPS,
            default => false,
        };
    }

    /** $html — Telegram uchun (HTML), $title/$body — panel uchun (oddiy matn). */
    public static function add(string $kind, string $title, string $body, ?int $refId, ?int $tgId, string $html): void
    {
        try {
            $pdo = db();
            $pdo->prepare('INSERT INTO admin_events (ts, kind, title, body, ref_id, tg_id) VALUES (?,?,?,?,?,?)')
                ->execute([time(), $kind, mb_substr($title, 0, 200), mb_substr($body, 0, 1500), $refId, $tgId]);
            if (random_int(1, 40) === 1) {
                $pdo->exec('DELETE FROM admin_events WHERE id <= (SELECT MAX(id) FROM admin_events) - ' . self::KEEP);
            }
            if ($html !== '' && self::flag($kind) && class_exists('AdminSecurity')) {
                AdminSecurity::notifyAdmins($html);
            }
        } catch (Throwable $e) {
            logError('admin_events', $e->getMessage());
        }
    }

    private static function typeLabel(string $t): string
    {
        return DocTypes::title($t);
    }

    public static function newOrder(int $jobId, int $uid, string $type, string $topic, string $vk, int $price, bool $free, array $student, ?string $username): void
    {
        $esc = fn($s) => Telegram::escape((string)$s);
        $who = $username ? '@' . $username : 'ID ' . $uid;
        $lines = [
            'Mavzu: ' . $topic,
            'Tur: ' . self::typeLabel($type) . ' · ' . $vk . ' · ' . ($free ? 'bepul' : number_format($price, 0, '', ' ') . " so'm"),
        ];
        if ($student) {
            $lines[] = 'Ism: ' . ($student['first_name'] ?? '') . ' · Familya: ' . ($student['last_name'] ?? '');
            $lines[] = 'Guruh: ' . ($student['group_name'] ?? '') . ' · Universitet: ' . ($student['university'] ?? '');
        }
        $lines[] = 'Foydalanuvchi: ' . $who . ' (' . $uid . ')';
        $html = "🆕 <b>Yangi buyurtma #{$jobId}</b>\n📚 " . $esc(self::typeLabel($type)) . ' · ' . $esc($vk) . ' · '
            . ($free ? 'bepul' : number_format($price, 0, '', ' ') . " so'm") . "\n📝 " . $esc(mb_substr($topic, 0, 200));
        if ($student) {
            $html .= "\n👤 " . $esc(($student['last_name'] ?? '') . ' ' . ($student['first_name'] ?? ''))
                . "\n👥 " . $esc($student['group_name'] ?? '') . "\n🏫 " . $esc($student['university'] ?? '');
        }
        $html .= "\n🙋 " . $esc($who) . " (<code>{$uid}</code>)";
        self::add('order', "Yangi buyurtma #{$jobId}", implode("\n", $lines), $jobId, $uid, $html);
    }

    public static function orderFailed(int $jobId, int $uid, string $reason): void
    {
        self::add('order_fail', "Buyurtma #{$jobId} xato bilan tugadi", 'Sabab: ' . $reason, $jobId, $uid,
            "❌ <b>Buyurtma #{$jobId}</b> xato bilan tugadi (user <code>{$uid}</code>)\n" . Telegram::escape(mb_substr($reason, 0, 200)));
    }

    public static function promoUsed(string $code, int $uid, string $kind, int $value): void
    {
        $val = $kind === 'balance' ? number_format($value, 0, '', ' ') . " so'm" : "+{$value}% bonus";
        self::add('promo', "Promokod ishlatildi: {$code}", "{$val} · user {$uid}", null, $uid,
            "🎟 Promokod <b>" . Telegram::escape($code) . "</b> ishlatildi · {$val} · user <code>{$uid}</code>");
    }

    public static function newUser(int $uid, ?string $username, ?int $referrer): void
    {
        $who = $username ? '@' . $username : 'ID ' . $uid;
        self::add('user', 'Yangi mijoz: ' . $who, 'ID ' . $uid . ($referrer ? ' · taklif qilgan: ' . $referrer : ''), null, $uid,
            "👤 <b>Yangi mijoz</b> " . Telegram::escape($who) . " (<code>{$uid}</code>)" . ($referrer ? "\n🔗 Taklif qilgan: <code>{$referrer}</code>" : ''));
    }

    /** Chek yuborildi — admin tekshirishi kerak (Telegram'da chek tugmalari bilan alohida boradi, shuning uchun bu yerda faqat panel yozuvi). */
    public static function receipt(int $topupId, int $uid, int $amount): void
    {
        self::add('receipt', "Yangi chek #{$topupId}", number_format($amount, 0, '', ' ') . " so'm · user {$uid} · «To'lov» bo'limida tasdiqlang", $topupId, $uid, '');
    }

    public static function topupPaid(int $topupId, int $uid, int $amount, int $bonus, string $method): void
    {
        $sum = number_format($amount, 0, '', ' ') . " so'm";
        self::add('topup', "Balans to'ldirildi #{$topupId}", $sum . ($bonus > 0 ? ' + bonus ' . number_format($bonus, 0, '', ' ') : '') . ' · ' . $method . ' · user ' . $uid, $topupId, $uid,
            "💰 <b>Balans to'ldirildi #{$topupId}</b>\n" . $sum . ($bonus > 0 ? ' + 🎁 ' . number_format($bonus, 0, '', ' ') : '') . ' · ' . Telegram::escape($method) . " · user <code>{$uid}</code>");
    }

    // ------------------------------------------------------------------ panel uchun

    public static function unread(): int
    {
        return (int)db()->query('SELECT COUNT(*) FROM admin_events WHERE is_read = 0')->fetchColumn();
    }

    public static function list(int $offset, int $limit = 25): array
    {
        $st = db()->prepare('SELECT id, ts, kind, title, body, ref_id, tg_id, is_read FROM admin_events ORDER BY id DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . max(0, $offset));
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        foreach ($rows as &$r) {
            foreach (['id', 'ts', 'is_read'] as $k) {
                $r[$k] = (int)$r[$k];
            }
            $r['ref_id'] = $r['ref_id'] !== null ? (int)$r['ref_id'] : null;
            $r['tg_id'] = $r['tg_id'] !== null ? (int)$r['tg_id'] : null;
        }
        return ['items' => $rows, 'more' => $more, 'unread' => self::unread()];
    }

    public static function markAllRead(): void
    {
        db()->exec('UPDATE admin_events SET is_read = 1 WHERE is_read = 0');
    }
}
