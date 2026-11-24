<?php

/**
 * ⚡ Tezkor (Express) buyurtma: navbatda kutayotgan ish uchun qo'shimcha to'lov — ish navbat boshiga o'tadi.
 * Tugma progress xabarida faqat ish hali boshlanmagan va oldida kamida 1 ta ish bo'lsa chiqadi.
 * Ish bekor qilinsa yoki xato bilan tugasa — tezkor to'lov ham qaytariladi (Engine::refund).
 * Sozlamalar: EXPRESS_ENABLED, EXPRESS_PRICE.
 */
class Express
{
    public static function enabled(): bool
    {
        return (bool)Engine::c('EXPRESS_ENABLED', true) && self::price() > 0;
    }

    public static function price(): int
    {
        return max(0, (int)Engine::c('EXPRESS_PRICE', 3000));
    }

    /** Progress klaviaturasiga qo'shiladigan tugma yoki null. */
    public static function button(array $job): ?array
    {
        if (!self::enabled() || $job['status'] !== 'pending' || !empty($job['started_at']) || (int)($job['priority'] ?? 0) > 0
            || in_array($job['type'], ['qayta'], true) || Engine::queuePosition($job) < 2) {
            return null;
        }
        return ['text' => '⚡ Tezkor — ' . number_format(self::price(), 0, '', ' ') . " so'm", 'callback_data' => 'job:x:' . $job['id']];
    }

    private static function job(int $id, int $tg): ?array
    {
        $st = db()->prepare('SELECT * FROM jobs WHERE id = ? AND telegram_id = ?');
        $st->execute([$id, $tg]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function ask(int $tg, int $id): string
    {
        $j = self::job($id, $tg);
        if (!$j || !empty($j['started_at']) || $j['status'] !== 'pending') {
            return "Ish allaqachon boshlangan — tezkor rejim kerak emas.";
        }
        if ((int)($j['priority'] ?? 0) > 0) {
            return "⚡ Bu buyurtma allaqachon tezkor.";
        }
        $pos = Engine::queuePosition($j);
        Telegram::sendMessage($tg, "⚡ <b>Tezkor buyurtma</b>\n\nHozir navbatda <b>{$pos}-o'rindasiz</b>. "
            . number_format(self::price(), 0, '', ' ') . " so'm to'lasangiz, buyurtma #{$id} navbat boshiga o'tadi va birinchi bo'lib bajariladi.\n\n"
            . "Agar ish bekor qilinsa yoki xato bo'lsa — bu to'lov ham balansingizga qaytariladi.",
            ['inline_keyboard' => [[['text' => '✅ Tasdiqlash', 'callback_data' => 'job:xy:' . $id], ['text' => '✖️ Kerak emas', 'callback_data' => 'job:xn:' . $id]]]]);
        return '';
    }

    public static function confirm(int $tg, int $id, int $msgId = 0): string
    {
        $j = self::job($id, $tg);
        if (!$j || !empty($j['started_at']) || $j['status'] !== 'pending') {
            return "Ish allaqachon boshlangan — pul yechilmadi.";
        }
        $price = self::price();
        $pdo = db();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $u = $pdo->prepare("UPDATE jobs SET priority = 1 WHERE id = ? AND status = 'pending' AND started_at IS NULL AND COALESCE(priority, 0) = 0");
            $u->execute([$id]);
            if (!$u->rowCount()) {
                $pdo->exec('ROLLBACK');
                return "⚡ Bu buyurtma allaqachon tezkor yoki boshlangan.";
            }
            $b = $pdo->prepare('UPDATE users SET balance = balance - ? WHERE telegram_id = ? AND balance >= ?');
            $b->execute([$price, $tg, $price]);
            if (!$b->rowCount()) {
                $pdo->exec('ROLLBACK');
                Telegram::sendMessage($tg, "⚠️ Balansingiz yetarli emas (kerak: " . number_format($price, 0, '', ' ') . " so'm). /balans orqali to'ldiring.");
                return '';
            }
            $pdo->prepare('INSERT INTO engine_kv (k, v, ts) VALUES (?, ?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v, ts = excluded.ts')
                ->execute(['xp:' . $id, (string)$price, time()]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable $e2) {
            }
            logError('express', $e->getMessage());
            return "Xatolik — qayta urinib ko'ring.";
        }
        if ($msgId) {
            Telegram::call('deleteMessage', ['chat_id' => $tg, 'message_id' => $msgId]);
        }
        Engine::refreshNow($id, $tg);
        return "⚡ Buyurtma navbat boshiga o'tkazildi!";
    }

    /** Qaytariladigan tezkor to'lov (bir marta: o'chirish muvaffaqiyatli bo'lgandagina qaytadi). */
    public static function takeFee(int $jobId): int
    {
        $g = Engine::kvGet('xp:' . $jobId);
        if (!$g) {
            return 0;
        }
        $d = db()->prepare('DELETE FROM engine_kv WHERE k = ?');
        $d->execute(['xp:' . $jobId]);
        return $d->rowCount() ? (int)$g['v'] : 0;
    }
}
