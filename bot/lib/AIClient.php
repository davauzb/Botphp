<?php

/**
 * AI marshrutlovchi (router).
 *
 * "Marshrut" = provayder + API kalit + model. config.php dagi AI_PROVIDERS dan hamma marshrutlar tuziladi
 * (provayderda 'models' => [...] bo'lsa, har bir model alohida marshrut hisoblanadi).
 *
 *  - Har chaqiruvda eng "bo'sh va sog'lom" marshrut tanlanadi (band emas, sovitishda emas, kam xato qilgan).
 *  - Marshrut xato bersa, xatoning TURIGA qarab "sovitiladi" va darhol keyingisiga o'tiladi:
 *       429 limit      → Retry-After bo'yicha (yo'q bo'lsa 30s,60s,120s… 10 daqiqagacha; kunlik limit → 30 daq)
 *       5xx / timeout  → qisqa (15s,30s,60s… 5 daqiqagacha)
 *       402 / 403 / 404 → uzoq (6 soat)      401 → butunlay o'chiriladi (kalit yaroqsiz)
 *  - Bir kalitga hamma yopirilmasligi uchun har marshrutga parallel chaqiruv chegarasi (lease) bor.
 *  - Hamma marshrut vaqtincha band/sovitishda bo'lsa — ish YIQILMAYDI: AIUnavailableException (qachon qayta urinish
 *    kerakligi bilan) tashlanadi, Engine qadamni shu vaqtga kechiktiradi va avtomatik davom etadi.
 */
class AIClient
{
    /** Hozirgi tick uchun "shu vaqtgacha tugash kerak" chegarasi (unix, float). null = cheklov yo'q. */
    public static ?float $deadline = null;
    public static int $defaultTimeout = 110;
    public static int $maxWaitInline = 6;     // hamma marshrut band bo'lsa, shuncha soniya o'zimiz kutamiz

    public static function setDeadline(?float $ts): void
    {
        self::$deadline = $ts;
    }

    // ------------------------------------------------------------------ ommaviy API

    public static function generateText(string $prompt, string $systemPrompt = '', int $maxTokens = 4096, array $opt = []): string
    {
        $temperature = $opt['temperature'] ?? 0.7;
        $lastError = '';

        for ($pass = 0; $pass < 3; $pass++) {
            $routes = self::availableRoutes();

            if (!$routes) {
                $wait = self::soonestAvailability();
                if ($wait === null) {
                    self::alertOnce('ai_all_dead', "Barcha AI kalitlar yaroqsiz yoki o'chirilgan! config.php dagi kalitlarni tekshiring.");
                    throw new RuntimeException('Faol AI kalit yo\'q: ' . $lastError);
                }
                $room = self::$deadline !== null ? (self::$deadline - microtime(true) - 6) : 1e9;
                if ($wait <= self::$maxWaitInline && $wait < $room) {
                    usleep((int)(($wait + 0.3) * 1e6));
                    continue;
                }
                throw new AIUnavailableException('Barcha AI marshrutlar band yoki sovitishda', max(3, $wait));
            }

            foreach ($routes as $r) {
                $timeout = self::$defaultTimeout;
                if (self::$deadline !== null) {
                    $timeout = (int)min($timeout, floor(self::$deadline - microtime(true) - 2));
                    if ($timeout < 10) {
                        throw new AIUnavailableException('Tick vaqti tugayapti — keyingi tickda davom etiladi', 1);
                    }
                }
                $lease = self::acquire($r);
                if (!$lease) {
                    continue; // band — keyingisi
                }
                $t0 = microtime(true);
                try {
                    $text = self::call($r, $prompt, $systemPrompt, $maxTokens, $timeout, (float)$temperature);
                    self::release($lease);
                    self::markSuccess($r, (int)((microtime(true) - $t0) * 1000));
                    return $text;
                } catch (AIRouteException $e) {
                    self::release($lease);
                    self::markFailure($r, $e);
                    $lastError = $r['provider'] . '/' . $r['model'] . ': ' . $e->getMessage();
                    continue;
                } catch (AIBadRequestException $e) {
                    self::release($lease);
                    throw $e;                       // so'rovning o'zida muammo — marshrutni ayblamaymiz
                } catch (Throwable $e) {
                    self::release($lease);
                    logError('ai_client', "Kutilmagan xato ({$r['provider']}): " . $e->getMessage());
                    $lastError = $e->getMessage();
                    continue;
                }
            }
            usleep(400000);
        }

        throw new AIUnavailableException('AI vaqtincha javob bermadi: ' . $lastError, 20);
    }

