<?php

/**
 * Botni GitHub'dan YANGILASH (faqat admin, Telegram'da /yangilash).
 *  - joriy kod + config.php + baza nusxasi storage/backup_*.zip ga olinadi;
 *  - yangi kod yuklab olinadi va ustidan yoziladi; config.php va storage/ ga tegilmaydi;
 *  - oxirgi o'rnatilgan versiya (commit) engine_kv da saqlanadi — «yangilik bormi?» shu bilan tekshiriladi.
 * Manba: config.php → UPDATE_REPO ('davauzb/Botphp'), UPDATE_BRANCH. O'chirish: define('UPDATER_ENABLED', false);
 */
class Updater
{
    private const KEEP = ['config.php', 'storage', 'ornatish.php'];

    public static function enabled(): bool
    {
        return (bool)(defined('UPDATER_ENABLED') ? UPDATER_ENABLED : true) && class_exists('ZipArchive') && function_exists('curl_init');
    }

    private static function repo(): string
    {
        return defined('UPDATE_REPO') ? (string)UPDATE_REPO : 'davauzb/Botphp';
    }

    private static function branch(): string
    {
        return defined('UPDATE_BRANCH') ? (string)UPDATE_BRANCH : 'claude/webapp-design-functionality-vzk5o1';
    }

    private static function get(string $url, int $timeout = 20): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'StudyBot-Updater', CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json']]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, is_string($body) ? $body : ''];
    }

    /** Oxirgi commit: ['sha','date','msg'] yoki null. */
    public static function latest(): ?array
    {
        [$code, $body] = self::get('https://api.github.com/repos/' . self::repo() . '/commits/' . rawurlencode(self::branch()));
        $d = $code === 200 ? json_decode($body, true) : null;
        if (!is_array($d) || empty($d['sha'])) {
            return null;
        }
        return ['sha' => substr((string)$d['sha'], 0, 7), 'date' => (string)($d['commit']['committer']['date'] ?? ''),
            'msg' => strtok((string)($d['commit']['message'] ?? ''), "\n") ?: ''];
    }

    public static function current(): string
    {
        $st = db()->prepare('SELECT v FROM engine_kv WHERE k = ?');
        $st->execute(['installed_sha']);
        return (string)($st->fetchColumn() ?: '');
    }

    /** Yangilashni bajaradi. Qaytadi: [ok(bool), xabar]. */
    public static function run(): array
    {
        @set_time_limit(300);
        $dir = dirname(__DIR__);
        $latest = self::latest();
        [$code, $data] = self::get('https://codeload.github.com/' . self::repo() . '/zip/refs/heads/' . self::branch(), 120);
        if ($code !== 200 || strlen($data) < 100000) {
            return [false, "GitHub'dan yuklab bo'lmadi (HTTP $code)."];
        }
        $tmp = $dir . '/storage/_upd_' . bin2hex(random_bytes(4)) . '.zip';
        if (file_put_contents($tmp, $data) === false) {
            return [false, "storage/ ga yozib bo'lmadi."];
        }
        unset($data);
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            return [false, 'Arxiv buzilgan.'];
        }
        $prefix = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (preg_match('#^([^/]+/bot/)webhook\.php$#', (string)$zip->getNameIndex($i), $m)) {
                $prefix = $m[1];
                break;
            }
        }
        if ($prefix === null) {
            $zip->close();
            @unlink($tmp);
            return [false, 'Arxivda bot papkasi topilmadi.'];
        }
        // zaxira (kod + config + baza)
        $backup = $dir . '/storage/backup_' . date('Ymd_His') . '.zip';
        $bz = new ZipArchive();
        if ($bz->open($backup, ZipArchive::CREATE) !== true) {
            $zip->close();
            @unlink($tmp);
            return [false, "Zaxira olinmadi — yangilash to'xtatildi."];
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
            if (str_starts_with($rel, 'storage/') && !preg_match('#^storage/[^/]+\.(sqlite|sqlite3|db)$#', $rel)) {
                continue;
            }
            $bz->addFile($f->getPathname(), $rel);
        }
        $bz->close();
        // yozish
        $n = 0;
        $err = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (!str_starts_with($name, $prefix) || str_ends_with($name, '/')) {
                continue;
            }
            $rel = substr($name, strlen($prefix));
            if ($rel === '' || str_contains($rel, '..') || in_array(explode('/', $rel)[0], self::KEEP, true)) {
                continue;
            }
            $target = $dir . '/' . $rel;
            if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true)) {
                $err++;
                continue;
            }
            $c = $zip->getFromIndex($i);
            if ($c === false || file_put_contents($target, $c, LOCK_EX) === false) {
                $err++;
                continue;
            }
            $n++;
        }
        $zip->close();
        @unlink($tmp);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        if ($latest) {
            db()->prepare('INSERT INTO engine_kv (k, v, ts) VALUES (?, ?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v, ts = excluded.ts')
                ->execute(['installed_sha', $latest['sha'], time()]);
        }
        return [$err === 0, ($err === 0 ? '✅' : '⚠️') . " Yangilandi: $n ta fayl" . ($err ? ", $err tasida xato" : '') . ($latest ? " (versiya {$latest['sha']})" : '')
            . ".\n🗄 Zaxira: storage/" . basename($backup) . "\nconfig.php va baza o'zgarmadi."];
    }
}
