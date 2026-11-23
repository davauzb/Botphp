<?php

/**
 * Fon xizmatlari (cron kerak emas — Engine tick va webhook oxirida chaqiriladi):
 *
 *  1) RASSILKA — admin paneldan yaratilgan xabar segmentdagi har bir mijozga navbat bilan yuboriladi
 *     (Telegram limitiga mos ~15 xabar/soniya, har mijoz atomik "band qilinadi" — ikki parallel jarayon bir odamga ikki marta yubormaydi).
 *  2) TOZALASH (soatiga 1 marta) — eski generatsiya fayllari, katta loglar (aylantiriladi), eski o'rnatuvchi zaxiralari.
 *  3) BAZA ZAXIRASI (kuniga 1 marta, Toshkent vaqti 03:00 dan keyin) — studybot.sqlite nusxasi zip qilinib
 *     ADMIN_CHAT_ID ga Telegram orqali yuboriladi va storage/backups/ da oxirgi 3 tasi saqlanadi.
 *
 * Sozlamalar (ixtiyoriy, config.php): DB_BACKUP_DAILY (true), DB_BACKUP_HOUR (3), BROADCAST_RATE (15).
 */
class Maintenance
{
    public const SEGMENTS = [
        'all' => 'Hamma mijozlar',
        'active7' => 'Oxirgi 7 kunda faol',
        'active30' => 'Oxirgi 30 kunda faol',
        'buyers' => 'Kamida 1 marta buyurtma berganlar',
        'nobuy' => 'Hali buyurtma bermaganlar',
        'balance' => 'Balansida puli borlar',
    ];

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS broadcasts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, admin INTEGER NOT NULL, text TEXT NOT NULL, btn_text TEXT, btn_url TEXT,
            segment TEXT NOT NULL, total INTEGER NOT NULL DEFAULT 0, sent INTEGER NOT NULL DEFAULT 0, failed INTEGER NOT NULL DEFAULT 0,
            blocked INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT \'running\', created_at INTEGER NOT NULL, finished_at INTEGER)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS broadcast_targets (bid INTEGER NOT NULL, tg INTEGER NOT NULL, st INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (bid, tg))');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_bt_pending ON broadcast_targets (bid, st)');
    }

    // ================================================================== ishga tushirish

    /** $until — shu vaqtgacha (unix float) ishlash mumkin. */
    public static function run(float $until): void
    {
        try {
            self::periodic();
        } catch (Throwable $e) {
            logError('maint', 'periodic: ' . $e->getMessage());
        }
        try {
            self::broadcastStep($until);
        } catch (Throwable $e) {
            logError('maint', 'broadcast: ' . $e->getMessage());
        }
        try {
            if (class_exists('Userbot') && class_exists('HumoAuto')) {
                Userbot::poll($until);                 // 👤 HUMO xabarlarini userbot orqali o'qish
            }
        } catch (Throwable $e) {
            logError('maint', 'userbot: ' . $e->getMessage());
        }
        try {
            if (class_exists('PromoDays')) {
                PromoDays::run();                      // 🎉 aksiya kunlari: bir martalik xabar
            }
        } catch (Throwable $e) {
            logError('maint', 'promo: ' . $e->getMessage());
        }
        try {
            if (class_exists('Growth')) {
                Growth::marketingRun($until);
            }
        } catch (Throwable $e) {
            logError('maint', 'marketing: ' . $e->getMessage());
        }
    }

    /** Yuborilishi kerak bo'lgan rassilka bormi (tick zanjirini davom ettirish uchun). */
    public static function pending(): bool
    {
        try {
            return (bool)db()->query("SELECT 1 FROM broadcasts WHERE status = 'running' LIMIT 1")->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    // ================================================================== rassilka

    private static function segmentSql(string $seg): string
    {
        $now = time();
        $base = 'SELECT telegram_id FROM users WHERE COALESCE(banned,0) = 0';
        return match ($seg) {
            'active7' => $base . ' AND last_request_at >= ' . ($now - 7 * 86400),
            'active30' => $base . ' AND last_request_at >= ' . ($now - 30 * 86400),
            'buyers' => $base . " AND telegram_id IN (SELECT telegram_id FROM jobs WHERE status = 'done')",
            'nobuy' => $base . ' AND telegram_id NOT IN (SELECT telegram_id FROM jobs)',
            'balance' => $base . ' AND balance > 0',
            default => $base,
        };
    }

    public static function countSegment(string $seg): int
    {
        if (!isset(self::SEGMENTS[$seg])) {
            return 0;
        }
        return (int)db()->query('SELECT COUNT(*) FROM (' . self::segmentSql($seg) . ')')->fetchColumn();
    }

    /** Admin yozgan oddiy matn → xavfsiz HTML. **qalin** va __kursiv__ qo'llab-quvvatlanadi. */
    public static function format(string $text): string
    {
        $t = htmlspecialchars(trim($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $t = preg_replace('/\*\*(.+?)\*\*/su', '<b>$1</b>', $t) ?? $t;
        return preg_replace('/__(.+?)__/su', '<i>$1</i>', $t) ?? $t;
    }

    private static function keyboard(?string $btnText, ?string $btnUrl): ?array
    {
        $btnText = trim((string)$btnText);
        $btnUrl = trim((string)$btnUrl);
        if ($btnText === '' || !preg_match('#^https://[^\s]{3,300}$#i', $btnUrl)) {
            return null;
        }
        return ['inline_keyboard' => [[['text' => mb_substr($btnText, 0, 40), 'url' => $btnUrl]]]];
    }

    /** Bitta xabar. Qaytadi: 'ok' | 'blocked' (botni bloklagan/o'chirgan) | 'fail'. */
    private static function sendOne(int $tg, string $html, ?array $kb): string
    {
        $p = ['chat_id' => $tg, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true'];
        if ($kb) {
            $p['reply_markup'] = json_encode($kb);
        }
        $r = Telegram::call('sendMessage', $p);
        if (!empty($r['ok'])) {
            return 'ok';
        }
        $code = (int)($r['error_code'] ?? 0);
        $desc = (string)($r['description'] ?? '');
        if ($code === 403 || stripos($desc, 'chat not found') !== false || stripos($desc, 'user is deactivated') !== false) {
            return 'blocked';
        }
        return 'fail';
    }

    /** Sinov: xabarni faqat adminning o'ziga yuboradi. */
    public static function test(int $admin, string $text, ?string $btnText, ?string $btnUrl): array
    {
        if (trim($text) === '') {
            return ['ok' => false, 'error' => 'empty', 'msg' => 'Matnni yozing'];
        }
        $r = self::sendOne($admin, "🧪 <i>Sinov (faqat sizga)</i>\n\n" . self::format($text), self::keyboard($btnText, $btnUrl));
        return $r === 'ok' ? ['ok' => true, 'msg' => 'Sinov xabari Telegram\'ingizga yuborildi'] : ['ok' => false, 'error' => 'send', 'msg' => 'Yuborib bo\'lmadi (matnni tekshiring)'];
    }

    public static function create(int $admin, string $text, ?string $btnText, ?string $btnUrl, string $seg): array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 3500) {
            return ['ok' => false, 'error' => 'text', 'msg' => 'Matn 1–3500 belgi bo\'lsin'];
        }
        if (!isset(self::SEGMENTS[$seg])) {
            return ['ok' => false, 'error' => 'segment', 'msg' => 'Segment noto\'g\'ri'];
        }
        if (trim((string)$btnText) !== '' && !self::keyboard($btnText, $btnUrl)) {
            return ['ok' => false, 'error' => 'btn', 'msg' => 'Tugma havolasi https:// bilan boshlanishi kerak'];
        }
        $pdo = db();
        if ((int)$pdo->query("SELECT COUNT(*) FROM broadcasts WHERE status = 'running'")->fetchColumn() > 0) {
            return ['ok' => false, 'error' => 'busy', 'msg' => 'Oldingi rassilka hali tugamagan — tugashini kuting yoki to\'xtating'];
        }
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $pdo->prepare('INSERT INTO broadcasts (admin, text, btn_text, btn_url, segment, created_at) VALUES (?,?,?,?,?,?)')
                ->execute([$admin, $text, trim((string)$btnText) ?: null, trim((string)$btnUrl) ?: null, $seg, time()]);
            $bid = (int)$pdo->lastInsertId();
            $pdo->exec('INSERT OR IGNORE INTO broadcast_targets (bid, tg) SELECT ' . $bid . ', telegram_id FROM (' . self::segmentSql($seg) . ')');
            $total = (int)$pdo->query('SELECT COUNT(*) FROM broadcast_targets WHERE bid = ' . $bid)->fetchColumn();
            $pdo->prepare('UPDATE broadcasts SET total = ?, status = ? WHERE id = ?')->execute([$total, $total > 0 ? 'running' : 'done', $bid]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
        if (class_exists('Engine')) {
            try {
                Engine::kick(0);          // yuborish darhol boshlansin
            } catch (Throwable $e) {
            }
        }
        return ['ok' => true, 'id' => $bid, 'total' => $total, 'msg' => $total . ' ta mijozga yuborish boshlandi'];
    }

    public static function cancel(int $bid): array
    {
        $st = db()->prepare("UPDATE broadcasts SET status = 'cancelled', finished_at = ? WHERE id = ? AND status = 'running'");
        $st->execute([time(), $bid]);
        return $st->rowCount() ? ['ok' => true, 'msg' => 'To\'xtatildi'] : ['ok' => false, 'error' => 'state', 'msg' => 'Bu rassilka allaqachon tugagan'];
    }

    public static function list(int $offset): array
    {
        $st = db()->prepare('SELECT id, admin, text, btn_text, btn_url, segment, total, sent, failed, blocked, status, created_at, finished_at FROM broadcasts ORDER BY id DESC LIMIT 21 OFFSET ?');
        $st->execute([$offset]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > 20;
        $items = [];
        foreach (array_slice($rows, 0, 20) as $r) {
            foreach (['id', 'admin', 'total', 'sent', 'failed', 'blocked', 'created_at', 'finished_at'] as $k) {
                $r[$k] = (int)$r[$k];
            }
            $r['segment_label'] = self::SEGMENTS[$r['segment']] ?? $r['segment'];
            $items[] = $r;
        }
        return ['items' => $items, 'more' => $more, 'segments' => self::SEGMENTS];
    }

    private static function broadcastStep(float $until): void
    {
        $pdo = db();
        $b = $pdo->query("SELECT * FROM broadcasts WHERE status = 'running' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$b) {
            return;
        }
        $bid = (int)$b['id'];
        $html = self::format((string)$b['text']);
        $kb = self::keyboard($b['btn_text'], $b['btn_url']);
        $rate = max(1, min(25, (int)Engine::c('BROADCAST_RATE', 15)));
        $gap = (int)(1000000 / $rate);
        $claim = $pdo->prepare('UPDATE broadcast_targets SET st = 9 WHERE bid = ? AND tg = ? AND st = 0');
        $done = $pdo->prepare('UPDATE broadcast_targets SET st = ? WHERE bid = ? AND tg = ?');
        while (microtime(true) < $until - 1) {
            $status = $pdo->query('SELECT status FROM broadcasts WHERE id = ' . $bid)->fetchColumn();
            if ($status !== 'running') {
                return;                                       // admin to'xtatgan
            }
            $ids = $pdo->query('SELECT tg FROM broadcast_targets WHERE bid = ' . $bid . ' AND st = 0 LIMIT 20')->fetchAll(PDO::FETCH_COLUMN);
            if (!$ids) {
                // 9 (band qilingan, lekin jarayon yiqilgan) qolgan bo'lsa — 10 daqiqadan keyin qayta yuborilmaydi, muvaffaqiyatsiz deb yopiladi
                $pdo->prepare("UPDATE broadcasts SET status = 'done', finished_at = ?, failed = failed + (SELECT COUNT(*) FROM broadcast_targets WHERE bid = ? AND st = 9) WHERE id = ? AND status = 'running'")
                    ->execute([time(), $bid, $bid]);
                self::reportDone($bid);
                return;
            }
            foreach ($ids as $tg) {
                if (microtime(true) >= $until - 1) {
                    return;
                }
                $claim->execute([$bid, $tg]);
                if ($claim->rowCount() === 0) {
                    continue;                                 // boshqa jarayon oldi
                }
                $t0 = microtime(true);
                $r = self::sendOne((int)$tg, $html, $kb);
                $code = $r === 'ok' ? 1 : ($r === 'blocked' ? 2 : 3);
                $done->execute([$code, $bid, $tg]);
                $col = $r === 'ok' ? 'sent' : ($r === 'blocked' ? 'blocked' : 'failed');
                $pdo->exec("UPDATE broadcasts SET $col = $col + 1 WHERE id = $bid");
                $left = $gap - (int)((microtime(true) - $t0) * 1000000);
                if ($left > 0) {
                    usleep($left);
                }
            }
        }
    }

    private static function reportDone(int $bid): void
    {
        $b = db()->query('SELECT * FROM broadcasts WHERE id = ' . $bid)->fetch(PDO::FETCH_ASSOC);
        if (!$b) {
            return;
        }
        try {
            Telegram::sendMessage((int)$b['admin'], "📣 Rassilka #{$bid} tugadi.\n✅ Yetkazildi: {$b['sent']}\n🚫 Botni bloklagan: {$b['blocked']}\n⚠️ Xato: {$b['failed']}\nJami: {$b['total']}");
        } catch (Throwable $e) {
        }
    }

    // ================================================================== davriy ishlar

    private static function due(string $key, int $every): bool
    {
        $st = db()->prepare('SELECT ts FROM engine_kv WHERE k = ?');
        $st->execute([$key]);
        $ts = (int)$st->fetchColumn();
        if ($ts && time() - $ts < $every) {
            return false;
        }
        // atomik: faqat bitta jarayon "oladi"
        $up = db()->prepare('INSERT INTO engine_kv (k, v, ts) VALUES (?, ?, ?) ON CONFLICT(k) DO UPDATE SET ts = excluded.ts WHERE engine_kv.ts = ?');
        $up->execute([$key, '1', time(), $ts]);
        return $up->rowCount() > 0;
    }

    private static function periodic(): void
    {
        if (self::due('mt_hourly', 3600)) {
            self::cleanup();
        }
        $hour = (int)gmdate('G', time() + (defined('ADMIN_TZ_OFFSET') ? ADMIN_TZ_OFFSET : 18000));
        if ((bool)Engine::c('DB_BACKUP_DAILY', true) && $hour >= (int)Engine::c('DB_BACKUP_HOUR', 3) && self::due('mt_backup', 20 * 3600)) {
            self::backup(true);
        }
    }

    /** Eski fayllar va katta loglarni tozalaydi. */
    public static function cleanup(): array
    {
        $now = time();
        $removed = 0;
        $freed = 0;
        foreach (glob(rtrim(GENERATED_DIR, '/') . '/*') ?: [] as $f) {
            if (is_file($f) && $now - (int)filemtime($f) > 6 * 3600) {
                $freed += (int)filesize($f);
                @unlink($f) && $removed++;
            }
        }
        foreach (glob(LOG_DIR . '*.log') ?: [] as $f) {        // 5 MB dan katta log — aylantiriladi (bitta eski nusxa qoladi)
            if (is_file($f) && filesize($f) > 5 * 1048576) {
                @rename($f, $f . '.1');
            }
        }
        $storage = dirname(DB_PATH);
        foreach (['/backup_*.zip' => 3, '/backups/db_*.zip' => 3] as $pat => $keep) {
            $files = glob($storage . $pat) ?: [];
            usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
            foreach (array_slice($files, $keep) as $f) {
                $freed += (int)filesize($f);
                @unlink($f) && $removed++;
            }
        }
        $keepDays = max(1, (int)Engine::c('EDITOR_RETENTION_DAYS', 30));
        $imgDir = rtrim(defined('EDITOR_STORAGE_DIR') ? EDITOR_STORAGE_DIR : $storage . '/editor/', '/') . '/docimg/';
        foreach (glob($imgDir . '*') ?: [] as $f) {                 // eski hujjat rasmlari
            if (is_file($f) && $now - (int)filemtime($f) > $keepDays * 86400) {
                $freed += (int)filesize($f);
                @unlink($f) && $removed++;
            }
        }
        foreach (glob($storage . '/_upd_*.zip') ?: [] as $f) {     // o'rnatuvchidan qolgan vaqtinchalik fayl
            if ($now - (int)filemtime($f) > 3600) {
                @unlink($f);
            }
        }
        return ['removed' => $removed, 'freed' => $freed];
    }

    /** Baza zaxirasi: zip yo'li (Telegram'ga yuborilsa — adminga ham). */
    public static function backup(bool $send): ?string
    {
        $dir = dirname(DB_PATH) . '/backups';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return null;
        }
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        $copy = $dir . '/tmp_' . bin2hex(random_bytes(4)) . '.sqlite';
        $ok = false;
        try {
            db()->exec("VACUUM INTO '" . str_replace("'", "''", $copy) . "'");     // izchil nusxa (SQLite 3.27+)
            $ok = is_file($copy);
        } catch (Throwable $e) {
            try {
                db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            } catch (Throwable $e2) {
            }
            $ok = @copy(DB_PATH, $copy);
        }
        if (!$ok) {
            logError('maint', 'Baza nusxasi olinmadi');
            return null;
        }
        $zipPath = $dir . '/db_' . gmdate('Ymd_His', time() + 18000) . '.zip';
        $z = new ZipArchive();
        if ($z->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($copy);
            return null;
        }
        $z->addFile($copy, 'studybot.sqlite');
        $z->close();
        @unlink($copy);
        if ($send && defined('ADMIN_CHAT_ID') && (int)ADMIN_CHAT_ID > 0 && filesize($zipPath) < 48 * 1048576) {
            $users = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $bal = (int)db()->query('SELECT COALESCE(SUM(balance),0) FROM users')->fetchColumn();
            Telegram::sendDocument((int)ADMIN_CHAT_ID, $zipPath, "🗄 Kunlik baza zaxirasi\n👥 Mijozlar: {$users} · 💰 Balanslar: " . number_format($bal, 0, '', ' ')
                . " so'm\n\nTiklash: zipdagi studybot.sqlite ni storage/ papkasiga qo'ying.");
        }
        return $zipPath;
    }
}