    /** Diagnostika uchun: marshrutlar holati. */
    public static function status(): array
    {
        self::syncRoutes();
        $now = time();
        $rows = db()->query('SELECT * FROM ai_routes ORDER BY provider, model')->fetchAll(PDO::FETCH_ASSOC);
        $out = ['total' => 0, 'ready' => 0, 'cooling' => 0, 'dead' => 0, 'routes' => []];
        foreach ($rows as $r) {
            if (!self::isConfigured($r['route'])) {
                continue;
            }
            $out['total']++;
            $state = (int)$r['perm'] === 1 ? 'dead' : ((int)$r['cooldown_until'] > $now ? 'cooling' : 'ready');
            $out[$state]++;
            $out['routes'][] = [
                'provider' => $r['provider'], 'model' => $r['model'], 'key' => '…' . $r['key_tail'], 'state' => $state,
                'wait' => max(0, (int)$r['cooldown_until'] - $now), 'ok' => (int)$r['ok_count'], 'err' => (int)$r['err_count'],
                'lat' => (int)$r['lat_ms'], 'last_error' => (string)$r['last_error'],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------ marshrutlar

    private static function configuredRoutes(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        foreach (AI_PROVIDERS as $p) {
            $models = $p['models'] ?? [$p['model'] ?? ''];
            foreach ($p['keys'] as $key) {
                if (!is_string($key) || $key === '' || preg_match('/_API_KEY_\d+$|^PUT_|YOUR_/i', $key)) {
                    continue; // hali to'ldirilmagan namuna kalit
                }
                foreach ($models as $m) {
                    if ($m === '') {
                        continue;
                    }
                    $id = substr(sha1($p['name'] . '|' . $key . '|' . $m), 0, 20);
                    $cache[$id] = [
                        'route' => $id, 'provider' => $p['name'], 'model' => $m, 'endpoint' => $p['endpoint'], 'api_key' => $key,
                        'key_tail' => substr($key, -4), 'max_parallel' => (int)($p['max_parallel'] ?? 2),
                        'headers' => $p['headers'] ?? [], 'timeout' => (int)($p['timeout'] ?? 0),
                        'max_tokens_cap' => (int)($p['max_tokens_cap'] ?? 0),
                        'priority' => (int)($p['priority'] ?? 5),
                    ];
                }
            }
        }
        return $cache;
    }

    private static function isConfigured(string $route): bool
    {
        return isset(self::configuredRoutes()[$route]);
    }

    private static function syncRoutes(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $stmt = db()->prepare('INSERT OR IGNORE INTO ai_routes (route, provider, model, key_tail) VALUES (?,?,?,?)');
        foreach (self::configuredRoutes() as $r) {
            $stmt->execute([$r['route'], $r['provider'], $r['model'], $r['key_tail']]);
        }
        $done = true;
    }

    private static function availableRoutes(): array
    {
        self::syncRoutes();
        $now = time();
        db()->prepare('DELETE FROM ai_leases WHERE expires < ?')->execute([$now]);
        $rows = db()->query(
            'SELECT r.*, (SELECT COUNT(*) FROM ai_leases l WHERE l.route = r.route AND l.expires >= ' . $now . ') AS busy
             FROM ai_routes r WHERE r.perm = 0 AND r.cooldown_until <= ' . $now
        )->fetchAll(PDO::FETCH_ASSOC);
        $cfg = self::configuredRoutes();
        $out = [];
        foreach ($rows as $row) {
            if (!isset($cfg[$row['route']])) {
                continue;
            }
            $c = $cfg[$row['route']];
            if ((int)$row['busy'] >= max(1, $c['max_parallel'])) {
                continue;
            }
            $out[] = $c + [
                'busy' => (int)$row['busy'], 'streak' => (int)$row['fail_streak'], 'last_used' => (int)$row['last_used'],
                'lat' => (int)$row['lat_ms'], 'ok_count' => (int)$row['ok_count'],
            ];
        }
        // avval provayder ustuvorligi (priority) → eng kam band → eng kam xato → eng uzoq ishlatilmagan;
        // ozgina tasodifiylik bir xil ustuvorlikdagi marshrutlar yukini tekis taqsimlaydi
        usort($out, function ($a, $b) {
            return [$a['priority'], $a['busy'], $a['streak'], $a['last_used'] + mt_rand(0, 2)] <=> [$b['priority'], $b['busy'], $b['streak'], $b['last_used'] + mt_rand(0, 2)];
        });
        return $out;
    }

    /** Eng yaqin qachon biror marshrut bo'shashini hisoblaydi (soniya). null = hammasi doimiy o'lik/yo'q. */
    private static function soonestAvailability(): ?float
    {
        self::syncRoutes();
        $now = time();
        $best = null;
        $cfg = self::configuredRoutes();
        foreach (db()->query('SELECT route, cooldown_until, perm FROM ai_routes')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($cfg[$r['route']]) || (int)$r['perm'] === 1) {
                continue;
            }
            $w = max(0, (int)$r['cooldown_until'] - $now);
            $best = $best === null ? $w : min($best, $w);
        }
        if ($best !== null && $best == 0) {
            return 1.0; // sovigan emas, faqat band
        }
        return $best === null ? null : (float)$best;
    }

    private static function acquire(array $r): ?int
    {
        $pdo = db();
        $now = time();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $st = $pdo->prepare('SELECT COUNT(*) FROM ai_leases WHERE route = ? AND expires >= ?');
            $st->execute([$r['route'], $now]);
            if ((int)$st->fetchColumn() >= max(1, $r['max_parallel'])) {
                $pdo->exec('COMMIT');
                return null;
            }
            $pdo->prepare('INSERT INTO ai_leases (route, expires) VALUES (?, ?)')->execute([$r['route'], $now + self::$defaultTimeout + 15]);
            $id = (int)$pdo->lastInsertId();
            $pdo->exec('COMMIT');
            return $id;
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable $e2) {
            }
            return null;
        }
    }

