<?php

require_once __DIR__ . '/TopupHelpers.php';
require_once __DIR__ . '/PaymePayment.php'; // PaymeException klassi shu yerda e'lon qilingan

/**
 * Payme orqali BALANS TO'LDIRISH. PaymePayment.php'ga o'xshash, lekin "topups"
 * jadvali bilan ishlaydi va account maydonida "topup_id" (order_id emas) kalitidan
 * foydalanadi — shu bilan payme.php buyurtma to'lovi bilan balans to'ldirishni
 * bir-biridan aniq ajrata oladi.
 */
class PaymeTopupPayment
{
    private const ERR_INVALID_AMOUNT = -31001;
    private const ERR_ORDER_NOT_FOUND = -31050;
    private const ERR_TRANSACTION_NOT_FOUND = -31003;
    private const ERR_UNABLE_TO_PERFORM = -31008;

    public static function generatePayLink(int $topupId, int $amountSom): string
    {
        $amountTiyin = $amountSom * 100;
        $params = base64_encode("m=" . PAYME_MERCHANT_ID . ";ac.topup_id=$topupId;a=$amountTiyin");
        return PAYME_CHECKOUT_URL . '/' . $params;
    }

    public static function handleRequest(array $body): array
    {
        $method = $body['method'] ?? '';
        $params = $body['params'] ?? [];
        $id = $body['id'] ?? null;

        try {
            $result = match ($method) {
                'CheckPerformTransaction' => self::checkPerform($params),
                'CreateTransaction' => self::create($params),
                'PerformTransaction' => self::perform($params),
                'CancelTransaction' => self::cancel($params),
                'CheckTransaction' => self::check($params),
                'GetStatement' => self::statement($params),
                default => throw new PaymeException(-32601, 'Method not found'),
            };
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        } catch (PaymeException $e) {
            $error = ['code' => $e->getCode(), 'message' => $e->paymeMessage()];
            if ($e->getField()) {
                $error['data'] = $e->getField();
            }
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
        }
    }

