<?php
declare(strict_types=1);

/**
 * Tahrirlovchi API. Barcha so'rovlar POST (rasm olish — GET), JSON, "Authorization: Bearer <sessiya>".
 * Har qanday xato holatida bir xil "404 Not found" qaytadi (mavjudligini yashirish uchun).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Telegram.php';
require_once __DIR__ . '/../lib/PptxGenerator.php';
require_once __DIR__ . '/../lib/PptxElements.php';
require_once __DIR__ . '/../lib/EditorStore.php';
require_once __DIR__ . '/../lib/DocApi.php';
require_once __DIR__ . '/../lib/SlidePdf.php';
require_once __DIR__ . '/../lib/AIClient.php';
require_once __DIR__ . '/../lib/EditorAI.php';

ini_set('display_errors', '0');
if (!EditorSecurity::enabled()) {
    EditorSecurity::deny();
}
EditorSecurity::sendCommonHeaders();

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function readJson(int $max = 8388608): array
{
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > $max) {
        EditorSecurity::deny();
    }
    $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($ct, 'application/json') !== 0) {
        EditorSecurity::deny();
    }
    $raw = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($raw === false || strlen($raw) > $max) {
        EditorSecurity::deny();
    }
    $d = json_decode($raw, true, 64);
    return is_array($d) ? $d : [];
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = (string)($_GET['a'] ?? '');
    $allowed = ['auth', 'load', 'save', 'upload', 'img', 'regen', 'airewrite', 'export', 'ping', 'logout', 'dai'];
    if (!in_array($action, $allowed, true)) {
        EditorSecurity::deny();
    }
    if (($action === 'img' && $method !== 'GET') || ($action !== 'img' && $method !== 'POST')) {
        EditorSecurity::deny();
    }

    // Boshqa saytdan so'rov (CSRF/CORS) — rad etiladi
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    // Brauzerning Origin sarlavhasida YO'L bo'lmaydi (faqat sxema://domen[:port]), PUBLIC_BASE_URL esa kichik papkani ham
    // o'z ichiga olishi mumkin (masalan https://domen.uz/SlayderAi1Bit) — shuning uchun faqat origin qismi solishtiriladi.
    // Avval to'liq URL bilan solishtirilgani uchun papkada turgan botda tahrirlovchi doim "havola eskirgan" der edi.
    $pu = parse_url(PUBLIC_BASE_URL) ?: [];
    $ownOrigin = strtolower(($pu['scheme'] ?? 'https') . '://' . ($pu['host'] ?? '') . (isset($pu['port']) ? ':' . $pu['port'] : ''));
    if ($origin !== '' && strtolower(rtrim($origin, '/')) !== $ownOrigin) {
        EditorSecurity::audit('bad_origin', null, null, $origin);
        EditorSecurity::deny();
    }
    $sfs = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($sfs !== '' && !in_array($sfs, ['same-origin', 'none'], true)) {
        EditorSecurity::deny();
    }

    $ip = EditorSecurity::ip();
    if (!EditorSecurity::rate("api:$ip", 900, 300)) {
        EditorSecurity::deny(429);
    }

    // ------------------------------------------------------------ AUTH
    if ($action === 'auth') {
        if (!EditorSecurity::rate("auth:$ip", 12, 600)) {
            EditorSecurity::audit('auth_rate_limited');
            EditorSecurity::deny(429);
        }
        $b = readJson(16384);
        $row = EditorSecurity::checkLaunchToken($b['t'] ?? null);
        if (!$row) {
            EditorSecurity::audit('auth_bad_token');
            EditorSecurity::deny();
        }
        if (!EditorSecurity::rate('tok:' . substr($row['token_hash'], 0, 16), 10, 900)) {
            EditorSecurity::deny(429);
        }
        $ini = EditorSecurity::verifyInitData($b['initData'] ?? null);
        if (!$ini || $ini['id'] !== (int)$row['telegram_id']) {
            EditorSecurity::audit('auth_bad_initdata', (int)$row['telegram_id'], $row['pres_id']);
            EditorSecurity::deny();
        }
        $pres = EditorStore::getOwned($row['pres_id'], $ini['id']) ?: DocStore::getOwned((string)$row['pres_id'], $ini['id']);
        if (!$pres) {
            EditorSecurity::deny();
        }
        $session = EditorSecurity::createSession($pres['id'], $ini['id']);
        // Tugma joylashgan xabarni chatdan o'chiramiz — havola chatda qolmaydi
        if (empty($row['opened_at']) && !empty($row['message_id']) && !empty($row['chat_id'])) {
            Telegram::deleteMessage((int)$row['chat_id'], (int)$row['message_id']);
        }
        db()->prepare('UPDATE edit_tokens SET opened_at = ? WHERE token_hash = ?')->execute([time(), $row['token_hash']]);
        EditorSecurity::audit('auth_ok', $ini['id'], $pres['id']);
        out(['ok' => true, 'session' => $session, 'idle' => EDITOR_SESSION_IDLE]);
    }

    // ------------------------------------------------------------ sessiya talab qilinadi
    $sess = EditorSecurity::session();
    if (!$sess) {
        EditorSecurity::deny();
    }
    $pid = (string)$sess['pres_id'];
    $tg = (int)$sess['telegram_id'];
    EditorSecurity::cleanup();
    // Sessiya HUJJAT (mustaqil ish/referat) tahrirlovchisiga tegishlimi?
    $docRow = DocStore::getOwned($pid, $tg);
    if ($docRow) {
        DocApi::handle($action, $sess, $docRow);
        exit;
    }
    if ($action === 'dai') {
        EditorSecurity::deny();
    }
    $pres = EditorStore::getOwned($pid, $tg);
    if (!$pres) {
        EditorSecurity::deny();
    }

    switch ($action) {
        case 'ping':
            out(['ok' => true]);

        case 'logout':
            EditorSecurity::revokeSession($sess['sess_hash']);
            out(['ok' => true]);

        case 'load':
            $designs = [];
            foreach (pptxDesigns() as $k => $d) {
                $designs[] = ['key' => $k, 'label' => $d['label'], 'accent' => $d['accent'], 'title' => $d['title'], 'skin' => $d['skin'] ?? 'classic'];
            }
            $imgs = [];
            $st = db()->prepare('SELECT id, w, h FROM pres_images WHERE pres_id = ? ORDER BY created_at');
            $st->execute([$pid]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $imgs[] = ['id' => $r['id'], 'w' => (int)$r['w'], 'h' => (int)$r['h']];
            }
            out(['ok' => true, 'pres' => [
                'id' => $pid, 'title' => $pres['title'], 'version' => (int)$pres['version'],
                'design' => $pres['design'], 'slides' => $pres['slides'],
            ], 'images' => $imgs, 'designs' => $designs, 'fonts' => PptxElements::FONTS,
                'limits' => ['slides' => PptxElements::MAX_SLIDES, 'els' => PptxElements::MAX_ELS, 'images' => EDITOR_MAX_IMAGES]]);

        case 'save':
            if (!EditorSecurity::rate("save:$pid", 400, 600)) {
                EditorSecurity::deny(429);
            }
            $b = readJson();
            $order = is_array($b['order'] ?? null) ? $b['order'] : [];
            $changed = is_array($b['slides'] ?? null) ? $b['slides'] : [];
            if (count($changed) > PptxElements::MAX_SLIDES) {
                EditorSecurity::deny();
            }
            $res = EditorStore::save(
                $pid, $order, $changed,
                isset($b['title']) && is_string($b['title']) ? $b['title'] : null,
                isset($b['design']) && is_string($b['design']) ? $b['design'] : null,
                (int)($b['base_version'] ?? 0)
            );
            if (!$res['ok']) {
                out(['ok' => false, 'error' => $res['error'], 'version' => $res['version'] ?? null], $res['error'] === 'conflict' ? 409 : 422);
            }
            out(['ok' => true, 'version' => $res['version']]);

        case 'upload':
            if (!EditorSecurity::rate("up:$pid", 40, 600)) {
                EditorSecurity::deny(429);
            }
            if (empty($_FILES['file']) || !is_array($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK
                || !is_uploaded_file((string)$_FILES['file']['tmp_name'])) {
                out(['ok' => false, 'error' => 'upload'], 422);
            }
            $bytes = (string)file_get_contents($_FILES['file']['tmp_name'], false, null, 0, EDITOR_MAX_UPLOAD_BYTES + 1);
            $r = EditorStore::addImage($pid, $bytes);
            if (!is_array($r)) {
                out(['ok' => false, 'error' => $r], 422);
            }
            out(['ok' => true, 'image' => ['id' => $r['id'], 'w' => $r['w'], 'h' => $r['h']]]);

        case 'img':
            $info = EditorStore::imageInfo($pid, (string)($_GET['id'] ?? ''));
            if (!$info) {
                EditorSecurity::deny();
            }
            header('Content-Type: ' . ($info['ext'] === 'png' ? 'image/png' : 'image/jpeg'));
            header('Content-Length: ' . filesize($info['path']));
            header('Cache-Control: private, max-age=3600');
            readfile($info['path']);
            exit;

        case 'regen':
            if (!EditorSecurity::rate("regen:$pid", 300, 300)) {
                EditorSecurity::deny(429);
            }
            $b = readJson(262144);
            $dk = isset($b['design']) && is_string($b['design']) && isset(pptxDesigns()[$b['design']]) ? $b['design'] : $pres['design']['key'];
            $res = EditorStore::regen($pid, is_array($b['sem'] ?? null) ? $b['sem'] : [], $dk, (int)($b['n'] ?? 1), (int)($b['total'] ?? 1));
            out(['ok' => true, 'sem' => $res['sem'], 'els' => $res['els']]);

        case 'airewrite':
            // Bitta slaydni AI bilan qayta yozish (natija faqat qaytariladi — saqlanmaydi; mijoz «Qo'llash» bosgandagina regen+save bo'ladi)
            if (!EditorAI::enabled()) {
                out(['ok' => false, 'error' => 'off'], 501);
            }
            $perHour = max(1, (int)Engine::c('EDITOR_AI_PER_HOUR', 20));
            if (!EditorSecurity::rate("ai:$pid", $perHour, 3600) || !EditorSecurity::rate("aiu:$tg", $perHour * 3, 3600)) {
                out(['ok' => false, 'error' => 'rate'], 429);
            }
            $b = readJson(65536);
            $res = EditorAI::rewrite(
                is_array($b['sem'] ?? null) ? $b['sem'] : [],
                is_string($b['mode'] ?? null) ? $b['mode'] : '',
                is_string($b['note'] ?? null) ? $b['note'] : '',
                (string)$pres['title'],
                (string)($pres['design']['lang'] ?? 'uz')
            );
            if (empty($res['ok'])) {
                out(['ok' => false, 'error' => (string)($res['error'] ?? 'ai')], $res['error'] === 'busy' ? 503 : 422);
            }
            EditorSecurity::audit('ai_rewrite', $tg, $pid);
            out(['ok' => true, 'sem' => $res['sem']]);

        case 'export':
            $b = readJson(4096);
            $format = (($b['format'] ?? '') === 'pdf') ? 'pdf' : 'pptx';
            if (!EditorSecurity::rate("exp:$pid", 8, 600) || ($format === 'pdf' && !EditorSecurity::rate("exppdf:$pid", 4, 600))) {
                out(['ok' => false, 'error' => 'rate'], 429);
            }
            if ($format === 'pdf') {
                if (!SlidePdf::available()) {
                    out(['ok' => false, 'error' => 'no_pdf'], 501);
                }
                [$path, $how] = SlidePdf::fromPresentation($pid);
                if (!$path) {
                    logError('editor', "PDF yaratilmadi ($pid): $how");
                    out(['ok' => false, 'error' => 'build'], 500);
                }
                $caption = '📄 PDF tayyor: <b>' . Telegram::escape((string)$pres['title']) . "</b>\n"
                    . "Pastdagi tugma orqali taqdimotni yana tahrirlashingiz mumkin.";
                $fname = preg_replace('/\.pptx$/i', '', EditorStore::fileSafeName((string)$pres['title'])) . '.pdf';
            } else {
                [$path, $errors] = EditorStore::exportFile($pid);
                if (!$path) {
                    out(['ok' => false, 'error' => 'build'], 500);
                }
                $caption = '✅ Tahrirlangan taqdimot tayyor: <b>' . Telegram::escape((string)$pres['title']) . "</b>\n"
                    . "Pastdagi tugma orqali yana tahrirlashingiz mumkin.";
                $fname = EditorStore::fileSafeName((string)$pres['title']);
            }
            $sent = Telegram::sendDocument($tg, $path, $caption, ['inline_keyboard' => [[
                ['text' => '✏️ Qayta tahrirlash', 'callback_data' => 'edit:' . $pid],
            ]]], $fname);
            @unlink($path);
            if (!$sent) {
                out(['ok' => false, 'error' => 'telegram'], 502);
            }
            db()->prepare('UPDATE presentations SET exported_at = ? WHERE id = ?')->execute([time(), $pid]);
            EditorSecurity::audit($format === 'pdf' ? 'export_pdf' : 'export', $tg, $pid);
            out(['ok' => true]);
    }
    EditorSecurity::deny();
} catch (Throwable $e) {
    logError('editor', 'api xato: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    out(['ok' => false, 'error' => 'server'], 500);
}
