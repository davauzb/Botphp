<?php

/**
 * TEST SAVOLLARI (DOCX): sarlavha, tuzuvchi, ko'rsatma, savollar (A–D variantlar) va oxirida alohida betda «Javoblar kaliti» jadvali.
 * To'g'ri javob o'rni har savolda tasodifiy aralashtiriladi (AI ko'pincha A ni to'g'ri qiladi).
 */
class TestDoc
{
    private const INK = '1F2A44';
    private const ACC = '2F4A7A';

    /** AI javobidagi savollarni tekshiradi va variantlarni aralashtiradi. @return array<int,array{q:string,o:string[],a:int}> */
    public static function normalize(array $raw): array
    {
        $out = [];
        foreach ($raw as $x) {
            $q = trim(preg_replace('/^\s*\d+[\.\)]\s*/u', '', (string)($x['s'] ?? '')) ?? '');
            $opts = array_values(array_filter(array_map(fn($o) => trim(preg_replace('/^\s*[A-Da-dА-Га-г][\)\.]\s*/u', '', (string)$o) ?? ''), (array)($x['v'] ?? [])), fn($o) => $o !== ''));
            $j = (int)($x['j'] ?? -1);
            if ($q === '' || count($opts) < 4 || $j < 0 || $j > 3) {
                continue;
            }
            $opts = array_slice($opts, 0, 4);
            $right = $opts[$j];
            if (count(array_unique(array_map('mb_strtolower', $opts))) < 4) {
                continue;
            }
            shuffle($opts);
            $out[] = ['q' => mb_substr($q, 0, 400), 'o' => array_map(fn($o) => mb_substr($o, 0, 200), $opts), 'a' => (int)array_search($right, $opts, true)];
        }
        return $out;
    }

    public static function generate(string $topic, array $qs, array $student, string $lang = 'uz'): string
    {
        $L = [
            'uz' => ['TEST SAVOLLARI', 'Tuzuvchi', 'Guruh', "Ko'rsatma: har bir savolga bitta to'g'ri javobni belgilang.", 'JAVOBLAR KALITI', 'Savol', 'Javob', 'ta savol'],
            'ru' => ['ТЕСТОВЫЕ ВОПРОСЫ', 'Составитель', 'Группа', 'Инструкция: на каждый вопрос выберите один правильный ответ.', 'КЛЮЧ ОТВЕТОВ', 'Вопрос', 'Ответ', 'вопросов'],
            'en' => ['TEST QUESTIONS', 'Compiled by', 'Group', 'Instructions: choose one correct answer for each question.', 'ANSWER KEY', 'Question', 'Answer', 'questions'],
        ][$lang] ?? null;
        $L = $L ?? ['TEST SAVOLLARI', 'Tuzuvchi', 'Guruh', "Ko'rsatma: har bir savolga bitta to'g'ri javobni belgilang.", 'JAVOBLAR KALITI', 'Savol', 'Javob', 'ta savol'];
        $letters = ['A', 'B', 'C', 'D'];
        $b = '';
        $univ = trim((string)($student['university'] ?? ''));
        if ($univ !== '') {
            $b .= SpeechDoc::p(SpeechDoc::r(mb_strtoupper($univ), true, 22, '5A6378'), 'center', 0, 120);
        }
        $b .= SpeechDoc::p(SpeechDoc::r($L[0], true, 32, self::INK, 30), 'center', 0, 60);
        $b .= SpeechDoc::p(SpeechDoc::r('«' . $topic . '»', true, 28, self::ACC), 'center', 0, 80);
        $b .= SpeechDoc::p(SpeechDoc::r(count($qs) . ' ' . $L[7], false, 22, '6B7280', 0, true), 'center', 0, 160,
            '<w:pBdr><w:bottom w:val="single" w:sz="8" w:space="6" w:color="' . self::ACC . '"/></w:pBdr>');
        $name = trim((string)($student['full_name'] ?? ''));
        if ($name !== '') {
            $b .= SpeechDoc::p(SpeechDoc::r($L[1] . ': ', true, 22) . SpeechDoc::r($name, false, 22)
                . (trim((string)($student['group_name'] ?? '')) !== '' ? SpeechDoc::r('   ·   ' . $L[2] . ': ', true, 22) . SpeechDoc::r((string)$student['group_name'], false, 22) : ''), 'left', 60, 60);
        }
        $b .= SpeechDoc::p(SpeechDoc::r($L[3], false, 22, '374151', 0, true), 'left', 60, 200);
        foreach ($qs as $n => $q) {
            $b .= SpeechDoc::p(SpeechDoc::r(($n + 1) . '. ', true, 24, self::ACC) . SpeechDoc::r($q['q'], true, 24, self::INK), 'left', 160, 60, '', 0, true, 425);
            foreach ($q['o'] as $k => $o) {
                $b .= SpeechDoc::p(SpeechDoc::r($letters[$k] . ') ', true, 24) . SpeechDoc::r($o, false, 24), 'left', 0, $k === 3 ? 40 : 10, '', 0, $k < 3, 0, 709);
            }
        }
        // javoblar kaliti — yangi betda, 5 juft ustunli jadval
        $b .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
        $b .= SpeechDoc::p(SpeechDoc::r($L[4], true, 28, self::INK, 20), 'center', 0, 200);
        $cols = 5;
        $rows = (int)ceil(count($qs) / $cols);
        $cell = function (string $t, bool $head, bool $shade) {
            return '<w:tc><w:tcPr><w:tcW w:w="900" w:type="dxa"/>' . ($shade ? '<w:shd w:val="clear" w:color="auto" w:fill="' . ($head ? 'DCE3F0' : 'F6F7FB') . '"/>' : '')
                . '<w:vAlign w:val="center"/></w:tcPr>' . SpeechDoc::p(SpeechDoc::r($t, $head, 22, $head ? self::INK : ''), 'center', 30, 30) . '</w:tc>';
        };
        $tbl = '<w:tbl><w:tblPr><w:tblW w:w="9000" w:type="dxa"/><w:jc w:val="center"/><w:tblBorders>'
            . '<w:top w:val="single" w:sz="6" w:color="9AA5BD"/><w:left w:val="single" w:sz="6" w:color="9AA5BD"/><w:bottom w:val="single" w:sz="6" w:color="9AA5BD"/>'
            . '<w:right w:val="single" w:sz="6" w:color="9AA5BD"/><w:insideH w:val="single" w:sz="4" w:color="C9D1E1"/><w:insideV w:val="single" w:sz="4" w:color="C9D1E1"/>'
            . '</w:tblBorders></w:tblPr><w:tblGrid>' . str_repeat('<w:gridCol w:w="900"/>', $cols * 2) . '</w:tblGrid>';
        $tbl .= '<w:tr>';
        for ($c = 0; $c < $cols; $c++) {
            $tbl .= $cell('№', true, true) . $cell($L[6], true, true);
        }
        $tbl .= '</w:tr>';
        for ($r = 0; $r < $rows; $r++) {
            $tbl .= '<w:tr>';
            for ($c = 0; $c < $cols; $c++) {
                $i = $c * $rows + $r;
                $tbl .= isset($qs[$i]) ? $cell((string)($i + 1), false, true) . $cell($letters[$qs[$i]['a']], true, false) : $cell('', false, false) . $cell('', false, false);
            }
            $tbl .= '</w:tr>';
        }
        $tbl .= '</w:tbl>';
        $b .= $tbl;
        return SpeechDoc::pack($b, 'test_savollari_');
    }
}
