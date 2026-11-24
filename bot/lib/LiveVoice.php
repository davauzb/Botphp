<?php

/**
 * ⭐ JONLI OVOZ — o'zbekcha ifodali nutq (Navoiy TTS) alohida ishchi serverda (lib/voice_worker).
 *
 * Ishchi o'zi BOTGA ulanadi (voice_api.php): «hello» — holat, «next» — navbatdagi ish, «result» — natija.
 * Shuning uchun serverga kiruvchi port, domen, HTTPS va token nusxalash kerak emas: admin panelda
 * «🚀 Server skripti» → Oracle Cloud'da server yaratishda skriptni joylash → hammasi o'zi o'rnatiladi va ulanadi.
 *
 *  • Ovozlar: admin botda /ovoz_qosh (video, audio yoki ovozli xabar). Namuna botda saqlanadi (server qayta
 *    o'rnatilsa — ovozlar avtomatik tiklanadi), ishchi undan eng toza ~11 soniyani o'zi tanlaydi.
 *  • Uslublar: 📖 hikoyachi, 🙂 oddiy, 🤗 mehribon, 😊 xursand, 🎓 rasmiy.
 *  • CPU serverda sekin (1 s ovozga ~5 s) — qisqa matnlar (LIVE_MAX_CHARS) va navbat.
 *  • Xato / vaqt tugashi — pul qaytariladi, oddiy ovoz taklif qilinadi.
 */
class LiveVoice
{
    public const STYLES = ['hikoya' => '📖 Hikoyachi', 'oddiy' => '🙂 Oddiy', 'mehribon' => '🤗 Mehribon', 'xursand' => '😊 Xursand', 'jiddiy' => '🎓 Rasmiy'];
    private const ONLINE_SEC = 600;               // shuncha vaqt ichida ishchidan xabar bo'lsa — «ulangan»

    // ------------------------------------------------------------------ sozlamalar

    public static function maxChars(): int
    {
        return max(50, min(700, (int)Engine::c('LIVE_MAX_CHARS', 500)));
    }

    public static function freeChars(): int
    {
        return max(0, (int)Engine::c('LIVE_FREE_CHARS', 300));
    }

    public static function price1000(): int
    {
        return max(0, (int)Engine::c('LIVE_PRICE_1000', 2000));
    }

    public static function token(): string
    {
        return (string)(Settings::raw('LIVE_WORKER_TOKEN') ?? '');
    }

    public static function checkToken(string $t): bool
    {
        $real = self::token();
        return strlen($real) >= 24 && hash_equals($real, $t);
    }

