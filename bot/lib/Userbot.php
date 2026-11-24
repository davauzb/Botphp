<?php

require_once __DIR__ . '/Telegram.php';
require_once __DIR__ . '/TopupHelpers.php';
require_once __DIR__ . '/HumoAuto.php';

/**
 * 👤 USERBOT (HUMO avto uchun): Telegram akkauntga (Telethon orqali) kirib, @HUMOcardbot chatidagi yangi «➕ … UZS»
 * xabarlarni o'qiydi va HumoAuto::ingest() ga beradi — Telegram Business/Premium kerak emas.
 *
 * Hostingda Python bo'lmasa — «portable» Python (python-build-standalone) storage/userbot/ ga avtomatik yuklanadi,
 * Telethon pip bilan o'sha yerga o'rnatiladi. Hammasi admin panel → Tizim → «👤 Userbot» dan boshqariladi:
 * O'rnatish → api_id/api_hash/telefon → kod → (2FA parol) → tayyor.
 *
 * Xavfsizlik: api_hash va sessiya fayli faqat serverda (storage/ — internetdan yopiq), maxfiy qiymatlar Python'ga STDIN orqali
 * beriladi (jarayonlar ro'yxatida ko'rinmaydi). Faqat HUMO botining (bot ekani tekshiriladi) chati o'qiladi.
 * Kirishni bekor qilish: panelda «Chiqish» yoki Telegram → Sozlamalar → Qurilmalar → «SlayderAi Pay» ni o'chirish.
 */
class Userbot
{
    public const PY_FALLBACK = 'https://github.com/astral-sh/python-build-standalone/releases/download/20241016/cpython-3.12.7%2B20241016-x86_64-unknown-linux-gnu-install_only.tar.gz';
    private const POLL_EVERY = 35;            // soniya (kutilayotgan HUMO so'rovi bo'lsa)

    public static function base(): string
    {
        return rtrim(dirname(DB_PATH), '/') . '/userbot';
    }

    public static function py(): string
    {
        return self::base() . '/python/bin/python3';
    }

    private static function get(string $k): string
    {
        return (string)(Settings::raw('UB_' . $k) ?? '');
    }

    private static function put(string $k, string $v, int $admin = 0): void
    {
        Settings::setRaw('UB_' . $k, $v, $admin);
    }

    public static function installed(): bool
    {
        return @is_executable(self::py()) && is_dir(self::base() . '/pylib/telethon');
    }

    public static function active(): bool
    {
        return self::get('AUTH') === '1' && self::get('ENABLED') !== '0' && self::installed();
    }

    // ------------------------------------------------------------------ o'rnatish (fonda)

    public static function pythonUrl(): string
    {
        $ch = curl_init('https://api.github.com/repos/astral-sh/python-build-standalone/releases/latest');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'SlayderAi-installer', CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json']]);
        $d = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        foreach ((array)($d['assets'] ?? []) as $a) {
            if (preg_match('/^cpython-3\.12\.\d+\+\d+-x86_64-unknown-linux-gnu-install_only\.tar\.gz$/', (string)($a['name'] ?? ''))) {
                return (string)$a['browser_download_url'];
            }
        }
        return self::PY_FALLBACK;
    }

