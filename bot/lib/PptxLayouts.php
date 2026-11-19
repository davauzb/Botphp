<?php

require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/PptxAnim.php';

/**
 * "cards" turidagi 12 ta dizayn uchun XILMA-XIL slayd maketlari.
 *
 * Har bir dizayn (config.php -> PPTX_DESIGNS) o'z "terisi" (skin)ga ega: faqat rang emas,
 * sarlavha joyi, fon bezaklari, karta shakli, raqam belgisi, shrift va muqova ham farq qiladi.
 *
 * Slayd turlari (kind): matn, tarif, bosqichlar, xronologiya, kartalar, taqqoslash, xulosa, reja,
 * hamda title / thanks (muqova va yakun). Har bir tur har bir teri ichida o'ziga xos shakllarda chiziladi.
 *
 * Slayd tuzilishi:
 *   ['kind' => 'matn', 'title' => '...', 'lead' => 'izoh', 'points' => [['head'=>'','text'=>'','side'=>''], ...],
 *    'image' => '/path.jpg', 'left_title' => '', 'right_title' => '', 'items' => [...] (reja uchun)]
 */
class PptxLayouts
{
    private const W = 9144000;
    private const H = 6858000;

    private static int $x0 = 572000;
    private static int $cw = 8000000;
    private static int $y0 = 1500000;
    private static int $yb = 6450000;
    private static string $skin = 'classic';
    private static array $S = [];
    private static array $P = [];
    private static int $id = 100;
    private static ?string $bgImage = null;     // PRO: fon rasm (lokal yo'l)
    private static array $animIds = [];         // PRO: animatsiya qilinadigan shakllar
    private static bool $recording = false;
    private static int $curN = 1;

    /**
     * Teri parametrlari:
     *  font/hfont — matn / sarlavha shrifti (hamma dasturlarda bor shriftlar), fw — shrift kengligi koeffitsienti
     *  dark — qora fon; bgBase/bgK — fon rangi (asosiy rang + accent aralashmasi)
     *  card — karta shakli (geom, adj, mode: outline|stripe|topline|shadow|tint|plain, lw)
     *  badge — raqam belgisi (ellipse|rect|roundRect|diamond|hexagon|ring|text)
     *  lead — izoh bandi (bar|fill|rule|outline|pill), head — kartalar sarlavhasi (round|rect|snip|pill|line)
     *  steps — bosqichlar shakli (chevron|rect|snip|circle|hexagon|pill|ruled), edge — muqova varianti
     */
    public const SKINS = [
        'classic' => ['font' => 'Calibri', 'hfont' => 'Calibri', 'fw' => 0.94, 'dark' => false, 'bgBase' => 'FFFFFF', 'bgK' => 0.07,
            'card' => ['geom' => 'roundRect', 'adj' => 4500, 'mode' => 'outline', 'lw' => 19050],
            'badge' => 'ellipse', 'lead' => 'bar', 'head' => 'round', 'steps' => 'chevron', 'edge' => 'full'],
        'band' => ['font' => 'Arial', 'hfont' => 'Arial', 'fw' => 1.0, 'dark' => false, 'bgBase' => 'FFFFFF', 'bgK' => 0.04,
            'card' => ['geom' => 'rect', 'adj' => null, 'mode' => 'stripe', 'lw' => 9525],
            'badge' => 'rect', 'lead' => 'fill', 'head' => 'rect', 'steps' => 'rect', 'edge' => 'band'],
        'sidebar' => ['font' => 'Trebuchet MS', 'hfont' => 'Trebuchet MS', 'fw' => 1.08, 'dark' => false, 'bgBase' => 'FFFFFF', 'bgK' => 0.05,
            'x0' => 1000000, 'cw' => 7600000,
            'card' => ['geom' => 'rect', 'adj' => null, 'mode' => 'topline', 'lw' => 9525],
            'badge' => 'roundRect', 'lead' => 'outline', 'head' => 'line', 'steps' => 'snip', 'edge' => 'side'],
        'dark' => ['font' => 'Calibri', 'hfont' => 'Calibri', 'fw' => 0.94, 'dark' => true, 'bgk' => 0.5,
            'card' => ['geom' => 'roundRect', 'adj' => 6000, 'mode' => 'outline', 'lw' => 12700],
            'badge' => 'ring', 'lead' => 'bar', 'head' => 'round', 'steps' => 'circle', 'edge' => 'rings'],
        'snip' => ['font' => 'Verdana', 'hfont' => 'Verdana', 'fw' => 1.14, 'dark' => false, 'bgBase' => 'FFF7EA', 'bgK' => 0.08,
            'card' => ['geom' => 'snip2DiagRect', 'adj' => ['adj1' => 12000, 'adj2' => 0], 'mode' => 'shadow', 'lw' => 19050],
            'badge' => 'diamond', 'lead' => 'outline', 'head' => 'snip', 'steps' => 'hexagon', 'edge' => 'snip'],
        'underline' => ['font' => 'Tahoma', 'hfont' => 'Tahoma', 'fw' => 1.0, 'dark' => false, 'bgBase' => 'FFFFFF', 'bgK' => 0.02,
            'card' => ['geom' => 'roundRect', 'adj' => 8000, 'mode' => 'tint', 'lw' => 0],
            'badge' => 'ring', 'lead' => 'rule', 'head' => 'line', 'steps' => 'pill', 'edge' => 'underline'],
        'bubble' => ['font' => 'Tahoma', 'hfont' => 'Tahoma', 'fw' => 1.0, 'dark' => false, 'bgBase' => 'FFFFFF', 'bgK' => 0.1, 'yb' => 6270000,
            'card' => ['geom' => 'roundRect', 'adj' => 14000, 'mode' => 'outline', 'lw' => 28575],
            'badge' => 'ellipse', 'lead' => 'pill', 'head' => 'pill', 'steps' => 'circle', 'edge' => 'bubble'],
        'minimal' => ['font' => 'Arial', 'hfont' => 'Georgia', 'fw' => 1.0, 'dark' => false, 'bgBase' => 'FFFFFF', 'bgK' => 0.0,
            'card' => ['geom' => 'rect', 'adj' => null, 'mode' => 'plain', 'lw' => 9525],
            'badge' => 'text', 'lead' => 'rule', 'head' => 'line', 'steps' => 'ruled', 'edge' => 'minimal'],
        'frame' => ['font' => 'Georgia', 'hfont' => 'Georgia', 'fw' => 1.1, 'dark' => false, 'bgBase' => 'FBF6E6', 'bgK' => 0.05,
            'x0' => 760000, 'cw' => 7624000, 'y0' => 1650000, 'yb' => 6250000,
            'card' => ['geom' => 'rect', 'adj' => null, 'mode' => 'outline', 'lw' => 12700],
            'badge' => 'diamond', 'lead' => 'rule', 'head' => 'line', 'steps' => 'ruled', 'edge' => 'frame'],
        'navy' => ['font' => 'Arial', 'hfont' => 'Arial', 'fw' => 1.0, 'dark' => true, 'bgk' => 0.12, 'acc' => '2E86DE',
            'card' => ['geom' => 'rect', 'adj' => null, 'mode' => 'stripe', 'lw' => 9525],
            'badge' => 'rect', 'lead' => 'fill', 'head' => 'rect', 'steps' => 'chevron', 'edge' => 'navy'],
        'paper' => ['font' => 'Cambria', 'hfont' => 'Cambria', 'fw' => 1.0, 'dark' => false, 'bgBase' => 'F6F0E4', 'bgK' => 0.07,
            'card' => ['geom' => 'foldedCorner', 'adj' => 12000, 'mode' => 'outline', 'lw' => 19050],
            'badge' => 'roundRect', 'lead' => 'outline', 'head' => 'round', 'steps' => 'pill', 'edge' => 'paper'],
        // PRO: to'liq ekranli foto fon + shisha (shaffof) kartalar; o'tish effekti va animatsiya (wrap ichida)
        'pro' => ['font' => 'Segoe UI', 'hfont' => 'Segoe UI', 'fw' => 1.04, 'dark' => true, 'bgk' => 0.6, 'pro' => true,
            'card' => ['geom' => 'roundRect', 'adj' => 7000, 'mode' => 'outline', 'lw' => 12700],
            'badge' => 'ellipse', 'lead' => 'bar', 'head' => 'round', 'steps' => 'circle', 'edge' => 'pro'],
        'tech' => ['font' => 'Trebuchet MS', 'hfont' => 'Trebuchet MS', 'fw' => 1.08, 'dark' => false, 'bgBase' => 'FFFFFF', 'bgK' => 0.08,
            'card' => ['geom' => 'snip1Rect', 'adj' => 14000, 'mode' => 'outline', 'lw' => 19050],
            'badge' => 'hexagon', 'lead' => 'fill', 'head' => 'snip', 'steps' => 'hexagon', 'edge' => 'tech'],
    ];

    // ------------------------------------------------------------------ setup / colors

    private static function len(string $t): int
    {
        return function_exists('mb_strlen') ? mb_strlen($t) : strlen($t);
    }

