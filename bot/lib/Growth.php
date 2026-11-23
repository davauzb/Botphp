<?php

/**
 * O'SISH MODULI (14-10):
 *  • «Talaba PRO» obunasi — oylik to'lov evaziga N ta buyurtma (balansdan bir marta yechiladi, har buyurtmada 1 kredit ishlatiladi).
 *  • Agentlar (hamkorlar) — maxsus havola (t.me/bot?start=agt<ID>) orqali kelgan mijozlarning HAR pullik buyurtmasidan % ulush.
 *  • Avtomatik marketing — soatiga bir marta: ro'yxatdan o'tib buyurtma bermaganlarga eslatma, birinchi buyurtmadan keyin
 *    boshqa turlar haqida xabar, 30 kun kelmaganlarga «sog'indik» xabari (har biri bir kishiga BIR MARTA). Standart — o'chiq.
 *  • Yomon baho (1-2 ⭐) — sabab so'raladi, adminga «Yordam» bo'limiga tushadi, bir martalik BEPUL qayta yozish taklif qilinadi.
 * Hammasi admin panel → Sozlamalar → «🚀 O'sish» bo'limidan yoqiladi/o'chiriladi.
 */
class Growth
{
    public static function ensureSchema(PDO $pdo): void
    {
        $cols = [
            'users' => ['sub_until INTEGER DEFAULT 0', 'sub_credits INTEGER DEFAULT 0', 'agent_id INTEGER', 'pending_req TEXT', 'rewrite_used INTEGER DEFAULT 0',
                'source TEXT'],
            'jobs' => ['paid_by TEXT', 'requirements TEXT', 'uniq INTEGER', 'parent_id INTEGER', 'priority INTEGER DEFAULT 0', 'group_id INTEGER'],
        ];
        foreach ($cols as $t => $defs) {
            foreach ($defs as $d) {
                try {
                    $pdo->exec("ALTER TABLE $t ADD COLUMN $d");
                } catch (Throwable $e) {
                }
            }
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS agents (tg INTEGER PRIMARY KEY, name TEXT, percent INTEGER NOT NULL DEFAULT 10, earned INTEGER NOT NULL DEFAULT 0,
            paid INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1, created_at INTEGER)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS agent_ledger (job_id INTEGER PRIMARY KEY, agent INTEGER NOT NULL, client INTEGER NOT NULL, price INTEGER NOT NULL,
            amount INTEGER NOT NULL, ts INTEGER NOT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_agent_ledger ON agent_ledger (agent, ts)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS auto_sent (tg INTEGER NOT NULL, rule TEXT NOT NULL, ts INTEGER NOT NULL, PRIMARY KEY (tg, rule))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS sub_log (id INTEGER PRIMARY KEY AUTOINCREMENT, tg INTEGER NOT NULL, price INTEGER NOT NULL, credits INTEGER NOT NULL,
            until_ts INTEGER NOT NULL, ts INTEGER NOT NULL)');
        try {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_agent ON users (agent_id)');
        } catch (Throwable $e) {
        }
    }

    private static function money(int $n): string
    {
        return number_format($n, 0, '', ' ') . " so'm";
    }

    private static function kv(string $k): int
    {
        try {
            $st = db()->prepare('SELECT ts FROM engine_kv WHERE k = ?');
            $st->execute([$k]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function kvSet(string $k, string $v = '1'): void
    {
        db()->prepare('INSERT INTO engine_kv (k, v, ts) VALUES (?, ?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v, ts = excluded.ts')->execute([$k, $v, time()]);
    }

    // ================================================================== REKLAMA MANBALARI

    /** /start payload ichidan manba kodi: «src_ig», «ref123_src_tgkanal» … (faqat a-z, 0-9, _ ; 2-24 belgi). */
    public static function sourceFromPayload(string $payload): string
    {
        return preg_match('/(?:^|_)src_([a-z0-9]{2,24})/i', $payload, $m) ? strtolower($m[1]) : '';
    }

    /** Faqat birinchi marta (yangi foydalanuvchi, hali buyurtmasi yo'q) yoziladi — keyingi havolalar manbani o'zgartirmaydi. */
    public static function setSource(int $tg, string $src): void
    {
        if ($src === '') {
            return;
        }
        db()->prepare('UPDATE users SET source = ? WHERE telegram_id = ? AND (source IS NULL OR source = \'\')
            AND NOT EXISTS (SELECT 1 FROM jobs WHERE jobs.telegram_id = users.telegram_id)')->execute([$src, $tg]);
    }

    /** Panel: har manba bo'yicha foydalanuvchilar, buyurtma berganlar, to'laganlar, tushum. */
    public static function sourcesReport(int $days): array
    {
        $from = $days > 0 ? time() - $days * 86400 : 0;
        $rows = db()->prepare("SELECT COALESCE(NULLIF(u.source,''), CASE WHEN u.agent_id IS NOT NULL THEN 'hamkor' WHEN u.referred_by IS NOT NULL THEN 'referal' ELSE 'organik' END) AS src,
                COUNT(*) AS users,
                SUM(EXISTS (SELECT 1 FROM jobs j WHERE j.telegram_id = u.telegram_id)) AS ordered,
                SUM(EXISTS (SELECT 1 FROM topups t WHERE t.telegram_id = u.telegram_id AND t.status = 'approved')) AS paid,
                COALESCE(SUM((SELECT SUM(t.amount) FROM topups t WHERE t.telegram_id = u.telegram_id AND t.status = 'approved')), 0) AS revenue
            FROM users u WHERE u.created_at >= ? GROUP BY src ORDER BY users DESC");
        $rows->execute([$from]);
        $out = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['src' => (string)$r['src'], 'users' => (int)$r['users'], 'ordered' => (int)$r['ordered'], 'paid' => (int)$r['paid'], 'revenue' => (int)$r['revenue']];
        }
        return $out;
    }

    // ================================================================== OBUNA

    public static function subEnabled(): bool
    {
        return (bool)Engine::c('SUB_ENABLED', false);
    }

    public static function subPrice(): int
    {
        return max(1000, (int)Engine::c('SUB_PRICE', 49000));
    }

    public static function subCredits(): int
    {
        return max(1, (int)Engine::c('SUB_CREDITS', 10));
    }

    public static function subDays(): int
    {
        return max(1, (int)Engine::c('SUB_DAYS', 30));
    }

    /** Obuna bilan qoplanadigan eng qimmat buyurtma (undan qimmati — balansdan). */
    public static function subMaxPrice(): int
    {
        return max(0, (int)Engine::c('SUB_MAX_PRICE', 15000));
    }

    /** @return array{active:bool,until:int,credits:int} */
    public static function sub(int $tg): array
    {
        $st = db()->prepare('SELECT sub_until, sub_credits FROM users WHERE telegram_id = ?');
        $st->execute([$tg]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $until = (int)($r['sub_until'] ?? 0);
        $cr = (int)($r['sub_credits'] ?? 0);
        return ['active' => $until > time() && $cr > 0, 'until' => $until, 'credits' => $until > time() ? $cr : 0];
    }

    public static function subCovers(int $tg, int $price): bool
    {
        return self::subEnabled() && $price > 0 && $price <= self::subMaxPrice() && self::sub($tg)['active'];
    }

    /** Atomik: 1 kredit yechadi. */
    public static function subTake(int $tg): bool
    {
        $st = db()->prepare('UPDATE users SET sub_credits = sub_credits - 1 WHERE telegram_id = ? AND sub_credits > 0 AND sub_until > ?');
        $st->execute([$tg, time()]);
        return $st->rowCount() > 0;
    }

    public static function subGive(int $tg): void
    {
        db()->prepare('UPDATE users SET sub_credits = sub_credits + 1 WHERE telegram_id = ?')->execute([$tg]);
    }

    public static function subText(int $tg): string
    {
        $s = self::sub($tg);
        $t = "💎 <b>Talaba PRO obunasi</b>\n\n"
            . "• " . self::subDays() . " kun ichida <b>" . self::subCredits() . " ta</b> buyurtma (har biri " . self::money(self::subMaxPrice()) . "gacha bo'lgan tariflar)\n"
            . "• Narxi: <b>" . self::money(self::subPrice()) . "</b> — balansdan bir marta yechiladi\n"
            . "• Odatdagi narxda ≈ " . self::money(self::subCredits() * min(self::subMaxPrice(), 8000)) . " turadi\n"
            . "• Qimmatroq tariflar (kurs ishi va h.k.) odatdagidek balansdan to'lanadi\n\n";
        if ($s['active']) {
            $t .= "✅ Obunangiz faol: <b>" . $s['credits'] . " ta</b> buyurtma qoldi, " . date('d.m.Y', $s['until']) . " gacha.\n"
                . "Yana sotib olsangiz — kreditlar qo'shiladi va muddat uzayadi.";
        } else {
            $t .= "Hozir obunangiz yo'q.";
        }
        return $t;
    }

    public static function subKeyboard(): array
    {
        return ['inline_keyboard' => [[['text' => '💎 Sotib olish — ' . self::money(self::subPrice()), 'callback_data' => 'sub:buy']]]];
    }

    /** Balansdan obuna sotib olish. Qaytadi: mijozga xabar. */
    public static function subBuy(int $tg): string
    {
        if (!self::subEnabled()) {
            return "Obuna hozircha mavjud emas.";
        }
        $price = self::subPrice();
        $pdo = db();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $st = $pdo->prepare('UPDATE users SET balance = balance - ? WHERE telegram_id = ? AND balance >= ?');
            $st->execute([$price, $tg, $price]);
            if (!$st->rowCount()) {
                $pdo->exec('ROLLBACK');
                return "⚠️ Balansingiz yetarli emas (kerak: " . self::money($price) . "). Avval /balans orqali to'ldiring.";
            }
            $cur = self::sub($tg);
            $until = max(time(), $cur['until']) + self::subDays() * 86400;
            $credits = $cur['credits'] + self::subCredits();
            $pdo->prepare('UPDATE users SET sub_until = ?, sub_credits = ? WHERE telegram_id = ?')->execute([$until, $credits, $tg]);
            $pdo->prepare('INSERT INTO sub_log (tg, price, credits, until_ts, ts) VALUES (?,?,?,?,?)')->execute([$tg, $price, self::subCredits(), $until, time()]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable $e2) {
            }
            logError('growth', 'subBuy: ' . $e->getMessage());
            return "⚠️ Xatolik yuz berdi, pulingiz yechilmadi. Birozdan keyin urinib ko'ring.";
        }
        try {
            AdminSecurity::notifyAdmins("💎 Yangi obuna: ID <code>$tg</code> — " . self::money($price));
        } catch (Throwable $e) {
        }
        return "🎉 <b>Talaba PRO faollashtirildi!</b>\n" . $credits . " ta buyurtma, " . date('d.m.Y', $until) . " gacha.\n"
            . "Endi buyurtma berganingizda pul balansdan emas, obunadan yechiladi.";
    }

    // ================================================================== AGENTLAR

    public static function agentsEnabled(): bool
    {
        return (bool)Engine::c('AGENTS_ENABLED', true);
    }

    public static function agent(int $tg): ?array
    {
        $st = db()->prepare('SELECT * FROM agents WHERE tg = ?');
        $st->execute([$tg]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** /start agt<ID> — faqat hali buyurtma bermagan va agentga bog'lanmagan mijoz uchun. */
    public static function attachAgent(int $client, int $agentTg): void
    {
        if (!self::agentsEnabled() || $client === $agentTg) {
            return;
        }
        $a = self::agent($agentTg);
        if (!$a || !(int)$a['active']) {
            return;
        }
        db()->prepare('UPDATE users SET agent_id = ? WHERE telegram_id = ? AND agent_id IS NULL
            AND NOT EXISTS (SELECT 1 FROM jobs WHERE jobs.telegram_id = users.telegram_id)')->execute([$agentTg, $client]);
    }

    /** Buyurtma tayyor bo'lganda: agent ulushi (faqat balansdan to'langan pullik buyurtmalar, bir marta). */
    public static function onJobDone(array $job): void
    {
        try {
            $price = (int)($job['price'] ?? 0);
            if ($price <= 0 || (int)($job['is_free'] ?? 0) === 1 || (string)($job['paid_by'] ?? '') === 'sub' || !self::agentsEnabled()) {
                return;
            }
            $st = db()->prepare('SELECT agent_id FROM users WHERE telegram_id = ?');
            $st->execute([(int)$job['telegram_id']]);
            $agentTg = (int)$st->fetchColumn();
            $a = $agentTg ? self::agent($agentTg) : null;
            if (!$a || !(int)$a['active']) {
                return;
            }
            $amount = (int)floor($price * max(0, min(90, (int)$a['percent'])) / 100);
            if ($amount <= 0) {
                return;
            }
            $ins = db()->prepare('INSERT OR IGNORE INTO agent_ledger (job_id, agent, client, price, amount, ts) VALUES (?,?,?,?,?,?)');
            $ins->execute([(int)$job['id'], $agentTg, (int)$job['telegram_id'], $price, $amount, time()]);
            if ($ins->rowCount()) {
                db()->prepare('UPDATE agents SET earned = earned + ? WHERE tg = ?')->execute([$amount, $agentTg]);
                Telegram::sendMessage($agentTg, "🤝 Mijozingiz buyurtmasi tayyor bo'ldi — ulushingiz: <b>+" . self::money($amount) . "</b>\nBatafsil: /agent");
            }
        } catch (Throwable $e) {
            logError('growth', 'agent ulushi: ' . $e->getMessage());
        }
    }

    public static function agentText(int $tg, string $botUser): string
    {
        $a = self::agent($tg);
        if (!$a || !(int)$a['active']) {
            return "🤝 <b>Hamkorlik dasturi</b>\n\nGuruhingiz, kanalingiz yoki tanishlaringizga botni tavsiya qilib, ular qilgan HAR buyurtmadan foiz oling.\n"
                . "Hamkor bo'lish uchun «💬 Yordam» orqali administratorga yozing.";
        }
        $q = fn(string $sql) => (function () use ($sql, $tg) {
            $st = db()->prepare($sql);
            $st->execute([$tg]);
            return (int)$st->fetchColumn();
        })();
        $clients = $q('SELECT COUNT(*) FROM users WHERE agent_id = ?');
        $orders = $q('SELECT COUNT(*) FROM agent_ledger WHERE agent = ?');
        $month = $q('SELECT COALESCE(SUM(amount),0) FROM agent_ledger WHERE agent = ? AND ts > ' . (time() - 30 * 86400));
        $link = $botUser !== '' ? "https://t.me/$botUser?start=agt$tg" : "/start agt$tg";
        return "🤝 <b>Hamkor kabineti</b>\n\n"
            . "🔗 Havolangiz:\n<code>$link</code>\n\n"
            . "💰 Ulush: <b>" . (int)$a['percent'] . "%</b> — mijozlaringizning har bir pullik buyurtmasidan\n"
            . "👥 Mijozlar: <b>$clients</b>  ·  🧾 Buyurtmalar: <b>$orders</b>\n"
            . "📅 30 kunda: <b>" . self::money($month) . "</b>\n"
            . "💵 Jami ishlangan: <b>" . self::money((int)$a['earned']) . "</b>\n"
            . "✅ To'langan: " . self::money((int)$a['paid']) . "  ·  ⏳ Qoldiq: <b>" . self::money((int)$a['earned'] - (int)$a['paid']) . "</b>\n\n"
            . "Pul yechish uchun «💬 Yordam» orqali yozing.";
    }

    // --- panel

    public static function agentsList(): array
    {
        $rows = db()->query('SELECT a.*, u.username, u.first_name,
            (SELECT COUNT(*) FROM users x WHERE x.agent_id = a.tg) AS clients,
            (SELECT COUNT(*) FROM agent_ledger l WHERE l.agent = a.tg) AS orders,
            (SELECT COALESCE(SUM(price),0) FROM agent_ledger l WHERE l.agent = a.tg) AS sales
            FROM agents a LEFT JOIN users u ON u.telegram_id = a.tg ORDER BY a.earned DESC, a.created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => ['tg' => (int)$r['tg'], 'name' => (string)($r['name'] ?: ($r['first_name'] ?: ($r['username'] ? '@' . $r['username'] : ''))),
            'username' => (string)($r['username'] ?? ''), 'percent' => (int)$r['percent'], 'earned' => (int)$r['earned'], 'paid' => (int)$r['paid'],
            'due' => (int)$r['earned'] - (int)$r['paid'], 'active' => (bool)$r['active'], 'clients' => (int)$r['clients'], 'orders' => (int)$r['orders'],
            'sales' => (int)$r['sales'], 'created_at' => (int)$r['created_at']], $rows);
    }

    public static function agentSave(int $admin, int $tg, int $percent, string $name, bool $active): array
    {
        $u = db()->prepare('SELECT COUNT(*) FROM users WHERE telegram_id = ?');
        $u->execute([$tg]);
        if (!(int)$u->fetchColumn()) {
            return ['ok' => false, 'msg' => "Bu ID botda topilmadi — odam avval botga /start yozsin"];
        }
        $percent = max(1, min(90, $percent));
        db()->prepare('INSERT INTO agents (tg, name, percent, active, created_at) VALUES (?,?,?,?,?)
            ON CONFLICT(tg) DO UPDATE SET name = excluded.name, percent = excluded.percent, active = excluded.active')
            ->execute([$tg, mb_substr(trim($name), 0, 60), $percent, $active ? 1 : 0, time()]);
        AdminSecurity::audit('agent_save', $admin, (string)$tg, $percent . '%' . ($active ? '' : ' (o\'chiq)'));
        if ($active) {
            try {
                Telegram::sendMessage($tg, "🤝 Siz StudyBot hamkori bo'ldingiz! Ulushingiz: <b>$percent%</b>.\nHavolangiz va hisobot: /agent");
            } catch (Throwable $e) {
            }
        }
        return ['ok' => true, 'msg' => 'Saqlandi'];
    }

    /** Agentga pul to'landi (naqd/karta) — qoldiqdan ayiriladi. */
    public static function agentPayout(int $admin, int $tg, int $amount): array
    {
        $a = self::agent($tg);
        if (!$a) {
            return ['ok' => false, 'msg' => 'Agent topilmadi'];
        }
        $due = (int)$a['earned'] - (int)$a['paid'];
        if ($amount <= 0 || $amount > $due) {
            return ['ok' => false, 'msg' => "Summa noto'g'ri (qoldiq: " . self::money($due) . ')'];
        }
        db()->prepare('UPDATE agents SET paid = paid + ? WHERE tg = ?')->execute([$amount, $tg]);
        AdminSecurity::audit('agent_payout', $admin, (string)$tg, (string)$amount);
        try {
            Telegram::sendMessage($tg, "💵 Hamkorlik ulushingizdan " . self::money($amount) . " to'landi. Rahmat!");
        } catch (Throwable $e) {
        }
        return ['ok' => true, 'msg' => "To'lov qayd etildi"];
    }

    // ================================================================== YOMON BAHO → QAYTA YOZISH

    public static function rewriteEnabled(): bool
    {
        return (bool)Engine::c('FREE_REWRITE', true);
    }

    /** Bepul qayta yozish mumkinmi (har mijozga 30 kunda 1 marta, buyurtma 7 kundan eski bo'lmasin). */
    public static function canRewrite(int $tg, array $job): bool
    {
        if (!self::rewriteEnabled() || (int)$job['telegram_id'] !== $tg || $job['status'] !== 'done') {
            return false;
        }
        if ((int)($job['finished_at'] ?? $job['updated_at'] ?? 0) < time() - 7 * 86400 || !empty($job['parent_id'])) {
            return false;
        }
        $st = db()->prepare('SELECT rewrite_used FROM users WHERE telegram_id = ?');
        $st->execute([$tg]);
        return (int)$st->fetchColumn() < time() - 30 * 86400;
    }

    /** Qayta yozish: mijozning izohi «talab» sifatida qo'shiladi. */
    public static function rewrite(int $tg, int $jobId): string
    {
        $st = db()->prepare('SELECT * FROM jobs WHERE id = ?');
        $st->execute([$jobId]);
        $j = $st->fetch(PDO::FETCH_ASSOC);
        if (!$j || !self::canRewrite($tg, $j)) {
            return "Bepul qayta yozish bu buyurtma uchun mavjud emas (30 kunda bir marta, 7 kun ichida).";
        }
        $claim = db()->prepare('UPDATE users SET rewrite_used = ? WHERE telegram_id = ? AND COALESCE(rewrite_used, 0) < CAST(? AS INTEGER)');
        $claim->execute([time(), $tg, time() - 30 * 86400]);
        if (!$claim->rowCount()) {
            return "Bepul qayta yozish allaqachon ishlatilgan.";
        }
        $fb = '';
        try {
            $k = db()->prepare('SELECT v FROM engine_kv WHERE k = ?');
            $k->execute(['fb:' . $jobId]);
            $fb = (string)$k->fetchColumn();
        } catch (Throwable $e) {
        }
        $req = trim((string)($j['requirements'] ?? ''));
        $req = trim($req . ($fb !== '' ? "\n\nOldingi variant haqida mijoz izohi (shuni tuzating): " . $fb : ''));
        try {
            $student = json_decode((string)($j['student'] ?? ''), true);
            $newId = Queue::createJob($tg, (string)$j['type'], (string)$j['topic'], (string)$j['volume_key'], 0, false, true,
                $j['design_key'] ?: null, $j['lang'] ?: null, is_array($student) ? $student : null);
            db()->prepare('UPDATE jobs SET requirements = ?, parent_id = ?, paid_by = ? WHERE id = ?')->execute([$req !== '' ? mb_substr($req, 0, 4000) : null, $jobId, 'gift', $newId]);
        } catch (Throwable $e) {
            db()->prepare('UPDATE users SET rewrite_used = 0 WHERE telegram_id = ?')->execute([$tg]);
            logError('growth', 'rewrite: ' . $e->getMessage());
            return "⚠️ Buyurtma yaratilmadi, birozdan keyin urinib ko'ring.";
        }
        Engine::onJobPending($newId, "🔁 Bepul qayta yozish boshlandi" . ($fb !== '' ? " — izohingiz hisobga olinadi" : '') . '.');
        try {
            AdminSecurity::notifyAdmins("🔁 Past bahodan keyin bepul qayta yozish: #$jobId → #$newId (ID <code>$tg</code>)");
        } catch (Throwable $e) {
        }
        return '';
    }

    // ================================================================== AVTOMATIK MARKETING

    public static function marketingEnabled(): bool
    {
        return (bool)Engine::c('MARKETING_ENABLED', false);
    }

    /** Maintenance::run() dan chaqiriladi; soatiga bir marta, bir yurishda ko'pi bilan 25 ta xabar. */
    public static function marketingRun(float $until): void
    {
        if (!self::marketingEnabled() || time() - self::kv('mkt_run') < 3600) {
            return;
        }
        self::kvSet('mkt_run');
        $h = (int)gmdate('G', time() + (defined('ADMIN_TZ_OFFSET') ? (int)ADMIN_TZ_OFFSET : 18000));
        if ($h < 9 || $h > 21) {
            return;                                   // tunda bezovta qilmaymiz
        }
        $now = time();
        $rules = [
            // 1) ro'yxatdan o'tgan, 1-3 kun ichida buyurtma bermagan, bepul urinishi bor
            'nojob' => ["SELECT u.telegram_id FROM users u WHERE u.created_at BETWEEN " . ($now - 3 * 86400) . " AND " . ($now - 86400)
                . " AND COALESCE(u.free_used,0) = 0 AND COALESCE(u.banned,0) = 0 AND NOT EXISTS (SELECT 1 FROM jobs j WHERE j.telegram_id = u.telegram_id)",
                "👋 Salom! Birinchi buyurtmangiz <b>bepul</b> — hali ishlatmadingiz.\n\nMavzuni yozing, 3-5 daqiqada tayyor taqdimot yoki mustaqil ish olasiz 👇"],
            // 2) birinchi buyurtma 2-5 kun oldin tayyor bo'lgan, boshqa buyurtma yo'q
            'cross' => ["SELECT j.telegram_id FROM jobs j JOIN users u ON u.telegram_id = j.telegram_id WHERE j.status = 'done' AND COALESCE(u.banned,0) = 0
                GROUP BY j.telegram_id HAVING COUNT(*) = 1 AND MAX(j.finished_at) BETWEEN " . ($now - 5 * 86400) . " AND " . ($now - 2 * 86400),
                "📚 Bilasizmi? Botda nafaqat taqdimot, balki <b>mustaqil ish, referat, kurs ishi, maqola va tezis</b> ham bor.\n"
                . "Har biriga himoya savollari va haqiqiy adabiyotlar ro'yxati qo'shib beriladi. /start"],
            // 3) 30-60 kun hech narsa buyurtma qilmagan (avval kamida 1 ta tayyor buyurtmasi bor)
            'winback' => ["SELECT j.telegram_id FROM jobs j JOIN users u ON u.telegram_id = j.telegram_id WHERE COALESCE(u.banned,0) = 0
                GROUP BY j.telegram_id HAVING SUM(j.status = 'done') >= 1 AND MAX(j.created_at) BETWEEN " . ($now - 60 * 86400) . " AND " . ($now - 30 * 86400),
                "🙂 Sizni sog'indik! Yangi semestr topshiriqlari bormi? Biz yangilandik: yangi dizaynlar, himoya savollari, haqiqiy manbalar.\n/start"],
        ];
        $sent = 0;
        foreach ($rules as $rule => [$sql, $text]) {
            try {
                $ids = db()->query($sql . ' LIMIT 60')->fetchAll(PDO::FETCH_COLUMN);
            } catch (Throwable $e) {
                logError('growth', "marketing $rule: " . $e->getMessage());
                continue;
            }
            foreach ($ids as $tg) {
                if ($sent >= 25 || microtime(true) > $until - 3) {
                    return;
                }
                $ins = db()->prepare('INSERT OR IGNORE INTO auto_sent (tg, rule, ts) VALUES (?, ?, ?)');
                $ins->execute([(int)$tg, $rule, $now]);
                if (!$ins->rowCount()) {
                    continue;                        // bu odamga bu xabar allaqachon yuborilgan
                }
                try {
                    Telegram::sendMessage((int)$tg, $text);
                } catch (Throwable $e) {
                }
                $sent++;
                usleep(60000);
            }
        }
    }

    /** Panel uchun: marketing statistikasi. */
    public static function marketingStats(): array
    {
        $out = [];
        foreach (db()->query('SELECT rule, COUNT(*) n, MAX(ts) last FROM auto_sent GROUP BY rule')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $conv = db()->prepare('SELECT COUNT(DISTINCT a.tg) FROM auto_sent a JOIN jobs j ON j.telegram_id = a.tg AND j.created_at > a.ts AND j.created_at < a.ts + 7 * 86400 WHERE a.rule = ?');
            $conv->execute([$r['rule']]);
            $out[] = ['rule' => $r['rule'], 'sent' => (int)$r['n'], 'last' => (int)$r['last'], 'conv' => (int)$conv->fetchColumn()];
        }
        $subs = db()->query('SELECT COUNT(*) c, COALESCE(SUM(price),0) s FROM sub_log WHERE ts > ' . (time() - 30 * 86400))->fetch(PDO::FETCH_ASSOC);
        $active = (int)db()->query('SELECT COUNT(*) FROM users WHERE sub_until > ' . time() . ' AND sub_credits > 0')->fetchColumn();
        return ['rules' => $out, 'subs30' => (int)$subs['c'], 'subs30_sum' => (int)$subs['s'], 'subs_active' => $active];
    }
}
