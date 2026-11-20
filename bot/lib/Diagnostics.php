<?php

require_once __DIR__ . '/SlidePdf.php';

/**
 * /tekshir — hosting va bot sog'ligini to'liq tekshirish (faqat admin uchun).
 * Natija Telegram'ga 2-3 ta xabar bo'lib yuboriladi: 🟢 yaxshi, 🟡 e'tibor, 🔴 muammo.
 */
class Diagnostics
{
    private array $lines = [];
    private int $bad = 0;
    private int $warn = 0;

    private function ok(string $t): void
    {
        $this->lines[] = "🟢 $t";
    }

    private function warn(string $t): void
    {
        $this->warn++;
        $this->lines[] = "🟡 $t";
    }

    private function bad(string $t): void
    {
        $this->bad++;
        $this->lines[] = "🔴 $t";
    }

    private function head(string $t): void
    {
        $this->lines[] = "\n<b>$t</b>";
    }

    public static function run(int $chatId, int $wallSeconds = 0): void
    {
        $d = new self();
        Telegram::sendMessage($chatId, "🔎 Tekshiruv boshlandi" . ($wallSeconds > 0 ? " (vaqt sinovi: {$wallSeconds}s — kuting)" : '…'));
        foreach ([[$d, 'php'], [$d, 'storage'], [$d, 'database'], [$d, 'engine'], [$d, 'telegram'], [$d, 'ai'], [$d, 'exposure']] as [$o, $m]) {
            try {
                $d->$m();
            } catch (Throwable $e) {
                $d->bad("$m tekshiruvi xato: " . Telegram::escape($e->getMessage()));
            }
            if (count($d->lines) > 18) {
                Telegram::sendMessage($chatId, implode("\n", $d->lines));
                $d->lines = [];
            }
        }
        if ($wallSeconds > 0) {
            $d->wallClock(min(90, $wallSeconds));
        }
        $verdict = $d->bad ? "❌ {$d->bad} ta jiddiy muammo, {$d->warn} ta ogohlantirish." : ($d->warn ? "✅ Ishlaydi, lekin {$d->warn} ta ogohlantirish bor." : "✅ Hammasi joyida!");
        $d->lines[] = "\n<b>Xulosa:</b> $verdict";
        Telegram::sendMessage($chatId, implode("\n", $d->lines));
    }