    public static function install(int $admin): array
    {
        if (php_uname('m') !== 'x86_64') {
            return ['ok' => false, 'msg' => 'Faqat x86_64 serverlar qo\'llab-quvvatlanadi'];
        }
        $b = self::base();
        if (!is_dir($b) && !@mkdir($b, 0700, true)) {
            return ['ok' => false, 'msg' => "storage/userbot papkasini yaratib bo'lmadi"];
        }
        @file_put_contents($b . '/.htaccess', "Require all denied\nDeny from all\n");
        if (is_file($b . '/install.lock') && time() - filemtime($b . '/install.lock') < 900) {
            return ['ok' => false, 'msg' => "O'rnatish allaqachon ketmoqda"];
        }
        $url = self::pythonUrl();
        $sh = "set -e\ncd " . escapeshellarg($b) . "\ntouch install.lock\necho \"[1/3] Python yuklanmoqda…\"\n"
            . "if command -v curl >/dev/null 2>&1; then curl -fsSL " . escapeshellarg($url) . " -o py.tgz; else wget -q -O py.tgz " . escapeshellarg($url) . "; fi\n"
            . "echo \"[2/3] Ochilmoqda…\"\nrm -rf python && tar -xzf py.tgz && rm -f py.tgz\n"
            . "echo \"[3/3] Telethon o'rnatilmoqda…\"\n"
            . "rm -rf pylib dl\n"
            . "./python/bin/python3 -m pip install -q --disable-pip-version-check --no-warn-script-location --no-cache-dir --no-deps --target pylib 'telethon>=1.36,<2' 'rsa>=4' 'pyasn1>=0.4' 2>&1 | tail -3\n"
            // pyaes faqat manba ko'rinishida — kompilyatsiyasiz to'g'ridan-to'g'ri nusxalanadi (setuptools kerak bo'lmasin)
            . "./python/bin/python3 -m pip download -q --disable-pip-version-check --no-deps --no-binary :all: -d dl pyaes==1.6.1 2>&1 | tail -2\n"
            . "tar -xzf dl/pyaes-1.6.1.tar.gz -C dl && cp -r dl/pyaes-1.6.1/pyaes pylib/ && rm -rf dl\n"
            . "./python/bin/python3 -c 'import sys; sys.path.insert(0,\"pylib\"); import telethon; print(\"Telethon\", telethon.__version__)'\n"
            . "rm -f install.lock\necho DONE\n";
        file_put_contents($b . '/install.sh', $sh);
        @unlink($b . '/install.log');
        $cmd = 'cd ' . escapeshellarg($b) . ' && nohup sh install.sh > install.log 2>&1 &';
        $p = @proc_open(['sh', '-c', $cmd], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            return ['ok' => false, 'msg' => "Fon jarayonini ishga tushirib bo'lmadi (proc_open)"];
        }
        foreach ($pipes as $x) {
            @fclose($x);
        }
        @proc_close($p);
        AdminSecurity::audit('userbot_install', $admin);
        return ['ok' => true, 'msg' => "O'rnatish boshlandi (1–3 daqiqa). Holat avtomatik yangilanadi."];
    }

    public static function installLog(): string
    {
        $f = self::base() . '/install.log';
        if (!is_file($f)) {
            return '';
        }
        $t = (string)file_get_contents($f);
        return mb_substr($t, -1200);
    }

    // ------------------------------------------------------------------ Python bilan ishlash

