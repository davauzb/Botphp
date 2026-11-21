<?php

/**
 * Admin panelning kengaytirilgan imkoniyatlari:
 *  - ROLLAR: owner (ADMIN_CHAT_ID) / admin / operator. Operator faqat cheklar, buyurtmalar va yordam bilan ishlaydi.
 *  - YORDAM QUTISI: mijozlar «💬 Yordam» orqali yozadi → panelda suhbat → admin javobi Telegram'ga boradi.
 *  - MIJOZ ESLATMASI VA TEGLARI (faqat adminlarga ko'rinadi).
 *  - MOLIYAVIY HISOBOT (oylar, to'lov usullari, buyurtma turlari) va Excel (CSV) eksporti — Telegram'ga fayl bo'lib keladi.
 *  - KONVERSIYA VORONKASI: ro'yxatdan o'tdi → buyurtma berdi → to'ladi → qayta buyurtma qildi.
 *  - AI SARFI: kunlik so'rov/token, provayderlar bo'yicha, har tur bo'yicha o'rtacha.
 *  - JONLI NAVBAT, BUYURTMANI QAYTA ISHGA TUSHIRISH va FAYLNI QAYTA YUBORISH.
 */
class AdminPro
{
    public const ROLES = ['admin' => 'Admin', 'operator' => 'Operator'];

    /** Operator bajarishi mumkin bo'lgan amallar (qolganlari faqat admin/owner). */
    public const OPERATOR_ACTIONS = ['ping', 'logout', 'me', 'stats', 'topups', 'topup_decide', 'receipt', 'jobs', 'job', 'job_resend', 'events', 'events_read',
        'users', 'user', 'user_meta', 'support_list', 'support_thread', 'support_reply', 'support_close', 'queue'];
    /** Faqat owner (ADMIN_CHAT_ID). */
    public const OWNER_ACTIONS = ['roles_list', 'roles_save', 'roles_delete', 'ai_keys', 'ai_key_add', 'ai_key_toggle', 'ai_key_delete', 'ai_key_test', 'logs_clear', 'agent_payout'];

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_roles (tg INTEGER PRIMARY KEY, role TEXT NOT NULL, name TEXT, added_by INTEGER, created_at INTEGER)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS support_msgs (id INTEGER PRIMARY KEY AUTOINCREMENT, tg INTEGER NOT NULL, from_admin INTEGER NOT NULL DEFAULT 0,
            text TEXT NOT NULL, ts INTEGER NOT NULL, is_read INTEGER NOT NULL DEFAULT 0, closed INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_support_tg ON support_msgs (tg, id)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS ai_usage (day TEXT NOT NULL, provider TEXT NOT NULL, model TEXT NOT NULL, calls INTEGER NOT NULL DEFAULT 0,
            prompt INTEGER NOT NULL DEFAULT 0, completion INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (day, provider, model))');
        foreach (['users' => ['admin_note TEXT', 'tags TEXT'], 'jobs' => ['tokens INTEGER DEFAULT 0']] as $t => $defs) {
            foreach ($defs as $d) {
                try {
                    $pdo->exec("ALTER TABLE $t ADD COLUMN $d");
                } catch (Throwable $e) {
                }
            }
        }
    }

    private static function one(string $sql, array $p = []): int
    {
        $st = db()->prepare($sql);
        $st->execute($p);
        return (int)($st->fetchColumn() ?: 0);
    }

    private static function tz(): int
    {
        return defined('ADMIN_TZ_OFFSET') ? (int)ADMIN_TZ_OFFSET : 18000;
    }

    // ================================================================== rollar

