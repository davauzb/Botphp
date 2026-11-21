<?php
declare(strict_types=1);

/** 📱 Buyurtma Mini App sahifasi (ma'lumot va buyurtma — app/api.php orqali, Telegram initData imzosi bilan). */
require_once __DIR__ . '/../db.php';

ini_set('display_errors', '0');
if (!MiniApp::enabled()) {
    http_response_code(404);
    exit('Not found');
}
$html = @file_get_contents(__DIR__ . '/../lib/app_ui/app.html');
if ($html === false) {
    http_response_code(404);
    exit('Not found');
}
$nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
AdminSecurity::sendPageHeaders($nonce);
echo str_replace('{{NONCE}}', $nonce, $html);
