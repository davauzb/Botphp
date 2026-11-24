<?php
declare(strict_types=1);

/**
 * ⭐ Jonli ovoz ishchisi uchun API (lib/voice_worker/worker.py shu manzilga o'zi ulanadi).
 * Faqat POST JSON; token tanada ("t") — ba'zi hostinglar Authorization sarlavhasini kesadi.
 *   install / file  — o'rnatuvchi skript va ishchi fayllari (Oracle cloud-init shu yerdan yuklaydi)
 *   progress        — o'rnatish bosqichi (admin panelda ko'rinadi)
 *   hello           — ishchi holati va ovozlari
 *   next            — navbatdagi ish (20 soniyagacha kutib turadi)
 *   sample          — ovoz qo'shish ishi uchun namuna fayli
 *   result          — natija (ovoz mijozga shu yerning o'zida jo'natiladi)
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/Telegram.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function vout(array $d, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    vout(['ok' => false, 'error' => 'POST kerak'], 405);
}
$raw = (string)file_get_contents('php://input', false, null, 0, 40 * 1048576);
$b = json_decode($raw, true);
if (!is_array($b)) {
    vout(['ok' => false, 'error' => 'bad json'], 400);
}
if (!LiveVoice::checkToken((string)($b['t'] ?? ''))) {
    usleep(800000);                                      // tokenni taxmin qilishni sekinlashtirish
    vout(['ok' => false, 'error' => 'unauthorized'], 401);
}

$a = (string)($b['a'] ?? '');
switch ($a) {
    case 'install':
    case 'file':
        $map = ['install' => 'install.sh', 'worker.py' => 'worker.py', 'requirements-lock.txt' => 'requirements-lock.txt'];
        $f = $a === 'install' ? 'install' : (string)($b['f'] ?? '');
        if (!isset($map[$f])) {
            vout(['ok' => false, 'error' => 'not found'], 404);
        }
        header('Content-Type: text/plain; charset=utf-8');
        readfile(__DIR__ . '/lib/voice_worker/' . $map[$f]);
        exit;

    case 'progress':
        $step = max(0, min(9, (int)($b['step'] ?? 0)));
        $msg = mb_substr((string)($b['msg'] ?? ''), 0, 400);
        $err = !empty($b['err']);
        Engine::kvSet('live_progress', json_encode(['step' => $step, 'msg' => $msg, 'err' => $err], JSON_UNESCAPED_UNICODE));
        if (($err || $step >= 8) && defined('ADMIN_CHAT_ID') && (int)ADMIN_CHAT_ID > 0) {
            Telegram::sendMessage((int)ADMIN_CHAT_ID, $err
                ? "⚠️ <b>Ovoz serverini o'rnatishda xato</b> (bosqich $step/7):\n<code>" . Telegram::escape($msg) . "</code>\nAdmin panel → Tizim → «⭐ Jonli ovoz» da ko'rsatma bor."
                : "⭐ Ovoz serveri o'rnatildi — model yuklanmoqda, 1–2 daqiqada ulanadi.");
        }
        vout(['ok' => true]);

    case 'hello':
        vout(LiveVoice::workerHello($b));

    case 'next':
        @set_time_limit(45);
        ignore_user_abort(false);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        $until = microtime(true) + 20;
        while (true) {
            $job = LiveVoice::claimNext();
            if ($job) {
                echo json_encode(['ok' => true, 'job' => $job], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (microtime(true) >= $until) {
                break;
            }
            sleep(1);
            echo ' ';                                    // ishchi uzilganini sezish uchun (JSON oldidagi bo'shliq zararsiz)
            flush();
            if (connection_aborted()) {
                exit;                                    // ishchi o'chgan — «oxirgi aloqa» yangilanmasin
            }
        }
        echo json_encode(['ok' => true, 'job' => null]);
        exit;

    case 'sample':
        @set_time_limit(120);
        vout(LiveVoice::sampleFor((int)($b['id'] ?? 0)));

    case 'result':
        @set_time_limit(120);
        vout(LiveVoice::workerResult($b));
}
vout(['ok' => false, 'error' => 'unknown action'], 400);