    private static function findTopup(array $account): array
    {
        $topupId = (int)($account['topup_id'] ?? 0);
        if (!$topupId) {
            throw new PaymeException(self::ERR_ORDER_NOT_FOUND, "To'ldirish so'rovi topilmadi", 'topup_id');
        }
        $stmt = db()->prepare("SELECT * FROM topups WHERE id = :id AND method = 'payme'");
        $stmt->execute(['id' => $topupId]);
        $topup = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$topup) {
            throw new PaymeException(self::ERR_ORDER_NOT_FOUND, "To'ldirish so'rovi topilmadi", 'topup_id');
        }
        return $topup;
    }

    private static function findByTransId(string $paymeId): array
    {
        $stmt = db()->prepare("SELECT * FROM topups WHERE external_trans_id = :id AND method = 'payme'");
        $stmt->execute(['id' => $paymeId]);
        $topup = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$topup) {
            throw new PaymeException(self::ERR_TRANSACTION_NOT_FOUND, "Tranzaksiya topilmadi");
        }
        return $topup;
    }

    private static function assertAmount(array $topup, int $amountTiyin): void
    {
        if ((int)$topup['amount'] * 100 !== (int)$amountTiyin) {
            throw new PaymeException(self::ERR_INVALID_AMOUNT, "Summa mos kelmadi", 'amount');
        }
    }

    private static function checkPerform(array $params): array
    {
        $topup = self::findTopup($params['account'] ?? []);
        self::assertAmount($topup, $params['amount'] ?? 0);
        if ($topup['status'] !== 'pending') {
            throw new PaymeException(self::ERR_UNABLE_TO_PERFORM, "Allaqachon ishlangan");
        }
        return ['allow' => true];
    }

    private static function create(array $params): array
    {
        $paymeId = $params['id'] ?? '';
        $topup = self::findTopup($params['account'] ?? []);

        if ($topup['external_trans_id'] === $paymeId) {
            return [
                'create_time' => (int)$topup['create_time_ms'],
                'transaction' => (string)$topup['id'],
                'state' => (int)$topup['payme_state'],
            ];
        }
        if ($topup['external_trans_id'] && $topup['external_trans_id'] !== $paymeId) {
            throw new PaymeException(-31051, "Boshqa tranzaksiya allaqachon mavjud");
        }
        self::assertAmount($topup, $params['amount'] ?? 0);
        if ($topup['status'] !== 'pending') {
            throw new PaymeException(self::ERR_UNABLE_TO_PERFORM, "Allaqachon ishlangan");
        }

        $createTimeMs = (int)($params['time'] ?? round(microtime(true) * 1000));
        $stmt = db()->prepare(
            'UPDATE topups SET external_trans_id = :ext, payme_state = 1, create_time_ms = :ct, updated_at = :now WHERE id = :id'
        );
        $stmt->execute(['ext' => $paymeId, 'ct' => $createTimeMs, 'now' => nowTs(), 'id' => $topup['id']]);

        return ['create_time' => $createTimeMs, 'transaction' => (string)$topup['id'], 'state' => 1];
    }

    private static function perform(array $params): array
    {
        $topup = self::findByTransId($params['id'] ?? '');

        if ((int)$topup['payme_state'] === 2) {
            return ['transaction' => (string)$topup['id'], 'perform_time' => (int)$topup['perform_time_ms'], 'state' => 2];
        }
        if ((int)$topup['payme_state'] !== 1) {
            throw new PaymeException(self::ERR_UNABLE_TO_PERFORM, "Amalga oshirib bo'lmaydi");
        }

        // Payme tranzaksiyasi 12 soatdan keyin eskiradi — shunda to'lovni bajarmaymiz, bekor qilamiz
        $nowMs = (int)round(microtime(true) * 1000);
        if ($nowMs - (int)$topup['create_time_ms'] > 12 * 3600 * 1000) {
            db()->prepare(
                "UPDATE topups SET payme_state = -1, cancel_time_ms = :ct, cancel_reason = 4,
                 status = CASE WHEN status = 'pending' THEN 'rejected' ELSE status END WHERE id = :id AND payme_state = 1"
            )->execute(['ct' => $nowMs, 'id' => $topup['id']]);
            throw new PaymeException(self::ERR_UNABLE_TO_PERFORM, "Tranzaksiya muddati o'tgan");
        }

        // AVVAL balansni to'ldiramiz (markTopupPaid takror chaqirilsa ham xavfsiz), KEYIN Payme holatini "bajarildi" qilamiz.
        // Avvalgi tartibda (holat → balans) balans yozilmay qolsa, Payme pulni yechgan, bizda esa kredit yo'q bo'lib qolardi;
        // endi bunday holatda Payme PerformTransaction ni qayta yuboradi va to'lov baribir tugallanadi.
        markTopupPaid((int)$topup['id']);

        $chk = db()->prepare('SELECT status FROM topups WHERE id = :id');
        $chk->execute(['id' => $topup['id']]);
        if ($chk->fetchColumn() !== 'approved') {
            logError('payme', "PerformTransaction: topup #{$topup['id']} balansga o'tmadi");
            throw new PaymeException(-32400, 'Ichki xatolik: balans yangilanmadi, qayta urinib ko\'ring');
        }

        $performTimeMs = (int)round(microtime(true) * 1000);
        db()->prepare('UPDATE topups SET payme_state = 2, perform_time_ms = :pt WHERE id = :id AND payme_state = 1')
            ->execute(['pt' => $performTimeMs, 'id' => $topup['id']]);

        return ['transaction' => (string)$topup['id'], 'perform_time' => $performTimeMs, 'state' => 2];
    }

    private static function cancel(array $params): array
    {
        $topup = self::findByTransId($params['id'] ?? '');
        $reason = (int)($params['reason'] ?? 0);
        $state = (int)$topup['payme_state'];

        if ($state === -1 || $state === -2) {
            return ['transaction' => (string)$topup['id'], 'cancel_time' => (int)$topup['cancel_time_ms'], 'state' => $state];
        }

        $cancelTimeMs = (int)round(microtime(true) * 1000);

        if ($state === 2) {
            // Pul allaqachon balansga o'tgan. Payme qaytarmoqchi bo'lsa, balansdan shu summani ayirishimiz kerak —
            // avval bu qilinmasdi: foydalanuvchiga pul Payme tomonidan qaytarilardi, balans esa o'z joyida qolardi
            // (bepul pul). Foydalanuvchi pulni allaqachon sarflab bo'lgan bo'lsa — protokol bo'yicha -31007 qaytariladi.
            $pdo = db();
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $amount = (int)$topup['amount'];
                $deduct = $pdo->prepare('UPDATE users SET balance = balance - :a WHERE telegram_id = :tid AND balance >= :a');
                $deduct->execute(['a' => $amount, 'tid' => (int)$topup['telegram_id']]);
                if ($deduct->rowCount() === 0) {
                    $pdo->exec('ROLLBACK');
                    throw new PaymeException(-31007, "Mablag' allaqachon ishlatilgan, bekor qilib bo'lmaydi");
                }
                $pdo->prepare("UPDATE topups SET payme_state = -2, cancel_time_ms = :ct, cancel_reason = :r, status = 'refunded', updated_at = :now WHERE id = :id")
                    ->execute(['ct' => $cancelTimeMs, 'r' => $reason, 'now' => nowTs(), 'id' => $topup['id']]);
                $pdo->exec('COMMIT');
            } catch (PaymeException $e) {
                throw $e;
            } catch (Throwable $e) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (Throwable $e2) {
                }
                logError('payme', 'Bekor qilishda xato: ' . $e->getMessage());
                throw new PaymeException(-32400, 'Ichki xatolik');
            }
            try {
                Telegram::sendMessage((int)$topup['telegram_id'], "↩️ To'lov #{$topup['id']} Payme tomonidan bekor qilindi, balansingizdan " . number_format($amount, 0, '', ' ') . " so'm ayirildi.");
                Telegram::notifyAdmin("Payme to'lovi bekor qilindi (topup #{$topup['id']}, user {$topup['telegram_id']}, " . number_format($amount, 0, '', ' ') . " so'm) — balansdan ayirildi.");
            } catch (Throwable $e) {
            }
            return ['transaction' => (string)$topup['id'], 'cancel_time' => $cancelTimeMs, 'state' => -2];
        }

        $stmt = db()->prepare(
            "UPDATE topups SET payme_state = -1, cancel_time_ms = :ct, cancel_reason = :r,
             status = CASE WHEN status = 'pending' THEN 'rejected' ELSE status END WHERE id = :id"
        );
        $stmt->execute(['ct' => $cancelTimeMs, 'r' => $reason, 'id' => $topup['id']]);

        return ['transaction' => (string)$topup['id'], 'cancel_time' => $cancelTimeMs, 'state' => -1];
    }

    private static function check(array $params): array
    {
        $topup = self::findByTransId($params['id'] ?? '');
        return [
            'create_time' => (int)$topup['create_time_ms'],
            'perform_time' => (int)$topup['perform_time_ms'],
            'cancel_time' => (int)$topup['cancel_time_ms'],
            'transaction' => (string)$topup['id'],
            'state' => (int)$topup['payme_state'],
            'reason' => $topup['cancel_reason'] !== null ? (int)$topup['cancel_reason'] : null,
        ];
    }

    private static function statement(array $params): array
    {
        // Payme "from"/"to" ni MILLISEKUNDda yuboradi va tranzaksiya YARATILGAN vaqti bo'yicha so'raydi.
        // Avval soniyaga o'girilib, topup'ning created_at ustuni bilan solishtirilardi (hech qachon mos kelmasdi) va
        // Payme tranzaksiyasi hali yaratilmagan yozuvlar ham (id = null) qaytarilardi.
        $from = (int)($params['from'] ?? 0);
        $to = (int)($params['to'] ?? 0);
        $stmt = db()->prepare("SELECT * FROM topups WHERE method = 'payme' AND external_trans_id IS NOT NULL AND create_time_ms BETWEEN :f AND :t ORDER BY create_time_ms ASC");
        $stmt->execute(['f' => $from, 't' => $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $transactions = array_map(fn($t) => [
            'id' => $t['external_trans_id'],
            'time' => (int)$t['create_time_ms'],
            'amount' => (int)$t['amount'] * 100,
            'account' => ['topup_id' => (string)$t['id']],
            'create_time' => (int)$t['create_time_ms'],
            'perform_time' => (int)$t['perform_time_ms'],
            'cancel_time' => (int)$t['cancel_time_ms'],
            'transaction' => (string)$t['id'],
            'state' => (int)$t['payme_state'],
            'reason' => $t['cancel_reason'] !== null ? (int)$t['cancel_reason'] : null,
        ], $rows);

        return ['transactions' => $transactions];
    }
}