    public static function esc(string $t): string
    {
        return htmlspecialchars($t, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public static function tint(string $hex, float $amount): string
    {
        return self::mix($hex, 'FFFFFF', $amount);
    }

    /** a dan b ga qarab t (0..1) aralashma */
    private static function mix(string $a, string $b, float $t): string
    {
        $out = '';
        foreach ([0, 2, 4] as $o) {
            $x = hexdec(substr($a, $o, 2));
            $y = hexdec(substr($b, $o, 2));
            $out .= sprintf('%02X', (int)round($x + ($y - $x) * $t));
        }
        return $out;
    }

    private static function lum(string $h): float
    {
        return (0.2126 * hexdec(substr($h, 0, 2)) + 0.7152 * hexdec(substr($h, 2, 2)) + 0.0722 * hexdec(substr($h, 4, 2))) / 255;
    }

    /** $h fon ustida o'qiladigan matn rangi */
    private static function on(string $h): string
    {
        return self::lum($h) > 0.62 ? '1F1F1F' : 'FFFFFF';
    }

    private static function setup(array $d): void
    {
        $skin = (string)($d['skin'] ?? 'classic');
        if (!isset(self::SKINS[$skin])) {
            $skin = 'classic';
        }
        self::$skin = $skin;
        self::$lang = I18n::norm($d['lang'] ?? null);
        self::$S = self::SKINS[$skin];
        $S = self::$S;
        $A = (string)$d['accent'];
        $T = (string)$d['title'];

        if ($S['dark']) {
            $bg = !empty($S['pro']) && !empty($d['bg']) ? (string)$d['bg'] : self::mix($T, '000000', $S['bgk']);
            $acc = $S['acc'] ?? (self::lum($A) < 0.3 ? self::tint($A, 0.5) : $A);
            self::$P = [
                'bg' => $bg, 'card' => self::mix($bg, 'FFFFFF', 0.07), 'line' => self::mix($acc, $bg, 0.45),
                'hair' => self::mix($bg, 'FFFFFF', 0.22), 'txt' => 'EDEDED', 'sub' => '9AA5B1', 'title' => 'FFFFFF',
                'head' => self::tint($acc, 0.5), 'acc' => $acc, 'acc2' => self::mix($acc, 'FFFFFF', 0.3),
                'soft' => self::mix($bg, 'FFFFFF', 0.13), 'deco' => self::mix($bg, $acc, 0.3),
            ];
        } else {
            self::$P = [
                'bg' => self::mix($S['bgBase'], $A, $S['bgK']), 'card' => 'FFFFFF', 'line' => $A,
                'hair' => 'D9D9D9', 'txt' => '262626', 'sub' => '7F7F7F', 'title' => $T,
                'head' => $T, 'acc' => $A, 'acc2' => $T,
                'soft' => self::tint($A, 0.8), 'deco' => self::tint($A, 0.84),
            ];
        }
        self::$x0 = $S['x0'] ?? 572000;
        self::$cw = $S['cw'] ?? 8000000;
        self::$y0 = $S['y0'] ?? 1500000;
        self::$yb = $S['yb'] ?? 6450000;
        self::$id = 100;
        self::$bgImage = null;
        self::$animIds = [];
        self::$recording = !empty($S['pro']);
    }

    private static function isPro(): bool
    {
        return self::$skin === 'pro';
    }

    // ------------------------------------------------------------------ XML primitives

    private static function nid(): int
    {
        return ++self::$id;
    }

    private static function run(string $text, int $sz, string $color, bool $b = false, bool $i = false, bool $h = false, int $spc = 0): string
    {
        $f = $h ? self::$S['hfont'] : self::$S['font'];
        return '<a:r><a:rPr lang="uz-UZ" sz="' . ($sz * 100) . '" b="' . ($b ? 1 : 0) . '" i="' . ($i ? 1 : 0)
            . ($spc ? '" spc="' . $spc : '') . '" dirty="0"><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill><a:latin typeface="' . $f
            . '"/><a:cs typeface="' . $f . '"/></a:rPr><a:t>' . self::esc($text) . '</a:t></a:r>';
    }

    private static function para(string $runs, string $algn = 'l', int $aftPts = 0, int $befPts = 0): string
    {
        return '<a:p><a:pPr marL="0" indent="0" algn="' . $algn . '"><a:lnSpc><a:spcPct val="100000"/></a:lnSpc>'
            . '<a:spcBef><a:spcPts val="' . ($befPts * 100) . '"/></a:spcBef>'
            . '<a:spcAft><a:spcPts val="' . ($aftPts * 100) . '"/></a:spcAft><a:buNone/></a:pPr>' . $runs . '</a:p>';
    }

    /** 'RRGGBB' yoki 'RRGGBB@alfa%' (shaffoflik) -> srgbClr. */
    private static function clr(string $c): string
    {
        if (str_contains($c, '@')) {
            [$h, $a] = explode('@', $c, 2);
            return '<a:srgbClr val="' . $h . '"><a:alpha val="' . (max(0, min(100, (int)$a)) * 1000) . '"/></a:srgbClr>';
        }
        return '<a:srgbClr val="' . $c . '"/>';
    }

    /** null | 'RRGGBB[@a]' | 'G:burchak:c1[@a]:c2[@a]' (chiziqli gradient). */
    private static function fillXmlOf(?string $fill): string
    {
        if (!$fill) {
            return '<a:noFill/>';
        }
        if (str_starts_with($fill, 'G:')) {
            $p = explode(':', $fill);
            return '<a:gradFill rotWithShape="1"><a:gsLst><a:gs pos="0">' . self::clr($p[2]) . '</a:gs><a:gs pos="100000">' . self::clr($p[3])
                . '</a:gs></a:gsLst><a:lin ang="' . ((int)$p[1] * 60000) . '" scaled="0"/></a:gradFill>';
        }
        return '<a:solidFill>' . self::clr($fill) . '</a:solidFill>';
    }

    /** PRO: dark palitrada "qattiq" ranglar o'rniga shaffof oq (shisha) ishlatiladi — fon rasm ko'rinib turadi. */
    private static function glass(?string $fill, ?string $line): array
    {
        $P = self::$P;
        if ($fill === $P['card']) {
            $fill = 'FFFFFF@9';
        } elseif ($fill === $P['soft']) {
            $fill = 'FFFFFF@14';
        } elseif ($fill === $P['hair']) {
            $fill = 'FFFFFF@22';
        }
        if ($line === $P['hair']) {
            $line = 'FFFFFF@24';
        } elseif ($line === $P['acc']) {
            $line = $P['acc'] . '@60';
        } elseif ($line === $P['line']) {
            $line = $P['acc'] . '@45';
        }
        return [$fill, $line];
    }

    private static function shape(
        string $name, string $geom, int $x, int $y, int $w, int $h,
        ?string $fill, ?string $line = null, int $lineW = 19050,
        string $paras = '', string $anchor = 't', array $ins = [0, 0, 0, 0],
        int|array|null $adj = null, bool $txBox = false, int $rot = 0
    ): string {
        if (is_int($adj)) {
            $av = '<a:avLst><a:gd name="adj" fmla="val ' . $adj . '"/></a:avLst>';
        } elseif (is_array($adj)) {
            $av = '<a:avLst>';
            foreach ($adj as $k => $v) {
                $av .= '<a:gd name="' . $k . '" fmla="val ' . $v . '"/>';
            }
            $av .= '</a:avLst>';
        } else {
            $av = '<a:avLst/>';
        }
        if (self::isPro()) {
            $big = ($name === 'DefBox' || $name === 'Panel') && $fill === self::$P['acc'];
            [$fill, $line] = self::glass($fill, $line);
            if ($big) {             // katta rangli bloklar — shaffof rangli shisha (foto ko'rinib turadi)
                $fill = self::$P['acc'] . '@30';
                $line = self::$P['acc'] . '@80';
                $lineW = 19050;
            }
        }
        $fillXml = self::fillXmlOf($fill);
        $lineXml = $line
            ? '<a:ln w="' . $lineW . '"><a:solidFill>' . self::clr($line) . '</a:solidFill></a:ln>'
            : '<a:ln><a:noFill/></a:ln>';
        $newId = self::nid();
        if (self::$recording) {
            self::$animIds[] = $newId;
        }
        $tx = $paras !== ''
            ? '<p:txBody><a:bodyPr wrap="square" lIns="' . $ins[0] . '" tIns="' . $ins[1] . '" rIns="' . $ins[2]
              . '" bIns="' . $ins[3] . '" anchor="' . $anchor . '" rtlCol="0"><a:noAutofit/></a:bodyPr><a:lstStyle/>'
              . $paras . '</p:txBody>'
            : '';
        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $newId . '" name="' . $name . '"/><p:cNvSpPr'
            . ($txBox ? ' txBox="1"' : '') . '/><p:nvPr/></p:nvSpPr><p:spPr><a:xfrm' . ($rot ? ' rot="' . $rot . '"' : '')
            . '><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $w . '" cy="' . $h . '"/></a:xfrm><a:prstGeom prst="'
            . $geom . '">' . $av . '</a:prstGeom>' . $fillXml . $lineXml . '</p:spPr>' . $tx . '</p:sp>';
    }

    private static function textBox(string $name, int $x, int $y, int $w, int $h, string $paras, string $anchor = 't', array $ins = [0, 0, 0, 0]): string
    {
        return self::shape($name, 'rect', $x, $y, $w, $h, null, null, 0, $paras, $anchor, $ins, null, true);
    }

    private static function wrap(string $shapes): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
       xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
       xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">
<p:cSld><p:spTree>
<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>
<p:grpSpPr/>
' . $shapes . '
</p:spTree></p:cSld>
<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>' . self::effectsXml() . '
</p:sld>';
    }

    /** PRO: o'tish effekti (<p:transition>) va shakllarning ketma-ket paydo bo'lishi (<p:timing>). Tartib: clrMapOvr, transition, timing. */
    private static function effectsXml(): string
    {
        if (!self::isPro()) {
            return '';
        }
        $xml = '';
        if (Engine::c('PPTX_TRANSITIONS', true)) {
            $xml .= PptxAnim::transition((string)Engine::c('PPTX_TRANSITION_STYLE', 'fade'));
        }
        if (Engine::c('PPTX_ANIMATIONS', true) && self::$animIds) {
            $xml .= PptxAnim::timing(self::$animIds);
        }
        return $xml;
    }

    /** Slayd sarlavhasi (title placeholder). */
    private static function titleSp(string $title, int $x, int $y, int $w, int $h, string $color, string $algn, int $sz, string $anchor = 'ctr'): string
    {
        return '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Title"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $w . '" cy="' . $h . '"/></a:xfrm><a:noFill/></p:spPr>'
            . '<p:txBody><a:bodyPr lIns="0" tIns="0" rIns="0" bIns="0" anchor="' . $anchor . '"><a:noAutofit/></a:bodyPr><a:lstStyle/><a:p><a:pPr algn="' . $algn . '"/>'
            . self::run($title, $sz, $color, true, false, true) . '</a:p></p:txBody></p:sp>';
    }

    // ------------------------------------------------------------------ text fitting

    /** Matn berilgan quti ichiga sig'ishi uchun shrift o'lchamini taxmin qiladi. */
    private static function fit(array $paras, int $wEmu, int $hEmu, int $max, int $min): int
    {
        $wPt = max(10, $wEmu / 12700);
        $hPt = $hEmu / 12700;
        $fw = self::$S['fw'];
        for ($sz = $max; $sz >= $min; $sz--) {
            $tot = 0.0;
            $ok = true;
            foreach ($paras as [$t, $bold, $delta, $gap]) {
                $s = $sz + $delta;
                $longest = 0;
                foreach (preg_split('/\s+/u', $t) as $wd) {
                    $longest = max($longest, self::len($wd));
                }
                if ($longest * $s * ($bold ? 0.58 : 0.54) * $fw > $wPt) {
                    $ok = false;
                    break;
                }
                $cpl = max(1, (int)floor($wPt / ($s * ($bold ? 0.56 : 0.52) * $fw)));
                $lines = max(1, (int)ceil(self::len($t) / $cpl * 1.07));
                $tot += $lines * $s * 1.2 + $gap;
            }
            if ($ok && $tot <= $hPt) {
                return $sz;
            }
        }
        return $min;
    }

    private static function pointParas(array $p, int $sz, string $headColor, int $gap = 3): string
    {
        $out = '';
        if ($p['head'] !== '') {
            $out .= self::para(self::run($p['head'], $sz + 1, $headColor, true, false, true), 'l', $p['text'] !== '' ? $gap : 0);
        }
        if ($p['text'] !== '') {
            $out .= self::para(self::run($p['text'], $sz, self::$P['txt']), 'l', 0);
        }
        return $out;
    }

    private static function pointFitDesc(array $p, int $gap = 3): array
    {
        $d = [];
        if ($p['head'] !== '') {
            $d[] = [$p['head'], true, 1, $p['text'] !== '' ? $gap : 0];
        }
        if ($p['text'] !== '') {
            $d[] = [$p['text'], false, 0, 0];
        }
        return $d;
    }

    private static function commonSize(array $pts, int $w, int $h, int $max, int $min): int
    {
        $sz = $max;
        foreach ($pts as $p) {
            $sz = min($sz, self::fit(self::pointFitDesc($p), $w, $h, $max, $min));
        }
        return $sz;
    }

    /** Haddan tashqari uzun matnni so'z chegarasida qisqartiradi (slayddan chiqib ketmasligi uchun). */
    private static function clip(string $t, int $max): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
        if (self::len($t) <= $max) {
            return $t;
        }
        $cut = function_exists('mb_substr') ? mb_substr($t, 0, $max) : substr($t, 0, $max);
        $sp = strrpos($cut, ' ');
        if ($sp !== false && $sp > $max * 0.6) {
            $cut = substr($cut, 0, $sp);
        }
        return rtrim($cut, " ,;:-") . '…';
    }

    /** Matn eng kichik shriftda ham qutiga sig'maydigan bo'lsa, so'z chegarasida qisqartiradi. */
    private static function clipTo(string $t, int $wEmu, int $hEmu, int $minSz, bool $hasHead): string
    {
        $wPt = max(10, $wEmu / 12700);
        $hPt = $hEmu / 12700;
        $lines = (int)floor($hPt / ($minSz * 1.2)) - ($hasHead ? 1 : 0);
        $cpl = (int)floor($wPt / ($minSz * 0.52 * self::$S['fw']));
        $cap = max(30, (int)floor(max(1, $lines) * $cpl * 0.88));
        return self::clip($t, $cap);
    }

    /** Barcha nuqtalar matnini berilgan quti hajmiga moslab qisqartiradi. */
    private static function shrinkPts(array $pts, int $w, int $h, int $minSz): array
    {
        foreach ($pts as $i => $p) {
            $pts[$i]['text'] = self::clipTo($p['text'], $w, $h, $minSz, $p['head'] !== '');
        }
        return $pts;
    }

    public static function points(array $slide): array
    {
        $raw = $slide['points'] ?? ($slide['bullets'] ?? []);
        $out = [];
        foreach ($raw as $p) {
            if (is_string($p)) {
                $t = self::clip($p, 260);
                if ($t !== '') {
                    $out[] = ['head' => '', 'text' => $t, 'side' => ''];
                }
            } elseif (is_array($p)) {
                $h = self::clip((string)($p['head'] ?? ''), 70);
                $t = self::clip((string)($p['text'] ?? ''), 260);
                if ($h !== '' || $t !== '') {
                    $out[] = ['head' => $h, 'text' => $t, 'side' => (string)($p['side'] ?? '')];
                }
            }
        }
        return $out;
    }

