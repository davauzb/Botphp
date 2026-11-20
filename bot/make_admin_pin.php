<?php
// Admin panel PIN hash'ini yaratadi. Faqat terminaldan ishlaydi:  php make_admin_pin.php [PIN]
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$pin = $argv[1] ?? '';
if ($pin === '') {
    fwrite(STDOUT, "Yangi PIN (4–12 ta raqam): ");
    $pin = trim((string)fgets(STDIN));
}
if (!preg_match('/^[0-9]{4,12}$/', $pin)) {
    fwrite(STDERR, "PIN faqat 4–12 ta raqamdan iborat bo'lishi kerak.\n");
    exit(1);
}
$hash = password_hash($pin, PASSWORD_DEFAULT);
echo "\nconfig.php ga quyidagi qatorni yozing:\n\ndefine('ADMIN_PIN_HASH', '" . $hash . "');\n\n";
