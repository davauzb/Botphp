<?php

/**
 * ISH MEXANIZMI (cron kerak emas).
 *
 *  - Har bir ish (mustaqil ish, referat, slayd) kichik QADAMLARGA bo'linadi (reja → bo'limlar → fayl → yuborish).
 *    Har qadam natijasi bazaga yoziladi, shuning uchun jarayon uzilsa ham ish QOLGAN joyidan davom etadi.
 *  - "Tick" — qisqa (≈40s) HTTP so'rov (tick.php). U navbatdagi ishlarni "ijaraga" (lease) olib, bir necha qadamni bajaradi,
 *    foizni yangilaydi va tugagach o'zini yana ishga tushiradi. Bir vaqtda bir nechta tick (ENGINE_MAX_PARALLEL) turli ishlarni bajaradi.
 *  - Ishni "uyg'otish": yangi buyurtma, har bir bot so'rovi (watchdog), foydalanuvchi «🔄 Holat» tugmasi, o'z-o'zini zanjirlash.
 *  - Hamma AI marshrutlar band bo'lsa ish yiqilmaydi — kutib, avtomatik davom etadi.
 */
class Engine
{
    public const STEP_ATTEMPTS = 4;

    // ------------------------------------------------------------------ sozlamalar

    public static function c(string $name, $default)
    {
        // admin paneldagi «Sozlamalar» (bazada) > config.php > standart
        return class_exists('Settings', false) ? Settings::get($name, $default) : (defined($name) ? constant($name) : $default);
    }

    public static function secret(): string
    {
        return hash_hmac('sha256', 'engine-tick-v1', BOT_TOKEN);
    }

    public static function baseUrl(): string
    {
        $b = (string)self::c('ENGINE_BASE_URL', '');
        return rtrim($b !== '' ? $b : (string)self::c('PUBLIC_BASE_URL', ''), '/');
    }

    public static function maxParallel(): int
    {
        return max(1, (int)self::c('ENGINE_MAX_PARALLEL', 3));
    }

    /** Bitta tick yangi qadam boshlashi mumkin bo'lgan vaqt (soniya, devor soati). */
    public static function tickBudget(): int
    {
        return max(10, (int)self::c('ENGINE_TICK_SECONDS', 40));
    }

    /** Bitta so'rov umri chegarasi: shundan keyin hosting uzib qo'yishi mumkin. */
    public static function hardLimit(): int
    {
        return max(20, (int)self::c('ENGINE_HARD_SECONDS', 100));
    }

    // ------------------------------------------------------------------ sxema

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS ai_routes (
            route TEXT PRIMARY KEY, provider TEXT, model TEXT, key_tail TEXT, cooldown_until INTEGER DEFAULT 0, fail_streak INTEGER DEFAULT 0,
            perm INTEGER DEFAULT 0, last_used INTEGER DEFAULT 0, ok_count INTEGER DEFAULT 0, err_count INTEGER DEFAULT 0, lat_ms INTEGER DEFAULT 0, last_error TEXT)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS ai_leases (id INTEGER PRIMARY KEY AUTOINCREMENT, route TEXT NOT NULL, expires INTEGER NOT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_leases_route ON ai_leases(route, expires)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS engine_ticks (id TEXT PRIMARY KEY, started INTEGER, beat INTEGER, expires INTEGER)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS engine_kv (k TEXT PRIMARY KEY, v TEXT, ts INTEGER)');

        $have = [];
        foreach ($pdo->query('PRAGMA table_info(jobs)')->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $have[$col['name']] = true;
        }
        $add = [
            'state' => 'TEXT', 'progress' => 'INTEGER DEFAULT 0', 'phase' => 'TEXT', 'pmsg_id' => 'INTEGER', 'pmsg_chat' => 'INTEGER',
            'lease_until' => 'INTEGER DEFAULT 0', 'lease_owner' => 'TEXT', 'next_run_at' => 'INTEGER DEFAULT 0',
            'last_push' => 'INTEGER DEFAULT 0', 'last_hash' => 'TEXT', 'started_at' => 'INTEGER', 'last_tick' => 'INTEGER DEFAULT 0',
            'wait_total' => 'INTEGER DEFAULT 0', 'cancel_req' => 'INTEGER DEFAULT 0', 'finished_at' => 'INTEGER', 'plan_action' => 'TEXT',
            'tg_file_id' => 'TEXT', 'tg_file_name' => 'TEXT', 'pres_id' => 'TEXT',
        ];
        foreach ($add as $name => $def) {
            if (!isset($have[$name])) {
                try {
                    $pdo->exec("ALTER TABLE jobs ADD COLUMN $name $def");
                } catch (Throwable $e) {
                }
            }
        }
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_jobs_runnable ON jobs(status, lease_until, next_run_at)");
    }

    // ------------------------------------------------------------------ kv

