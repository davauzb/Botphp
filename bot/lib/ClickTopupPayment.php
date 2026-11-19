<?php

require_once __DIR__ . '/TopupHelpers.php';

/**
 * Click orqali BALANS TO'LDIRISH (buyurtma to'lovi emas). Mantiq ClickPayment.php'ga
 * juda o'xshash, lekin "topups" jadvali bilan ishlaydi va "t" prefiksidan foydalanadi —
 * shu bilan buyurtma to'lovlari (ClickPayment, "o" prefiksi) bilan ID to'qnashmaydi.
 */
class ClickTopupPayment
{
    const ERR_SUCCESS = 0;
    const ERR_SIGN_FAILED = -1;
    const ERR_AMOUNT = -2;
    const ERR_ACTION_NOT_FOUND = -3;
    const ERR_ALREADY_PAID = -4;
    const ERR_ORDER_NOT_FOUND = -5;

    public static function generatePayLink(int $topupId, int $amount): string
    {
        $params = http_build_query([
            'service_id' => CLICK_SERVICE_ID,
            'merchant_id' => CLICK_MERCHANT_ID,
            'amount' => $amount,
            'transaction_param' => 't' . $topupId,
        ]);
        return CLICK_PAY_URL . '?' . $params;
    }

    public static function handleRequest(array $p): array
    {
        $action = (int)($p['action'] ?? -1);

        if (!self::verifySign($p, $action)) {
            return self::response($p, ['error' => self::ERR_SIGN_FAILED, 'error_note' => 'SIGN CHECK FAILED']);
        }
        if ($action === 0) {
            return self::prepare($p);
        }
        if ($action === 1) {
            return self::complete($p);
        }
        return self::response($p, ['error' => self::ERR_ACTION_NOT_FOUND, 'error_note' => 'Action not found']);
    }

    private static function verifySign(array $p, int $action): bool
    {
        if (CLICK_SECRET_KEY === '' || str_contains(CLICK_SECRET_KEY, '_HERE')) {
            return false;   // namuna kalit bilan imzoni soxtalashtirish oson — rad etamiz
        }
        $base = ($p['click_trans_id'] ?? '') . (CLICK_SERVICE_ID) . CLICK_SECRET_KEY
            . ($p['merchant_trans_id'] ?? '');
        if ($action === 1) {
            $base .= ($p['merchant_prepare_id'] ?? '');
        }
        $base .= ($p['amount'] ?? '') . $action . ($p['sign_time'] ?? '');
        return hash_equals(md5($base), (string)($p['sign_string'] ?? ''));
    }

    private static function prepare(array $p): array
    {
        $topupId = self::extractId($p['merchant_trans_id'] ?? '');
        $topup = self::findTopup($topupId);

        if (!$topup) {
            return self::response($p, ['error' => self::ERR_ORDER_NOT_FOUND, 'error_note' => "To'ldirish so'rovi topilmadi"]);
        }
        if ($topup['status'] !== 'pending') {
            return self::response($p, ['error' => self::ERR_ALREADY_PAID, 'error_note' => "Allaqachon ishlangan"]);
        }
        if (abs((float)$p['amount'] - (float)$topup['amount']) > 0.01) {
            return self::response($p, ['error' => self::ERR_AMOUNT, 'error_note' => "Summa mos kelmadi"]);
        }

        $stmt = db()->prepare('UPDATE topups SET external_trans_id = :ext, updated_at = :now WHERE id = :id');
        $stmt->execute(['ext' => $p['click_trans_id'], 'now' => nowTs(), 'id' => $topupId]);

        return self::response($p, ['error' => self::ERR_SUCCESS, 'error_note' => 'Success', 'merchant_prepare_id' => $topupId]);
    }

    private static function complete(array $p): array
    {
        $topupId = self::extractId($p['merchant_trans_id'] ?? '');
        $topup = self::findTopup($topupId);

        if (!$topup) {
            return self::response($p, ['error' => self::ERR_ORDER_NOT_FOUND, 'error_note' => "To'ldirish so'rovi topilmadi"]);
        }
        if ((int)($p['error'] ?? 0) < 0) {
            // Click o'z tomonidan bekor qilsa — bizda ham "rejected" qilamiz
            $stmt = db()->prepare("UPDATE topups SET status = 'rejected', updated_at = :now WHERE id = :id AND status = 'pending'");
            $stmt->execute(['now' => nowTs(), 'id' => $topupId]);
            return self::response($p, ['error' => self::ERR_SUCCESS, 'error_note' => 'Cancelled accepted']);
        }
        if ($topup['status'] !== 'pending') {
            return self::response($p, ['error' => self::ERR_ALREADY_PAID, 'error_note' => "Allaqachon ishlangan", 'merchant_confirm_id' => $topupId]);
        }

        if (abs((float)($p['amount'] ?? 0) - (float)$topup['amount']) > 0.01) {
            return self::response($p, ['error' => self::ERR_AMOUNT, 'error_note' => "Summa mos kelmadi"]);
        }

        markTopupPaid($topupId);

        return self::response($p, ['error' => self::ERR_SUCCESS, 'error_note' => 'Success', 'merchant_confirm_id' => $topupId]);
    }

    private static function findTopup(int $id): ?array
    {
        $stmt = db()->prepare("SELECT * FROM topups WHERE id = :id AND method = 'click'");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function extractId(string $raw): int
    {
        preg_match('/\d+/', $raw, $m);
        return (int)($m[0] ?? 0);
    }

    private static function response(array $p, array $extra): array
    {
        return array_merge([
            'click_trans_id' => $p['click_trans_id'] ?? null,
            'merchant_trans_id' => $p['merchant_trans_id'] ?? null,
        ], $extra);
    }
}
