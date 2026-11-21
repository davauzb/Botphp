<?php
declare(strict_types=1);

/** Mini App API: POST JSON {initData, action: "init"|"order", ...}. Har so'rovda Telegram imzosi tekshiriladi. */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Telegram.php';
require_once __DIR__ . '/../lib/Queue.php';
require_once __DIR__ . '/../lib/AIClient.php';
require_once __DIR__ . '/../lib/DocxGenerator.php';
require_once __DIR__ . '/../lib/PptxGenerator.php';
require_once __DIR__ . '/../lib/ImageSearch.php';
require_once __DIR__ . '/../lib/JobSteps.php';

ini_set('display_errors', '0');
EditorSecurity::sendCommonHeaders();
header('Content-Type: application/json; charset=utf-8');

function appOut(array $d, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!MiniApp::enabled() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
    appOut(['ok' => false, 'msg' => 'Not found'], 404);
}
$in = json_decode((string)file_get_contents('php://input', false, null, 0, 16385), true);
if (!is_array($in)) {
    appOut(['ok' => false, 'msg' => 'Bad request'], 400);
}
$tg = MiniApp::verify((string)($in['initData'] ?? ''));
if ($tg <= 0) {
    appOut(['ok' => false, 'msg' => "Ilovani bot ichidagi «📱 Ilova» tugmasi orqali oching"], 403);
}
if (!AdminSecurity::rate('app:' . $tg, 40, 300)) {
    appOut(['ok' => false, 'msg' => 'Juda tez-tez. Birozdan keyin urinib ko\'ring'], 429);
}
try {
    switch ((string)($in['action'] ?? '')) {
        case 'init':
            appOut(MiniApp::data($tg));
        case 'order':
            if (!AdminSecurity::rate('apporder:' . $tg, 4, 300)) {
                appOut(['ok' => false, 'msg' => 'Juda ko\'p buyurtma. Birozdan keyin urinib ko\'ring']);
            }
            appOut(MiniApp::place($tg, $in));
        default:
            appOut(['ok' => false, 'msg' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    logError('miniapp', $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    appOut(['ok' => false, 'msg' => 'Server xatosi'], 500);
}
