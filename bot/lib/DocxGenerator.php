<?php

require_once __DIR__ . '/I18n.php';

class DocxGenerator
{
    // Andoza: Times New Roman, 14pt asosiy matn, 1.5 interval, abzats bilan tekislangan (justify),
    // birinchi qator abzats bilan (0.5 duym), bet raqami footer'da — odatiy universitet talablariga mos.
    private const FONT = 'Times New Roman';

    /**
     * $sections = [ ['heading' => '1. Kirish', 'text' => '...'], ... ]
     * $studentInfo = ['full_name' => '...', 'university' => '...', 'group_name' => '...']
     * Natija: to'liq fayl yo'li (.docx)
     *
     * Hujjat andozasi (O'zbekiston OTMlarida keng qo'llaniladigan standart tartib):
     *   1-bet: Titul varag'i (universitet, "MUSTAQIL ISH", mavzu, bajaruvchi F.I.Sh/guruh, shahar-yil)
     *   2-bet: Reja (barcha bo'limlar ro'yxati)
     *   3-bet va keyingisi: Kirish → boblar → Xulosa → Foydalanilgan adabiyotlar
     */
    /**
     * Foydalanilgan adabiyotlar bo'limini (agar hali yo'q bo'lsa) oxiriga qo'shadi. Aniq soxta kitob/muallif o'ylab topilmaydi —
     * umumiy, halol matn. Tahrirlovchi shu ro'yxatni (adabiyotlar bilan birga) saqlaydi, shuning uchun alohida ochiq.
     */
    public static function withBiblio(array $sections, string $lang = 'uz'): array
    {
        $lang = I18n::norm($lang);
        $stripos = function_exists('mb_stripos') ? 'mb_stripos' : 'stripos';
        foreach ($sections as $s) {
            if ($stripos((string)$s['heading'], I18n::t('biblio_match', $lang)) !== false) {
                return $sections;
            }
        }
        $sections[] = ['heading' => I18n::t('biblio', $lang), 'text' => I18n::t('biblio_text', $lang)];
        return $sections;
    }

    public static function generate(string $topic, array $sections, array $studentInfo = [], string $docType = 'MUSTAQIL ISH', string $lang = 'uz', bool $autoBiblio = true): string
    {
        $lang = I18n::norm($lang);
        if ($autoBiblio) {
            $sections = self::withBiblio($sections, $lang);
        }

        $filename = ($docType === 'REFERAT' ? 'referat_' : 'mustaqil_ish_') . uniqid() . '.docx';
        $path = GENERATED_DIR . $filename;

        $bodyXml = self::titlePage($topic, $studentInfo, $docType, $lang);
        $bodyXml .= self::rejaPage($sections, $lang);

        foreach ($sections as $section) {
            $bodyXml .= self::headingParagraph($section['heading']);
            foreach (self::splitParagraphs($section['text']) as $para) {
                $bodyXml .= self::bodyParagraph($para);
            }
        }

        $documentXml = self::documentTemplate($bodyXml);

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('DOCX fayl yaratib bo\'lmadi: ' . $path);
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypesXml());
        $zip->addFromString('_rels/.rels', self::relsXml());
        $zip->addFromString('word/_rels/document.xml.rels', self::documentRelsXml());
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->addFromString('word/footer1.xml', self::footerXml());
        $zip->addFromString('word/footer2.xml', self::emptyFooterXml());
        if (!$zip->close() || !is_file($path)) {
            throw new RuntimeException('DOCX faylni diskka yozib bo\'lmadi (papka mavjudmi va yozish ruxsati bormi?): ' . $path);
        }

