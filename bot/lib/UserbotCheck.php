<?php

/**
 * /userbot_tekshir — hosting userbot (MadelineProto) uchun yaroqlimi: PHP versiyasi, kengaytmalar, fon jarayoni,
 * xotira, PHP CLI, MadelineProto serveriga ulanish va storage'ga yozish. Hech narsa o'rnatmaydi, faqat tekshiradi.
 */
class UserbotCheck
{
    public static function report(): string
    {
        $L = ["👤 <b>Userbot uchun hosting tekshiruvi</b>"];
        $okAll = true;
        $row = function (bool $ok, string $t, bool $critical = true) use (&$L, &$okAll) {
            $L[] = ($ok ? '🟢 ' : ($critical ? '🔴 ' : '🟡 ')) . $t;
            if (!$ok && $critical) {
                $okAll = false;
            }
        };
        $row(PHP_VERSION_ID >= 80200, 'PHP ' . PHP_VERSION . (PHP_VERSION_ID >= 80200 ? '' : ' — kamida 8.2 kerak (hosting panelidan PHP versiyasini oshiring)'));
        $need = ['openssl', 'mbstring', 'json', 'xml', 'dom', 'zlib', 'fileinfo', 'ctype', 'filter', 'hash', 'curl'];
        $miss = array_values(array_filter($need, fn($e) => !extension_loaded($e)));
        $row(!$miss, $miss ? 'Kengaytmalar yetishmaydi: ' . implode(', ', $miss) : 'Kerakli PHP kengaytmalari bor');
        $row(extension_loaded('gmp') || extension_loaded('bcmath'), 'GMP/BCMath: ' . (extension_loaded('gmp') ? 'gmp ✅' : (extension_loaded('bcmath') ? 'bcmath' : "yo'q")), false);
        $dis = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        foreach (['proc_open', 'exec', 'shell_exec', 'posix_kill'] as $f) {
            $on = function_exists($f) && !in_array($f, $dis, true);
            $row($on, "$f(): " . ($on ? 'ruxsat' : "o'chirilgan"), $f === 'proc_open');
        }
        $mem = (string)ini_get('memory_limit');
        $bytes = $mem === '-1' ? PHP_INT_MAX : (int)$mem * (stripos($mem, 'g') !== false ? 1 << 30 : (stripos($mem, 'm') !== false ? 1 << 20 : 1));
        $row($bytes >= 256 << 20, "memory_limit: $mem" . ($bytes >= 256 << 20 ? '' : ' — kamida 256M tavsiya'), false);
        $cli = '';
        foreach ([PHP_BINDIR . '/php', '/usr/bin/php', '/usr/local/bin/php', '/opt/alt/php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '/usr/bin/php'] as $c) {
            if (@is_executable($c)) {
                $cli = $c;
                break;
            }
        }
        $row($cli !== '', 'PHP CLI: ' . ($cli ?: 'topilmadi'), false);
        $ch = curl_init('https://phar.madelineproto.xyz/madeline.php');
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 10, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $row($code >= 200 && $code < 400, "MadelineProto serveriga ulanish: HTTP $code");
        $f = @fsockopen('149.154.167.50', 443, $en, $es, 6);       // Telegram DC2
        $row((bool)$f, "Telegram serverlariga to'g'ridan-to'g'ri ulanish (MTProto): " . ($f ? 'bor' : "yo'q — hosting tashqi ulanishlarni bloklaydi"));
        if ($f) {
            fclose($f);
        }
        $w = @file_put_contents(rtrim(dirname(DB_PATH), '/') . '/.ub_test', '1') !== false;
        @unlink(rtrim(dirname(DB_PATH), '/') . '/.ub_test');
        $row($w, 'storage/ ga yozish: ' . ($w ? 'ruxsat' : "yo'q"));
        $L[] = '';
        $L[] = $okAll ? "✅ <b>Hosting userbot uchun yaroqli ko'rinadi.</b> Natijani menga yuboring — o'rnatamiz."
            : "❌ <b>Bu hostingda userbot ishlamaydi</b> (🔴 qatorlar). Yo'llar: hosting tarifini/PHP ni o'zgartirish, yoki Android orqali SMS usuli.";
        return implode("\n", $L);
    }
}