    public static function flatBullets(array $slide): array
    {
        $out = [];
        $lead = trim((string)($slide['lead'] ?? ''));
        if ($lead !== '') {
            $out[] = $lead;
        }
        foreach (self::points($slide) as $p) {
            $out[] = $p['head'] !== '' && $p['text'] !== '' ? $p['head'] . ': ' . $p['text'] : ($p['head'] . $p['text']);
        }
        return $out;
    }

    private static function pictureXml(string $imagePath, int $bx, int $by, int $bw, int $bh, string $border): string
    {
        $x = $bx; $y = $by; $w = $bw; $h = $bh;
        $size = @getimagesize($imagePath);
        if ($size && $size[0] > 0 && $size[1] > 0) {
            $ir = $size[0] / $size[1];
            $br = $bw / $bh;
            if ($ir > $br) {
                $h = (int)round($bw / $ir);
                $y = $by + (int)round(($bh - $h) / 2);
            } else {
                $w = (int)round($bh * $ir);
                $x = $bx + (int)round(($bw - $w) / 2);
            }
        }
        $geom = self::$S['card']['geom'] === 'rect' ? 'rect' : 'roundRect';
        $av = $geom === 'roundRect' ? '<a:avLst><a:gd name="adj" fmla="val 6000"/></a:avLst>' : '<a:avLst/>';
        return '<p:pic><p:nvPicPr><p:cNvPr id="' . self::nid() . '" name="Picture"/><p:cNvPicPr/><p:nvPr/></p:nvPicPr>'
            . '<p:blipFill><a:blip r:embed="rId2"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
            . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $w . '" cy="' . $h . '"/></a:xfrm>'
            . '<a:prstGeom prst="' . $geom . '">' . $av . '</a:prstGeom>'
            . '<a:ln w="19050"><a:solidFill><a:srgbClr val="' . $border . '"/></a:solidFill></a:ln></p:spPr></p:pic>';
    }

    // ------------------------------------------------------------------ skin primitives

    /** Karta (teriga qarab: konturli, chap chiziqli, tepa chiziqli, soyali, rangli fon yoki oddiy). */
    private static function card(string $name, int $x, int $y, int $w, int $h, string $col, ?string $fill = null): string
    {
        $c = self::$S['card'];
        $P = self::$P;
        $fill = $fill ?? $P['card'];
        $g = $c['geom'];
        $adj = $c['adj'];
        switch ($c['mode']) {
            case 'stripe':
                return self::shape($name, 'rect', $x, $y, $w, $h, $fill, $P['hair'], 9525)
                    . self::shape($name . 'Bar', 'rect', $x, $y, 80000, $h, $col);
            case 'topline':
                return self::shape($name, 'rect', $x, $y, $w, $h, $fill, $P['hair'], 9525)
                    . self::shape($name . 'Bar', 'rect', $x, $y, $w, 60000, $col);
            case 'shadow':
                return self::shape($name . 'Sh', $g, $x + 60000, $y + 60000, $w, $h, self::tint($col, 0.6), null, 0, '', 't', [0, 0, 0, 0], $adj)
                    . self::shape($name, $g, $x, $y, $w, $h, $fill, $col, $c['lw'], '', 't', [0, 0, 0, 0], $adj);
            case 'tint':
                return self::shape($name, $g, $x, $y, $w, $h, $fill === $P['card'] ? $P['soft'] : $fill, null, 0, '', 't', [0, 0, 0, 0], $adj);
            case 'plain':
                return self::shape($name, 'rect', $x, $y, $w, $h, $fill, $P['hair'], 12700);
            default: // outline
                return self::shape($name, $g, $x, $y, $w, $h, $fill, $col, $c['lw'], '', 't', [0, 0, 0, 0], $adj);
        }
    }

    /** Raqam belgisi. $solid=true bo'lsa matn ko'rinishidagi belgi o'rniga halqa chiziladi. */
    private static function badge(string $name, int $x, int $y, int $s, string $txt, int $fs, string $col, bool $solid = false): string
    {
        $b = self::$S['badge'];
        if ($b === 'text' && $solid) {
            $b = 'ring';
        }
        switch ($b) {
            case 'text':
                return self::textBox($name, $x, $y, $s, $s, self::para(self::run($txt, $fs + 10, $col, true, false, true), 'ctr'), 'ctr');
            case 'ring':
                return self::shape($name, 'ellipse', $x, $y, $s, $s, self::$P['card'], $col, 38100, self::para(self::run($txt, $fs, $col, true, false, true), 'ctr'), 'ctr');
            case 'diamond':
                $k = 1.28;
                $ns = (int)($s * $k);
                $off = (int)(($ns - $s) / 2);
                return self::shape($name, 'diamond', $x - $off, $y - $off, $ns, $ns, $col, null, 0, self::para(self::run($txt, max(9, $fs - 3), self::on($col), true, false, true), 'ctr'), 'ctr');
            case 'roundRect':
                return self::shape($name, 'roundRect', $x, $y, $s, $s, $col, null, 0, self::para(self::run($txt, $fs, self::on($col), true, false, true), 'ctr'), 'ctr', [0, 0, 0, 0], 22000);
            default: // ellipse | rect | hexagon
                return self::shape($name, $b, $x, $y, $s, $s, $col, null, 0, self::para(self::run($txt, $fs, self::on($col), true, false, true), 'ctr'), 'ctr');
        }
    }

    /** Kartalar/taqqoslash sarlavhasi. */
    private static function gridHead(string $name, int $x, int $y, int $w, int $h, string $text, int $sz, string $col, string $algn = 'ctr'): string
    {
        $mode = self::$S['head'];
        $tc = self::on($col);
        $paraC = self::para(self::run($text, $sz, $tc, true, false, true), $algn);
        $ins = [100000, 0, 100000, 0];
        switch ($mode) {
            case 'rect':
                return self::shape($name, 'rect', $x, $y, $w, $h, $col, null, 0, $paraC, 'ctr', $ins);
            case 'snip':
                return self::shape($name, 'snip2SameRect', $x, $y, $w, $h, $col, null, 0, $paraC, 'ctr', $ins, ['adj1' => 16000, 'adj2' => 0]);
            case 'pill':
                return self::shape($name, 'roundRect', $x + 90000, $y + 90000, $w - 180000, $h - 110000, $col, null, 0, $paraC, 'ctr', $ins, 50000);
            case 'line':
                $textCol = self::$P['head'];
                return self::textBox($name, $x + 150000, $y, $w - 300000, $h - 40000, self::para(self::run($text, $sz, $textCol, true, false, true), 'l'), 'b', [0, 0, 0, 40000])
                    . self::shape($name . 'Ln', 'rect', $x + 150000, $y + $h - 25000, $w - 300000, 28575, $col);
            default: // round
                return self::shape($name, 'round2SameRect', $x, $y, $w, $h, $col, null, 0, $paraC, 'ctr', $ins, ['adj1' => 12000, 'adj2' => 0]);
        }
    }

    private static function leadHeight(string $lead, int $short = 700000, int $long = 950000): int
    {
        return self::len($lead) > 170 ? $long : $short;
    }

    private static function leadBand(string $lead, int $y, int $h): string
    {
        $P = self::$P;
        $A = $P['acc'];
        $x = self::$x0;
        $w = self::$cw;
        $mode = self::$S['lead'];
        $inset = $mode === 'bar' ? 230000 : 200000;
        $sz = self::fit([[$lead, false, 0, 0]], $w - $inset - 200000, $h - 100000, 17, 12);
        $italic = $mode !== 'fill';
        $algn = $mode === 'pill' ? 'ctr' : 'l';
        $txt = self::textBox('LeadText', $x + $inset, $y, $w - $inset - 150000, $h, self::para(self::run($lead, $sz, $P['head'], false, $italic), $algn), 'ctr');
        switch ($mode) {
            case 'fill':
                return self::shape('LeadBand', 'rect', $x, $y, $w, $h, $P['soft']) . self::shape('LeadTop', 'rect', $x, $y, $w, 25400, $A) . $txt;
            case 'rule':
                return self::shape('LeadRuleT', 'rect', $x, $y, $w, 19050, $A) . self::shape('LeadRuleB', 'rect', $x, $y + $h - 19050, $w, 9525, $P['hair']) . $txt;
            case 'outline':
                return self::shape('LeadBand', 'roundRect', $x, $y, $w, $h, $P['card'], $A, 19050, '', 't', [0, 0, 0, 0], 12000) . $txt;
            case 'pill':
                return self::shape('LeadBand', 'roundRect', $x, $y, $w, $h, $P['soft'], null, 0, '', 't', [0, 0, 0, 0], 50000) . $txt;
            default: // bar
                return self::shape('LeadBand', 'roundRect', $x, $y, $w, $h, $P['soft'], null, 0, '', 't', [0, 0, 0, 0], 12000)
                    . self::shape('LeadBar', 'rect', $x, $y + 60000, 70000, $h - 120000, $A) . $txt;
        }
    }

    // ------------------------------------------------------------------ frame (fon, bezak, sarlavha)

    /** Slayd teglari (til bo'yicha) — I18n dagi tag_<tur> kalitlari. */
    private const TAG_KINDS = ['matn', 'tarif', 'bosqichlar', 'xronologiya', 'kartalar', 'taqqoslash', 'xulosa', 'reja'];

    /** Hujjat tili (design['lang']); setup() har safar o'rnatadi. */
    private static string $lang = 'uz';

