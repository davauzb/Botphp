<?php

// Eski config.php bilan ham ishlashi uchun standart qiymatlar (config.php dagi qiymat har doim ustun)
foreach ([
    'PUBLIC_BASE_URL' => '', 'EDITOR_PATH' => 'editor', 'EDITOR_STORAGE_DIR' => dirname(__DIR__) . '/storage/editor/',
    'EDITOR_LINK_TTL' => 900, 'EDITOR_SESSION_IDLE' => 2700, 'EDITOR_SESSION_MAX' => 21600, 'EDITOR_INITDATA_MAX_AGE' => 3600,
    'EDITOR_RETENTION_DAYS' => 30, 'EDITOR_MAX_IMAGES' => 40, 'EDITOR_MAX_UPLOAD_BYTES' => 8388608, 'EDITOR_TRUSTED_IP_HEADER' => '',
    'WEBHOOK_SECRET' => '',
] as $__k => $__v) {
    if (!defined($__k)) {
        define($__k, $__v);
    }
}

/**
 * Tahrirlovchi xavfsizligi.
 *
 *  - Havola (token): 256 bit tasodifiy, bazada faqat SHA-256 hash'i saqlanadi, 15 daqiqa amal qiladi,
 *    foydalanuvchiga FAQAT "web_app" tugmasi ichida beriladi (matn sifatida hech qachon ko'rsatilmaydi).
 *  - Telegram imzosi (initData, HMAC-SHA256): token bor bo'lsa ham, Telegram ichidan ochilgan va
 *    egasining akkaunti ekani tekshiriladi — havola boshqaga tushsa ham ishlamaydi.
 *  - Sessiya: yana bir tasodifiy 256 bit token (Authorization header). Taqdimotga bitta faol sessiya,
 *    harakatsizlikda tugaydi, brauzer (User-Agent) o'zgarsa bekor qilinadi.
 *  - Tezlik cheklovi va vaqtinchalik bloklash; barcha xatolar bir xil "404" bilan qaytadi.
 */
class EditorSecurity
{
    public static function enabled(): bool
    {
        return PUBLIC_BASE_URL !== '' && strpos(PUBLIC_BASE_URL, 'https://') === 0 && PptxElements::available();
    }

    public static function now(): int
    {
        return time();
    }

