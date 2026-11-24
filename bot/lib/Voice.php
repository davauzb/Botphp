<?php

/**
 * 🔊 MATNNI OVOZGA AYLANTIRISH (o'zbek, rus, ingliz).
 *
 * Asosiy dvigatel — Microsoft Edge neyron ovozlari (uz-UZ-Madina / Sardor — tabiiy o'zbekcha talaffuz), Python «edge-tts»
 * paketi orqali. Python userbot bilan umumiy (storage/userbot/python); paket storage/voice/pylib ga o'rnatiladi
 * (admin panel → Tizim → «🔊 Ovoz moduli» → «O'rnatish»). Microsoft protokolni o'zgartirsa — o'sha tugma paketni yangilaydi.
 * Zaxira dvigatel — Gemini TTS (kalit bo'lsa; o'zbekcha talaffuzi sustroq).
 * ffmpeg bo'lsa — natija OGG/Opus «ovozli xabar» bo'lib keladi, bo'lmasa — MP3 audio (Telegram ichida ijro etiladi).
 *
 * Narx: kuniga TTS_FREE_CHARS belgi bepul, undan keyin har 1000 belgi — TTS_PRICE_1000 so'm (balansdan). Adminlar uchun bepul.
 */
class Voice
{
    public const MENU = '🔊 Matnni ovozga';

    /** kalit => [Edge ovozi, ko'rinadigan nom, til] */
    public const VOICES = [
        'madina' => ['uz-UZ-MadinaNeural', "👩 Madina (o'zbek)", 'uz'],
        'sardor' => ['uz-UZ-SardorNeural', "👨 Sardor (o'zbek)", 'uz'],
        'svetlana' => ['ru-RU-SvetlanaNeural', '👩 Svetlana (rus)', 'ru'],
        'dmitry' => ['ru-RU-DmitryNeural', '👨 Dmitriy (rus)', 'ru'],
        'jenny' => ['en-US-JennyNeural', '👩 Jenny (ingliz)', 'en'],
        'guy' => ['en-US-GuyNeural', '👨 Guy (ingliz)', 'en'],
    ];

    public const RATES = ['slow' => ['-15%', '🐢 Sekin'], 'norm' => ['+0%', '▶️ Oddiy'], 'fast' => ['+15%', '⚡ Tez']];

    // ------------------------------------------------------------------ sozlamalar

    public static function maxChars(): int
    {
        return max(200, min(10000, (int)Engine::c('TTS_MAX_CHARS', 4000)));
    }

    public static function freeChars(): int
    {
        return max(0, (int)Engine::c('TTS_FREE_CHARS', 2000));
    }

    public static function price1000(): int
    {
        return max(0, (int)Engine::c('TTS_PRICE_1000', 500));
    }

    public static function base(): string
    {
        return rtrim(dirname(DB_PATH), '/') . '/voice';
    }

    public static function installed(): bool
    {
        return @is_executable(Userbot::py()) && is_dir(self::base() . '/pylib/edge_tts');
    }

    /** 'edge' | 'gemini' | '' */
    public static function engine(): string
    {
        if (self::installed()) {
            return 'edge';
        }
        return (class_exists('VideoDeck') && VideoDeck::ttsKey() !== '') ? 'gemini' : '';
    }

    public static function enabled(): bool
    {
        return (bool)Engine::c('TTS_ENABLED', true) && self::engine() !== '';
    }

    // ------------------------------------------------------------------ o'rnatish (fonda, admin panel orqali)

