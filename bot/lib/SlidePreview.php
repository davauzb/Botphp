<?php

require_once __DIR__ . '/SlidePdf.php';

/**
 * Taqdimot OLDINDAN KO'RINISHI: tanlangan 3-4 slayd rasmi Telegram'da albom bo'lib (fayldan oldin) yuboriladi —
 * mijoz faylni ochmasdan natijani ko'radi.
 *
 *  - LibreOffice bo'lsa: har slayd alohida (bitta slaydli) PPTX nusxaga ajratiladi va BITTA soffice chaqiruvida hammasi PNG ga aylanadi
 *    (soffice faqat 1-slaydni eksport qiladi — shuning uchun shu usul). Oddiy va PRO dizaynlarning ikkalasida ishlaydi.
 *  - LibreOffice yo'q bo'lsa: oddiy dizaynlar tahrirlovchi modeli orqali GD bilan chiziladi (PRO uchun preview yuborilmaydi).
 * O'chirish: config.php → define('SLIDE_PREVIEW', false);
 */
class SlidePreview
{
    public static function enabled(): bool
    {
        return (bool)Engine::c('SLIDE_PREVIEW', true);
    }

    /** Ko'rsatiladigan slaydlar: muqova + taqdimot bo'ylab tekis 3 ta (reja va «Rahmat» dan tashqari). */
    public static function pick(int $total, int $n = 4): array
    {
        if ($total <= 0) {
            return [];
        }
        $idx = [0];
        $body = range(2, max(2, $total - 2));
        $body = array_values(array_filter($body, fn($i) => $i < $total - 1));
        for ($k = 1; $k < $n && $body; $k++) {
            $i = $body[(int)floor(($k - 0.5) * count($body) / ($n - 1))] ?? null;
            if ($i !== null && !in_array($i, $idx, true)) {
                $idx[] = $i;
            }
        }
        return $idx;
    }

    /** Qaytadi: rasm fayllari yo'llari (bo'sh — preview yo'q). */
    public static function make(string $pptx, array $indexes, ?string $editorPid = null, float $budget = 40): array
    {
        if (!self::enabled() || !is_file($pptx) || !$indexes) {
            return [];
        }
        try {
            if (SlidePdf::sofficePath()) {
                $r = self::viaSoffice($pptx, $indexes, $budget);
                if ($r) {
                    return $r;
                }
            }
            if ($editorPid !== null && SlidePdf::gdReady()) {
                $out = [];
                foreach (SlidePdf::slideJpegs($editorPid, $indexes) as $jpg) {
                    $f = rtrim(GENERATED_DIR, '/') . '/pv_' . bin2hex(random_bytes(5)) . '.jpg';
                    if (@file_put_contents($f, $jpg)) {
                        $out[] = $f;
                    }
                }
                return $out;
            }
        } catch (Throwable $e) {
            logError('preview', $e->getMessage());
        }
        return [];
    }

    private static function viaSoffice(string $pptx, array $indexes, float $budget): array
    {
        $work = rtrim(GENERATED_DIR, '/') . '/pv_' . bin2hex(random_bytes(5));
        if (!@mkdir($work, 0700, true)) {
            return [];
        }
        $files = [];
        foreach ($indexes as $k => $i) {
            $copy = $work . '/s' . $k . '.pptx';
            if (self::singleSlideCopy($pptx, (int)$i, $copy)) {
                $files[$k] = $copy;
            }
        }
        if (!$files) {
            self::rmdir($work);
            return [];
        }
        $soffice = SlidePdf::sofficePath();
        $cmd = escapeshellarg($soffice) . ' -env:UserInstallation=' . escapeshellarg('file://' . $work . '/profile')
            . ' --headless --norestore --nolockcheck --convert-to png --outdir ' . escapeshellarg($work);
        foreach ($files as $f) {
            $cmd .= ' ' . escapeshellarg($f);
        }
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $work, ['HOME' => $work, 'PATH' => '/usr/bin:/bin:/usr/local/bin']);
        if (!is_resource($proc)) {
            self::rmdir($work);
            return [];
        }
        $deadline = microtime(true) + max(10, $budget);
        while (microtime(true) < $deadline) {
            $st = proc_get_status($proc);
            if (!$st['running']) {
                break;
            }
            usleep(200000);
        }
        if (proc_get_status($proc)['running']) {
            @proc_terminate($proc, 9);
        }
        foreach ($pipes as $pp) {
            @fclose($pp);
        }
        @proc_close($proc);
        $out = [];
        foreach ($files as $k => $f) {
            $png = $work . '/s' . $k . '.png';
            if (is_file($png) && filesize($png) > 2000) {
                $dst = rtrim(GENERATED_DIR, '/') . '/pv_' . bin2hex(random_bytes(5)) . '.png';
                if (@rename($png, $dst)) {
                    $out[] = $dst;
                }
            }
        }
        self::rmdir($work);
        return $out;
    }

    /** Faqat $index-chi slayd qolgan PPTX nusxa (boshqa slaydlar ro'yxatdan olib tashlanadi, fayllari qoladi — LibreOffice ularni o'qimaydi). */
    private static function singleSlideCopy(string $src, int $index, string $dst): bool
    {
        if (!@copy($src, $dst)) {
            return false;
        }
        $z = new ZipArchive();
        if ($z->open($dst) !== true) {
            return false;
        }
        $xml = (string)$z->getFromName('ppt/presentation.xml');
        if (!preg_match('#<p:sldIdLst>(.*?)</p:sldIdLst>#s', $xml, $m) || !preg_match_all('#<p:sldId\b[^>]*/>#', $m[1], $ids) || !isset($ids[0][$index])) {
            $z->close();
            return false;
        }
        $xml = str_replace($m[0], '<p:sldIdLst>' . $ids[0][$index] . '</p:sldIdLst>', $xml);
        $z->addFromString('ppt/presentation.xml', $xml);
        return $z->close();
    }

    private static function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /** Albomni yuboradi (2-10 ta rasm). */
    public static function send(int $chatId, array $paths, string $caption): bool
    {
        $paths = array_values(array_filter($paths, 'is_file'));
        if (count($paths) < 2) {
            return $paths ? Telegram::sendPhoto($chatId, $paths[0], $caption) : false;
        }
        $media = [];
        $files = [];
        foreach (array_slice($paths, 0, 10) as $k => $p) {
            $media[] = ['type' => 'photo', 'media' => 'attach://p' . $k] + ($k === 0 ? ['caption' => $caption, 'parse_mode' => 'HTML'] : []);
            $files['p' . $k] = $p;
        }
        $r = Telegram::call('sendMediaGroup', ['chat_id' => $chatId, 'media' => json_encode($media, JSON_UNESCAPED_UNICODE)], $files);
        return !empty($r['ok']);
    }
}
