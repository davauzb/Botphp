<?php

require_once __DIR__ . '/Telegram.php';

/**
 * Payme Subscribe API (JSON-RPC 2.0). Hujjat: https://developer.help.paycom.uz
 * Payme summani TIYINDA yuboradi (1 so'm = 100 tiyin) — shuning uchun
 * hamma joyda *100 / /100 qilinadi.
 */
class PaymePayment
{
    private const ERR_INVALID_AMOUNT = -31001;
    private const ERR_ORDER_NOT_FOUND = -31050;
    private const ERR_TRANSACTION_NOT_FOUND = -31003;
    private const ERR_UNABLE_TO_PERFORM = -31008;
    private const ERR_UNABLE_TO_CANCEL = -31007;
    private const ERR_ALREADY_HAS_TRANSACTION = -31051;

    public static function checkAuth(): bool
    {
        // Kalit hali to'ldirilmagan (namuna) bo'lsa — hamma so'rovni rad etamiz, aks holda namuna kalitni bilgan har kim
        // soxta to'lov tasdig'ini yubora olardi.
        if (PAYME_KEY === '' || str_contains(PAYME_KEY, '_HERE')) {
            return false;
        }
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp((string)$k, 'Authorization') === 0) {
                    $header = (string)$v;
                    break;
                }
            }
        }
        $expected = 'Basic ' . base64_encode('Paycom:' . PAYME_KEY);
        return hash_equals($expected, $header);
    }

    public static function generatePayLink(int $paymentId, int $amountSom): string
    {
        $amountTiyin = $amountSom * 100;
        $params = base64_encode(
            "m=" . PAYME_MERCHANT_ID . ";ac.order_id=$paymentId;a=$amountTiyin"
        );
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

    private static function findOrder(array $account): array
    {
        $orderId = (int)($account['order_id'] ?? 0);
        if (!$orderId) {
            throw new PaymeException(self::ERR_ORDER_NOT_FOUND, "Buyurtma topilmadi", 'order_id');
        }
        $stmt = db()->prepare("SELECT * FROM payments WHERE id = :id AND provider = 'payme'");
        $stmt->execute(['id' => $orderId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            throw new PaymeException(self::ERR_ORDER_NOT_FOUND, "Buyurtma topilmadi", 'order_id');
        }
        return $payment;
    }

    private static function findByTransId(string $paymeId): array
    {
        $stmt = db()->prepare("SELECT * FROM payments WHERE external_trans_id = :id AND provider = 'payme'");
        $stmt->execute(['id' => $paymeId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            throw new PaymeException(self::ERR_TRANSACTION_NOT_FOUND, "Tranzaksiya topilmadi");
        }
        return $payment;
    }

    private static function checkPerform(array $params): array
    {
        $payment = self::findOrder($params['account'] ?? []);
        self::assertAmount($payment, $params['amount'] ?? 0);
        if ($payment['status'] === 'cancelled') {
            throw new PaymeException(self::ERR_UNABLE_TO_PERFORM, "Buyurtma bekor qilingan");
        }
        return ['allow' => true];
    }

    private static function assertAmount(array $payment, int $amountTiyin): void
    {
        if ((int)$payment['amount'] * 100 !== (int)$amountTiyin) {
            throw new PaymeException(self::ERR_INVALID_AMOUNT, "Summa mos kelmadi", 'amount');
        }
    }

    private static function create(array $params): array
    {
        $paymeId = $params['id'] ?? '';
        $payment = self::findOrder($params['account'] ?? []);

        // Idempotentlik: bu tranzaksiya oldin yaratilgan bo'lsa, xuddi shu javobni qaytaramiz
        if ($payment['external_trans_id'] === $paymeId) {
            return [
                'create_time' => (int)$payment['create_time_ms'],
                'transaction' => (string)$payment['id'],
                'state' => (int)$payment['payme_state'],
            ];
        }

        if ($payment['external_trans_id'] && $payment['external_trans_id'] !== $paymeId) {
            throw new PaymeException(self::ERR_ALREADY_HAS_TRANSACTION, "Boshqa tranzaksiya allaqachon mavjud");
        }

        self::assertAmount($payment, $params['amount'] ?? 0);
        if ($payment['status'] === 'cancelled') {
            throw new PaymeException(self::ERR_UNABLE_TO_PERFORM, "Buyurtma bekor qilingan");
        }

        $createTimeMs = (int)($params['time'] ?? round(microtime(true) * 1000));
        $stmt = db()->prepare(
            'UPDATE payments SET external_trans_id = :ext, payme_state = 1, create_time_ms = :ct, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([
            'ext' => $paymeId, 'ct' => $createTimeMs, 'now' => nowTs(), 'id' => $payment['id'],
        ]);

        return ['create_time' => $createTimeMs, 'transaction' => (string)$payment['id'], 'state' => 1];
    }

    private static function perform(array $params): array
    {
        $payment = self::findByTransId($params['id'] ?? '');

        if ((int)$payment['payme_state'] === 2) {
            // idempotent — allaqachon amalga oshirilgan
            return [
                'transaction' => (string)$payment['id'],
                'perform_time' => (int)$payment['perform_time_ms'],
                'state' => 2,
            ];
        }

        if ((int)$payment['payme_state'] !== 1) {
            throw new PaymeException(self::ERR_UNABLE_TO_PERFORM, "Amalga oshirib bo'lmaydi");
        }

        $performTimeMs = (int)round(microtime(true) * 1000);
        $stmt = db()->prepare(
            'UPDATE payments SET payme_state = 2, perform_time_ms = :pt WHERE id = :id'
        );
        $stmt->execute(['pt' => $performTimeMs, 'id' => $payment['id']]);

        require_once __DIR__ . '/PaymentHelpers.php';
        markPaymentPaid((int)$payment['id']);

        if ($payment['status'] === 'cancelled') {
            // CreateTransaction bosqichida hali bekor qilinmagan edi, lekin shu orada
            // foydalanuvchi xuddi shu buyurtmani Click orqali ham to'lab ulgurgan —
            // Payme'da pul allaqachon yechilgani uchun tranzaksiyani baribir "performed"
            // deb tasdiqlaymiz (Payme protokoli buni talab qiladi), lekin adminni
            // DUPLIKAT TO'LOV haqida ogohlantiramiz.
            Telegram::notifyAdmin(
                "DIQQAT: to'lov #{$payment['id']} (Payme) buyurtma #{$payment['job_id']} uchun "
                . "allaqachon boshqa usulda to'langandan KEYIN tasdiqlandi — ehtimol foydalanuvchi "
                . "ikki marta to'lov qilgan. Ortiqcha summani qaytarishni tekshiring."
            );
        }

        return ['transaction' => (string)$payment['id'], 'perform_time' => $performTimeMs, 'state' => 2];
    }

    private static function cancel(array $params): array
    {
        $payment = self::findByTransId($params['id'] ?? '');
        $reason = (int)($params['reason'] ?? 0);
        $state = (int)$payment['payme_state'];

        if ($state === -1 || $state === -2) {
            // idempotent — allaqachon bekor qilingan
            return [
                'transaction' => (string)$payment['id'],
                'cancel_time' => (int)$payment['cancel_time_ms'],
                'state' => $state,
            ];
        }

        $newState = ($state === 2) ? -2 : -1; // amalga oshgandan keyin bekor qilinsa -2, oldin bo'lsa -1
        $cancelTimeMs = (int)round(microtime(true) * 1000);

        $stmt = db()->prepare(
            'UPDATE payments SET payme_state = :s, cancel_time_ms = :ct, cancel_reason = :r WHERE id = :id'
        );
        $stmt->execute(['s' => $newState, 'ct' => $cancelTimeMs, 'r' => $reason, 'id' => $payment['id']]);

        require_once __DIR__ . '/PaymentHelpers.php';
        markPaymentCancelled((int)$payment['id']); // agar to'langan bo'lsa, kreditni qaytarib oladi

        return ['transaction' => (string)$payment['id'], 'cancel_time' => $cancelTimeMs, 'state' => $newState];
    }

    private static function check(array $params): array
    {
        $payment = self::findByTransId($params['id'] ?? '');
        return [
            'create_time' => (int)$payment['create_time_ms'],
            'perform_time' => (int)$payment['perform_time_ms'],
            'cancel_time' => (int)$payment['cancel_time_ms'],
            'transaction' => (string)$payment['id'],
            'state' => (int)$payment['payme_state'],
            'reason' => $payment['cancel_reason'] !== null ? (int)$payment['cancel_reason'] : null,
        ];
    }

    private static function statement(array $params): array
    {
        // Payme "from"/"to" — MILLISEKUND va tranzaksiya yaratilgan vaqti (create_time_ms) bo'yicha
        $from = (int)($params['from'] ?? 0);
        $to = (int)($params['to'] ?? 0);

        $stmt = db()->prepare(
            "SELECT * FROM payments WHERE provider = 'payme' AND external_trans_id IS NOT NULL AND create_time_ms BETWEEN :f AND :t ORDER BY create_time_ms ASC"
        );
        $stmt->execute(['f' => $from, 't' => $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $transactions = array_map(fn($p) => [
            'id' => $p['external_trans_id'],
            'time' => (int)$p['create_time_ms'],
            'amount' => (int)$p['amount'] * 100,
            'account' => ['order_id' => (string)$p['id']],
            'create_time' => (int)$p['create_time_ms'],
            'perform_time' => (int)$p['perform_time_ms'],
            'cancel_time' => (int)$p['cancel_time_ms'],
            'transaction' => (string)$p['id'],
            'state' => (int)$p['payme_state'],
            'reason' => $p['cancel_reason'] !== null ? (int)$p['cancel_reason'] : null,
        ], $rows);

        return ['transactions' => $transactions];
    }
}

class PaymeException extends Exception
{
    private ?string $field;

    public function __construct(int $code, string $messageUz, ?string $field = null)
    {
        parent::__construct($messageUz, $code);
        $this->field = $field;
    }

    public function paymeMessage(): array
    {
        return ['uz' => $this->getMessage(), 'ru' => $this->getMessage(), 'en' => $this->getMessage()];
    }

    public function getField(): ?string
    {
        return $this->field;
    }
}
