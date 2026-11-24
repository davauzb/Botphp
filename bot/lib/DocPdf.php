<?php

/**
 * 📄 PDF: tayyor ishni PDF qilib yuborish.
 *  • Serverda LibreOffice bo'lsa — asl DOCX/PPTX aynan o'zi PDF ga aylantiriladi (eng aniq).
 *  • Bo'lmasa: taqdimot — SlidePdf (GD) orqali; hujjat — shu klass A4 sahifalarni GD bilan o'zi chizadi
 *    (titul, reja, boblar, band sarlavhalari, ro'yxatlar, jadvallar, rasmlar, bet raqamlari; Liberation Serif shrifti).
 * Tayyor PDF ning Telegram file_id si saqlanadi — ikkinchi so'rovda darhol qayta yuboriladi.
 * O'chirish: Sozlamalar → «📄 PDF tugmasi».
 */
require_once __DIR__ . '/SlidePdf.php';

class DocPdf
{
    private const DPI = 130;
    private const W = 1075;               // A4 210 mm @130 dpi
    private const H = 1520;               // 297 mm
    private const ML = 154;               // chap hoshiya 30 mm
    private const MR = 77;                // o'ng 15 mm
    private const MT = 103;               // yuqori 20 mm
    private const MB = 120;
    private const INK = [31, 42, 68];
    private const ACC = [47, 74, 122];

    private static array $pages = [];
    private static $img = null;
    private static int $y = 0;
    private static int $pageNo = 0;
    private static string $font = '';
    private static string $bold = '';

    public static function enabled(): bool
    {
        return (bool)Engine::c('PDF_BUTTON', true);
    }

    public static function button(int $jobId): array
    {
        return ['text' => '📄 PDF', 'callback_data' => 'pdf:' . $jobId];
    }

    // ------------------------------------------------------------------ bot: so'rov

    public static function send(int $tg, int $jobId): void
    {
        $st = db()->prepare('SELECT * FROM jobs WHERE id = ?');
        $st->execute([$jobId]);
        $j = $st->fetch(PDO::FETCH_ASSOC);
        if (!$j || (int)$j['telegram_id'] !== $tg || $j['status'] !== 'done') {
            Telegram::sendMessage($tg, "Bu buyurtma topilmadi yoki hali tayyor emas.");
            return;
        }
        $stamp = max((int)$j['updated_at'], self::editedAt($j));
        $kv = db()->prepare('SELECT v, ts FROM engine_kv WHERE k = ?');
        $kv->execute(['pdf_fid:' . $jobId]);
        $row = $kv->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['ts'] >= $stamp && Telegram::sendDocumentById($tg, (string)$row['v'], '📄 PDF nusxa')) {
            return;
        }
        $lock = db()->prepare('INSERT OR IGNORE INTO engine_kv (k, v, ts) VALUES (?, ?, ?)');
        db()->prepare('DELETE FROM engine_kv WHERE k = ? AND ts < ?')->execute(['pdf_lock:' . $jobId, time() - 180]);
        $lock->execute(['pdf_lock:' . $jobId, '1', time()]);
        if (!$lock->rowCount()) {
            Telegram::sendMessage($tg, "⏳ PDF tayyorlanmoqda, biroz kuting…");
            return;
        }
        Telegram::sendMessage($tg, "⏳ PDF tayyorlanmoqda (10–40 soniya)…");
        $pdf = null;
        try {
            @set_time_limit(180);
            $pdf = self::make($j);
        } catch (Throwable $e) {
            logError('pdf', 'job #' . $jobId . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
        } finally {
            db()->prepare('DELETE FROM engine_kv WHERE k = ?')->execute(['pdf_lock:' . $jobId]);
        }
        if (!$pdf) {
            Telegram::sendMessage($tg, "⚠️ Bu fayldan PDF yaratib bo'lmadi. DOCX/PPTX faylni Word yoki PowerPoint'da ochib, «Сохранить как → PDF» qilishingiz mumkin.");
            return;
        }
        $name = preg_replace('/\.\w+$/', '', (string)($j['tg_file_name'] ?: 'fayl')) . '.pdf';
        if (Telegram::sendDocument($tg, $pdf, '📄 PDF nusxa', null, $name) && Telegram::$lastFileId) {
            db()->prepare('INSERT INTO engine_kv (k, v, ts) VALUES (?, ?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v, ts = excluded.ts')
                ->execute(['pdf_fid:' . $jobId, Telegram::$lastFileId, time()]);
        }
        @unlink($pdf);
    }