    private static function frame(string $kind, string $title, string $body, int $n, int $total): string
    {
        $animIds = self::$animIds;       // PRO: faqat tana (body) shakllari animatsiya qilinadi
        self::$recording = false;
        $P = self::$P;
        $A = $P['acc'];
        $T = $P['acc2'];
        $W = self::W;
        $H = self::H;
        $x0 = self::$x0;
        $cw = self::$cw;
        $tag = I18n::t('tag_' . (in_array($kind, self::TAG_KINDS, true) ? $kind : 'matn'), self::$lang);
        $tagW = 260000 + self::len($tag) * 92000;
        $tagFont = 10;

        $deco = '';
        $tagXml = '';
        $numXml = self::textBox('SlideNumber', 8000000, 6560000, 1000000, 240000, self::para(self::run($n . ' / ' . $total, 12, 'A5A5A5'), 'r'));
        $tx = $x0; $tw = $cw; $ty = 540000; $th = 820000; $tc = $P['title']; $al = 'l';
        $pill = fn(int $x, int $y, string $fill, string $txtc) => self::shape('Tag', 'roundRect', $x, $y, $tagW, 250000, $fill, null, 0,
            self::para(self::run($tag, $tagFont, $txtc, true), 'ctr'), 'ctr', [0, 0, 0, 0], 50000);

        switch (self::$skin) {
            case 'band':
                $deco = self::shape('Band', 'rect', 0, 0, $W, 1300000, $A)
                    . self::shape('BandC1', 'ellipse', 7600000, -700000, 2000000, 2000000, self::mix($A, 'FFFFFF', 0.14))
                    . self::shape('BandC2', 'ellipse', 6800000, 700000, 700000, 700000, self::mix($A, 'FFFFFF', 0.1))
                    . self::shape('BandLine', 'rect', 0, 1300000, $W, 60000, $T);
                $tagXml = $pill($x0, 230000, 'FFFFFF', $A);
                $ty = 520000; $th = 700000; $tc = self::on($A); $tw = $cw - 1500000;
                break;

            case 'sidebar':
                $deco = self::shape('Side', 'rect', 0, 0, 650000, $H, $A)
                    . self::shape('SideLine', 'rect', 650000, 0, 25000, $H, $T)
                    . self::shape('SideC1', 'ellipse', 130000, 5700000, 390000, 390000, self::mix($A, 'FFFFFF', 0.22))
                    . self::shape('SideC2', 'ellipse', 230000, 6250000, 190000, 190000, self::mix($A, 'FFFFFF', 0.35))
                    . self::textBox('SideNum', 0, 330000, 650000, 520000, self::para(self::run((string)$n, 22, self::on($A), true, false, true), 'ctr'), 'ctr');
                $tagXml = self::textBox('Tag', $x0, 250000, 4500000, 250000, self::para(self::run($tag, 10, $A, true), 'l'), 'ctr');
                $numXml = self::textBox('SlideNumber', 7600000, 6560000, 1000000, 240000, self::para(self::run($n . ' / ' . $total, 12, $P['sub']), 'r'));
                break;

            case 'dark':
                $deco = self::shape('Ring1', 'ellipse', 7300000, -1300000, 3000000, 3000000, null, self::mix($P['bg'], $A, 0.4), 19050)
                    . self::shape('Ring2', 'ellipse', -900000, 5600000, 2200000, 2200000, null, self::mix($P['bg'], $A, 0.4), 19050)
                    . self::shape('Dot', 'ellipse', 8250000, 5950000, 480000, 480000, $P['deco'])
                    . self::shape('Dot2', 'ellipse', 7850000, 400000, 220000, 220000, $A);
                $tagXml = $pill($x0, 250000, $A, self::on($A));
                $numXml = self::textBox('SlideNumber', 8000000, 6560000, 1000000, 240000, self::para(self::run($n . ' / ' . $total, 12, $P['sub']), 'r'));
                break;

            case 'snip':
                $deco = self::shape('Tri1', 'rtTriangle', 6900000, 0, 2244000, 1500000, $P['deco'], null, 0, '', 't', [0, 0, 0, 0], null, false, 10800000)
                    . self::shape('Tri2', 'rtTriangle', 0, 5400000, 1700000, 1458000, $P['deco']);
                $tagXml = self::shape('Tag', 'rect', $x0, 250000, $tagW, 260000, $A, null, 0, self::para(self::run($tag, $tagFont, self::on($A), true), 'ctr'), 'ctr');
                $numXml = self::textBox('SlideNumber', 8000000, 6560000, 1000000, 240000, self::para(self::run($n . ' / ' . $total, 12, $P['sub']), 'r'));
                break;

            case 'underline':
                $deco = self::shape('Circ', 'ellipse', 7700000, -900000, 2400000, 2400000, $P['deco'])
                    . self::shape('Wave', 'wave', 0, 6380000, $W, 478000, $P['deco'])
                    . self::shape('TitleBar', 'rect', $x0, 1410000, 1000000, 60000, $A);
                $tagXml = self::shape('TagSq', 'rect', $x0, 330000, 110000, 110000, $A)
                    . self::textBox('Tag', $x0 + 200000, 270000, 4500000, 230000, self::para(self::run($tag, 10, $A, true), 'l'), 'ctr');
                $ty = 540000; $th = 820000;
                break;

            case 'bubble':
                $deco = self::shape('B1', 'ellipse', -700000, -700000, 1800000, 1800000, $P['deco'])
                    . self::shape('B2', 'ellipse', 7900000, 300000, 520000, 520000, $P['deco'])
                    . self::shape('B3', 'ellipse', 8450000, 850000, 300000, 300000, $P['deco'])
                    . self::shape('B4', 'ellipse', 350000, 6050000, 600000, 600000, $P['deco'])
                    . self::shape('B5', 'ellipse', 1100000, 6400000, 300000, 300000, $P['deco'])
                    . self::shape('B6', 'ellipse', 8050000, 5900000, 700000, 700000, $P['deco']);
                $tagXml = $pill((int)(($W - $tagW) / 2), 250000, $A, self::on($A));
                $al = 'ctr';
                $numXml = self::shape('SlideNumber', 'ellipse', 8300000, 6330000, 470000, 470000, $A, null, 0, self::para(self::run((string)$n, 13, self::on($A), true, false, true), 'ctr'), 'ctr');
                break;

            case 'minimal':
                $deco = self::shape('Edge', 'rect', 0, 0, 90000, $H, $A)
                    . self::shape('Rule', 'rect', $x0, 1400000, $cw, 12700, $P['hair'])
                    . self::shape('RuleAcc', 'rect', $x0, 1386000, 700000, 38100, $A);
                $tagXml = self::textBox('Tag', $x0, 250000, 4500000, 250000, self::para(self::run($tag, 10, $A, true), 'l'), 'ctr');
                $numXml = self::textBox('SlideNumber', 7000000, 6560000, 1572000, 240000, self::para(self::run(sprintf('%02d', $n) . ' / ' . sprintf('%02d', $total), 11, $P['sub']), 'r'));
                break;

            case 'frame':
                $deco = self::shape('FrameO', 'rect', 180000, 180000, $W - 360000, $H - 360000, null, $A, 19050)
                    . self::shape('FrameI', 'rect', 270000, 270000, $W - 540000, $H - 540000, null, $A, 9525);
                foreach ([[180000, 180000], [$W - 340000, 180000], [180000, $H - 340000], [$W - 340000, $H - 340000]] as $k => [$cx, $cy]) {
                    $deco .= self::shape('Corner' . $k, 'rect', $cx, $cy, 160000, 160000, $A);
                }
                $deco .= self::shape('DivL', 'rect', (int)($W / 2) - 1000000, 1440000, 880000, 12700, $A)
                    . self::shape('DivR', 'rect', (int)($W / 2) + 120000, 1440000, 880000, 12700, $A)
                    . self::shape('DivD', 'diamond', (int)($W / 2) - 70000, 1375000, 140000, 140000, $A);
                $tagXml = self::textBox('Tag', $x0, 330000, $cw, 230000, self::para(self::run($tag, 10, $A, true), 'ctr'), 'ctr');
                $ty = 600000; $th = 700000; $al = 'ctr';
                $numXml = self::textBox('SlideNumber', $x0, 6290000, $cw, 230000, self::para(self::run('— ' . $n . ' —', 11, $P['sub']), 'ctr'));
                break;

            case 'navy':
                $deco = self::shape('Top', 'rect', 0, 0, $W, 70000, $A)
                    . self::shape('Rule', 'rect', $x0, 1400000, $cw, 9525, $P['hair'])
                    . self::textBox('GhostNum', 6900000, 180000, 1700000, 1000000, self::para(self::run(sprintf('%02d', $n), 54, self::mix($P['bg'], $A, 0.28), true, false, true), 'r'), 'ctr')
                    . self::shape('Sq1', 'rect', 8000000, 6000000, 150000, 150000, $A)
                    . self::shape('Sq2', 'rect', 8230000, 6000000, 150000, 150000, self::mix($P['bg'], $A, 0.5))
                    . self::shape('Sq3', 'rect', 8460000, 6000000, 150000, 150000, self::mix($P['bg'], $A, 0.25));
                $tagXml = self::shape('Tag', 'rect', $x0, 250000, $tagW, 260000, $A, null, 0, self::para(self::run($tag, $tagFont, self::on($A), true), 'ctr'), 'ctr');
                $numXml = self::textBox('SlideNumber', 7000000, 6560000, 1572000, 240000, self::para(self::run($n . ' / ' . $total, 12, $P['sub']), 'r'));
                $tw = $cw - 1800000;
                break;

            case 'paper':
                $deco = self::shape('Rule1', 'rect', $x0, 1400000, $cw, 19050, $A)
                    . self::shape('Rule2', 'rect', $x0, 1440000, $cw, 9525, $A)
                    . self::shape('RuleB', 'rect', $x0, 6560000, $cw - 1300000, 9525, $A)
                    . self::shape('Ribbon', 'flowChartOffpageConnector', 7750000, 0, 560000, 960000, $A, null, 0,
                        self::para(self::run((string)$n, 18, self::on($A), true, false, true), 'ctr'), 't', [0, 170000, 0, 0]);
                $tagXml = self::textBox('Tag', $x0, 250000, 4500000, 250000, self::para(self::run($tag, 11, $A, true, true, true), 'l'), 'ctr');
                $numXml = self::textBox('SlideNumber', 7000000, 6520000, 1572000, 240000, self::para(self::run($n . ' / ' . $total, 11, $P['sub'], false, true), 'r'));
                $tw = $cw - 1000000;
                break;

            case 'tech':
                $deco = self::shape('NumBlock', 'rect', $x0, 330000, 820000, 820000, $A, null, 0,
                        self::para(self::run(sprintf('%02d', $n), 26, self::on($A), true, false, true), 'ctr'), 'ctr')
                    . self::shape('NumCorner', 'rect', $x0 + 820000, 1070000, 80000, 80000, $T)
                    . self::shape('TitleBar', 'rect', $x0 + 1000000, 1330000, 2200000, 40000, $A)
                    . self::shape('Sq1', 'rect', 7950000, 330000, 150000, 150000, $A)
                    . self::shape('Sq2', 'rect', 8180000, 330000, 150000, 150000, self::tint($A, 0.45))
                    . self::shape('Sq3', 'rect', 8410000, 330000, 150000, 150000, self::tint($A, 0.75));
                $tagXml = self::textBox('Tag', $x0 + 1000000, 320000, 4500000, 230000, self::para(self::run($tag, 10, $A, true), 'l'), 'ctr');
                $tx = $x0 + 1000000; $tw = $cw - 1000000; $ty = 560000; $th = 740000;
                $numXml = self::textBox('SlideNumber', 8000000, 6560000, 1000000, 240000, self::para(self::run($n . ' / ' . $total, 12, 'A5A5A5'), 'r'));
                break;

            case 'pro':
                $upTag = function_exists('mb_strtoupper') ? mb_strtoupper($tag) : strtoupper($tag);
                $deco = self::shape('TagDot', 'ellipse', $x0, 345000, 110000, 110000, $A);
                $tagXml = self::textBox('Tag', $x0 + 200000, 270000, 5500000, 260000, self::para(self::run($upTag, 10, $A, true, false, false, 300), 'l'), 'ctr');
                $ty = 600000; $th = 780000;
                $tc = 'FFFFFF';
                $deco .= self::shape('TitleBar', 'rect', $x0, 1400000, 760000, 50000, $A);
                $numXml = self::textBox('SlideNumber', 6600000, 6520000, 1972000, 240000,
                    self::para(self::run(sprintf('%02d', $n) . '  /  ' . sprintf('%02d', $total), 11, $P['sub'], false, false, false, 200), 'r'));
                break;

            default: // classic
                $deco = match ($n % 3) {
                    0 => self::shape('Deco', 'ellipse', 7500000, -1000000, 2600000, 2600000, $P['deco']),
                    1 => self::shape('Deco', 'ellipse', -1100000, 5700000, 2400000, 2400000, $P['deco'])
                        . self::shape('Deco2', 'ellipse', 8300000, 5900000, 900000, 900000, self::mix($P['deco'], $P['bg'], 0.2)),
                    default => self::shape('Deco', 'rtTriangle', 6900000, 0, 2244000, 1500000, $P['deco'])
                        . self::shape('Deco2', 'ellipse', -500000, 6100000, 1300000, 1300000, self::mix($P['deco'], $P['bg'], 0.2)),
                };
                $tagXml = $pill($x0, 250000, $A, self::on($A));
        }

        $len = self::len($title);
        $ts = $len > 62 ? 22 : ($len > 44 ? 24 : 28);
        $ts = self::fit([[$title, true, 0, 0]], $tw, $th, $ts, 18);
        $titleXml = self::titleSp($title, $tx, $ty, $tw, $th, $tc, $al, $ts);

        if (self::isPro()) {
            // Foydalanuvchi shakllari (karta, matn) ketma-ket paydo bo'ladi; fon va bezaklar animatsiyasiz
            return self::wrapPro(self::proBg(false, $n) . $deco . $tagXml . $titleXml . $body . $numXml, $animIds);
        }
        return self::wrap(self::shape('ContentBg', 'rect', 0, 0, $W, $H, $P['bg']) . $deco . $tagXml . $titleXml . $body . $numXml);
    }

    // ------------------------------------------------------------------ PRO fon

    private static function wrapPro(string $shapes, array $animIds): string
    {
        self::$animIds = $animIds;
        return self::wrap($shapes);
    }

