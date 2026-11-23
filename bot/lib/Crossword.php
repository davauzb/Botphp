<?php

/**
 * 🧩 KROSSVORD: AI mavzu bo'yicha so'z + savol juftlarini beradi, shu klass ularni panjaraga joylaydi va DOCX yasaydi
 *  (1-bet: bo'sh panjara raqamlar bilan + «Eniga» / «Bo'yiga» savollari; 2-bet: javoblar — to'ldirilgan panjara).
 * O'zbek lotinidagi Oʻ va Gʻ bitta katak hisoblanadi; tutuq belgili (ma'no) yoki bo'shliqli so'zlar olinmaydi.
 */
class Crossword
{
    private const INK = '1F2A44';
    private const ACC = '2F4A7A';

    /** So'zni kataklarga ajratish (Oʻ/Gʻ — bitta katak). Yaroqsiz bo'lsa null. */
    public static function cells(string $w): ?array
    {
        $w = mb_strtoupper(trim($w));
        $w = preg_replace("/([OG])['’ʻ`‘ʼ]/u", '$1ʻ', $w) ?? $w;
        if (!preg_match('/^[\p{L}ʻ]+$/u', $w)) {
            return null;
        }
        preg_match_all('/[OG]ʻ|\p{L}/u', $w, $m);
        if (implode('', $m[0]) !== $w) {                 // boshqa joyda turgan ʻ (masalan, maʼno)
            return null;
        }
        $n = count($m[0]);
        return ($n >= 3 && $n <= 14) ? $m[0] : null;
    }

    /** AI javobidan toza juftliklar: [['w' => [kataklar], 'word' => 'SO'Z', 'clue' => '...'], ...] */
    public static function normalize(array $raw): array
    {
        $out = [];
        $seen = [];
        foreach ($raw as $x) {
            $word = trim((string)($x['soz'] ?? $x['word'] ?? ''));
            $clue = trim(preg_replace('/\s+/u', ' ', (string)($x['savol'] ?? $x['clue'] ?? '')) ?? '');
            $c = self::cells($word);
            if (!$c || mb_strlen($clue) < 5) {
                continue;
            }
            $key = implode('', $c);
            if (isset($seen[$key]) || mb_stripos($clue, mb_strtolower($word)) !== false) {   // savolda javob so'zi bo'lmasin
                continue;
            }
            $seen[$key] = true;
            $out[] = ['w' => $c, 'word' => $key, 'clue' => mb_substr($clue, 0, 220)];
        }
        return $out;
    }

    /**
     * Joylashtirish: bir necha urinish (har xil tartib), eng ko'p so'z va ixcham panjara tanlanadi.
     * @return array{grid: array, words: array, w: int, h: int}
     */
    public static function layout(array $items, int $want, int $tries = 40): array
    {
        $best = null;
        usort($items, fn($a, $b) => count($b['w']) <=> count($a['w']));
        for ($t = 0; $t < $tries; $t++) {
            $order = $items;
            if ($t > 0) {                                   // birinchisi — uzundan qisqaga; qolganlari aralash (uzunlari oldinroq)
                $head = array_slice($order, 0, 3);
                $rest = array_slice($order, 3);
                shuffle($head);
                shuffle($rest);
                $order = array_merge($head, $rest);
            }
            $r = self::tryLayout($order, $want);
            $score = count($r['words']) * 1000 - ($r['w'] * $r['h']) + $r['cross'] * 15;
            if (!$best || $score > $best['score']) {
                $r['score'] = $score;
                $best = $r;
            }
            if (count($best['words']) >= $want && $t >= 12) {
                break;
            }
        }
        return $best;
    }