    /** Tahrirlovchida oxirgi o'zgartirilgan vaqt (0 — tahrirlanmagan / nusxa yo'q). */
    private static function editedAt(array $j): int
    {
        $pid = (string)($j['pres_id'] ?? '');
        if ($pid === '') {
            return 0;
        }
        $t = $j['type'] === 'slayd' ? 'presentations' : 'documents';
        $st = db()->prepare("SELECT updated_at FROM $t WHERE id = ?");
        $st->execute([$pid]);
        return (int)$st->fetchColumn();
    }

    /** PDF fayl yo'li yoki null. */
    public static function make(array $j): ?string
    {
        $pid = (string)($j['pres_id'] ?? '');
        $edited = $pid !== '' && self::editedAt($j) > (int)($j['finished_at'] ?? 0) + 5;   // yetkazilgandan keyin tahrirlangan
        if (SlidePdf::sofficePath()) {
            $src = $edited ? self::freshExport($j) : self::sourceFile($j);
            if ($src) {
                $pdf = SlidePdf::convertToPdf($src);
                if (str_contains(basename($src), 'pdfsrc_')) {
                    @unlink($src);
                }
                if ($pdf) {
                    return $pdf;
                }
            }
        }
        if ($pid === '') {
            return null;                          // PRO taqdimot / test: tahrirlanadigan nusxa yo'q, LibreOffice ham yo'q
        }
        if ($j['type'] === 'slayd') {
            [$path] = SlidePdf::fromPresentation($pid);
            return $path;
        }
        $doc = DocStore::get($pid);
        return $doc ? self::renderDoc($doc) : null;
    }

    /** Tahrirlangan nusxadan yangi DOCX/PPTX (LibreOffice uchun). */
    private static function freshExport(array $j): ?string
    {
        try {
            $pid = (string)$j['pres_id'];
            if ($j['type'] === 'slayd') {
                [$f] = EditorStore::exportFile($pid);
            } else {
                $d = DocStore::get($pid);
                $f = $d ? DocStore::exportDocx($d) : null;
            }
            if ($f && is_file($f)) {
                $dst = dirname($f) . '/pdfsrc_' . basename($f);
                return @rename($f, $dst) ? $dst : $f;
            }
        } catch (Throwable $e) {
            logError('pdf', 'Eksport: ' . $e->getMessage());
        }
        return null;
    }

