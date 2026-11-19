<?php

require_once __DIR__ . '/Telegram.php';

/**
 * Click Merchant API. Rasmiy oqim: Click avval "Prepare" (action=0),
 * pul yechilgach "Complete" (action=1) so'rovini yuboradi.
 * Hujjat: https://docs.click.uz
 */
class ClickPayment
{
    // Xato kodlari (Click spetsifikatsiyasiga mos)
    const ERR_SUCCESS = 0;
    const ERR_SIGN_FAILED = -1;
    const ERR_AMOUNT = -2;
    const ERR_ACTION_NOT_FOUND = -3;
    const ERR_ALREADY_PAID = -4;
    const ERR_ORDER_NOT_FOUND = -5;
    const ERR_TRANSACTION_NOT_FOUND = -6;
    const ERR_FAILED_UPDATE_USER = -7;
    const ERR_BAD_REQUEST = -8;
    const ERR_TRANSACTION_CANCELLED = -9;

    public static function generatePayLink(int $paymentId, int $amount): string
    {
        $params = http_build_query([
            'service_id' => CLICK_SERVICE_ID,
            'merchant_id' => CLICK_MERCHANT_ID,
            'amount' => $amount,
            // "o" prefiksi — bu BUYURTMA to'lovi ekanini bildiradi, balans to'ldirish
            // (ClickTopupPayment, "t" prefiksi) bilan ID to'qnashmasligi uchun kerak,
            // chunki ikkala jadval ham 1 dan boshlab avtoinkrement qiladi.
            'transaction_param' => 'o' . $paymentId,
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

        $expected = md5($base);
        return hash_equals($expected, (string)($p['sign_string'] ?? ''));
    }

    private static function prepare(array $p): array
    {
        $paymentId = self::extractId($p['merchant_trans_id'] ?? '');
        $payment = self::findPayment($paymentId);

        if (!$payment) {
            return self::response($p, ['error' => self::ERR_ORDER_NOT_FOUND, 'error_note' => "Buyurtma topilmadi"]);
        }
        if ($payment['status'] === 'paid') {
            return self::response($p, ['error' => self::ERR_ALREADY_PAID, 'error_note' => "Allaqachon to'langan"]);
        }
        if ($payment['status'] === 'cancelled') {
            // Buyurtma boshqa to'lov usuli (Payme) orqali allaqachon to'langan bo'lishi mumkin —
            // haqiqiy pul yechilishidan OLDIN rad etamiz, shu bilan ikki marta to'lov qilinishining
            // oldini olamiz.
            return self::response($p, ['error' => self::ERR_TRANSACTION_CANCELLED, 'error_note' => "Bu buyurtma uchun to'lov allaqachon boshqa usulda amalga oshirilgan yoki bekor qilingan"]);
        }
        if (abs((float)$p['amount'] - (float)$payment['amount']) > 0.01) {
            return self::response($p, ['error' => self::ERR_AMOUNT, 'error_note' => "Summa mos kelmadi"]);
        }

        $stmt = db()->prepare(
            'UPDATE payments SET external_trans_id = :ext, click_prepare_id = :pid, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([
            'ext' => $p['click_trans_id'], 'pid' => $paymentId, 'now' => nowTs(), 'id' => $paymentId,
        ]);

        return self::response($p, [
            'error' => self::ERR_SUCCESS,
            'error_note' => 'Success',
            'merchant_prepare_id' => $paymentId,
        ]);
    }

    private static function complete(array $p): array
    {
        $paymentId = self::extractId($p['merchant_trans_id'] ?? '');
        $payment = self::findPayment($paymentId);

        if (!$payment) {
            return self::response($p, ['error' => self::ERR_ORDER_NOT_FOUND, 'error_note' => "Buyurtma topilmadi"]);
        }

        // Click o'z tomonidan xatolik/bekor qilishni bildirsa
        if ((int)($p['error'] ?? 0) < 0) {
            markPaymentCancelled($paymentId);
            return self::response($p, ['error' => self::ERR_SUCCESS, 'error_note' => 'Cancelled accepted']);
        }

        if ($payment['status'] === 'paid') {
            return self::response($p, [
                'error' => self::ERR_ALREADY_PAID, 'error_note' => "Allaqachon to'langan",
                'merchant_confirm_id' => $paymentId,
            ]);
        }

        if ($payment['status'] === 'cancelled') {
            // Bu yerga kelib qolish kamdan-kam holat: Prepare muvaffaqiyatli o'tgan, lekin shu
            // orada foydalanuvchi xuddi shu buyurtmani Payme orqali ham to'lab ulgurgan.
            // Pul Click orqali allaqachon yechilgan bo'lishi mumkinligi uchun to'lovni "paid"
            // deb belgilaymiz (buyurtma holati o'zgarmaydi, chunki allaqachon pending/tayyor),
            // lekin adminni DUPLIKAT TO'LOV haqida ogohlantiramiz — u qo'lda ortiqcha summani
            // qaytarishi kerak bo'ladi.
            markPaymentPaid($paymentId);
            Telegram::notifyAdmin(
                "DIQQAT: to'lov #$paymentId (Click) buyurtma #{$payment['job_id']} uchun "
                . "allaqachon boshqa usulda to'langandan KEYIN tasdiqlandi — ehtimol foydalanuvchi "
                . "ikki marta to'lov qilgan. Ortiqcha summani qaytarishni tekshiring."
            );
            return self::response($p, [
                'error' => self::ERR_SUCCESS, 'error_note' => 'Success',
                'merchant_confirm_id' => $paymentId,
            ]);
        }

        markPaymentPaid($paymentId);

        return self::response($p, [
            'error' => self::ERR_SUCCESS,
            'error_note' => 'Success',
            'merchant_confirm_id' => $paymentId,
        ]);
    }

    private static function findPayment(int $id): ?array
    {
        $stmt = db()->prepare("SELECT * FROM payments WHERE id = :id AND provider = 'click'");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * "o123" kabi prefiksli merchant_trans_id'dan raqamli ID'ni ajratib oladi.
     * Prefiks bo'lmasa ham (eski so'rovlar bilan orqaga moslik uchun) ishlaydi.
     */
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