    public static function dbAdmins(): array
    {
        try {
            return array_map('intval', db()->query('SELECT tg FROM admin_roles')->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function role(int $tg): ?string
    {
        if (defined('ADMIN_CHAT_ID') && (int)ADMIN_CHAT_ID === $tg && $tg > 0) {
            return 'owner';
        }
        if (in_array($tg, array_map('intval', (array)(defined('ADMIN_IDS') ? ADMIN_IDS : [])), true)) {
            return 'admin';
        }
        try {
            $st = db()->prepare('SELECT role FROM admin_roles WHERE tg = ?');
            $st->execute([$tg]);
            $r = $st->fetchColumn();
            return in_array($r, ['admin', 'operator'], true) ? $r : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function can(int $tg, string $action): bool
    {
        $role = self::role($tg);
        if ($role === null) {
            return false;
        }
        if (in_array($action, self::OWNER_ACTIONS, true)) {
            return $role === 'owner';
        }
        return $role !== 'operator' || in_array($action, self::OPERATOR_ACTIONS, true);
    }

    public static function rolesList(): array
    {
        $out = [];
        if (defined('ADMIN_CHAT_ID') && (int)ADMIN_CHAT_ID > 0) {
            $out[] = ['tg' => (int)ADMIN_CHAT_ID, 'role' => 'owner', 'name' => self::userName((int)ADMIN_CHAT_ID), 'fixed' => true];
        }
        foreach ((array)(defined('ADMIN_IDS') ? ADMIN_IDS : []) as $id) {
            if ((int)$id > 0) {
                $out[] = ['tg' => (int)$id, 'role' => 'admin', 'name' => self::userName((int)$id), 'fixed' => true];
            }
        }
        foreach (db()->query('SELECT tg, role, name, created_at FROM admin_roles ORDER BY created_at')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['tg' => (int)$r['tg'], 'role' => $r['role'], 'name' => $r['name'] ?: self::userName((int)$r['tg']), 'fixed' => false, 'created_at' => (int)$r['created_at']];
        }
        return $out;
    }

    private static function userName(int $tg): string
    {
        $st = db()->prepare('SELECT full_name, username FROM users WHERE telegram_id = ?');
        $st->execute([$tg]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        return $u ? (string)($u['full_name'] ?: ($u['username'] ? '@' . $u['username'] : '')) : '';
    }

    public static function roleSave(int $owner, int $tg, string $role, string $name): array
    {
        if ($tg <= 0 || !isset(self::ROLES[$role])) {
            return ['ok' => false, 'error' => 'bad', 'msg' => 'Telegram ID va rolni to\'g\'ri kiriting'];
        }
        if (self::role($tg) === 'owner' || in_array($tg, array_map('intval', (array)(defined('ADMIN_IDS') ? ADMIN_IDS : [])), true)) {
            return ['ok' => false, 'error' => 'fixed', 'msg' => 'Bu admin config.php da belgilangan — paneldan o\'zgartirilmaydi'];
        }
        db()->prepare('INSERT INTO admin_roles (tg, role, name, added_by, created_at) VALUES (?,?,?,?,?) ON CONFLICT(tg) DO UPDATE SET role = excluded.role, name = excluded.name')
            ->execute([$tg, $role, mb_substr(trim($name), 0, 60), $owner, time()]);
        AdminSecurity::audit('role_save', $owner, (string)$tg, $role);
        try {
            Telegram::sendMessage($tg, "🛡 Sizga StudyBot admin panelida <b>" . self::ROLES[$role] . "</b> huquqi berildi. Kirish: /admin");
        } catch (Throwable $e) {
        }
        return ['ok' => true, 'msg' => 'Saqlandi'];
    }

    public static function roleDelete(int $owner, int $tg): array
    {
        $st = db()->prepare('DELETE FROM admin_roles WHERE tg = ?');
        $st->execute([$tg]);
        if (!$st->rowCount()) {
            return ['ok' => false, 'error' => 'not_found', 'msg' => 'Topilmadi'];
        }
        // ochiq sessiyalari darhol yopiladi
        try {
            db()->prepare('DELETE FROM admin_sessions WHERE tg_id = ?')->execute([$tg]);
        } catch (Throwable $e) {
        }
        AdminSecurity::audit('role_delete', $owner, (string)$tg);
        return ['ok' => true, 'msg' => "Huquq olib tashlandi"];
    }

    // ================================================================== yordam qutisi

    public static function supportIncoming(int $tg, string $text): void
    {
        $text = mb_substr(trim($text), 0, 2000);
        if ($text === '') {
            return;
        }
        db()->prepare('INSERT INTO support_msgs (tg, from_admin, text, ts) VALUES (?, 0, ?, ?)')->execute([$tg, $text, time()]);
        db()->prepare('UPDATE support_msgs SET closed = 0 WHERE tg = ?')->execute([$tg]);
        try {
            if (class_exists('AdminEvents') && method_exists('AdminEvents', 'support')) {
                AdminEvents::support($tg, $text);
            }
        } catch (Throwable $e) {
        }
    }

    public static function supportList(string $filter, int $offset): array
    {
        $where = $filter === 'open' ? 'HAVING SUM(CASE WHEN m.closed = 0 THEN 1 ELSE 0 END) > 0' : '';
        $st = db()->prepare("SELECT m.tg, MAX(m.id) last_id, SUM(CASE WHEN m.from_admin = 0 AND m.is_read = 0 THEN 1 ELSE 0 END) unread, MAX(m.ts) ts,
                MIN(m.closed) closed_min
            FROM support_msgs m GROUP BY m.tg $where ORDER BY MAX(m.id) DESC LIMIT 31 OFFSET ?");
        $st->execute([$offset]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > 30;
        $items = [];
        $lt = db()->prepare('SELECT text, from_admin FROM support_msgs WHERE id = ?');
        $ut = db()->prepare('SELECT full_name, username, tags FROM users WHERE telegram_id = ?');
        foreach (array_slice($rows, 0, 30) as $r) {
            $lt->execute([$r['last_id']]);
            $last = $lt->fetch(PDO::FETCH_ASSOC) ?: [];
            $ut->execute([$r['tg']]);
            $u = $ut->fetch(PDO::FETCH_ASSOC) ?: [];
            $items[] = ['telegram_id' => (int)$r['tg'], 'full_name' => $u['full_name'] ?? null, 'username' => $u['username'] ?? null, 'tags' => self::tags($u['tags'] ?? ''),
                'last' => mb_substr((string)($last['text'] ?? ''), 0, 140), 'last_admin' => (int)($last['from_admin'] ?? 0) > 0,
                'unread' => (int)$r['unread'], 'ts' => (int)$r['ts'], 'closed' => (int)$r['closed_min'] === 1];
        }
        return ['items' => $items, 'more' => $more, 'unread_total' => self::supportUnread()];
    }

    public static function supportUnread(): int
    {
        try {
            return self::one('SELECT COUNT(DISTINCT tg) FROM support_msgs WHERE from_admin = 0 AND is_read = 0');
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function supportThread(int $tg): array
    {
        $st = db()->prepare('SELECT id, from_admin, text, ts FROM support_msgs WHERE tg = ? ORDER BY id DESC LIMIT 100');
        $st->execute([$tg]);
        $msgs = array_reverse(array_map(fn($r) => ['id' => (int)$r['id'], 'admin' => (int)$r['from_admin'], 'text' => $r['text'], 'ts' => (int)$r['ts']], $st->fetchAll(PDO::FETCH_ASSOC)));
        db()->prepare('UPDATE support_msgs SET is_read = 1 WHERE tg = ? AND from_admin = 0')->execute([$tg]);
        return ['messages' => $msgs, 'user' => AdminService::user($tg)];
    }

    public static function supportReply(int $admin, int $tg, string $text): array
    {
        $text = mb_substr(trim($text), 0, 2000);
        if ($text === '') {
            return ['ok' => false, 'error' => 'empty', 'msg' => 'Javobni yozing'];
        }
        $id = Telegram::sendMessage($tg, "💬 <b>Administrator javobi:</b>\n\n" . htmlspecialchars($text),
            ['inline_keyboard' => [[['text' => '✍️ Javob yozish', 'callback_data' => 'sup:reply']]]]);
        if (!$id) {
            return ['ok' => false, 'error' => 'send', 'msg' => 'Yuborib bo\'lmadi (mijoz botni bloklagan bo\'lishi mumkin)'];
        }
        db()->prepare('INSERT INTO support_msgs (tg, from_admin, text, ts, is_read) VALUES (?, ?, ?, ?, 1)')->execute([$tg, $admin, $text, time()]);
        db()->prepare('UPDATE support_msgs SET is_read = 1 WHERE tg = ? AND from_admin = 0')->execute([$tg]);
        AdminSecurity::audit('support_reply', $admin, (string)$tg, mb_substr($text, 0, 120));
        return ['ok' => true, 'msg' => 'Yuborildi'];
    }

    public static function supportClose(int $admin, int $tg): array
    {
        db()->prepare('UPDATE support_msgs SET closed = 1, is_read = 1 WHERE tg = ?')->execute([$tg]);
        return ['ok' => true, 'msg' => 'Suhbat yopildi'];
    }

    // ================================================================== eslatma va teglar

    public static function tags($raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string)$raw))));
    }

    public static function userMeta(int $admin, int $tg, string $note, array $tags): array
    {
        $clean = [];
        foreach (array_slice($tags, 0, 6) as $t) {
            $t = mb_strtolower(preg_replace('/[^\p{L}\p{N}_\-]+/u', '', (string)$t) ?? '');
            if ($t !== '' && mb_strlen($t) <= 20) {
                $clean[] = $t;
            }
        }
        $st = db()->prepare('UPDATE users SET admin_note = ?, tags = ? WHERE telegram_id = ?');
        $st->execute([mb_substr(trim($note), 0, 1000) ?: null, $clean ? implode(',', array_unique($clean)) : null, $tg]);
        if (!$st->rowCount()) {
            return ['ok' => false, 'error' => 'not_found', 'msg' => 'Topilmadi'];
        }
        AdminSecurity::audit('user_meta', $admin, (string)$tg, implode(',', $clean));
        return ['ok' => true, 'msg' => 'Saqlandi'];
    }

    // ================================================================== moliya

    public static function finance(int $months): array
    {
        $months = max(1, min(24, $months));
        $tz = self::tz();
        $from = strtotime(gmdate('Y-m-01', time() + $tz) . ' -' . ($months - 1) . ' months') - $tz;
        $pdo = db();
        $q = function (string $sql, array $p) use ($pdo) {
            $st = $pdo->prepare($sql);
            $st->execute($p);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        };
        $rows = [];
        for ($i = 0; $i < $months; $i++) {
            $m = gmdate('Y-m', strtotime(gmdate('Y-m-01', $from + $tz) . " +$i months"));
            $rows[$m] = ['m' => $m, 'topup' => 0, 'methods' => [], 'sales' => 0, 'orders' => 0, 'free' => 0, 'types' => [], 'users' => 0, 'refund' => 0];
        }
        foreach ($q("SELECT strftime('%Y-%m', updated_at + :tz, 'unixepoch') m, method, SUM(amount) s FROM topups WHERE status = 'approved' AND updated_at >= :f GROUP BY m, method", ['tz' => $tz, 'f' => $from]) as $r) {
            if (isset($rows[$r['m']])) {
                $rows[$r['m']]['topup'] += (int)$r['s'];
                $rows[$r['m']]['methods'][$r['method']] = (int)$r['s'];
            }
        }
        foreach ($q("SELECT strftime('%Y-%m', updated_at + :tz, 'unixepoch') m, type, is_free, COUNT(*) c, SUM(price) s FROM jobs WHERE status = 'done' AND updated_at >= :f GROUP BY m, type, is_free", ['tz' => $tz, 'f' => $from]) as $r) {
            if (!isset($rows[$r['m']])) {
                continue;
            }
            if ((int)$r['is_free'] === 1) {
                $rows[$r['m']]['free'] += (int)$r['c'];
                continue;
            }
            $rows[$r['m']]['orders'] += (int)$r['c'];
            $rows[$r['m']]['sales'] += (int)$r['s'];
            $t = &$rows[$r['m']]['types'][$r['type']];
            $t = ['c' => ($t['c'] ?? 0) + (int)$r['c'], 's' => ($t['s'] ?? 0) + (int)$r['s']];
            unset($t);
        }
        foreach ($q("SELECT strftime('%Y-%m', created_at + :tz, 'unixepoch') m, COUNT(*) c FROM users WHERE created_at >= :f GROUP BY m", ['tz' => $tz, 'f' => $from]) as $r) {
            if (isset($rows[$r['m']])) {
                $rows[$r['m']]['users'] = (int)$r['c'];
            }
        }
        foreach ($q("SELECT strftime('%Y-%m', updated_at + :tz, 'unixepoch') m, SUM(price) s FROM jobs WHERE status IN ('failed','cancelled') AND is_free = 0 AND updated_at >= :f GROUP BY m", ['tz' => $tz, 'f' => $from]) as $r) {
            if (isset($rows[$r['m']])) {
                $rows[$r['m']]['refund'] = (int)$r['s'];
            }
        }
        $byVol = $q("SELECT type, volume_key, COUNT(*) c, SUM(price) s FROM jobs WHERE status = 'done' AND is_free = 0 AND updated_at >= :f GROUP BY type, volume_key ORDER BY s DESC LIMIT 15", ['f' => $from]);
        $tot = ['topup' => 0, 'sales' => 0, 'orders' => 0, 'users' => 0, 'free' => 0];
        foreach ($rows as $r) {
            foreach ($tot as $k => $_) {
                $tot[$k] += $r[$k];
            }
        }
        $avg = $tot['orders'] ? (int)round($tot['sales'] / $tot['orders']) : 0;
        return ['months' => array_values($rows), 'total' => $tot + ['avg_check' => $avg],
            'by_volume' => array_map(fn($r) => ['type' => $r['type'], 'vk' => $r['volume_key'], 'c' => (int)$r['c'], 's' => (int)$r['s']], $byVol)];
    }

    /** CSV (Excel'da to'g'ri ochiladi: UTF-8 BOM + «;» ajratuvchi) → admin Telegram'iga fayl. */
    public static function export(int $admin, string $kind, int $months): array
    {
        $tz = self::tz();
        $lines = [];
        $cell = fn($v) => '"' . str_replace('"', '""', (string)$v) . '"';
        if ($kind === 'orders') {
            $from = time() - max(1, min(24, $months)) * 31 * 86400;
            $st = db()->prepare('SELECT j.id, j.created_at, j.type, j.volume_key, j.topic, j.price, j.is_free, j.status, j.telegram_id, u.username, u.full_name, j.design_key, j.rating
                FROM jobs j LEFT JOIN users u ON u.telegram_id = j.telegram_id WHERE j.created_at >= ? ORDER BY j.id DESC LIMIT 20000');
            $st->execute([$from]);
            $lines[] = implode(';', ['ID', 'Sana', 'Tur', 'Hajm', 'Mavzu', 'Narx', 'Bepul', 'Holat', 'Telegram ID', 'Username', 'Ism', 'Dizayn', 'Baho']);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $lines[] = implode(';', array_map($cell, [$r['id'], gmdate('Y-m-d H:i', (int)$r['created_at'] + $tz), DocTypes::title((string)$r['type']), $r['volume_key'],
                    $r['topic'], $r['price'], $r['is_free'] ? 'ha' : '', $r['status'], $r['telegram_id'], $r['username'], $r['full_name'], $r['design_key'], $r['rating']]));
            }
            $name = 'buyurtmalar_' . gmdate('Y-m-d', time() + $tz) . '.csv';
        } else {
            $f = self::finance($months);
            $methods = ['click', 'payme', 'humo', 'manual', 'stars'];
            $types = array_keys(DocTypes::T);
            $lines[] = implode(';', array_merge(['Oy', "Kirim (to'ldirish)"], array_map(fn($m) => "Kirim: $m", $methods), ['Sotuv', 'Buyurtmalar', "O'rtacha chek", 'Bepul', 'Qaytarilgan', 'Yangi mijozlar'],
                array_map(fn($t) => DocTypes::title($t) . ' (soni)', $types), array_map(fn($t) => DocTypes::title($t) . " (so'm)", $types)));
            foreach ($f['months'] as $r) {
                $lines[] = implode(';', array_map($cell, array_merge([$r['m'], $r['topup']], array_map(fn($m) => $r['methods'][$m] ?? 0, $methods),
                    [$r['sales'], $r['orders'], $r['orders'] ? (int)round($r['sales'] / $r['orders']) : 0, $r['free'], $r['refund'], $r['users']],
                    array_map(fn($t) => $r['types'][$t]['c'] ?? 0, $types), array_map(fn($t) => $r['types'][$t]['s'] ?? 0, $types))));
            }
            $name = 'moliya_' . gmdate('Y-m-d', time() + $tz) . '.csv';
        }
        $path = rtrim(GENERATED_DIR, '/') . '/' . $name;
        file_put_contents($path, "\xEF\xBB\xBFsep=;\r\n" . implode("\r\n", $lines) . "\r\n");
        $ok = Telegram::sendDocument($admin, $path, '📊 ' . ($kind === 'orders' ? 'Buyurtmalar ro\'yxati' : 'Moliyaviy hisobot') . ' (Excel\'da oching)');
        @unlink($path);
        AdminSecurity::audit('export', $admin, $kind, (string)count($lines));
        return $ok ? ['ok' => true, 'msg' => 'Fayl Telegram\'ingizga yuborildi'] : ['ok' => false, 'error' => 'send', 'msg' => 'Yuborib bo\'lmadi'];
    }

    // ================================================================== voronka

    public static function funnel(int $days): array
    {
        $from = $days > 0 ? time() - $days * 86400 : 0;
        $cohort = 'SELECT telegram_id FROM users WHERE created_at >= ' . (int)$from;
        $users = self::one('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$from]);
        $started = self::one("SELECT COUNT(DISTINCT telegram_id) FROM jobs WHERE telegram_id IN ($cohort)");
        $done = self::one("SELECT COUNT(DISTINCT telegram_id) FROM jobs WHERE status = 'done' AND telegram_id IN ($cohort)");
        $paid = self::one("SELECT COUNT(*) FROM ($cohort) c WHERE c.telegram_id IN (SELECT telegram_id FROM jobs WHERE is_free = 0 AND price > 0 AND status = 'done')
            OR c.telegram_id IN (SELECT telegram_id FROM topups WHERE status = 'approved')");
        $repeat = self::one("SELECT COUNT(*) FROM (SELECT telegram_id FROM jobs WHERE status = 'done' AND telegram_id IN ($cohort) GROUP BY telegram_id HAVING COUNT(*) >= 2)");
        $ref = self::one('SELECT COUNT(*) FROM users WHERE created_at >= ? AND referred_by IS NOT NULL AND referred_by > 0', [$from]);
        return ['steps' => [
            ['k' => 'users', 'l' => "Ro'yxatdan o'tdi", 'v' => $users],
            ['k' => 'started', 'l' => 'Buyurtma boshladi', 'v' => $started],
            ['k' => 'done', 'l' => 'Fayl oldi', 'v' => $done],
            ['k' => 'paid', 'l' => "Pul to'ladi", 'v' => $paid],
            ['k' => 'repeat', 'l' => 'Qayta buyurtma qildi', 'v' => $repeat],
        ], 'referred' => $ref, 'days' => $days];
    }

    // ================================================================== AI sarfi

    public static function recordUsage(string $provider, string $model, int $prompt, int $completion, ?int $jobId): void
    {
        try {
            $day = gmdate('Y-m-d', time() + self::tz());
            db()->prepare('INSERT INTO ai_usage (day, provider, model, calls, prompt, completion) VALUES (?,?,?,1,?,?)
                ON CONFLICT(day, provider, model) DO UPDATE SET calls = calls + 1, prompt = prompt + excluded.prompt, completion = completion + excluded.completion')
                ->execute([$day, $provider, mb_substr($model, 0, 80), $prompt, $completion]);
            if ($jobId) {
                db()->prepare('UPDATE jobs SET tokens = COALESCE(tokens,0) + ? WHERE id = ?')->execute([$prompt + $completion, $jobId]);
            }
        } catch (Throwable $e) {
        }
    }

    public static function aiUsage(int $days): array
    {
        $days = in_array($days, [7, 14, 30], true) ? $days : 14;
        $tz = self::tz();
        $from = gmdate('Y-m-d', time() + $tz - ($days - 1) * 86400);
        $st = db()->prepare('SELECT day, SUM(calls) c, SUM(prompt) p, SUM(completion) o FROM ai_usage WHERE day >= ? GROUP BY day');
        $st->execute([$from]);
        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[$r['day']] = $r;
        }
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', time() + $tz - $i * 86400);
            $series[] = ['d' => $d, 'calls' => (int)($map[$d]['c'] ?? 0), 'tokens' => (int)(($map[$d]['p'] ?? 0) + ($map[$d]['o'] ?? 0)), 'out' => (int)($map[$d]['o'] ?? 0)];
        }
        $st = db()->prepare('SELECT provider, model, SUM(calls) c, SUM(prompt) p, SUM(completion) o FROM ai_usage WHERE day >= ? GROUP BY provider, model ORDER BY (SUM(prompt)+SUM(completion)) DESC');
        $st->execute([$from]);
        $prov = array_map(fn($r) => ['p' => $r['provider'], 'm' => $r['model'], 'calls' => (int)$r['c'], 'in' => (int)$r['p'], 'out' => (int)$r['o']], $st->fetchAll(PDO::FETCH_ASSOC));
        $st = db()->prepare("SELECT type, COUNT(*) c, AVG(tokens) a FROM jobs WHERE status = 'done' AND tokens > 0 AND updated_at >= ? GROUP BY type");
        $st->execute([time() - 30 * 86400]);
        $perType = array_map(fn($r) => ['type' => $r['type'], 'title' => DocTypes::title((string)$r['type']), 'jobs' => (int)$r['c'], 'avg' => (int)round((float)$r['a'])], $st->fetchAll(PDO::FETCH_ASSOC));
        return ['series' => $series, 'providers' => $prov, 'per_type' => $perType, 'days' => $days];
    }

    // ================================================================== jonli navbat

    public static function queue(): array
    {
        $rows = db()->query("SELECT j.id, j.telegram_id, j.type, j.volume_key, j.topic, j.status, j.progress, j.state, j.created_at, j.started_at, j.updated_at, u.username, u.full_name
            FROM jobs j LEFT JOIN users u ON u.telegram_id = j.telegram_id WHERE j.status IN ('pending','processing') ORDER BY (j.started_at IS NULL), j.id LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $st = $r['state'] ? json_decode((string)$r['state'], true) : null;
            $step = '';
            if (is_array($st) && isset($st['steps'][$st['i'] ?? 0])) {
                $step = (string)($st['steps'][$st['i']]['label'] ?? '');
            }
            $out[] = ['id' => (int)$r['id'], 'telegram_id' => (int)$r['telegram_id'], 'type' => $r['type'], 'volume_key' => $r['volume_key'], 'topic' => mb_substr((string)$r['topic'], 0, 90),
                'status' => $r['status'], 'pct' => (int)$r['progress'], 'step' => $step, 'created_at' => (int)$r['created_at'], 'started_at' => (int)($r['started_at'] ?? 0),
                'updated_at' => (int)$r['updated_at'], 'username' => $r['username'], 'full_name' => $r['full_name']];
        }
        return ['items' => $out, 'now' => time()];
    }

    // ================================================================== qayta ishga tushirish / qayta yuborish

    public static function resend(int $admin, int $jobId): array
    {
        $st = db()->prepare('SELECT * FROM jobs WHERE id = ?');
        $st->execute([$jobId]);
        $j = $st->fetch(PDO::FETCH_ASSOC);
        if (!$j || empty($j['tg_file_id'])) {
            return ['ok' => false, 'error' => 'no_file', 'msg' => 'Bu buyurtmada yuborilgan fayl yo\'q'];
        }
        $ok = Telegram::sendDocumentById((int)$j['telegram_id'], (string)$j['tg_file_id'], "📎 Administrator #{$jobId} buyurtma faylini qayta yubordi.");
        AdminSecurity::audit('job_resend', $admin, '#' . $jobId);
        return $ok ? ['ok' => true, 'msg' => 'Fayl mijozga qayta yuborildi'] : ['ok' => false, 'error' => 'send', 'msg' => 'Yuborib bo\'lmadi'];
    }

    /** $gift = true — mijozdan pul yechilmaydi (admin hisobidan). */
    public static function retry(int $admin, int $jobId, bool $gift): array
    {
        $st = db()->prepare('SELECT * FROM jobs WHERE id = ?');
        $st->execute([$jobId]);
        $j = $st->fetch(PDO::FETCH_ASSOC);
        if (!$j || !in_array($j['status'], ['failed', 'cancelled', 'done'], true)) {
            return ['ok' => false, 'error' => 'state', 'msg' => 'Faqat tugagan, xato bergan yoki bekor qilingan buyurtmani qayta ishga tushirish mumkin'];
        }
        $tg = (int)$j['telegram_id'];
        $price = $gift ? 0 : (int)$j['price'];
        if ($price > 0) {
            $u = db()->prepare('UPDATE users SET balance = balance - ? WHERE telegram_id = ? AND balance >= ?');
            $u->execute([$price, $tg, $price]);
            if (!$u->rowCount()) {
                return ['ok' => false, 'error' => 'balance', 'msg' => "Mijoz balansida mablag' yetarli emas — «🎁 Bepul» variantini tanlang"];
            }
        }
        try {
            $student = json_decode((string)($j['student'] ?? ''), true);
            $newId = Queue::createJob($tg, (string)$j['type'], (string)$j['topic'], (string)$j['volume_key'], $price, false, true,
                $j['design_key'] ?: null, $j['lang'] ?: null, is_array($student) ? $student : null);
        } catch (Throwable $e) {
            if ($price > 0) {
                db()->prepare('UPDATE users SET balance = balance + ? WHERE telegram_id = ?')->execute([$price, $tg]);
            }
            return ['ok' => false, 'error' => 'server', 'msg' => 'Buyurtma yaratilmadi'];
        }
        Engine::onJobPending($newId, "🔁 Administrator #{$jobId} buyurtmangizni qayta ishga tushirdi" . ($gift ? ' (bepul)' : ' (' . number_format($price, 0, '', ' ') . " so'm balansdan)") . '.');
        AdminSecurity::audit('job_retry', $admin, '#' . $jobId . ' → #' . $newId, $gift ? 'bepul' : (string)$price);
        return ['ok' => true, 'msg' => "Yangi buyurtma #$newId navbatga qo'yildi", 'id' => $newId];
    }

    // ================================================================== xatolar jurnali

    /** Maxfiy qiymatlarni yashiradi (bot tokeni, AI kalitlari, parollar). */
    public static function mask(string $s): string
    {
        $s = preg_replace('/\d{6,12}:[A-Za-z0-9_\-]{30,}/', '[BOT_TOKEN]', $s) ?? $s;
        $s = preg_replace('/\b(sk-[A-Za-z0-9_\-]{8}|AIza[0-9A-Za-z_\-]{4}|gsk_[A-Za-z0-9]{4}|csk-[A-Za-z0-9]{4})[A-Za-z0-9_\-]{8,}/', '$1…', $s) ?? $s;
        $s = preg_replace('/(Bearer\s+)[A-Za-z0-9._\-]{12,}/i', '$1…', $s) ?? $s;
        $s = preg_replace('/((?:pass(?:word)?|secret|token|key)["\']?\s*[:=]\s*["\']?)[^\s"\',&]{6,}/i', '$1…', $s) ?? $s;
        return $s;
    }

    /** app.log oxiridan (≈400 KB) yozuvlar: yangilari birinchi. */
    public static function logs(string $q, string $ctx, int $limit = 200): array
    {
        $file = LOG_DIR . 'app.log';
        $items = [];
        $ctxCount = [];
        $size = is_file($file) ? (int)filesize($file) : 0;
        if ($size > 0) {
            $fh = fopen($file, 'rb');
            $read = min($size, 400 * 1024);
            fseek($fh, $size - $read);
            $chunk = (string)fread($fh, $read);
            fclose($fh);
            $lines = preg_split('/\R(?=\[\d{4}-\d\d-\d\d )/', $chunk) ?: [];
            if ($read < $size) {
                array_shift($lines);                 // birinchi qator yarim bo'lishi mumkin
            }
            $day = time() - 86400;
            foreach (array_reverse($lines) as $ln) {
                if (!preg_match('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\] \[([^\]]{1,40})\] (.*)$/s', trim($ln), $m)) {
                    continue;
                }
                $ts = (int)strtotime($m[1]);
                if ($ts >= $day) {
                    $ctxCount[$m[2]] = ($ctxCount[$m[2]] ?? 0) + 1;
                }
                if ($ctx !== '' && $m[2] !== $ctx) {
                    continue;
                }
                if ($q !== '' && mb_stripos($m[3], $q) === false) {
                    continue;
                }
                if (count($items) < $limit) {
                    $items[] = ['ts' => $ts, 'ctx' => $m[2], 'msg' => mb_substr(self::mask($m[3]), 0, 1200)];
                }
            }
        }
        arsort($ctxCount);
        $c = [];
        foreach ($ctxCount as $k => $n) {
            $c[] = ['ctx' => $k, 'n' => $n];
        }
        return ['ok' => true, 'items' => $items, 'contexts' => $c, 'size' => $size];
    }

    public static function logsClear(int $admin): array
    {
        $file = LOG_DIR . 'app.log';
        if (is_file($file)) {
            @rename($file, LOG_DIR . 'app.' . date('Ymd_His') . '.log');
            foreach (array_slice(array_reverse(glob(LOG_DIR . 'app.*.log') ?: []), 3) as $old) {
                @unlink($old);                       // eng ko'pi 3 ta eski nusxa
            }
        }
        AdminSecurity::audit('logs_clear', $admin);
        return ['ok' => true, 'msg' => 'Jurnal arxivlandi va tozalandi'];
    }
}
