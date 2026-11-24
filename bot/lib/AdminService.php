<?php

/**
 * Admin panel amallari. Barcha SQL parametrli (prepared). Pul/holat o'zgartiradigan har bir amal:
 *  - atomik bajariladi,
 *  - admin_audit jurnaliga yoziladi,
 *  - barcha adminlarga Telegram'da xabar yuboriladi.
 */
class AdminService
{
    private const PAGE = 30;

    private static function likeEscape(string $q): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
    }

    private static function cut($s, int $n): string
    {
        return mb_substr((string)$s, 0, $n);
    }

    private static function dayStart(int $ts = 0): int
    {
        $ts = $ts ?: time();
        return intdiv($ts + ADMIN_TZ_OFFSET, 86400) * 86400 - ADMIN_TZ_OFFSET;
    }

    private static function one(string $sql, array $p = []): int
    {
        $st = db()->prepare($sql);
        $st->execute($p);
        return (int)($st->fetchColumn() ?: 0);
    }

    // ------------------------------------------------------------------ statistika

    public static function stats(int $days = 14): array
    {
        $days = in_array($days, [7, 14, 30], true) ? $days : 14;
        $today = self::dayStart();
        $week = $today - 6 * 86400;
        $pdo = db();

        $users = [
            'total' => self::one('SELECT COUNT(*) FROM users'),
            'today' => self::one('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$today]),
            'week' => self::one('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$week]),
            'banned' => self::one('SELECT COUNT(*) FROM users WHERE banned = 1'),
            'balance_sum' => self::one('SELECT COALESCE(SUM(balance),0) FROM users'),
        ];

        $st = $pdo->query('SELECT status, COUNT(*) c FROM jobs GROUP BY status');
        $by = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $by[$r['status']] = (int)$r['c'];
        }
        $rating = $pdo->query('SELECT AVG(rating) FROM jobs WHERE rating IS NOT NULL')->fetchColumn();
        $jobs = [
            'total' => array_sum($by),
            'today' => self::one('SELECT COUNT(*) FROM jobs WHERE created_at >= ?', [$today]),
            'active' => ($by['pending'] ?? 0) + ($by['processing'] ?? 0),
            'done' => $by['done'] ?? 0,
            'failed' => $by['failed'] ?? 0,
            'failed_24h' => self::one("SELECT COUNT(*) FROM jobs WHERE status = 'failed' AND updated_at >= ?", [time() - 86400]),
            'cancelled' => $by['cancelled'] ?? 0,
            'rating' => $rating !== false && $rating !== null ? round((float)$rating, 2) : null,
        ];

        $money = [
            'topup_today' => self::one("SELECT COALESCE(SUM(amount),0) FROM topups WHERE status = 'approved' AND updated_at >= ?", [$today]),
            'topup_week' => self::one("SELECT COALESCE(SUM(amount),0) FROM topups WHERE status = 'approved' AND updated_at >= ?", [$week]),
            'topup_total' => self::one("SELECT COALESCE(SUM(amount),0) FROM topups WHERE status = 'approved'"),
            'sales_today' => self::one("SELECT COALESCE(SUM(price),0) FROM jobs WHERE status = 'done' AND is_free = 0 AND updated_at >= ?", [$today]),
            'sales_total' => self::one("SELECT COALESCE(SUM(price),0) FROM jobs WHERE status = 'done' AND is_free = 0"),
            'pending_manual' => self::one("SELECT COUNT(*) FROM topups WHERE status = 'pending' AND method = 'manual' AND receipt_file_id IS NOT NULL"),
        ];

        // Oxirgi $days kun (Toshkent vaqti bilan), bo'sh kunlar 0 bilan to'ldiriladi
        $from = $today - ($days - 1) * 86400;
        $ord = self::seriesMap("SELECT date(created_at + :o, 'unixepoch') d, COUNT(*) v FROM jobs WHERE created_at >= :f GROUP BY d", $from);
        $rev = self::seriesMap("SELECT date(updated_at + :o, 'unixepoch') d, SUM(amount) v FROM topups WHERE status = 'approved' AND updated_at >= :f GROUP BY d", $from);
        $usr = self::seriesMap("SELECT date(created_at + :o, 'unixepoch') d, COUNT(*) v FROM users WHERE created_at >= :f GROUP BY d", $from);
        $series = [];
        $sal = self::seriesMap("SELECT date(updated_at + :o, 'unixepoch') d, SUM(price) v FROM jobs WHERE status = 'done' AND is_free = 0 AND updated_at >= :f GROUP BY d", $from);
        for ($i = 0; $i < $days; $i++) {
            $ts = $from + $i * 86400;
            $d = gmdate('Y-m-d', $ts + ADMIN_TZ_OFFSET);
            $series[] = ['d' => $d, 'orders' => $ord[$d] ?? 0, 'revenue' => $rev[$d] ?? 0, 'users' => $usr[$d] ?? 0, 'sales' => $sal[$d] ?? 0];
        }
        // Oldingi teng davr bilan solishtirish (o'sish foizi uchun)
        $prevFrom = $from - $days * 86400;
        $cmp = [
            'orders' => [self::one('SELECT COUNT(*) FROM jobs WHERE created_at >= ? AND created_at < ?', [$from, time() + 1]),
                self::one('SELECT COUNT(*) FROM jobs WHERE created_at >= ? AND created_at < ?', [$prevFrom, $from])],
            'revenue' => [self::one("SELECT COALESCE(SUM(amount),0) FROM topups WHERE status = 'approved' AND updated_at >= ?", [$from]),
                self::one("SELECT COALESCE(SUM(amount),0) FROM topups WHERE status = 'approved' AND updated_at >= ? AND updated_at < ?", [$prevFrom, $from])],
            'users' => [self::one('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$from]),
                self::one('SELECT COUNT(*) FROM users WHERE created_at >= ? AND created_at < ?', [$prevFrom, $from])],
        ];

        $types = [];
        foreach ($pdo->query("SELECT type, COUNT(*) c FROM jobs WHERE status = 'done' GROUP BY type")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $types[$r['type']] = (int)$r['c'];
        }

        $designs = [];
        foreach ($pdo->query("SELECT design_key k, COUNT(*) c FROM jobs WHERE type = 'slayd' AND design_key IS NOT NULL AND design_key <> '' GROUP BY design_key ORDER BY c DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $label = (string)(pptxDesigns()[$r['k']]['label'] ?? $r['k']);
            $designs[] = ['key' => (string)$r['k'], 'label' => preg_replace('/^\d+\.\s*/u', '', $label), 'c' => (int)$r['c']];
        }

        return ['users' => $users, 'jobs' => $jobs, 'money' => $money, 'series' => $series, 'types' => $types, 'designs' => $designs,
            'cmp' => $cmp, 'days' => $days, 'health' => self::health(), 'server_time' => time()];
    }

    /** Tizim holati: AI marshrutlari, navbat, disk, PDF. Har biri xato bersa ham panel ochilaveradi. */
    private static function health(): array
    {
        $h = ['ai' => null, 'queue' => null, 'disk' => null, 'pdf' => null];
        try {
            require_once __DIR__ . '/AIClient.php';
            $s = AIClient::status();
            $h['ai'] = ['total' => $s['total'], 'ready' => $s['ready'], 'cooling' => $s['cooling'], 'dead' => $s['dead'],
                'routes' => array_map(fn($r) => ['p' => $r['provider'], 'm' => (string)$r['model'], 'k' => $r['key'], 'st' => $r['state'], 'wait' => $r['wait'], 'ok' => $r['ok'], 'err' => $r['err']], array_slice($s['routes'], 0, 12))];
        } catch (Throwable $e) {
        }
        try {
            $h['queue'] = [
                'pending' => self::one("SELECT COUNT(*) FROM jobs WHERE status = 'pending'"),
                'processing' => self::one("SELECT COUNT(*) FROM jobs WHERE status = 'processing'"),
                'oldest' => self::one("SELECT COALESCE(MIN(created_at),0) FROM jobs WHERE status IN ('pending','processing')"),
            ];
        } catch (Throwable $e) {
        }
        $free = @disk_free_space(dirname(DB_PATH));
        $tot = @disk_total_space(dirname(DB_PATH));
        if ($free !== false && $tot) {
            $h['disk'] = ['free' => (int)$free, 'total' => (int)$tot, 'db' => (int)@filesize(DB_PATH)];
        }
        try {
            require_once __DIR__ . '/SlidePdf.php';
            $h['pdf'] = (string)SlidePdf::engine();
        } catch (Throwable $e) {
        }
        return $h;
    }

    private static function seriesMap(string $sql, int $from): array
    {
        $st = db()->prepare($sql);
        $st->execute(['o' => ADMIN_TZ_OFFSET, 'f' => $from]);
        $m = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $m[$r['d']] = (int)$r['v'];
        }
        return $m;
    }

    // ------------------------------------------------------------------ foydalanuvchilar

    public static function users(string $q, int $offset): array
    {
        $q = trim(self::cut($q, 60));
        $where = '1=1';
        $p = [];
        if ($q !== '' && $q[0] === '#') {                 // #vip — teg bo'yicha qidirish
            $tag = mb_strtolower(preg_replace('/[^\p{L}\p{N}_\-]+/u', '', mb_substr($q, 1)) ?? '');
            $where = "(',' || COALESCE(u.tags,'') || ',') LIKE :t";
            $p['t'] = '%,' . $tag . ',%';
        } elseif ($q !== '') {
            $clean = ltrim($q, '@');
            $like = '%' . self::likeEscape($clean) . '%';
            if (ctype_digit($clean)) {
                $where = '(u.telegram_id = :id OR u.username LIKE :l ESCAPE \'\\\' OR u.full_name LIKE :l ESCAPE \'\\\')';
                $p['id'] = (int)$clean;
            } else {
                $where = '(u.username LIKE :l ESCAPE \'\\\' OR u.full_name LIKE :l ESCAPE \'\\\' OR u.university LIKE :l ESCAPE \'\\\')';
            }
            $p['l'] = $like;
        }
        $sql = "SELECT u.telegram_id, u.username, u.full_name, u.balance, u.free_used, u.banned, u.created_at, u.last_request_at, u.tags,
                       (SELECT COUNT(*) FROM jobs j WHERE j.telegram_id = u.telegram_id) AS jobs
                FROM users u WHERE $where ORDER BY u.last_request_at DESC, u.id DESC LIMIT " . (self::PAGE + 1) . ' OFFSET ' . max(0, $offset);
        $st = db()->prepare($sql);
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > self::PAGE;
        $rows = array_slice($rows, 0, self::PAGE);
        foreach ($rows as &$r) {
            $r = self::normUser($r);
            $r['tags'] = AdminPro::tags($r['tags'] ?? '');
        }
        return ['items' => $rows, 'more' => $more];
    }

    private static function normUser(array $r): array
    {
        foreach (['telegram_id', 'balance', 'free_used', 'banned', 'created_at', 'last_request_at', 'jobs'] as $k) {
            if (array_key_exists($k, $r)) {
                $r[$k] = (int)$r[$k];
            }
        }
        return $r;
    }

    public static function user(int $tgId): ?array
    {
        $st = db()->prepare('SELECT telegram_id, username, full_name, first_name, last_name, university, group_name, balance, free_used, banned,
                referred_by, referral_count, referral_earned, created_at, last_request_at, admin_note, tags FROM users WHERE telegram_id = ?');
        $st->execute([$tgId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            return null;
        }
        $u = self::normUser($u);
        $u['tags'] = AdminPro::tags($u['tags'] ?? '');
        $u['support_count'] = (int)db()->query('SELECT COUNT(*) FROM support_msgs WHERE tg = ' . (int)$tgId)->fetchColumn();
        foreach (['referred_by', 'referral_count', 'referral_earned'] as $k) {
            $u[$k] = (int)($u[$k] ?? 0);
        }
        $u['jobs_total'] = self::one('SELECT COUNT(*) FROM jobs WHERE telegram_id = ?', [$tgId]);
        $u['spent'] = self::one("SELECT COALESCE(SUM(price),0) FROM jobs WHERE telegram_id = ? AND status = 'done' AND is_free = 0", [$tgId]);
        $u['topped_up'] = self::one("SELECT COALESCE(SUM(amount),0) FROM topups WHERE telegram_id = ? AND status = 'approved'", [$tgId]);
        $j = db()->prepare('SELECT id, type, substr(topic,1,80) AS topic, volume_key, price, is_free, status, created_at FROM jobs WHERE telegram_id = ? ORDER BY id DESC LIMIT 8');
        $j->execute([$tgId]);
        $u['recent_jobs'] = array_map([self::class, 'normJob'], $j->fetchAll(PDO::FETCH_ASSOC));
        $t = db()->prepare('SELECT id, amount, method, status, created_at FROM topups WHERE telegram_id = ? ORDER BY id DESC LIMIT 8');
        $t->execute([$tgId]);
        $u['recent_topups'] = array_map([self::class, 'normTopup'], $t->fetchAll(PDO::FETCH_ASSOC));
        return $u;
    }

    public static function adjustBalance(int $adminId, int $tgId, int $amount, string $reason, bool $notifyUser): array
    {
        if ($amount === 0 || abs($amount) > ADMIN_MAX_ADJUST) {
            return ['ok' => false, 'error' => 'bad_amount', 'msg' => "Summa 0 bo'lmasligi va " . number_format(ADMIN_MAX_ADJUST, 0, '', ' ') . " so'mdan oshmasligi kerak"];
        }
        $reason = trim(self::cut($reason, 200));
        if (mb_strlen($reason) < 3) {
            return ['ok' => false, 'error' => 'reason_required', 'msg' => 'Sababni yozing (kamida 3 belgi)'];
        }
        $pdo = db();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $st = $pdo->prepare('SELECT balance FROM users WHERE telegram_id = ?');
            $st->execute([$tgId]);
            $old = $st->fetchColumn();
            if ($old === false) {
                $pdo->exec('ROLLBACK');
                return ['ok' => false, 'error' => 'not_found', 'msg' => 'Foydalanuvchi topilmadi'];
            }
            $old = (int)$old;
            if ($old + $amount < 0) {
                $pdo->exec('ROLLBACK');
                return ['ok' => false, 'error' => 'negative', 'msg' => 'Balans manfiy bo\'lib qoladi (hozir: ' . number_format($old, 0, '', ' ') . ' so\'m)'];
            }
            $pdo->prepare('UPDATE users SET balance = balance + ? WHERE telegram_id = ?')->execute([$amount, $tgId]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable $e2) {
            }
            logError('admin', 'balans o\'zgartirishda xato: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'server', 'msg' => 'Server xatosi'];
        }
        $new = $old + $amount;
        AdminSecurity::audit('balance_adjust', $adminId, (string)$tgId, "$old → $new ($amount) · $reason");
        AdminSecurity::notifyAdmins('⚠️ <b>Admin panel: balans o\'zgartirildi</b>' . "\n"
            . 'Foydalanuvchi: <code>' . $tgId . "</code>\n"
            . 'O\'zgarish: <b>' . ($amount > 0 ? '+' : '') . number_format($amount, 0, '', ' ') . " so'm</b> (" . number_format($old, 0, '', ' ') . ' → ' . number_format($new, 0, '', ' ') . ")\n"
            . 'Sabab: ' . htmlspecialchars($reason) . "\nAdmin: <code>$adminId</code>");
        if ($notifyUser) {
            Telegram::sendMessage($tgId, $amount > 0
                ? '💰 Administrator balansingizni <b>' . number_format($amount, 0, '', ' ') . " so'm</b>ga to'ldirdi. Joriy balans: <b>" . number_format($new, 0, '', ' ') . " so'm</b>."
                : '💳 Balansingizdan <b>' . number_format(-$amount, 0, '', ' ') . " so'm</b> ayirildi (administrator). Joriy balans: <b>" . number_format($new, 0, '', ' ') . " so'm</b>.");
        }
        return ['ok' => true, 'balance' => $new];
    }

    public static function setBanned(int $adminId, int $tgId, bool $ban): array
    {
        if (AdminSecurity::isAdmin($tgId)) {
            return ['ok' => false, 'error' => 'forbidden', 'msg' => 'Adminni bloklab bo\'lmaydi'];
        }
        $st = db()->prepare('UPDATE users SET banned = ? WHERE telegram_id = ?');
        $st->execute([$ban ? 1 : 0, $tgId]);
        if ($st->rowCount() === 0) {
            return ['ok' => false, 'error' => 'not_found', 'msg' => 'Foydalanuvchi topilmadi'];
        }
        AdminSecurity::audit($ban ? 'ban' : 'unban', $adminId, (string)$tgId);
        AdminSecurity::notifyAdmins(($ban ? '🚫' : '✅') . ' Admin panel: foydalanuvchi <code>' . $tgId . '</code> ' . ($ban ? 'bloklandi' : 'blokdan chiqarildi'));
        return ['ok' => true, 'banned' => $ban ? 1 : 0];
    }

    public static function sendMessage(int $adminId, int $tgId, string $text): array
    {
        $text = trim(self::cut($text, 1000));
        if ($text === '') {
            return ['ok' => false, 'error' => 'empty', 'msg' => 'Xabar bo\'sh'];
        }
        if (self::one('SELECT COUNT(*) FROM users WHERE telegram_id = ?', [$tgId]) === 0) {
            return ['ok' => false, 'error' => 'not_found', 'msg' => 'Foydalanuvchi topilmadi'];
        }
        $id = Telegram::sendMessage($tgId, "📩 <b>Administrator xabari:</b>\n\n" . htmlspecialchars($text));
        AdminSecurity::audit('send_msg', $adminId, (string)$tgId, self::cut($text, 120));
        return $id ? ['ok' => true] : ['ok' => false, 'error' => 'send_failed', 'msg' => 'Yuborib bo\'lmadi (foydalanuvchi botni bloklagan bo\'lishi mumkin)'];
    }

    // ------------------------------------------------------------------ to'lovlar

    private static function normTopup(array $r): array
    {
        foreach (['id', 'telegram_id', 'amount', 'created_at', 'updated_at'] as $k) {
            if (array_key_exists($k, $r)) {
                $r[$k] = (int)$r[$k];
            }
        }
        return $r;
    }

    /** $filter: pending | all */
    public static function topups(string $filter, int $offset): array
    {
        $where = $filter === 'pending' ? "t.status = 'pending' AND t.method = 'manual' AND t.receipt_file_id IS NOT NULL" : '1=1';
        $st = db()->prepare("SELECT t.id, t.telegram_id, t.amount, t.method, t.status, t.created_at, t.updated_at,
                (t.receipt_file_id IS NOT NULL) AS has_receipt, u.username, u.full_name
            FROM topups t LEFT JOIN users u ON u.telegram_id = t.telegram_id
            WHERE $where ORDER BY t.id DESC LIMIT " . (self::PAGE + 1) . ' OFFSET ' . max(0, $offset));
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > self::PAGE;
        $rows = array_slice($rows, 0, self::PAGE);
        foreach ($rows as &$r) {
            $r = self::normTopup($r);
            $r['has_receipt'] = (int)$r['has_receipt'];
        }
        return ['items' => $rows, 'more' => $more];
    }

    public static function decideTopup(int $adminId, int $id, bool $approve): array
    {
        $st = db()->prepare('SELECT * FROM topups WHERE id = ?');
        $st->execute([$id]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) {
            return ['ok' => false, 'error' => 'not_found', 'msg' => 'To\'lov topilmadi'];
        }
        if ($t['status'] !== 'pending') {
            return ['ok' => false, 'error' => 'already', 'msg' => 'Bu to\'lov allaqachon ko\'rib chiqilgan (' . $t['status'] . ')'];
        }
        // Click/Payme to'lovlari faqat o'z tizimi orqali tasdiqlanadi — admin qo'lda "to'langan" deb belgilay olmasin
        if ($t['method'] !== 'manual') {
            return ['ok' => false, 'error' => 'not_manual', 'msg' => 'Click/Payme to\'lovlarini qo\'lda tasdiqlab bo\'lmaydi'];
        }
        if ($approve && empty($t['receipt_file_id'])) {
            return ['ok' => false, 'error' => 'no_receipt', 'msg' => 'Chek hali yuborilmagan'];
        }
        if ($approve) {
            markTopupPaid($id);
        } else {
            markTopupRejected($id);
        }
        $st->execute([$id]);
        $after = $st->fetch(PDO::FETCH_ASSOC);
        $want = $approve ? 'approved' : 'rejected';
        if (!$after || $after['status'] !== $want) {
            return ['ok' => false, 'error' => 'server', 'msg' => 'Holat o\'zgarmadi, qayta urinib ko\'ring'];
        }
        AdminSecurity::audit($approve ? 'topup_approve' : 'topup_reject', $adminId, '#' . $id, 'user ' . $t['telegram_id'] . ' · ' . $t['amount']);
        AdminSecurity::notifyAdmins(($approve ? '✅' : '❌') . ' Admin panel: to\'ldirish <b>#' . $id . '</b> ' . ($approve ? 'tasdiqlandi' : 'rad etildi')
            . ' (' . number_format((int)$t['amount'], 0, '', ' ') . ' so\'m, user <code>' . $t['telegram_id'] . '</code>)');
        return ['ok' => true, 'status' => $want];
    }

    /** Chek rasmini adminning o'z Telegram chatiga yuboradi (rasmni serverimiz orqali o'tkazmaymiz). */
    public static function sendReceipt(int $adminId, int $id): array
    {
        $st = db()->prepare('SELECT amount, telegram_id, receipt_file_id FROM topups WHERE id = ?');
        $st->execute([$id]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t || empty($t['receipt_file_id'])) {
            return ['ok' => false, 'error' => 'no_receipt', 'msg' => 'Chek topilmadi'];
        }
        $ok = Telegram::sendPhoto($adminId, (string)$t['receipt_file_id'], "🧾 To'ldirish #$id · " . number_format((int)$t['amount'], 0, '', ' ') . " so'm · user " . $t['telegram_id']);
        AdminSecurity::audit('view_receipt', $adminId, '#' . $id);
        return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'send_failed', 'msg' => 'Chekni yuborib bo\'lmadi'];
    }

    // ------------------------------------------------------------------ buyurtmalar

    private static function normJob(array $r): array
    {
        foreach (['id', 'telegram_id', 'price', 'is_free', 'created_at', 'updated_at', 'rating', 'retries', 'has_file', 'tokens', 'progress'] as $k) {
            if (array_key_exists($k, $r) && $r[$k] !== null) {
                $r[$k] = (int)$r[$k];
            }
        }
        return $r;
    }

    /** $status: all | active | done | failed | cancelled */
    public static function jobs(string $status, string $q, int $offset): array
    {
        $where = ['1=1'];
        $p = [];
        switch ($status) {
            case 'active':
                $where[] = "j.status IN ('pending','processing')";
                break;
            case 'done':
            case 'failed':
            case 'cancelled':
                $where[] = 'j.status = :s';
                $p['s'] = $status;
                break;
        }
        $q = trim(self::cut($q, 60));
        if ($q !== '') {
            if (ctype_digit($q)) {
                $where[] = '(j.id = :n OR j.telegram_id = :n)';
                $p['n'] = (int)$q;
            } else {
                $where[] = '(j.topic LIKE :l ESCAPE \'\\\' OR j.student LIKE :l ESCAPE \'\\\')';
                $p['l'] = '%' . self::likeEscape($q) . '%';
            }
        }
        $st = db()->prepare('SELECT j.id, j.telegram_id, j.type, substr(j.topic,1,90) AS topic, j.volume_key, j.price, j.is_free, j.status,
                j.created_at, j.updated_at, u.username FROM jobs j LEFT JOIN users u ON u.telegram_id = j.telegram_id
                WHERE ' . implode(' AND ', $where) . ' ORDER BY j.id DESC LIMIT ' . (self::PAGE + 1) . ' OFFSET ' . max(0, $offset));
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > self::PAGE;
        $rows = array_slice($rows, 0, self::PAGE);
        return ['items' => array_map([self::class, 'normJob'], $rows), 'more' => $more];
    }

    public static function job(int $id): ?array
    {
        $st = db()->prepare('SELECT j.id, j.telegram_id, j.type, j.topic, j.volume_key, j.price, j.is_free, j.design_key, j.rating, j.status,
                j.retries, j.error_message, j.created_at, j.updated_at, j.tg_file_id IS NOT NULL AS has_file, COALESCE(j.tokens,0) AS tokens, j.progress, u.username, u.full_name
            FROM jobs j LEFT JOIN users u ON u.telegram_id = j.telegram_id WHERE j.id = ?');
        $st->execute([$id]);
        $j = $st->fetch(PDO::FETCH_ASSOC);
        if ($j) {
            $sx = db()->prepare('SELECT student FROM jobs WHERE id = ?');
            $sx->execute([$id]);
            $stu = json_decode((string)($sx->fetchColumn() ?: ''), true);
            $j['student'] = is_array($stu) ? $stu : null;
        }
        return $j ? self::normJob($j) : null;
    }

    public static function cancelJob(int $adminId, int $id): array
    {
        $j = self::job($id);
        if (!$j) {
            return ['ok' => false, 'error' => 'not_found', 'msg' => 'Buyurtma topilmadi'];
        }
        // Engine::cancel egasi bo'yicha tekshiradi — admin buyurtma egasi nomidan bekor qiladi (pul avtomatik qaytadi)
        $msg = Engine::cancel($id, (int)$j['telegram_id']);
        AdminSecurity::audit('job_cancel', $adminId, '#' . $id, $msg);
        AdminSecurity::notifyAdmins('🛑 Admin panel: buyurtma <b>#' . $id . '</b> bekor qilishga yuborildi (user <code>' . $j['telegram_id'] . '</code>)');
        return ['ok' => true, 'msg' => $msg];
    }

    // ------------------------------------------------------------------ promokodlar

    private static function normPromo(array $r): array
    {
        foreach (['value', 'max_uses', 'used', 'expires_at', 'active', 'created_at'] as $k) {
            $r[$k] = (int)($r[$k] ?? 0);
        }
        $r['expired'] = $r['expires_at'] > 0 && $r['expires_at'] < time();
        $r['full'] = $r['max_uses'] > 0 && $r['used'] >= $r['max_uses'];
        return $r;
    }

    public static function promos(int $offset): array
    {
        $st = db()->prepare('SELECT code, kind, value, max_uses, used, expires_at, active, note, created_at FROM promo_codes
            ORDER BY active DESC, created_at DESC LIMIT ' . (self::PAGE + 1) . ' OFFSET ' . max(0, $offset));
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > self::PAGE;
        $rows = array_map([self::class, 'normPromo'], array_slice($rows, 0, self::PAGE));
        $tot = db()->query("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN status IN ('applied','consumed') THEN amount ELSE 0 END),0) AS given FROM promo_uses")->fetch(PDO::FETCH_ASSOC);
        return ['items' => $rows, 'more' => $more, 'uses_total' => (int)$tot['n'], 'given_total' => (int)$tot['given']];
    }

    public static function createPromo(int $adminId, string $code, string $kind, int $value, int $max, int $days, string $note): array
    {
        [$ok, $msg, $c] = Promo::create($code, $kind, $value, $max, $days, $note);
        if (!$ok) {
            return ['ok' => false, 'error' => 'invalid', 'msg' => $msg];
        }
        AdminSecurity::audit('promo_create', $adminId, $c, $kind . ' ' . $value . ' limit ' . $max . ' kun ' . $days);
        AdminSecurity::notifyAdmins('🎟 Admin panel: promokod <b>' . Telegram::escape($c) . '</b> yaratildi (' . ($kind === 'balance' ? number_format($value, 0, '', ' ') . " so'm" : "+{$value}%") . ')');
        return ['ok' => true, 'code' => $c, 'msg' => 'Yaratildi: ' . $c];
    }

    public static function setPromoActive(int $adminId, string $code, bool $active): array
    {
        $code = Promo::normalize($code);
        $st = db()->prepare('UPDATE promo_codes SET active = ? WHERE code = ?');
        $st->execute([$active ? 1 : 0, $code]);
        if ($st->rowCount() === 0) {
            return ['ok' => false, 'error' => 'not_found', 'msg' => 'Kod topilmadi'];
        }
        AdminSecurity::audit($active ? 'promo_on' : 'promo_off', $adminId, $code);
        return ['ok' => true, 'msg' => $active ? 'Yoqildi' : "O'chirildi"];
    }

    public static function deletePromo(int $adminId, string $code): array
    {
        $code = Promo::normalize($code);
        $st = db()->prepare('SELECT used FROM promo_codes WHERE code = ?');
        $st->execute([$code]);
        $used = $st->fetchColumn();
        if ($used === false) {
            return ['ok' => false, 'error' => 'not_found', 'msg' => 'Kod topilmadi'];
        }
        if ((int)$used > 0) {
            return ['ok' => false, 'error' => 'used', 'msg' => "Ishlatilgan kodni o'chirib bo'lmaydi — faqat o'chirib qo'ying (statistika saqlanadi)"];
        }
        db()->prepare('DELETE FROM promo_codes WHERE code = ?')->execute([$code]);
        AdminSecurity::audit('promo_delete', $adminId, $code);
        return ['ok' => true, 'msg' => "O'chirildi"];
    }

    public static function promoUses(string $code, int $offset): array
    {
        $code = Promo::normalize($code);
        $st = db()->prepare('SELECT p.telegram_id, p.kind, p.value, p.status, p.amount, p.created_at, u.username, u.full_name
            FROM promo_uses p LEFT JOIN users u ON u.telegram_id = p.telegram_id WHERE p.code = ? ORDER BY p.id DESC LIMIT ' . (self::PAGE + 1) . ' OFFSET ' . max(0, $offset));
        $st->execute([$code]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > self::PAGE;
        $rows = array_slice($rows, 0, self::PAGE);
        foreach ($rows as &$r) {
            foreach (['telegram_id', 'value', 'amount', 'created_at'] as $k) {
                $r[$k] = (int)$r[$k];
            }
        }
        return ['items' => $rows, 'more' => $more];
    }

    // ------------------------------------------------------------------ jurnal

    public static function audit(int $offset): array
    {
        $st = db()->prepare('SELECT id, ts, ip, tg_id, event, target, detail FROM admin_audit ORDER BY id DESC LIMIT ' . (self::PAGE + 1) . ' OFFSET ' . max(0, $offset));
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > self::PAGE;
        $rows = array_slice($rows, 0, self::PAGE);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['ts'] = (int)$r['ts'];
            $r['tg_id'] = $r['tg_id'] !== null ? (int)$r['tg_id'] : null;
        }
        return ['items' => $rows, 'more' => $more];
    }
}