    /** ub.py ni ishga tushiradi; maxfiy qiymatlar STDIN orqali. */
    private static function call(array $data, int $timeout = 40): array
    {
        if (!self::installed()) {
            return ['ok' => false, 'error' => "Python/Telethon o'rnatilmagan"];
        }
        $sess = self::get('SESSION');
        if ($sess === '') {
            $sess = 's_' . bin2hex(random_bytes(8));
            self::put('SESSION', $sess);
        }
        $data += ['api_id' => self::get('API_ID'), 'api_hash' => self::get('API_HASH'), 'session' => self::base() . '/' . $sess, 'deadline' => max(8, $timeout - 6)];
        $env = ['PYTHONPATH' => self::base() . '/pylib', 'HOME' => self::base(), 'PYTHONIOENCODING' => 'utf-8', 'PATH' => '/usr/bin:/bin'];
        $p = @proc_open([self::py(), dirname(__FILE__) . '/userbot/ub.py'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::base(), $env);
        if (!is_resource($p)) {
            return ['ok' => false, 'error' => 'proc_open ishlamadi'];
        }
        fwrite($pipes[0], json_encode($data, JSON_UNESCAPED_UNICODE));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = $err = '';
        $end = microtime(true) + $timeout;
        while (true) {
            $out .= (string)fread($pipes[1], 65536);
            $err .= (string)fread($pipes[2], 65536);
            $st = proc_get_status($p);
            if (!$st['running']) {
                $out .= (string)stream_get_contents($pipes[1]);
                $err .= (string)stream_get_contents($pipes[2]);
                break;
            }
            if (microtime(true) > $end) {
                @proc_terminate($p, 9);
                $err .= ' timeout';
                break;
            }
            usleep(80000);
        }
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        @proc_close($p);
        foreach (array_reverse(preg_split('/\R/', trim($out)) ?: []) as $ln) {
            $j = json_decode($ln, true);
            if (is_array($j)) {
                return $j;
            }
        }
        logError('userbot', 'javob yo\'q: ' . mb_substr(trim($err), 0, 300));
        return ['ok' => false, 'error' => 'Python javob bermadi: ' . mb_substr(trim($err), -160)];
    }

    // ------------------------------------------------------------------ kirish (admin panel)

    public static function sendCode(int $admin, string $apiId, string $apiHash, string $phone): array
    {
        $apiId = preg_replace('/\D+/', '', $apiId);
        $apiHash = strtolower(trim($apiHash));
        $phone = '+' . preg_replace('/\D+/', '', $phone);
        if ($apiId === '' || !preg_match('/^[0-9a-f]{32}$/', $apiHash) || strlen($phone) < 10) {
            return ['ok' => false, 'msg' => "api_id (raqam), api_hash (32 belgi) va telefonni (+998…) to'g'ri kiriting"];
        }
        self::put('API_ID', $apiId, $admin);
        self::put('API_HASH', $apiHash, $admin);
        self::put('PHONE', $phone, $admin);
        $r = self::call(['cmd' => 'send_code', 'phone' => $phone]);
        if (empty($r['ok'])) {
            return ['ok' => false, 'msg' => self::err($r)];
        }
        self::put('CODE_HASH', (string)$r['hash'], $admin);
        AdminSecurity::audit('userbot_code', $admin, substr($phone, 0, 6) . '…');
        return ['ok' => true, 'msg' => "Kod Telegram'ingizga yuborildi (Telegram ilovasidagi «Telegram» chatiga). Uni shu yerga kiriting."];
    }

    public static function signIn(int $admin, string $code): array
    {
        $code = preg_replace('/\D+/', '', $code);
        $r = self::call(['cmd' => 'sign_in', 'phone' => self::get('PHONE'), 'code' => $code, 'hash' => self::get('CODE_HASH')]);
        if (!empty($r['need_password'])) {
            return ['ok' => true, 'need_password' => true, 'msg' => "Akkauntda ikki bosqichli parol bor — uni kiriting"];
        }
        return self::finishLogin($admin, $r);
    }

    public static function password(int $admin, string $pwd): array
    {
        return self::finishLogin($admin, self::call(['cmd' => 'password', 'password' => $pwd]));
    }

    private static function finishLogin(int $admin, array $r): array
    {
        if (empty($r['ok'])) {
            return ['ok' => false, 'msg' => self::err($r)];
        }
        self::put('AUTH', '1', $admin);
        self::put('ENABLED', '1', $admin);
        self::put('NAME', (string)($r['name'] ?? ''), $admin);
        self::put('CODE_HASH', '', $admin);
        AdminSecurity::audit('userbot_login', $admin);
        return ['ok' => true, 'msg' => '✅ Userbot ulandi: ' . ($r['name'] ?? '')];
    }

    public static function logout(int $admin): array
    {
        if (self::installed() && self::get('AUTH') === '1') {
            self::call(['cmd' => 'logout'], 25);
        }
        $s = self::get('SESSION');
        if ($s !== '') {
            @unlink(self::base() . '/' . $s . '.session');
        }
        foreach (['AUTH', 'SESSION', 'CODE_HASH', 'NAME', 'API_HASH', 'LAST_ID'] as $k) {
            self::put($k, '', $admin);
        }
        AdminSecurity::audit('userbot_logout', $admin);
        return ['ok' => true, 'msg' => 'Userbot akkauntdan chiqdi, sessiya o\'chirildi'];
    }

    public static function toggle(int $admin, bool $on): array
    {
        self::put('ENABLED', $on ? '1' : '0', $admin);
        return ['ok' => true, 'msg' => $on ? 'Yoqildi' : "To'xtatildi"];
    }

    private static function err(array $r): string
    {
        $e = (string)($r['error'] ?? 'Xatolik');
        return $e === 'flood' ? 'Telegram vaqtincha cheklov qo\'ydi — ' . (int)($r['wait'] ?? 60) . ' soniyadan keyin urinib ko\'ring' : $e;
    }

    public static function status(): array
    {
        return ['installed' => self::installed(), 'installing' => is_file(self::base() . '/install.lock'), 'log' => self::installLog(),
            'auth' => self::get('AUTH') === '1', 'enabled' => self::get('ENABLED') !== '0', 'name' => self::get('NAME'),
            'phone' => self::get('PHONE') !== '' ? substr(self::get('PHONE'), 0, 6) . '•••' . substr(self::get('PHONE'), -2) : '',
            'has_api' => self::get('API_ID') !== '' && self::get('API_HASH') !== '', 'code_sent' => self::get('CODE_HASH') !== '',
            'last_poll' => (int)(self::kv('ub_poll')['ts'] ?? 0), 'last_result' => (string)(self::kv('ub_poll')['v'] ?? '')];
    }

    /** O'qiladigan bank botlari: config'dagi HUMO_BOT_USERNAME + Sozlamalardagi UB_BOTS (vergul bilan, 5 tagacha). */
    public static function peers(): array
    {
        $list = [ltrim((string)HUMO_BOT_USERNAME, '@')];
        foreach (preg_split('/[\s,;]+/u', (string)Engine::c('UB_BOTS', '')) ?: [] as $u) {
            $u = ltrim(trim($u), '@');
            if (preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $u) && preg_match('/bot$/i', $u)) {
                $list[] = $u;
            }
        }
        $out = [];
        foreach ($list as $u) {
            if ($u !== '' && !isset($out[strtolower($u)])) {
                $out[strtolower($u)] = '@' . $u;
            }
        }
        return array_slice(array_values($out), 0, 6);
    }

