<?php
declare(strict_types=1);

/**
 * Admin panel sahifasi. Faqat to'g'ri, muddati o'tmagan, ishlatilmagan havola tokeni bilan ochiladi
 * (token botdagi /admin xabarining "web_app" tugmasi ichida yashirin turadi). Aks holda — oddiy "404 Not found".
 */
require_once __DIR__ . '/../db.php';

ini_set('display_errors', '0');
if (!AdminSecurity::enabled() || !in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    AdminSecurity::deny();
}
if (!AdminSecurity::rate('page:' . AdminSecurity::ip(), 40, 300)) {
    AdminSecurity::deny(429);
}
AdminSecurity::cleanup();

$t = $_GET['t'] ?? '';
$row = AdminSecurity::checkLaunchToken($t);
if (!$row) {
    AdminSecurity::audit('bad_link');
    AdminSecurity::deny();
}

$ui = __DIR__ . '/../lib/admin_ui/';
$html = @file_get_contents($ui . 'app.html');
$css = @file_get_contents($ui . 'style.css');
$js = @file_get_contents($ui . 'app.js');
if ($html === false || $css === false || $js === false) {
    AdminSecurity::deny();
}

$nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
AdminSecurity::sendPageHeaders($nonce);
echo str_replace(
    ['{{NONCE}}', '{{TOKEN}}', '{{PIN}}', '{{CSS}}', '{{JS}}'],
    [$nonce, (string)$t, AdminSecurity::pinRequired() ? '1' : '0', $css, $js],
    $html
);
