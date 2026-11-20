<?php

/**
 * TAQDIMOTNI PDF QILISH.
 *
 * Ikki usul (config.php → PDF_ENGINE: 'auto' | 'gd' | 'soffice'):
 *   1) LibreOffice (soffice) — serverda o'rnatilgan va exec() ochiq bo'lsa: tayyor PPTX ni PDF ga aylantiradi (eng aniq, matn tanlanadi).
 *   2) GD — hech narsa o'rnatish shart emas (arzon hosting): slayd elementlari (shakl, matn, rasm, chiziq, jadval) PHP'ning o'zida
 *      chiziladi (joylashtirilgan Carlito/Liberation shriftlari bilan), har bir slayd rasm sifatida PDF betiga qo'yiladi.
 *      Matn bu holatda tanlanmaydi (rasm), lekin ko'rinishi tahrirlovchidagi bilan deyarli bir xil.
 * 'auto' — avval LibreOffice, bo'lmasa GD.
 */
class SlidePdf
{
    private const BASE_W = 1280;      // bitta slayd chiqish kengligi (piksel)
    private const SS = 2;             // supersampling (silliq chiziqlar uchun 2x katta chizib, kichraytiriladi)
    private const JPEG_Q = 90;
    private const W = 9144000;        // PptxElements::W / H (EMU)
    private const H = 6858000;

    private static float $k = 0.0;    // piksel / EMU (supersampled)
    private static float $pt = 0.0;   // piksel / pt (supersampled)
    private static array $wc = [];

    public static function fontDir(): string
    {
        return dirname(__DIR__) . '/assets/fonts/';
    }

    public static function gdReady(): bool
    {
        return function_exists('imagettftext') && function_exists('imagecreatetruecolor') && function_exists('imagejpeg')
            && function_exists('imagerotate') && is_file(self::fontDir() . 'Carlito-Regular.ttf');
    }

    public static function sofficePath(): ?string
    {
        static $cache = false;
        if ($cache !== false) {
            return $cache;
        }
        $cache = null;
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (!function_exists('proc_open') || in_array('proc_open', $disabled, true) || PHP_OS_FAMILY === 'Windows') {
            return null;
        }
        foreach (['/usr/bin/soffice', '/usr/bin/libreoffice', '/usr/local/bin/soffice', '/opt/libreoffice/program/soffice',
            '/usr/lib/libreoffice/program/soffice', '/snap/bin/libreoffice'] as $p) {
            if (is_file($p) && is_executable($p)) {
                return $cache = $p;
            }
        }
        return null;
    }

    public static function engine(): string
    {
        $e = defined('PDF_ENGINE') ? (string)PDF_ENGINE : 'auto';
        if ($e === 'soffice') {
            return self::sofficePath() ? 'soffice' : (self::gdReady() ? 'gd' : 'none');
        }
        if ($e === 'gd') {
            return self::gdReady() ? 'gd' : 'none';
        }
        return self::sofficePath() ? 'soffice' : (self::gdReady() ? 'gd' : 'none');
    }

    public static function available(): bool
    {
        return self::engine() !== 'none';
    }

    /** Saqlangan taqdimotdan PDF yaratadi. Qaytadi: [fayl yo'li | null, usul yoki xato]. */
    public static function fromPresentation(string $pid): array
    {
        @set_time_limit(240);
        @ini_set('memory_limit', '256M');
        $p = EditorStore::get($pid);
        if (!$p) {
            return [null, 'not_found'];
        }
        $imgs = EditorStore::imageMap($pid);
        $ids = EditorStore::imageIds($pid);
        $slides = [];
        foreach ($p['slides'] as $s) {
            $c = PptxElements::sanitizeSlide($s, $ids);
            if ($c) {
                $slides[] = $c;
            }
        }
        if (!$slides) {
            return [null, 'empty'];
        }
        $engine = self::engine();
        if ($engine === 'soffice') {
            [$path, $errors] = EditorStore::exportFile($pid);
            if ($path) {
                $pdf = self::viaSoffice($path);
                @unlink($path);
                if ($pdf) {
                    return [$pdf, 'soffice'];
                }
                logError('pdf', 'LibreOffice PDF yaratolmadi — GD ga o\'tildi');
            }
            if (!self::gdReady()) {
                return [null, 'soffice_failed'];
            }
        } elseif ($engine === 'none') {
            return [null, 'no_engine'];
        }
        try {
            $pdf = self::viaGd($slides, $imgs, (string)$p['title']);
        } catch (Throwable $e) {
            logError('pdf', 'GD PDF xato: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
            return [null, 'render_failed'];
        }
        return [$pdf, 'gd'];
    }

    // ============================================================== LibreOffice

    /** Istalgan ofis fayli (pptx/docx) → PDF (LibreOffice orqali). Qaytadi: PDF yo'li yoki null. */
    public static function convertToPdf(string $file): ?string
    {
        return self::viaSoffice($file);
    }

    private static function viaSoffice(string $pptx): ?string
    {
        $soffice = self::sofficePath();
        if (!$soffice) {
            return null;
        }
        $work = rtrim(GENERATED_DIR, '/') . '/lo_' . bin2hex(random_bytes(5));
        if (!@mkdir($work, 0700, true)) {
            return null;
        }
        $cmd = escapeshellarg($soffice) . ' -env:UserInstallation=' . escapeshellarg('file://' . $work . '/profile')
            . ' --headless --norestore --nolockcheck --convert-to pdf --outdir ' . escapeshellarg($work) . ' ' . escapeshellarg($pptx);
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $work, ['HOME' => $work, 'PATH' => '/usr/bin:/bin:/usr/local/bin']);
        $out = null;
        if (is_resource($proc)) {
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $deadline = microtime(true) + 90;
            while (microtime(true) < $deadline) {
                $st = proc_get_status($proc);
                if (!$st['running']) {
                    break;
                }
                usleep(200000);
            }
            $st = proc_get_status($proc);
            if ($st['running']) {
                @proc_terminate($proc, 9);
            }
            foreach ($pipes as $pp) {
                @fclose($pp);
            }
            @proc_close($proc);
            $gen = $work . '/' . pathinfo($pptx, PATHINFO_FILENAME) . '.pdf';
            if (is_file($gen) && filesize($gen) > 1000 && strncmp((string)file_get_contents($gen, false, null, 0, 5), '%PDF-', 5) === 0) {
                $out = rtrim(GENERATED_DIR, '/') . '/pdf_' . bin2hex(random_bytes(6)) . '.pdf';
                if (!@rename($gen, $out)) {
                    $out = null;
                }
            }
        }
        self::rrmdir($work);
        return $out;
    }

    private static function rrmdir(string $d): void
    {
        if (!is_dir($d)) {
            return;
        }
        foreach (scandir($d) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $d . '/' . $f;
            is_dir($p) && !is_link($p) ? self::rrmdir($p) : @unlink($p);
        }
        @rmdir($d);
    }

