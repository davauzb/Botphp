<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/ClickPayment.php';
require_once __DIR__ . '/lib/ClickTopupPayment.php';
require_once __DIR__ . '/lib/PaymentHelpers.php';

header('Content-Type: application/json');

try {
    $params = $_POST;
    if (empty($params)) {
        // ba'zi holatlarda raw body sifatida keladi
        parse_str(file_get_contents('php://input'), $params);
    }

    // merchant_trans_id prefiksi buyurtma to'lovi ("o...") bilan balans
    // to'ldirishni ("t...") bir-biridan ajratadi.
    $transId = (string)($params['merchant_trans_id'] ?? '');
    if (str_starts_with($transId, 't')) {
        $result = ClickTopupPayment::handleRequest($params);
    } else {
        $result = ClickPayment::handleRequest($params);
    }
    echo json_encode($result);
} catch (Throwable $e) {
    logError('click', $e->getMessage());
    echo json_encode(['error' => -8, 'error_note' => 'Internal error']);
}