    private static function lastKey(string $peer, int $i): string
    {
        return $i === 0 ? 'LAST_ID' : 'LAST_' . strtoupper(substr(preg_replace('/\W/', '', $peer), 0, 32));
    }

    /** Sinov: oxirgi xabarlarni o'qib ko'radi (hech narsa hisoblamaydi). */
    public static function test(): array
    {
        $peers = self::peers();
        $r = self::call(['cmd' => 'fetch_multi', 'peers' => array_fill_keys($peers, 0), 'limit' => 5]);
        if (empty($r['ok'])) {
            return ['ok' => false, 'msg' => self::err($r)];
        }
        $parts = [];
        $anyOk = false;
        foreach ($peers as $peer) {
            $pr = (array)($r['peers'][$peer] ?? []);
            if (empty($pr['ok'])) {
                $parts[] = "❌ $peer: " . self::err($pr);
                continue;
            }
            $anyOk = true;
            $n = count((array)$pr['messages']);
            $inc = 0;
            foreach ((array)$pr['messages'] as $m) {
                $inc += HumoAuto::parse((string)$m['text']) !== null ? 1 : 0;
            }
            $parts[] = "✅ $peer: oxirgi $n ta xabar, $inc tasi kirim";
        }
        return ['ok' => $anyOk, 'msg' => implode("\n", $parts)];
    }

    // ------------------------------------------------------------------ fon: o'qish

