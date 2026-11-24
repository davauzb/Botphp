<?php

/**
 * ⭐ JONLI OVOZ — o'zbekcha ifodali nutq (Navoiy TTS) alohida ovoz serverida (voice-server/install.sh).
 * Hosting PHP'da og'ir model ishlamaydi, shuning uchun bot matnni serverga yuboradi, server navbat bilan OGG qaytaradi.
 *
 *  • Ovozlar serverda: har biri 10–25 soniyalik toza namuna. Admin botda /ovoz_qosh bilan qo'shadi
 *    (audio, ovozli xabar yoki VIDEO — ovoz ajratib olinadi). Faqat egasining roziligi bilan!
 *  • Uslublar: 🙂 oddiy, 📖 hikoyachi (iliq, shoshilmasdan), 🤗 mehribon, 😊 xursand, 🎓 rasmiy.
 *  • CPU serverda sekin (1 soniya ovozga ~4 soniya), shuning uchun qisqa matnlar (LIVE_MAX_CHARS) va navbat.
 *    Natija webhook'da ~100 soniyagacha kutiladi, kechiksa — fon tick'ida yetkaziladi.
 *  • Server javob bermasa yoki xato bo'lsa — pul qaytariladi, mijozga oddiy ovoz taklif qilinadi.
 * Sozlash: admin panel → Tizim → «⭐ Jonli ovoz serveri» (manzil + token).
 */
class LiveVoice
{
    public static function url(): string
    {
        return rtrim((string)(Settings::raw('LIVE_TTS_URL') ?? ''), '/');
    }

    private static function token(): string
    {
        return (string)(Settings::raw('LIVE_TTS_TOKEN') ?? '');
    }

    public static function configured(): bool
    {
        return self::url() !== '' && self::token() !== '';
    }

    public static function enabled(): bool
    {
        return (bool)Engine::c('LIVE_TTS_ENABLED', true) && self::configured() && self::voices() !== [];
    }

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

    public const STYLES = ['hikoya' => '📖 Hikoyachi', 'oddiy' => '🙂 Oddiy', 'mehribon' => '🤗 Mehribon', 'xursand' => '😊 Xursand', 'jiddiy' => '🎓 Rasmiy'];

    // ------------------------------------------------------------------ HTTP

