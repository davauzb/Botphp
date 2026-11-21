<?php

require_once __DIR__ . '/I18n.php';

/**
 * «Himoya uchun konspekt» — taqdimot bilan birga yuboriladigan qisqa DOCX.
 * Har bir slayd: raqam + sarlavha, asosiy fikrlar (punktlar) va (bo'lsa) og'zaki nutq matni.
 * AI so'rovisiz, tayyor slayd matnidan yig'iladi — qo'shimcha token sarflanmaydi.
 * O'chirish: config.php → define('KONSPEKT_ENABLED', false);
 */
class SpeechDoc
{
    private const FONT = 'Times New Roman';
    private const INK = '1F2A44';
    private const ACC = '2F4A7A';

    private const L = [
        'uz' => ['qa_title' => 'HIMOYA SAVOLLARI', 'qa_sub' => 'Ehtimoliy savollar va qisqa javoblar', 'qa_tip' => 'Maslahat: javoblarni yodlamang — mazmunini tushunib, o‘z so‘zlaringiz bilan ayting. Har bir javobni 30–40 soniyada aytishni mashq qiling.', 'qa_head' => 'Ehtimoliy savollar va javoblar', 'title' => 'HIMOYA UCHUN KONSPEKT', 'sub' => 'Taqdimot matni va og‘zaki nutq', 'slide' => 'slayd', 'speech' => 'Nutq',
            'tip' => 'Maslahat: har slaydga 40–60 soniya ajrating. «Nutq» qismini yodlamang — o‘z so‘zlaringiz bilan gapiring, asosiy fikrlar esa slaydda ko‘rinib turadi.',
            'section' => 'Bo‘lim', 'cover' => 'Muqova', 'agenda' => 'Reja', 'end' => 'Yakun'],
        'ru' => ['qa_title' => 'ВОПРОСЫ НА ЗАЩИТЕ', 'qa_sub' => 'Возможные вопросы и краткие ответы', 'qa_tip' => 'Совет: не заучивайте ответы — поймите суть и отвечайте своими словами, 30–40 секунд на ответ.', 'qa_head' => 'Возможные вопросы и ответы', 'title' => 'КОНСПЕКТ ДЛЯ ЗАЩИТЫ', 'sub' => 'Текст презентации и устная речь', 'slide' => 'слайд', 'speech' => 'Речь',
            'tip' => 'Совет: уделяйте каждому слайду 40–60 секунд. Не заучивайте «Речь» наизусть — говорите своими словами, ключевые мысли видны на слайде.',
            'section' => 'Раздел', 'cover' => 'Титул', 'agenda' => 'План', 'end' => 'Завершение'],
        'en' => ['qa_title' => 'DEFENSE QUESTIONS', 'qa_sub' => 'Likely questions and short answers', 'qa_tip' => 'Tip: do not memorise — understand the idea and answer in your own words, 30–40 seconds each.', 'qa_head' => 'Likely questions and answers', 'title' => 'PRESENTATION SPEAKER NOTES', 'sub' => 'Slide content and talking points', 'slide' => 'slide', 'speech' => 'Speech',
            'tip' => 'Tip: spend 40–60 seconds per slide. Do not memorise the «Speech» part — say it in your own words; key points are on the slide.',
            'section' => 'Section', 'cover' => 'Cover', 'agenda' => 'Agenda', 'end' => 'Closing'],
    ];

    public static function enabled(): bool
    {
        return (bool)Engine::c('KONSPEKT_ENABLED', true) && class_exists('ZipArchive');
    }

    /** $final — JobSteps::stepSlideBuild dagi tayyor slaydlar ro'yxati. Qaytadi: .docx yo'li. */
    /** Faqat himoya savollari (hujjatlar uchun). */
    public static function qaDocx(string $topic, array $qa, string $lang = 'uz'): string
    {
        return self::generate([], $topic, $lang, $qa);
    }