    public static function install(int $admin): array
    {
        if (php_uname('m') !== 'x86_64') {
            return ['ok' => false, 'msg' => "Faqat x86_64 serverlar qo'llab-quvvatlanadi"];
        }
        $b = self::base();
        if (!is_dir($b) && !@mkdir($b, 0700, true)) {
            return ['ok' => false, 'msg' => "storage/voice papkasini yaratib bo'lmadi"];
        }
        @file_put_contents($b . '/.htaccess', "Require all denied\nDeny from all\n");
        if (is_file($b . '/install.lock') && time() - filemtime($b . '/install.lock') < 900) {
            return ['ok' => false, 'msg' => "O'rnatish allaqachon ketmoqda"];
        }
        $ub = Userbot::base();
        if (!is_dir($ub) && !@mkdir($ub, 0700, true)) {
            return ['ok' => false, 'msg' => "storage/userbot papkasini yaratib bo'lmadi"];
        }
        @file_put_contents($ub . '/.htaccess', "Require all denied\nDeny from all\n");
        $py = Userbot::py();
        $sh = "set -e\ncd " . escapeshellarg($b) . "\ntouch install.lock\n";
        if (!@is_executable($py)) {                    // Python yo'q — userbot bilan umumiy portable Python yuklanadi
            $url = Userbot::pythonUrl();
            $sh .= "echo \"[1/3] Python yuklanmoqda…\"\ncd " . escapeshellarg($ub) . "\n"
                . "if command -v curl >/dev/null 2>&1; then curl -fsSL " . escapeshellarg($url) . " -o py.tgz; else wget -q -O py.tgz " . escapeshellarg($url) . "; fi\n"
                . "echo \"[2/3] Ochilmoqda…\"\nrm -rf python && tar -xzf py.tgz && rm -f py.tgz\ncd " . escapeshellarg($b) . "\n";
        } else {
            $sh .= "echo \"[1/3] Python bor (userbot bilan umumiy)\"\n";
        }
        $sh .= "echo \"[3/3] edge-tts o'rnatilmoqda…\"\nrm -rf pylib.new\n"
            . escapeshellarg($py) . " -m pip install -q --disable-pip-version-check --no-warn-script-location --no-cache-dir --only-binary :all: --target pylib.new 'edge-tts>=7' 2>&1 | tail -3\n"
            . "rm -rf pylib && mv pylib.new pylib\n"
            . escapeshellarg($py) . " -c 'import sys; sys.path.insert(0,\"pylib\"); import edge_tts; print(\"edge-tts\", getattr(edge_tts, \"__version__\", \"?\"))'\n"
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
        AdminSecurity::audit('voice_install', $admin);
        return ['ok' => true, 'msg' => "O'rnatish boshlandi (1–3 daqiqa). Holat avtomatik yangilanadi."];
    }

    public static function status(): array
    {
        $log = is_file(self::base() . '/install.log') ? mb_substr((string)file_get_contents(self::base() . '/install.log'), -1000) : '';
        $ver = '';
        if (self::installed()) {
            $r = self::python(['cmd' => 'version'], 20);
            $ver = (string)($r['version'] ?? '');
        }
        return [
            'python' => @is_executable(Userbot::py()), 'installed' => self::installed(), 'version' => $ver,
            'installing' => is_file(self::base() . '/install.lock') && time() - filemtime(self::base() . '/install.lock') < 900,
            'log' => $log, 'ffmpeg' => class_exists('VideoDeck') && VideoDeck::ffmpeg() !== null,
            'gemini' => class_exists('VideoDeck') && VideoDeck::ttsKey() !== '', 'engine' => self::engine(),
            'enabled' => (bool)Engine::c('TTS_ENABLED', true),
            'today' => self::statsToday(),
        ];
    }

    private static function statsToday(): array
    {
        try {
            $st = db()->prepare("SELECT COUNT(*), COALESCE(SUM(CAST(v AS INTEGER)), 0) FROM engine_kv WHERE k LIKE ?");
            $st->execute(['ttsd:%:' . self::day()]);
            [$users, $chars] = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
            return ['users' => (int)$users, 'chars' => (int)$chars];
        } catch (Throwable $e) {
            return ['users' => 0, 'chars' => 0];
        }
    }

    // ------------------------------------------------------------------ ovoz yaratish

