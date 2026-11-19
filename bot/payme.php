<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/PaymePayment.php';
require_once __DIR__ . '/lib/PaymeTopupPayment.php';

header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true) ?: [];

if (!PaymePayment::checkAuth()) {
    echo json_encode([
        'jsonrpc' => '2.0',
        'id' => $body['id'] ?? null,
        'error' => [
            'code' => -32504,
            'message' => ['uz' => "Ruxsat yo'q", 'ru' => 'Недостаточно прав', 'en' => 'Insufficient privilege'],
        ],
    ]);
    exit;
}

try {
    echo json_encode(routePaymeRequest($body));
} catch (Throwable $e) {
    logError('payme', $e->getMessage());
    echo json_encode([
        'jsonrpc' => '2.0',
        'id' => $body['id'] ?? null,
        'error' => ['code' => -32400, 'message' => ['uz' => 'Ichki xatolik', 'ru' => 'Внутренняя ошибка', 'en' => 'Internal error']],
    ]);
}

/**
 * Payme so'rovini BUYURTMA to'lovi (PaymePayment) yoki BALANS to'ldirish
 * (PaymeTopupPayment) sinfiga yo'naltiradi.
 *
 * MUHIM: faqat CheckPerformTransaction/CreateTransaction so'rovlarida "account"
 * maydoni bo'ladi (order_id/topup_id shu yerda ko'rinadi). PerformTransaction,
 * CancelTransaction va CheckTransaction esa FAQAT ichki Payme tranzaksiya ID'sini
 * ("id" maydoni) yuboradi, account'siz — chunki Payme buni CreateTransaction
 * bosqichida allaqachon bog'lab qo'ygan deb hisoblaydi. Shu sabab bu uch metod
 * uchun "id" qaysi jadvalda (payments yoki topups) ro'yxatdan o'tganini BAZADAN
 * qidirib topamiz — aks holda PerformTransaction (haqiqiy pul yechish tasdig'i)
 * doim noto'g'ri sinfga tushib, "tranzaksiya topilmadi" xatosi bilan
 * muvaffaqiyatsiz tugaydi (bu xato haqiqiy testda aniqlangan va shu funksiya
 * bilan tuzatilgan).
 */
function routePaymeRequest(array $body): array
{
    $method = $body['method'] ?? '';
    $params = $body['params'] ?? [];
    $account = $params['account'] ?? null;

    if ($account !== null) {
        if (isset($account['topup_id'])) {
            return PaymeTopupPayment::handleRequest($body);
        }
        return PaymePayment::handleRequest($body);
    }

    if ($method === 'GetStatement') {
        // Statement — vaqt oralig'i bo'yicha so'raladi, aniq bitta tranzaksiyaga bog'liq
        // emas. Ikkalasini ham qaytarish uchun natijalarni birlashtiramiz.
        $orders = PaymePayment::handleRequest($body);
        $topups = PaymeTopupPayment::handleRequest($body);
        $merged = array_merge(
            $orders['result']['transactions'] ?? [],
            $topups['result']['transactions'] ?? []
        );
        return ['jsonrpc' => '2.0', 'id' => $body['id'] ?? null, 'result' => ['transactions' => $merged]];
    }

    if (isset($params['id'])) {
        // PerformTransaction / CancelTransaction / CheckTransaction — qaysi jadvalga
        // tegishli ekanini Payme'ning o'z tranzaksiya ID'si bo'yicha aniqlaymiz.
        $stmt = db()->prepare("SELECT 1 FROM topups WHERE external_trans_id = :id LIMIT 1");
        $stmt->execute(['id' => $params['id']]);
        if ($stmt->fetchColumn()) {
            return PaymeTopupPayment::handleRequest($body);
        }
        return PaymePayment::handleRequest($body);
    }

    // Noma'lum metod — PaymePayment o'zi "Method not found" xatosini qaytaradi
    return PaymePayment::handleRequest($body);
}