    // ============================================================== GD → PDF

    /** Tanlangan slaydlar JPEG baytlari (Telegram preview uchun, LibreOffice bo'lmaganda). */
    public static function slideJpegs(string $pid, array $indexes): array
    {
        $p = EditorStore::get($pid);
        if (!$p || !self::gdReady()) {
            return [];
        }
        $imgs = EditorStore::imageMap($pid);
        $ids = EditorStore::imageIds($pid);
        self::$k = (self::BASE_W * self::SS) / self::W;
        self::$pt = (self::BASE_W * self::SS) / 10.0 / 72.0;
        self::$wc = [];
        $out = [];
        foreach ($indexes as $i) {
            $s = $p['slides'][$i] ?? null;
            $c = $s ? PptxElements::sanitizeSlide($s, $ids) : null;
            if ($c) {
                $out[] = self::renderSlide($c, $imgs);
            }
        }
        return $out;
    }

    private static function viaGd(array $slides, array $imgs, string $title): string
    {
        self::$k = (self::BASE_W * self::SS) / self::W;
        self::$pt = (self::BASE_W * self::SS) / 10.0 / 72.0;     // slayd kengligi 10 dyuym
        self::$wc = [];
        $pages = [];
        foreach ($slides as $s) {
            $pages[] = self::renderSlide($s, $imgs);
        }
        $path = rtrim(GENERATED_DIR, '/') . '/pdf_' . bin2hex(random_bytes(6)) . '.pdf';
        if (!self::writePdf($path, $pages, $title)) {
            throw new RuntimeException('PDF faylni yozib bo\'lmadi');
        }
        return $path;
    }

    /** JPEG baytlari (BASE_W x BASE_H). */
    private static function renderSlide(array $slide, array $imgs): string
    {
        $cw = self::BASE_W * self::SS;
        $ch = (int)round(self::H * self::$k);
        $img = imagecreatetruecolor($cw, $ch);
        imagealphablending($img, true);
        imagefilledrectangle($img, 0, 0, $cw, $ch, imagecolorallocate($img, 255, 255, 255));
        foreach ($slide['els'] as $e) {
            if (!empty($e['hid'])) {
                continue;
            }
            try {
                self::drawElement($img, $e, $imgs);
            } catch (Throwable $ex) {
                logError('pdf', 'element chizilmadi (' . ($e['t'] ?? '?') . '): ' . $ex->getMessage());
            }
        }
        $out = imagecreatetruecolor(self::BASE_W, (int)round($ch / self::SS));
        imagecopyresampled($out, $img, 0, 0, 0, 0, self::BASE_W, (int)round($ch / self::SS), $cw, $ch);
        imagedestroy($img);
        ob_start();
        imageinterlace($out, false);
        imagejpeg($out, null, self::JPEG_Q);
        $jpg = (string)ob_get_clean();
        imagedestroy($out);
        return $jpg;
    }

    private static function writePdf(string $path, array $jpegs, string $title): bool
    {
        $n = count($jpegs);
        $wpt = 720;
        $hpt = 540;
        $objs = [];                         // id => body (string) — stream'lar alohida
        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $kids = [];
        for ($i = 0; $i < $n; $i++) {
            $kids[] = (3 + $i * 3) . ' 0 R';
        }
        $objs[2] = '<< /Type /Pages /Count ' . $n . ' /Kids [' . implode(' ', $kids) . '] >>';
        $streams = [];
        $info = '';
        $t16 = function_exists('mb_convert_encoding') ? mb_convert_encoding($title, 'UTF-16BE', 'UTF-8') : '';
        $infoId = 3 + $n * 3;
        if ($t16 !== '') {
            $info = '<< /Title <FEFF' . strtoupper(bin2hex($t16)) . '> /Producer (StudyBot) >>';
        }
        $dim = [];
        foreach ($jpegs as $i => $jpg) {
            $sz = @getimagesizefromstring($jpg);
            $dim[$i] = $sz ? [$sz[0], $sz[1]] : [self::BASE_W, 960];
            $pid = 3 + $i * 3;
            $objs[$pid] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $wpt . ' ' . $hpt . '] /Resources << /XObject << /Im0 ' . ($pid + 2) . ' 0 R >> >> /Contents ' . ($pid + 1) . ' 0 R >>';
            $content = "q $wpt 0 0 $hpt 0 0 cm /Im0 Do Q";
            $streams[$pid + 1] = ['<< /Length ' . strlen($content) . ' >>', $content];
            $streams[$pid + 2] = ['<< /Type /XObject /Subtype /Image /Width ' . $dim[$i][0] . ' /Height ' . $dim[$i][1]
                . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($jpg) . ' >>', $jpg];
        }
        if ($info !== '') {
            $objs[$infoId] = $info;
        }
        $fh = @fopen($path, 'wb');
        if (!$fh) {
            return false;
        }
        $offsets = [];
        $pos = 0;
        $w = function (string $s) use ($fh, &$pos) {
            fwrite($fh, $s);
            $pos += strlen($s);
        };
        $w("%PDF-1.4\n%\xE2\xE3\xCF\xD3\n");
        $maxId = $info !== '' ? $infoId : 2 + $n * 3;
        for ($id = 1; $id <= $maxId; $id++) {
            $offsets[$id] = $pos;
            if (isset($objs[$id])) {
                $w($id . " 0 obj\n" . $objs[$id] . "\nendobj\n");
            } elseif (isset($streams[$id])) {
                $w($id . " 0 obj\n" . $streams[$id][0] . "\nstream\n");
                $w($streams[$id][1]);
                $w("\nendstream\nendobj\n");
            }
        }
        $xref = $pos;
        $w("xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n");
        for ($id = 1; $id <= $maxId; $id++) {
            $w(sprintf("%010d 00000 n \n", $offsets[$id]));
        }
        $w("trailer\n<< /Size " . ($maxId + 1) . ' /Root 1 0 R' . ($info !== '' ? ' /Info ' . $infoId . ' 0 R' : '') . " >>\nstartxref\n$xref\n%%EOF\n");
        fclose($fh);
        return is_file($path) && filesize($path) > 500;
    }

    // ============================================================== chizish: umumiy yordamchilar