    /** @return array{0:int,1:array|string|null} [http kod, javob] */
    private static function http(string $method, string $path, ?array $body = null, int $timeout = 20, bool $raw = false): array
    {
        $ch = curl_init(self::url() . $path);
        $h = ['Authorization: Bearer ' . self::token(), 'Accept: application/json'];
        $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_CUSTOMREQUEST => $method];
        if ($body !== null) {
            $h[] = 'Content-Type: application/json';
            $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        $opt[CURLOPT_HTTPHEADER] = $h;
        curl_setopt_array($ch, $opt);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) {
            return [0, $err];
        }
        if ($raw) {
            return [$code, (string)$res];
        }
        $d = json_decode((string)$res, true);
        return [$code, is_array($d) ? $d : null];
    }

    /** Serverdagi ovozlar (10 daqiqa keshlanadi). */
    public static function voices(bool $fresh = false): array
    {
        if (!self::configured()) {
            return [];
        }
        $kv = Engine::kvGet('live_voices');
        if (!$fresh && $kv && time() - (int)$kv['ts'] < 600) {
            return json_decode((string)$kv['v'], true) ?: [];
        }
        [$code, $d] = self::http('GET', '/voices', null, 8);
        if ($code !== 200 || !is_array($d) || empty($d['ok'])) {
            // server vaqtincha javob bermasa — eski ro'yxat 1 soatgacha ishlatiladi
            return ($kv && time() - (int)$kv['ts'] < 3600) ? (json_decode((string)$kv['v'], true) ?: []) : [];
        }
        $list = [];
        foreach ((array)$d['voices'] as $v) {
            if (preg_match('/^[a-z0-9_]{2,24}$/', (string)($v['key'] ?? ''))) {
                $list[] = ['key' => (string)$v['key'], 'name' => mb_substr((string)($v['name'] ?? $v['key']), 0, 40), 'gender' => (string)($v['gender'] ?? '')];
            }
        }
        Engine::kvSet('live_voices', json_encode($list, JSON_UNESCAPED_UNICODE));
        return $list;
    }

    public static function voiceName(string $key): string
    {
        foreach (self::voices() as $v) {
            if ($v['key'] === $key) {
                return ($v['gender'] === 'm' ? '👨 ' : ($v['gender'] === 'f' ? '👩 ' : '🎙 ')) . $v['name'];
            }
        }
        return '🎙 ' . $key;
    }

    // ------------------------------------------------------------------ buyurtma

    private static function day(): string
    {
        return gmdate('Ymd', time() + 18000);
    }

    public static function usedToday(int $tg): int
    {
        $kv = Engine::kvGet('ltsd:' . $tg . ':' . self::day());
        return (int)($kv['v'] ?? 0);
    }

    /** Matn tayyorlash: belgilar, emoji olib tashlanadi, raqamlar so'z bilan (server o'zi ham tekshiradi). */
    public static function clean(string $text): string
    {
        $t = Voice::normalize($text, 'uz', false);
        return mb_substr($t, 0, 1000);
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
        $pend = db()->prepare("SELECT COUNT(*) FROM engine_kv WHERE k LIKE 'livejob:%' AND v LIKE ?");
        $pend->execute(['%"tg":' . $tg . ',%']);
        if ((int)$pend->fetchColumn() >= 2) {
            Telegram::sendMessage($tg, "⏳ Oldingi jonli ovozlaringiz hali tayyorlanmoqda — ular kelgach yana yuboring.");
            return;
        }
        $admin = AdminSecurity::isAdmin($tg);
        $freeLeft = $admin ? $len : max(0, self::freeChars() - self::usedToday($tg));
        $paid = max(0, $len - $freeLeft);
        $cost = $paid > 0 ? (int)(ceil($paid / 1000) * self::price1000()) : 0;
        if ($cost > 0) {
            $b = db()->prepare('UPDATE users SET balance = balance - ? WHERE telegram_id = ? AND balance >= ?');
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
        [$code, $d] = self::http('POST', '/tts', ['text' => self::clean($text), 'voice' => $voice, 'style' => $style, 'speed' => 1.0], 15);
        if ($code !== 200 || empty($d['ok']) || empty($d['id']) || !preg_match('/^[0-9a-f]{32}$/', (string)$d['id'])) {
            if ($cost > 0) {
                db()->prepare('UPDATE users SET balance = balance + ? WHERE telegram_id = ?')->execute([$cost, $tg]);
            }
            logError('livevoice', "submit HTTP $code: " . (is_array($d) ? (string)($d['error'] ?? '') : (string)$d));
            Telegram::sendMessage($tg, "⚠️ Jonli ovoz serveri hozir javob bermayapti" . ($cost > 0 ? " (pul qaytarildi)" : '') . ".\nOddiy ovozda yaratib beraymi?",
                ['inline_keyboard' => [[['text' => '🔊 Oddiy ovozda', 'callback_data' => 'tts:fallback']]]]);
            Engine::kvSet('ttsfb:' . $tg, mb_substr($text, 0, 4000));
            return;
        }
        $id = (string)$d['id'];
        $pos = max(1, (int)($d['position'] ?? 1));
        $estSec = (int)(($pos - 1) * 45 + 15 + $len * 0.32);
        Engine::kvSet('ltsd:' . $tg . ':' . self::day(), (string)(self::usedToday($tg) + $len));
        $msg = Telegram::sendMessage($tg, "⏳ <b>Jonli ovoz tayyorlanmoqda…</b>\n" . self::voiceName($voice) . " · " . (self::STYLES[$style] ?? '')
            . ($pos > 1 ? "\n🕐 Navbatda: {$pos}-o'rin" : '') . "\n⏱ Taxminan " . ($estSec < 90 ? max(20, (int)(ceil($estSec / 10) * 10)) . ' soniya' : (int)ceil($estSec / 60) . ' daqiqa')
            . ($cost > 0 ? "\n💳 " . number_format($cost, 0, '', ' ') . " so'm" : ''));
        Engine::kvSet('livejob:' . $id, json_encode(['tg' => $tg, 'cost' => $cost, 'chars' => $len, 'voice' => $voice, 'style' => $style,
            'msg' => (int)$msg, 'ts' => time()]));
        Telegram::call('sendChatAction', ['chat_id' => $tg, 'action' => 'record_voice']);
        // Webhook javobi allaqachon qaytgan (fastcgi_finish_request) — qisqa matnlarni shu yerning o'zida kutamiz
        @set_time_limit(170);
        $until = microtime(true) + 120;
        while (microtime(true) < $until) {
            sleep(4);
            if (self::check($id)) {
                return;
            }
        }
        Engine::kick(0);                                // qolganini fon tick'i yetkazadi
    }

    /** Bitta ishni tekshirish; yetkazilgan yoki yopilgan bo'lsa true. */
    public static function check(string $id): bool
    {
        $kv = Engine::kvGet('livejob:' . $id);
        if (!$kv) {
            return true;
        }
        $j = json_decode((string)$kv['v'], true) ?: [];
        [$code, $d] = self::http('GET', '/job/' . $id, null, 10);
        $status = is_array($d) ? (string)($d['status'] ?? '') : '';
        if ($code === 200 && $status === 'done') {
            if (!self::claim($id)) {
                return true;                             // boshqa jarayon allaqachon olgan
            }
            [$c2, $audio] = self::http('GET', '/audio/' . $id, null, 60, true);
            if ($c2 === 200 && is_string($audio) && strlen($audio) > 500) {
                $f = rtrim(GENERATED_DIR, '/') . '/live_' . $id . '.ogg';
                file_put_contents($f, $audio);
                if (!empty($j['msg'])) {
                    Telegram::call('deleteMessage', ['chat_id' => $j['tg'], 'message_id' => (int)$j['msg']]);
                }
                $secs = (int)round((float)($d['secs'] ?? 0));
                $ok = Voice::send((int)$j['tg'], ['file' => $f, 'fmt' => 'ogg', 'secs' => $secs],
                    "⭐ " . self::voiceName((string)$j['voice']) . " · " . (self::STYLES[$j['style']] ?? '') . " · " . number_format((int)$j['chars'], 0, '', ' ') . " belgi"
                    . (!empty($j['cost']) ? " · 💳 " . number_format((int)$j['cost'], 0, '', ' ') . " so'm" : '') . "\n✍️ Yana matn yuboring — davom etamiz.",
                    ['inline_keyboard' => [[['text' => "🎙 Ovoz / uslubni o'zgartirish", 'callback_data' => 'tts:menu']]]]);
                if ($ok) {
                    return true;
                }
            }
            self::fail($j, "audio olinmadi");
            return true;
        }
        $expired = time() - (int)($j['ts'] ?? 0) > 25 * 60;
        if (($code === 200 && $status === 'error') || $code === 404 || $expired) {
            if (self::claim($id)) {
                self::fail($j, $expired ? 'vaqt tugadi' : (string)($d['error'] ?? 'server xatosi'));
            }
            return true;
        }
        return false;
    }

    /** Ishni atomik «olish» (ikki jarayon bir xil ishni ikki marta yetkazmasin). */
    private static function claim(string $id): bool
    {
        $st = db()->prepare('DELETE FROM engine_kv WHERE k = ?');
        $st->execute(['livejob:' . $id]);
        return $st->rowCount() > 0;
    }

    private static function fail(array $j, string $why): void
    {
        $tg = (int)($j['tg'] ?? 0);
        if (!empty($j['cost'])) {
            db()->prepare('UPDATE users SET balance = balance + ? WHERE telegram_id = ?')->execute([(int)$j['cost'], $tg]);
        }
        logError('livevoice', 'ish yopildi: ' . $why);
        if ($tg) {
            if (!empty($j['msg'])) {
                Telegram::call('deleteMessage', ['chat_id' => $tg, 'message_id' => (int)$j['msg']]);
            }
            Telegram::sendMessage($tg, "⚠️ Jonli ovozni yaratib bo'lmadi" . (!empty($j['cost']) ? ", pul qaytarildi" : '') . ". Qayta urinib ko'ring yoki oddiy ovozni tanlang.",
                ['inline_keyboard' => [[['text' => "🎙 Ovozni o'zgartirish", 'callback_data' => 'tts:menu']]]]);
        }
    }

    public static function pending(): bool
    {
        try {
            return (bool)db()->query("SELECT 1 FROM engine_kv WHERE k LIKE 'livejob:%' LIMIT 1")->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Maintenance'dan: kutilayotgan ishlarni yetkazish. */
    public static function poll(float $until): void
    {
        if (!self::configured()) {
            return;
        }
        $ids = db()->query("SELECT substr(k, 9) FROM engine_kv WHERE k LIKE 'livejob:%' ORDER BY ts LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            if (microtime(true) > $until - 12) {
                return;
            }
            if (preg_match('/^[0-9a-f]{32}$/', (string)$id)) {
                self::check((string)$id);
            }
        }
    }

    // ------------------------------------------------------------------ admin: ovoz namunasi qo'shish (bot orqali)

    public static function sampleStart(int $tg): void
    {
        if (!AdminSecurity::isAdmin($tg)) {
            return;
        }
        if (!self::configured()) {
            Telegram::sendMessage($tg, "Avval admin panel → Tizim → «⭐ Jonli ovoz serveri» da manzil va tokenni kiriting.");
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

    /** Namuna fayli keldi (message ichidan file_id olinadi). */
    public static function sampleFile(int $tg, array $message): bool
    {
        $f = $message['video'] ?? $message['audio'] ?? $message['voice'] ?? $message['video_note'] ?? $message['document'] ?? null;
        if (!is_array($f) || empty($f['file_id'])) {
            Telegram::sendMessage($tg, "Video, audio yoki ovozli xabar yuboring (yoki «✖️ Bekor qilish»).");
            return true;
        }
        if ((int)($f['file_size'] ?? 0) > 20 * 1048576) {
            Telegram::sendMessage($tg, "Fayl 20 MB dan katta — Telegram botlari uni yuklab ololmaydi. Videoning qisqa (30 soniyalik) qismini yuboring.");
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
        Telegram::sendMessage($tg, "⏳ Namuna yuklanmoqda va serverga yuborilmoqda…");
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
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $d['name']) ?: '') ?? '');
        $key = substr(trim($slug, '_'), 0, 16) ?: 'ovoz';
        $key = (preg_match('/^[a-z]/', $key) ? $key : 'v_' . $key) . '_' . substr(bin2hex(random_bytes(2)), 0, 3);
        @set_time_limit(200);
        [$code, $res] = self::http('POST', '/voice', ['key' => $key, 'name' => $d['name'], 'gender' => $d['gender'], 'consent' => true,
            'audio_b64' => base64_encode($bin)], 150);
        if ($code === 200 && !empty($res['ok'])) {
            self::voices(true);
            AdminSecurity::audit('live_voice_add', $tg, $key);
            Telegram::sendMessage($tg, "✅ «" . Telegram::escape($d['name']) . "» ovozi qo'shildi! Endi «🔊 Matnni ovozga» → «⭐ Jonli ovozlar» da ko'rinadi.\n"
                . "Sinab ko'ring: ovozni tanlab, qisqa matn yuboring.", mainMenu());
        } else {
            Telegram::sendMessage($tg, "⚠️ Server qabul qilmadi: " . Telegram::escape(is_array($res) ? (string)($res['error'] ?? "HTTP $code") : "ulanib bo'lmadi"));
        }
    }

    // ------------------------------------------------------------------ admin panel

    public static function status(): array
    {
        $out = ['configured' => self::configured(), 'url' => self::url(), 'enabled' => (bool)Engine::c('LIVE_TTS_ENABLED', true),
            'max_chars' => self::maxChars(), 'ready' => false, 'queue' => 0, 'voices' => [], 'error' => ''];
        if (!self::configured()) {
            return $out;
        }
        [$code, $h] = self::http('GET', '/health', null, 8);
        if ($code !== 200 || !is_array($h)) {
            $out['error'] = $code === 0 ? "Serverga ulanib bo'lmadi" : "HTTP $code";
            return $out;
        }
        $out['ready'] = !empty($h['ready']);
        $out['queue'] = (int)($h['queue'] ?? 0);
        $out['error'] = (string)($h['error'] ?? '');
        $out['voices'] = self::voices(true);
        if ($out['voices'] === [] && $out['ready']) {
            $out['error'] = "Token noto'g'ri yoki ovozlar yo'q";
        }
        return $out;
    }

    public static function saveConfig(int $admin, string $url, string $token): array
    {
        $url = rtrim(trim($url), '/');
        if ($url !== '' && !preg_match('#^https?://[a-z0-9.\-]+(:\d{2,5})?$#i', $url)) {
            return ['ok' => false, 'msg' => "Manzil noto'g'ri (masalan: https://1-2-3-4.sslip.io)"];
        }
        Settings::setRaw('LIVE_TTS_URL', $url, $admin);
        if (trim($token) !== '') {
            if (!preg_match('/^[A-Za-z0-9_\-]{24,100}$/', trim($token))) {
                return ['ok' => false, 'msg' => "Token formati noto'g'ri"];
            }
            Settings::setRaw('LIVE_TTS_TOKEN', trim($token), $admin);
        }
        if ($url === '') {
            Settings::setRaw('LIVE_TTS_TOKEN', '', $admin);
        }
        db()->prepare('DELETE FROM engine_kv WHERE k = ?')->execute(['live_voices']);
        AdminSecurity::audit('live_voice_config', $admin, $url);
        $s = self::status();
        return ['ok' => $url === '' || ($s['error'] === '' && $s['voices'] !== []), 'msg' => $url === '' ? "Jonli ovoz o'chirildi"
            : ($s['error'] === '' ? "✅ Ulandi: " . count($s['voices']) . " ta ovoz" . ($s['ready'] ? '' : ' (model hali yuklanmoqda)') : "⚠️ " . $s['error'])];
    }

    public static function deleteVoice(int $admin, string $key): array
    {
        if (!preg_match('/^[a-z0-9_]{2,24}$/', $key)) {
            return ['ok' => false, 'msg' => "Kalit noto'g'ri"];
        }
        [$code, $res] = self::http('POST', '/voice/delete', ['key' => $key], 15);
        self::voices(true);
        AdminSecurity::audit('live_voice_delete', $admin, $key);
        return ['ok' => $code === 200, 'msg' => $code === 200 ? "Ovoz o'chirildi" : "Server javob bermadi"];
    }

    /** Panel: sinov — namunani adminning Telegram'iga yuboradi (navbat orqali). */
    public static function test(int $admin, string $voice, string $style): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'msg' => 'Avval manzil va tokenni saqlang'];
        }
        [$code, $d] = self::http('POST', '/tts', ['text' => "Bir bor ekan, bir yo'q ekan, qadim zamonda bir dono chol yashagan ekan. U har kuni ertalab bog'iga chiqib, gullarga suv quyar ekan.",
            'voice' => $voice, 'style' => $style], 15);
        if ($code !== 200 || empty($d['id'])) {
            return ['ok' => false, 'msg' => is_array($d) ? (string)($d['error'] ?? "HTTP $code") : "Serverga ulanib bo'lmadi"];
        }
        Engine::kvSet('livejob:' . $d['id'], json_encode(['tg' => $admin, 'cost' => 0, 'chars' => 120, 'voice' => $voice, 'style' => $style, 'msg' => 0, 'ts' => time()]));
        Engine::kick(0);
        return ['ok' => true, 'msg' => "Sinov navbatga qo'yildi — ~1 daqiqada Telegram'ingizga keladi"];
    }
}
