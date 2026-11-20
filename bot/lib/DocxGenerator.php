<?php

require_once __DIR__ . '/I18n.php';

/**
 * Mustaqil ish / referat DOCX generatori (PRO andoza).
 *
 *  - Haqiqiy Word uslublari (styles.xml): Normal, Heading 1/2, Title, TOC — Word «Navigatsiya» paneli va avtomatik mundarija ishlaydi;
 *  - Titul varag'i: faqat 1-betda ikki chiziqli ramka, vazirlik → universitet → ajratuvchi chiziq → ish turi → mavzu →
 *    bajaruvchi/guruh/qabul qildi bloki → eng pastda o'quv yili (ramka ichida, har doim bet tagida);
 *  - Reja: nuqtali yo'naltiruvchi chiziq va bet raqami (PAGEREF + xatcho'p; raqam oldindan hisoblanadi, Word'da F9 bilan yangilanadi);
 *  - Har bir asosiy bo'lim (Kirish, boblar, Xulosa, Adabiyotlar) yangi betdan boshlanadi;
 *  - Matn: Times New Roman 14pt, 1.5 interval, ikki tomonlama tekislash, 1.25 sm abzats, «yetim» qatorlar nazorati;
 *  - Ro'yxatlar (•, -, 1) ...) haqiqiy Word ro'yxatiga aylanadi; adabiyotlar — raqamlangan ro'yxat;
 *  - Bet raqami pastda markazda (titul varag'ida ko'rsatilmaydi), hujjat xossalari (sarlavha, muallif) to'ldiriladi.
 */
class DocxGenerator
{
    private const FONT = 'Times New Roman';
    private const INK = '1F2A44';        // titul va sarlavhalar uchun to'q ko'k-kulrang (chop etilganda qora kabi ko'rinadi)
    private const ACCENT = '2F4A7A';     // titul ramkasi va ajratuvchi chiziqlar

    // Bet raqamini hisoblash uchun taxminiy o'lchamlar (LibreOffice/Word bilan solishtirib sozlangan)
    private const CHARS_PER_LINE = 84;
    private const LINES_PER_PAGE = 29;

    /**
     * Foydalanilgan adabiyotlar bo'limini (agar hali yo'q bo'lsa) oxiriga qo'shadi. Aniq soxta kitob/muallif o'ylab topilmaydi —
     * umumiy, halol matn. Tahrirlovchi shu ro'yxatni (adabiyotlar bilan birga) saqlaydi, shuning uchun alohida ochiq.
     */
    public static function withBiblio(array $sections, string $lang = 'uz'): array
    {
        $lang = I18n::norm($lang);
        foreach ($sections as $s) {
            if (self::isBiblio((string)$s['heading'], $lang)) {
                return $sections;
            }
        }
        $sections[] = ['heading' => I18n::t('biblio', $lang), 'text' => I18n::t('biblio_text', $lang)];
        return $sections;
    }

    private static function isBiblio(string $heading, string $lang): bool
    {
        return mb_stripos($heading, I18n::t('biblio_match', $lang)) !== false
            || mb_stripos($heading, 'adabiyot') !== false || mb_stripos($heading, 'литератур') !== false || mb_stripos($heading, 'reference') !== false;
    }

    /**
     * $sections = [ ['heading' => '1. Kirish', 'text' => '...'], ... ]
     * $studentInfo = ['full_name' => '...', 'university' => '...', 'group_name' => '...']
     * Natija: to'liq fayl yo'li (.docx)
     */
    /** Joriy hujjat holati (rasm/jadval raqamlari, maket turi). */
    private static array $media = [];       // raqam => ['rid','name','path','cx','cy']
    private static int $figN = 0;
    private static int $tabN = 0;
    private static int $drawId = 0;
    private static bool $article = false;
    private static string $lang = 'uz';

    /**
     * $opts['images'] = [raqam => lokal rasm yo'li] — matndagi «[RASM n: izoh]» qatorlari o'rniga qo'yiladi.
     * MAQOLA/TEZIS — titulsiz maqola maketi (sarlavha, muallif, annotatsiya, kalit so'zlar, matn, adabiyotlar).
     */
    public static function generate(string $topic, array $sections, array $studentInfo = [], string $docType = 'MUSTAQIL ISH', string $lang = 'uz', bool $autoBiblio = true, array $opts = []): string
    {
        $lang = I18n::norm($lang);
        self::$lang = $lang;
        self::$article = in_array($docType, ['MAQOLA', 'TEZIS'], true);
        self::$figN = 0;
        self::$tabN = 0;
        self::$drawId = 0;
        if ($autoBiblio) {
            $sections = self::withBiblio($sections, $lang);
        }
        $sections = array_values(array_filter($sections, fn($s) => trim((string)($s['heading'] ?? '')) !== '' || trim((string)($s['text'] ?? '')) !== ''));

        $prefix = ['REFERAT' => 'referat_', 'KURS ISHI' => 'kurs_ishi_', 'MAQOLA' => 'maqola_', 'TEZIS' => 'tezis_'][$docType] ?? 'mustaqil_ish_';
        $path = GENERATED_DIR . $prefix . uniqid() . '.docx';
        $tmpFiles = [];
        self::$media = self::prepareImages((array)($opts['images'] ?? []), $tmpFiles);

        if (self::$article) {
            $body = self::articleHead($topic, $studentInfo);
            foreach ($sections as $i => $section) {
                $body .= self::sectionXml($section, $i, $lang, []);
            }
        } else {
            $pages = self::estimatePages($sections);
            $body = self::titlePage($topic, $studentInfo, $docType, $lang);
            $body .= self::planPage($sections, $pages, $lang);
            foreach ($sections as $i => $section) {
                $body .= self::sectionXml($section, $i, $lang, $pages['h2'][$i] ?? []);
            }
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('DOCX fayl yaratib bo\'lmadi: ' . $path);
        }
        $author = trim((string)($studentInfo['full_name'] ?? ''));
        $zip->addFromString('[Content_Types].xml', self::contentTypesXml());
        foreach (self::$media as $m) {
            if (!empty($m['used'])) {
                $zip->addFile($m['path'], 'word/media/' . $m['name']);
            }
        }
        $zip->addFromString('_rels/.rels', self::relsXml());
        $zip->addFromString('docProps/core.xml', self::coreXml($topic, $author, I18n::t('doc_' . $docType, $lang), $lang));
        $zip->addFromString('docProps/app.xml', self::appXml());
        $zip->addFromString('word/_rels/document.xml.rels', self::documentRelsXml());
        $zip->addFromString('word/document.xml', self::documentTemplate($body));
        $zip->addFromString('word/styles.xml', self::stylesXml($lang));
        $zip->addFromString('word/settings.xml', self::settingsXml($lang));
        $zip->addFromString('word/numbering.xml', self::numberingXml());
        $zip->addFromString('word/fontTable.xml', self::fontTableXml());
        $zip->addFromString('word/footer1.xml', self::footerXml());
        $zip->addFromString('word/footer2.xml', self::emptyFooterXml());
        $closed = $zip->close();
        foreach ($tmpFiles as $f) {
            @unlink($f);
        }
        if (!$closed || !is_file($path)) {
            throw new RuntimeException('DOCX faylni diskka yozib bo\'lmadi (papka mavjudmi va yozish ruxsati bormi?): ' . $path);
        }
        return $path;
    }

