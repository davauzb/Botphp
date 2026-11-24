<?php
declare(strict_types=1);

/**
 * ISH MEXANIZMI KIRISH NUQTASI (cron kerak emas).
 *  - HTTP: botning o'zi POST bilan chaqiradi (maxfiy kalit bilan). Kalitsiz so'rovga 404 qaytadi.
 *  - CLI:  php tick.php [kechikish_soniya]   (xohlasangiz cron yoki qo'lda ham ishlatish mumkin)
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/Telegram.php';
require_once __DIR__ . '/lib/AIClient.php';
require_once __DIR__ . '/lib/DocxGenerator.php';
require_once __DIR__ . '/lib/PptxGenerator.php';
require_once __DIR__ . '/lib/EditorStore.php';
require_once __DIR__ . '/lib/ImageSearch.php';
require_once __DIR__ . '/lib/JobSteps.php';

ini_set('display_errors', '0');
@ignore_user_abort(true);

if (PHP_SAPI === 'cli') {
    Engine::runTick((int)($argv[1] ?? 0));
    exit;
}

$key = (string)($_POST['k'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !hash_equals(Engine::secret(), $key)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}
// Diagnostika: /tekshir buyrug'i uchun (hech narsa ishga tushirmaydi). probe=N — N soniya kutib 'ok' qaytaradi
if (isset($_POST['probe'])) {
    @set_time_limit(0);
    $n = max(0, min(90, (int)$_POST['probe']));
    if ($n > 1) {
        sleep($n);
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ok';
    exit;
}
$delay = max(0, min(50, (int)($_POST['d'] ?? 0)));

// Javobni darhol yopamiz (chaqiruvchi kutib o'tirmasin), ish esa davom etadi
header('Content-Type: text/plain; charset=utf-8');
header('Connection: close');
header('Content-Length: 2');
echo 'ok';
while (ob_get_level() > 0) {
    @ob_end_flush();
}
@flush();
if (function_exists('fastcgi_finish_request')) {
    @fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    @litespeed_finish_request();
}

Engine::runTick($delay);