    private static function release(?int $lease): void
    {
        if ($lease) {
            try {
                db()->prepare('DELETE FROM ai_leases WHERE id = ?')->execute([$lease]);
            } catch (Throwable $e) {
            }
        }
    }

    // ------------------------------------------------------------------ chaqiruv

    private static function call(array $r, string $prompt, string $systemPrompt, int $maxTokens, int $timeout, float $temperature): string
    {
        $messages = [];
        if ($systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];
        if ($r['max_tokens_cap'] > 0) {
            $maxTokens = min($maxTokens, $r['max_tokens_cap']);
        }
        if ($r['timeout'] > 0) {
            $timeout = min($timeout, $r['timeout']);
        }

        $respHeaders = [];
        $ch = curl_init($r['endpoint']);
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $r['api_key']];
        foreach ($r['headers'] as $k => $v) {
            $headers[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                $p = explode(':', $line, 2);
                if (count($p) === 2) {
                    $respHeaders[strtolower(trim($p[0]))] = trim($p[1]);
                }
                return strlen($line);
            },
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $r['model'],
                'messages' => $messages,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,   // belgilanmasa provider javobni o'rtasida kesib qo'yadi (real xato bo'lgan)
            ]),
        ]);
        $response = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $response === false) {
            $kind = $errno === 28 ? 'timeout' : 'network';
            throw new AIRouteException("$kind (curl=$errno)", $kind, 0, false);
        }
        $retryAfter = self::parseRetryAfter($respHeaders);
        $body = (string)$response;

        if ($code === 401) {
            throw new AIRouteException('Kalit yaroqsiz (401)', 'auth', 0, true);
        }
        if ($code === 402 || $code === 403) {
            throw new AIRouteException("Ruxsat/kredit yo'q ($code)", 'forbidden', 6 * 3600, false);
        }
        if ($code === 404) {
            throw new AIRouteException('Model/endpoint topilmadi (404)', 'notfound', 6 * 3600, false);
        }
        if ($code === 429) {
            $daily = (bool)preg_match('/per day|daily|quota|exceeded your current|tokens per day|TPD|RPD/i', $body);
            throw new AIRouteException('Limit tugagan (429)', 'ratelimit', $retryAfter ?? ($daily ? 1800 : 0), false);
        }
        if ($code === 400 || $code === 413 || $code === 422) {
            if (preg_match('/context|too long|maximum|max_tokens|token/i', $body)) {
                throw new AIBadRequestException("So'rov mos kelmadi ($code): " . substr($body, 0, 200));
            }
            throw new AIRouteException("Noto'g'ri so'rov ($code) " . substr($body, 0, 120), 'badreq', 120, false);
        }
        if ($code >= 500 || $code === 408 || $code === 0) {
            throw new AIRouteException("Provider xatosi ($code)", 'server', $retryAfter ?? 0, false);
        }
        if ($code >= 400) {
            throw new AIRouteException("HTTP $code", 'other', 120, false);
        }

        $data = json_decode($body, true);
        $text = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new AIRouteException("Bo'sh javob", 'empty', 45, false);
        }
        if (($data['choices'][0]['finish_reason'] ?? '') === 'length') {
            logError('ai_client', "Javob max_tokens ($maxTokens) tufayli kesildi ({$r['provider']}/{$r['model']}).");
        }
        $text = preg_replace('#<think>.*?</think>#su', '', $text) ?? $text;   // ba'zi modellarning "o'ylash" qismi
        return trim($text);
    }

    private static function parseRetryAfter(array $h): ?int
    {
        foreach (['retry-after', 'x-ratelimit-reset-requests', 'x-ratelimit-reset-tokens', 'x-ratelimit-reset'] as $k) {
            if (!isset($h[$k])) {
                continue;
            }
            $v = trim($h[$k]);
            if ($v === '') {
                continue;
            }
            if (is_numeric($v)) {
                $n = (float)$v;
                return $n > 1e9 ? max(1, (int)($n - time())) : max(1, (int)ceil($n));
            }
            if (preg_match('/^(?:(\d+)h)?(?:(\d+)m(?!s))?(?:([\d.]+)s)?$/', $v, $m)) {
                return max(1, (int)(((int)($m[1] ?? 0)) * 3600 + ((int)($m[2] ?? 0)) * 60 + ceil((float)($m[3] ?? 0))));
            }
            $ts = strtotime($v);
            if ($ts) {
                return max(1, $ts - time());
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ holat yozish

    private static function markSuccess(array $r, int $latMs): void
    {
        db()->prepare(
            'UPDATE ai_routes SET fail_streak = 0, ok_count = ok_count + 1, last_used = ?, lat_ms = (lat_ms * 3 + ?) / 4, last_error = NULL WHERE route = ?'
        )->execute([time(), $latMs, $r['route']]);
    }

    private static function markFailure(array $r, AIRouteException $e): void
    {
        $pdo = db();
        $st = $pdo->prepare('SELECT fail_streak FROM ai_routes WHERE route = ?');
        $st->execute([$r['route']]);
        $streak = (int)$st->fetchColumn() + 1;
        $now = time();
        $perm = 0;
        $cool = $e->cooldown;
        if ($e->permanent) {
            $perm = 1;
            $cool = 0;
        } elseif ($cool <= 0) {
            $cool = $e->kind === 'ratelimit'
                ? min(600, 30 * (2 ** min(5, $streak - 1)))
                : min(300, 15 * (2 ** min(5, $streak - 1)));
        }
        $cool = min($cool, 6 * 3600);
        $pdo->prepare(
            'UPDATE ai_routes SET fail_streak = ?, err_count = err_count + 1, last_used = ?, cooldown_until = ?, perm = ?, last_error = ? WHERE route = ?'
        )->execute([$streak, $now, $now + (int)$cool, $perm, substr($e->getMessage(), 0, 160), $r['route']]);

        logError('ai_client', "{$r['provider']}/{$r['model']} …{$r['key_tail']} → {$e->getMessage()} [{$e->kind}] sovitish: {$cool}s");

        if ($perm) {
            self::alertOnce('ai_perm_' . $r['route'], "AI kalit butunlay o'chirildi (yaroqsiz): {$r['provider']} …{$r['key_tail']}");
        }
        $ready = (int)$pdo->query('SELECT COUNT(*) FROM ai_routes WHERE perm = 0 AND cooldown_until <= ' . $now)->fetchColumn();
        if ($ready === 0) {
            self::alertOnce('ai_all_cooling', "Hamma AI marshrutlar vaqtincha limitda/xatoda. Ishlar to'xtamaydi — avtomatik kutib davom etadi. Oxirgi: " . $e->getMessage());
        }
    }

    /** Admin'ga bir xil ogohlantirishni $every soniyada ko'pi bilan 1 marta yuboradi. */
    public static function alertOnce(string $key, string $message, int $every = 1800): void
    {
        try {
            $pdo = db();
            $now = time();
            $st = $pdo->prepare('SELECT ts FROM engine_kv WHERE k = ?');
            $st->execute(['alert_' . $key]);
            $ts = (int)$st->fetchColumn();
            if ($ts && $now - $ts < $every) {
                return;
            }
            $pdo->prepare('INSERT INTO engine_kv (k, v, ts) VALUES (?, ?, ?) ON CONFLICT(k) DO UPDATE SET ts = excluded.ts, v = excluded.v')
                ->execute(['alert_' . $key, '1', $now]);
            if (class_exists('Telegram')) {
                Telegram::notifyAdmin(Telegram::escape($message));
            }
        } catch (Throwable $e) {
        }
    }
}

/** Marshrutning o'ziga xos muammosi (limit, server xatosi…) — keyingi marshrutga o'tiladi. */
class AIRouteException extends RuntimeException
{
    public string $kind;
    public int $cooldown;
    public bool $permanent;

    public function __construct(string $message, string $kind = 'other', int $cooldown = 0, bool $permanent = false)
    {
        parent::__construct($message);
        $this->kind = $kind;
        $this->cooldown = $cooldown;
        $this->permanent = $permanent;
    }
}

/** So'rovning o'zida muammo (juda uzun kontekst va h.k.) — marshrut aybsiz. */
class AIBadRequestException extends RuntimeException
{
}

/** Hozir hech bir marshrut ishlata olmaydi — $retryAfter soniyadan keyin qayta urinish kerak. */
class AIUnavailableException extends RuntimeException
{
    public float $retryAfter;

    public function __construct(string $message, float $retryAfter)
    {
        parent::__construct($message);
        $this->retryAfter = $retryAfter;
    }
}