    /** Fon rasm — butun slayd, 4:3 ga "cover" usulida qirqiladi (srcRect). */
    private static function bgPicXml(string $path): string
    {
        $crop = '';
        $sz = @getimagesize($path);
        if ($sz && $sz[0] > 0 && $sz[1] > 0) {
            $ir = $sz[0] / $sz[1];
            $br = self::W / self::H;
            if ($ir > $br + 0.01) {
                $c = (int)round((1 - $br / $ir) / 2 * 100000);
                $crop = '<a:srcRect l="' . $c . '" r="' . $c . '"/>';
            } elseif ($ir < $br - 0.01) {
                $c = (int)round((1 - $ir / $br) / 2 * 100000);
                $crop = '<a:srcRect t="' . $c . '" b="' . $c . '"/>';
            }
        }
        return '<p:pic><p:nvPicPr><p:cNvPr id="' . self::nid() . '" name="Fon rasm"/><p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr>'
            . '<p:blipFill><a:blip r:embed="rId2"/>' . $crop . '<a:stretch><a:fillRect/></a:stretch></p:blipFill>'
            . '<p:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . self::W . '" cy="' . self::H . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic>';
    }

    /** PRO fon: rasm bo'lsa — qoraytirilgan foto; bo'lmasa — gradient + topografik halqalar (rasmsiz ham chiroyli). */
    private static function proBg(bool $edge, int $n = 1): string
    {
        $P = self::$P;
        $A = $P['acc'];
        $bg = $P['bg'];
        $W = self::W;
        $H = self::H;
        $s = '';
        if (self::$bgImage && is_file(self::$bgImage)) {
            $s .= self::bgPicXml(self::$bgImage);
            if ($edge) {
                $s .= self::shape('Overlay', 'rect', 0, 0, $W, $H, "G:0:{$bg}@92:{$bg}@22")
                    . self::shape('OverlayB', 'rect', 0, (int)($H * 0.5), $W, (int)($H * 0.5), "G:90:{$bg}@0:{$bg}@82");
            } else {
                $s .= self::shape('Overlay', 'rect', 0, 0, $W, $H, "G:90:{$bg}@74:{$bg}@86");
            }
            return $s;
        }
        $s .= self::shape('Bg', 'rect', 0, 0, $W, $H, 'G:40:' . self::mix($bg, $A, $edge ? 0.30 : 0.20) . ':' . $bg);
        $cx = [7000000, 1900000, 7600000][$n % 3];
        $cy = [1900000, 5300000, 4900000][$n % 3];
        $glow = $edge ? 4200000 : 3000000;
        $s .= self::shape('Glow', 'ellipse', $cx - (int)($glow / 2), $cy - (int)($glow / 2), $glow, $glow, $A . '@' . ($edge ? 16 : 10));
        $rings = $edge ? 6 : 4;
        for ($i = 1; $i <= $rings; $i++) {
            $rw = (int)(($edge ? 1500000 : 1150000) * $i);
            $rh = (int)($rw * 0.62);
            $s .= self::shape('Ring' . $i, 'ellipse', $cx - (int)($rw / 2), $cy - (int)($rh / 2), $rw, $rh, null, $A . '@' . ($edge ? 22 : 14), 12700);
        }
        return $s;
    }

    // ------------------------------------------------------------------ public entry points

    public static function contentSlide(array $slide, array $design, int $n, int $total, bool $hasImage, ?string $imagePath): string
    {
        self::setup($design);
        $kind = (string)($slide['kind'] ?? 'matn');
        $title = (string)($slide['title'] ?? '');
        $lead = self::clip((string)($slide['lead'] ?? ''), 320);
        $title = self::clip($title, 110);
        $pts = self::points($slide);
        self::$curN = $n;
        if (self::isPro()) {      // rasm yon tomonda emas, butun slayd foni bo'ladi
            self::$bgImage = ($hasImage && $imagePath && is_file($imagePath)) ? $imagePath : null;
            $hasImage = false;
            $imagePath = null;
        }

        if ($kind === 'reja') {
            $body = self::layoutAgenda($slide['items'] ?? []);
        } else {
            $body = match ($kind) {
                'tarif' => self::layoutDefinition($pts, $lead, $hasImage, $imagePath),
                'bosqichlar' => self::layoutSteps($pts, $lead),
                'xronologiya' => self::layoutTimeline($pts, $lead),
                'kartalar' => self::layoutCards($pts, $lead),
                'taqqoslash' => self::layoutCompare($pts, $lead, (string)($slide['left_title'] ?? ''), (string)($slide['right_title'] ?? '')),
                'xulosa' => self::layoutConclusion($pts, $lead),
                default => self::layoutText($pts, $lead, $n, $hasImage, $imagePath),
            };
            if (!in_array($kind, self::TAG_KINDS, true)) {
                $kind = 'matn';
            }
        }
        return self::frame($kind, $title, $body, $n, $total);
    }

    // ------------------------------------------------------------------ layouts

    /** matn: izoh-band + raqamli qatorlar (+ rasm). */
    private static function layoutText(array $pts, string $lead, int $n, bool $hasImage, ?string $imagePath): string
    {
        $P = self::$P;
        $A = $P['acc'];
        $H = $P['head'];
        $y = self::$y0;
        $body = '';
        if ($lead !== '') {
            $lh = self::leadHeight($lead);
            $body .= self::leadBand($lead, $y, $lh);
            $y += $lh + 140000;
        }
        $cardH = self::$yb - $y;
        $cardW = $hasImage ? (int)(self::$cw * 0.675) : self::$cw;
        $imgLeft = $hasImage && $n % 2 === 0;
        $cardX = $imgLeft ? self::$x0 + self::$cw - $cardW : self::$x0;
        $body .= self::card('Card', $cardX, $y, $cardW, $cardH, $A);

        $pts = array_slice($pts, 0, 7);
        $N = max(1, count($pts));
        $pad = 170000;
        $rowH = (int)(($cardH - 2 * $pad) / $N);
        $circ = 380000;
        $textX = $cardX + $pad + $circ + 190000;
        $textW = $cardW - ($textX - $cardX) - $pad;
        $pts = self::shrinkPts($pts, $textW, $rowH - 50000, 11);
        $sz = self::commonSize($pts, $textW, $rowH - 50000, 16, 11);

        foreach ($pts as $i => $p) {
            $ry = $y + $pad + $i * $rowH;
            $body .= self::badge('Num' . ($i + 1), $cardX + $pad + 30000, $ry + (int)(($rowH - $circ) / 2), $circ, (string)($i + 1), 14, $A);
            $body .= self::textBox('Point' . ($i + 1), $textX, $ry, $textW, $rowH, self::pointParas($p, $sz, $H), 'ctr');
            if ($i < $N - 1) {
                $body .= self::shape('Sep', 'rect', $textX, $ry + $rowH - 4000, $textW, 9525, self::$S['dark'] ? $P['hair'] : self::tint($A, 0.8));
            }
        }

        if ($hasImage && $imagePath) {
            $imgW = self::$cw - $cardW - 200000;
            $imgX = $imgLeft ? self::$x0 : $cardX + $cardW + 200000;
            $body .= self::pictureXml($imagePath, $imgX, $y, $imgW, $cardH, $A);
        }
        return $body;
    }

    /** tarif: katta rangli ta'rif bloki + asosiy belgilar mini-kartalari. */
    private static function layoutDefinition(array $pts, string $lead, bool $hasImage, ?string $imagePath): string
    {
        $P = self::$P;
        $A = $P['acc'];
        $H = $P['head'];
        $c = self::$S['card'];
        $x0 = self::$x0;
        $cw = self::$cw;
        $body = '';
        $boxH = 2150000;
        $boxW = $hasImage ? (int)($cw * 0.64) : $cw;
        if ($lead === '' && $pts) {
            $lead = $pts[0]['text'] ?: $pts[0]['head'];
            array_shift($pts);
        }
        $on = self::isPro() ? 'FFFFFF' : self::on($A);
        $sz = self::fit([[$lead, false, 0, 0]], $boxW - 1100000, $boxH - 260000, 24, 13);
        $geom = $c['geom'] === 'foldedCorner' ? 'rect' : $c['geom'];
        $adj = is_int($c['adj']) ? 7000 : $c['adj'];
        if ($c['mode'] === 'shadow') {
            $body .= self::shape('DefSh', $geom, $x0 + 70000, self::$y0 + 70000, $boxW, $boxH, self::tint($A, 0.55), null, 0, '', 't', [0, 0, 0, 0], $adj);
        }
        $body .= self::shape('DefBox', $geom, $x0, self::$y0, $boxW, $boxH, $A, null, 0, '', 't', [0, 0, 0, 0], $adj);
        $body .= self::textBox('Quote', $x0 + 150000, self::$y0 + 20000, 800000, 900000, self::para(self::run('“', 72, self::mix($A, $on, 0.45), true, false, true)));
        $body .= self::textBox('DefText', $x0 + 850000, self::$y0, $boxW - 1100000, $boxH, self::para(self::run($lead, $sz, $on, false)), 'ctr');
        if ($hasImage && $imagePath) {
            $body .= self::pictureXml($imagePath, $x0 + $boxW + 200000, self::$y0, $cw - $boxW - 200000, $boxH, $A);
        }

        $pts = array_slice($pts, 0, 4);
        $N = count($pts);
        if ($N === 0) {
            return $body;
        }
        $y = self::$y0 + $boxH + 170000;
        $body .= self::textBox('KeyLabel', $x0, $y, $cw, 300000, self::para(self::run(I18n::t('key_features', self::$lang), 12, $H, true, false, true)), 'ctr');
        $y += 340000;
        $availH = self::$yb - $y;
        $gap = 150000;
        $rows = $N <= 3 ? [$N] : [2, 2];
        $rowH = (int)(($availH - $gap * (count($rows) - 1)) / count($rows));
        $k = 0;
        foreach ($rows as $ri => $cnt) {
            $cellW = (int)(($cw - $gap * ($cnt - 1)) / $cnt);
            for ($ci = 0; $ci < $cnt; $ci++) {
                if (!isset($pts[$k])) {
                    break;
                }
                $p = $pts[$k];
                $cx = $x0 + $ci * ($cellW + $gap);
                $cy = $y + $ri * ($rowH + $gap);
                $col = ($k % 2 === 0) ? $A : $P['acc2'];
                $body .= self::card('MiniCard' . ($k + 1), $cx, $cy, $cellW, $rowH, $col);
                $tw = $cellW - 460000;
                $p['text'] = self::clipTo($p['text'], $tw, $rowH - 130000, 10, $p['head'] !== '');
                $s = self::fit(self::pointFitDesc($p), $tw, $rowH - 130000, 15, 10);
                $body .= self::textBox('MiniText' . ($k + 1), $cx + 290000, $cy + 40000, $tw, $rowH - 80000, self::pointParas($p, $s, $H), 'ctr');
                $k++;
            }
        }
        return $body;
    }