    private static function pdo(): PDO
    {
        static $ok = false;
        $pdo = db();
        if (!$ok) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS live_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL, tg INTEGER, payload TEXT,
                status TEXT NOT NULL DEFAULT \'queued\', cost INTEGER DEFAULT 0, chars INTEGER DEFAULT 0, msg INTEGER DEFAULT 0,
                created INTEGER, started INTEGER, done INTEGER, error TEXT)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_live_jobs_st ON live_jobs(status, id)');
            $ok = true;
        }
        return $pdo;
    }

    public static function worker(): array
    {
        $kv = Engine::kvGet('live_worker');
        return $kv ? (json_decode((string)$kv['v'], true) ?: []) : [];
    }

    public static function online(): bool
    {
        $w = self::worker();
        return !empty($w['seen']) && time() - (int)$w['seen'] < self::ONLINE_SEC;
    }

    public static function enabled(): bool
    {
        return (bool)Engine::c('LIVE_TTS_ENABLED', true) && self::token() !== '' && self::online() && !empty(self::worker()['ready']) && self::voices() !== [];
    }

    /** Ovozlar ro'yxati (bot — asosiy manba): [key => [name, gender, state, sample]] */
    private static function vlist(): array
    {
        $kv = Engine::kvGet('live_vlist');
        return $kv ? (json_decode((string)$kv['v'], true) ?: []) : [];
    }

    private static function vsave(array $l): void
    {
        Engine::kvSet('live_vlist', json_encode($l, JSON_UNESCAPED_UNICODE));
    }

    /** Mijozlarga ko'rinadigan (ishchida tayyor) ovozlar. */
    public static function voices(): array
    {
        $out = [];
        foreach (self::vlist() as $k => $v) {
            if (($v['state'] ?? '') === 'ready') {
                $out[] = ['key' => (string)$k, 'name' => (string)$v['name'], 'gender' => (string)($v['gender'] ?? '')];
            }
        }
        return $out;
    }

    public static function voiceName(string $key): string
    {
        $v = self::vlist()[$key] ?? null;
        if (!$v) {
            return '🎙 ' . $key;
        }
        return (($v['gender'] ?? '') === 'm' ? '👨 ' : (($v['gender'] ?? '') === 'f' ? '👩 ' : '🎙 ')) . $v['name'];
    }

    private static function sampleDir(): string
    {
        $d = rtrim(dirname(DB_PATH), '/') . '/voice_samples';
        if (!is_dir($d)) {
            @mkdir($d, 0700, true);
            @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        return $d;
    }

    // ------------------------------------------------------------------ mijoz buyurtmasi

    private static function day(): string
    {
        return gmdate('Ymd', time() + 18000);
    }

    public static function usedToday(int $tg): int
    {
        $kv = Engine::kvGet('ltsd:' . $tg . ':' . self::day());
        return (int)($kv['v'] ?? 0);
    }

    public static function clean(string $text): string
    {
        return mb_substr(Voice::normalize($text, 'uz', false), 0, 1000);
    }

    private static function queueAhead(int $id = 0): int
    {
        $st = self::pdo()->prepare("SELECT COUNT(*) FROM live_jobs WHERE status IN ('queued','working') AND type = 'tts'" . ($id ? ' AND id < ?' : ''));
        $st->execute($id ? [$id] : []);
        return (int)$st->fetchColumn();
    }

    public static function order(int $tg, string $text, string $voice, string $style): void
    {
        $len = mb_strlen(trim($text));
        $max = self::maxChars();
        if ($len > $max) {
            Telegram::sendMessage($tg, "📏 Jonli ovoz uchun matn juda uzun: " . number_format($len, 0, '', ' ') . " belgi (ko'pi bilan $max).\n"
                . "Qisqartiring yoki uzun matnlar uchun oddiy ovozni tanlang.", ['inline_keyboard' => [[['text' => "🎙 Ovozni o'zgartirish", 'callback_data' => 'tts:menu']]]]);
            return;
        }
        if (!self::enabled()) {
            Telegram::sendMessage($tg, "⚠️ Jonli ovoz serveri hozir ulanmagan. Oddiy ovozda yaratib beraymi?", ['inline_keyboard' => [[['text' => '🔊 Oddiy ovozda', 'callback_data' => 'tts:fallback']]]]);
            Engine::kvSet('ttsfb:' . $tg, mb_substr($text, 0, 4000));
            return;
        }
        $pdo = self::pdo();
        $pend = $pdo->prepare("SELECT COUNT(*) FROM live_jobs WHERE tg = ? AND type = 'tts' AND status IN ('queued','working')");
        $pend->execute([$tg]);
        if ((int)$pend->fetchColumn() >= 2) {
            Telegram::sendMessage($tg, "⏳ Oldingi jonli ovozlaringiz hali tayyorlanmoqda — ular kelgach yana yuboring.");
            return;
        }
        $admin = AdminSecurity::isAdmin($tg);
        $freeLeft = $admin ? $len : max(0, self::freeChars() - self::usedToday($tg));
        $paid = max(0, $len - $freeLeft);
        $cost = $paid > 0 ? (int)(ceil($paid / 1000) * self::price1000()) : 0;
        if ($cost > 0) {
            $b = $pdo->prepare('UPDATE users SET balance = balance - ? WHERE telegram_id = ? AND balance >= ?');
            $b->execute([$cost, $tg, $cost]);
            if (!$b->rowCount()) {
                Telegram::sendMessage($tg, "⚠️ Jonli ovoz uchun " . number_format($cost, 0, '', ' ') . " so'm kerak — balansingiz yetarli emas"
                    . ($freeLeft > 0 ? " (bugun bepul qoldi: $freeLeft belgi)" : '') . ".", ['inline_keyboard' => [
                        [['text' => "💳 Balansni to'ldirish", 'callback_data' => 'topup_amount:10000']],
                        [['text' => "🎙 Oddiy ovozga o'tish", 'callback_data' => 'tts:menu']],
                    ]]);
                return;
            }
        }
        $ahead = self::queueAhead();
        $estSec = (int)($ahead * 60 + 20 + $len * 0.4);
        $mid = Telegram::sendMessage($tg, "⏳ <b>Jonli ovoz tayyorlanmoqda…</b>\n" . self::voiceName($voice) . " · " . (self::STYLES[$style] ?? '')
            . ($ahead > 0 ? "\n🕐 Navbatda: " . ($ahead + 1) . "-o'rin" : '')
            . "\n⏱ Taxminan " . ($estSec < 90 ? max(20, (int)(ceil($estSec / 10) * 10)) . ' soniya' : (int)ceil($estSec / 60) . ' daqiqa')
            . ($cost > 0 ? "\n💳 " . number_format($cost, 0, '', ' ') . " so'm" : ''));
        $pdo->prepare('INSERT INTO live_jobs (type, tg, payload, cost, chars, msg, created) VALUES (?,?,?,?,?,?,?)')
            ->execute(['tts', $tg, json_encode(['text' => self::clean($text), 'voice' => $voice, 'style' => $style, 'speed' => 1.0], JSON_UNESCAPED_UNICODE),
                $cost, $len, (int)$mid, time()]);
        Engine::kvSet('ltsd:' . $tg . ':' . self::day(), (string)(self::usedToday($tg) + $len));
        Telegram::call('sendChatAction', ['chat_id' => $tg, 'action' => 'record_voice']);
    }

    // ------------------------------------------------------------------ ishchi API (voice_api.php)

    /** «hello»: holatni saqlash va ovozlarni sinxronlash. */
    public static function workerHello(array $d): array
    {
        $wv = [];
        foreach ((array)($d['voices'] ?? []) as $v) {
            if (is_array($v) && preg_match('/^[a-z0-9_]{2,24}$/', (string)($v['key'] ?? ''))) {
                $wv[(string)$v['key']] = ['name' => mb_substr((string)($v['name'] ?? $v['key']), 0, 40), 'gender' => in_array($v['gender'] ?? '', ['m', 'f'], true) ? $v['gender'] : ''];
            }
        }
        $prev = self::worker();
        Engine::kvSet('live_worker', json_encode(['seen' => time(), 'hello' => time(), 'ready' => !empty($d['ready']), 'error' => mb_substr((string)($d['error'] ?? ''), 0, 300),
            'version' => mb_substr((string)($d['version'] ?? ''), 0, 20), 'cpu' => (int)($d['cpu'] ?? 0), 'threads' => (int)($d['threads'] ?? 0),
            'voices' => array_keys($wv)], JSON_UNESCAPED_UNICODE));
        $notified = Engine::kvGet('live_notified');
        if (empty($prev['ready']) && !empty($d['ready']) && defined('ADMIN_CHAT_ID') && (int)ADMIN_CHAT_ID > 0
            && (!$notified || time() - (int)$notified['ts'] > 6 * 3600)) {               // qayta ishga tushishlarda bezovta qilmaslik
            Engine::kvSet('live_notified', '1');
            Telegram::sendMessage((int)ADMIN_CHAT_ID, "⭐ <b>Jonli ovoz serveri ulandi!</b>\nOvozlar: " . count($wv) . " ta. Mijozlar «🔊 Matnni ovozga» da ⭐ ovozlarni ko'radi.\nO'z ovozingizni qo'shish: /ovoz_qosh");
        }
        $list = self::vlist();
        $changed = false;
        foreach ($wv as $k => $v) {                 // ishchidagi, botga noma'lum ovoz (masalan, boshlang'ich «ayol»)
            if (!isset($list[$k])) {
                $list[$k] = ['name' => $v['name'], 'gender' => $v['gender'], 'state' => 'ready'];
                $changed = true;
            } elseif (($list[$k]['state'] ?? '') !== 'ready' && !self::hasJob('voice_add', $k)) {
                $list[$k]['state'] = 'ready';
                $changed = true;
            }
        }
        foreach ($list as $k => $v) {               // botda bor, ishchida yo'q (server qayta o'rnatilgan) — namunadan tiklaymiz
            if (!isset($wv[$k]) && ($v['state'] ?? '') === 'ready') {
                if (!empty($v['sample']) && is_file((string)$v['sample'])) {
                    $list[$k]['state'] = 'pending';
                    if (!self::hasJob('voice_add', $k)) {
                        self::addJob('voice_add', 0, ['key' => $k, 'name' => $v['name'], 'gender' => $v['gender'] ?? '']);
                    }
                } else {
                    unset($list[$k]);
                }
                $changed = true;
            }
        }
        if ($changed) {
            self::vsave($list);
        }
        return ['ok' => true];
    }

    private static function hasJob(string $type, string $key): bool
    {
        $st = self::pdo()->prepare("SELECT 1 FROM live_jobs WHERE type = ? AND status IN ('queued','working') AND payload LIKE ? LIMIT 1");
        $st->execute([$type, '%"key":"' . $key . '"%']);
        return (bool)$st->fetchColumn();
    }

    private static function addJob(string $type, int $tg, array $payload): int
    {
        self::pdo()->prepare('INSERT INTO live_jobs (type, tg, payload, created) VALUES (?,?,?,?)')
            ->execute([$type, $tg, json_encode($payload, JSON_UNESCAPED_UNICODE), time()]);
        return (int)self::pdo()->lastInsertId();
    }

    /** «next»: navbatdagi ishni atomik olish (ovoz qo'shish/o'chirish — birinchi). */
    public static function claimNext(): ?array
    {
        $w = self::worker();
        if (time() - (int)($w['seen'] ?? 0) >= 15) {         // «oxirgi aloqa» — 15 soniyada bir marta yoziladi
            $w['seen'] = time();
            Engine::kvSet('live_worker', json_encode($w, JSON_UNESCAPED_UNICODE));
        }
        $pdo = self::pdo();
        $row = $pdo->query("SELECT * FROM live_jobs WHERE status = 'queued' ORDER BY (type = 'tts') ASC, id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $u = $pdo->prepare("UPDATE live_jobs SET status = 'working', started = ? WHERE id = ? AND status = 'queued'");
        $u->execute([time(), $row['id']]);
        if (!$u->rowCount()) {
            return null;
        }
        $p = json_decode((string)$row['payload'], true) ?: [];
        return ['id' => (int)$row['id'], 'type' => (string)$row['type']] + $p;
    }

    /** «sample»: ovoz qo'shish ishi uchun namuna fayli (base64). */
    public static function sampleFor(int $id): array
    {
        $st = self::pdo()->prepare("SELECT payload FROM live_jobs WHERE id = ? AND type = 'voice_add' AND status = 'working'");
        $st->execute([$id]);
        $p = json_decode((string)$st->fetchColumn(), true) ?: [];
        $f = (string)(self::vlist()[(string)($p['key'] ?? '')]['sample'] ?? '');
        if ($f === '' || !is_file($f)) {
            return ['ok' => false, 'error' => 'namuna topilmadi'];
        }
        return ['ok' => true, 'b64' => base64_encode((string)file_get_contents($f))];
    }

    /** «result»: natijani qabul qilish va mijozga yetkazish. */
    public static function workerResult(array $d): array
    {
        $id = (int)($d['id'] ?? 0);
        $pdo = self::pdo();
        $st = $pdo->prepare("SELECT * FROM live_jobs WHERE id = ? AND status = 'working'");
        $st->execute([$id]);
        $job = $st->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            return ['ok' => true, 'note' => 'ish topilmadi yoki allaqachon yopilgan'];
        }
        $ok = !empty($d['ok']);
        $pdo->prepare("UPDATE live_jobs SET status = ?, done = ?, error = ? WHERE id = ?")
            ->execute([$ok ? 'done' : 'error', time(), $ok ? null : mb_substr((string)($d['error'] ?? 'xato'), 0, 300), $id]);
        $p = json_decode((string)$job['payload'], true) ?: [];
        if ($job['type'] === 'tts') {
            if ($ok && !empty($d['audio_b64'])) {
                $bin = base64_decode((string)$d['audio_b64'], true);
                if ($bin !== false && strlen($bin) > 500) {
                    $f = rtrim(GENERATED_DIR, '/') . '/live_' . $id . '_' . bin2hex(random_bytes(3)) . '.ogg';
                    file_put_contents($f, $bin);
                    if (!empty($job['msg'])) {
                        Telegram::call('deleteMessage', ['chat_id' => (int)$job['tg'], 'message_id' => (int)$job['msg']]);
                    }
                    $sent = Voice::send((int)$job['tg'], ['file' => $f, 'fmt' => 'ogg', 'secs' => (int)round((float)($d['secs'] ?? 0))],
                        "⭐ " . self::voiceName((string)($p['voice'] ?? '')) . " · " . (self::STYLES[$p['style'] ?? ''] ?? '') . " · " . number_format((int)$job['chars'], 0, '', ' ') . " belgi"
                        . ((int)$job['cost'] > 0 ? " · 💳 " . number_format((int)$job['cost'], 0, '', ' ') . " so'm" : '') . "\n✍️ Yana matn yuboring — davom etamiz.",
                        ['inline_keyboard' => [[['text' => "🎙 Ovoz / uslubni o'zgartirish", 'callback_data' => 'tts:menu']]]]);
                    if ($sent) {
                        return ['ok' => true];
                    }
                }
            }
            self::failJob($job, $ok ? 'audio yuborilmadi' : (string)($d['error'] ?? 'xato'));
            return ['ok' => true];
        }
        if ($job['type'] === 'voice_add') {
            $key = (string)($p['key'] ?? '');
            $list = self::vlist();
            if (isset($list[$key])) {
                $list[$key]['state'] = $ok ? 'ready' : 'error';
                $list[$key]['error'] = $ok ? '' : mb_substr((string)($d['error'] ?? ''), 0, 200);
                self::vsave($list);
            }
            if ((int)$job['tg'] > 0) {
                Telegram::sendMessage((int)$job['tg'], $ok
                    ? "✅ «" . Telegram::escape((string)($p['name'] ?? $key)) . "» ovozi tayyor! «🔊 Matnni ovozga» → ⭐ da tanlab, qisqa matn yuborib sinab ko'ring."
                    : "⚠️ Ovozni qo'shib bo'lmadi: " . Telegram::escape((string)($d['error'] ?? 'xato')) . "\nBoshqa (musiqasiz, toza) yozuv bilan qayta urining: /ovoz_qosh");
            }
        }
        return ['ok' => true];
    }

    private static function failJob(array $job, string $why): void
    {
        logError('livevoice', 'ish #' . $job['id'] . ' yopildi: ' . $why);
        if ($job['type'] !== 'tts') {
            return;
        }
        $tg = (int)$job['tg'];
        if ((int)$job['cost'] > 0) {
            db()->prepare('UPDATE users SET balance = balance + ? WHERE telegram_id = ?')->execute([(int)$job['cost'], $tg]);
        }
        if (!empty($job['msg'])) {
            Telegram::call('deleteMessage', ['chat_id' => $tg, 'message_id' => (int)$job['msg']]);
        }
        $p = json_decode((string)$job['payload'], true) ?: [];
        Engine::kvSet('ttsfb:' . $tg, (string)($p['text'] ?? ''));
        Telegram::sendMessage($tg, "⚠️ Jonli ovozni yaratib bo'lmadi" . ((int)$job['cost'] > 0 ? ", pul qaytarildi" : '') . ". Oddiy ovozda yaratib beraymi?",
            ['inline_keyboard' => [[['text' => '🔊 Oddiy ovozda', 'callback_data' => 'tts:fallback'], ['text' => "🎙 Ovozni o'zgartirish", 'callback_data' => 'tts:menu']]]]);
    }

    /** Maintenance'dan: osilib qolgan ishlarni yopish (pul qaytariladi). */
    public static function poll(float $until = 0): void
    {
        $pdo = self::pdo();
        $now = time();
        $st = $pdo->prepare("SELECT * FROM live_jobs WHERE (status = 'working' AND started < ?) OR (status = 'queued' AND type = 'tts' AND created < ?) LIMIT 20");
        $st->execute([$now - 600, $now - ((self::online() ? 25 : 10) * 60)]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $job) {
            $u = $pdo->prepare("UPDATE live_jobs SET status = 'error', done = ?, error = 'vaqt tugadi' WHERE id = ? AND status IN ('queued','working')");
            $u->execute([$now, $job['id']]);
            if ($u->rowCount()) {
                self::failJob($job, 'vaqt tugadi');
            }
        }
        if (mt_rand(1, 50) === 1) {
            $pdo->prepare("DELETE FROM live_jobs WHERE status IN ('done','error') AND done < ?")->execute([$now - 7 * 86400]);
        }
    }

    public static function pending(): bool
    {
        return false;                                // ishchi o'zi so'raydi — tick zanjiri kerak emas
    }

    // ------------------------------------------------------------------ admin: ovoz namunasi qo'shish (bot orqali)

    public static function sampleStart(int $tg): void
    {
        if (!AdminSecurity::isAdmin($tg)) {
            return;
        }
        if (self::token() === '') {
            Telegram::sendMessage($tg, "Avval jonli ovoz serverini o'rnating: admin panel → Tizim → «⭐ Jonli ovoz» → «🚀 Server skripti».");
            return;
        }
        setUserState($tg, 'awaiting_voice_sample');
        Telegram::sendMessage($tg, "🎙 <b>Yangi jonli ovoz qo'shish</b>\n\n"
            . "Ovoz namunasini yuboring: <b>video, audio yoki ovozli xabar</b> (20 MB gacha).\n"
            . "• uzun video ham bo'ladi — server undan <b>eng toza ~11 soniyalik</b> nutq qismini o'zi tanlaydi;\n"
            . "• bitta odam gapirsin, fon musiqasi va shovqin qancha kam bo'lsa — ovoz shuncha o'xshash chiqadi;\n"
            . "• ertak/hikoya ohangida gapirilgan bo'lsa — natija ham shunday iliq chiqadi.\n\n"
            . "⚠️ Faqat <b>o'zingizning</b> yoki egasi <b>rozilik bergan</b> ovozni qo'shing.",
            ['inline_keyboard' => [[['text' => '✖️ Bekor qilish', 'callback_data' => 'vs:no']]]]);
    }

    public static function sampleFile(int $tg, array $message): bool
    {
        $f = $message['video'] ?? $message['audio'] ?? $message['voice'] ?? $message['video_note'] ?? $message['document'] ?? null;
        if (!is_array($f) || empty($f['file_id'])) {
            Telegram::sendMessage($tg, "Video, audio yoki ovozli xabar yuboring (yoki «✖️ Bekor qilish»).");
            return true;
        }
        if ((int)($f['file_size'] ?? 0) > 20 * 1048576) {
            Telegram::sendMessage($tg, "Fayl 20 MB dan katta — Telegram botlari uni yuklab ololmaydi. Videoning qisqaroq qismini yuboring.");
            return true;
        }
        Engine::kvSet('vsample:' . $tg, json_encode(['file_id' => (string)$f['file_id']]));
        setUserState($tg, 'awaiting_voice_name');
        Telegram::sendMessage($tg, "✅ Namuna qabul qilindi. Endi ovozga <b>nom</b> bering (masalan: «Davron aka» yoki «Ertakchi»):");
        return true;
    }

    public static function sampleName(int $tg, string $name): void
    {
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)) ?? '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 30) {
            Telegram::sendMessage($tg, "Nom 2–30 belgi bo'lsin. Qayta yozing:");
            return;
        }
        $kv = Engine::kvGet('vsample:' . $tg);
        $d = $kv ? (json_decode((string)$kv['v'], true) ?: []) : [];
        $d['name'] = $name;
        Engine::kvSet('vsample:' . $tg, json_encode($d, JSON_UNESCAPED_UNICODE));
        setUserState($tg, 'idle');
        Telegram::sendMessage($tg, "Ovoz kimniki?", ['inline_keyboard' => [[['text' => '👨 Erkak', 'callback_data' => 'vs:g:m'], ['text' => '👩 Ayol', 'callback_data' => 'vs:g:f']]]]);
    }

    public static function sampleCallback(int $tg, string $data): void
    {
        if (!AdminSecurity::isAdmin($tg)) {
            return;
        }
        if ($data === 'vs:no') {
            db()->prepare('DELETE FROM engine_kv WHERE k = ?')->execute(['vsample:' . $tg]);
            setUserState($tg, 'idle');
            Telegram::sendMessage($tg, 'Bekor qilindi.', mainMenu());
            return;
        }
        $kv = Engine::kvGet('vsample:' . $tg);
        $d = $kv ? (json_decode((string)$kv['v'], true) ?: []) : [];
        if (empty($d['file_id']) || empty($d['name'])) {
            Telegram::sendMessage($tg, "So'rov eskirgan — /ovoz_qosh bilan qaytadan boshlang.");
            return;
        }
        if (preg_match('/^vs:g:([mf])$/', $data, $m)) {
            $d['gender'] = $m[1];
            Engine::kvSet('vsample:' . $tg, json_encode($d, JSON_UNESCAPED_UNICODE));
            Telegram::sendMessage($tg, "🔐 <b>Tasdiqlang:</b> bu ovoz <b>sizniki</b> yoki uning egasi botda ishlatilishiga <b>rozilik bergan</b>.\n"
                . "Ovoz botning barcha mijozlariga «⭐ Jonli ovoz» sifatida ko'rinadi.",
                ['inline_keyboard' => [[['text' => '✅ Ha, rozilik bor', 'callback_data' => 'vs:ok']], [['text' => '✖️ Bekor qilish', 'callback_data' => 'vs:no']]]]);
            return;
        }
        if ($data !== 'vs:ok' || empty($d['gender'])) {
            return;
        }
        db()->prepare('DELETE FROM engine_kv WHERE k = ?')->execute(['vsample:' . $tg]);
        $r = Telegram::call('getFile', ['file_id' => (string)$d['file_id']]);
        $path = (string)($r['result']['file_path'] ?? '');
        $bin = false;
        if ($path !== '') {
            $ch = curl_init(TELEGRAM_FILE_API . $path);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90]);
            $bin = curl_exec($ch);
            if ((int)curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
                $bin = false;
            }
            curl_close($ch);
        }
        if (!is_string($bin) || strlen($bin) < 2000) {
            Telegram::sendMessage($tg, "⚠️ Faylni Telegram'dan yuklab bo'lmadi. Qayta urinib ko'ring: /ovoz_qosh");
            return;
        }
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', (string)(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $d['name']) ?: '')) ?? '');
        $key = substr(trim($slug, '_'), 0, 16) ?: 'ovoz';
        $key = (preg_match('/^[a-z]/', $key) ? $key : 'v_' . $key) . '_' . substr(bin2hex(random_bytes(2)), 0, 3);
        $file = self::sampleDir() . '/' . $key . '.bin';
        file_put_contents($file, $bin);
        $list = self::vlist();
        $list[$key] = ['name' => $d['name'], 'gender' => $d['gender'], 'state' => 'pending', 'sample' => $file];
        self::vsave($list);
        self::addJob('voice_add', $tg, ['key' => $key, 'name' => $d['name'], 'gender' => $d['gender']]);
        AdminSecurity::audit('live_voice_add', $tg, $key);
        Telegram::sendMessage($tg, "⏳ Namuna ovoz serveriga yuborildi" . (self::online() ? " — 1 daqiqada tayyor bo'ladi, xabar beraman." : ".\n⚠️ Server hozir ulanmagan — ulangach o'zi qo'shiladi."), mainMenu());
    }

    // ------------------------------------------------------------------ admin panel

    /** «🚀 Server skripti»: token (bo'lmasa yaratiladi) va Oracle cloud-init skripti. */
    public static function setup(int $admin, bool $regenerate): array
    {
        if (!defined('PUBLIC_BASE_URL') || !str_starts_with((string)PUBLIC_BASE_URL, 'https://')) {
            return ['ok' => false, 'msg' => "config.php da PUBLIC_BASE_URL (https://…) yozilmagan"];
        }
        if ($regenerate || self::token() === '') {
            Settings::setRaw('LIVE_WORKER_TOKEN', 'wk_' . bin2hex(random_bytes(24)), $admin);
            AdminSecurity::audit('live_worker_token', $admin, $regenerate ? 'yangilandi' : 'yaratildi');
        }
        $api = rtrim((string)PUBLIC_BASE_URL, '/') . '/voice_api.php';
        $t = self::token();
        $script = "#!/bin/bash\n"
            . "# SlayderAi jonli ovoz serveri — avtomatik o'rnatish (Oracle Cloud: Initialization script)\n"
            . "export VOICE_BOT='" . $api . "'\n"
            . "export VOICE_TOKEN='" . $t . "'\n"
            . "curl -fsS -m 120 -X POST -H 'Content-Type: application/json' -d '{\"a\":\"install\",\"t\":\"'\"\$VOICE_TOKEN\"'\"}' \"\$VOICE_BOT\" -o /root/slayder-install.sh \\\n"
            . "  && bash /root/slayder-install.sh >> /var/log/slayder-install.log 2>&1\n";
        return ['ok' => true, 'script' => $script, 'regenerated' => $regenerate];
    }

    public static function status(): array
    {
        $w = self::worker();
        $prog = Engine::kvGet('live_progress');
        $pg = $prog ? (json_decode((string)$prog['v'], true) ?: []) + ['ts' => (int)$prog['ts']] : null;
        $q = self::pdo()->query("SELECT COUNT(*) FROM live_jobs WHERE status IN ('queued','working')")->fetchColumn();
        $today = self::pdo()->prepare("SELECT COUNT(*), COALESCE(SUM(chars),0) FROM live_jobs WHERE type = 'tts' AND status = 'done' AND done > ?");
        $today->execute([strtotime('today', time() + 18000) - 18000]);
        [$n, $chars] = $today->fetch(PDO::FETCH_NUM) ?: [0, 0];
        $voices = [];
        foreach (self::vlist() as $k => $v) {
            $voices[] = ['key' => (string)$k, 'name' => (string)$v['name'], 'gender' => (string)($v['gender'] ?? ''), 'state' => (string)($v['state'] ?? ''), 'error' => (string)($v['error'] ?? '')];
        }
        return [
            'has_token' => self::token() !== '', 'online' => self::online(), 'ready' => !empty($w['ready']), 'seen' => (int)($w['seen'] ?? 0),
            'error' => (string)($w['error'] ?? ''), 'version' => (string)($w['version'] ?? ''), 'cpu' => (int)($w['cpu'] ?? 0),
            'enabled' => (bool)Engine::c('LIVE_TTS_ENABLED', true), 'queue' => (int)$q, 'today' => ['n' => (int)$n, 'chars' => (int)$chars],
            'progress' => $pg, 'voices' => $voices, 'max_chars' => self::maxChars(),
        ];
    }

    public static function deleteVoice(int $admin, string $key): array
    {
        $list = self::vlist();
        if (!isset($list[$key])) {
            return ['ok' => false, 'msg' => 'Ovoz topilmadi'];
        }
        if (!empty($list[$key]['sample'])) {
            @unlink((string)$list[$key]['sample']);
        }
        unset($list[$key]);
        self::vsave($list);
        self::addJob('voice_delete', 0, ['key' => $key]);
        AdminSecurity::audit('live_voice_delete', $admin, $key);
        return ['ok' => true, 'msg' => "Ovoz o'chirildi"];
    }

    /** Panel: sinov — namunaviy ertak adminning Telegram'iga keladi (navbat orqali). */
    public static function test(int $admin, string $voice, string $style): array
    {
        if (!self::online()) {
            return ['ok' => false, 'msg' => 'Ovoz serveri ulanmagan'];
        }
        if (!isset(self::vlist()[$voice])) {
            return ['ok' => false, 'msg' => 'Ovoz topilmadi'];
        }
        $text = "Bir bor ekan, bir yo'q ekan, qadim zamonda bir dono chol yashagan ekan. U har kuni ertalab bog'iga chiqib, gullarga suv quyar ekan.";
        self::pdo()->prepare('INSERT INTO live_jobs (type, tg, payload, chars, created) VALUES (?,?,?,?,?)')
            ->execute(['tts', $admin, json_encode(['text' => $text, 'voice' => $voice, 'style' => $style, 'speed' => 1.0], JSON_UNESCAPED_UNICODE), mb_strlen($text), time()]);
        return ['ok' => true, 'msg' => "Sinov navbatga qo'yildi — ~1 daqiqada Telegram'ingizga keladi"];
    }
}