    /** Python skriptini ishga tushirish (JSON STDIN orqali). */
    private static function python(array $data, int $timeout): array
    {
        $env = ['PYTHONPATH' => self::base() . '/pylib', 'PYTHONIOENCODING' => 'utf-8', 'HOME' => self::base(), 'PATH' => '/usr/bin:/bin'];
        foreach (['HTTPS_PROXY', 'https_proxy', 'SSL_CERT_FILE'] as $k) {
            if (($v = getenv($k)) !== false && $v !== '') {
                $env[$k] = $v;
            }
        }
        $p = @proc_open([Userbot::py(), __DIR__ . '/voice/tts.py'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::base(), $env);
        if (!is_resource($p)) {
            return ['ok' => false, 'error' => 'proc_open'];
        }
        fwrite($pipes[0], json_encode($data + ['deadline' => max(5, $timeout - 3)], JSON_UNESCAPED_UNICODE));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $end = microtime(true) + $timeout;
        while (true) {
            $out .= (string)fread($pipes[1], 65536);
            $err .= (string)fread($pipes[2], 65536);
            $st = proc_get_status($p);
            if (!$st['running']) {
                $out .= (string)stream_get_contents($pipes[1]);
                break;
            }
            if (microtime(true) > $end) {
                @proc_terminate($p, 9);
                $out = '';
                $err = 'timeout';
                break;
            }
            usleep(50000);
        }
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        @proc_close($p);
        $r = json_decode(trim($out), true);
        if (!is_array($r)) {
            return ['ok' => false, 'error' => $err !== '' ? mb_substr(trim($err), -200) : 'javob yo\'q'];
        }
        return $r;
    }

    /** Edge orqali MP3 fayl (videotaqdimot uchun). Muvaffaqiyatsiz bo'lsa false. */
    public static function edgeMp3(string $text, string $lang, string $dst): bool
    {
        if (!self::installed()) {
            return false;
        }
        $voice = ['uz' => 'uz-UZ-MadinaNeural', 'ru' => 'ru-RU-SvetlanaNeural', 'en' => 'en-US-JennyNeural'][$lang] ?? 'uz-UZ-MadinaNeural';
        $text = self::normalize($text, $lang);
        $r = self::python(['text' => $text, 'voice' => $voice, 'rate' => '-5%', 'out' => $dst], (int)min(120, 25 + mb_strlen($text) / 60));
        return !empty($r['ok']) && is_file($dst);
    }

    /** O'zbekcha matnni ovoz uchun tayyorlash: HTML/markdown belgilari olib tashlanadi, oʻ/gʻ va tutuq belgisi to'g'rilanadi. */
    public static function normalize(string $text, string $lang): string
    {
        $t = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/[*_#`~|>]+/u', ' ', $t) ?? $t;
        $t = preg_replace('/https?:\/\/\S+/u', '', $t) ?? $t;
        $t = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', '', $t) ?? $t;   // emoji o'qilmasin
        if ($lang === 'uz') {
            $t = UzSpeak::prepare($t);                                                // raqam, sana, foiz → so'z; pauzalar
            $t = preg_replace("/([OoGg])['’`‘ʼ]/u", '$1ʻ', $t) ?? $t;                 // oʻ, gʻ
            $t = preg_replace("/(\p{L})['’`‘](\p{L})/u", '$1ʼ$2', $t) ?? $t;          // tutuq belgisi: maʼno
        }
        $t = preg_replace("/[ \t]+/u", ' ', $t) ?? $t;
        $t = preg_replace("/\n{3,}/u", "\n\n", $t) ?? $t;
        return trim($t);
    }

    /**
     * Matn → audio fayl. Qaytadi: ['ok'=>true, 'file'=>yo'l, 'fmt'=>'ogg'|'mp3'|'wav', 'secs'=>int] yoki ['ok'=>false, 'error'=>...].
     */
    public static function synth(string $text, string $voiceKey = 'madina', string $rateKey = 'norm'): array
    {
        [$voice, , $lang] = self::VOICES[$voiceKey] ?? self::VOICES['madina'];
        $rate = self::RATES[$rateKey][0] ?? '+0%';
        $text = self::normalize($text, $lang);
        if (mb_strlen($text) < 2) {
            return ['ok' => false, 'error' => "O'qiladigan matn yo'q"];
        }
        $dir = rtrim(GENERATED_DIR, '/');
        $id = bin2hex(random_bytes(5));
        $engine = self::engine();
        $src = null;
        if ($engine === 'edge') {
            $mp3 = "$dir/tts_$id.mp3";
            $timeout = (int)min(150, 25 + mb_strlen($text) / 60);
            $r = self::python(['text' => $text, 'voice' => $voice, 'rate' => $rate, 'out' => $mp3], $timeout);
            if (!empty($r['ok']) && is_file($mp3)) {
                $src = $mp3;
            } else {
                logError('voice', 'edge-tts: ' . (string)($r['error'] ?? '?'));
                $engine = (class_exists('VideoDeck') && VideoDeck::ttsKey() !== '') ? 'gemini' : '';
            }
        }
        if ($src === null && $engine === 'gemini') {
            $pcm = "$dir/tts_$id.pcm";
            if (VideoDeck::tts(mb_substr($text, 0, 3000), $lang, $pcm)) {
                $wav = "$dir/tts_$id.wav";
                $data = (string)file_get_contents($pcm);
                @unlink($pcm);
                file_put_contents($wav, self::wavHeader(strlen($data)) . $data);
                $src = $wav;
            }
        }
        if ($src === null) {
            return ['ok' => false, 'error' => "Ovoz xizmati hozir javob bermadi"];
        }
        // ffmpeg bo'lsa — Telegram «ovozli xabar» formati (OGG/Opus)
        $ff = class_exists('VideoDeck') ? VideoDeck::ffmpeg() : null;
        if ($ff) {
            $ogg = "$dir/tts_$id.ogg";
            if (self::exec([$ff, '-y', '-loglevel', 'error', '-i', $src, '-vn', '-ac', '1', '-ar', '48000', '-c:a', 'libopus', '-b:a', '40k', $ogg], 60) && is_file($ogg) && filesize($ogg) > 500) {
                $secs = self::secsFromSize($src);
                @unlink($src);
                return ['ok' => true, 'file' => $ogg, 'fmt' => 'ogg', 'secs' => $secs];
            }
            @unlink($ogg);
        }
        return ['ok' => true, 'file' => $src, 'fmt' => str_ends_with($src, '.wav') ? 'wav' : 'mp3', 'secs' => self::secsFromSize($src)];
    }

    private static function secsFromSize(string $f): int
    {
        $n = (int)@filesize($f);
        return str_ends_with($f, '.wav') ? (int)round(max(0, $n - 44) / 48000) : (int)round($n * 8 / 48000);   // edge MP3 — 48 kbit/s
    }

    private static function wavHeader(int $len): string
    {
        return 'RIFF' . pack('V', 36 + $len) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16) . 'data' . pack('V', $len);
    }

    private static function exec(array $args, int $timeout): bool
    {
        $p = @proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            return false;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $end = microtime(true) + $timeout;
        $code = -1;
        while (true) {
            fread($pipes[1], 65536);
            fread($pipes[2], 65536);
            $st = proc_get_status($p);
            if (!$st['running']) {
                $code = (int)$st['exitcode'];
                break;
            }
            if (microtime(true) > $end) {
                @proc_terminate($p, 9);
                break;
            }
            usleep(50000);
        }
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        @proc_close($p);
        return $code === 0;
    }

    /** Audio faylni yuborish: OGG — ovozli xabar, MP3 — audio, WAV — fayl. */
    public static function send(int $chatId, array $res, string $caption, ?array $kb = null, string $title = 'Ovoz'): bool
    {
        $params = ['chat_id' => $chatId, 'caption' => $caption, 'parse_mode' => 'HTML'];
        if ($kb) {
            $params['reply_markup'] = json_encode(Telegram::colorize($kb));
        }
        $f = (string)$res['file'];
        if ($res['fmt'] === 'ogg') {
            $r = Telegram::call('sendVoice', $params + ['duration' => (int)$res['secs']], ['voice' => [$f, 'ovoz.ogg']]);
        } elseif ($res['fmt'] === 'mp3') {
            $r = Telegram::call('sendAudio', $params + ['duration' => (int)$res['secs'], 'title' => $title, 'performer' => 'SlayderAi'], ['audio' => [$f, 'ovoz.mp3']]);
        } else {
            $r = Telegram::call('sendDocument', $params, ['document' => [$f, 'ovoz.wav']]);
        }
        @unlink($f);
        return !empty($r['ok']);
    }

    // ------------------------------------------------------------------ bot oqimi

    private static function day(): string
    {
        return gmdate('Ymd', time() + 18000);
    }

    private static function prefs(int $tg): array
    {
        $kv = Engine::kvGet('tts:' . $tg);
        $p = $kv ? (json_decode((string)$kv['v'], true) ?: []) : [];
        if (!isset(self::VOICES[$p['v'] ?? ''])) {
            $lang = I18n::userLang($tg);
            $p['v'] = $lang === 'ru' ? 'svetlana' : ($lang === 'en' ? 'jenny' : 'madina');
        }
        if (!isset(self::RATES[$p['r'] ?? ''])) {
            $p['r'] = 'norm';
        }
        return $p;
    }

    private static function usedToday(int $tg): int
    {
        $kv = Engine::kvGet('ttsd:' . $tg . ':' . self::day());
        return (int)($kv['v'] ?? 0);
    }

    private static function keyboard(array $p): array
    {
        $rows = [];
        $row = [];
        foreach (self::VOICES as $k => [, $label]) {
            $row[] = ['text' => ($p['v'] === $k ? '✅ ' : '') . $label, 'callback_data' => 'tts:v:' . $k];
            if (count($row) === 2) {
                $rows[] = $row;
                $row = [];
            }
        }
        $rows[] = array_map(fn($k) => ['text' => ($p['r'] === $k ? '✅ ' : '') . self::RATES[$k][1], 'callback_data' => 'tts:r:' . $k], array_keys(self::RATES));
        return ['inline_keyboard' => $rows];
    }

    private static function promptText(int $tg, array $p): string
    {
        $free = self::freeChars();
        $left = max(0, $free - self::usedToday($tg));
        $price = self::price1000();
        $lim = AdminSecurity::isAdmin($tg) ? "👑 Siz uchun bepul" : ($free > 0 ? "🎁 Bugun bepul: <b>" . number_format($left, 0, '', ' ') . "</b> belgi" : '')
            . ($price > 0 ? ($free > 0 ? ', keyin' : '💳') . ' har 1000 belgi — ' . number_format($price, 0, '', ' ') . " so'm" : '');
        return "🔊 <b>Matnni ovozga aylantirish</b>\n\n"
            . "Istalgan matnni yuboring — men uni tanlangan ovozda o'qib, audio qilib qaytaraman. "
            . "Ma'ruza, referat yoki himoya nutqini tinglab yodlash uchun qulay.\n\n"
            . "🎙 Ovoz: <b>" . self::VOICES[$p['v']][1] . "</b> · Tezlik: <b>" . self::RATES[$p['r']][1] . "</b>\n"
            . "📏 Bir martada " . number_format(self::maxChars(), 0, '', ' ') . " belgigacha\n"
            . ($lim !== '' ? $lim . "\n" : '')
            . "\n✍️ Matnni yozing yoki nusxalab yuboring 👇";
    }

    public static function start(int $tg): void
    {
        if (!self::enabled()) {
            Telegram::sendMessage($tg, "🔊 Ovoz xizmati hozircha ishlamayapti. Birozdan keyin urinib ko'ring.", mainMenu());
            return;
        }
        setUserState($tg, 'awaiting_tts');
        $p = self::prefs($tg);
        Telegram::sendMessage($tg, self::promptText($tg, $p), self::keyboard($p));
    }

    public static function callback(int $tg, string $data, int $msgId): void
    {
        $p = self::prefs($tg);
        $pp = explode(':', $data);
        if (($pp[1] ?? '') === 'v' && isset(self::VOICES[$pp[2] ?? ''])) {
            $p['v'] = $pp[2];
        } elseif (($pp[1] ?? '') === 'r' && isset(self::RATES[$pp[2] ?? ''])) {
            $p['r'] = $pp[2];
        } elseif (($pp[1] ?? '') === 'menu') {
            self::start($tg);
            return;
        }
        Engine::kvSet('tts:' . $tg, json_encode(['v' => $p['v'], 'r' => $p['r']]));
        setUserState($tg, 'awaiting_tts');
        if ($msgId > 0) {
            Telegram::call('editMessageText', ['chat_id' => $tg, 'message_id' => $msgId, 'text' => self::promptText($tg, $p), 'parse_mode' => 'HTML',
                'reply_markup' => json_encode(Telegram::colorize(self::keyboard($p)))]);
        }
    }

    public static function onText(int $tg, string $text): void
    {
        $len = mb_strlen(trim($text));
        if ($len < 2) {
            Telegram::sendMessage($tg, "✍️ Ovozga aylantirish uchun matn yuboring.");
            return;
        }
        if ($len > self::maxChars()) {
            Telegram::sendMessage($tg, "📏 Matn juda uzun: " . number_format($len, 0, '', ' ') . " belgi (bir martada ko'pi bilan "
                . number_format(self::maxChars(), 0, '', ' ') . "). Bo'lib-bo'lib yuboring.");
            return;
        }
        // bir vaqtda bitta so'rov (ikki marta yuborilsa ham)
        db()->prepare('DELETE FROM engine_kv WHERE k = ? AND ts < ?')->execute(['ttslock:' . $tg, time() - 180]);
        $lock = db()->prepare('INSERT OR IGNORE INTO engine_kv (k, v, ts) VALUES (?, ?, ?)');
        $lock->execute(['ttslock:' . $tg, '1', time()]);
        if (!$lock->rowCount()) {
            Telegram::sendMessage($tg, "⏳ Oldingi matn hali ovozga aylantirilmoqda — biroz kuting.");
            return;
        }
        try {
            self::process($tg, $text, $len);
        } finally {
            db()->prepare('DELETE FROM engine_kv WHERE k = ?')->execute(['ttslock:' . $tg]);
        }
    }

    private static function process(int $tg, string $text, int $len): void
    {
        $p = self::prefs($tg);
        $dayKey = 'ttsd:' . $tg . ':' . self::day();
        $admin = AdminSecurity::isAdmin($tg);
        $used = self::usedToday($tg);
        $freeLeft = $admin ? $len : max(0, self::freeChars() - $used);
        $paidChars = max(0, $len - $freeLeft);
        $cost = $paidChars > 0 ? (int)(ceil($paidChars / 1000) * self::price1000()) : 0;
        if ($cost > 0) {
            $b = db()->prepare('UPDATE users SET balance = balance - ? WHERE telegram_id = ? AND balance >= ?');
            $b->execute([$cost, $tg, $cost]);
            if (!$b->rowCount()) {
                Telegram::sendMessage($tg, ($freeLeft > 0 ? "⚠️ Bu matn bugungi bepul limitdan oshadi (bepul qoldi: " . number_format($freeLeft, 0, '', ' ') . " belgi)." : "⚠️ Bugungi bepul limit tugadi.") . "\n"
                    . "Bu matn uchun " . number_format($cost, 0, '', ' ') . " so'm kerak — balansingiz yetarli emas.\n\n"
                    . "💰 Balansni to'ldiring yoki qisqaroq matn yuboring.", ['inline_keyboard' => [[['text' => "💳 Balansni to'ldirish", 'callback_data' => 'topup_amount:10000']]]]);
                return;
            }
        }
        Telegram::call('sendChatAction', ['chat_id' => $tg, 'action' => 'record_voice']);
        @set_time_limit(200);
        $res = self::synth($text, $p['v'], $p['r']);
        if (empty($res['ok'])) {
            if ($cost > 0) {
                db()->prepare('UPDATE users SET balance = balance + ? WHERE telegram_id = ?')->execute([$cost, $tg]);
            }
            Telegram::sendMessage($tg, "⚠️ Ovoz yaratib bo'lmadi: " . Telegram::escape((string)($res['error'] ?? 'xatolik')) . ($cost > 0 ? "\nPul qaytarildi." : '')
                . "\nBirozdan keyin qayta urinib ko'ring.");
            return;
        }
        Telegram::call('sendChatAction', ['chat_id' => $tg, 'action' => 'upload_voice']);
        $mins = intdiv((int)$res['secs'], 60) . ':' . str_pad((string)((int)$res['secs'] % 60), 2, '0', STR_PAD_LEFT);
        $cap = "🔊 " . self::VOICES[$p['v']][1] . " · " . number_format($len, 0, '', ' ') . " belgi · ⏱ ~$mins"
            . ($cost > 0 ? " · 💳 " . number_format($cost, 0, '', ' ') . " so'm" : '')
            . "\n✍️ Yana matn yuboring — davom etamiz.";
        $ok = self::send($tg, $res, $cap, ['inline_keyboard' => [[['text' => "🎙 Ovoz / tezlikni o'zgartirish", 'callback_data' => 'tts:menu']]]],
            mb_substr(preg_replace('/\s+/u', ' ', trim($text)) ?? '', 0, 40));
        if (!$ok) {
            if ($cost > 0) {
                db()->prepare('UPDATE users SET balance = balance + ? WHERE telegram_id = ?')->execute([$cost, $tg]);
            }
            Telegram::sendMessage($tg, "⚠️ Audio yuborilmadi. Qayta urinib ko'ring." . ($cost > 0 ? ' Pul qaytarildi.' : ''));
            return;
        }
        db()->prepare('INSERT INTO engine_kv (k, v, ts) VALUES (?, ?, ?) ON CONFLICT(k) DO UPDATE SET v = CAST(CAST(engine_kv.v AS INTEGER) + ? AS TEXT), ts = excluded.ts')
            ->execute([$dayKey, (string)$len, time(), $len]);
    }

    /** Admin panel: sinov — namunaviy ovozni adminning Telegram'iga yuboradi. */
    public static function test(int $admin, string $voiceKey = 'madina'): array
    {
        if (!self::enabled() && self::engine() === '') {
            return ['ok' => false, 'msg' => "Ovoz dvigateli yo'q: avval «O'rnatish» ni bosing"];
        }
        $t = microtime(true);
        $res = self::synth("Assalomu alaykum! Men SlayderAi botiman. Oʻzbek tilida matnni ovozga aylantirib beraman. Bugun " . date('j') . "-sana.", $voiceKey);
        if (empty($res['ok'])) {
            return ['ok' => false, 'msg' => (string)($res['error'] ?? 'xatolik')];
        }
        $sec = round(microtime(true) - $t, 1);
        $ok = self::send($admin, $res, "🧪 Sinov · " . self::VOICES[$voiceKey][1] . " · dvigatel: " . self::engine() . " · format: {$res['fmt']} · {$sec} s");
        return ['ok' => $ok, 'msg' => $ok ? "Sinov ovozi Telegram'ingizga yuborildi ({$sec} s, {$res['fmt']})" : "Telegram'ga yuborib bo'lmadi"];
    }
}