    /** bosqichlar: teriga qarab strelka / blok / olti burchak / doira / chiziqli. */
    private static function layoutSteps(array $pts, string $lead): string
    {
        $P = self::$P;
        $A = $P['acc'];
        $T = $P['acc2'];
        $mode = self::$S['steps'];
        $x0 = self::$x0;
        $cw = self::$cw;
        $y = self::$y0;
        $body = '';
        if ($lead !== '') {
            $lh = self::leadHeight($lead);
            $body .= self::leadBand($lead, $y, $lh);
            $y += $lh + 160000;
        }
        $pts = array_slice($pts, 0, 5);
        $N = count($pts);
        if ($N === 0) {
            return $body;
        }
        $gap = $mode === 'chevron' ? 110000 : 130000;
        $colW = (int)(($cw - $gap * ($N - 1)) / $N);
        $chevH = $mode === 'circle' ? 640000 : ($mode === 'ruled' ? 700000 : 760000);
        $cardY = $y + $chevH + 140000;
        $cardH = self::$yb - $cardY;

        if ($mode === 'circle' && $N > 1) {
            $body .= self::shape('StepLine', 'rect', $x0 + (int)($colW / 2), $y + (int)($chevH / 2) - 15000, $cw - $colW, 30000, self::$S['dark'] ? $P['hair'] : self::tint($A, 0.55));
        }

        foreach ($pts as $i => $p) {
            $col = $N > 1 ? self::mix($A, $T, $i / ($N - 1)) : $A;
            $x = $x0 + $i * ($colW + $gap);
            $numTxt = (string)($i + 1);
            $on = self::on($col);
            $numPara = self::para(self::run($numTxt, 26, $on, true, false, true), 'ctr');
            switch ($mode) {
                case 'chevron':
                    $w = $i < $N - 1 ? $colW + (int)($gap * 0.9) : $colW;
                    $body .= self::shape('Step' . ($i + 1), $i === 0 ? 'homePlate' : 'chevron', $x, $y, $w, $chevH, $col, null, 0, $numPara, 'ctr', [0, 0, 0, 0], 32000);
                    break;
                case 'rect':
                    $body .= self::shape('Step' . ($i + 1), 'rect', $x, $y, $colW, $chevH, $col, null, 0, $numPara, 'ctr');
                    break;
                case 'snip':
                    $body .= self::shape('Step' . ($i + 1), 'snip2DiagRect', $x, $y, $colW, $chevH, $col, null, 0, $numPara, 'ctr', [0, 0, 0, 0], ['adj1' => 28000, 'adj2' => 0]);
                    break;
                case 'hexagon':
                    $body .= self::shape('Step' . ($i + 1), 'hexagon', $x, $y, $colW, $chevH, $col, null, 0, $numPara, 'ctr');
                    break;
                case 'pill':
                    $body .= self::shape('Step' . ($i + 1), 'roundRect', $x, $y, $colW, $chevH, $col, null, 0, $numPara, 'ctr', [0, 0, 0, 0], 50000);
                    break;
                case 'circle':
                    $body .= self::shape('Step' . ($i + 1), 'ellipse', $x + (int)(($colW - $chevH) / 2), $y, $chevH, $chevH, $col, self::$P['bg'], 38100,
                        self::para(self::run($numTxt, 22, $on, true, false, true), 'ctr'), 'ctr');
                    break;
                case 'ruled':
                    $body .= self::shape('StepRule' . ($i + 1), 'rect', $x, $y, $colW, 38100, $col)
                        . self::textBox('StepNum' . ($i + 1), $x, $y + 60000, $colW, $chevH - 60000, self::para(self::run(sprintf('%02d', $i + 1), 30, $col, true, false, true), 'l'), 'ctr');
                    break;
            }
            if ($mode === 'ruled') {
                $tw = $colW - 40000;
                $p['text'] = self::clipTo($p['text'], $tw, $cardH - 100000, 10, $p['head'] !== '');
                $s = self::fit(self::pointFitDesc($p, 5), $tw, $cardH - 100000, 15, 10);
                $body .= self::textBox('StepText' . ($i + 1), $x, $cardY, $tw, $cardH, self::pointParas($p, $s, $P['head'], 5), 't');
                if ($i < $N - 1) {
                    $body .= self::shape('StepDiv' . ($i + 1), 'rect', $x + $colW + (int)($gap / 2) - 3000, $cardY, 6350, $cardH - 100000, $P['hair']);
                }
            } else {
                $body .= self::card('StepCard' . ($i + 1), $x, $cardY, $colW, $cardH, $col);
                $tw = $colW - 240000;
                $p['text'] = self::clipTo($p['text'], $tw, $cardH - 280000, 10, $p['head'] !== '');
                $s = self::fit(self::pointFitDesc($p, 5), $tw, $cardH - 280000, 15, 10);
                $body .= self::textBox('StepText' . ($i + 1), $x + 120000, $cardY + 150000, $tw, $cardH - 220000, self::pointParas($p, $s, $P['head'], 5), 't');
            }
        }
        return $body;
    }

    /** xronologiya: vertikal chiziq va raqamli nuqtalar. */
    private static function layoutTimeline(array $pts, string $lead): string
    {
        $P = self::$P;
        $A = $P['acc'];
        $T = $P['acc2'];
        $x0 = self::$x0;
        $cw = self::$cw;
        $y = self::$y0;
        $body = '';
        if ($lead !== '') {
            $lh = self::leadHeight($lead, 650000, 850000);
            $body .= self::leadBand($lead, $y, $lh);
            $y += $lh + 140000;
        }
        $pts = array_slice($pts, 0, 5);
        $N = count($pts);
        if ($N === 0) {
            return $body;
        }
        $avail = self::$yb - $y;
        $gap = 110000;
        $slot = (int)(($avail - $gap * ($N - 1)) / $N);
        $lineX = $x0 + 260000;
        $body .= self::shape('TLine', 'rect', $lineX - 20000, $y + (int)($slot / 2), 40000, $avail - $slot, self::$S['dark'] ? $P['hair'] : self::tint($A, 0.4));
        $cardX = $x0 + 720000;
        $cardW = $cw - 720000;
        $pts = self::shrinkPts($pts, $cardW - 380000, $slot - 60000, 11);
        $sz = self::commonSize($pts, $cardW - 380000, $slot - 60000, 15, 11);
        foreach ($pts as $i => $p) {
            $cy = $y + $i * ($slot + $gap);
            $col = self::mix($A, $T, $N > 1 ? $i / ($N - 1) : 0);
            $d = 440000;
            $body .= self::badge('Dot' . ($i + 1), $lineX - (int)($d / 2), $cy + (int)(($slot - $d) / 2), $d, (string)($i + 1), 15, $col, true);
            $body .= self::card('TCard' . ($i + 1), $cardX, $cy, $cardW, $slot, $col, $i % 2 === 0 ? null : $P['soft']);
            $body .= self::textBox('TText' . ($i + 1), $cardX + 190000, $cy, $cardW - 380000, $slot, self::pointParas($p, $sz, $P['head'], 2), 'ctr');
        }
        return $body;
    }

    /** kartalar: sarlavhali kartalar setkasi. */
    private static function layoutCards(array $pts, string $lead): string
    {
        $P = self::$P;
        $A = $P['acc'];
        $T = $P['acc2'];
        $x0 = self::$x0;
        $cw = self::$cw;
        $y = self::$y0;
        $body = '';
        if ($lead !== '') {
            $lh = self::leadHeight($lead, 650000, 850000);
            $body .= self::leadBand($lead, $y, $lh);
            $y += $lh + 150000;
        }
        $pts = array_slice($pts, 0, 6);
        $N = count($pts);
        if ($N === 0) {
            return $body;
        }
        $layout = [1 => [1], 2 => [2], 3 => [3], 4 => [2, 2], 5 => [3, 2], 6 => [3, 3]][$N];
        $gap = 150000;
        $availH = self::$yb - $y;
        $rowH = (int)(($availH - $gap * (count($layout) - 1)) / count($layout));
        $single = count($layout) === 1;
        $k = 0;
        foreach ($layout as $ri => $cnt) {
            $cellW = (int)(($cw - $gap * ($cnt - 1)) / $cnt);
            for ($ci = 0; $ci < $cnt; $ci++) {
                $p = $pts[$k];
                $col = ($k % 2 === 0) ? $A : $T;
                $cx = $x0 + $ci * ($cellW + $gap);
                $cy = $y + $ri * ($rowH + $gap);
                $headH = $single ? 800000 : 620000;
                $body .= self::card('GCard' . ($k + 1), $cx, $cy, $cellW, $rowH, $col);
                $head = $p['head'] !== '' ? $p['head'] : ('Yo\'nalish ' . ($k + 1));
                $hs = self::fit([[$head, true, 0, 0]], $cellW - 300000, $headH - 60000, $single ? 20 : 17, 12);
                $body .= self::gridHead('GHead' . ($k + 1), $cx, $cy, $cellW, $headH, $head, $hs, $col);
                $tw = $cellW - 300000;
                $th = $rowH - $headH - 100000;
                $p['text'] = self::clipTo($p['text'], $tw, $th, 11, false);
                $s = self::fit([[$p['text'], false, 0, 0]], $tw, $th, $single ? 20 : 16, 11);
                $body .= self::textBox('GText' . ($k + 1), $cx + 150000, $cy + $headH + 50000, $tw, $th, self::para(self::run($p['text'], $s, $P['txt'])), 'ctr');
                $k++;
            }
        }
        return $body;
    }

    /** taqqoslash: ikki ustun va "VS" belgisi. */
    private static function layoutCompare(array $pts, string $lead, string $leftTitle, string $rightTitle): string
    {
        $P = self::$P;
        $A = $P['acc'];
        $T = $P['acc2'];
        $x0 = self::$x0;
        $cw = self::$cw;
        $y = self::$y0;
        $body = '';
        if ($lead !== '') {
            $lh = self::leadHeight($lead, 650000, 850000);
            $body .= self::leadBand($lead, $y, $lh);
            $y += $lh + 150000;
        }
        $left = [];
        $right = [];
        foreach ($pts as $p) {
            if ($p['side'] === 'chap' || $p['side'] === 'left') {
                $left[] = $p;
            } elseif ($p['side'] === 'ong' || $p['side'] === 'right') {
                $right[] = $p;
            }
        }
        if (!$left || !$right) {
            $half = (int)ceil(count($pts) / 2);
            $left = array_slice($pts, 0, $half);
            $right = array_slice($pts, $half);
        }
        $left = array_slice($left, 0, 4);
        $right = array_slice($right, 0, 4);
        $leftTitle = $leftTitle !== '' ? $leftTitle : 'Birinchi tomon';
        $rightTitle = $rightTitle !== '' ? $rightTitle : 'Ikkinchi tomon';

        $gap = 300000;
        $colW = (int)(($cw - $gap) / 2);
        $headH = 640000;
        $bodyH = self::$yb - $y;
        $rowsMax = max(1, count($left), count($right));
        $pad = 150000;
        $rowH = (int)(($bodyH - $headH - 2 * $pad) / $rowsMax);
        $left = self::shrinkPts($left, $colW - 2 * $pad - 300000, $rowH - 40000, 10);
        $right = self::shrinkPts($right, $colW - 2 * $pad - 300000, $rowH - 40000, 10);
        $sz = self::commonSize(array_merge($left, $right), $colW - 2 * $pad - 300000, $rowH - 40000, 15, 10);

        foreach ([[$left, $leftTitle, $A, $x0], [$right, $rightTitle, $T, $x0 + $colW + $gap]] as $ci => [$items, $name, $col, $x]) {
            $body .= self::card('CBody' . ($ci + 1), $x, $y, $colW, $bodyH, $col);
            $hs = self::fit([[$name, true, 0, 0]], $colW - 700000, $headH - 60000, 20, 13);
            $body .= self::gridHead('CHead' . ($ci + 1), $x, $y, $colW, $headH, $name, $hs, $col);
            $hc = self::$S['dark'] ? $P['head'] : ($col === $T ? $P['head'] : $col);
            foreach ($items as $i => $p) {
                $ry = $y + $headH + $pad + $i * $rowH;
                $body .= self::shape('Dot', 'ellipse', $x + $pad, $ry + 60000, 150000, 150000, $col);
                $body .= self::textBox('CText', $x + $pad + 270000, $ry, $colW - 2 * $pad - 270000, $rowH, self::pointParas($p, $sz, $hc, 2), 't');
            }
        }
        $body .= self::shape(
            'VS', 'ellipse', $x0 + $colW + (int)($gap / 2) - 290000, $y + (int)(($headH - 580000) / 2), 580000, 580000, $P['card'], $A, 38100,
            self::para(self::run('VS', 13, $P['head'], true, false, true), 'ctr'), 'ctr'
        );
        return $body;
    }

    /** xulosa: chap rangli panel + o'ngda raqamli xulosalar. */
    private static function layoutConclusion(array $pts, string $lead): string
    {
        $P = self::$P;
        $A = $P['acc'];
        $T = $P['acc2'];
        $c = self::$S['card'];
        $x0 = self::$x0;
        $cw = self::$cw;
        $pts = array_slice($pts, 0, 6);
        $N = max(1, count($pts));
        $panelW = (int)($cw * 0.33);
        $h = self::$yb - self::$y0;
        $geom = $c['geom'] === 'foldedCorner' ? 'rect' : $c['geom'];
        $adj = is_int($c['adj']) ? 6000 : $c['adj'];
        $on = self::isPro() ? 'FFFFFF' : self::on($A);
        $body = self::shape('Panel', $geom, $x0, self::$y0, $panelW, $h, $A, null, 0, '', 't', [0, 0, 0, 0], $adj);
        $body .= self::shape('PanelDeco', 'ellipse', $x0 + $panelW - 1350000, self::$y0 + $h - 1500000, 1350000, 1350000, self::mix($A, $on, 0.14));
        $panelText = $lead !== '' ? $lead : I18n::t('default_panel', self::$lang);
        $sz = self::fit([[$panelText, false, 0, 0]], $panelW - 400000, $h - 800000, 20, 12);
        $body .= self::textBox('PanelText', $x0 + 200000, self::$y0 + 200000, $panelW - 400000, $h - 900000, self::para(self::run($panelText, $sz, $on, false, true)), 'ctr');

        $x = $x0 + $panelW + 200000;
        $w = $cw - $panelW - 200000;
        $gap = 110000;
        $rowH = (int)(($h - $gap * ($N - 1)) / $N);
        $nb = 460000;
        $tw = $w - 240000 - $nb - 160000;
        $pts = self::shrinkPts($pts, $tw, $rowH - 50000, 11);
        $s = self::commonSize($pts, $tw, $rowH - 50000, 15, 11);
        foreach ($pts as $i => $p) {
            $ry = self::$y0 + $i * ($rowH + $gap);
            $body .= self::card('CRow' . ($i + 1), $x, $ry, $w, $rowH, $i % 2 === 0 ? $A : $T, $i % 2 === 0 ? null : $P['soft']);
            $body .= self::badge('CNum' . ($i + 1), $x + 150000, $ry + (int)(($rowH - $nb) / 2), $nb, (string)($i + 1), 16, $T, true);
            $body .= self::textBox('CText' . ($i + 1), $x + 240000 + $nb, $ry, $tw, $rowH, self::pointParas($p, $s, $P['head'], 2), 'ctr');
        }
        return $body;
    }

