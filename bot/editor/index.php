<?php
declare(strict_types=1);

/**
 * Tahrirlovchi sahifasi. Faqat to'g'ri, muddati o'tmagan havola tokeni bilan ochiladi (token Telegram'dagi
 * "web_app" tugmasi ichida yashirin turadi). Aks holda — oddiy "404 Not found".
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/PptxElements.php';

ini_set('display_errors', '0');
if (!EditorSecurity::enabled() || !in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    EditorSecurity::deny();
}
$ip = EditorSecurity::ip();
if (!EditorSecurity::rate("page:$ip", 40, 600)) {
    EditorSecurity::deny(429);
}
$row = EditorSecurity::checkLaunchToken($_GET['t'] ?? null);
if (!$row) {
    EditorSecurity::audit('page_bad_token');
    EditorSecurity::deny();
}

// Havola hujjat (mustaqil ish/referat) uchunmi yoki taqdimot uchunmi — shunga qarab tegishli interfeys beriladi
try {
    $isDoc = (bool)DocStore::getOwned((string)$row['pres_id'], (int)$row['telegram_id']);
} catch (Throwable $e) {
    logError('editor', 'index: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    EditorSecurity::deny();      // bo'sh 500 sahifa o'rniga oddiy 404 (xato jurnalda)
}
if ($isDoc) {
    $dir = __DIR__ . '/../lib/doc_ui/';
    $files = ['doc.js'];
} else {
    $dir = __DIR__ . '/../lib/editor_ui/';
    $files = ['icons.js', 'geom.js', 'model.js', 'render.js', 'text.js', 'interact.js', 'panels.js', 'main.js'];
}
$js = '';
foreach ($files as $f) {
    $js .= "\n/* ==== $f ==== */\n" . (string)file_get_contents($dir . $f);
}
$css = (string)file_get_contents($dir . 'style.css');
$html = (string)file_get_contents($dir . 'app.html');
$nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');

$html = str_replace(
    ['{{CSS}}', '{{NONCE}}', '{{TOKEN}}', '{{JS}}'],
    [$css, $nonce, (string)$_GET['t'], $js],
    $html
);

EditorSecurity::sendPageHeaders($nonce);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') {
    exit;
}
echo $html;
