<?php
declare(strict_types=1);

/**
 * Admin panel API. Barcha so'rovlar POST + JSON; sessiya — "Authorization: Bearer <token>" (cookie ishlatilmaydi).
 * Autentifikatsiyadan oldingi har qanday xato — bir xil "404 Not found".
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Telegram.php';
require_once __DIR__ . '/../lib/Queue.php';
require_once __DIR__ . '/../lib/TopupHelpers.php';
require_once __DIR__ . '/../lib/AdminService.php';

ini_set('display_errors', '0');
if (!AdminSecurity::enabled()) {
    AdminSecurity::deny();
}
EditorSecurity::sendCommonHeaders();

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function readJson(int $max = 16384): array
{
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > $max) {
        AdminSecurity::deny();
    }
    $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($ct, 'application/json') !== 0) {
        AdminSecurity::deny();
    }
    $raw = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($raw === false || strlen($raw) > $max) {
        AdminSecurity::deny();
    }
    $d = json_decode($raw, true, 16);
    return is_array($d) ? $d : [];
}

/** Butun son (yoki raqamli satr) — aks holda "bad_request". */
function intv($v, int $min, int $max): int
{
    if (is_string($v) && preg_match('/^-?[0-9]{1,12}$/', $v)) {
        $v = (int)$v;
    }
    if (!is_int($v) || $v < $min || $v > $max) {
        AdminSecurity::fail('bad_request', 400, 'Noto\'g\'ri qiymat');
    }
    return $v;
}

function strv($v, int $max): string
{
    return is_string($v) ? mb_substr($v, 0, $max) : '';
}

/** Pul bilan bog'liq katta amallarda PIN qayta so'raladi (PIN o'rnatilgan bo'lsa). Idempotency'dan OLDIN chaqiriladi. */
function stepUp(int $admin, int $amount, array $b): void
{
    if (!AdminSecurity::pinRequired() || abs($amount) <= ADMIN_STEPUP_AMOUNT) {
        return;
    }
    $lock = AdminSecurity::pinLocked($admin);
    if ($lock > 0) {
        AdminSecurity::fail('locked', 429, 'PIN bloklangan. ' . (int)ceil($lock / 60) . ' daqiqadan keyin urinib ko\'ring');
    }
    $pin = $b['pin'] ?? '';
    if (!is_string($pin) || $pin === '') {
        AdminSecurity::fail('stepup_required', 403, 'Katta summa — PIN kodni kiriting');
    }
    if (!AdminSecurity::checkPin($admin, $pin)) {
        AdminSecurity::fail('bad_pin', 403, 'PIN noto\'g\'ri');
    }
}