    public static function kvGet(string $k): ?array
    {
        $st = db()->prepare('SELECT v, ts FROM engine_kv WHERE k = ?');
        $st->execute([$k]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public static function kvSet(string $k, string $v): void
    {
        db()->prepare('INSERT INTO engine_kv (k, v, ts) VALUES (?, ?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v, ts = excluded.ts')
            ->execute([$k, $v, time()]);
    }

    // ------------------------------------------------------------------ uyg'otish (kick)

    public static function activeTicks(): int
    {
        $st = db()->prepare('SELECT COUNT(*) FROM engine_ticks WHERE expires > ?');
        $st->execute([time()]);
        return (int)$st->fetchColumn();
    }

    public static function runnableCount(): int
    {
        $st = db()->prepare("SELECT COUNT(*) FROM jobs WHERE status IN ('pending','processing') AND lease_until <= ? AND next_run_at <= ?");
        $now = time();
        $st->execute([$now, $now]);
        return (int)$st->fetchColumn();
    }

    /** Eng yaqin kechiktirilgan ish necha soniyadan keyin tayyor bo'ladi (null = yo'q). */
    public static function nextDelay(): ?int
    {
        $now = time();
        $st = db()->prepare("SELECT MIN(MAX(next_run_at, lease_until)) FROM jobs WHERE status IN ('pending','processing') AND (next_run_at > ? OR lease_until > ?)");
        $st->execute([$now, $now]);
        $v = $st->fetchColumn();
        return $v ? max(1, (int)$v - $now) : null;
    }

    /**
     * Yangi tick so'rovini yuboradi (javobini kutmaydi). $delay > 0 bo'lsa tick shuncha soniya uxlab, keyin ishlaydi.
     * Usullar: 1) domen orqali, 2) 127.0.0.1 + Host, 3) CLI fon jarayoni — faqat HTTP aniq ishlamasa va qat'iy cheklov bilan.
     */
    public static function kick(int $delay = 0): bool
    {
        $base = self::baseUrl();
        if ($base === '') {
            return false;
        }
        $body = http_build_query(['k' => self::secret(), 'd' => $delay]);
        $attempts = [[$base . '/tick.php', []]];
        $host = parse_url($base, PHP_URL_HOST);
        $scheme = parse_url($base, PHP_URL_SCHEME);
        $port = parse_url($base, PHP_URL_PORT);
        if ($host && $scheme === 'https' && !$port) {
            // Bot kichik papkada turgan bo'lsa (masalan https://domen.uz/SlayderAi1Bit) yo'lni ham saqlaymiz
            $attempts[] = ['http://127.0.0.1' . rtrim((string)parse_url($base, PHP_URL_PATH), '/') . '/tick.php', ['Host: ' . $host]];
        }
        $last = self::kvGet('kick_mode');
        if ($last && $last['v'] === 'local' && count($attempts) > 1 && time() - $last['ts'] < 3600) {
            $attempts = array_reverse($attempts);
        }
        $hardFail = false;
        foreach ($attempts as [$url, $hdr]) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_NOSIGNAL => true,
                CURLOPT_TIMEOUT_MS => 1500, CURLOPT_CONNECTTIMEOUT_MS => 900, CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true,
                CURLOPT_HTTPHEADER => $hdr, CURLOPT_SSL_VERIFYPEER => true,
            ]);
            curl_exec($ch);
            $errno = curl_errno($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            // 28 (javob kutish vaqti), 52/56 (javobsiz uzilish) — so'rov serverga yetgan; bu "ulanish ishlaydi" degani.
            // Faqat 2xx javob "tick ishga tushdi" degani: 404/403 (noto'g'ri manzil yoki bloklangan fayl) va 3xx (http→https
            // yo'naltirish) holatlarida tick HECH QACHON bajarilmaydi — avval bularni ham "muvaffaqiyat" deb hisoblab,
            // zaxira usullarga (127.0.0.1 / CLI) o'tilmay, ishlar jim qotib qolardi.
            if (($errno === 0 && $code >= 200 && $code < 300) || in_array($errno, [28, 52, 56], true)) {
                self::kvSet('kick_mode', $url === $base . '/tick.php' ? 'domain' : 'local');
                self::kvSet('kick_fail', '0');
                return true;
            }
            $hardFail = $hardFail || in_array($errno, [6, 7, 35, 51, 58, 60], true) || ($errno === 0 && ($code === 403 || $code === 404 || ($code >= 300 && $code < 400)));
        }
        // HTTP aniq ishlamayapti (DNS/ulanish rad etildi) — 3 marta ketma-ket bo'lsa CLI fon jarayoniga o'tamiz
        $f = self::kvGet('kick_fail');
        $fails = $hardFail ? ((int)($f['v'] ?? 0) + 1) : 0;
        self::kvSet('kick_fail', (string)$fails);
        if ($fails >= 3 && self::canExec()) {
            $sp = self::kvGet('exec_spawn');
            if (!$sp || time() - $sp['ts'] >= 3) {                      // eng ko'pi bilan 3 soniyada 1 jarayon
                self::kvSet('exec_spawn', '1');
                $php = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
                @exec(escapeshellarg($php) . ' ' . escapeshellarg(dirname(__DIR__) . '/tick.php') . ' ' . (int)$delay . ' > /dev/null 2>&1 &');
                self::kvSet('kick_mode', 'exec');
                return true;
            }
            return false;
        }
        if ($fails >= 3) {
            self::kvSet('kick_mode', 'inline');
        }
        return false;
    }

    public static function canExec(): bool
    {
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        return function_exists('exec') && !in_array('exec', $disabled, true) && PHP_OS_FAMILY !== 'Windows';
    }

    /**
     * Har bir kelgan so'rovda chaqiriladi (arzon). Ish bor-u hech kim bajarmayotgan bo'lsa — uyg'otadi.
     * Shu tufayli cron bo'lmasa ham ishlar to'xtab qolmaydi.
     */
    public static function watchdog(): void
    {
        static $ran = false;
        if ($ran) {
            return;
        }
        $ran = true;
        try {
            $now = time();
            // 1) e'lon qilinmagan (progress xabari yo'q) ishlar — masalan to'lov Click/Payme orqali kelgan
            $st = db()->prepare("SELECT id FROM jobs WHERE status IN ('pending','processing') AND (pmsg_id IS NULL OR pmsg_id = 0) AND created_at < ? LIMIT 3");
            $st->execute([$now - 2]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $jid) {
                self::announce((int)$jid, "✅ Buyurtma qabul qilindi!");
            }
            // 2) ish bor-u, tick yo'q
            $last = self::kvGet('last_kick');
            if ($last && $now - $last['ts'] < 3) {
                return;
            }
            $runnable = self::runnableCount();
            $active = self::activeTicks();
            if ($runnable > 0 && $active < min($runnable, self::maxParallel())) {
                self::kvSet('last_kick', '1');
                self::kick(0);
                return;
            }
            // 2b) rassilka (yoki userbot kutayotgan HUMO to'lovi) bor-u, tick yo'q
            if ($active === 0 && (Maintenance::pending() || (class_exists('Userbot') && class_exists('HumoAuto') && Userbot::wantsPoll())
                || (class_exists('LiveVoice') && LiveVoice::pending()))) {
                self::kvSet('last_kick', '1');
                self::kick(0);
                return;
            }
            // 3) faqat kechiktirilgan ishlar bor va uxlab turgan tick yo'q
            if ($runnable === 0 && $active === 0) {
                $d = self::nextDelay();
                if ($d !== null) {
                    $sl = self::kvGet('sleeper_until');
                    if (!$sl || (int)$sl['v'] < $now) {
                        self::kvSet('last_kick', '1');
                        self::kick(min($d, 45));
                    }
                }
            }
        } catch (Throwable $e) {
            logError('engine', 'watchdog: ' . $e->getMessage());
        }
    }

