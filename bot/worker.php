<?php
declare(strict_types=1);

/**
 * ESLATMA: endi cron KERAK EMAS — bot ishlarni o'zi tick.php orqali bajaradi.
 * Bu fayl faqat ixtiyoriy: xohlasangiz qo'lda yoki cron bilan  `php worker.php`  deb ishga tushirib,
 * navbatdagi barcha ishlarni shu jarayonning o'zida bajartirishingiz mumkin.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/Telegram.php';
require_once __DIR__ . '/lib/AIClient.php';
require_once __DIR__ . '/lib/DocxGenerator.php';
require_once __DIR__ . '/lib/PptxGenerator.php';
require_once __DIR__ . '/lib/EditorStore.php';
require_once __DIR__ . '/lib/ImageSearch.php';
require_once __DIR__ . '/lib/JobSteps.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$until = time() + 55;
do {
    Engine::runTick(0);
    if (Engine::runnableCount() === 0) {
        break;
    }
} while (time() < $until);