/** Idempotent bajarish: bir xil kalit bilan qayta kelgan so'rov ikkinchi marta bajarilmaydi. */
function mutate(int $admin, array $b, callable $fn): void
{
    $key = strv($b['idem'] ?? '', 64);
    $dup = AdminSecurity::idemBegin($admin, $key);
    if ($dup !== null) {
        out($dup + ['dup' => true], !empty($dup['ok']) ? 200 : 409);
    }
    try {
        $res = $fn();
    } catch (Throwable $e) {
        logError('admin', 'amal xatosi: ' . $e->getMessage());
        $res = ['ok' => false, 'error' => 'server', 'msg' => 'Server xatosi'];
    }
    AdminSecurity::idemEnd($key, $res);
    out($res, !empty($res['ok']) ? 200 : 400);
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = (string)($_GET['a'] ?? '');
    $allowed = ['auth', 'ping', 'logout', 'stats', 'users', 'user', 'adjust', 'ban', 'msg', 'topups', 'topup_decide',
        'receipt', 'jobs', 'job', 'job_cancel', 'audit', 'promos', 'promo_save', 'promo_toggle', 'promo_delete', 'promo_uses',
        'events', 'events_read'];
    if ($method !== 'POST' || !in_array($action, $allowed, true)) {
        AdminSecurity::deny();
    }

    // Boshqa saytdan so'rov (CSRF/CORS) — rad etiladi. Origin sarlavhasida yo'l bo'lmaydi, faqat sxema://domen
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $pu = parse_url(PUBLIC_BASE_URL) ?: [];
    $ownOrigin = strtolower(($pu['scheme'] ?? 'https') . '://' . ($pu['host'] ?? '') . (isset($pu['port']) ? ':' . $pu['port'] : ''));
    if ($origin !== '' && strtolower(rtrim($origin, '/')) !== $ownOrigin) {
        AdminSecurity::audit('bad_origin', null, '', $origin);
        AdminSecurity::deny();
    }
    $sfs = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($sfs !== '' && !in_array($sfs, ['same-origin', 'none'], true)) {
        AdminSecurity::deny();
    }

    $ip = AdminSecurity::ip();
    if (!AdminSecurity::rate("api:$ip", 600, 300)) {
        AdminSecurity::deny(429);
    }
    AdminSecurity::cleanup();
    $b = readJson();

    // ------------------------------------------------------------ kirish
    if ($action === 'auth') {
        if (!AdminSecurity::rate("auth:$ip", 20, 600)) {
            AdminSecurity::deny(429);
        }
        $tok = $b['t'] ?? '';
        $row = AdminSecurity::checkLaunchToken($tok);
        $v = $row ? AdminSecurity::verifyInitData($b['initData'] ?? null) : null;
        if (!$row || !$v || $v['id'] !== (int)$row['tg_id']) {
            AdminSecurity::audit('auth_fail', $row ? (int)$row['tg_id'] : null, '', $row ? 'initData' : 'token');
            AdminSecurity::deny();
        }
        $admin = (int)$row['tg_id'];

        if (AdminSecurity::pinRequired()) {
            $lock = AdminSecurity::pinLocked($admin);
            if ($lock > 0) {
                AdminSecurity::fail('locked', 429, 'PIN bloklangan. ' . (int)ceil($lock / 60) . ' daqiqadan keyin urinib ko\'ring');
            }
            $pin = $b['pin'] ?? '';
            if (!is_string($pin) || $pin === '') {
                out(['ok' => false, 'error' => 'pin_required']);       // token hali sarflanmaydi
            }
            if (!AdminSecurity::checkPin($admin, $pin)) {
                $left = max(0, ADMIN_PIN_MAX_FAILS - (int)(db()->query("SELECT COUNT(*) FROM edit_hits WHERE k = 'admfail:pin:$admin' AND ts > " . (time() - ADMIN_PIN_LOCK))->fetchColumn()));
                if ($left <= 0) {
                    AdminSecurity::notifyAdmins('🚨 <b>Admin panel:</b> PIN ko\'p marta xato kiritildi — kirish ' . (int)(ADMIN_PIN_LOCK / 60) . ' daqiqaga bloklandi. IP: <code>' . htmlspecialchars($ip) . '</code>');
                }
                AdminSecurity::fail('bad_pin', 403, $left > 0 ? "PIN noto'g'ri. Qolgan urinishlar: $left" : 'PIN bloklandi');
            }
        }

        if (!AdminSecurity::consumeToken(EditorSecurity::hash((string)$tok))) {
            AdminSecurity::deny();
        }
        $sess = AdminSecurity::createSession($admin);
        AdminSecurity::audit('auth_ok', $admin);
        if (!empty($row['chat_id']) && !empty($row['message_id'])) {
            Telegram::deleteMessage((int)$row['chat_id'], (int)$row['message_id']);   // havola xabari o'chiriladi
        }
        AdminSecurity::notifyAdmins('🔐 Admin panelga kirildi · <code>' . $admin . '</code> · IP <code>' . htmlspecialchars($ip) . '</code> · ' . gmdate('H:i', time() + ADMIN_TZ_OFFSET));
        out(['ok' => true, 'session' => $sess, 'admin' => $admin, 'idle' => ADMIN_SESSION_IDLE, 'pin' => AdminSecurity::pinRequired(),
            'stepup' => ADMIN_STEPUP_AMOUNT, 'max_adjust' => ADMIN_MAX_ADJUST]);
    }

    // ------------------------------------------------------------ sessiya talab qilinadi
    $s = AdminSecurity::session();
    if (!$s) {
        AdminSecurity::deny();
    }
    $admin = $s['tg_id'];
    if (!AdminSecurity::rate('s:' . $s['hash'], 240, 60)) {
        AdminSecurity::fail('rate', 429, 'Juda tez-tez so\'rov. Biroz kuting');
    }

    switch ($action) {
        case 'ping':
            out(['ok' => true, 'expires_at' => $s['expires_at'], 'abs_expires' => $s['abs_expires'], 'now' => time()]);

        case 'logout':
            AdminSecurity::revokeSession($s['hash']);
            AdminSecurity::audit('logout', $admin);
            out(['ok' => true]);

        case 'stats':
            out(['ok' => true] + AdminService::stats(intv($b['days'] ?? 14, 7, 30)));

        case 'users':
            out(['ok' => true] + AdminService::users(strv($b['q'] ?? '', 60), intv($b['offset'] ?? 0, 0, 100000)));

        case 'user':
            $u = AdminService::user(intv($b['id'] ?? 0, 1, PHP_INT_MAX));
            $u ? out(['ok' => true, 'user' => $u]) : AdminSecurity::fail('not_found', 404, 'Topilmadi');

        case 'adjust':
            $tg = intv($b['tg'] ?? 0, 1, PHP_INT_MAX);
            $amount = intv($b['amount'] ?? 0, -ADMIN_MAX_ADJUST, ADMIN_MAX_ADJUST);
            stepUp($admin, $amount, $b);
            mutate($admin, $b, fn() => AdminService::adjustBalance($admin, $tg, $amount, strv($b['reason'] ?? '', 200), !empty($b['notify'])));

        case 'ban':
            $tg = intv($b['tg'] ?? 0, 1, PHP_INT_MAX);
            mutate($admin, $b, fn() => AdminService::setBanned($admin, $tg, !empty($b['ban'])));

        case 'msg':
            $tg = intv($b['tg'] ?? 0, 1, PHP_INT_MAX);
            if (!AdminSecurity::rate("msg:$admin", 30, 600)) {
                AdminSecurity::fail('rate', 429, 'Juda ko\'p xabar yuborildi');
            }
            mutate($admin, $b, fn() => AdminService::sendMessage($admin, $tg, strv($b['text'] ?? '', 1000)));

        case 'topups':
            $f = ($b['filter'] ?? 'pending') === 'all' ? 'all' : 'pending';
            out(['ok' => true] + AdminService::topups($f, intv($b['offset'] ?? 0, 0, 100000)));

        case 'topup_decide':
            $id = intv($b['id'] ?? 0, 1, PHP_INT_MAX);
            $approve = !empty($b['approve']);
            if ($approve) {
                $st = db()->prepare('SELECT amount FROM topups WHERE id = ?');
                $st->execute([$id]);
                stepUp($admin, (int)($st->fetchColumn() ?: 0), $b);
            }
            mutate($admin, $b, fn() => AdminService::decideTopup($admin, $id, $approve));

        case 'receipt':
            if (!AdminSecurity::rate("rcpt:$admin", 20, 300)) {
                AdminSecurity::fail('rate', 429, 'Juda tez-tez. Biroz kuting');
            }
            $r = AdminService::sendReceipt($admin, intv($b['id'] ?? 0, 1, PHP_INT_MAX));
            out($r, !empty($r['ok']) ? 200 : 400);

        case 'jobs':
            $f = in_array($b['filter'] ?? 'all', ['all', 'active', 'done', 'failed', 'cancelled'], true) ? (string)$b['filter'] : 'all';
            out(['ok' => true] + AdminService::jobs($f, strv($b['q'] ?? '', 60), intv($b['offset'] ?? 0, 0, 100000)));

        case 'job':
            $j = AdminService::job(intv($b['id'] ?? 0, 1, PHP_INT_MAX));
            $j ? out(['ok' => true, 'job' => $j]) : AdminSecurity::fail('not_found', 404, 'Topilmadi');

        case 'job_cancel':
            $id = intv($b['id'] ?? 0, 1, PHP_INT_MAX);
            mutate($admin, $b, fn() => AdminService::cancelJob($admin, $id));

        case 'promos':
            out(['ok' => true] + AdminService::promos(intv($b['offset'] ?? 0, 0, 100000)));

        case 'promo_save':
            $kind = ($b['kind'] ?? '') === 'topup_pct' ? 'topup_pct' : 'balance';
            $value = intv($b['value'] ?? 0, 1, 5000000);
            $max = intv($b['max'] ?? 0, 0, 1000000);
            $days = intv($b['days'] ?? 0, 0, 3650);
            // balansga pul beradigan kod — eng ko'pi bilan berilishi mumkin bo'lgan summa bo'yicha PIN talab qilinadi
            stepUp($admin, $kind === 'balance' ? $value * max(1, min($max ?: 100, 100)) : 0, $b);
            mutate($admin, $b, fn() => AdminService::createPromo($admin, strv($b['code'] ?? '', 40), $kind, $value, $max, $days, strv($b['note'] ?? '', 120)));

        case 'promo_toggle':
            mutate($admin, $b, fn() => AdminService::setPromoActive($admin, strv($b['code'] ?? '', 40), !empty($b['active'])));

        case 'promo_delete':
            mutate($admin, $b, fn() => AdminService::deletePromo($admin, strv($b['code'] ?? '', 40)));

        case 'promo_uses':
            out(['ok' => true] + AdminService::promoUses(strv($b['code'] ?? '', 40), intv($b['offset'] ?? 0, 0, 100000)));

        case 'events':
            out(['ok' => true] + AdminEvents::list(intv($b['offset'] ?? 0, 0, 100000)));

        case 'events_read':
            AdminEvents::markAllRead();
            out(['ok' => true]);

        case 'audit':
            out(['ok' => true] + AdminService::audit(intv($b['offset'] ?? 0, 0, 100000)));
    }
    AdminSecurity::deny();
} catch (Throwable $e) {
    logError('admin', $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    out(['ok' => false, 'error' => 'server', 'msg' => 'Server xatosi'], 500);
}
