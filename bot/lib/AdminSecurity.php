<?php

// Eski config.php bilan ham ishlashi uchun standart qiymatlar (config.php dagi qiymat har doim ustun)
foreach ([
    'ADMIN_IDS' => [],                // qo'shimcha adminlar (Telegram ID); ADMIN_CHAT_ID har doim admin hisoblanadi
    'ADMIN_PATH' => 'admin',          // papka nomi (xohlasangiz tasodifiy nomga o'zgartiring va papkani ham qayta nomlang)
    'ADMIN_PIN_HASH' => '',           // password_hash() natijasi. Bo'sh = PIN so'ralmaydi (TAVSIYA: albatta o'rnating)
    'ADMIN_LINK_TTL' => 300,          // /admin havolasi necha soniya amal qiladi
    'ADMIN_SESSION_IDLE' => 900,      // harakatsiz 15 daqiqadan keyin sessiya tugaydi
    'ADMIN_SESSION_MAX' => 7200,      // sessiya ko'pi bilan 2 soat
    'ADMIN_INITDATA_MAX_AGE' => 600,  // Telegram imzosi ko'pi bilan 10 daqiqa eski bo'lishi mumkin
    'ADMIN_MAX_ADJUST' => 1000000,    // bir marta balansni o'zgartirishning eng katta summasi (so'm)
    'ADMIN_STEPUP_AMOUNT' => 200000,  // shundan katta o'zgartirishda PIN qayta so'raladi (PIN o'rnatilgan bo'lsa)
    'ADMIN_PIN_MAX_FAILS' => 5,       // shuncha xato PINdan keyin bloklanadi
    'ADMIN_PIN_LOCK' => 900,          // bloklash muddati (soniya)
    'ADMIN_TZ_OFFSET' => 18000,       // Toshkent: UTC+5 (kunlik statistika uchun)
] as $__k => $__v) {
    if (!defined($__k)) {
        define($__k, $__v);
    }
}

/**
 * Admin panel xavfsizligi (ko'p qatlamli):
 *
 *  1. Faqat ADMIN_CHAT_ID / ADMIN_IDS dagi akkaunt botga /admin yozganda havola beriladi (shaxsiy chatda).
 *  2. Havola tokeni: 256 bit, bazada faqat hash, 5 daqiqa, BIR MARTALIK, tugma ichida yashirin turadi va ochilgach xabar o'chiriladi.
 *  3. Telegram imzosi (initData, HMAC-SHA256, 10 daqiqadan eski emas) — havola boshqaga tushsa ham ishlamaydi.
 *  4. Ixtiyoriy PIN (password_hash) — 5 ta xatodan keyin 15 daqiqa bloklanadi; PIN tekshiruvi tokenni "yoqmaydi".
 *  5. Sessiya: alohida tasodifiy 256 bit token (Authorization: Bearer — cookie EMAS, shuning uchun CSRF mumkin emas),
 *     bitta faol sessiya, 15 daqiqa harakatsizlik / 2 soat umumiy, User-Agent o'zgarsa bekor.
 *  6. Pul bilan bog'liq amallar: idempotency kaliti (qo'sh bosish ikki marta bajarilmaydi), summa chegarasi, sabab majburiy,
 *     katta summada PIN qayta so'raladi, har bir amal jurnalga yoziladi va BARCHA adminlarga Telegram'da xabar boradi.
 *  7. Qat'iy CSP (nonce), tezlik cheklovi, barcha tashqi xatolar bir xil "404".
 */
class AdminSecurity
{
    public static function now(): int
    {
        return time();
    }

    /** @return int[] */
    public static function admins(): array
    {
        $ids = [];
        if (defined('ADMIN_CHAT_ID') && (int)ADMIN_CHAT_ID > 0) {
            $ids[] = (int)ADMIN_CHAT_ID;
        }
        foreach ((array)ADMIN_IDS as $id) {
            if ((int)$id > 0) {
                $ids[] = (int)$id;
            }
        }
        return array_values(array_unique($ids));
    }

    public static function isAdmin(int $tgId): bool
    {
        return $tgId > 0 && in_array($tgId, self::admins(), true);
    }

    public static function enabled(): bool
    {
        return PUBLIC_BASE_URL !== '' && strpos(PUBLIC_BASE_URL, 'https://') === 0 && self::admins() !== [];
    }

    public static function pinRequired(): bool
    {
        return ADMIN_PIN_HASH !== '';
    }