    /** Webhook javobni yopib bo'lgach: kick ishlamaydigan hostingda (inline rejim) shu jarayonning o'zida bir tick bajaradi. */
    public static function inlineIfNeeded(): void
    {
        $m = self::kvGet('kick_mode');
        if (!$m || $m['v'] !== 'inline') {
            return;
        }
        if ((self::runnableCount() > 0 || Maintenance::pending()) && self::activeTicks() < self::maxParallel()) {
            self::runTick(0);
        }
    }

    // ------------------------------------------------------------------ tick

    public static function runTick(int $delay = 0): void
    {
        @ignore_user_abort(true);
        $start = microtime(true);
        $now = time();

        if ($delay > 0) {
            $sl = self::kvGet('sleeper_until');
            if ($sl && (int)$sl['v'] > $now) {
                return;                                   // boshqa "uyqudagi" tick bor
            }
            self::kvSet('sleeper_until', (string)($now + $delay + 5));
            $until = microtime(true) + $delay;
            while (microtime(true) < $until) {
                usleep(500000);
            }
            self::kvSet('sleeper_until', '0');
            $start = microtime(true);
        }

        if (self::activeTicks() >= self::maxParallel()) {
            return;
        }
        $tickId = bin2hex(random_bytes(6));
        $hard = self::hardLimit();
        $pdo = db();
        $pdo->prepare('INSERT INTO engine_ticks (id, started, beat, expires) VALUES (?,?,?,?)')->execute([$tickId, time(), time(), time() + $hard + 10]);
        @set_time_limit($hard + 20);
        $budgetEnd = $start + self::tickBudget();
        $hardEnd = $start + $hard - 6;
        $unregistered = false;
        $finish = function () use ($tickId, &$unregistered) {
            if (!$unregistered) {
                $unregistered = true;
                try {
                    db()->prepare('DELETE FROM engine_ticks WHERE id = ?')->execute([$tickId]);
                } catch (Throwable $e) {
                }
            }
        };
        register_shutdown_function($finish);

        $idle = 0;
        $iter = 0;
        try {
            self::cleanup();
            while (microtime(true) < $budgetEnd && $iter++ < 80) {
                $job = self::pickJob($tickId);
                if (!$job) {
                    break;
                }
                $before = (int)($job['progress'] ?? 0) . ':' . strlen((string)$job['state']);
                try {
                    self::runJob($job, $tickId, $budgetEnd, $hardEnd);
                } catch (Throwable $e) {
                    logError('engine', 'runJob #' . $job['id'] . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
                    self::releaseLease((int)$job['id'], $tickId, 15);
                }
                // Aylanib qolish (spin) himoyasi: ish hech qanday oldinga siljimasa, tickni to'xtatamiz
                $st = $pdo->prepare('SELECT progress, state FROM jobs WHERE id = ?');
                $st->execute([$job['id']]);
                $a = $st->fetch(PDO::FETCH_ASSOC);
                $after = $a ? ((int)$a['progress'] . ':' . strlen((string)$a['state'])) : '';
                $idle = $after === $before ? $idle + 1 : 0;
                if ($idle >= 3) {
                    break;
                }
                $pdo->prepare('UPDATE engine_ticks SET beat = ?, expires = ? WHERE id = ?')->execute([time(), time() + $hard + 10, $tickId]);
            }
        } finally {
            AIClient::setDeadline(null);
        }
        // Fon xizmatlari (rassilka, tozalash, baza zaxirasi) — ishlar navbatidan keyin, qolgan vaqt ichida
        try {
            $ub = class_exists('Userbot') && class_exists('HumoAuto') && Userbot::wantsPoll();
            $mEnd = min($hardEnd, microtime(true) + ($ub ? 45 : 20));     // userbot o'qishi uchun ko'proq vaqt
            if ($mEnd - microtime(true) > 3) {
                Maintenance::run($mEnd);
            }
        } catch (Throwable $e) {
            logError('engine', 'maintenance: ' . $e->getMessage());
        } finally {
            $finish();
        }

        // zanjir: ish qolgan bo'lsa o'zimizni (va kerak bo'lsa yana ticklarni) davom ettiramiz
        try {
            $runnable = self::runnableCount();
            if ($runnable > 0 && $idle < 3) {
                $want = min($runnable, self::maxParallel()) - self::activeTicks();
                for ($i = 0; $i < max(1, min(2, $want)); $i++) {
                    self::kick(0);
                }
            } elseif (Maintenance::pending()) {
                self::kick(1);                                   // rassilka davom etmoqda
            } elseif (class_exists('Userbot') && class_exists('HumoAuto') && Userbot::wantsPoll()) {
                self::kick(Userbot::nextPollIn());               // 👤 HUMO to'lovi kutilmoqda — userbot o'qishi uchun
            } elseif (class_exists('LiveVoice') && LiveVoice::pending()) {
                self::kick(8);                                   // ⭐ jonli ovoz tayyorlanmoqda
            } else {
                $d = self::nextDelay();
                if ($d !== null) {
                    $sl = self::kvGet('sleeper_until');
                    if (!$sl || (int)$sl['v'] < time()) {
                        self::kick(max(2, min($d, 45)));
                    }
                }
            }
        } catch (Throwable $e) {
            logError('engine', 'zanjir: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ ishni olish

    private static function pickJob(string $tickId): ?array
    {
        $pdo = db();
        $now = time();
        $maxActive = max(1, (int)self::c('ENGINE_MAX_ACTIVE_JOBS', 8));
        $perUser = max(1, (int)self::c('ENGINE_MAX_JOBS_PER_USER', 2));
        $lease = (int)self::c('ENGINE_LEASE_SECONDS', 150);
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $st = $pdo->prepare(
                "SELECT j.* FROM jobs j
                 WHERE j.status IN ('pending','processing') AND j.lease_until <= :now AND j.next_run_at <= :now
                   AND (j.started_at IS NOT NULL
                        OR ((SELECT COUNT(*) FROM jobs a WHERE a.status = 'processing' AND a.started_at IS NOT NULL) < :maxActive
                            AND (SELECT COUNT(*) FROM jobs u WHERE u.telegram_id = j.telegram_id AND u.status = 'processing' AND u.started_at IS NOT NULL) < :perUser))
                 ORDER BY (j.started_at IS NULL) ASC, CASE WHEN j.started_at IS NULL THEN -COALESCE(j.priority, 0) ELSE 0 END ASC, j.last_tick ASC, j.id ASC LIMIT 1"
            );
            $st->execute(['now' => $now, 'maxActive' => $maxActive, 'perUser' => $perUser]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $pdo->exec('COMMIT');
                return null;
            }
            $pdo->prepare(
                "UPDATE jobs SET status = 'processing', lease_until = ?, lease_owner = ?, started_at = COALESCE(started_at, ?), last_tick = ?, updated_at = ? WHERE id = ?"
            )->execute([$now + $lease, $tickId, $now, $now, $now, $row['id']]);
            $pdo->exec('COMMIT');
            $row['lease_owner'] = $tickId;
            return $row;
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable $e2) {
            }
            logError('engine', 'pickJob: ' . $e->getMessage());
            return null;
        }
    }

    private static function releaseLease(int $jobId, string $owner, int $retryIn = 0): void
    {
        db()->prepare('UPDATE jobs SET lease_until = 0, next_run_at = ?, updated_at = ? WHERE id = ? AND lease_owner = ?')
            ->execute([time() + $retryIn, time(), $jobId, $owner]);
    }

    private static function extendLease(int $jobId, string $owner): bool
    {
        $lease = (int)self::c('ENGINE_LEASE_SECONDS', 150);
        $st = db()->prepare('UPDATE jobs SET lease_until = ?, updated_at = ? WHERE id = ? AND lease_owner = ?');
        $st->execute([time() + $lease, time(), $jobId, $owner]);
        return $st->rowCount() > 0;
    }

    // ------------------------------------------------------------------ ishni bajarish

    private static function runJob(array $job, string $tickId, float $budgetEnd, float $hardEnd): void
    {
        $jobId = (int)$job['id'];
        AIClient::$jobId = $jobId;                 // AI token sarfi shu buyurtmaga yoziladi
        AIClient::$context = '';                   // o'qituvchi talablari (JobSteps::exec qadam boshida o'rnatadi)
        $state = $job['state'] ? json_decode((string)$job['state'], true) : null;
        if (!is_array($state) || empty($state['steps'])) {
            try {
                $state = JobSteps::init($job);
            } catch (Throwable $e) {
                self::failJob($job, 'Reja tuzib bo\'lmadi: ' . $e->getMessage());
                return;
            }
            self::saveState($jobId, $tickId, $state, 1);
        }
        $stepsDone = 0;

        while (true) {
            $fresh = db()->prepare('SELECT cancel_req, status, wait_total FROM jobs WHERE id = ?');
            $fresh->execute([$jobId]);
            $f = $fresh->fetch(PDO::FETCH_ASSOC);
            if (!$f || !in_array($f['status'], ['pending', 'processing'], true)) {
                return;
            }
            if ((int)$f['cancel_req'] === 1) {
                self::finishCancel($job);
                return;
            }
            $i = (int)$state['i'];
            if ($i >= count($state['steps'])) {
                self::completeJob($job, $state);
                return;
            }
            $step = $state['steps'][$i];
            // Yangi qadamni faqat vaqt yetarli bo'lsa boshlaymiz (kamida bitta qadam har tickda bajariladi)
            if ($stepsDone > 0 && microtime(true) > $budgetEnd) {
                self::releaseLease($jobId, $tickId, 0);
                return;
            }
            if (!self::extendLease($jobId, $tickId)) {
                return;                               // ijara boshqaga o'tib ketgan
            }
            AIClient::setDeadline($hardEnd);
            $t0 = microtime(true);
            self::setPhase($job, $step['label']);
            try {
                JobSteps::exec($step, $job, $state);
            } catch (AIUnavailableException $e) {
                $wait = (int)ceil(max(3, $e->retryAfter));
                $total = (int)$f['wait_total'] + $wait;
                if ($total > (int)self::c('ENGINE_MAX_WAIT_SECONDS', 2400)) {
                    self::failJob($job, 'AI uzoq vaqt javob bermadi: ' . $e->getMessage());
                    return;
                }
                db()->prepare('UPDATE jobs SET wait_total = ?, phase = ?, lease_until = 0, next_run_at = ?, updated_at = ? WHERE id = ? AND lease_owner = ?')
                    ->execute([$total, 'AI band — kutilmoqda', time() + $wait, time(), $jobId, $tickId]);
                self::push($jobId, true, "⏸ AI hozir band, avtomatik davom etadi (~{$wait}s)");
                return;
            } catch (StepWaitException $e) {
                // qadam foydalanuvchi javobini kutmoqda (masalan reja tasdiqlash) — holat saqlanadi, ish $e->delay soniyadan keyin qaytadi
                self::saveState($jobId, $tickId, $state);
                self::releaseLease($jobId, $tickId, max(2, $e->delay));
                return;
            } catch (StepContinueException $e) {
                // qadam qisman bajarildi (natija saqlangan) — keyingi tickda davom etadi, urinish hisoblanmaydi
                self::saveState($jobId, $tickId, $state);
                self::releaseLease($jobId, $tickId, 1);
                return;
            } catch (StepRetryException $e) {
                $a = ($state['att'][$step['id']] ?? 0) + 1;
                $state['att'][$step['id']] = $a;
                if ($a >= $e->maxAttempts) {
                    if ($e->acceptAfterRetries) {
                        $e->accept($state);
                    } else {
                        self::failJob($job, $e->getMessage());
                        return;
                    }
                } else {
                    self::saveState($jobId, $tickId, $state);
                    self::releaseLease($jobId, $tickId, 2);
                    return;
                }
            } catch (AIBadRequestException $e) {
                $a = ($state['att'][$step['id']] ?? 0) + 1;
                $state['att'][$step['id']] = $a;
                $state['shrink'][$step['id']] = ($state['shrink'][$step['id']] ?? 1.0) * 0.6;   // so'rovni kichraytiramiz
                if ($a >= self::STEP_ATTEMPTS) {
                    self::failJob($job, $e->getMessage());
                    return;
                }
                self::saveState($jobId, $tickId, $state);
                self::releaseLease($jobId, $tickId, 2);
                return;
            } catch (Throwable $e) {
                $a = ($state['att'][$step['id']] ?? 0) + 1;
                $state['att'][$step['id']] = $a;
                logError('engine', "Job #$jobId qadam {$step['id']} xato (urinish $a): " . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
                if ($a >= self::STEP_ATTEMPTS) {
                    self::failJob($job, $e->getMessage());
                    return;
                }
                self::saveState($jobId, $tickId, $state);
                self::releaseLease($jobId, $tickId, 5 * (2 ** $a));
                return;
            }
            // muvaffaqiyat
            $state['dur'][$step['id']] = round(microtime(true) - $t0, 1);
            $state['i'] = $i + 1;
            $stepsDone++;
            $pct = self::pct($state);
            if (!self::saveState($jobId, $tickId, $state, $pct)) {
                return;
            }
            self::push($jobId, false);
            if ((int)$state['i'] >= count($state['steps'])) {
                self::completeJob($job, $state);
                return;
            }
        }
    }

    private static function saveState(int $jobId, string $owner, array $state, ?int $pct = null): bool
    {
        $pct = $pct ?? self::pct($state);
        $st = db()->prepare('UPDATE jobs SET state = ?, progress = ?, updated_at = ? WHERE id = ? AND lease_owner = ?');
        $st->execute([json_encode($state, JSON_UNESCAPED_UNICODE), $pct, time(), $jobId, $owner]);
        return $st->rowCount() > 0;
    }

    private static function setPhase(array $job, string $label): void
    {
        db()->prepare('UPDATE jobs SET phase = ? WHERE id = ?')->execute([$label, $job['id']]);
        self::push((int)$job['id'], false);
    }

    public static function pct(array $state): int
    {
        $total = 0.0;
        $done = 0.0;
        foreach ($state['steps'] as $idx => $s) {
            $total += $s['w'];
            if ($idx < (int)$state['i']) {
                $done += $s['w'];
            }
        }
        if ($total <= 0) {
            return 1;
        }
        $p = (int)floor($done / $total * 100);
        return max(1, min(99, $p));
    }

    // ------------------------------------------------------------------ yakun / xato / bekor

    private static function completeJob(array $job, array $state): void
    {
        $jobId = (int)$job['id'];
        $file = (string)($state['res']['file'] ?? '');
        $st = db()->prepare("UPDATE jobs SET status = 'done', result_file = ?, progress = 100, phase = ?, lease_until = 0, finished_at = ?, updated_at = ?, state = NULL WHERE id = ?");
        $st->execute([$file, 'Tayyor', time(), time(), $jobId]);
        if (!empty($state['res']['pres'])) {
            db()->prepare('UPDATE jobs SET pres_id = ? WHERE id = ?')->execute([(string)$state['res']['pres'], $jobId]);
        }
        self::push($jobId, true, null, true);
        JobSteps::afterDone($job, $state);
        if (class_exists('Growth')) {
            Growth::onJobDone($job);              // hamkor (agent) ulushi
        }
        foreach ((array)($state['res']['tmp'] ?? []) as $tmp) {
            if (is_string($tmp) && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    public static function failJob(array $job, string $reason): void
    {
        $jobId = (int)$job['id'];
        logError('engine', "Job #$jobId muvaffaqiyatsiz: $reason");
        $st = db()->prepare("UPDATE jobs SET status = 'failed', error_message = ?, lease_until = 0, finished_at = ?, updated_at = ? WHERE id = ? AND status IN ('pending','processing')");
        $st->execute([substr($reason, 0, 300), time(), time(), $jobId]);
        if ($st->rowCount() === 0) {
            return;
        }
        $note = self::refund($job);
        self::push($jobId, true, "❌ Xatolik yuz berdi. $note", true);
        Telegram::sendMessage(
            (int)$job['telegram_id'],
            "Kechirasiz, buyurtmangizni (#$jobId) tayyorlashda xatolik yuz berdi. $note\nMuammo davom etsa, administrator bilan bog'laning."
        );
        if (class_exists('AdminEvents')) {
            AdminEvents::orderFailed($jobId, (int)$job['telegram_id'], substr($reason, 0, 200) . " ($note)");
        } else {
            Telegram::notifyAdmin("Job #$jobId (user {$job['telegram_id']}) muvaffaqiyatsiz: " . Telegram::escape(substr($reason, 0, 200)) . " ($note)");
        }
    }

    private static function finishCancel(array $job): void
    {
        $jobId = (int)$job['id'];
        $st = db()->prepare("UPDATE jobs SET status = 'cancelled', lease_until = 0, finished_at = ?, updated_at = ? WHERE id = ? AND status IN ('pending','processing')");
        $st->execute([time(), time(), $jobId]);
        if ($st->rowCount() === 0) {
            return;
        }
        $note = self::refund($job);
        self::push($jobId, true, "✖️ Bekor qilindi. $note", true);
        $state = $job['state'] ? json_decode((string)$job['state'], true) : [];
        if (!empty($state['res']['plan_mid'])) {
            try {
                PlanReview::removeKeyboard($state);       // reja tasdiqlash tugmalari chatda qolib ketmasin
            } catch (Throwable $e) {
            }
        }
        foreach ((array)($state['res']['tmp'] ?? []) as $tmp) {
            if (is_string($tmp) && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** To'lovni qaytaradi (balansga yoki bepul urinishni tiklaydi). */
    public static function refund(array $job): string
    {
        $chatId = (int)$job['telegram_id'];
        $xp = class_exists('Express') ? Express::takeFee((int)$job['id']) : 0;
        if ($xp > 0) {
            db()->prepare('UPDATE users SET balance = balance + ? WHERE telegram_id = ?')->execute([$xp, $chatId]);
        }
        $msg = self::refundMain($job);
        return $xp > 0 ? trim($msg . " ⚡ Tezkor to'lov (" . number_format($xp, 0, '', ' ') . " so'm) ham qaytarildi.") : $msg;
    }

    private static function refundMain(array $job): string
    {
        $chatId = (int)$job['telegram_id'];
        if ((int)($job['is_free'] ?? 0) === 1) {
            db()->prepare('UPDATE users SET free_used = 0 WHERE telegram_id = ?')->execute([$chatId]);
            return "Bepul urinishingiz qaytarildi.";
        }
        if (($job['paid_by'] ?? '') === 'sub') {
            Growth::subGive($chatId);
            return "Obunangizga 1 ta buyurtma qaytarildi.";
        }
        $price = (int)($job['price'] ?? 0);
        if ($price > 0) {
            db()->prepare('UPDATE users SET balance = balance + ? WHERE telegram_id = ?')->execute([$price, $chatId]);
            return "Balansingizga " . number_format($price, 0, '', ' ') . " so'm qaytarildi.";
        }
        return '';
    }

    /** Foydalanuvchi so'rovi bilan bekor qilish. */
    public static function cancel(int $jobId, int $userId): string
    {
        $st = db()->prepare('SELECT * FROM jobs WHERE id = ? AND telegram_id = ?');
        $st->execute([$jobId, $userId]);
        $job = $st->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            return "Buyurtma topilmadi.";
        }
        if (!in_array($job['status'], ['pending', 'processing'], true)) {
            return "Bu buyurtmani bekor qilib bo'lmaydi (holati: {$job['status']}).";
        }
        $state = $job['state'] ? json_decode((string)$job['state'], true) : [];
        if (!empty($state['res']['sent'])) {
            return "Fayl allaqachon yuborilmoqda — bekor qilib bo'lmaydi.";
        }
        if ((int)$job['lease_until'] <= time()) {
            self::finishCancel($job);
            return "Buyurtma bekor qilindi, to'lov qaytarildi.";
        }
        db()->prepare('UPDATE jobs SET cancel_req = 1 WHERE id = ?')->execute([$jobId]);
        return "Bekor qilish so'raldi — bir necha soniyada bajariladi.";
    }

    // ------------------------------------------------------------------ progress xabari

    public static function bar(int $pct): string
    {
        $n = (int)round($pct / 10);
        return str_repeat('▰', $n) . str_repeat('▱', 10 - $n);
    }

    private static function typeTitle(array $job): string
    {
        $vk = (string)$job['volume_key'];
        $label = '';
        $t = (string)$job['type'];
        $head = isset(DocTypes::T[$t]) ? DocTypes::T[$t]['menu'] : ($t === 'tarjima' ? '🌐 Tarjima' : ($t === 'qayta' ? '♻️ Qismini qayta yozish' : '📄 Buyurtma'));
        $vols = DocTypes::volumes($t) ?? [];
        $label = (string)($vols[$vk]['label'] ?? '');
        if ($t === 'tarjima' && class_exists('Translate')) {
            $label = Translate::LANGS[explode(':', $vk . ':')[1]] ?? '';
        }
        return $head . ($label !== '' ? " · $label" : '');
    }

    public static function queuePosition(array $job): int
    {
        if ($job['started_at']) {
            return 0;
        }
        $pr = (int)($job['priority'] ?? 0);
        $st = db()->prepare("SELECT COUNT(*) FROM jobs WHERE status IN ('pending','processing') AND started_at IS NULL
            AND (COALESCE(priority, 0) > ? OR (COALESCE(priority, 0) = ? AND id < ?))");
        $st->bindValue(1, $pr, PDO::PARAM_INT);           // COALESCE() natijasida affinity yo'q — matn '0' bilan teng chiqmaydi
        $st->bindValue(2, $pr, PDO::PARAM_INT);
        $st->bindValue(3, (int)$job['id'], PDO::PARAM_INT);
        $st->execute();
        return (int)$st->fetchColumn() + 1;
    }

    /** Shu turdagi oxirgi 30 ta ishning o'rtacha (mediana) bajarilish vaqti, soniya. Ma'lumot bo'lmasa — taxminiy. */
    public static function typicalDuration(string $type): int
    {
        static $cache = [];
        if (isset($cache[$type])) {
            return $cache[$type];
        }
        $st = db()->prepare("SELECT finished_at - started_at FROM jobs WHERE type = ? AND status = 'done' AND started_at > 0 AND finished_at > started_at
            ORDER BY id DESC LIMIT 30");
        $st->execute([$type]);
        $d = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (count($d) >= 3) {
            sort($d);
            $v = $d[intdiv(count($d), 2)];
        } else {
            $v = ['slayd' => 150, 'kurs_ishi' => 420, 'diplom' => 900, 'tarjima' => 120, 'qayta' => 60, 'test' => 120, 'tezis' => 90, 'krossvord' => 60, 'maruza' => 200, 'keys' => 200][$type] ?? 240;
        }
        return $cache[$type] = max(30, min(3600, $v));
    }

    /** Navbatdagi ish qachon tayyor bo'lishi (soniya): oldidagilar parallel oqimlarga bo'linadi. */
    public static function queueEta(array $job): int
    {
        $pos = self::queuePosition($job);
        $slots = max(1, (int)self::c('ENGINE_MAX_ACTIVE_JOBS', 8));
        $st = db()->query("SELECT type FROM jobs WHERE status IN ('pending','processing') ORDER BY id LIMIT 200");
        $sum = 0;
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $sum += self::typicalDuration((string)$t);
            $n++;
        }
        $avg = $n ? $sum / $n : 240;
        $waves = (int)floor(($pos - 1) / $slots);              // oldingi «to'lqinlar» soni
        return (int)($waves * $avg * 0.8 + self::typicalDuration((string)$job['type']) + 15);
    }

    private static function fmtDur(int $sec): string
    {
        if ($sec < 90) {
            return max(10, (int)(round($sec / 10) * 10)) . ' soniya';
        }
        $m = (int)ceil($sec / 60);
        return $m < 60 ? $m . ' daqiqa' : intdiv($m, 60) . ' soat ' . ($m % 60 ? ($m % 60) . ' daqiqa' : '');
    }

    public static function progressText(array $job, ?string $note = null, bool $final = false): string
    {
        $pct = (int)$job['progress'];
        $topic = (string)$job['topic'];
        $title = Telegram::escape(function_exists('mb_substr') ? mb_substr($topic, 0, 80) : substr($topic, 0, 80));
        $head = "<b>Buyurtma #{$job['id']}</b>\n" . self::typeTitle($job) . "\n«{$title}»\n\n";
        if ($job['status'] === 'done') {
            return $head . self::bar(100) . " <b>100%</b>\n✅ Tayyor — fayl yuborildi!";
        }
        if (in_array($job['status'], ['failed', 'cancelled'], true)) {
            return $head . self::bar($pct) . " {$pct}%\n" . ($note ?? '');
        }
        $lines = self::bar($pct) . " <b>{$pct}%</b>\n";
        if (empty($job['started_at'])) {
            $pos = self::queuePosition($job);
            $lines .= "🕐 Navbatda: {$pos}-o'rin" . (!empty($job['priority']) ? ' ⚡ (tezkor)' : '') . ". Tez orada boshlanadi…\n";
            $lines .= "⏱ Taxminan " . self::fmtDur(self::queueEta($job)) . " ichida tayyor bo'ladi\n";
        } else {
            $phase = (string)($job['phase'] ?? '');
            if ($phase !== '') {
                $lines .= '⚙️ ' . Telegram::escape($phase) . "\n";
            }
            $eta = self::eta($job);
            if ($eta === null || $pct < 8) {           // qadamlar bo'yicha hali hisob yo'q — shu turdagi ishlar tarixidan
                $eta = max(20, self::typicalDuration((string)$job['type']) - (time() - (int)$job['started_at']));
            }
            $lines .= "⏱ Taxminan " . self::fmtDur((int)$eta) . " qoldi\n";
        }
        if ($note) {
            $lines .= "\n" . Telegram::escape($note) . "\n";
        }
        return $head . $lines;
    }

    private static function eta(array $job): ?int
    {
        $state = $job['state'] ? json_decode((string)$job['state'], true) : null;
        if (!is_array($state) || empty($state['dur'])) {
            return null;
        }
        $doneW = 0.0;
        $doneT = 0.0;
        $remW = 0.0;
        foreach ($state['steps'] as $idx => $s) {
            if ($idx < (int)$state['i']) {
                $doneW += $s['w'];
                $doneT += (float)($state['dur'][$s['id']] ?? 0);
            } else {
                $remW += $s['w'];
            }
        }
        if ($doneW <= 0 || $doneT <= 0) {
            return null;
        }
        return (int)($doneT / $doneW * $remW * 1.15);
    }

    public static function progressKeyboard(array $job): array
    {
        if (!in_array($job['status'], ['pending', 'processing'], true)) {
            return ['inline_keyboard' => []];
        }
        $rows = [[
            ['text' => '🔄 Holatni yangilash', 'callback_data' => 'job:r:' . $job['id']],
            ['text' => '✖️ Bekor qilish', 'callback_data' => 'job:c:' . $job['id']],
        ]];
        if (class_exists('Express') && ($xb = Express::button($job))) {
            array_unshift($rows, [$xb]);
        }
        return ['inline_keyboard' => $rows];
    }

    /** Foydalanuvchiga progress xabarini birinchi marta yuboradi. */
    public static function announce(int $jobId, string $header = ''): void
    {
        $st = db()->prepare('SELECT * FROM jobs WHERE id = ?');
        $st->execute([$jobId]);
        $job = $st->fetch(PDO::FETCH_ASSOC);
        if (!$job || !empty($job['pmsg_id']) || !in_array($job['status'], ['pending', 'processing'], true)) {
            return;
        }
        $claim = db()->prepare('UPDATE jobs SET pmsg_id = -1 WHERE id = ? AND (pmsg_id IS NULL OR pmsg_id = 0)');
        $claim->execute([$jobId]);
        if ($claim->rowCount() === 0) {
            return;
        }
        if ((int)$job['progress'] < 1) {
            $job['progress'] = 1;
            db()->prepare('UPDATE jobs SET progress = 1 WHERE id = ?')->execute([$jobId]);
        }
        $text = ($header !== '' ? $header . "\n\n" : '') . self::progressText($job);
        $mid = Telegram::sendMessage((int)$job['telegram_id'], $text, self::progressKeyboard($job));
        db()->prepare('UPDATE jobs SET pmsg_id = ?, pmsg_chat = ?, last_push = ?, last_hash = ? WHERE id = ?')
            ->execute([$mid ?: 0, (int)$job['telegram_id'], (int)(microtime(true) * 1000), md5(self::progressText($job)), $jobId]);
    }

    /** Buyurtma tayyor (to'langan) bo'lgan zahoti chaqiriladi. */
    public static function onJobPending(int $jobId, string $header = ''): void
    {
        try {
            self::announce($jobId, $header);
            self::kvSet('last_kick', '1');
            self::kick(0);
        } catch (Throwable $e) {
            logError('engine', 'onJobPending: ' . $e->getMessage());
        }
    }

    /** Progress xabarini yangilaydi (Telegram chegarasini hisobga olgan holda, bloklamasdan). */
    public static function push(int $jobId, bool $force = false, ?string $note = null, bool $final = false): void
    {
        try {
            $st = db()->prepare('SELECT * FROM jobs WHERE id = ?');
            $st->execute([$jobId]);
            $job = $st->fetch(PDO::FETCH_ASSOC);
            if (!$job || (int)$job['pmsg_id'] <= 0) {
                return;
            }
            $nowMs = (int)(microtime(true) * 1000);
            $text = self::progressText($job, $note, $final);
            $hash = md5($text);
            if ($hash === $job['last_hash']) {
                return;
            }
            $minGap = $force ? 1200 : 4500;
            if (!$final && $nowMs - (int)$job['last_push'] < $minGap) {
                return;
            }
            $res = Telegram::editQuick((int)$job['pmsg_chat'], (int)$job['pmsg_id'], $text, self::progressKeyboard($job));
            if ($res['retry']) {
                db()->prepare('UPDATE jobs SET last_push = ? WHERE id = ?')->execute([$nowMs + $res['retry'] * 1000, $jobId]);
                return;
            }
            if ($res['gone']) {
                db()->prepare('UPDATE jobs SET pmsg_id = 0 WHERE id = ?')->execute([$jobId]);
                return;
            }
            db()->prepare('UPDATE jobs SET last_push = ?, last_hash = ? WHERE id = ?')->execute([$nowMs, $hash, $jobId]);
        } catch (Throwable $e) {
            logError('engine', 'push: ' . $e->getMessage());
        }
    }

    /** «🔄 Holatni yangilash» tugmasi: darhol yangilaydi va ishni uyg'otadi. */
    public static function refreshNow(int $jobId, int $userId): string
    {
        $st = db()->prepare('SELECT * FROM jobs WHERE id = ? AND telegram_id = ?');
        $st->execute([$jobId, $userId]);
        $job = $st->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            return 'Buyurtma topilmadi';
        }
        if (in_array($job['status'], ['pending', 'processing'], true)) {
            db()->prepare('UPDATE jobs SET last_hash = NULL, last_push = 0 WHERE id = ?')->execute([$jobId]);
            self::push($jobId, true);
            self::kvSet('last_kick', '1');
            self::kick(0);
            return 'Yangilandi';
        }
        return 'Buyurtma holati: ' . $job['status'];
    }

    // ------------------------------------------------------------------ tozalash

    private static function cleanup(): void
    {
        if (mt_rand(1, 25) !== 1) {
            return;
        }
        $pdo = db();
        $now = time();
        $pdo->prepare('DELETE FROM engine_ticks WHERE expires < ?')->execute([$now]);
        $pdo->prepare('DELETE FROM ai_leases WHERE expires < ?')->execute([$now]);
        $pdo->prepare("UPDATE jobs SET state = NULL WHERE status IN ('done','failed','cancelled') AND state IS NOT NULL AND updated_at < ?")->execute([$now - 3600]);
        foreach (glob(rtrim(GENERATED_DIR, '/') . '/*') ?: [] as $f) {
            if (is_file($f) && $now - filemtime($f) > 6 * 3600) {
                @unlink($f);
            }
        }
    }
}

/** Qadam natijasi yetarli emas (masalan matn juda qisqa) — qayta urinib ko'rish kerak. */
class StepRetryException extends RuntimeException
{
    public int $maxAttempts = Engine::STEP_ATTEMPTS;
    public bool $acceptAfterRetries = false;
    /** @var callable|null */
    public $acceptFn = null;

    public function accept(array &$state): void
    {
        if ($this->acceptFn) {
            ($this->acceptFn)($state);
        }
    }
}

/** Qadam foydalanuvchi javobini kutmoqda: ish $delay soniyadan keyin shu qadamdan davom etadi (urinish hisoblanmaydi). */
class StepWaitException extends RuntimeException
{
    public int $delay;

    public function __construct(int $delay = 5)
    {
        parent::__construct('Foydalanuvchi javobi kutilmoqda');
        $this->delay = $delay;
    }
}

/** Qadam qisman bajarildi; natija state'da saqlangan, keyingi tickda davom ettiriladi. */
class StepContinueException extends RuntimeException
{
}