    private static function tryLayout(array $order, int $want): array
    {
        $g = [];                       // "x,y" => harf
        $placed = [];
        $cross = 0;
        $first = array_shift($order);
        foreach ($first['w'] as $i => $ch) {
            $g["$i,0"] = $ch;
        }
        $placed[] = $first + ['x' => 0, 'y' => 0, 'd' => 'a'];
        $skipped = [];
        foreach ([$order, null] as $pass) {
            $list = $pass ?? $skipped;
            $skipped = [];
            foreach ($list as $it) {
                if (count($placed) >= $want) {
                    break 2;
                }
                $cand = self::candidates($g, $it['w']);
                if (!$cand) {
                    $skipped[] = $it;
                    continue;
                }
                usort($cand, fn($a, $b) => $b['s'] <=> $a['s']);
                $c = $cand[0];
                foreach ($it['w'] as $i => $ch) {
                    $x = $c['d'] === 'a' ? $c['x'] + $i : $c['x'];
                    $y = $c['d'] === 'a' ? $c['y'] : $c['y'] + $i;
                    $g["$x,$y"] = $ch;
                }
                $cross += $c['n'];
                $placed[] = $it + ['x' => $c['x'], 'y' => $c['y'], 'd' => $c['d']];
            }
        }
        [$minX, $minY, $maxX, $maxY] = self::bounds($g);
        return ['grid' => $g, 'words' => $placed, 'w' => $maxX - $minX + 1, 'h' => $maxY - $minY + 1, 'minX' => $minX, 'minY' => $minY, 'cross' => $cross];
    }

    private static function bounds(array $g): array
    {
        $xs = $ys = [];
        foreach (array_keys($g) as $k) {
            [$x, $y] = array_map('intval', explode(',', (string)$k));
            $xs[] = $x;
            $ys[] = $y;
        }
        return [min($xs), min($ys), max($xs), max($ys)];
    }

    /** So'zni mavjud harflar bilan kesishtirib qo'yish mumkin bo'lgan joylar. */
    private static function candidates(array $g, array $w): array
    {
        [$minX, $minY, $maxX, $maxY] = self::bounds($g);
        $out = [];
        $len = count($w);
        foreach ($g as $k => $ch) {
            [$gx, $gy] = array_map('intval', explode(',', (string)$k));
            foreach ($w as $i => $wc) {
                if ($wc !== $ch) {
                    continue;
                }
                foreach (['a' => [$gx - $i, $gy], 'd' => [$gx, $gy - $i]] as $d => [$sx, $sy]) {
                    $n = self::fits($g, $w, $sx, $sy, $d);
                    if ($n < 1) {
                        continue;
                    }
                    $nx0 = min($minX, $sx);
                    $ny0 = min($minY, $sy);
                    $nx1 = max($maxX, $d === 'a' ? $sx + $len - 1 : $sx);
                    $ny1 = max($maxY, $d === 'd' ? $sy + $len - 1 : $sy);
                    $wd = $nx1 - $nx0 + 1;
                    $ht = $ny1 - $ny0 + 1;
                    if ($wd > 20 || $ht > 20) {             // A4 sahifaga sig'sin
                        continue;
                    }
                    $out[] = ['x' => $sx, 'y' => $sy, 'd' => $d, 'n' => $n, 's' => $n * 40 - $wd * $ht * 0.5 - abs($wd - $ht) * 3 + mt_rand(0, 9)];
                }
            }
        }
        return $out;
    }

    /** Kesishuvlar soni yoki -1 (qo'yib bo'lmaydi). */
    private static function fits(array $g, array $w, int $sx, int $sy, string $d): int
    {
        $dx = $d === 'a' ? 1 : 0;
        $dy = $d === 'a' ? 0 : 1;
        $len = count($w);
        // so'z boshidan oldin va oxiridan keyin bo'sh bo'lsin
        if (isset($g[($sx - $dx) . ',' . ($sy - $dy)]) || isset($g[($sx + $dx * $len) . ',' . ($sy + $dy * $len)])) {
            return -1;
        }
        $n = 0;
        for ($i = 0; $i < $len; $i++) {
            $x = $sx + $dx * $i;
            $y = $sy + $dy * $i;
            $cur = $g["$x,$y"] ?? null;
            if ($cur !== null) {
                if ($cur !== $w[$i]) {
                    return -1;
                }
                $n++;
                continue;
            }
            // yonma-yon (perpendikulyar) qo'shnilar bo'sh bo'lsin — aks holda tasodifiy «so'zlar» hosil bo'ladi
            if (isset($g[($x + $dy) . ',' . ($y + $dx)]) || isset($g[($x - $dy) . ',' . ($y - $dx)])) {
                return -1;
            }
        }
        return $n === $len ? -1 : $n;                       // to'liq ustma-ust tushish — kerak emas
    }