    // ---------------------------------------------------------------- schema

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_tokens (
            token_hash TEXT PRIMARY KEY, tg_id INTEGER NOT NULL, chat_id INTEGER, message_id INTEGER,
            created_at INTEGER, expires_at INTEGER, used_at INTEGER, revoked INTEGER DEFAULT 0)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_sessions (
            sess_hash TEXT PRIMARY KEY, tg_id INTEGER NOT NULL, ua_hash TEXT, created_at INTEGER, last_seen INTEGER,
            expires_at INTEGER, abs_expires INTEGER, revoked INTEGER DEFAULT 0, ip TEXT)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admsess_tg ON admin_sessions(tg_id)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER, ip TEXT, tg_id INTEGER, event TEXT, target TEXT, detail TEXT)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admaudit_ts ON admin_audit(ts)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_idem (
            idem TEXT PRIMARY KEY, tg_id INTEGER, ts INTEGER, result TEXT)');
        // Tezlik/xato hisoblagichi uchun edit_hits jadvalidan foydalanamiz (EditorSecurity yaratadi)
        $pdo->exec('CREATE TABLE IF NOT EXISTS edit_hits (k TEXT NOT NULL, ts INTEGER NOT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hits ON edit_hits(k, ts)');
        try {
            $pdo->exec('ALTER TABLE users ADD COLUMN banned INTEGER DEFAULT 0');
        } catch (Throwable $e) {
            // ustun allaqachon bor
        }
    }

    // ---------------------------------------------------------------- yordamchilar

    public static function ip(): string
    {
        return EditorSecurity::ip();
    }

    public static function audit(string $event, ?int $tg = null, string $target = '', string $detail = ''): void
    {
        try {
            db()->prepare('INSERT INTO admin_audit (ts, ip, tg_id, event, target, detail) VALUES (?,?,?,?,?,?)')
                ->execute([self::now(), self::ip(), $tg, $event, substr($target, 0, 80), substr($detail, 0, 400)]);
        } catch (Throwable $e) {
        }
    }

    /** true = ruxsat. Oyna ichida $max dan ko'p urinish bo'lsa false. */
    public static function rate(string $key, int $max, int $windowSec): bool
    {
        return EditorSecurity::rate('adm:' . $key, $max, $windowSec);
    }

    private static function failCount(string $key, int $window): int
    {
        $st = db()->prepare('SELECT COUNT(*) FROM edit_hits WHERE k = ? AND ts > ?');
        $st->execute(['admfail:' . $key, self::now() - $window]);
        return (int)$st->fetchColumn();
    }

    private static function addFail(string $key): void
    {
        db()->prepare('INSERT INTO edit_hits (k, ts) VALUES (?, ?)')->execute(['admfail:' . $key, self::now()]);
    }

    public static function cleanup(): void
    {
        if (mt_rand(1, 40) !== 1) {
            return;
        }
        $pdo = db();
        $now = self::now();
        $pdo->prepare('DELETE FROM admin_tokens WHERE expires_at < ?')->execute([$now - 86400]);
        $pdo->prepare('DELETE FROM admin_sessions WHERE abs_expires < ? OR revoked = 1')->execute([$now]);
        $pdo->prepare('DELETE FROM admin_idem WHERE ts < ?')->execute([$now - 86400]);
        $pdo->prepare('DELETE FROM admin_audit WHERE ts < ?')->execute([$now - 86400 * 180]);
    }

    // ---------------------------------------------------------------- HTTP sarlavhalari

    public static function sendPageHeaders(string $nonce): void
    {
        EditorSecurity::sendCommonHeaders();
        $csp = "default-src 'none'; "
            . "script-src 'nonce-$nonce' https://telegram.org; "
            . "style-src 'nonce-$nonce'; "                       // inline style="" atributlari yo'q — faqat nonce'li <style>
            . "img-src 'self' data:; font-src 'self' data:; connect-src 'self'; "
            . "media-src 'none'; object-src 'none'; worker-src 'none'; manifest-src 'none'; "
            . "base-uri 'none'; form-action 'none'; "
            . "frame-ancestors https://web.telegram.org https://*.telegram.org https://telegram.org";
        header('Content-Security-Policy: ' . $csp);
        header('Content-Type: text/html; charset=utf-8');
    }

    /** Autentifikatsiyadan oldingi barcha xatolar uchun yagona javob. */
    public static function deny(int $code = 404): void
    {
        EditorSecurity::deny($code);
    }

    /** Autentifikatsiyadan KEYIN: UI ga tushunarli JSON xato. */
    public static function fail(string $error, int $http = 400, string $msg = ''): void
    {
        EditorSecurity::sendCommonHeaders();
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $error, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------------------------------------------------------------- havola tokeni

    public static function createLaunchToken(int $tgId, int $chatId): string
    {
        $pdo = db();
        $pdo->prepare('UPDATE admin_tokens SET revoked = 1 WHERE tg_id = ? AND used_at IS NULL')->execute([$tgId]);
        $secret = EditorSecurity::newSecret();
        $now = self::now();
        $pdo->prepare('INSERT INTO admin_tokens (token_hash, tg_id, chat_id, created_at, expires_at) VALUES (?,?,?,?,?)')
            ->execute([EditorSecurity::hash($secret), $tgId, $chatId, $now, $now + ADMIN_LINK_TTL]);
        return $secret;
    }

    public static function setTokenMessage(string $secret, int $messageId): void
    {
        db()->prepare('UPDATE admin_tokens SET message_id = ? WHERE token_hash = ?')->execute([$messageId, EditorSecurity::hash($secret)]);
    }

    /** Token haqiqiymi (muddati o'tmagan, ishlatilmagan, bekor qilinmagan, egasi hali admin)? */
    public static function checkLaunchToken($secret): ?array
    {
        if (!EditorSecurity::validSecretFormat($secret)) {
            return null;
        }
        $st = db()->prepare('SELECT * FROM admin_tokens WHERE token_hash = ?');
        $st->execute([EditorSecurity::hash($secret)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['revoked'] === 1 || $row['used_at'] !== null || (int)$row['expires_at'] < self::now()
            || !self::isAdmin((int)$row['tg_id'])) {
            return null;
        }
        return $row;
    }

    /** Tokenni atomik "sarflaydi": faqat bitta so'rov muvaffaqiyatli bo'ladi (parallel urinishlardan himoya). */
    public static function consumeToken(string $tokenHash): bool
    {
        $st = db()->prepare('UPDATE admin_tokens SET used_at = ? WHERE token_hash = ? AND used_at IS NULL AND revoked = 0 AND expires_at >= ?');
        $st->execute([self::now(), $tokenHash, self::now()]);
        return $st->rowCount() === 1;
    }

    // ---------------------------------------------------------------- Telegram imzosi

    /** @return array{id:int,auth_date:int}|null */
    public static function verifyInitData($initData): ?array
    {
        if (!is_string($initData) || $initData === '' || strlen($initData) > 4096) {
            return null;
        }
        parse_str($initData, $pairs);
        if (!isset($pairs['hash']) || !is_string($pairs['hash']) || !preg_match('/^[0-9a-f]{64}$/', $pairs['hash'])) {
            return null;
        }
        $hash = $pairs['hash'];
        unset($pairs['hash']);
        ksort($pairs);
        $lines = [];
        foreach ($pairs as $k => $v) {
            if (!is_string($v)) {
                return null;
            }
            $lines[] = $k . '=' . $v;
        }
        $secret = hash_hmac('sha256', BOT_TOKEN, 'WebAppData', true);
        if (!hash_equals(hash_hmac('sha256', implode("\n", $lines), $secret), $hash)) {
            return null;
        }
        $authDate = (int)($pairs['auth_date'] ?? 0);
        if ($authDate <= 0 || $authDate > self::now() + 60 || self::now() - $authDate > ADMIN_INITDATA_MAX_AGE) {
            return null;
        }
        $user = json_decode((string)($pairs['user'] ?? ''), true);
        if (!is_array($user) || !isset($user['id']) || !is_int($user['id']) || $user['id'] <= 0) {
            return null;
        }
        return ['id' => $user['id'], 'auth_date' => $authDate];
    }

    // ---------------------------------------------------------------- PIN

    public static function pinLocked(int $tgId): int
    {
        // qaytadi: qolgan bloklash soniyalari (0 = bloklanmagan)
        $st = db()->prepare('SELECT MAX(ts) FROM edit_hits WHERE k = ? AND ts > ?');
        $st->execute(['admfail:pin:' . $tgId, self::now() - ADMIN_PIN_LOCK]);
        $last = (int)$st->fetchColumn();
        if ($last > 0 && self::failCount('pin:' . $tgId, ADMIN_PIN_LOCK) >= ADMIN_PIN_MAX_FAILS) {
            return max(1, $last + ADMIN_PIN_LOCK - self::now());
        }
        return 0;
    }

    /** PIN to'g'rimi? Xato bo'lsa hisoblagich oshadi. Bloklangan bo'lsa har doim false. */
    public static function checkPin(int $tgId, $pin): bool
    {
        if (!self::pinRequired()) {
            return true;
        }
        if (self::pinLocked($tgId) > 0) {
            return false;
        }
        if (!is_string($pin) || !preg_match('/^[0-9]{4,12}$/', $pin) || !password_verify($pin, ADMIN_PIN_HASH)) {
            self::addFail('pin:' . $tgId);
            self::addFail('pinip:' . self::ip());
            self::audit('pin_fail', $tgId);
            return false;
        }
        return true;
    }

    // ---------------------------------------------------------------- sessiya

    public static function createSession(int $tgId): string
    {
        $pdo = db();
        $pdo->prepare('UPDATE admin_sessions SET revoked = 1 WHERE tg_id = ?')->execute([$tgId]);   // bitta faol sessiya
        $secret = EditorSecurity::newSecret();
        $now = self::now();
        $pdo->prepare('INSERT INTO admin_sessions (sess_hash, tg_id, ua_hash, created_at, last_seen, expires_at, abs_expires, ip) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([EditorSecurity::hash($secret), $tgId, EditorSecurity::uaHash(), $now, $now, $now + ADMIN_SESSION_IDLE, $now + ADMIN_SESSION_MAX, self::ip()]);
        return $secret;
    }

    /** Authorization: Bearer ... bo'yicha sessiya. Qaytadi: ['tg_id'=>..., 'hash'=>..., 'expires_at'=>...] yoki null. */
    public static function session(): ?array
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($h === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp((string)$k, 'Authorization') === 0) {
                    $h = (string)$v;
                    break;
                }
            }
        }
        if (!preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/', $h, $m)) {
            return null;
        }
        $hash = EditorSecurity::hash($m[1]);
        $st = db()->prepare('SELECT * FROM admin_sessions WHERE sess_hash = ?');
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $now = self::now();
        if (!$row || (int)$row['revoked'] === 1 || (int)$row['expires_at'] < $now || (int)$row['abs_expires'] < $now
            || !self::isAdmin((int)$row['tg_id'])) {
            return null;
        }
        if (!hash_equals((string)$row['ua_hash'], EditorSecurity::uaHash())) {
            db()->prepare('UPDATE admin_sessions SET revoked = 1 WHERE sess_hash = ?')->execute([$hash]);
            self::audit('session_ua_mismatch', (int)$row['tg_id']);
            return null;
        }
        $newExp = min($now + ADMIN_SESSION_IDLE, (int)$row['abs_expires']);
        db()->prepare('UPDATE admin_sessions SET last_seen = ?, expires_at = ? WHERE sess_hash = ?')->execute([$now, $newExp, $hash]);
        return ['tg_id' => (int)$row['tg_id'], 'hash' => $hash, 'expires_at' => $newExp, 'abs_expires' => (int)$row['abs_expires']];
    }

    public static function revokeSession(string $hash): void
    {
        db()->prepare('UPDATE admin_sessions SET revoked = 1 WHERE sess_hash = ?')->execute([$hash]);
    }

    // ---------------------------------------------------------------- idempotency (qo'sh bosishdan himoya)

    /** @return array|null null = yangi (bajarish mumkin); massiv = bu kalit bilan allaqachon bajarilgan natija */
    public static function idemBegin(int $tgId, string $key): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $key)) {
            self::fail('bad_request', 400, 'Noto\'g\'ri so\'rov kaliti');
        }
        $st = db()->prepare('INSERT OR IGNORE INTO admin_idem (idem, tg_id, ts, result) VALUES (?,?,?,NULL)');
        $st->execute([$key, $tgId, self::now()]);
        if ($st->rowCount() === 1) {
            return null;
        }
        $r = db()->prepare('SELECT result FROM admin_idem WHERE idem = ? AND tg_id = ?');
        $r->execute([$key, $tgId]);
        $res = $r->fetchColumn();
        $decoded = is_string($res) ? json_decode($res, true) : null;
        return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'in_progress', 'msg' => 'So\'rov bajarilmoqda'];
    }

    public static function idemEnd(string $key, array $result): void
    {
        db()->prepare('UPDATE admin_idem SET result = ? WHERE idem = ?')->execute([json_encode($result, JSON_UNESCAPED_UNICODE), $key]);
    }

    // ---------------------------------------------------------------- xabarnoma

    /** Barcha adminlarga Telegram'da xabar (muhim amallar va kirishlar haqida — buzib kirishni darhol bilish uchun). */
    public static function notifyAdmins(string $html): void
    {
        foreach (self::admins() as $id) {
            try {
                Telegram::sendMessage($id, $html);
            } catch (Throwable $e) {
            }
        }
    }
}