    private static function col($img, string $hex, int $alphaPct = 100): int
    {
        $hex = strlen($hex) === 6 ? $hex : '000000';
        $a = (int)round(127 * (1 - max(0, min(100, $alphaPct)) / 100));
        return imagecolorallocatealpha($img, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), $a);
    }

    private static function fillPoly($img, array $pts, int $color): void
    {
        if (count($pts) < 6) {
            return;
        }
        $p = array_map(fn($v) => (int)round($v), $pts);
        if (PHP_VERSION_ID >= 80100) {
            imagefilledpolygon($img, $p, $color);
        } else {
            imagefilledpolygon($img, $p, intdiv(count($p), 2), $color);
        }
    }

    /** Qalin chiziq (to'rtburchak sifatida). */
    private static function thickLine($img, float $x1, float $y1, float $x2, float $y2, float $w, int $color): void
    {
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $len = sqrt($dx * $dx + $dy * $dy);
        if ($len < 0.01) {
            return;
        }
        $nx = -$dy / $len * $w / 2;
        $ny = $dx / $len * $w / 2;
        self::fillPoly($img, [$x1 + $nx, $y1 + $ny, $x2 + $nx, $y2 + $ny, $x2 - $nx, $y2 - $ny, $x1 - $nx, $y1 - $ny], $color);
    }

    private static function dashPattern(string $dash, float $w): array
    {
        switch ($dash) {
            case 'dash':
                return [4 * $w, 3 * $w];
            case 'sysDot':
                return [1 * $w, 1 * $w];
            case 'dashDot':
                return [4 * $w, 2 * $w, 1 * $w, 2 * $w];
            case 'lgDash':
                return [8 * $w, 3 * $w];
            default:
                return [];
        }
    }

    private static function strokeSegment($img, float $x1, float $y1, float $x2, float $y2, float $w, int $color, string $dash): void
    {
        $pat = self::dashPattern($dash, max($w, 2.0));
        if (!$pat) {
            self::thickLine($img, $x1, $y1, $x2, $y2, $w, $color);
            return;
        }
        $len = sqrt(($x2 - $x1) ** 2 + ($y2 - $y1) ** 2);
        if ($len < 0.01) {
            return;
        }
        $ux = ($x2 - $x1) / $len;
        $uy = ($y2 - $y1) / $len;
        $d = 0.0;
        $i = 0;
        while ($d < $len) {
            $seg = $pat[$i % count($pat)];
            $e = min($len, $d + $seg);
            if ($i % 2 === 0) {
                self::thickLine($img, $x1 + $ux * $d, $y1 + $uy * $d, $x1 + $ux * $e, $y1 + $uy * $e, $w, $color);
            }
            $d = $e;
            $i++;
        }
    }

    private static function strokePoly($img, array $pts, float $w, int $color, string $dash = 'solid', bool $closed = true): void
    {
        $n = intdiv(count($pts), 2);
        $round = $n <= 24;
        for ($i = 0; $i < $n - ($closed ? 0 : 1); $i++) {
            $j = ($i + 1) % $n;
            self::strokeSegment($img, $pts[$i * 2], $pts[$i * 2 + 1], $pts[$j * 2], $pts[$j * 2 + 1], $w, $color, $dash);
            if ($round && $dash === 'solid' && $w > 2.5) {
                imagefilledellipse($img, (int)round($pts[$j * 2]), (int)round($pts[$j * 2 + 1]), (int)round($w), (int)round($w), $color);
            }
        }
    }

    // ============================================================== geometriya

    /** Geometriya nuqtalari (tekis massiv, piksel). */
    private static function geomPoints(string $g, float $x, float $y, float $w, float $h, array $adj): array
    {
        $ss = min($w, $h);
        $cx = $x + $w / 2;
        $cy = $y + $h / 2;
        $a = fn(string $k, float $def) => (float)($adj[$k] ?? $def) / 100000.0;
        switch ($g) {
            case 'roundRect':
                return self::roundRectPts($x, $y, $w, $h, [$a('adj', 16667) * $ss, $a('adj', 16667) * $ss, $a('adj', 16667) * $ss, $a('adj', 16667) * $ss]);
            case 'round2SameRect':
                $r1 = $a('adj1', 16667) * $ss;
                $r2 = $a('adj2', 0) * $ss;
                return self::roundRectPts($x, $y, $w, $h, [$r1, $r1, $r2, $r2]);
            case 'ellipse':
                return self::ellipsePts($cx, $cy, $w / 2, $h / 2);
            case 'triangle':
                return [$x + $w * $a('adj', 50000), $y, $x + $w, $y + $h, $x, $y + $h];
            case 'rtTriangle':
                return [$x, $y, $x, $y + $h, $x + $w, $y + $h];
            case 'diamond':
                return [$cx, $y, $x + $w, $cy, $cx, $y + $h, $x, $cy];
            case 'parallelogram':
                $o = $a('adj', 25000) * $ss;
                return [$x + $o, $y, $x + $w, $y, $x + $w - $o, $y + $h, $x, $y + $h];
            case 'trapezoid':
                $o = $a('adj', 25000) * $ss;
                return [$x + $o, $y, $x + $w - $o, $y, $x + $w, $y + $h, $x, $y + $h];
            case 'hexagon':
                $o = $a('adj', 25000) * $ss;
                return [$x, $cy, $x + $o, $y, $x + $w - $o, $y, $x + $w, $cy, $x + $w - $o, $y + $h, $x + $o, $y + $h];
            case 'octagon':
                $o = $a('adj', 29289) * $ss;
                return [$x, $y + $o, $x + $o, $y, $x + $w - $o, $y, $x + $w, $y + $o, $x + $w, $y + $h - $o, $x + $w - $o, $y + $h, $x + $o, $y + $h, $x, $y + $h - $o];
            case 'star5':
                $inner = max(0.1, min(1.0, $a('adj', 19098) * 2));
                $pts = [];
                for ($i = 0; $i < 10; $i++) {
                    $ang = -M_PI / 2 + $i * M_PI / 5;
                    $rr = $i % 2 === 0 ? 1.0 : $inner;
                    $pts[] = $cx + cos($ang) * $rr * $w / 2;
                    $pts[] = $cy + sin($ang) * $rr * $h / 2;
                }
                return $pts;
            case 'plus':
                $o = $a('adj', 25000) * $ss;
                return [$x + $o, $y, $x + $w - $o, $y, $x + $w - $o, $y + $o, $x + $w, $y + $o, $x + $w, $y + $h - $o, $x + $w - $o, $y + $h - $o,
                    $x + $w - $o, $y + $h, $x + $o, $y + $h, $x + $o, $y + $h - $o, $x, $y + $h - $o, $x, $y + $o, $x + $o, $y + $o];
            case 'rightArrow':
            case 'leftArrow':
                $sh = $h * $a('adj1', 50000) / 2;
                $hd = min($w, $a('adj2', 50000) * $ss);
                $pts = [$x, $cy - $sh, $x + $w - $hd, $cy - $sh, $x + $w - $hd, $y, $x + $w, $cy, $x + $w - $hd, $y + $h, $x + $w - $hd, $cy + $sh, $x, $cy + $sh];
                if ($g === 'leftArrow') {
                    for ($i = 0; $i < count($pts); $i += 2) {
                        $pts[$i] = 2 * $cx - $pts[$i];
                    }
                }
                return $pts;
            case 'upArrow':
            case 'downArrow':
                $sh = $w * $a('adj1', 50000) / 2;
                $hd = min($h, $a('adj2', 50000) * $ss);
                $pts = [$cx - $sh, $y + $h, $cx - $sh, $y + $hd, $x, $y + $hd, $cx, $y, $x + $w, $y + $hd, $cx + $sh, $y + $hd, $cx + $sh, $y + $h];
                if ($g === 'downArrow') {
                    for ($i = 1; $i < count($pts); $i += 2) {
                        $pts[$i] = 2 * $cy - $pts[$i];
                    }
                }
                return $pts;
            case 'chevron':
                $o = $a('adj', 50000) * $ss;
                return [$x, $y, $x + $w - $o, $y, $x + $w, $cy, $x + $w - $o, $y + $h, $x, $y + $h, $x + $o, $cy];
            case 'homePlate':
                $o = $a('adj', 50000) * $ss;
                return [$x, $y, $x + $w - $o, $y, $x + $w, $cy, $x + $w - $o, $y + $h, $x, $y + $h];
            case 'snip1Rect':
                $o = $a('adj', 16667) * $ss;
                return [$x, $y, $x + $w - $o, $y, $x + $w, $y + $o, $x + $w, $y + $h, $x, $y + $h];
            case 'snip2SameRect':
                $d1 = $a('adj1', 16667) * $ss;
                $d2 = $a('adj2', 0) * $ss;
                return [$x + $d1, $y, $x + $w - $d1, $y, $x + $w, $y + $d1, $x + $w, $y + $h - $d2, $x + $w - $d2, $y + $h, $x + $d2, $y + $h, $x, $y + $h - $d2, $x, $y + $d1];
            case 'snip2DiagRect':
                $d1 = $a('adj1', 0) * $ss;
                $d2 = $a('adj2', 16667) * $ss;
                return [$x + $d1, $y, $x + $w - $d2, $y, $x + $w, $y + $d2, $x + $w, $y + $h - $d1, $x + $w - $d1, $y + $h, $x + $d2, $y + $h, $x, $y + $h - $d2, $x, $y + $d1];
            case 'foldedCorner':
                $o = $a('adj', 16667) * $ss;
                return [$x, $y, $x + $w, $y, $x + $w, $y + $h - $o, $x + $w - $o, $y + $h, $x, $y + $h];
            case 'flowChartOffpageConnector':
                return [$x, $y, $x + $w, $y, $x + $w, $y + $h * 0.8, $cx, $y + $h, $x, $y + $h * 0.8];
            case 'wave':
                $amp = $h * $a('adj1', 12500);
                $pts = [];
                for ($i = 0; $i <= 24; $i++) {
                    $pts[] = $x + $w * $i / 24;
                    $pts[] = $y + $amp + sin($i / 24 * 2 * M_PI) * $amp;
                }
                for ($i = 24; $i >= 0; $i--) {
                    $pts[] = $x + $w * $i / 24;
                    $pts[] = $y + $h - $amp + sin($i / 24 * 2 * M_PI) * $amp;
                }
                return $pts;
            case 'donut':
                $outer = self::ellipsePts($cx, $cy, $w / 2, $h / 2);
                $t = $a('adj', 25000) * $ss;
                $inner = self::ellipsePts($cx, $cy, max(1, $w / 2 - $t), max(1, $h / 2 - $t));
                $pts = $outer;
                $pts[] = $outer[0];
                $pts[] = $outer[1];
                $m = intdiv(count($inner), 2);
                $pts[] = $inner[0];
                $pts[] = $inner[1];
                for ($i = $m - 1; $i >= 1; $i--) {
                    $pts[] = $inner[$i * 2];
                    $pts[] = $inner[$i * 2 + 1];
                }
                $pts[] = $inner[0];
                $pts[] = $inner[1];
                return $pts;
            default:   // rect
                return [$x, $y, $x + $w, $y, $x + $w, $y + $h, $x, $y + $h];
        }
    }

    private static function ellipsePts(float $cx, float $cy, float $rx, float $ry): array
    {
        $n = (int)max(40, min(180, ($rx + $ry) / 6));
        $pts = [];
        for ($i = 0; $i < $n; $i++) {
            $a = 2 * M_PI * $i / $n;
            $pts[] = $cx + cos($a) * $rx;
            $pts[] = $cy + sin($a) * $ry;
        }
        return $pts;
    }

    /** radii = [tl, tr, br, bl] */
    private static function roundRectPts(float $x, float $y, float $w, float $h, array $r): array
    {
        $lim = min($w, $h) / 2;
        $r = array_map(fn($v) => max(0.0, min($lim, $v)), $r);
        $pts = [];
        $corner = function (float $cx, float $cy, float $rad, float $a0) use (&$pts) {
            if ($rad < 1) {
                $pts[] = $cx;
                $pts[] = $cy;
                return;
            }
            $steps = max(6, (int)min(24, $rad / 4));
            for ($i = 0; $i <= $steps; $i++) {
                $a = $a0 + ($i / $steps) * M_PI / 2;
                $pts[] = $cx + cos($a) * $rad;
                $pts[] = $cy + sin($a) * $rad;
            }
        };
        $corner($x + $r[0], $y + $r[0], $r[0], M_PI);                  // tl
        $corner($x + $w - $r[1], $y + $r[1], $r[1], 1.5 * M_PI);       // tr
        $corner($x + $w - $r[2], $y + $h - $r[2], $r[2], 0);           // br
        $corner($x + $r[3], $y + $h - $r[3], $r[3], 0.5 * M_PI);       // bl
        // r=0 burchaklar uchun haqiqiy burchak nuqtasi kerak
        if ($r[0] < 1) {
            $pts[0] = $x;
            $pts[1] = $y;
        }
        return $pts;
    }

    private static function transformPts(array $pts, float $cx, float $cy, float $rot, bool $fh, bool $fv): array
    {
        if (!$fh && !$fv && abs(fmod($rot, 360)) < 0.01) {
            return $pts;
        }
        $rad = deg2rad($rot);
        $cs = cos($rad);
        $sn = sin($rad);
        for ($i = 0; $i < count($pts); $i += 2) {
            $px = $pts[$i];
            $py = $pts[$i + 1];
            if ($fh) {
                $px = 2 * $cx - $px;
            }
            if ($fv) {
                $py = 2 * $cy - $py;
            }
            $dx = $px - $cx;
            $dy = $py - $cy;
            $pts[$i] = $cx + $dx * $cs - $dy * $sn;
            $pts[$i + 1] = $cy + $dx * $sn + $dy * $cs;
        }
        return $pts;
    }

    /** Qavariq shakllar uchun qatorlar bo'yicha [x0,x1] (layer koordinatalarida) — gradient/rasm maskasi. Qaytadi: null = qo'llab bo'lmaydi. */
    private static function spanFn(string $g, float $w, float $h, array $adj): ?callable
    {
        if ($g === 'rect' || $g === 'foldedCorner') {
            return fn(int $row) => [0, (int)$w - 1];
        }
        if ($g === 'ellipse') {
            return function (int $row) use ($w, $h) {
                $t = ($row + 0.5 - $h / 2) / max(0.5, $h / 2);
                $half = ($w / 2) * sqrt(max(0.0, 1 - $t * $t));
                return [(int)round($w / 2 - $half), (int)round($w / 2 + $half) - 1];
            };
        }
        if ($g === 'roundRect') {
            $r = min($w, $h) / 2;
            $r = min($r, ((float)($adj['adj'] ?? 16667) / 100000.0) * min($w, $h));
            return function (int $row) use ($w, $h, $r) {
                $d = 0.0;
                if ($row + 0.5 < $r) {
                    $d = $r - sqrt(max(0.0, $r * $r - ($r - $row - 0.5) ** 2));
                } elseif ($row + 0.5 > $h - $r) {
                    $d = $r - sqrt(max(0.0, $r * $r - ($row + 0.5 - ($h - $r)) ** 2));
                }
                return [(int)round($d), (int)round($w - $d) - 1];
            };
        }
        return null;
    }

    // ============================================================== elementlar

    private static function drawElement($img, array $e, array $imgs): void
    {
        $k = self::$k;
        $x = $e['x'] * $k;
        $y = $e['y'] * $k;
        $w = $e['w'] * $k;
        $h = $e['h'] * $k;
        $t = $e['t'];
        if ($t === 'line') {
            self::drawLine($img, $e);
            return;
        }
        if ($t === 'table') {
            self::drawTable($img, $e, $x, $y);
            return;
        }
        if ($w < 1 || $h < 1) {
            return;
        }
        $g = (string)($e['g'] ?? 'rect');
        $adj = (array)($e['adj'] ?? []);
        $rot = (float)($e['r'] ?? 0);
        $fh = !empty($e['fh']);
        $fv = !empty($e['fv']);
        $cx = $x + $w / 2;
        $cy = $y + $h / 2;
        $pts = self::transformPts(self::geomPoints($g, $x, $y, $w, $h, $adj), $cx, $cy, $rot, $fh, $fv);

        // soya
        $sh = $e['sh'] ?? null;
        if (is_array($sh)) {
            $ang = deg2rad((float)$sh['ang']);
            $d = (float)$sh['d'] * $k;
            $off = [];
            for ($i = 0; $i < count($pts); $i += 2) {
                $off[] = $pts[$i] + cos($ang) * $d;
                $off[] = $pts[$i + 1] + sin($ang) * $d;
            }
            $blur = max(0.0, (float)$sh['b'] * $k);
            $layers = $blur > 3 ? 4 : 1;
            for ($l = $layers; $l >= 1; $l--) {
                $grow = $blur * 0.5 * ($l - 1) / max(1, $layers - 1);
                $col = self::col($img, (string)$sh['c'], (int)max(4, round((int)$sh['a'] * 0.9 / $layers)));
                self::fillPoly($img, $grow > 0 ? self::growPts($off, $grow) : $off, $col);
            }
        }

        if ($t === 'image') {
            self::drawImage($img, $e, $imgs, $x, $y, $w, $h, $pts);
            return;
        }

        // to'ldirish
        $fill = $e['fill'] ?? null;
        if (is_array($fill)) {
            if (isset($fill['g'])) {
                $done = false;
                $span = (abs(fmod($rot, 360)) < 0.01 && !$fh && !$fv) ? self::spanFn($g, $w, $h, $adj) : null;
                if ($span) {
                    $done = self::gradientFill($img, (int)round($x), (int)round($y), (int)round($w), (int)round($h), $fill['g'], (int)$fill['ang'], $span);
                }
                if (!$done) {
                    $mid = self::mixHex($fill['g'][0], $fill['g'][1], 0.5);
                    self::fillPoly($img, $pts, self::col($img, $mid, 100));
                }
            } else {
                self::fillPoly($img, $pts, self::col($img, (string)$fill['c'], (int)($fill['a'] ?? 100)));
            }
        }
        // chiziq (kontur)
        $ln = $e['ln'] ?? null;
        if (is_array($ln) && $ln['w'] > 0) {
            self::strokePoly($img, $pts, max(1.0, $ln['w'] * $k), self::col($img, (string)$ln['c'], (int)$ln['a']), (string)$ln['dash'], true);
        }
        // matn
        if (!empty($e['tx']) && is_array($e['tx'])) {
            $tx = $e['tx'];
            $draw = function ($target, float $ox, float $oy) use ($tx, $w, $h) {
                self::drawText($target, $tx, $ox, $oy, $w, $h);
            };
            if (abs(fmod($rot, 360)) < 0.01) {
                $draw($img, $x, $y);
            } else {
                self::drawRotatedLayer($img, $cx, $cy, $w, $h, $rot, $draw);
            }
        }
    }

    private static function growPts(array $pts, float $g): array
    {
        $n = intdiv(count($pts), 2);
        $cx = array_sum(array_filter($pts, fn($k) => $k % 2 === 0, ARRAY_FILTER_USE_KEY)) / $n;
        $cy = array_sum(array_filter($pts, fn($k) => $k % 2 === 1, ARRAY_FILTER_USE_KEY)) / $n;
        for ($i = 0; $i < count($pts); $i += 2) {
            $dx = $pts[$i] - $cx;
            $dy = $pts[$i + 1] - $cy;
            $len = max(0.001, sqrt($dx * $dx + $dy * $dy));
            $pts[$i] += $dx / $len * $g;
            $pts[$i + 1] += $dy / $len * $g;
        }
        return $pts;
    }

    private static function mixHex(string $a, string $b, float $t): string
    {
        $o = '';
        foreach ([0, 2, 4] as $i) {
            $o .= sprintf('%02X', (int)round(hexdec(substr($a, $i, 2)) * (1 - $t) + hexdec(substr($b, $i, 2)) * $t));
        }
        return $o;
    }

    /** Chiziqli gradient: qatlamga chizib, qatorlar bo'yicha (span) asosiy rasmga ko'chiradi. */
    private static function gradientFill($img, int $x, int $y, int $w, int $h, array $stops, int $angDeg, callable $span): bool
    {
        if ($w < 2 || $h < 2 || $w * $h > 20000000) {
            return false;
        }
        $layer = imagecreatetruecolor($w, $h);
        $a = deg2rad($angDeg);
        $dx = cos($a);
        $dy = sin($a);
        $extent = abs($dx) * $w + abs($dy) * $h;
        $steps = 96;
        $cx = $w / 2;
        $cy = $h / 2;
        $big = ($w + $h) * 2;
        for ($i = 0; $i < $steps; $i++) {
            $t = $i / ($steps - 1);
            $hex = self::mixHex($stops[0], $stops[1], $t);
            $c = imagecolorallocate($layer, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
            $s = ($i / $steps - 0.5) * $extent;       // chiziq holati yo'nalish bo'ylab
            $px = $cx + $dx * $s;
            $py = $cy + $dy * $s;
            $nx = -$dy;
            $ny = $dx;
            // yarim tekislik: s dan keyingi hamma narsa
            self::fillPoly($layer, [
                $px + $nx * $big, $py + $ny * $big,
                $px - $nx * $big, $py - $ny * $big,
                $px - $nx * $big + $dx * $big, $py - $ny * $big + $dy * $big,
                $px + $nx * $big + $dx * $big, $py + $ny * $big + $dy * $big,
            ], $c);
        }
        for ($row = 0; $row < $h; $row++) {
            [$x0, $x1] = $span($row);
            if ($x1 >= $x0) {
                imagecopy($img, $layer, $x + $x0, $y + $row, $x0, $row, $x1 - $x0 + 1, 1);
            }
        }
        imagedestroy($layer);
        return true;
    }

    private static function drawRotatedLayer($img, float $cx, float $cy, float $w, float $h, float $rot, callable $draw): void
    {
        $pad = 60;
        $lw = (int)ceil($w) + 2 * $pad;
        $lh = (int)ceil($h) + 2 * $pad;
        if ($lw * $lh > 30000000) {
            return;
        }
        $layer = imagecreatetruecolor($lw, $lh);
        imagealphablending($layer, false);
        imagesavealpha($layer, true);
        imagefilledrectangle($layer, 0, 0, $lw, $lh, imagecolorallocatealpha($layer, 0, 0, 0, 127));
        imagealphablending($layer, true);
        $draw($layer, (float)$pad, (float)$pad);
        $rotated = imagerotate($layer, -$rot, imagecolorallocatealpha($layer, 0, 0, 0, 127));
        imagedestroy($layer);
        if (!$rotated) {
            return;
        }
        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);
        $rw = imagesx($rotated);
        $rh = imagesy($rotated);
        imagealphablending($img, true);
        imagecopy($img, $rotated, (int)round($cx - $rw / 2), (int)round($cy - $rh / 2), 0, 0, $rw, $rh);
        imagedestroy($rotated);
    }

    // ------------------------------------------------------------------ rasm

    private static function drawImage($img, array $e, array $imgs, float $x, float $y, float $w, float $h, array $pts): void
    {
        $info = $imgs[$e['img'] ?? ''] ?? null;
        if (!$info || !is_file($info['path'])) {
            return;
        }
        $src = $info['ext'] === 'png' ? @imagecreatefrompng($info['path']) : @imagecreatefromjpeg($info['path']);
        if (!$src) {
            return;
        }
        $iw = imagesx($src);
        $ih = imagesy($src);
        $c = (array)($e['crop'] ?? []);
        $sx = (int)round($iw * (float)($c['l'] ?? 0) / 100);
        $sy = (int)round($ih * (float)($c['t'] ?? 0) / 100);
        $sw = max(1, (int)round($iw * (1 - ((float)($c['l'] ?? 0) + (float)($c['r'] ?? 0)) / 100)));
        $sh = max(1, (int)round($ih * (1 - ((float)($c['t'] ?? 0) + (float)($c['b'] ?? 0)) / 100)));
        $dw = max(1, (int)round($w));
        $dh = max(1, (int)round($h));
        $layer = imagecreatetruecolor($dw, $dh);
        imagealphablending($layer, false);
        imagesavealpha($layer, true);
        imagefilledrectangle($layer, 0, 0, $dw, $dh, imagecolorallocatealpha($layer, 255, 255, 255, 0));
        imagecopyresampled($layer, $src, 0, 0, $sx, $sy, $dw, $dh, $sw, $sh);
        imagedestroy($src);
        imagealphablending($img, true);

        $g = (string)($e['g'] ?? 'rect');
        $adj = (array)($e['adj'] ?? []);
        $rot = (float)($e['r'] ?? 0);
        if (!empty($e['fh'])) {
            imageflip($layer, IMG_FLIP_HORIZONTAL);
        }
        if (!empty($e['fv'])) {
            imageflip($layer, IMG_FLIP_VERTICAL);
        }
        $span = self::spanFn($g, (float)$dw, (float)$dh, $adj);
        $paste = function ($target, float $ox, float $oy) use ($layer, $dw, $dh, $span) {
            $px = (int)round($ox);
            $py = (int)round($oy);
            if (!$span) {
                imagecopy($target, $layer, $px, $py, 0, 0, $dw, $dh);
                return;
            }
            for ($row = 0; $row < $dh; $row++) {
                [$x0, $x1] = $span($row);
                if ($x1 >= $x0) {
                    imagecopy($target, $layer, $px + $x0, $py + $row, $x0, $row, $x1 - $x0 + 1, 1);
                }
            }
        };
        if (abs(fmod($rot, 360)) < 0.01) {
            $paste($img, $x, $y);
        } else {
            self::drawRotatedLayer($img, $x + $w / 2, $y + $h / 2, (float)$dw, (float)$dh, $rot, $paste);
        }
        imagedestroy($layer);
        $ln = $e['ln'] ?? null;
        if (is_array($ln) && $ln['w'] > 0) {
            self::strokePoly($img, $pts, max(1.0, $ln['w'] * self::$k), self::col($img, (string)$ln['c'], (int)$ln['a']), (string)$ln['dash'], true);
        }
    }

    // ------------------------------------------------------------------ chiziq

    private static function drawLine($img, array $e): void
    {
        $k = self::$k;
        $x = $e['x'] * $k;
        $y = $e['y'] * $k;
        $w = $e['w'] * $k;
        $h = $e['h'] * $k;
        $ln = $e['ln'] ?? null;
        if (!is_array($ln)) {
            return;
        }
        $x1 = $x;
        $y1 = $y;
        $x2 = $x + $w;
        $y2 = $y + $h;
        if (!empty($e['fh'])) {
            [$x1, $x2] = [$x2, $x1];
        }
        if (!empty($e['fv'])) {
            [$y1, $y2] = [$y2, $y1];
        }
        $p = self::transformPts([$x1, $y1, $x2, $y2], $x + $w / 2, $y + $h / 2, (float)($e['r'] ?? 0), false, false);
        $lw = max(1.0, $ln['w'] * $k);
        $col = self::col($img, (string)$ln['c'], (int)$ln['a']);
        $len = sqrt(($p[2] - $p[0]) ** 2 + ($p[3] - $p[1]) ** 2);
        if ($len < 0.5) {
            return;
        }
        $ux = ($p[2] - $p[0]) / $len;
        $uy = ($p[3] - $p[1]) / $len;
        $head = $ln['head'] ?? 'none';
        $tail = $ln['tail'] ?? 'none';
        $as = max(3.0 * $lw, 14.0);                     // o'q uchi o'lchami
        $sx = $p[0] + ($head !== 'none' ? $ux * $as * 0.6 : 0);
        $sy = $p[1] + ($head !== 'none' ? $uy * $as * 0.6 : 0);
        $ex = $p[2] - ($tail !== 'none' ? $ux * $as * 0.6 : 0);
        $ey = $p[3] - ($tail !== 'none' ? $uy * $as * 0.6 : 0);
        self::strokeSegment($img, $sx, $sy, $ex, $ey, $lw, $col, (string)$ln['dash']);
        if ($head !== 'none') {
            self::arrowHead($img, $p[0], $p[1], -$ux, -$uy, $as, $head, $col);
        }
        if ($tail !== 'none') {
            self::arrowHead($img, $p[2], $p[3], $ux, $uy, $as, $tail, $col);
        }
    }

    private static function arrowHead($img, float $x, float $y, float $dx, float $dy, float $s, string $kind, int $col): void
    {
        $nx = -$dy;
        $ny = $dx;
        switch ($kind) {
            case 'oval':
                imagefilledellipse($img, (int)round($x - $dx * $s * 0.3), (int)round($y - $dy * $s * 0.3), (int)round($s * 0.7), (int)round($s * 0.7), $col);
                break;
            case 'diamond':
                self::fillPoly($img, [$x, $y, $x - $dx * $s * 0.35 + $nx * $s * 0.35, $y - $dy * $s * 0.35 + $ny * $s * 0.35, $x - $dx * $s * 0.7, $y - $dy * $s * 0.7,
                    $x - $dx * $s * 0.35 - $nx * $s * 0.35, $y - $dy * $s * 0.35 - $ny * $s * 0.35], $col);
                break;
            case 'stealth':
                self::fillPoly($img, [$x, $y, $x - $dx * $s + $nx * $s * 0.4, $y - $dy * $s + $ny * $s * 0.4, $x - $dx * $s * 0.7, $y - $dy * $s * 0.7,
                    $x - $dx * $s - $nx * $s * 0.4, $y - $dy * $s - $ny * $s * 0.4], $col);
                break;
            default:
                self::fillPoly($img, [$x, $y, $x - $dx * $s + $nx * $s * 0.4, $y - $dy * $s + $ny * $s * 0.4, $x - $dx * $s - $nx * $s * 0.4, $y - $dy * $s - $ny * $s * 0.4], $col);
        }
    }

    // ------------------------------------------------------------------ jadval

    private static function drawTable($img, array $e, float $x, float $y): void
    {
        $t = $e['tbl'] ?? null;
        if (!is_array($t)) {
            return;
        }
        $k = self::$k;
        $rh = $t['rh'] * $k;
        $cy = $y;
        $border = self::col($img, (string)$t['bc'], 100);
        $bw = max(1.0, 12700 * $k);
        foreach ($t['rows'] as $ri => $row) {
            $cx = $x;
            $isHdr = !empty($t['hdr']) && $ri === 0;
            foreach ($row as $ci => $cell) {
                $cw = ($t['cols'][$ci] ?? 914400) * $k;
                $fillHex = $cell['f'] ?? null;
                if ($fillHex === null) {
                    $fillHex = $isHdr ? $t['hc'] : (!empty($t['band']) && $ri % 2 === 1 ? 'F2F2F2' : null);
                }
                if ($fillHex !== null) {
                    imagefilledrectangle($img, (int)round($cx), (int)round($cy), (int)round($cx + $cw) - 1, (int)round($cy + $rh) - 1, self::col($img, $fillHex, 100));
                }
                imagerectangle($img, (int)round($cx), (int)round($cy), (int)round($cx + $cw), (int)round($cy + $rh), $border);
                $color = $cell['c'] ?? ($isHdr ? $t['hf'] : $t['fc']);
                $tx = ['anchor' => 'ctr', 'ins' => [91440, 45720, 91440, 45720], 'paras' => [[
                    'al' => $cell['al'] ?? 'l', 'bu' => 'none', 'sa' => 0, 'sb' => 0, 'ls' => 100,
                    'runs' => [['t' => (string)$cell['t'], 'sz' => (float)$t['sz'], 'b' => !empty($cell['b']) || $isHdr, 'i' => false, 'u' => false, 's' => false, 'c' => $color, 'f' => $t['font'], 'sp' => 0]],
                ]]];
                self::drawText($img, $tx, $cx, $cy, $cw, $rh);
                $cx += $cw;
            }
            $cy += $rh;
        }
        unset($bw);
    }

    // ============================================================== matn

    private static function fontFile(string $name, bool $b, bool $i): string
    {
        $dir = self::fontDir();
        $serif = ['Times New Roman', 'Georgia', 'Cambria', 'Palatino Linotype', 'Garamond'];
        $sans = ['Arial', 'Courier New'];
        if (in_array($name, $serif, true)) {
            return $dir . 'LiberationSerif-' . ($b ? 'Bold' : 'Regular') . '.ttf';
        }
        if (in_array($name, $sans, true)) {
            return $dir . 'LiberationSans-' . ($b ? 'Bold' : 'Regular') . '.ttf';
        }
        return $dir . 'Carlito-' . ($b ? ($i ? 'BoldItalic' : 'Bold') : ($i ? 'Italic' : 'Regular')) . '.ttf';
    }

    /** Matn kengligi (piksel). Bo'shliqlarni to'g'ri hisoblash uchun "|" bilan o'raladi. */
    private static function tw(string $font, float $size, string $text): float
    {
        if ($text === '') {
            return 0.0;
        }
        $key = $font . '|' . round($size, 2) . '|' . $text;
        if (isset(self::$wc[$key])) {
            return self::$wc[$key];
        }
        if (count(self::$wc) > 20000) {
            self::$wc = [];
        }
        $b = imagettfbbox($size, 0, $font, '|' . $text . '|');
        $b0 = imagettfbbox($size, 0, $font, '||');
        $w = ($b[2] - $b[0]) - ($b0[2] - $b0[0]);
        return self::$wc[$key] = (float)max(0, $w);
    }

    /**
     * Matn qutisini chizadi. $tx = element 'tx'. (x,y,w,h) — quti (piksel).
     */
    private static function drawText($img, array $tx, float $x, float $y, float $w, float $h): void
    {
        $ins = $tx['ins'] ?? [91440, 45720, 91440, 45720];
        $k = self::$k;
        $ix = $x + $ins[0] * $k;
        $iy = $y + $ins[1] * $k;
        $iw = max(10.0, $w - ($ins[0] + $ins[2]) * $k);
        $ih = max(10.0, $h - ($ins[1] + $ins[3]) * $k);
        $lines = self::layout($tx['paras'] ?? [], $iw);
        if (!$lines) {
            return;
        }
        $total = 0.0;
        foreach ($lines as $ln) {
            $total = max($total, $ln['top'] + $ln['h']);
        }
        $off = 0.0;
        if (($tx['anchor'] ?? 't') === 'ctr') {
            $off = ($ih - $total) / 2;
        } elseif (($tx['anchor'] ?? 't') === 'b') {
            $off = $ih - $total;
        }
        foreach ($lines as $ln) {
            $base = $iy + $off + $ln['top'] + $ln['base'];
            $avail = $iw - $ln['indent'];
            $lx = $ix + $ln['indent'];
            $extra = 0.0;
            if ($ln['al'] === 'ctr') {
                $lx += ($avail - $ln['w']) / 2;
            } elseif ($ln['al'] === 'r') {
                $lx += $avail - $ln['w'];
            } elseif ($ln['al'] === 'just' && !$ln['last'] && $ln['spaces'] > 0) {
                $extra = ($avail - $ln['w']) / $ln['spaces'];
            }
            if (!empty($ln['bullet'])) {
                $bf = $ln['bullet'];
                $col = self::col($img, $bf['color'], 100);
                imagettftext($img, $bf['size'] * 0.75, 0, (int)round($ix + $ln['indent'] - $bf['gap'] - self::tw($bf['font'], $bf['size'] * 0.75, $bf['text'])), (int)round($base), $col, $bf['font'], $bf['text']);
            }
            $cx = $lx;
            foreach ($ln['items'] as $it) {
                if ($it['space']) {
                    $cx += $it['w'] + $extra;
                    continue;
                }
                $col = self::col($img, $it['color'], 100);
                imagettftext($img, $it['size'] * 0.75, 0, (int)round($cx), (int)round($base), $col, $it['font'], $it['text']);
                if ($it['u']) {
                    self::thickLine($img, $cx, $base + $it['size'] * 0.1, $cx + $it['w'], $base + $it['size'] * 0.1, max(1.0, $it['size'] * 0.06), $col);
                }
                if ($it['s']) {
                    self::thickLine($img, $cx, $base - $it['size'] * 0.28, $cx + $it['w'], $base - $it['size'] * 0.28, max(1.0, $it['size'] * 0.06), $col);
                }
                $cx += $it['w'];
            }
        }
    }

    /**
     * Matnni qatorlarga ajratadi.
     * Qaytadi: [['top','h','base','indent','w','al','last','spaces','items'=>[['text','font','size'(px),'color','u','s','w','space']],'bullet'=>?]]
     */
    private static function layout(array $paras, float $boxW): array
    {
        $pt = self::$pt;
        $lines = [];
        $y = 0.0;
        $numCounter = 0;
        foreach ($paras as $pi => $p) {
            $runs = $p['runs'] ?? [];
            $bu = $p['bu'] ?? 'none';
            $numCounter = $bu === 'num' ? $numCounter + 1 : 0;
            $indent = $bu !== 'none' ? 0.3 * 10 * (self::BASE_W * self::SS / 10) / 10 : 0.0;     // 0.3 dyuym
            $indent = $bu !== 'none' ? 0.3 * (self::BASE_W * self::SS / 10.0) : 0.0;
            $avail = max(10.0, $boxW - $indent);
            $ls = max(0.5, ($p['ls'] ?? 100) / 100);
            if ($pi > 0) {
                $y += ($p['sb'] ?? 0) * $pt;
            }
            // tokenlar
            $tokens = [];
            $firstRun = null;
            foreach ($runs as $r) {
                $firstRun = $firstRun ?? $r;
                $txt = str_replace(["\u{02BB}", "\u{02BC}"], ["\u{2018}", "\u{2019}"], (string)$r['t']);
                $px = (float)$r['sz'] * $pt;
                $font = self::fontFile((string)$r['f'], !empty($r['b']), !empty($r['i']));
                foreach (preg_split('/(\n)/u', $txt, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $seg) {
                    if ($seg === "\n") {
                        $tokens[] = ['br' => true, 'px' => $px];
                        continue;
                    }
                    foreach (preg_split('/(\s+)/u', $seg, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                        $isSp = (bool)preg_match('/^\s+$/u', $word);
                        $tokens[] = [
                            'text' => $isSp ? ' ' : $word, 'space' => $isSp, 'font' => $font, 'size' => $px, 'color' => (string)$r['c'],
                            'u' => !empty($r['u']), 's' => !empty($r['s']), 'br' => false,
                            'w' => $isSp ? self::tw($font, $px * 0.75, 'x x') - self::tw($font, $px * 0.75, 'xx') : self::tw($font, $px * 0.75, $word),
                        ];
                    }
                }
            }
            $defPx = $firstRun ? (float)$firstRun['sz'] * $pt : 18 * $pt;
            $cur = [];
            $curW = 0.0;
            $first = true;
            $flush = function (bool $last) use (&$cur, &$curW, &$lines, &$y, &$first, $indent, $ls, $p, $defPx, $bu, $numCounter, $firstRun, $avail) {
                while ($cur && $cur[count($cur) - 1]['space']) {            // oxirgi bo'shliqlarni olib tashlaymiz
                    $curW -= $cur[count($cur) - 1]['w'];
                    array_pop($cur);
                }
                $mx = 0.0;
                $spaces = 0;
                foreach ($cur as $it) {
                    $mx = max($mx, $it['size']);
                    if ($it['space']) {
                        $spaces++;
                    }
                }
                if ($mx <= 0) {
                    $mx = $defPx;
                }
                $lh = $mx * 1.22 * $ls;
                $line = ['top' => $y, 'h' => $lh, 'base' => $lh - $mx * 0.269, 'indent' => $indent, 'w' => $curW, 'al' => (string)($p['al'] ?? 'l'),
                    'last' => $last, 'spaces' => $spaces, 'items' => $cur];
                if ($first && $bu !== 'none') {
                    $bcol = $firstRun ? (string)$firstRun['c'] : '262626';
                    $bfont = self::fontFile($firstRun ? (string)$firstRun['f'] : 'Calibri', false, false);
                    $line['bullet'] = ['text' => $bu === 'num' ? $numCounter . '.' : "\u{2022}", 'font' => $bfont, 'size' => $mx, 'color' => $bcol, 'gap' => $mx * 0.25];
                }
                $first = false;
                $lines[] = $line;
                $y += $lh;
                $cur = [];
                $curW = 0.0;
            };
            foreach ($tokens as $tk) {
                if ($tk['br']) {
                    $flush(false);
                    continue;
                }
                if ($tk['space']) {
                    if ($cur) {
                        $cur[] = $tk;
                        $curW += $tk['w'];
                    }
                    continue;
                }
                if ($cur && $curW + $tk['w'] > $avail) {
                    $flush(false);
                }
                $cur[] = $tk;
                $curW += $tk['w'];
            }
            $flush(true);
            $y += ($p['sa'] ?? 0) * $pt;
        }
        return $lines;
    }
}