    /** Raqamlash: chapdan o'ngga, yuqoridan pastga; bir katakdan ikki so'z boshlansa — bitta raqam. */
    public static function number(array $L): array
    {
        $starts = [];
        foreach ($L['words'] as $i => $w) {
            $starts[($w['y'] - $L['minY']) . ',' . ($w['x'] - $L['minX'])][] = $i;
        }
        uksort($starts, function ($a, $b) {
            [$ay, $ax] = array_map('intval', explode(',', $a));
            [$by, $bx] = array_map('intval', explode(',', $b));
            return [$ay, $ax] <=> [$by, $bx];
        });
        $num = 1;
        $cellNum = [];
        foreach ($starts as $k => $idxs) {
            [$y, $x] = array_map('intval', explode(',', $k));
            $cellNum["$x,$y"] = $num;
            foreach ($idxs as $i) {
                $L['words'][$i]['num'] = $num;
            }
            $num++;
        }
        $L['cellNum'] = $cellNum;
        return $L;
    }

    // ------------------------------------------------------------------ DOCX

    private static function grid(array $L, bool $filled): string
    {
        $W = $L['w'];
        $H = $L['h'];
        $cw = (int)min(560, floor(9300 / max(1, $W)));       // katak eni (dxa)
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="' . ($cw * $W) . '" w:type="dxa"/><w:jc w:val="center"/><w:tblLayout w:type="fixed"/>'
            . '<w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:left w:w="20" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/><w:right w:w="20" w:type="dxa"/></w:tblCellMar>'
            . '</w:tblPr><w:tblGrid>' . str_repeat('<w:gridCol w:w="' . $cw . '"/>', $W) . '</w:tblGrid>';
        $numSz = $cw >= 480 ? 13 : 11;
        $letSz = $cw >= 480 ? 24 : 20;
        for ($y = 0; $y < $H; $y++) {
            $xml .= '<w:tr><w:trPr><w:trHeight w:val="' . $cw . '" w:hRule="exact"/></w:trPr>';
            for ($x = 0; $x < $W; $x++) {
                $ch = $L['grid'][($x + $L['minX']) . ',' . ($y + $L['minY'])] ?? null;
                if ($ch === null) {
                    $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $cw . '" w:type="dxa"/><w:tcBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/></w:tcBorders></w:tcPr><w:p><w:pPr><w:spacing w:before="0" w:after="0"/></w:pPr></w:p></w:tc>';
                    continue;
                }
                $b = '<w:top w:val="single" w:sz="10" w:color="' . self::INK . '"/><w:left w:val="single" w:sz="10" w:color="' . self::INK . '"/>'
                    . '<w:bottom w:val="single" w:sz="10" w:color="' . self::INK . '"/><w:right w:val="single" w:sz="10" w:color="' . self::INK . '"/>';
                $num = $L['cellNum']["$x,$y"] ?? null;
                $p = '<w:p><w:pPr><w:spacing w:before="0" w:after="0" w:line="200" w:lineRule="exact"/></w:pPr>'
                    . ($num !== null ? '<w:r><w:rPr><w:b/><w:color w:val="' . self::ACC . '"/><w:sz w:val="' . $numSz . '"/></w:rPr><w:t>' . $num . '</w:t></w:r>' : '')
                    . '</w:p>';
                if ($filled) {
                    $p .= '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:before="0" w:after="0"/></w:pPr><w:r><w:rPr><w:b/><w:color w:val="' . self::INK . '"/><w:sz w:val="' . $letSz . '"/></w:rPr><w:t>'
                        . htmlspecialchars($ch, ENT_XML1) . '</w:t></w:r></w:p>';
                }
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $cw . '" w:type="dxa"/><w:tcBorders>' . $b . '</w:tcBorders>'
                    . ($filled ? '<w:shd w:val="clear" w:color="auto" w:fill="EEF2FA"/>' : '') . '</w:tcPr>' . $p . '</w:tc>';
            }
            $xml .= '</w:tr>';
        }
        return $xml . '</w:tbl>';
    }