    private function php(): void
    {
        $this->head('PHP');
        version_compare(PHP_VERSION, '8.0.0', '>=') ? $this->ok('PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')') : $this->bad('PHP ' . PHP_VERSION . ' — kamida 8.0 kerak (hosting panelida PHP versiyasini oshiring)');
        $need = ['curl' => 'curl', 'dom' => 'dom', 'zip' => 'zip', 'gd' => 'gd', 'mbstring' => 'mbstring', 'pdo_sqlite' => 'pdo_sqlite', 'openssl' => 'openssl', 'json' => 'json'];
        $missing = [];
        foreach ($need as $label => $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $label;
            }
        }
        $missing ? $this->bad('Yetishmayotgan PHP kengaytmalari: <b>' . implode(', ', $missing) . '</b> (panelda PHP kengaytmalarida yoqing)') : $this->ok('Kengaytmalar: curl, dom, zip, gd, mbstring, pdo_sqlite, openssl — bor');
        if (function_exists('gd_info')) {
            $gi = gd_info();
            (!empty($gi['JPEG Support']) && !empty($gi['PNG Support'])) ? $this->ok('GD: JPEG va PNG qo\'llab-quvvatlanadi') : $this->bad('GD: JPEG/PNG qo\'llab-quvvatlanmaydi (rasmlar ishlamaydi)');
        }
        $mem = ini_get('memory_limit');
        $bytes = self::bytes((string)$mem);
        ($bytes === -1 || $bytes >= 256 * 1048576) ? $this->ok("memory_limit = $mem") : ($bytes >= 128 * 1048576 ? $this->warn("memory_limit = $mem (katta ishlar uchun 256M tavsiya etiladi)") : $this->bad("memory_limit = $mem — juda kam (kamida 128M, yaxshisi 256M)"));
        $met = (int)ini_get('max_execution_time');
        ($met === 0 || $met >= 60) ? $this->ok('max_execution_time = ' . ($met ?: 'cheklovsiz')) : $this->warn("max_execution_time = {$met}s — config.php da ENGINE_TICK_SECONDS ni " . max(10, $met - 12) . ", ENGINE_HARD_SECONDS ni " . max(20, $met - 5) . " qiling");
        $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
        $important = array_intersect($disabled, ['curl_exec', 'curl_init', 'fopen', 'file_put_contents', 'proc_open']);
        $important ? $this->bad('O\'chirilgan zarur funksiyalar: ' . implode(', ', $important)) : $this->ok('Zarur funksiyalar yoqilgan' . (in_array('exec', $disabled, true) ? ' (exec o\'chiq — kerak emas)' : ''));
        // PDF (tahrirlovchida «PDF sifatida»): LibreOffice yoki GD (shriftlar assets/fonts da)
        if (class_exists('SlidePdf')) {
            $eng = SlidePdf::engine();
            $eng === 'soffice' ? $this->ok('PDF: LibreOffice orqali (matn tanlanadi, eng aniq)')
                : ($eng === 'gd' ? $this->ok('PDF: GD orqali (slaydlar rasm sifatida; LibreOffice shart emas)')
                    : $this->warn('PDF yaratish ishlamaydi — assets/fonts papkasi (Carlito-Regular.ttf) va GD freetype kerak; tahrirlovchida PDF tugmasi xato beradi'));
        }
        $up = ini_get('upload_max_filesize');
        self::bytes((string)$up) >= 8 * 1048576 ? $this->ok("upload_max_filesize = $up (tahrirlovchida rasm yuklash uchun)") : $this->warn("upload_max_filesize = $up — tahrirlovchida 8MB rasm yuklash uchun 10M qiling");
    }

    private function storage(): void
    {
        $this->head('Papkalar va ruxsatlar');
        $dirs = ['storage' => dirname(DB_PATH), 'generated' => GENERATED_DIR, 'editor' => EDITOR_STORAGE_DIR];
        foreach ($dirs as $label => $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $f = rtrim($dir, '/') . '/.write_test_' . bin2hex(random_bytes(3));
            if (is_dir($dir) && @file_put_contents($f, 'x') !== false) {
                @unlink($f);
                $this->ok("$label/ yozish mumkin");
            } else {
                $this->bad("$label/ ga YOZIB BO'LMAYDI — papkaga yozish ruxsati (chmod 775/755) bering");
            }
        }
        $free = @disk_free_space(dirname(DB_PATH));
        if ($free !== false) {
            $mb = (int)($free / 1048576);
            $mb > 500 ? $this->ok("Bo'sh joy: {$mb} MB") : ($mb > 100 ? $this->warn("Bo'sh joy kam: {$mb} MB") : $this->bad("Bo'sh joy juda kam: {$mb} MB"));
        }
    }

    private function database(): void
    {
        $this->head('Ma\'lumotlar bazasi');
        $pdo = db();
        $mode = (string)$pdo->query('PRAGMA journal_mode')->fetchColumn();
        strtolower($mode) === 'wal' ? $this->ok('SQLite ' . $pdo->query('select sqlite_version()')->fetchColumn() . ', rejim: WAL (parallel ishlash uchun to\'g\'ri)') : $this->warn("Jurnal rejimi: $mode (WAL emas — fayl tizimi WAL ni qo'llamasligi mumkin, parallel ishlar sekinlashishi mumkin)");
        $size = round(filesize(DB_PATH) / 1048576, 1);
        $this->ok("Baza hajmi: {$size} MB, sxema v" . (int)$pdo->query('PRAGMA user_version')->fetchColumn());
        $t0 = microtime(true);
        $pdo->exec('BEGIN IMMEDIATE');
        $pdo->exec('COMMIT');
        $ms = (int)((microtime(true) - $t0) * 1000);
        $ms < 200 ? $this->ok("Yozish tezligi: {$ms} ms") : $this->warn("Yozish sekin: {$ms} ms (disk band bo'lishi mumkin)");
        $rows = $pdo->query("SELECT status, COUNT(*) c FROM jobs GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($rows) {
            $this->ok('Buyurtmalar: ' . implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($rows), $rows)));
        }
        $stuck = (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE status IN ('pending','processing') AND updated_at < " . (time() - 900))->fetchColumn();
        $stuck > 0 ? $this->warn("$stuck ta buyurtma 15 daqiqadan beri yangilanmagan (to'xtab qolgan bo'lishi mumkin — webhookka biror xabar yuboring, tizim uyg'onadi)") : $this->ok("To'xtab qolgan buyurtma yo'q");
    }

    private function engine(): void
    {
        $this->head('Ish mexanizmi (cron kerak emas)');
        $base = Engine::baseUrl();
        if ($base === '') {
            $this->bad('PUBLIC_BASE_URL (yoki ENGINE_BASE_URL) config.php da yozilmagan — ishlar o\'z-o\'zidan ishga tushmaydi');
            return;
        }
        $this->ok("Manzil: $base");
        $t0 = microtime(true);
        $ch = curl_init($base . '/tick.php');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['k' => Engine::secret(), 'probe' => 1]), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6]);
        $body = (string)curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ms = (int)((microtime(true) - $t0) * 1000);
        if ($errno === 0 && $code === 200 && trim($body) === 'ok') {
            $this->ok("tick.php o'z-o'zini chaqira oladi ({$ms} ms) — ishlar avtomatik ketadi");
        } elseif ($errno !== 0) {
            $this->bad("Bot o'z saytiga ulana olmadi: " . Telegram::escape($err) . " — hostingda o'z-o'ziga so'rov (loopback) bloklangan bo'lishi mumkin; ENGINE_BASE_URL ni tekshiring yoki administratorga ayting");
        } else {
            $this->bad("tick.php kutilmagan javob berdi (HTTP $code). Fayl yuklanganini va maxfiy kalit (BOT_TOKEN) o'zgarmaganini tekshiring");
        }
        $mode = Engine::kvGet('kick_mode');
        $this->ok('Oxirgi ishga tushirish usuli: ' . ($mode['v'] ?? 'hali ishlatilmagan'));
        $this->ok('Faol tick: ' . Engine::activeTicks() . '/' . Engine::maxParallel() . ', navbatdagi ish: ' . Engine::runnableCount());
        $this->ok('Sozlama: parallel ' . Engine::maxParallel() . ', tick ' . Engine::tickBudget() . 's, chegara ' . Engine::hardLimit() . 's, bir vaqtda ' . Engine::c('ENGINE_MAX_ACTIVE_JOBS', 8) . ' ta ish');
    }

    private function telegram(): void
    {
        $this->head('Telegram');
        $me = Telegram::call('getMe', []);
        !empty($me['ok']) ? $this->ok('Bot: @' . ($me['result']['username'] ?? '?')) : $this->bad('Telegram API ga ulanib bo\'lmadi yoki BOT_TOKEN noto\'g\'ri');
        $wh = Telegram::call('getWebhookInfo', []);
        $r = $wh['result'] ?? [];
        if (empty($r['url'])) {
            $this->bad('Webhook o\'rnatilmagan! setWebhook qiling');
        } else {
            $this->ok('Webhook: ' . Telegram::escape(preg_replace('/\?.*/', '', (string)$r['url'])));
            if (!empty($r['last_error_message'])) {
                $age = time() - (int)($r['last_error_date'] ?? 0);
                $age < 3600 ? $this->warn('Oxirgi webhook xatosi (' . (int)($age / 60) . ' daq oldin): ' . Telegram::escape((string)$r['last_error_message'])) : $this->ok('Webhook xatolari yo\'q (eskilari e\'tiborga olinmadi)');
            }
            $pending = (int)($r['pending_update_count'] ?? 0);
            $pending > 20 ? $this->warn("Navbatda $pending ta kutayotgan yangilanish (server sekin javob beryapti)") : $this->ok("Kutayotgan yangilanishlar: $pending");
            if (WEBHOOK_SECRET === '') {
                $this->warn("WEBHOOK_SECRET qo'yilmagan — soxta so'rovlardan himoya yo'q (config.php va setWebhook secret_token)");
            }
        }
    }

    private function ai(): void
    {
        $this->head('AI kalitlar');
        $s = AIClient::status();
        if ($s['total'] === 0) {
            $this->bad("Birorta ham AI kalit sozlanmagan (config.php → AI_PROVIDERS). Namuna kalitlar (…_API_KEY_1) hisobga olinmaydi");
            return;
        }
        $line = "Marshrutlar: {$s['total']} ta — tayyor {$s['ready']}, vaqtincha limitda {$s['cooling']}, yaroqsiz {$s['dead']}";
        $s['ready'] > 0 ? $this->ok($line) : ($s['cooling'] > 0 ? $this->warn($line . ' (limit tugagach avtomatik tiklanadi)') : $this->bad($line));
        foreach (array_slice($s['routes'], 0, 8) as $r) {
            $icon = $r['state'] === 'ready' ? '▫️' : ($r['state'] === 'cooling' ? '⏳' : '⛔');
            $this->lines[] = "$icon {$r['provider']}/" . Telegram::escape((string)$r['model']) . " {$r['key']}: ok {$r['ok']}, xato {$r['err']}" . ($r['wait'] ? ", {$r['wait']}s kutadi" : '') . ($r['state'] === 'dead' ? ' — kalitni almashtiring' : '');
        }
    }

    private function exposure(): void
    {
        $this->head('Maxfiy fayllar ochiq emasmi?');
        if (PUBLIC_BASE_URL === '') {
            $this->warn('PUBLIC_BASE_URL sozlanmagan — tekshirib bo\'lmadi');
            return;
        }
        $base = rtrim(PUBLIC_BASE_URL, '/');
        $paths = ['storage/' . basename(DB_PATH) => 'Baza', 'storage/logs/app.log' => 'Log', 'install.sql' => 'Baza sxemasi', 'config.php' => 'config.php', 'lib/Engine.php' => 'Ichki kod', 'lib/editor_ui/main.js' => 'Tahrirlovchi manbasi'];
        $leaks = 0;
        foreach ($paths as $p => $label) {
            $ch = curl_init($base . '/' . $p);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_FOLLOWLOCATION => false, CURLOPT_RANGE => '0-200']);
            $body = (string)curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $leak = ($code === 200 || $code === 206) && $body !== '' && ($p !== 'config.php' || strpos($body, '<?php') !== false);
            if ($leak) {
                $leaks++;
                $this->bad("OCHIQ: /$p ($label)");
            }
        }
        $leaks ? $this->lines[] = "⚠️ Server sozlamasini tuzating: Apache'da .htaccess ishlashi (AllowOverride All) kerak; Nginx bo'lsa nginx.conf.example qoidalarini qo'shing." : $this->ok('Baza, log va ichki kod internetdan ochilmaydi');
    }

    private function wallClock(int $sec): void
    {
        $this->lines[] = "\n<b>Vaqt sinovi ({$sec}s)</b>";
        $base = Engine::baseUrl();
        $t0 = microtime(true);
        $ch = curl_init($base . '/tick.php');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['k' => Engine::secret(), 'probe' => $sec]), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $sec + 15, CURLOPT_CONNECTTIMEOUT => 8]);
        $body = trim((string)curl_exec($ch));
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $took = (int)(microtime(true) - $t0);
        if ($body === 'ok' && $code === 200) {
            $this->ok("{$took}s davom etgan so'rov uzilmadi. ENGINE_HARD_SECONDS ni " . max(20, $sec - 8) . " gacha qo'yish xavfsiz");
        } else {
            $this->bad("So'rov {$took}s da uzildi (HTTP $code). Hosting uzoq so'rovlarni uzadi: ENGINE_HARD_SECONDS ni " . max(20, $took - 8) . ", ENGINE_TICK_SECONDS ni " . max(10, $took - 20) . " qiling");
        }
    }

    private static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '-1') {
            return -1;
        }
        $n = (int)$v;
        switch (strtolower(substr($v, -1))) {
            case 'g':
                return $n * 1073741824;
            case 'm':
                return $n * 1048576;
            case 'k':
                return $n * 1024;
        }
        return $n;
    }
}