    /** reja: raqamli bo'limlar ro'yxati. */
    private static function layoutAgenda(array $items): string
    {
        $P = self::$P;
        $A = $P['acc'];
        $T = $P['acc2'];
        $items = array_slice(array_values(array_filter(array_map(fn($t) => self::clip((string)$t, 60), $items))), 0, 10);
        $N = count($items);
        if ($N === 0) {
            return '';
        }
        $cols = $N > 5 ? 2 : 1;
        $rows = (int)ceil($N / $cols);
        $gapX = 200000;
        $gapY = 130000;
        $colW = (int)((self::$cw - $gapX * ($cols - 1)) / $cols);
        $availH = self::$yb - self::$y0;
        $rowH = min(820000, (int)(($availH - $gapY * ($rows - 1)) / $rows));
        $body = '';
        foreach ($items as $i => $t) {
            $cc = intdiv($i, $rows);
            $r = $i % $rows;
            $x = self::$x0 + $cc * ($colW + $gapX);
            $y = self::$y0 + $r * ($rowH + $gapY);
            $col = self::mix($A, $T, $N > 1 ? $i / ($N - 1) : 0);
            $nb = 520000;
            $s = self::fit([[$t, true, 0, 0]], $colW - $nb - 400000, $rowH - 80000, 19, 12);
            $body .= self::card('AItem' . ($i + 1), $x, $y, $colW, $rowH, $col);
            $body .= self::badge('ANum' . ($i + 1), $x + 140000, $y + (int)(($rowH - $nb) / 2), $nb, sprintf('%02d', $i + 1), 15, $col, true);
            $body .= self::textBox('AText' . ($i + 1), $x + 140000 + $nb + 170000, $y, $colW - $nb - 400000, $rowH, self::para(self::run($t, $s, $P['head'], true, false, true)), 'ctr');
        }
        return $body;
    }

    // ------------------------------------------------------------------ muqova va yakuniy slayd