    public static function generate(array $final, string $topic, string $lang = 'uz', array $qa = []): string
    {
        $lang = I18n::norm($lang);
        $L = self::L[$lang] ?? self::L['uz'];
        $total = count($final);
        $b = self::p(self::r($final ? $L['title'] : $L['qa_title'], true, 30, self::INK, 40), 'center', 0, 60);
        $b .= self::p(self::r('«' . $topic . '»', true, 26, self::INK), 'center', 0, 60);
        $b .= self::p(self::r($final ? $L['sub'] . ' · ' . $total . ' ' . $L['slide'] : $L['qa_sub'], false, 22, '6B7280', 0, true), 'center', 0, 200,
            '<w:pBdr><w:bottom w:val="single" w:sz="8" w:space="8" w:color="' . self::ACC . '"/></w:pBdr>');
        $b .= self::p(self::r($final ? $L['tip'] : $L['qa_tip'], false, 22, '374151', 0, true), 'both', 120, 200,
            '<w:pBdr><w:left w:val="single" w:sz="18" w:space="8" w:color="' . self::ACC . '"/></w:pBdr><w:shd w:val="clear" w:color="auto" w:fill="F1F4F9"/>', 284);

        foreach ($final as $i => $s) {
            $n = $i + 1;
            $kind = (string)($s['kind'] ?? 'matn');
            $title = trim((string)($s['title'] ?? ''));
            $label = match ($kind) {
                'title' => $L['cover'], 'reja' => $L['agenda'], 'thanks' => $L['end'],
                'bolim' => $L['section'] . ' ' . (int)($s['lead'] ?? 0), default => '',
            };
            $head = self::r($n . '-' . $L['slide'] . ($label !== '' ? ' · ' . $label : '') . '   ', true, 20, self::ACC, 20)
                . self::r($title, true, 26, self::INK);
            $b .= self::p($head, 'left', 260, 80, '', 0, true);

            $lines = [];
            $lead = trim((string)($s['lead'] ?? ''));
            if ($lead !== '' && $kind !== 'bolim') {
                $lines[] = self::p(self::r($lead, false, 24, '374151', 0, true), 'both', 0, 60);
            }
            foreach (($s['points'] ?? []) as $p) {
                $h = trim((string)($p['head'] ?? ''));
                $t = trim((string)($p['text'] ?? ''));
                if ($h === '' && $t === '') {
                    continue;
                }
                $lines[] = self::p(self::r('•  ', true, 24, self::ACC) . ($h !== '' ? self::r($h . ($t !== '' ? ' — ' : ''), true, 24) : '') . self::r($t, false, 24), 'both', 0, 40, '', 0, false, 360);
            }
            foreach (array_merge($s['items'] ?? [], $kind === 'reja' ? [] : ($s['bullets'] ?? [])) as $it) {
                $it = trim((string)$it);
                if ($it !== '') {
                    $lines[] = self::p(self::r('•  ', true, 24, self::ACC) . self::r($it, false, 24), 'left', 0, 40, '', 0, false, 360);
                }
            }
            $b .= implode('', $lines);
            $notes = trim((string)($s['notes'] ?? ''));
            if ($notes !== '') {
                $b .= self::p(self::r('🎤 ' . $L['speech'] . ': ', true, 24, self::ACC) . self::r($notes, false, 24, '1F2937', 0, true), 'both', 100, 60,
                    '<w:shd w:val="clear" w:color="auto" w:fill="F6F7FB"/>', 0);
            }
        }

        if ($qa) {
            $b .= self::p(self::r('🎓 ' . $L['qa_head'], true, 28, self::INK), 'left', 360, 120,
                '<w:pBdr><w:bottom w:val="single" w:sz="8" w:space="4" w:color="' . self::ACC . '"/></w:pBdr>', 0, true);
            foreach ($qa as $k => $x) {
                $b .= self::p(self::r(($k + 1) . '. ', true, 24, self::ACC) . self::r($x['q'], true, 24, self::INK), 'left', 200, 60, '', 0, true, 360);
                $b .= self::p(self::r($x['a'], false, 24, '1F2937'), 'both', 0, 60, '<w:shd w:val="clear" w:color="auto" w:fill="F6F7FB"/>', 360);
            }
        }
        return self::pack($b, $final ? 'konspekt_' : 'himoya_savollari_');
    }

    /** Tayyor tana (body) XML'ini DOCX faylga yig'adi (A4, Times New Roman, bet raqami). */
    public static function pack(string $b, string $prefix): string
    {
        $path = (defined('GENERATED_DIR') ? GENERATED_DIR : sys_get_temp_dir() . '/') . $prefix . uniqid() . '.docx';
        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>'
            . $b . '<w:sectPr><w:footerReference w:type="default" r:id="rIdF"/><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1021" w:bottom="1134" w:left="1418" w:header="567" w:footer="567" w:gutter="0"/></w:sectPr></w:body></w:document>';
        $F = self::FONT;
        $W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Konspekt fayl yaratib bo\'lmadi');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rIdF" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/></Relationships>');
        $zip->addFromString('word/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="' . $W . '">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="' . $F . '" w:hAnsi="' . $F . '" w:cs="' . $F . '" w:eastAsia="' . $F . '"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:spacing w:after="0" w:line="300" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style></w:styles>');
        $zip->addFromString('word/footer1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr xmlns:w="' . $W . '"><w:p><w:pPr><w:jc w:val="center"/></w:pPr>'
            . '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>1</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p></w:ftr>');
        $zip->addFromString('word/document.xml', $doc);
        if (!$zip->close() || !is_file($path)) {
            throw new RuntimeException('Konspekt faylni yozib bo\'lmadi');
        }
        return $path;
    }

    public static function esc(string $t): string
    {
        $t = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $t) ?? '';
        return htmlspecialchars($t, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function r(string $t, bool $b = false, int $sz = 24, string $color = '', int $spacing = 0, bool $i = false): string
    {
        $p = ($b ? '<w:b/><w:bCs/>' : '') . ($i ? '<w:i/><w:iCs/>' : '') . ($color !== '' ? '<w:color w:val="' . $color . '"/>' : '')
            . ($spacing ? '<w:spacing w:val="' . $spacing . '"/>' : '') . '<w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/>';
        return '<w:r><w:rPr>' . $p . '</w:rPr><w:t xml:space="preserve">' . self::esc($t) . '</w:t></w:r>';
    }

    /** Paragraf. Tartib (Word sxemasi): keepNext, pBdr, shd, spacing, ind, jc. */
    public static function p(string $runs, string $jc = 'left', int $before = 0, int $after = 0, string $bdrShd = '', int $indLR = 0, bool $keepNext = false, int $hanging = 0, int $left = 0): string
    {
        $ind = $hanging ? '<w:ind w:left="' . ($left ?: $hanging) . '" w:hanging="' . $hanging . '"/>' : ($left ? '<w:ind w:left="' . $left . '"/>' : ($indLR ? '<w:ind w:left="' . $indLR . '" w:right="' . $indLR . '"/>' : ''));
        return '<w:p><w:pPr>' . ($keepNext ? '<w:keepNext/>' : '') . $bdrShd . '<w:spacing w:before="' . $before . '" w:after="' . $after . '" w:line="300" w:lineRule="auto"/>'
            . $ind . '<w:jc w:val="' . $jc . '"/></w:pPr>' . $runs . '</w:p>';
    }
}