        return $path;
    }

    private static function splitParagraphs(string $text): array
    {
        $parts = preg_split('/\n{1,}/', trim($text));
        return array_filter(array_map('trim', $parts));
    }

    private static function escape(string $text): string
    {
        // XML 1.0 da ruxsat etilmagan boshqaruv belgilari (AI matnida uchrab qoladi) Word'da "fayl buzilgan" xatosini beradi
        $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function rFonts(): string
    {
        return '<w:rFonts w:ascii="' . self::FONT . '" w:hAnsi="' . self::FONT . '" w:cs="' . self::FONT . '"/>';
    }

    private static function pageBreak(): string
    {
        return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
    }

    private static function centeredLine(string $text, int $size = 24, bool $bold = false, int $spacingAfter = 120): string
    {
        $text = self::escape($text);
        $b = $bold ? '<w:b/>' : '';
        return '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="' . $spacingAfter . '"/></w:pPr>'
            . '<w:r><w:rPr>' . self::rFonts() . $b . '<w:sz w:val="' . $size . '"/></w:rPr>'
            . '<w:t xml:space="preserve">' . $text . '</w:t></w:r></w:p>';
    }

    private static function rightLine(string $text, int $size = 24): string
    {
        $text = self::escape($text);
        return '<w:p><w:pPr><w:jc w:val="right"/><w:spacing w:after="80"/></w:pPr>'
            . '<w:r><w:rPr>' . self::rFonts() . '<w:sz w:val="' . $size . '"/></w:rPr>'
            . '<w:t xml:space="preserve">' . $text . '</w:t></w:r></w:p>';
    }

    private static function emptyLine(): string
    {
        return '<w:p><w:r>' . self::rFonts() . '<w:t></w:t></w:r></w:p>';
    }

    /**
     * Titul varag'i — universitet, "MUSTAQIL ISH", mavzu, bajaruvchi (F.I.Sh, guruh),
     * shahar va yil. O'zbekiston OTMlarida qabul qilingan standart tartibda joylashtirilgan:
     * yuqorida universitet, o'rtada ish turi va mavzu, pastda o'ngga surilgan bajaruvchi
     * ma'lumotlari, eng pastda shahar-yil.
     */
    private static function titlePage(string $topic, array $studentInfo, string $docType = 'MUSTAQIL ISH', string $lang = 'uz'): string
    {
        $university = $studentInfo['university'] ?? "________________________";
        $fullName = $studentInfo['full_name'] ?? "________________________";
        $group = $studentInfo['group_name'] ?? "________";

        $xml = '';
        $ministry = I18n::ministry($lang);
        if ($ministry !== '') {
            $xml .= self::centeredLine($ministry, 24, true, 200);
        }
        $xml .= self::centeredLine((function_exists('mb_strtoupper') ? mb_strtoupper($university) : strtoupper($university)), 26, true, 600);
        $xml .= self::emptyLine();
        $xml .= self::emptyLine();
        $xml .= self::emptyLine();
        $xml .= self::centeredLine(I18n::t('doc_' . $docType, $lang), 36, true, 400);
        $xml .= self::centeredLine(I18n::t('topic', $lang) . ': "' . $topic . '"', 28, false, 800);
        $xml .= self::emptyLine();
        $xml .= self::emptyLine();
        $xml .= self::emptyLine();
        $xml .= self::emptyLine();
        $xml .= self::rightLine(I18n::t('executor', $lang) . ': ' . $fullName, 26);
        $xml .= self::rightLine(I18n::t('group', $lang) . ': ' . $group, 26);
        $xml .= self::emptyLine();
        $xml .= self::emptyLine();
        $xml .= self::emptyLine();
        $xml .= self::emptyLine();
        $xml .= self::centeredLine(self::academicYearLabel($lang), 24, false, 0);
        $xml .= self::pageBreak();
        return $xml;
    }

    /**
     * "2025-2026 o'quv yili" formatida — O'zbekiston OTMlarida standart. O'quv yili
     * sentyabrdan boshlanadi deb hisoblanadi (sentyabr-dekabr = joriy yil boshlanishi).
     */
    private static function academicYearLabel(string $lang = 'uz'): string
    {
        $year = (int)date('Y');
        $month = (int)date('n');
        $start = $month >= 9 ? $year : $year - 1;
        return $start . '-' . ($start + 1) . ' ' . I18n::t('year_suffix', $lang);
    }

    /**
     * Reja — hujjatning barcha bo'limlari ro'yxati (Kirish, boblar, Xulosa,
     * Foydalanilgan adabiyotlar). AI tomonidan yaratilgan bob sarlavhalari
     * avtomatik shu yerga tushadi — alohida so'rashning hojati yo'q.
     */
    private static function rejaPage(array $sections, string $lang = 'uz'): string
    {
        $xml = self::headingParagraph(I18n::t('plan_upper', $lang));
        $n = 1;
        foreach ($sections as $section) {
            // AI ba'zan sarlavhaga o'zi "1. " kabi raqam qo'shib yuboradi — Reja'da
            // qo'sh raqamlanish ("1. 1. ...") bo'lmasligi uchun uni olib tashlaymiz.
            $heading = preg_replace('/^\d+[\.\)]\s*/', '', $section['heading']);
            $xml .= self::rejaItem($n . '. ' . $heading);
            $n++;
        }
        $xml .= self::pageBreak();
        return $xml;
    }

    private static function rejaItem(string $text): string
    {
        $text = self::escape($text);
        return '<w:p><w:pPr><w:spacing w:line="360" w:lineRule="auto" w:after="80"/><w:ind w:left="360"/></w:pPr>'
            . '<w:r><w:rPr>' . self::rFonts() . '<w:sz w:val="28"/></w:rPr>'
            . '<w:t xml:space="preserve">' . $text . '</w:t></w:r></w:p>';
    }

    private static function headingParagraph(string $text): string
    {
        $text = self::escape($text);
        return '<w:p><w:pPr><w:spacing w:before="280" w:after="200"/></w:pPr>'
            . '<w:r><w:rPr>' . self::rFonts() . '<w:b/><w:sz w:val="28"/></w:rPr>'
            . '<w:t xml:space="preserve">' . $text . '</w:t></w:r></w:p>';
    }

    /**
     * Asosiy matn abzatsi: 1.5 interval, ikki tomonlama tekislash (justify),
     * birinchi qatorda abzats chekinishi — standart akademik hujjat ko'rinishi.
     */
    private static function bodyParagraph(string $text): string
    {
        $text = self::escape($text);
        return '<w:p><w:pPr>'
            . '<w:spacing w:line="360" w:lineRule="auto" w:after="160"/>'
            . '<w:ind w:firstLine="720"/>'
            . '<w:jc w:val="both"/>'
            . '</w:pPr>'
            . '<w:r><w:rPr>' . self::rFonts() . '<w:sz w:val="28"/></w:rPr>'
            . '<w:t xml:space="preserve">' . $text . '</w:t></w:r></w:p>';
    }

    private static function documentTemplate(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
    xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<w:body>' . $body . '<w:sectPr>
<w:footerReference w:type="first" r:id="rIdFooterFirst"/>
<w:footerReference w:type="default" r:id="rIdFooter"/>
<w:pgSz w:w="11906" w:h="16838"/>
<w:pgMar w:top="1417" w:right="1133" w:bottom="1417" w:left="1701" w:footer="708"/>
<w:titlePg/>
</w:sectPr>
</w:body>
</w:document>';
    }

    /**
     * Sahifa raqami — pastki qismda, markazda. Universitet talablarining
     * deyarli barchasida bet raqami majburiy bo'lgani uchun andozaga qo'shildi.
     */
    private static function footerXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
<w:p><w:pPr><w:jc w:val="center"/></w:pPr>
<w:fldSimple w:instr=" PAGE "><w:r><w:rPr>' . self::rFonts() . '<w:sz w:val="24"/></w:rPr><w:t>1</w:t></w:r></w:fldSimple>
</w:p>
</w:ftr>';
    }

    /**
     * Titul varag'ida (1-bet) sahifa raqami ko'RSATILMAYDI — bu deyarli barcha
     * universitet andozalarida qabul qilingan norma. w:titlePg + shu bo'sh
     * footer orqali erishiladi.
     */
    private static function emptyFooterXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
<w:p/>
</w:ftr>';
    }

    private static function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>
<Override PartName="/word/footer2.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>
</Types>';
    }

    private static function relsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>';
    }

    private static function documentRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rIdFooter" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>
<Relationship Id="rIdFooterFirst" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer2.xml"/>
</Relationships>';
    }
}