    /** Rasmlar: o'lchami aniqlanadi, katta bo'lsa GD bilan 1400 px gacha kichraytiriladi (DOCX yengil bo'lsin). */
    private static function prepareImages(array $images, array &$tmp): array
    {
        $out = [];
        foreach ($images as $n => $src) {
            $n = (int)$n;
            $info = is_string($src) && is_file($src) ? @getimagesize($src) : false;
            if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || $info[0] < 50 || $info[1] < 50) {
                continue;
            }
            $file = $src;
            $ext = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
            [$w, $h] = [$info[0], $info[1]];
            if ($w > 1400 && function_exists('imagecreatetruecolor')) {
                $im = $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($src) : @imagecreatefromjpeg($src);
                if ($im) {
                    $nw = 1400;
                    $nh = (int)round($h * 1400 / $w);
                    $dst = imagecreatetruecolor($nw, $nh);
                    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
                    imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
                    $file = (defined('GENERATED_DIR') ? GENERATED_DIR : sys_get_temp_dir() . '/') . 'dimg_' . bin2hex(random_bytes(5)) . '.jpg';
                    imagejpeg($dst, $file, 82);
                    imagedestroy($dst);
                    imagedestroy($im);
                    $tmp[] = $file;
                    [$w, $h, $ext] = [$nw, $nh, 'jpg'];
                }
            }
            // eni 15 sm gacha, bo'yi 9.5 sm dan oshmasin
            $cx = 5400000;
            $cy = (int)round($cx * $h / $w);
            if ($cy > 3420000) {
                $cy = 3420000;
                $cx = (int)round($cy * $w / $h);
            }
            $out[$n] = ['rid' => 'rIdImg' . $n, 'name' => 'image' . $n . '.' . $ext, 'path' => $file, 'cx' => $cx, 'cy' => $cy, 'used' => false];
        }
        return $out;
    }

    // ================================================================== yordamchilar

    private static function escape(string $text): string
    {
        // XML 1.0 da ruxsat etilmagan boshqaruv belgilari (AI matnida uchrab qoladi) Word'da "fayl buzilgan" xatosini beradi
        $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function upper(string $t): string
    {
        return mb_strtoupper($t, 'UTF-8');
    }

    private static function run(string $text, array $o = []): string
    {
        $rPr = '';
        if (!empty($o['b'])) {
            $rPr .= '<w:b/><w:bCs/>';
        }
        if (!empty($o['i'])) {
            $rPr .= '<w:i/><w:iCs/>';
        }
        if (!empty($o['caps'])) {
            $rPr .= '<w:caps/>';
        }
        if (!empty($o['color'])) {
            $rPr .= '<w:color w:val="' . $o['color'] . '"/>';
        }
        if (!empty($o['spacing'])) {
            $rPr .= '<w:spacing w:val="' . (int)$o['spacing'] . '"/>';
        }
        if (!empty($o['sz'])) {
            $rPr .= '<w:sz w:val="' . (int)$o['sz'] . '"/><w:szCs w:val="' . (int)$o['sz'] . '"/>';
        }
        return '<w:r>' . ($rPr !== '' ? '<w:rPr>' . $rPr . '</w:rPr>' : '') . '<w:t xml:space="preserve">' . self::escape($text) . '</w:t></w:r>';
    }

    /** Paragraf: $pPr — tayyor XML (w:pPr ichi), $runs — tayyor runlar. */
    private static function p(string $runs, string $pPr = ''): string
    {
        return '<w:p>' . ($pPr !== '' ? '<w:pPr>' . $pPr . '</w:pPr>' : '') . $runs . '</w:p>';
    }

    private static function sp(int $before = 0, int $after = 0, int $line = 240): string
    {
        return '<w:spacing w:before="' . $before . '" w:after="' . $after . '" w:line="' . $line . '" w:lineRule="auto"/>';
    }

    private static function pageBreak(): string
    {
        return '<w:p><w:pPr>' . self::sp(0, 0) . '</w:pPr><w:r><w:br w:type="page"/></w:r></w:p>';
    }

    // ================================================================== titul varag'i

    /**
     * O'zbekiston OTMlarida qabul qilingan tartib: tepada vazirlik va universitet, o'rtada ish turi va mavzu,
     * o'ngda bajaruvchi ma'lumotlari, eng pastda o'quv yili. Ramka faqat shu betda (pgBorders display=firstPage).
     */
    private static function titlePage(string $topic, array $studentInfo, string $docType, string $lang): string
    {
        $university = trim((string)($studentInfo['university'] ?? '')) ?: '________________________________';
        $fullName = trim((string)($studentInfo['full_name'] ?? '')) ?: '______________________';
        $group = trim((string)($studentInfo['group_name'] ?? '')) ?: '__________';
        $ctr = '<w:jc w:val="center"/>';
        $xml = '';

        $ministry = I18n::ministry($lang);
        if ($ministry !== '') {
            $xml .= self::p(self::run(self::upper($ministry), ['b' => true, 'sz' => 26, 'color' => self::INK]), '<w:keepNext/>' . self::sp(0, 160, 252) . $ctr);
        }
        $xml .= self::p(self::run(self::upper($university), ['b' => true, 'sz' => 26, 'color' => self::INK]), self::sp(0, 120, 252) . $ctr);
        // ajratuvchi: ikki chiziq (yo'g'on + ingichka)
        $xml .= self::p('', '<w:pBdr><w:bottom w:val="thinThickSmallGap" w:sz="18" w:space="1" w:color="' . self::ACCENT . '"/></w:pBdr>' . self::sp(0, 0, 240) . '<w:ind w:left="567" w:right="567"/>');

        $typeLabel = self::upper(I18n::t('doc_' . $docType, $lang));
        $typeSz = mb_strlen($typeLabel) > 16 ? 48 : 64;
        $xml .= self::p(self::run($typeLabel, ['b' => true, 'sz' => $typeSz, 'color' => self::INK, 'spacing' => 40]), self::sp(mb_strlen($ministry) > 0 ? 2300 : 2700, 360, 240) . $ctr);

        $xml .= self::p(self::run(I18n::t('topic', $lang) . ':', ['i' => true, 'sz' => 28, 'color' => '5A6378']), self::sp(0, 80, 240) . $ctr);
        $topic = trim($topic);
        $tsz = mb_strlen($topic) > 110 ? 30 : (mb_strlen($topic) > 60 ? 34 : 38);
        $xml .= self::p(self::run('«' . $topic . '»', ['b' => true, 'sz' => $tsz, 'color' => self::INK]), self::sp(0, 0, 264) . '<w:ind w:left="284" w:right="284"/>' . $ctr);

        // Bajaruvchi bloki — o'ng yarmida, chap chegarasi bir xil (jadvalsiz, chap chekinish bilan)
        $ind = '<w:ind w:left="4536" w:firstLine="0"/>';
        $label = fn(string $k, string $v, int $before = 0) => self::p(
            self::run($k . ': ', ['b' => true, 'sz' => 28]) . self::run($v, ['sz' => 28]),
            self::sp($before, 100, 276) . $ind . '<w:jc w:val="left"/>'
        );
        $xml .= $label(I18n::t('executor', $lang), $fullName, 2200);
        $xml .= $label(I18n::t('group', $lang), $group);
        $xml .= $label(I18n::t('checked_by', $lang), '________________');

        // O'quv yili — bet tagida (ramka/frame bilan), matn uzunligiga bog'liq emas
        $xml .= self::p(self::run(self::academicYearLabel($lang), ['b' => true, 'sz' => 28, 'color' => self::INK]),
            '<w:framePr w:w="9072" w:hSpace="0" w:wrap="around" w:vAnchor="margin" w:hAnchor="margin" w:xAlign="center" w:yAlign="bottom"/>' . self::sp(0, 0) . $ctr);

        // Titul varag'i alohida bo'lim: faqat shu betda ramka, bet raqami yo'q
        $xml .= '<w:p><w:pPr>' . self::sp(0, 0) . '<w:sectPr>'
            . '<w:footerReference w:type="default" r:id="rIdFooterFirst"/>'
            . '<w:pgSz w:w="11906" w:h="16838"/>'
            . '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1701" w:header="567" w:footer="567" w:gutter="0"/>'
            . '<w:pgBorders w:offsetFrom="page">'
            . '<w:top w:val="thinThickSmallGap" w:sz="24" w:space="24" w:color="' . self::ACCENT . '"/>'
            . '<w:left w:val="thinThickSmallGap" w:sz="24" w:space="24" w:color="' . self::ACCENT . '"/>'
            . '<w:bottom w:val="thickThinSmallGap" w:sz="24" w:space="24" w:color="' . self::ACCENT . '"/>'
            . '<w:right w:val="thickThinSmallGap" w:sz="24" w:space="24" w:color="' . self::ACCENT . '"/>'
            . '</w:pgBorders>'
            . '</w:sectPr></w:pPr></w:p>';
        return $xml;
    }

    /** Maqola/tezis boshi: sarlavha (katta harflarda), muallif, OTM/guruh. */
    private static function articleHead(string $topic, array $st): string
    {
        $ctr = '<w:ind w:firstLine="0"/><w:jc w:val="center"/>';
        $x = self::p(self::run(self::upper(trim($topic)), ['b' => true, 'sz' => 28, 'color' => self::INK]), '<w:keepNext/>' . self::sp(0, 200, 264) . $ctr);
        $name = trim((string)($st['full_name'] ?? ''));
        if ($name !== '') {
            $x .= self::p(self::run($name, ['b' => true, 'i' => true]), self::sp(0, 40, 264) . '<w:ind w:firstLine="0"/><w:jc w:val="right"/>');
        }
        $aff = trim(implode(', ', array_filter([(string)($st['university'] ?? ''), ($st['group_name'] ?? '') !== '' ? I18n::t('group', self::$lang) . ': ' . $st['group_name'] : ''])));
        if ($aff !== '') {
            $x .= self::p(self::run($aff, ['i' => true, 'sz' => 24]), self::sp(0, 240, 264) . '<w:ind w:firstLine="0"/><w:jc w:val="right"/>');
        }
        return $x;
    }

    /** "2025-2026 o'quv yili" — o'quv yili sentyabrdan boshlanadi. */
    private static function academicYearLabel(string $lang = 'uz'): string
    {
        $year = (int)date('Y');
        $start = (int)date('n') >= 9 ? $year : $year - 1;
        return $start . '–' . ($start + 1) . ' ' . I18n::t('year_suffix', $lang);
    }

    // ================================================================== reja

    /** Bo'lim sarlavhasining Reja/kitob ichidagi ko'rinishi: boblar raqami bilan, Kirish/Xulosa/Adabiyotlar — raqamsiz. */
    private static function headingText(string $heading): string
    {
        $h = trim(preg_replace('/\s+/u', ' ', $heading) ?? $heading);
        if (preg_match('/^(\d+)\s*[\.\)]\s*(.+)$/u', $h, $m)) {
            return $m[1] . '. ' . $m[2];
        }
        return $h;
    }

    private static function isChapter(string $heading): bool
    {
        return (bool)preg_match('/^\s*\d+\s*[\.\)]/u', $heading);
    }

    /**
     * Har bo'lim boshlanadigan betni taxmin qiladi (titul = 1, reja = 2, matn 3-betdan). Har bo'lim yangi betdan boshlanadi,
     * shuning uchun xato to'planmaydi — har bo'limning o'z hajmigina hisoblanadi.
     */
    private static function estimatePages(array $sections): array
    {
        $planLines = 3;
        foreach ($sections as $sct) {
            $planLines += 1.6;
            foreach (self::blocks((string)$sct['text']) as $bl) {
                $planLines += $bl['type'] === 'h2' ? 1.4 : 0;
            }
        }
        $page = 2 + (int)floor($planLines / self::LINES_PER_PAGE) + 1;
        $out = ['sec' => [], 'h2' => []];
        foreach ($sections as $i => $s) {
            $out['sec'][$i] = $page;
            $lines = 3.0 + ceil(mb_strlen(self::headingText((string)$s['heading'])) / 40) * 1.2;      // sarlavha + bo'shliqlar
            $k = 0;
            foreach (self::blocks((string)$s['text']) as $b) {
                if ($b['type'] === 'h2') {
                    $out['h2'][$i][$k++] = $page + (int)floor(($lines + 1) / self::LINES_PER_PAGE);
                    $lines += 2.4;
                    continue;
                }
                if ($b['type'] === 'img') {
                    $lines += 15;
                    continue;
                }
                if ($b['type'] === 'table') {
                    $lines += 2 + count($b['rows']) * 1.7;
                    continue;
                }
                $len = mb_strlen($b['text']) + ($b['type'] === 'p' ? 8 : 6);
                $lines += max(1, ceil($len / self::CHARS_PER_LINE)) + ($b['type'] === 'p' ? 0.15 : 0.1);
            }
            $page += max(1, (int)ceil($lines / self::LINES_PER_PAGE));
        }
        return $out;
    }

    private static function planPage(array $sections, array $pages, string $lang): string
    {
        $xml = self::p(self::run(rtrim(self::upper(I18n::t('plan_upper', $lang)), ':'), ['b' => true, 'sz' => 32, 'color' => self::INK, 'spacing' => 30]),
            '<w:pStyle w:val="Title"/>');
        foreach ($sections as $i => $s) {
            $text = self::headingText((string)$s['heading']);
            $chapter = self::isChapter((string)$s['heading']);
            $bm = '_Toc' . (100000 + $i);
            $pg = (string)($pages['sec'][$i] ?? '');
            $runs = self::run($text, ['b' => !$chapter, 'sz' => 28])
                . '<w:r><w:rPr><w:sz w:val="28"/></w:rPr><w:tab/></w:r>'
                . '<w:r><w:rPr><w:sz w:val="28"/></w:rPr><w:fldChar w:fldCharType="begin"/></w:r>'
                . '<w:r><w:rPr><w:sz w:val="28"/></w:rPr><w:instrText xml:space="preserve"> PAGEREF ' . $bm . ' \h </w:instrText></w:r>'
                . '<w:r><w:rPr><w:sz w:val="28"/></w:rPr><w:fldChar w:fldCharType="separate"/></w:r>'
                . '<w:r><w:rPr><w:sz w:val="28"/></w:rPr><w:t>' . $pg . '</w:t></w:r>'
                . '<w:r><w:rPr><w:sz w:val="28"/></w:rPr><w:fldChar w:fldCharType="end"/></w:r>';
            $xml .= self::p($runs, '<w:pStyle w:val="TOC1"/>' . ($chapter ? '<w:ind w:left="0" w:right="567" w:hanging="0"/>' : ''));
            $k = 0;
            foreach (self::blocks((string)$s['text']) as $b) {           // bandlar (1.1, 1.2 …) — ichkariroqda
                if ($b['type'] !== 'h2') {
                    continue;
                }
                $bm2 = '_Toc' . (200000 + $i * 100 + $k);
                $pg2 = (string)($pages['h2'][$i][$k] ?? $pg);
                $k++;
                $xml .= self::p(self::run($b['text'], ['sz' => 26])
                    . '<w:r><w:rPr><w:sz w:val="26"/></w:rPr><w:tab/></w:r>'
                    . '<w:r><w:rPr><w:sz w:val="26"/></w:rPr><w:fldChar w:fldCharType="begin"/></w:r>'
                    . '<w:r><w:rPr><w:sz w:val="26"/></w:rPr><w:instrText xml:space="preserve"> PAGEREF ' . $bm2 . ' \\h </w:instrText></w:r>'
                    . '<w:r><w:rPr><w:sz w:val="26"/></w:rPr><w:fldChar w:fldCharType="separate"/></w:r>'
                    . '<w:r><w:rPr><w:sz w:val="26"/></w:rPr><w:t>' . $pg2 . '</w:t></w:r>'
                    . '<w:r><w:rPr><w:sz w:val="26"/></w:rPr><w:fldChar w:fldCharType="end"/></w:r>', '<w:pStyle w:val="TOC2"/>');
            }
        }
        return $xml;
    }

    // ================================================================== matn

    /**
     * Matnni bloklarga ajratadi: oddiy abzats (p), belgili ro'yxat (ul), raqamli ro'yxat (ol), kichik sarlavha (h2), eslatma (note).
     * @return array<int,array{type:string,text:string}>
     */
    private static function blocks(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $out = [];
        $rows = null;                                   // yig'ilayotgan jadval qatorlari
        $flush = function () use (&$rows, &$out) {
            if ($rows !== null) {
                if (count($rows) >= 2) {
                    $out[] = ['type' => 'table', 'text' => '', 'rows' => $rows];
                } else {
                    foreach ($rows as $r) {
                        $out[] = ['type' => 'p', 'text' => implode(' — ', $r)];
                    }
                }
                $rows = null;
            }
        };
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                $flush();
                continue;
            }
            if (str_starts_with($line, '|')) {          // jadval qatori: | a | b |
                if (preg_match('/^\|[\s:\-|]+\|?$/', $line)) {
                    continue;                           // |---|---| ajratuvchi
                }
                $cells = array_map('trim', explode('|', trim($line, '|')));
                $rows = $rows ?? [];
                if (count($rows) < 12 && count($cells) <= 6) {
                    $rows[] = $cells;
                }
                continue;
            }
            $flush();
            if (preg_match('/^\[(?:RASM|РИСУНОК|FIGURE)\s*(\d+)\s*:\s*(.{0,200})\]$/iu', $line, $m)) {
                $out[] = ['type' => 'img', 'n' => (int)$m[1], 'text' => trim($m[2])];
            } elseif (preg_match('/^(JADVAL|ТАБЛИЦА|TABLE)\s*\d*\s*[:.]\s*(.{2,200})$/iu', $line, $m)) {
                $out[] = ['type' => 'tcap', 'text' => trim($m[2])];
            } elseif (preg_match('/^[•●▪◦\-–—*]\s+(.+)$/u', $line, $m)) {
                $out[] = ['type' => 'ul', 'text' => trim($m[1])];
            } elseif (preg_match('/^(\d+\.\d+\.?)\s+(.{3,140})$/u', $line, $m) && !preg_match('/[.!?…:;]$/u', $line)) {
                $out[] = ['type' => 'h2', 'text' => rtrim($m[1], '.') . '. ' . trim($m[2])];
            } elseif (preg_match('/^\d{1,2}[\.\)]\s+(.+)$/u', $line, $m) && mb_strlen($line) < 400) {
                $out[] = ['type' => 'ol', 'text' => trim($m[1])];
            } elseif (preg_match('/^(Eslatma|Izoh|Примечание|Note)\s*:/iu', $line)) {
                $out[] = ['type' => 'note', 'text' => $line];
            } else {
                $out[] = ['type' => 'p', 'text' => $line];
            }
        }
        $flush();
        return $out;
    }

    private static function isLabel(string $heading, string $key): bool
    {
        $h = mb_strtolower(trim($heading));
        foreach (['uz', 'ru', 'en'] as $l) {
            if ($h === mb_strtolower(I18n::t($key, $l))) {
                return true;
            }
        }
        return false;
    }

    private static function tableXml(array $rows): string
    {
        $cols = max(array_map('count', $rows));
        $total = self::$article ? 9600 : 9300;
        $cw = (int)floor($total / $cols);
        $b = '<w:tblBorders>';
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $e) {
            $b .= '<w:' . $e . ' w:val="single" w:sz="6" w:space="0" w:color="8A94A8"/>';
        }
        $b .= '</w:tblBorders>';
        $x = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:jc w:val="center"/>' . $b
            . '<w:tblCellMar><w:top w:w="60" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:bottom w:w="60" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar>'
            . '<w:tblLook w:val="04A0" w:firstRow="1" w:lastRow="0" w:firstColumn="1" w:lastColumn="0" w:noHBand="0" w:noVBand="1"/></w:tblPr><w:tblGrid>';
        for ($c = 0; $c < $cols; $c++) {
            $x .= '<w:gridCol w:w="' . $cw . '"/>';
        }
        $x .= '</w:tblGrid>';
        foreach ($rows as $r => $cells) {
            $head = $r === 0;
            $x .= '<w:tr>' . ($head ? '<w:trPr><w:tblHeader/></w:trPr>' : '');
            for ($c = 0; $c < $cols; $c++) {
                $t = (string)($cells[$c] ?? '');
                $x .= '<w:tc><w:tcPr><w:tcW w:w="' . $cw . '" w:type="dxa"/>' . ($head ? '<w:shd w:val="clear" w:color="auto" w:fill="E6ECF5"/>' : '') . '</w:tcPr>'
                    . self::p(self::run($t, ['b' => $head, 'sz' => 24]), self::sp(0, 0, 252) . '<w:ind w:firstLine="0"/><w:jc w:val="' . ($head ? 'center' : 'left') . '"/>')
                    . '</w:tc>';
            }
            $x .= '</w:tr>';
        }
        return $x . '</w:tbl>' . self::p('', self::sp(0, 120, 240) . '<w:ind w:firstLine="0"/>');
    }

    private static function capLabel(string $kind, int $n): string
    {
        return match (self::$lang) {
            'ru' => ($kind === 'fig' ? 'Рис. ' : 'Таблица ') . $n,
            'en' => ($kind === 'fig' ? 'Figure ' : 'Table ') . $n,
            default => $n . '-' . ($kind === 'fig' ? 'rasm' : 'jadval'),
        };
    }

    private static function imageXml(int $n, string $caption): string
    {
        $m = self::$media[$n] ?? null;
        if (!$m) {
            return '';
        }
        self::$media[$n]['used'] = true;
        self::$figN++;
        $id = ++self::$drawId;
        $draw = '<w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $m['cx'] . '" cy="' . $m['cy'] . '"/>'
            . '<wp:docPr id="' . $id . '" name="Rasm ' . $id . '"/><wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="' . $id . '" name="' . $m['name'] . '"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $m['rid'] . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $m['cx'] . '" cy="' . $m['cy'] . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
            . '</a:graphicData></a:graphic></wp:inline></w:drawing>';
        $ctr = '<w:ind w:firstLine="0"/><w:jc w:val="center"/>';
        return self::p('<w:r>' . $draw . '</w:r>', '<w:keepNext/>' . self::sp(160, 60, 240) . $ctr)
            . self::p(self::run(self::capLabel('fig', self::$figN) . '. ', ['b' => true, 'sz' => 24]) . self::run($caption, ['i' => true, 'sz' => 24]), self::sp(0, 200, 252) . $ctr);
    }

    private static function sectionXml(array $section, int $i, string $lang, array $h2Pages): string
    {
        $raw = (string)$section['heading'];
        $heading = self::headingText($raw);
        $chapter = self::isChapter($raw);
        $biblio = self::isBiblio($raw, $lang);
        $text = (string)$section['text'];
        $xml = '';

        if (self::$article) {
            // annotatsiya / kalit so'zlar — sarlavhasiz, ichkariga surilgan kichik blok
            if (self::isLabel($raw, 'annotation') || self::isLabel($raw, 'keywords')) {
                $isKw = self::isLabel($raw, 'keywords');
                return self::p(self::run($heading . ($isKw ? ': ' : '. '), ['b' => true, 'sz' => 24]) . self::run(preg_replace('/\s+/u', ' ', trim($text)) ?? '', ['i' => true, 'sz' => 24]),
                    self::sp(0, $isKw ? 280 : 100, 264) . '<w:ind w:left="567" w:right="567" w:firstLine="0"/><w:jc w:val="both"/>');
            }
            if ($raw !== '—' && $raw !== '') {
                $xml .= self::p(self::run($chapter ? $heading : self::upper($heading)), '<w:pStyle w:val="ArtHeading"/>');
            }
        } else {
            $shown = $chapter ? $heading : self::upper($heading);
            $bm = '_Toc' . (100000 + $i);
            $xml = '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr>'
                . '<w:bookmarkStart w:id="' . ($i + 1) . '" w:name="' . $bm . '"/>'
                . self::run($shown)
                . '<w:bookmarkEnd w:id="' . ($i + 1) . '"/></w:p>';
        }

        $olNum = $biblio ? 2 : 3;         // adabiyotlar — alohida raqamlash (1., 2., ...)
        $k = 0;
        $pendingCap = null;
        foreach (self::blocks($text) as $b) {
            if ($pendingCap !== null && $b['type'] !== 'table') {
                $xml .= self::p(self::inline($pendingCap));          // «JADVAL:» dan keyin jadval kelmadi — oddiy abzats
                $pendingCap = null;
            }
            switch ($b['type']) {
                case 'ul':
                    $xml .= self::p(self::inline($b['text']), '<w:pStyle w:val="ListParagraph"/><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>');
                    break;
                case 'ol':
                    $xml .= self::p(self::inline($b['text']), '<w:pStyle w:val="ListParagraph"/><w:numPr><w:ilvl w:val="0"/><w:numId w:val="' . $olNum . '"/></w:numPr>');
                    break;
                case 'h2':
                    $id = 1000 + $i * 100 + $k;
                    $bm2 = '_Toc' . (200000 + $i * 100 + $k);
                    $k++;
                    $xml .= self::$article
                        ? self::p(self::run($b['text']), '<w:pStyle w:val="Heading2"/>')
                        : '<w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:bookmarkStart w:id="' . $id . '" w:name="' . $bm2 . '"/>' . self::run($b['text']) . '<w:bookmarkEnd w:id="' . $id . '"/></w:p>';
                    break;
                case 'note':
                    $xml .= self::p(self::run($b['text'], ['i' => true, 'sz' => 24, 'color' => '4B5563']), '<w:pStyle w:val="Note"/>');
                    break;
                case 'tcap':
                    $pendingCap = $b['text'];
                    break;
                case 'table':
                    self::$tabN++;
                    $xml .= self::p(self::run(self::capLabel('tab', self::$tabN), ['i' => true, 'sz' => 24]), '<w:keepNext/>' . self::sp(120, 0, 240) . '<w:ind w:firstLine="0"/><w:jc w:val="right"/>');
                    if ($pendingCap !== null) {
                        $xml .= self::p(self::run($pendingCap, ['b' => true, 'sz' => 26]), '<w:keepNext/>' . self::sp(0, 80, 252) . '<w:ind w:firstLine="0"/><w:jc w:val="center"/>');
                        $pendingCap = null;
                    }
                    $xml .= self::tableXml($b['rows']);
                    break;
                case 'img':
                    $xml .= self::imageXml((int)$b['n'], $b['text']);
                    break;
                default:
                    $xml .= self::p(self::inline($b['text']));
            }
        }
        if ($pendingCap !== null) {
            $xml .= self::p(self::inline($pendingCap));
        }
        return $xml;
    }

    /** Qator ichidagi «Atama — izoh» yoki «Atama: izoh» boshlanishini qalin qiladi (ro'yxatlarda chiroyli ko'rinadi). */
    private static function inline(string $t): string
    {
        if (preg_match('/^([^.!?:—–]{2,60}?)(\s[—–]\s|:\s)(.+)$/u', $t, $m) && mb_strlen($m[1]) <= 60 && count(preg_split('/\s+/u', trim($m[1]))) <= 6) {
            return self::run($m[1], ['b' => true]) . self::run($m[2] . $m[3]);
        }
        return self::run($t);
    }

    // ================================================================== paketning qolgan qismlari

    private static function documentTemplate(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            . '<w:body>' . $body
            . '<w:sectPr>'
            . '<w:footerReference w:type="default" r:id="rIdFooter"/>'
            . '<w:pgSz w:w="11906" w:h="16838"/>'
            . (self::$article
                ? '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/>'
                : '<w:pgMar w:top="1134" w:right="851" w:bottom="1134" w:left="1701" w:header="567" w:footer="567" w:gutter="0"/><w:pgNumType w:start="2"/>')
            . '</w:sectPr></w:body></w:document>';
    }

    private static function stylesXml(string $lang): string
    {
        $F = self::FONT;
        $L = ['uz' => 'uz-Latn-UZ', 'ru' => 'ru-RU', 'en' => 'en-US'][$lang] ?? 'uz-Latn-UZ';
        $fonts = '<w:rFonts w:ascii="' . $F . '" w:hAnsi="' . $F . '" w:cs="' . $F . '" w:eastAsia="' . $F . '"/>';
        $ink = self::INK;
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr>' . $fonts . '<w:sz w:val="28"/><w:szCs w:val="28"/><w:lang w:val="' . $L . '" w:eastAsia="' . $L . '" w:bidi="ar-SA"/></w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:widowControl/><w:spacing w:after="0" w:line="360" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            // Oddiy matn
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/>'
            . '<w:pPr><w:widowControl/><w:spacing w:after="0" w:line="' . (self::$article ? 276 : 360) . '" w:lineRule="auto"/><w:ind w:firstLine="709"/><w:jc w:val="both"/></w:pPr>'
            . '<w:rPr>' . $fonts . '<w:sz w:val="28"/><w:szCs w:val="28"/></w:rPr></w:style>'
            // Asosiy bo'lim sarlavhasi — har doim yangi betdan, markazda
            . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
            . '<w:pPr><w:keepNext/><w:keepLines/><w:pageBreakBefore/><w:spacing w:before="0" w:after="360" w:line="276" w:lineRule="auto"/><w:ind w:left="567" w:right="567" w:firstLine="0"/><w:jc w:val="center"/><w:outlineLvl w:val="0"/></w:pPr>'
            . '<w:rPr><w:b/><w:bCs/><w:color w:val="' . $ink . '"/><w:sz w:val="32"/><w:szCs w:val="32"/></w:rPr></w:style>'
            // Kichik sarlavha (1.1. ...)
            . '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
            . '<w:pPr><w:keepNext/><w:keepLines/><w:spacing w:before="240" w:after="120" w:line="276" w:lineRule="auto"/><w:ind w:firstLine="709"/><w:jc w:val="left"/><w:outlineLvl w:val="1"/></w:pPr>'
            . '<w:rPr><w:b/><w:bCs/><w:color w:val="' . $ink . '"/></w:rPr></w:style>'
            // Reja sarlavhasi
            . '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
            . '<w:pPr><w:keepNext/><w:spacing w:before="0" w:after="480" w:line="240" w:lineRule="auto"/><w:ind w:firstLine="0"/><w:jc w:val="center"/></w:pPr>'
            . '<w:rPr><w:b/><w:bCs/><w:sz w:val="32"/><w:szCs w:val="32"/></w:rPr></w:style>'
            // Reja qatorlari: nuqtali tab + bet raqami o'ngda
            . '<w:style w:type="paragraph" w:styleId="TOC1"><w:name w:val="toc 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/>'
            . '<w:pPr><w:tabs><w:tab w:val="right" w:leader="dot" w:pos="9344"/></w:tabs><w:spacing w:before="0" w:after="140" w:line="300" w:lineRule="auto"/><w:ind w:left="0" w:right="567" w:firstLine="0"/><w:jc w:val="left"/></w:pPr></w:style>'
            // Reja: bandlar (1.1, 1.2) — ichkariroqda
            . '<w:style w:type="paragraph" w:styleId="TOC2"><w:name w:val="toc 2"/><w:basedOn w:val="TOC1"/><w:next w:val="Normal"/>'
            . '<w:pPr><w:spacing w:before="0" w:after="100" w:line="276" w:lineRule="auto"/><w:ind w:left="567" w:right="567" w:firstLine="0"/></w:pPr></w:style>'
            // Maqola bo'lim sarlavhasi (yangi betdan emas)
            . '<w:style w:type="paragraph" w:styleId="ArtHeading"><w:name w:val="Article Heading"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
            . '<w:pPr><w:keepNext/><w:keepLines/><w:spacing w:before="240" w:after="120" w:line="264" w:lineRule="auto"/><w:ind w:firstLine="0"/><w:jc w:val="center"/><w:outlineLvl w:val="0"/></w:pPr>'
            . '<w:rPr><w:b/><w:bCs/><w:color w:val="' . $ink . '"/></w:rPr></w:style>'
            // Ro'yxat
            . '<w:style w:type="paragraph" w:styleId="ListParagraph"><w:name w:val="List Paragraph"/><w:basedOn w:val="Normal"/><w:qFormat/>'
            . '<w:pPr><w:spacing w:after="60"/><w:ind w:left="1066" w:hanging="357"/><w:contextualSpacing/><w:jc w:val="left"/></w:pPr></w:style>'
            // Eslatma
            . '<w:style w:type="paragraph" w:styleId="Note"><w:name w:val="Note"/><w:basedOn w:val="Normal"/>'
            . '<w:pPr><w:pBdr><w:left w:val="single" w:sz="18" w:space="8" w:color="' . self::ACCENT . '"/></w:pBdr><w:shd w:val="clear" w:color="auto" w:fill="F1F4F9"/><w:spacing w:before="240" w:after="0" w:line="300" w:lineRule="auto"/><w:ind w:left="284" w:right="284" w:firstLine="0"/></w:pPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Footer"><w:name w:val="footer"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:line="240" w:lineRule="auto"/><w:ind w:firstLine="0"/><w:jc w:val="center"/></w:pPr><w:rPr><w:sz w:val="24"/></w:rPr></w:style>'
            . '<w:style w:type="character" w:default="1" w:styleId="DefaultParagraphFont"><w:name w:val="Default Paragraph Font"/><w:uiPriority w:val="1"/><w:semiHidden/></w:style>'
            . '</w:styles>';
    }

    private static function settingsXml(string $lang): string
    {
        $L = ['uz' => 'uz-Latn-UZ', 'ru' => 'ru-RU', 'en' => 'en-US'][$lang] ?? 'uz-Latn-UZ';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:zoom w:percent="100"/><w:defaultTabStop w:val="709"/><w:characterSpacingControl w:val="doNotCompress"/>'
            . '<w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat>'
            . '<w:themeFontLang w:val="' . $L . '"/>'          // CT_Settings tartibi: compat'dan keyin (Word qat'iy tekshiradi)
            . '</w:settings>';
    }

    private static function numberingXml(): string
    {
        $lvl = fn(string $fmt, string $text, string $font = '') => '<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="' . $fmt . '"/><w:lvlText w:val="' . $text . '"/><w:lvlJc w:val="left"/>'
            . '<w:pPr>' . ($fmt === 'bullet' ? '<w:ind w:left="1066" w:hanging="357"/>' : '<w:ind w:left="1134" w:hanging="454"/>') . '</w:pPr>' . ($font !== '' ? '<w:rPr><w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '" w:hint="default"/></w:rPr>' : '') . '</w:lvl>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:abstractNum w:abstractNumId="0"><w:multiLevelType w:val="singleLevel"/>' . $lvl('bullet', '•', 'Times New Roman') . '</w:abstractNum>'
            . '<w:abstractNum w:abstractNumId="1"><w:multiLevelType w:val="singleLevel"/>' . $lvl('decimal', '%1.') . '</w:abstractNum>'
            . '<w:abstractNum w:abstractNumId="2"><w:multiLevelType w:val="singleLevel"/>' . $lvl('decimal', '%1)') . '</w:abstractNum>'
            . '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>'
            . '<w:num w:numId="2"><w:abstractNumId w:val="1"/></w:num>'
            . '<w:num w:numId="3"><w:abstractNumId w:val="2"/></w:num>'
            . '</w:numbering>';
    }

    private static function fontTableXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:fonts xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:font w:name="Times New Roman"><w:panose1 w:val="02020603050405020304"/><w:charset w:val="CC"/><w:family w:val="roman"/><w:pitch w:val="variable"/></w:font>'
            . '</w:fonts>';
    }

    /** Bet raqami — pastda markazda. */
    private static function footerXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:p><w:pPr><w:pStyle w:val="Footer"/></w:pPr>'
            . '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r>'
            . '<w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>2</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r>'
            . '</w:p></w:ftr>';
    }

    /** Titul varag'ida bet raqami ko'rsatilmaydi. */
    private static function emptyFooterXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:pPr><w:pStyle w:val="Footer"/></w:pPr></w:p></w:ftr>';
    }

    private static function coreXml(string $topic, string $author, string $subject, string $lang): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . self::escape(mb_substr($topic, 0, 250)) . '</dc:title>'
            . '<dc:subject>' . self::escape($subject) . '</dc:subject>'
            . '<dc:creator>' . self::escape($author) . '</dc:creator>'
            . '<cp:lastModifiedBy>' . self::escape($author) . '</cp:lastModifiedBy>'
            . '<dc:language>' . self::escape($lang) . '</dc:language>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private static function appXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Microsoft Office Word</Application></Properties>';
    }

    private static function contentTypesXml(): string
    {
        $wml = 'application/vnd.openxmlformats-officedocument.wordprocessingml.';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="jpg" ContentType="image/jpeg"/><Default Extension="png" ContentType="image/png"/>'
            . '<Override PartName="/word/document.xml" ContentType="' . $wml . 'document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="' . $wml . 'styles+xml"/>'
            . '<Override PartName="/word/settings.xml" ContentType="' . $wml . 'settings+xml"/>'
            . '<Override PartName="/word/numbering.xml" ContentType="' . $wml . 'numbering+xml"/>'
            . '<Override PartName="/word/fontTable.xml" ContentType="' . $wml . 'fontTable+xml"/>'
            . '<Override PartName="/word/footer1.xml" ContentType="' . $wml . 'footer+xml"/>'
            . '<Override PartName="/word/footer2.xml" ContentType="' . $wml . 'footer+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private static function relsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private static function documentRelsXml(): string
    {
        $R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rIdStyles" Type="' . $R . 'styles" Target="styles.xml"/>'
            . '<Relationship Id="rIdSettings" Type="' . $R . 'settings" Target="settings.xml"/>'
            . '<Relationship Id="rIdNumbering" Type="' . $R . 'numbering" Target="numbering.xml"/>'
            . '<Relationship Id="rIdFonts" Type="' . $R . 'fontTable" Target="fontTable.xml"/>'
            . '<Relationship Id="rIdFooter" Type="' . $R . 'footer" Target="footer1.xml"/>'
            . '<Relationship Id="rIdFooterFirst" Type="' . $R . 'footer" Target="footer2.xml"/>'
            . implode('', array_map(fn($m) => !empty($m['used']) ? '<Relationship Id="' . $m['rid'] . '" Type="' . $R . 'image" Target="media/' . $m['name'] . '"/>' : '', self::$media))
            . '</Relationships>';
    }
}