    private static function sourceFile(array $j): ?string
    {
        $f = (string)($j['result_file'] ?? '');
        if ($f !== '' && is_file($f)) {
            return $f;
        }
        if (empty($j['tg_file_id'])) {
            return null;
        }
        $r = Telegram::call('getFile', ['file_id' => (string)$j['tg_file_id']]);
        $path = (string)($r['result']['file_path'] ?? '');
        if ($path === '') {
            return null;
        }
        $ch = curl_init(TELEGRAM_FILE_API . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40]);
        $body = curl_exec($ch);
        $ok = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
        if (!$ok || !is_string($body)) {
            return null;
        }
        $dst = rtrim(GENERATED_DIR, '/') . '/pdfsrc_' . bin2hex(random_bytes(5)) . '.' . (pathinfo($path, PATHINFO_EXTENSION) ?: 'docx');
        file_put_contents($dst, $body);
        return $dst;
    }

    // ------------------------------------------------------------------ hujjatni chizish (GD)

    private static function pt(float $pt): float
    {
        return $pt * self::DPI / 72;
    }

    private static function newPage(): void
    {
        self::flush();
        self::$img = imagecreatetruecolor(self::W, self::H);
        imagefill(self::$img, 0, 0, imagecolorallocate(self::$img, 255, 255, 255));
        self::$y = self::MT;
        self::$pageNo++;
    }

    private static function flush(): void
    {
        if (self::$img === null) {
            return;
        }
        if (self::$pageNo > 1) {                  // titul betida raqam yo'q
            self::text((string)self::$pageNo, self::font(), 11, (int)(self::W / 2), self::H - 60, [90, 99, 120], 'center');
        }
        ob_start();
        imagejpeg(self::$img, null, 88);
        self::$pages[] = (string)ob_get_clean();
        imagedestroy(self::$img);
        self::$img = null;
    }

    private static function font(bool $b = false): string
    {
        return $b ? self::$bold : self::$font;
    }

    private static function color(array $c): int
    {
        return imagecolorallocate(self::$img, $c[0], $c[1], $c[2]);
    }

    private static function width(string $s, string $font, float $sizePt): float
    {
        $b = imagettfbbox(self::pt($sizePt) * 0.75, 0, $font, $s);
        return (float)($b[2] - $b[0]);
    }

    private static function text(string $s, string $font, float $sizePt, int $x, int $baseline, array $c, string $align = 'left'): void
    {
        $sz = self::pt($sizePt) * 0.75;           // GD: pt → px (96 dpi asosida)
        if ($align !== 'left') {
            $w = self::width($s, $font, $sizePt);
            $x = (int)($align === 'center' ? $x - $w / 2 : $x - $w);
        }
        imagettftext(self::$img, $sz, 0, $x, $baseline, self::color($c), $font, $s);
    }

    /** So'zlarni qatorlarga bo'lish. */
    private static function wrap(string $s, string $font, float $sizePt, float $maxW): array
    {
        $lines = [];
        $cur = '';
        foreach (preg_split('/\s+/u', trim($s)) ?: [] as $w) {
            $t = $cur === '' ? $w : $cur . ' ' . $w;
            if ($cur !== '' && self::width($t, $font, $sizePt) > $maxW) {
                $lines[] = $cur;
                $cur = $w;
            } else {
                $cur = $t;
            }
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }
        return $lines;
    }

    /** Abzats: birinchi qator chekinishi, ikki tomonga tekislash, 1.5 interval. */
    private static function para(string $s, array $o = []): void
    {
        $size = $o['size'] ?? 14;
        $font = self::font(!empty($o['bold']));
        $indent = (int)($o['indent'] ?? self::pt(35));
        $left = self::ML + (int)($o['left'] ?? 0);
        $maxW = self::W - self::MR - $left;
        $lh = (int)(self::pt($size) * 1.5);
        $c = $o['color'] ?? self::INK;
        $align = $o['align'] ?? 'justify';
        $lines = [];
        $cur = '';
        foreach (preg_split('/\s+/u', trim($s)) ?: [] as $w) {
            $t = $cur === '' ? $w : $cur . ' ' . $w;
            if ($cur !== '' && self::width($t, $font, $size) > ($maxW - (count($lines) === 0 ? $indent : 0))) {
                $lines[] = $cur;
                $cur = $w;
            } else {
                $cur = $t;
            }
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }
        foreach ($lines as $i => $ln) {
            if (self::$y + $lh > self::H - self::MB) {
                self::newPage();
            }
            $x = $left + ($i === 0 ? $indent : 0);
            $base = self::$y + (int)(self::pt($size) * 1.05);
            $last = $i === count($lines) - 1;
            if ($align === 'center') {
                self::text($ln, $font, $size, (int)(($left + self::W - self::MR) / 2), $base, $c, 'center');
            } elseif ($align === 'justify' && !$last && substr_count($ln, ' ') > 0) {
                $words = explode(' ', $ln);
                $wsum = 0;
                foreach ($words as $w) {
                    $wsum += self::width($w, $font, $size);
                }
                $gap = ($maxW - ($i === 0 ? $indent : 0) - $wsum) / (count($words) - 1);
                if ($gap > self::pt($size) * 1.2) {    // juda siyrak bo'lsa — oddiy chapga
                    self::text($ln, $font, $size, $x, $base, $c);
                } else {
                    $cx = (float)$x;
                    foreach ($words as $w) {
                        self::text($w, $font, $size, (int)round($cx), $base, $c);
                        $cx += self::width($w, $font, $size) + $gap;
                    }
                }
            } else {
                self::text($ln, $font, $size, $x, $base, $c);
            }
            self::$y += $lh;
        }
        self::$y += (int)($o['after'] ?? self::pt(4));
    }

    private static function image(string $path, string $caption): void
    {
        $im = @imagecreatefromstring((string)@file_get_contents($path));
        if (!$im) {
            return;
        }
        $maxW = (int)((self::W - self::ML - self::MR) * 0.8);
        $k = min(1, $maxW / imagesx($im), self::pt(260) / imagesy($im));
        $w = (int)(imagesx($im) * $k);
        $h = (int)(imagesy($im) * $k);
        if (self::$y + $h + 60 > self::H - self::MB) {
            self::newPage();
        }
        $x = (int)(self::ML + (self::W - self::ML - self::MR - $w) / 2);
        imagecopyresampled(self::$img, $im, $x, self::$y + 10, 0, 0, $w, $h, imagesx($im), imagesy($im));
        self::$y += $h + 18;
        self::para($caption, ['size' => 12, 'align' => 'center', 'indent' => 0, 'color' => [70, 80, 100]]);
    }

    private static function table(array $rows, string $caption): void
    {
        if (self::$y + 160 > self::H - self::MB) {      // sarlavha jadvaldan ajralib qolmasin
            self::newPage();
        }
        if ($caption !== '') {
            self::para($caption, ['size' => 12, 'align' => 'center', 'indent' => 0, 'bold' => true, 'after' => 4]);
        }
        $cols = max(array_map('count', $rows));
        $tw = self::W - self::ML - self::MR;
        $cw = $tw / max(1, $cols);
        $line = self::color([150, 160, 185]);
        foreach ($rows as $ri => $r) {
            $cells = array_map(fn($c) => self::wrap($c, self::font($ri === 0), 12, $cw - 16), array_pad($r, $cols, ''));
            $lh = (int)(self::pt(12) * 1.35);
            $h = max(1, max(array_map('count', $cells))) * $lh + 12;
            if (self::$y + $h > self::H - self::MB) {
                self::newPage();
            }
            if ($ri === 0) {
                imagefilledrectangle(self::$img, self::ML, self::$y, (int)(self::ML + $tw), self::$y + $h, self::color([232, 237, 246]));
            }
            foreach ($cells as $ci => $ls) {
                $x = (int)(self::ML + $ci * $cw);
                imagerectangle(self::$img, $x, self::$y, (int)($x + $cw), self::$y + $h, $line);
                foreach ($ls as $li => $l) {
                    self::text($l, self::font($ri === 0), 12, $x + 8, self::$y + 6 + (int)(($li + 0.85) * $lh), self::INK);
                }
            }
            self::$y += $h;
        }
        self::$y += (int)self::pt(10);
    }

    private static function heading(string $h): void
    {
        if (self::$y > self::MT + 10) {
            self::newPage();
        }
        self::$y += (int)self::pt(6);
        self::para(mb_strtoupper($h), ['size' => 15, 'bold' => true, 'align' => 'center', 'indent' => 0, 'after' => (int)self::pt(10), 'color' => self::INK]);
    }

    /** Bo'lim matnini chizish (bandlar, ro'yxat, jadval, rasm belgilari). */
    private static function body(string $text, array $images): void
    {
        $blocks = preg_split('/\n\s*\n/u', str_replace("\r", '', $text)) ?: [];
        $caption = '';
        foreach ($blocks as $b) {
            $b = trim($b);
            if ($b === '') {
                continue;
            }
            if (preg_match('/^\[RASM\s+(\d+):\s*([^\]]*)\]$/u', $b, $m)) {
                $p = $images[(int)$m[1]] ?? null;
                if ($p && is_file((string)$p)) {
                    self::image((string)$p, (int)$m[1] . '-rasm. ' . $m[2]);
                }
                continue;
            }
            $lines = explode("\n", $b);
            if (preg_match('/^JADVAL:\s*(.+)$/u', trim($lines[0]), $m)) {
                $caption = trim($m[1]);
                array_shift($lines);
                if (!$lines) {
                    continue;
                }
            }
            if (str_starts_with(trim($lines[0]), '|')) {
                $rows = [];
                foreach ($lines as $l) {
                    $l = trim($l);
                    if (!str_starts_with($l, '|') || preg_match('/^\|[\s\-:|]+\|$/', $l)) {
                        continue;
                    }
                    $rows[] = array_map('trim', explode('|', trim($l, '|')));
                }
                if ($rows) {
                    self::table($rows, $caption);
                }
                $caption = '';
                continue;
            }
            foreach ($lines as $l) {
                $l = trim($l);
                if ($l === '') {
                    continue;
                }
                if (preg_match('/^\d+\.\d+\.\s+\S/u', $l) && mb_strlen($l) < 170) {
                    self::$y += (int)self::pt(4);
                    self::para($l, ['bold' => true, 'indent' => 0, 'align' => 'left', 'after' => (int)self::pt(4)]);
                } elseif (preg_match('/^[•\-–]\s+(.+)$/u', $l, $m)) {
                    self::para('•  ' . $m[1], ['indent' => 0, 'left' => (int)self::pt(25), 'align' => 'left', 'after' => 0]);
                } else {
                    self::para($l);
                }
            }
        }
    }

    public static function renderDoc(array $doc): ?string
    {
        if (!function_exists('imagettftext')) {
            return null;
        }
        $dir = SlidePdf::fontDir();
        self::$font = $dir . 'LiberationSerif-Regular.ttf';
        self::$bold = $dir . 'LiberationSerif-Bold.ttf';
        if (!is_file(self::$font)) {
            return null;
        }
        self::$pages = [];
        self::$img = null;
        self::$pageNo = 0;
        $lang = (string)($doc['lang'] ?: 'uz');
        $st = (array)$doc['student'];
        $sections = DocStore::sanitize(['title' => $doc['title'], 'student' => $st, 'sections' => $doc['sections']])['sections'];
        $images = DocStore::images((string)$doc['id']);
        $article = DocTypes::isArticleDoc((string)$doc['doc_type']);

        self::newPage();
        if (!$article) {
            // titul
            $ramka = self::color(self::ACC);
            imagesetthickness(self::$img, 4);
            imagerectangle(self::$img, 60, 60, self::W - 60, self::H - 60, $ramka);
            imagesetthickness(self::$img, 1);
            imagerectangle(self::$img, 70, 70, self::W - 70, self::H - 70, $ramka);
            self::$y = 150;
            $min = I18n::ministry($lang);
            if ($min !== '') {
                self::para(mb_strtoupper($min), ['bold' => true, 'align' => 'center', 'indent' => 0, 'size' => 13]);
            }
            self::para(mb_strtoupper((string)($st['university'] ?? '')), ['bold' => true, 'align' => 'center', 'indent' => 0, 'size' => 13]);
            if (!empty($st['kafedra'])) {
                self::para((string)$st['kafedra'], ['align' => 'center', 'indent' => 0, 'size' => 12, 'color' => [90, 99, 120]]);
            }
            self::$y = 560;
            self::para(mb_strtoupper(I18n::t('doc_' . $doc['doc_type'], $lang)), ['bold' => true, 'align' => 'center', 'indent' => 0, 'size' => 30]);
            self::$y += 20;
            self::para(I18n::t('topic', $lang) . ':', ['align' => 'center', 'indent' => 0, 'size' => 14, 'color' => [90, 99, 120]]);
            self::para('«' . $doc['title'] . '»', ['bold' => true, 'align' => 'center', 'indent' => 0, 'size' => 18]);
            self::$y = 1100;
            $x = (int)(self::W * 0.5);
            foreach ([[I18n::t('executor', $lang), $st['full_name'] ?? ''], [I18n::t('group', $lang), $st['group_name'] ?? ''],
                [I18n::t(in_array($doc['doc_type'], ['KURS ISHI', 'BITIRUV MALAKAVIY ISHI'], true) ? 'supervisor' : 'checked_by', $lang), ($st['teacher'] ?? '') ?: '________________']] as [$k, $v]) {
                self::text($k . ': ', self::font(true), 14, $x, self::$y, self::INK);
                self::text((string)$v, self::font(), 14, $x + (int)self::width($k . ': ', self::font(true), 14), self::$y, self::INK);
                self::$y += 42;
            }
            $year = (int)date('n') >= 9 ? (int)date('Y') : (int)date('Y') - 1;
            $city = DocxGenerator::city((string)($st['university'] ?? ''), $lang);
            self::text(($city !== '' ? $city . ' – ' : '') . $year . '–' . ($year + 1) . ' ' . I18n::t('year_suffix', $lang), self::font(true), 14, (int)(self::W / 2), self::H - 130, self::INK, 'center');
            // reja
            self::newPage();
            self::para(mb_strtoupper(I18n::t('contents', $lang)), ['bold' => true, 'align' => 'center', 'indent' => 0, 'size' => 15, 'after' => (int)self::pt(12)]);
            foreach ($sections as $s) {
                self::para($s['heading'], ['indent' => 0, 'align' => 'left', 'after' => 2, 'bold' => !preg_match('/^\d+\./u', $s['heading'])]);
            }
        } else {
            self::para(mb_strtoupper((string)$doc['title']), ['bold' => true, 'align' => 'center', 'indent' => 0, 'size' => 15]);
            if (!empty($st['full_name'])) {
                self::para((string)$st['full_name'], ['bold' => true, 'align' => 'right', 'indent' => 0]);
            }
            if (!empty($st['university'])) {
                self::para((string)$st['university'], ['align' => 'right', 'indent' => 0, 'size' => 12, 'color' => [90, 99, 120], 'after' => (int)self::pt(10)]);
            }
        }
        foreach ($sections as $s) {
            if (!$article) {
                self::heading($s['heading']);
            } elseif ($s['heading'] !== '—') {
                self::$y += (int)self::pt(8);
                self::para($s['heading'], ['bold' => true, 'indent' => 0, 'align' => 'left']);
            }
            self::body((string)$s['text'], $images);
        }
        self::flush();
        $path = rtrim(GENERATED_DIR, '/') . '/doc_' . bin2hex(random_bytes(5)) . '.pdf';
        $ok = SlidePdf::jpegsToPdf($path, self::$pages, (string)$doc['title']);
        self::$pages = [];
        return $ok ? $path : null;
    }
}