    // ---------------------------------------------------------------- schema

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS presentations (
            id TEXT PRIMARY KEY, telegram_id INTEGER NOT NULL, title TEXT, design_key TEXT, design_json TEXT,
            data TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1, created_at INTEGER, updated_at INTEGER, exported_at INTEGER)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pres_user ON presentations(telegram_id, created_at)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS pres_images (
            id TEXT PRIMARY KEY, pres_id TEXT NOT NULL, ext TEXT NOT NULL, bytes INTEGER, w INTEGER, h INTEGER, created_at INTEGER)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_img_pres ON pres_images(pres_id)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS edit_tokens (
            token_hash TEXT PRIMARY KEY, pres_id TEXT NOT NULL, telegram_id INTEGER NOT NULL, chat_id INTEGER, message_id INTEGER,
            created_at INTEGER, expires_at INTEGER, opened_at INTEGER, revoked INTEGER DEFAULT 0)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tok_pres ON edit_tokens(pres_id)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS edit_sessions (
            sess_hash TEXT PRIMARY KEY, pres_id TEXT NOT NULL, telegram_id INTEGER NOT NULL, ua_hash TEXT, created_at INTEGER,
            last_seen INTEGER, expires_at INTEGER, abs_expires INTEGER, revoked INTEGER DEFAULT 0, ip TEXT)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sess_pres ON edit_sessions(pres_id)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS edit_hits (k TEXT NOT NULL, ts INTEGER NOT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hits ON edit_hits(k, ts)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS edit_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER, ip TEXT, telegram_id INTEGER, pres_id TEXT, event TEXT, detail TEXT)');
    }

    // ---------------------------------------------------------------- yordamchilar

    public static function newSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function validSecretFormat($s): bool
    {
        return is_string($s) && preg_match('/^[A-Za-z0-9_-]{43}$/', $s) === 1;
    }

    public static function ip(): string
    {
        $h = EDITOR_TRUSTED_IP_HEADER;
        $ip = ($h !== '' && !empty($_SERVER[$h])) ? trim(explode(',', (string)$_SERVER[$h])[0]) : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public static function uaHash(): string
    {
        return hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    public static function audit(string $event, ?int $tg = null, ?string $pid = null, string $detail = ''): void
    {
        try {
            db()->prepare('INSERT INTO edit_audit (ts, ip, telegram_id, pres_id, event, detail) VALUES (?,?,?,?,?,?)')
                ->execute([self::now(), self::ip(), $tg, $pid, $event, substr($detail, 0, 300)]);
        } catch (Throwable $e) {
        }
    }

    /** true = ruxsat. Oyna ichida $max dan ko'p urinish bo'lsa false. */
    public static function rate(string $key, int $max, int $windowSec): bool
    {
        $pdo = db();
        $now = self::now();
        $st = $pdo->prepare('SELECT COUNT(*) FROM edit_hits WHERE k = ? AND ts > ?');
        $st->execute([$key, $now - $windowSec]);
        if ((int)$st->fetchColumn() >= $max) {
            return false;
        }
        $pdo->prepare('INSERT INTO edit_hits (k, ts) VALUES (?, ?)')->execute([$key, $now]);
        return true;
    }

    public static function cleanup(): void
    {
        if (mt_rand(1, 40) !== 1) {
            return;
        }
        $pdo = db();
        $now = self::now();
        $pdo->prepare('DELETE FROM edit_hits WHERE ts < ?')->execute([$now - 7200]);
        $pdo->prepare('DELETE FROM edit_tokens WHERE expires_at < ?')->execute([$now - 86400]);
        $pdo->prepare('DELETE FROM edit_sessions WHERE abs_expires < ? OR revoked = 1')->execute([$now]);
        $pdo->prepare('DELETE FROM edit_audit WHERE ts < ?')->execute([$now - 86400 * 30]);
        // Eskirgan taqdimotlar va ularning rasmlari
        $old = $pdo->prepare('SELECT id FROM presentations WHERE updated_at < ?');
        $old->execute([$now - 86400 * EDITOR_RETENTION_DAYS]);
        foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            EditorStore::deletePresentation((string)$pid);
        }
        if (class_exists('DocStore')) {
            $oldDocs = $pdo->prepare('SELECT id FROM documents WHERE updated_at < ?');
            $oldDocs->execute([$now - 86400 * EDITOR_RETENTION_DAYS]);
            foreach ($oldDocs->fetchAll(PDO::FETCH_COLUMN) as $did) {
                DocStore::delete((string)$did);
            }
        }
    }

    // ---------------------------------------------------------------- HTTP sarlavhalari

    public static function sendCommonHeaders(): void
    {
        header_remove('X-Powered-By');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    public static function sendPageHeaders(string $nonce): void
    {
        self::sendCommonHeaders();
        $csp = "default-src 'none'; "
            . "script-src 'nonce-$nonce' https://telegram.org; "
            . "style-src 'unsafe-inline'; "                       // faqat inline uslublar (element joylashuvi); tashqi stil yo'q
            . "img-src 'self' data: blob:; "
            . "font-src 'self' data:; "
            . "connect-src 'self'; "
            . "media-src 'none'; object-src 'none'; worker-src 'none'; manifest-src 'none'; "
            . "base-uri 'none'; form-action 'none'; "
            . "frame-ancestors https://web.telegram.org https://*.telegram.org https://telegram.org";
        header('Content-Security-Policy: ' . $csp);
        header('Content-Type: text/html; charset=utf-8');
    }

    /** Hamma xatolar uchun yagona javob: sahifa mavjud emasdek ko'rinadi. */
    public static function deny(int $code = 404): void
    {
        self::sendCommonHeaders();
        http_response_code($code === 429 ? 429 : 404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        exit;
    }

    // ---------------------------------------------------------------- havola tokeni

    /** Yangi havola tokeni yaratadi (avvalgilarini bekor qiladi). Ochiq token faqat bir marta qaytadi. */
    public static function createLaunchToken(string $pid, int $tgId, int $chatId): string
    {
        $pdo = db();
        $pdo->prepare('UPDATE edit_tokens SET revoked = 1 WHERE pres_id = ? AND telegram_id = ?')->execute([$pid, $tgId]);
        $secret = self::newSecret();
        $now = self::now();
        $pdo->prepare('INSERT INTO edit_tokens (token_hash, pres_id, telegram_id, chat_id, created_at, expires_at) VALUES (?,?,?,?,?,?)')
            ->execute([self::hash($secret), $pid, $tgId, $chatId, $now, $now + EDITOR_LINK_TTL]);
        return $secret;
    }

    public static function setTokenMessage(string $secret, int $messageId): void
    {
        db()->prepare('UPDATE edit_tokens SET message_id = ? WHERE token_hash = ?')->execute([$messageId, self::hash($secret)]);
    }

    /** Token haqiqiymi (muddati o'tmagan, bekor qilinmagan)? Qaytadi: qator yoki null. */
    public static function checkLaunchToken($secret): ?array
    {
        if (!self::validSecretFormat($secret)) {
            return null;
        }
        $st = db()->prepare('SELECT * FROM edit_tokens WHERE token_hash = ?');
        $st->execute([self::hash($secret)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['revoked'] === 1 || (int)$row['expires_at'] < self::now()) {
            return null;
        }
        return $row;
    }

    // ---------------------------------------------------------------- Telegram imzosi

    /** initData'ni tekshiradi. Qaytadi: ['id'=>telegram_id, 'auth_date'=>ts] yoki null. */
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
        $calc = hash_hmac('sha256', implode("\n", $lines), $secret);
        if (!hash_equals($calc, $hash)) {
            return null;
        }
        $authDate = (int)($pairs['auth_date'] ?? 0);
        if ($authDate <= 0 || $authDate > self::now() + 60 || self::now() - $authDate > EDITOR_INITDATA_MAX_AGE) {
            return null;
        }
        $user = json_decode((string)($pairs['user'] ?? ''), true);
        if (!is_array($user) || !isset($user['id']) || !is_int($user['id']) || $user['id'] <= 0) {
            return null;
        }
        return ['id' => $user['id'], 'auth_date' => $authDate];
    }

    // ---------------------------------------------------------------- sessiya

    public static function createSession(string $pid, int $tgId): string
    {
        $pdo = db();
        $pdo->prepare('UPDATE edit_sessions SET revoked = 1 WHERE pres_id = ?')->execute([$pid]); // bitta faol sessiya
        $secret = self::newSecret();
        $now = self::now();
        $pdo->prepare('INSERT INTO edit_sessions (sess_hash, pres_id, telegram_id, ua_hash, created_at, last_seen, expires_at, abs_expires, ip)
                       VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([self::hash($secret), $pid, $tgId, self::uaHash(), $now, $now, $now + EDITOR_SESSION_IDLE, $now + EDITOR_SESSION_MAX, self::ip()]);
        return $secret;
    }

    /** Authorization: Bearer ... bo'yicha sessiyani topadi va (harakat bo'lsa) muddatini uzaytiradi. */
    public static function session(): ?array
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($h === '' && function_exists('getallheaders')) {      // ba'zi FastCGI sozlamalarida faqat shu yerda bo'ladi
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp((string)$k, 'Authorization') === 0) {
                    $h = (string)$v;
                    break;
                }
            }
        }
        if (!preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/', (string)$h, $m)) {
            return null;
        }
        $hash = self::hash($m[1]);
        $st = db()->prepare('SELECT * FROM edit_sessions WHERE sess_hash = ?');
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $now = self::now();
        if (!$row || (int)$row['revoked'] === 1 || (int)$row['expires_at'] < $now || (int)$row['abs_expires'] < $now) {
            return null;
        }
        if (!hash_equals((string)$row['ua_hash'], self::uaHash())) {
            db()->prepare('UPDATE edit_sessions SET revoked = 1 WHERE sess_hash = ?')->execute([$hash]);
            self::audit('session_ua_mismatch', (int)$row['telegram_id'], $row['pres_id']);
            return null;
        }
        $newExp = min($now + EDITOR_SESSION_IDLE, (int)$row['abs_expires']);
        db()->prepare('UPDATE edit_sessions SET last_seen = ?, expires_at = ? WHERE sess_hash = ?')->execute([$now, $newExp, $hash]);
        $row['sess_hash'] = $hash;
        return $row;
    }

    public static function revokeSession(string $hash): void
    {
        db()->prepare('UPDATE edit_sessions SET revoked = 1 WHERE sess_hash = ?')->execute([$hash]);
    }

    public static function revokeAllFor(string $pid): void
    {
        db()->prepare('UPDATE edit_sessions SET revoked = 1 WHERE pres_id = ?')->execute([$pid]);
        db()->prepare('UPDATE edit_tokens SET revoked = 1 WHERE pres_id = ?')->execute([$pid]);
    }
}