    public static function generate(string $topic, array $L, array $student, string $lang = 'uz'): string
    {
        $T = [
            'uz' => ['KROSSVORD', 'Eniga', "Bo'yiga", 'JAVOBLAR', 'Tuzuvchi', 'Guruh', "ta so'z", 'harf'],
            'ru' => ['КРОССВОРД', 'По горизонтали', 'По вертикали', 'ОТВЕТЫ', 'Составитель', 'Группа', 'слов', 'букв'],
            'en' => ['CROSSWORD', 'Across', 'Down', 'ANSWERS', 'Compiled by', 'Group', 'words', 'letters'],
        ][$lang] ?? null;
        $T = $T ?? ['KROSSVORD', 'Eniga', "Bo'yiga", 'JAVOBLAR', 'Tuzuvchi', 'Guruh', "ta so'z", 'harf'];
        $L = self::number($L);
        $b = '';
        $univ = trim((string)($student['university'] ?? ''));
        if ($univ !== '') {
            $b .= SpeechDoc::p(SpeechDoc::r(mb_strtoupper($univ), true, 20, '5A6378'), 'center', 0, 80);
        }
        $b .= SpeechDoc::p(SpeechDoc::r($T[0], true, 34, self::INK, 40), 'center', 0, 40);
        $b .= SpeechDoc::p(SpeechDoc::r('«' . $topic . '»', true, 26, self::ACC), 'center', 0, 60);
        $name = trim((string)($student['full_name'] ?? ''));
        $b .= SpeechDoc::p(SpeechDoc::r(count($L['words']) . ' ' . $T[6], false, 20, '6B7280', 0, true)
            . ($name !== '' ? SpeechDoc::r('   ·   ' . $T[4] . ': ' . $name . (trim((string)($student['group_name'] ?? '')) !== '' ? ', ' . $student['group_name'] : ''), false, 20, '6B7280', 0, true) : ''),
            'center', 0, 200, '<w:pBdr><w:bottom w:val="single" w:sz="8" w:space="6" w:color="' . self::ACC . '"/></w:pBdr>');
        $b .= self::grid($L, false);
        $across = $down = [];
        foreach ($L['words'] as $w) {
            $line = SpeechDoc::p(SpeechDoc::r($w['num'] . '. ', true, 22, self::ACC) . SpeechDoc::r($w['clue'], false, 22)
                . SpeechDoc::r(' (' . count($w['w']) . ' ' . $T[7] . ')', false, 18, '8A93A6', 0, true), 'left', 0, 40, '', 0, false, 340);
            if ($w['d'] === 'a') {
                $across[$w['num']] = $line;
            } else {
                $down[$w['num']] = $line;
            }
        }
        ksort($across);
        ksort($down);
        foreach ([[$T[1], $across], [$T[2], $down]] as [$h, $rows]) {
            if ($rows) {
                $b .= SpeechDoc::p(SpeechDoc::r($h . ':', true, 26, self::INK), 'left', 240, 80, '', 0, true);
                $b .= implode('', $rows);
            }
        }
        // javoblar
        $b .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
        $b .= SpeechDoc::p(SpeechDoc::r($T[3], true, 30, self::INK, 20), 'center', 0, 200);
        $b .= self::grid($L, true);
        $ans = [];
        foreach ([['a', $T[1]], ['d', $T[2]]] as [$d, $h]) {
            $ws = array_filter($L['words'], fn($w) => $w['d'] === $d);
            usort($ws, fn($a, $b) => $a['num'] <=> $b['num']);
            if ($ws) {
                $ans[] = SpeechDoc::r($h . ': ', true, 22, self::ACC) . SpeechDoc::r(implode(', ', array_map(fn($w) => $w['num'] . '. ' . $w['word'], $ws)), false, 22);
            }
        }
        foreach ($ans as $a) {
            $b .= SpeechDoc::p($a, 'left', 200, 60);
        }
        return SpeechDoc::pack($b, 'krossvord_');
    }
}