    /** kind = 'title' yoki 'thanks'. Har bir terida o'z muqovasi bor. */
    public static function edgeSlide(array $slide, array $d, int $n, int $total): string
    {
        self::setup($d);
        $P = self::$P;
        $A = $P['acc'];
        $T = $P['acc2'];
        $W = self::W;
        $H = self::H;
        $isThanks = ($slide['kind'] ?? 'title') === 'thanks';
        $title = (string)($slide['title'] ?? '');
        $lines = array_values(array_filter(array_map('strval', $slide['bullets'] ?? [])));
        $pillText = $isThanks ? I18n::t('pill_end', self::$lang) : I18n::t('pill_presentation', self::$lang);
        $maxT = $isThanks ? 54 : 40;
        $variant = self::$S['edge'];
        self::$curN = $n;
        self::$recording = false;
        $s = '';
        $brandCol = $P['sub'];

        $info = function (int $x, int $y, int $w, int $h, string $color, string $algn, int $sz, bool $italic = false) use ($lines) {
            if (!$lines) {
                return '';
            }
            $p = '';
            foreach ($lines as $l) {
                $p .= self::para(self::run($l, $sz, $color, false, $italic), $algn, 6);
            }
            return self::textBox('Info', $x, $y, $w, $h, $p, 't');
        };
        $titleAt = function (int $x, int $y, int $w, int $h, string $color, string $algn) use ($title, $maxT) {
            $sz = self::fit([[$title, true, 0, 0]], $w, $h, $maxT, 22);
            return self::titleSp($title, $x, $y, $w, $h, $color, $algn, $sz);
        };
        $pillAt = fn(int $x, int $y, string $fill, string $txtc, ?string $line = null) => self::shape(
            'Pill', 'roundRect', $x, $y, 1800000, 340000, $fill, $line, 19050,
            self::para(self::run($pillText, 12, $txtc, true), 'ctr'), 'ctr', [0, 0, 0, 0], 50000);
        $ctr = $isThanks ? 'ctr' : 'l';
        self::$animIds = [];
        $bx = $isThanks ? 685800 : 950000;
        $bw = $isThanks ? 7772400 : 7200000;

        switch ($variant) {
            case 'pro': // foto fon (yoki gradient) + shisha ma'lumot kartasi
                $img = (string)($slide['image'] ?? '');
                self::$bgImage = ($img !== '' && is_file($img)) ? $img : null;
                self::$recording = false;
                $up = fn(string $t) => function_exists('mb_strtoupper') ? mb_strtoupper($t) : strtoupper($t);
                $s .= self::proBg(true, $n);
                if ($isThanks) {
                    $s .= self::textBox('Pill', $bx, 2000000, $bw, 300000, self::para(self::run($up($pillText), 13, $A, true, false, false, 400), 'ctr'), 'ctr')
                        . $titleAt($bx, 2400000, $bw, 1500000, 'FFFFFF', 'ctr')
                        . self::shape('Rule', 'rect', (int)(($W - 1200000) / 2), 4050000, 1200000, 50000, $A);
                    if ($lines) {
                        $ip = '';
                        foreach ($lines as $l) {
                            $ip .= self::para(self::run($l, 18, 'DDE6EE'), 'ctr', 6);
                        }
                        $s .= self::textBox('Info', $bx, 4300000, $bw, 1100000, $ip, 't');
                    }
                } else {
                    $s .= self::shape('PillDot', 'ellipse', 700000, 1560000, 120000, 120000, $A)
                        . self::textBox('Pill', 920000, 1480000, 5500000, 280000, self::para(self::run($up($pillText), 13, $A, true, false, false, 400), 'l'), 'ctr')
                        . $titleAt(700000, 1900000, 7300000, 2300000, 'FFFFFF', 'l')
                        . self::shape('Bar', 'rect', 700000, 4330000, 900000, 60000, $A);
                    if ($lines) {
                        $ih = min(1700000, 300000 + count($lines) * 400000);
                        $ip = '';
                        foreach ($lines as $k => $l) {
                            $ip .= self::para(self::run($l, 17, $k === 0 ? $A : 'DDE6EE', $k === 0), 'l', 4);
                        }
                        $s .= self::shape('InfoCard', 'roundRect', 700000, 4720000, 6300000, $ih, 'FFFFFF@10', 'FFFFFF@26', 12700, '', 't', [0, 0, 0, 0], 12000)
                            . self::textBox('Info', 960000, 4720000, 5800000, $ih, $ip, 'ctr');
                    }
                }
                $brandCol = 'FFFFFF';
                break;

            case 'rings': // qora fon + halqalar
                $ring = self::mix($P['bg'], $A, 0.4);
                $s .= self::shape('Bg', 'rect', 0, 0, $W, $H, $P['bg'])
                    . self::shape('R1', 'ellipse', 5500000, -2300000, 6000000, 6000000, null, $ring, 28575)
                    . self::shape('R2', 'ellipse', 5900000, -1900000, 5200000, 5200000, null, $ring, 12700)
                    . self::shape('R3', 'ellipse', -1900000, 4600000, 4200000, 4200000, null, $ring, 19050)
                    . self::shape('D1', 'ellipse', 7700000, 5200000, 560000, 560000, $A)
                    . self::shape('D2', 'ellipse', 1200000, 700000, 260000, 260000, $P['deco']);
                if ($isThanks) {
                    $s .= $titleAt($bx, 2400000, $bw, 1300000, 'FFFFFF', 'ctr') . self::shape('Rule', 'rect', 3972000, 3800000, 1200000, 45720, $A)
                        . $info($bx, 4000000, $bw, 900000, $P['txt'], 'ctr', 20);
                } else {
                    $s .= $pillAt(950000, 1750000, $A, self::on($A)) . self::shape('Bar', 'rect', 685800, 2400000, 90000, 1700000, $A)
                        . $titleAt(950000, 2400000, 7000000, 1700000, 'FFFFFF', 'l') . $info(950000, 4400000, 7000000, 1500000, $P['txt'], 'l', 18);
                }
                $brandCol = 'FFFFFF';
                break;

            case 'navy': // qora fon + rangli lenta
                $s .= self::shape('Bg', 'rect', 0, 0, $W, $H, $P['bg'])
                    . self::shape('Top', 'rect', 0, 0, $W, 70000, $A)
                    . self::shape('Band', 'rect', 0, 2250000, $W, 2300000, $A)
                    . self::shape('BandC', 'ellipse', 6900000, 1500000, 3000000, 3000000, self::mix($A, '000000', 0.12))
                    . self::shape('Sq1', 'rect', 7950000, 5900000, 160000, 160000, $A)
                    . self::shape('Sq2', 'rect', 8200000, 5900000, 160000, 160000, self::mix($P['bg'], $A, 0.5))
                    . self::shape('Sq3', 'rect', 8450000, 5900000, 160000, 160000, self::mix($P['bg'], $A, 0.25));
                $on = self::on($A);
                if ($isThanks) {
                    $s .= $titleAt($bx, 2450000, $bw, 1900000, $on, 'ctr') . $info($bx, 4850000, $bw, 900000, $P['txt'], 'ctr', 20);
                } else {
                    $s .= $pillAt(950000, 1700000, $P['bg'], $P['head'], $A)
                        . $titleAt(950000, 2400000, 6400000, 1900000, $on, 'l') . $info(950000, 4850000, 7200000, 1400000, $P['txt'], 'l', 18);
                }
                $brandCol = 'FFFFFF';
                break;

            case 'band': // yuqorida katta rangli blok
                $s .= self::shape('Bg', 'rect', 0, 0, $W, $H, $P['bg'])
                    . self::shape('Top', 'rect', 0, 0, $W, 4300000, $A)
                    . self::shape('C1', 'ellipse', 6200000, -1500000, 4600000, 4600000, self::mix($A, 'FFFFFF', 0.12))
                    . self::shape('C2', 'ellipse', -1200000, 1500000, 2800000, 2800000, self::mix($A, 'FFFFFF', 0.08))
                    . self::shape('Line', 'rect', 0, 4300000, $W, 70000, $T);
                $on = self::on($A);
                if ($isThanks) {
                    $s .= $titleAt($bx, 1500000, $bw, 2300000, $on, 'ctr') . $info($bx, 4750000, $bw, 1200000, $P['txt'], 'ctr', 20);
                } else {
                    $s .= $pillAt(950000, 1150000, 'FFFFFF', $A) . $titleAt(950000, 1700000, 7000000, 2100000, $on, 'l')
                        . $info(950000, 4800000, 7200000, 1500000, $P['txt'], 'l', 18);
                }
                break;

            case 'side': // chap tomonda rangli ustun
                $col2 = self::mix($A, 'FFFFFF', 0.12);
                $s .= self::shape('Bg', 'rect', 0, 0, $W, $H, $P['bg'])
                    . self::shape('Col', 'rect', 0, 0, 3000000, $H, $A)
                    . self::shape('C1', 'ellipse', -900000, 5200000, 3000000, 3000000, $col2)
                    . self::shape('C2', 'ellipse', 1500000, -700000, 2400000, 2400000, self::mix($A, 'FFFFFF', 0.1))
                    . self::shape('C3', 'ellipse', 2100000, 3000000, 500000, 500000, self::mix($A, 'FFFFFF', 0.22))
                    . self::shape('Line', 'rect', 3000000, 0, 40000, $H, $T);
                $s .= $pillAt(600000, 3300000, 'FFFFFF', $A)
                    . $titleAt(3500000, 1900000, 5100000, 2200000, $P['title'], 'l')
                    . self::shape('Rule', 'rect', 3500000, 4250000, 1400000, 70000, $A)
                    . $info(3500000, 4500000, 5100000, 1500000, $P['txt'], 'l', 18);
                $brandCol = self::on($A);
                break;

            case 'snip': // markazda qirrali karta
                $s .= self::shape('Bg', 'rect', 0, 0, $W, $H, $P['bg'])
                    . self::shape('Tri1', 'rtTriangle', 6900000, 0, 2244000, 1500000, $P['deco'], null, 0, '', 't', [0, 0, 0, 0], null, false, 10800000)
                    . self::shape('Tri2', 'rtTriangle', 0, 5300000, 1900000, 1558000, $P['deco'])
                    . self::shape('Sh', 'snip2DiagRect', 1090000, 1790000, $W - 2000000, 2900000, self::tint($A, 0.55), null, 0, '', 't', [0, 0, 0, 0], ['adj1' => 10000, 'adj2' => 0])
                    . self::shape('Card', 'snip2DiagRect', 1000000, 1700000, $W - 2000000, 2900000, $A, null, 0, '', 't', [0, 0, 0, 0], ['adj1' => 10000, 'adj2' => 0])
                    . self::shape('Pill', 'rect', (int)(($W - 1800000) / 2), 1050000, 1800000, 340000, $T, null, 0, self::para(self::run($pillText, 12, self::on($T), true), 'ctr'), 'ctr')
                    . $titleAt(1500000, 1950000, $W - 3000000, 2400000, self::on($A), 'ctr')
                    . $info(900000, 5000000, $W - 1800000, 1200000, $P['txt'], 'ctr', 18);
                break;

            case 'underline': // katta doira + qalin chiziq
                $s .= self::shape('Bg', 'rect', 0, 0, $W, $H, $P['bg'])
                    . self::shape('C1', 'ellipse', 5600000, -1700000, 5600000, 5600000, $P['deco'])
                    . self::shape('C2', 'ellipse', 6900000, 4700000, 2800000, 2800000, self::mix($P['deco'], $P['bg'], 0.4))
                    . self::shape('C3', 'ellipse', 500000, 5800000, 800000, 800000, $P['deco'])
                    . self::shape('Sq', 'rect', 950000, 1850000, 110000, 110000, $A)
                    . self::textBox('Pill', 1150000, 1790000, 4000000, 230000, self::para(self::run($pillText, 12, $A, true), 'l'), 'ctr');
                if ($isThanks) {
                    $s .= $titleAt($bx, 2300000, $bw, 1500000, $P['title'], 'ctr') . self::shape('Bar', 'rect', 3772000, 3950000, 1600000, 90000, $A)
                        . $info($bx, 4250000, $bw, 1300000, $P['txt'], 'ctr', 20);
                } else {
                    $s .= $titleAt(950000, 2200000, 6200000, 1800000, $P['title'], 'l') . self::shape('Bar', 'rect', 950000, 4150000, 1600000, 90000, $A)
                        . $info(950000, 4450000, 6200000, 1500000, $P['txt'], 'l', 18);
                }
                break;

            case 'bubble': // markazda katta doira
                $dia = 4700000;
                $cx = (int)(($W - $dia) / 2);
                $s .= self::shape('Bg', 'rect', 0, 0, $W, $H, $P['bg'])
                    . self::shape('B1', 'ellipse', -700000, -700000, 1800000, 1800000, $P['deco'])
                    . self::shape('B2', 'ellipse', 7800000, 300000, 700000, 700000, $P['deco'])
                    . self::shape('B3', 'ellipse', 8400000, 1100000, 350000, 350000, $P['deco'])
                    . self::shape('B4', 'ellipse', 400000, 5200000, 900000, 900000, $P['deco'])
                    . self::shape('B5', 'ellipse', 1500000, 5900000, 400000, 400000, $P['deco'])
                    . self::shape('B6', 'ellipse', 7900000, 5300000, 800000, 800000, $P['deco'])
                    . self::shape('Circ', 'ellipse', $cx, 600000, $dia, $dia, $A)
                    . self::shape('Ring', 'ellipse', $cx + 150000, 750000, $dia - 300000, $dia - 300000, null, self::mix($A, 'FFFFFF', 0.4), 19050)
                    . self::shape('Pill', 'roundRect', (int)(($W - 1800000) / 2), 1350000, 1800000, 340000, 'FFFFFF', null, 0,
                        self::para(self::run($pillText, 12, $A, true), 'ctr'), 'ctr', [0, 0, 0, 0], 50000)
                    . $titleAt($cx + 650000, 1850000, $dia - 1300000, 2300000, self::on($A), 'ctr')
                    . $info(900000, 5500000, $W - 1800000, 1200000, $P['head'], 'ctr', 18);
                break;

            case 'minimal': // oq fon, katta serif sarlavha
                $s .= self::shape('Bg', 'rect', 0, 0, $W, $H, $P['bg'])
                    . self::shape('Edge', 'rect', 0, 0, 90000, $H, $A)
                    . self::shape('Sq', 'rect', 950000, 900000, 320000, 320000, $A)
                    . self::textBox('Pill', 950000, 1450000, 5000000, 250000, self::para(self::run($pillText, 12, $A, true), 'l'), 'ctr')
                    . $titleAt(950000, 1950000, 7200000, 2100000, $P['title'], 'l')
                    . self::shape('RuleAcc', 'rect', 950000, 4250000, 2000000, 38100, $A)
                    . self::shape('Rule', 'rect', 950000, 4290000, 7200000, 9525, $P['hair'])
                    . $info(950000, 4600000, 7200000, 1400000, $P['sub'], 'l', 17);
                break;

            case 'frame': // qo'sh ramka
                $s .= self::shape('Bg', 'rect', 0, 0, $W, $H, $P['bg'])
                    . self::shape('FrameO', 'rect', 180000, 180000, $W - 360000, $H - 360000, null, $A, 28575)
                    . self::shape('FrameI', 'rect', 290000, 290000, $W - 580000, $H - 580000, null, $A, 9525);
                foreach ([[180000, 180000], [$W - 340000, 180000], [180000, $H - 340000], [$W - 340000, $H - 340000]] as $k => [$cx, $cy]) {
                    $s .= self::shape('Corner' . $k, 'rect', $cx, $cy, 160000, 160000, $A);
                }
                $s .= self::textBox('Pill', 900000, 1700000, $W - 1800000, 280000, self::para(self::run($pillText, 12, $A, true), 'ctr'), 'ctr')
                    . $titleAt(1000000, 2150000, $W - 2000000, 1800000, $P['title'], 'ctr')
                    . self::shape('DivL', 'rect', (int)($W / 2) - 1300000, 4150000, 1180000, 12700, $A)
                    . self::shape('DivR', 'rect', (int)($W / 2) + 120000, 4150000, 1180000, 12700, $A)
                    . self::shape('DivD', 'diamond', (int)($W / 2) - 80000, 4070000, 160000, 160000, $A)
                    . $info(900000, 4450000, $W - 1800000, 1400000, $P['txt'], 'ctr', 18);
                break;

            case 'paper': // daftar: tilmoch + buklangan karta
                $s .= self::shape('Bg', 'rect', 0, 0, $W, $H, $P['bg'])
                    . self::shape('Ribbon', 'flowChartOffpageConnector', 7500000, 0, 700000, 1500000, $A)
                    . self::shape('Sh', 'rect', 1090000, 1890000, $W - 2000000, 2800000, self::tint($A, 0.65))
                    . self::shape('Card', 'foldedCorner', 1000000, 1800000, $W - 2000000, 2800000, $P['card'], $A, 28575, '', 't', [0, 0, 0, 0], 12000)
                    . self::textBox('Pill', 1000000, 1300000, $W - 2600000, 300000, self::para(self::run($pillText, 13, $A, true, true, true), 'ctr'), 'ctr')
                    . $titleAt(1500000, 2000000, $W - 3000000, 2000000, $P['title'], 'ctr')
                    . self::shape('R1', 'rect', 3200000, 4150000, $W - 6400000, 19050, $A)
                    . self::shape('R2', 'rect', 3200000, 4190000, $W - 6400000, 9525, $A)
                    . $info(900000, 5000000, $W - 1800000, 1200000, $P['txt'], 'ctr', 18, true);
                break;

            case 'tech': // chap panelda olti burchaklar
                $hexL = self::mix($A, 'FFFFFF', 0.4);
                $s .= self::shape('Bg', 'rect', 0, 0, $W, $H, $P['bg'])
                    . self::shape('Panel', 'rect', 0, 0, 3300000, $H, $A)
                    . self::shape('H1', 'hexagon', 400000, 700000, 1500000, 1300000, null, $hexL, 28575)
                    . self::shape('H2', 'hexagon', 1500000, 1700000, 1500000, 1300000, self::mix($A, 'FFFFFF', 0.14))
                    . self::shape('H3', 'hexagon', 400000, 2900000, 1500000, 1300000, null, $hexL, 19050)
                    . self::shape('H4', 'hexagon', 2200000, 4400000, 800000, 700000, self::mix($A, 'FFFFFF', 0.22))
                    . self::shape('Sq1', 'rect', 400000, 5900000, 160000, 160000, 'FFFFFF')
                    . self::shape('Sq2', 'rect', 650000, 5900000, 160000, 160000, $hexL)
                    . self::shape('Sq3', 'rect', 900000, 5900000, 160000, 160000, self::mix($A, 'FFFFFF', 0.2))
                    . self::textBox('Pill', 3700000, 1650000, 4800000, 250000, self::para(self::run($pillText, 12, $A, true), 'l'), 'ctr')
                    . $titleAt(3700000, 2050000, 4900000, 2050000, $P['title'], 'l')
                    . self::shape('Bar', 'rect', 3700000, 4250000, 2200000, 60000, $A)
                    . $info(3700000, 4500000, 4900000, 1500000, $P['txt'], 'l', 18);
                $brandCol = self::on($A);
                break;

            default: // full (klassik)
                $light = self::tint($A, 0.14);
                $lighter = self::tint($A, 0.22);
                $on = self::on($A);
                $s .= self::shape('EdgeBg', 'rect', 0, 0, $W, $H, $A);
                if ($isThanks) {
                    $s .= self::shape('C1', 'ellipse', -1500000, -1500000, 4200000, 4200000, $light)
                        . self::shape('C2', 'ellipse', 6700000, 4300000, 4600000, 4600000, $light)
                        . self::shape('C3', 'ellipse', 7600000, 700000, 900000, 900000, $lighter)
                        . self::shape('C4', 'ellipse', 900000, 5500000, 600000, 600000, $lighter)
                        . $titleAt($bx, 2400000, $bw, 1300000, $on, 'ctr') . self::shape('Rule', 'rect', 3972000, 3800000, 1200000, 45720, $on)
                        . $info($bx, 4000000, $bw, 900000, $on, 'ctr', 20);
                } else {
                    $s .= self::shape('C1', 'ellipse', 5500000, -2200000, 6200000, 6200000, $light)
                        . self::shape('C2', 'ellipse', -1800000, 4700000, 4400000, 4400000, $light)
                        . self::shape('C3', 'ellipse', 7900000, 4900000, 700000, 700000, $lighter)
                        . $pillAt(950000, 1750000, $A, $on, $on) . self::shape('Bar', 'rect', 685800, 2400000, 90000, 1700000, $on)
                        . $titleAt(950000, 2400000, 7000000, 1700000, $on, 'l') . $info(950000, 4400000, 7000000, 1500000, $on, 'l', 18);
                }
                $brandCol = $on;
        }

        if (defined('SLIDE_BRANDING_TAG') && SLIDE_BRANDING_TAG !== '') {
            $s .= self::textBox('Brand', 685800, 6300000, 4000000, 300000, self::para(self::run(SLIDE_BRANDING_TAG, 14, $brandCol)));
        }
        return self::wrap($s);
    }
}
