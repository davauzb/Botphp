<?php

/**
 * ⭐ TELEGRAM STARS orqali balansni to'ldirish (karta shart emas, Telegram ichida to'lanadi).
 *
 * Oqim: usul tanlanadi → topups (method='stars') yaratiladi → sendInvoice (valyuta XTR) → Telegram pre_checkout_query yuboradi
 * → tekshirib tasdiqlaymiz → successful_payment keladi → summa va to'lov ID tekshirilib markTopupPaid() (bonus/promokod/buyurtmaga qaytish — odatdagidek).
 * Bir to'lov ikki marta hisoblanmaydi (telegram_payment_charge_id saqlanadi, topup faqat 'pending' holatda tasdiqlanadi).
 *
 * Sozlash (config.php, ixtiyoriy): STARS_ENABLED (true), STARS_RATE — 1 yulduz necha so'mga teng (standart 200).
 * Yig'ilgan yulduzlarni Telegram'da @BotFather → bot → Balance orqali yechib olasiz.
 */
class StarsPay
{
    public static function enabled(): bool
    {
        return (bool)Engine::c('STARS_ENABLED', true);
    }

    public static function rate(): int
    {
        return max(50, (int)Engine::c('STARS_RATE', 200));
    }

    public static function starsFor(int $som): int
    {
        return max(1, (int)ceil($som / self::rate()));
    }

    /** Hisob-fakturani yuboradi. */
    public static function start(int $chatId, int $amount, ?string $resumeType, ?string $resumeVk): void
    {
        self::ensureWebhook();
        $stars = self::starsFor($amount);
        $topupId = createPendingTopup($chatId, $amount, 'stars', $resumeType, $resumeVk);
        db()->prepare('UPDATE topups SET admin_note = ? WHERE id = ?')->execute(['stars:' . $stars, $topupId]);
        $r = Telegram::call('sendInvoice', [
            'chat_id' => $chatId,
            'title' => 'Balansni to\'ldirish',
            'description' => number_format($amount, 0, '', ' ') . " so'm — StudyBot balansiga (" . $stars . ' ⭐)',
            'payload' => 'stars:' . $topupId,
            'provider_token' => '',
            'currency' => 'XTR',
            'prices' => json_encode([['label' => number_format($amount, 0, '', ' ') . " so'm", 'amount' => $stars]]),
        ]);
        if (empty($r['ok'])) {
            db()->prepare("UPDATE topups SET status = 'rejected', admin_note = 'invoice_failed' WHERE id = ?")->execute([$topupId]);
            Telegram::sendMessage($chatId, "⚠️ Telegram Stars hisobini yaratib bo'lmadi. Boshqa to'lov usulini tanlang.");
        }
    }

    private static function topupFromPayload(string $payload): ?array
    {
        if (!preg_match('/^stars:(\d+)$/', $payload, $m)) {
            return null;
        }
        $st = db()->prepare("SELECT * FROM topups WHERE id = ? AND method = 'stars'");
        $st->execute([(int)$m[1]]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Webhook update'ini qayta ishlaydi. Qaytadi: true — bu Stars update'i edi. */
    public static function handleUpdate(array $u): bool
    {
        if (isset($u['pre_checkout_query'])) {
            $q = $u['pre_checkout_query'];
            $t = self::topupFromPayload((string)($q['invoice_payload'] ?? ''));
            $ok = $t && $t['status'] === 'pending' && ($q['currency'] ?? '') === 'XTR'
                && (int)$t['telegram_id'] === (int)($q['from']['id'] ?? 0)
                && ('stars:' . (int)($q['total_amount'] ?? 0)) === (string)$t['admin_note'];
            Telegram::call('answerPreCheckoutQuery', ['pre_checkout_query_id' => (string)$q['id'], 'ok' => $ok ? 'true' : 'false']
                + ($ok ? [] : ['error_message' => "Bu to'lov eskirgan. /balans orqali qaytadan boshlang."]));
            return true;
        }
        $sp = $u['message']['successful_payment'] ?? null;
        if (!$sp) {
            return false;
        }
        $t = self::topupFromPayload((string)($sp['invoice_payload'] ?? ''));
        $charge = (string)($sp['telegram_payment_charge_id'] ?? '');
        if (!$t || ($sp['currency'] ?? '') !== 'XTR' || ('stars:' . (int)($sp['total_amount'] ?? 0)) !== (string)$t['admin_note'] || $charge === '') {
            logError('stars', 'Mos kelmagan to\'lov: ' . json_encode($sp));
            if (class_exists('AdminSecurity')) {
                AdminSecurity::notifyAdmins('⚠️ Telegram Stars: mos kelmagan to\'lov keldi (payload ' . htmlspecialchars((string)($sp['invoice_payload'] ?? '')) . '). Jurnalni tekshiring.');
            }
            return true;
        }
        $dup = db()->prepare("SELECT COUNT(*) FROM topups WHERE method = 'stars' AND external_trans_id = ?");
        $dup->execute([$charge]);
        if ((int)$dup->fetchColumn() > 0) {
            return true;
        }
        db()->prepare('UPDATE topups SET external_trans_id = ? WHERE id = ? AND external_trans_id IS NULL')->execute([$charge, (int)$t['id']]);
        markTopupPaid((int)$t['id']);
        return true;
    }

    /**
     * Webhook allowed_updates ro'yxatida pre_checkout_query yo'q bo'lsa (HUMO uchun ro'yxat berib o'rnatilgan bo'lsa shunday bo'ladi) —
     * mavjud manzil va maxfiy kalit bilan o'zi qo'shib qo'yadi. Kuniga ko'pi bilan 1 marta tekshiradi.
     */
    public static function ensureWebhook(): void
    {
        try {
            $st = db()->prepare('SELECT ts FROM engine_kv WHERE k = ?');
            $st->execute(['stars_wh']);
            if (time() - (int)$st->fetchColumn() < 86400) {
                return;
            }
            db()->prepare('INSERT INTO engine_kv (k, v, ts) VALUES (?, ?, ?) ON CONFLICT(k) DO UPDATE SET ts = excluded.ts')->execute(['stars_wh', '1', time()]);
            $info = Telegram::call('getWebhookInfo');
            $url = (string)($info['result']['url'] ?? '');
            $allowed = $info['result']['allowed_updates'] ?? null;
            if ($url === '' || !is_array($allowed) || !$allowed || in_array('pre_checkout_query', $allowed, true)) {
                return;                    // ro'yxat berilmagan (= hammasi keladi) yoki allaqachon bor
            }
            $allowed[] = 'pre_checkout_query';
            if (defined('HUMO_ENABLED') && HUMO_ENABLED) {          // HUMO avto uchun business xabarlar ham qolsin
                array_push($allowed, 'business_connection', 'business_message', 'edited_business_message', 'deleted_business_messages');
            }
            $params = ['url' => $url, 'allowed_updates' => json_encode(array_values(array_unique($allowed)))];
            if (defined('WEBHOOK_SECRET') && WEBHOOK_SECRET !== '') {
                $params['secret_token'] = WEBHOOK_SECRET;
            }
            Telegram::call('setWebhook', $params);
        } catch (Throwable $e) {
            logError('stars', 'webhook: ' . $e->getMessage());
        }
    }
}