    private static function kv(string $k): array
    {
        try {
            $st = db()->prepare('SELECT v, ts FROM engine_kv WHERE k = ?');
            $st->execute([$k]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function kvSet(string $k, string $v): void
    {
        db()->prepare('INSERT INTO engine_kv (k, v, ts) VALUES (?, ?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v, ts = excluded.ts')->execute([$k, $v, time()]);
    }

    /** Kutilayotgan HUMO so'rovi bormi (tick zanjiri shu vaqt davom etadi). */
    public static function wantsPoll(): bool
    {
        if (!self::active() || !HumoAuto::enabled()) {
            return false;
        }
        try {
            $st = db()->prepare("SELECT 1 FROM topups WHERE method = 'humo' AND status = 'pending' AND created_at > ? LIMIT 1");
            $st->execute([time() - ((int)HUMO_TTL_MINUTES + 30) * 60]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function nextPollIn(): int
    {
        $last = (int)(self::kv('ub_poll')['ts'] ?? 0);
        return max(3, self::POLL_EVERY - (time() - $last));
    }

    /** Maintenance::run() dan: kutilayotgan so'rov bo'lsa har ~35 s, bo'lmasa har 10 daqiqada bir marta o'qiydi. */
    /** Panel: «Hozir o'qish» — kutmasdan darhol o'qiydi va mos to'lovlarni tasdiqlaydi. */
    public static function pollNow(): array
    {
        if (!self::active()) {
            return ['ok' => false, 'msg' => 'Userbot ulanmagan yoki to\'xtatilgan'];
        }
        $before = (int)db()->query("SELECT COUNT(*) FROM topups WHERE method = 'humo' AND status = 'approved'")->fetchColumn();
        self::poll(microtime(true) + 45, true);
        $after = (int)db()->query("SELECT COUNT(*) FROM topups WHERE method = 'humo' AND status = 'approved'")->fetchColumn();
        $res = (string)(self::kv('ub_poll')['v'] ?? '');
        return ['ok' => !str_starts_with($res, 'xato'), 'msg' => ($after > $before ? '✅ ' . ($after - $before) . " ta to'lov tasdiqlandi · " : '') . $res];
    }

    public static function poll(float $until, bool $force = false): void
    {
        if (!self::active() || !HumoAuto::enabled() || $until - microtime(true) < 14) {
            return;
        }
        $last = (int)(self::kv('ub_poll')['ts'] ?? 0);
        $gap = $force ? 0 : (self::wantsPoll() ? self::POLL_EVERY : 600);
        if (time() - $last < $gap) {
            return;
        }
        $lock = db()->prepare("UPDATE engine_kv SET ts = ? WHERE k = 'ub_poll' AND ts = ?");
        $lock->execute([time(), $last]);
        if ($last > 0 && !$lock->rowCount() && !$force) {
            return;                                   // boshqa tick allaqachon o'qiyapti
        }
        if ($last === 0) {
            self::kvSet('ub_poll', 'start');
        }
        $peers = self::peers();
        $mins = [];
        foreach ($peers as $i => $peer) {
            $mins[$peer] = (int)self::get(self::lastKey($peer, $i));
        }
        $r = self::call(['cmd' => 'fetch_multi', 'peers' => $mins, 'limit' => 20], (int)max(12, min(35, $until - microtime(true) - 2)));
        if (empty($r['ok'])) {
            self::kvSet('ub_poll', 'xato: ' . mb_substr(self::err($r), 0, 120));
            if (($r['error'] ?? '') === 'not_authorized') {
                self::put('AUTH', '0');
                Telegram::notifyAdmin("👤 Userbot akkauntdan chiqarilgan (sessiya bekor qilingan). Admin panel → Tizim → Userbot orqali qayta kiring.");
            }
            return;
        }
        $total = 0;
        $errs = [];
        foreach ($peers as $i => $peer) {
            $pr = (array)($r['peers'][$peer] ?? []);
            if (empty($pr['ok'])) {
                $errs[] = $peer;
                continue;
            }
            $minId = $mins[$peer];
            $msgs = array_reverse((array)$pr['messages']);   // eskidan yangiga
            $max = $minId;
            foreach ($msgs as $m) {
                $max = max($max, (int)$m['id']);
                if ($minId === 0 && (int)$m['date'] < time() - 3 * 3600) {
                    continue;                             // birinchi ishga tushishda eski xabarlarni hisoblamaymiz
                }
                HumoAuto::ingest((string)$m['text'], (int)$m['date'], (int)($pr['peer_id'] ?? 0), (int)$m['id'], $i === 0 ? 'userbot' : 'userbot:' . $peer);
            }
            if ($max > $minId) {
                self::put(self::lastKey($peer, $i), (string)$max);
            }
            $total += count($msgs);
        }
        if ($errs) {
            self::kvSet('ub_poll', 'ok · ' . $total . ' ta yangi xabar · ⚠️ o\'qilmadi: ' . implode(', ', $errs));
            return;
        }
        self::kvSet('ub_poll', 'ok · ' . $total . ' ta yangi xabar' . (count($peers) > 1 ? ' (' . count($peers) . ' ta bot)' : ''));
    }
}
