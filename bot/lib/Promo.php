<?php

/**
 * PROMOKODLAR va to'ldirish bonusi.
 *
 * Promokod turlari:
 *   balance    — kod kiritilganda balansga darhol `value` so'm qo'shiladi.
 *   topup_pct  — kod "bonus" sifatida saqlanadi; foydalanuvchining KEYINGI tasdiqlangan to'ldirishiga `value`% qo'shimcha beriladi.
 *
 * Qoidalar: bir foydalanuvchi bir kodni faqat 1 marta ishlata oladi; `max_uses` (0 = cheksiz) va `expires_at` (0 = muddatsiz)
 * tekshiriladi; hammasi bitta tranzaksiyada (parallel so'rovlarda ham limit buzilmaydi). Noto'g'ri kodni ko'p kiritish
 * vaqtincha bloklanadi (tasodifiy topib olishdan himoya).
 *
 * Hamma foydalanuvchiga bonus (aksiya): config.php → TOPUP_BONUS_PERCENT (0 = o'chiq), TOPUP_BONUS_MIN_AMOUNT.
 */
class Promo
{
    public const MAX_FAILS = 5;          // shuncha xato kiritishdan keyin
    public const BLOCK_SECONDS = 900;    // shuncha vaqtga bloklanadi

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS promo_codes (
            code TEXT PRIMARY KEY, kind TEXT NOT NULL, value INTEGER NOT NULL, max_uses INTEGER DEFAULT 0, used INTEGER DEFAULT 0,
            expires_at INTEGER DEFAULT 0, active INTEGER DEFAULT 1, note TEXT, created_at INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS promo_uses (
            id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL, telegram_id INTEGER NOT NULL, kind TEXT NOT NULL, value INTEGER NOT NULL,
            status TEXT NOT NULL, amount INTEGER DEFAULT 0, created_at INTEGER NOT NULL, consumed_at INTEGER,
            UNIQUE(code, telegram_id))');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_promo_uses_user ON promo_uses(telegram_id, status)');
    }

    public static function normalize(string $raw): string
    {
        $c = mb_strtoupper(trim($raw));
        return preg_replace('/[^A-Z0-9_\-]/u', '', $c) ?? '';
    }

    // ------------------------------------------------------------------ foydalanuvchi: kodni kiritish

    private static function failKey(int $uid): string
    {
        return 'promo_fail:' . $uid;
    }

    private static function blockedFor(int $uid): int
    {
        $r = Engine::kvGet(self::failKey($uid));
        if (!$r) {
            return 0;
        }
        [$n, $first] = array_pad(array_map('intval', explode(':', (string)$r['v'], 2)), 2, 0);
        if ($n >= self::MAX_FAILS) {
            return max(0, $first + self::BLOCK_SECONDS - time());
        }
        return 0;
    }

    private static function registerFail(int $uid): void
    {
        $r = Engine::kvGet(self::failKey($uid));
        $n = 0;
        $first = time();
        if ($r) {
            [$n, $first] = array_pad(array_map('intval', explode(':', (string)$r['v'], 2)), 2, 0);
            if (time() - $first > self::BLOCK_SECONDS) {
                $n = 0;
                $first = time();
            }
        }
        Engine::kvSet(self::failKey($uid), ($n + 1) . ':' . $first);
    }

    /** Qaytadi: [ok(bool), foydalanuvchiga xabar (HTML)] */
    public static function redeem(int $uid, string $raw): array
    {
        $wait = self::blockedFor($uid);
        if ($wait > 0) {
            return [false, "⏳ Juda ko'p noto'g'ri urinish. " . (int)ceil($wait / 60) . " daqiqadan keyin qayta urinib ko'ring."];
        }
        $code = self::normalize($raw);
        if (mb_strlen($code) < 3 || mb_strlen($code) > 32) {
            self::registerFail($uid);
            return [false, "❌ Promokod noto'g'ri. Qaytadan tekshirib yuboring."];
        }

        $pdo = db();
        $now = time();
        $bonusMsg = '';
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $st = $pdo->prepare('SELECT * FROM promo_codes WHERE code = ?');
            $st->execute([$code]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p || (int)$p['active'] !== 1) {
                $pdo->exec('COMMIT');
                self::registerFail($uid);
                return [false, "❌ Bunday promokod topilmadi yoki o'chirilgan."];
            }
            if ((int)$p['expires_at'] > 0 && (int)$p['expires_at'] < $now) {
                $pdo->exec('COMMIT');
                return [false, "⌛ Bu promokodning muddati tugagan."];
            }
            if ((int)$p['max_uses'] > 0 && (int)$p['used'] >= (int)$p['max_uses']) {
                $pdo->exec('COMMIT');
                return [false, "😕 Bu promokod limiti tugagan."];
            }
            $dup = $pdo->prepare('SELECT 1 FROM promo_uses WHERE code = ? AND telegram_id = ?');
            $dup->execute([$code, $uid]);
            if ($dup->fetchColumn()) {
                $pdo->exec('COMMIT');
                return [false, "ℹ️ Siz bu promokodni allaqachon ishlatgansiz."];
            }
            if ($p['kind'] === 'topup_pct') {
                $pend = $pdo->prepare("SELECT COUNT(*) FROM promo_uses WHERE telegram_id = ? AND status = 'pending'");
                $pend->execute([$uid]);
                if ((int)$pend->fetchColumn() > 0) {
                    $pdo->exec('COMMIT');
                    return [false, "ℹ️ Sizda ishlatilmagan bonus bor — avval balansni to'ldiring, keyin yangi kod kiriting."];
                }
            }

            $value = (int)$p['value'];
            if ($p['kind'] === 'balance') {
                $pdo->prepare('UPDATE users SET balance = balance + ? WHERE telegram_id = ?')->execute([$value, $uid]);
                $pdo->prepare("INSERT INTO promo_uses (code, telegram_id, kind, value, status, amount, created_at, consumed_at) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$code, $uid, 'balance', $value, 'applied', $value, $now, $now]);
                $bonusMsg = "🎉 Promokod qabul qilindi! Balansingizga <b>" . number_format($value, 0, '', ' ') . " so'm</b> qo'shildi.";
            } else {
                $pdo->prepare("INSERT INTO promo_uses (code, telegram_id, kind, value, status, amount, created_at) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$code, $uid, 'topup_pct', $value, 'pending', 0, $now]);
                $bonusMsg = "🎉 Promokod qabul qilindi! Keyingi balans to'ldirishingizga <b>+{$value}%</b> bonus qo'shiladi.";
            }
            $pdo->prepare('UPDATE promo_codes SET used = used + 1 WHERE code = ?')->execute([$code]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable $e2) {
            }
            logError('promo', 'redeem: ' . $e->getMessage());
            return [false, "⚠️ Vaqtincha xatolik. Birozdan keyin qayta urinib ko'ring."];
        }
        Engine::kvSet(self::failKey($uid), '0:' . time());
        if (class_exists('AdminEvents')) {
            AdminEvents::promoUsed($code, $uid, (string)$p['kind'], (int)$p['value']);
        }
        return [true, $bonusMsg];
    }

    // ------------------------------------------------------------------ to'ldirish bonusi (markTopupPaid ichida, tranzaksiya ICHIDA chaqiriladi)

    /**
     * To'ldirilgan $amount uchun bonusni hisoblaydi va (bor bo'lsa) balansga qo'shadi.
     * Qaytadi: berilgan bonus so'mi (0 = bonus yo'q).
     */
    public static function applyOnTopup(PDO $pdo, int $uid, int $amount): int
    {
        $bonus = 0;
        $cap = (int)Engine::c('PROMO_MAX_BONUS', 100000);
        // 1) foydalanuvchining kutayotgan promokod bonusi
        $st = $pdo->prepare("SELECT id, value FROM promo_uses WHERE telegram_id = ? AND status = 'pending' AND kind = 'topup_pct' ORDER BY id LIMIT 1");
        $st->execute([$uid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $b = (int)floor($amount * (int)$row['value'] / 100);
            $b = $cap > 0 ? min($b, $cap) : $b;
            $pdo->prepare("UPDATE promo_uses SET status = 'consumed', amount = ?, consumed_at = ? WHERE id = ? AND status = 'pending'")
                ->execute([$b, time(), $row['id']]);
            $bonus += $b;
        }
        // 2) hamma uchun aksiya
        $pct = (int)Engine::c('TOPUP_BONUS_PERCENT', 0);
        if ($pct > 0 && $amount >= (int)Engine::c('TOPUP_BONUS_MIN_AMOUNT', 0)) {
            $b = (int)floor($amount * $pct / 100);
            $bonus += $cap > 0 ? min($b, $cap) : $b;
        }
        if ($bonus > 0) {
            $pdo->prepare('UPDATE users SET balance = balance + ? WHERE telegram_id = ?')->execute([$bonus, $uid]);
        }
        return $bonus;
    }

    // ------------------------------------------------------------------ admin buyruqlari (Telegram)

    public static function adminHelp(): string
    {
        return "🎟 <b>Promokod buyruqlari</b>\n\n"
            . "<code>/promo_add KOD balance 5000</code> — kiritganga 5000 so'm beradi\n"
            . "<code>/promo_add KOD topup_pct 10</code> — keyingi to'ldirishga +10%\n"
            . "Ixtiyoriy oxirgi ikki raqam: <b>limit</b> (necha kishi, 0 = cheksiz) va <b>kun</b> (necha kun amal qiladi):\n"
            . "<code>/promo_add KOD balance 5000 100 7</code>\n\n"
            . "<code>/promo_list</code> — ro'yxat\n"
            . "<code>/promo_off KOD</code> — o'chirish";
    }

    /** Kod yaratish (Telegram buyrug'i ham, admin panel ham shu orqali). Qaytadi: [ok, xabar, kod]. */
    public static function create(string $rawCode, string $kind, int $value, int $max, int $days, string $note = ''): array
    {
        $code = self::normalize($rawCode);
        if ($code === '') {
            for ($i = 0; $i < 8; $i++) {
                $code .= 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'[random_int(0, 31)];
            }
        }
        if (mb_strlen($code) < 3 || mb_strlen($code) > 32) {
            return [false, "Kod 3-32 belgi bo'lsin (harf, raqam, - yoki _).", $code];
        }
        if (!in_array($kind, ['balance', 'topup_pct'], true)) {
            return [false, "Tur: balance yoki topup_pct.", $code];
        }
        if ($value < 1 || ($kind === 'topup_pct' && $value > 100) || ($kind === 'balance' && $value > (int)Engine::c('MAX_TOPUP_AMOUNT', 5000000))) {
            return [false, "Qiymat noto'g'ri (foiz: 1-100, summa: so'm).", $code];
        }
        $max = max(0, min(1000000, $max));
        $exp = $days > 0 ? time() + min($days, 3650) * 86400 : 0;
        $st = db()->prepare('INSERT OR IGNORE INTO promo_codes (code, kind, value, max_uses, expires_at, active, note, created_at) VALUES (?,?,?,?,?,1,?,?)');
        $st->execute([$code, $kind, $value, $max, $exp, mb_substr(trim($note), 0, 120), time()]);
        if ($st->rowCount() === 0) {
            return [false, "$code kodi allaqachon bor. Boshqa nom tanlang.", $code];
        }
        return [true, 'Yaratildi', $code];
    }

    public static function adminHandle(string $text): string
    {
        $p = preg_split('/\s+/u', trim($text)) ?: [];
        $cmd = $p[0] ?? '';
        if ($cmd === '/promo_add') {
            if (count($p) < 4) {
                return self::adminHelp();
            }
            $code = self::normalize($p[1]);
            $kind = $p[2];
            $value = (int)$p[3];
            $max = max(0, (int)($p[4] ?? 0));
            $days = max(0, (int)($p[5] ?? 0));
            if (mb_strlen($code) < 3 || mb_strlen($code) > 32) {
                return "⚠️ Kod 3-32 belgi bo'lsin (harf, raqam, - yoki _).";
            }
            if (!in_array($kind, ['balance', 'topup_pct'], true)) {
                return "⚠️ Tur: <b>balance</b> yoki <b>topup_pct</b>.";
            }
            if ($value < 1 || ($kind === 'topup_pct' && $value > 100) || ($kind === 'balance' && $value > (int)Engine::c('MAX_TOPUP_AMOUNT', 5000000))) {
                return "⚠️ Qiymat noto'g'ri (topup_pct: 1-100, balance: so'm).";
            }
            $exp = $days > 0 ? time() + $days * 86400 : 0;
            $st = db()->prepare('INSERT OR IGNORE INTO promo_codes (code, kind, value, max_uses, expires_at, active, created_at) VALUES (?,?,?,?,?,1,?)');
            $st->execute([$code, $kind, $value, $max, $exp, time()]);
            if ($st->rowCount() === 0) {
                return "⚠️ <b>$code</b> kodi allaqachon bor. Avval <code>/promo_off $code</code>, keyin boshqa nom bilan yarating.";
            }
            return "✅ Yaratildi: <b>$code</b> — " . ($kind === 'balance' ? number_format($value, 0, '', ' ') . " so'm" : "+{$value}% bonus")
                . ", limit: " . ($max ?: 'cheksiz') . ", muddat: " . ($days ? "$days kun" : 'cheksiz');
        }
        if ($cmd === '/promo_off') {
            $code = self::normalize($p[1] ?? '');
            $st = db()->prepare('UPDATE promo_codes SET active = 0 WHERE code = ?');
            $st->execute([$code]);
            return $st->rowCount() ? "✅ <b>$code</b> o'chirildi." : "⚠️ Bunday kod topilmadi.";
        }
        // /promo_list
        $rows = db()->query('SELECT * FROM promo_codes ORDER BY created_at DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return "Promokodlar yo'q.\n\n" . self::adminHelp();
        }
        $out = ["🎟 <b>Promokodlar</b>"];
        foreach ($rows as $r) {
            $exp = (int)$r['expires_at'];
            $state = (int)$r['active'] !== 1 ? '⛔' : (($exp > 0 && $exp < time()) ? '⌛' : '✅');
            $val = $r['kind'] === 'balance' ? number_format((int)$r['value'], 0, '', ' ') . " so'm" : '+' . (int)$r['value'] . '%';
            $out[] = "$state <b>{$r['code']}</b> · $val · " . (int)$r['used'] . '/' . ((int)$r['max_uses'] ?: '∞')
                . ($exp > 0 ? ' · ' . date('d.m.Y', $exp) . 'gacha' : '');
        }
        return implode("\n", $out);
    }
}
