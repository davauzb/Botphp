<?php

/**
 * 🎁 Sodiqlik dasturi: har N-chi pullik buyurtma bepul (narxi LOYALTY_MAX_PRICE dan oshsa — shu summaga chegirma).
 * Hisob: foydalanuvchining balansdan to'langan (paid_by 'balance' yoki 'loyal') va bekor/xato bo'lmagan buyurtmalari.
 * Sovg'a buyurtmasi xato bilan tugasa, u hisobdan chiqadi — keyingi buyurtma yana sovg'a bo'ladi (adolatli).
 * Qayta yozish va tarjima hisobga kirmaydi. Sozlamalar: LOYALTY_EVERY (0 — o'chiq), LOYALTY_MAX_PRICE.
 */
class Loyalty
{
    private const SKIP = ['qayta', 'tarjima'];

    public static function every(): int
    {
        return max(0, (int)Engine::c('LOYALTY_EVERY', 10));
    }

    public static function cap(): int
    {
        return max(0, (int)Engine::c('LOYALTY_MAX_PRICE', 20000));
    }

    public static function count(int $tg): int
    {
        $st = db()->prepare("SELECT COUNT(*) FROM jobs WHERE telegram_id = ? AND (paid_by = 'loyal' OR (paid_by = 'balance' AND price > 0))
            AND status NOT IN ('failed','cancelled') AND type NOT IN ('qayta','tarjima')");
        $st->execute([$tg]);
        return (int)$st->fetchColumn();
    }

    /** Bu buyurtma uchun sovg'a chegirmasi (so'm). 0 — sovg'a navbati emas. */
    public static function discount(int $tg, string $type, int $price): int
    {
        $n = self::every();
        if ($n < 2 || $price <= 0 || in_array($type, self::SKIP, true)) {
            return 0;
        }
        if ((self::count($tg) + 1) % $n !== 0) {
            return 0;
        }
        $cap = self::cap();
        return $cap > 0 ? min($price, $cap) : $price;
    }

    /** Buyurtma qabul qilingandagi qisqa satr: «yana k ta buyurtmadan keyin — bepul». */
    public static function line(int $tg): string
    {
        $n = self::every();
        if ($n < 2) {
            return '';
        }
        $left = $n - (self::count($tg) % $n);
        if ($left === $n) {
            return "🎁 Sodiqlik: navbatdagi har {$n}-buyurtma — sovg'a!";
        }
        return $left === 1
            ? "🎁 Keyingi buyurtmangiz — <b>sovg'a</b> (bepul)!"
            : "🎁 Yana <b>{$left} ta</b> buyurtmadan keyin — bittasi bepul!";
    }

    /** /balans va profil uchun: progress ko'rinishi. */
    public static function status(int $tg): string
    {
        $n = self::every();
        if ($n < 2) {
            return '';
        }
        $done = self::count($tg) % $n;
        $bar = str_repeat('🟩', $done) . str_repeat('⬜️', $n - 1 - $done) . '🎁';
        $cap = self::cap();
        return "🎁 <b>Sodiqlik dasturi:</b> har {$n}-buyurtma bepul" . ($cap > 0 ? " (" . number_format($cap, 0, '', ' ') . " so'mgacha)" : '') . "\n" . $bar;
    }
}
