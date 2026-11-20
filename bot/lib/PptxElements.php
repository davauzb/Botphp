<?php

require_once __DIR__ . '/PptxAnim.php';
require_once __DIR__ . '/PptxNotes.php';

/**
 * Erkin tahrirlash uchun "element modeli".
 *
 * Slayd = elementlar ro'yxati (shakl, matn, rasm, chiziq, jadval). Tahrirlovchi (web) shu JSON bilan ishlaydi,
 * server esa uni QAT'IY tekshiradi (sanitize) va faqat tekshirilgan qiymatlardan PPTX XML yozadi.
 * Foydalanuvchi yuborgan hech qanday satr XML'ga "xom" holda tushmaydi — shuning uchun tahrirlangan
 * fayl har doim sxemaga mos va PowerPoint / Google Slides / LibreOffice'da ochiladi.
 *
 * Element:
 *  id, t (shape|image|line|table), n (nom), g (geometriya), x,y,w,h (EMU), r (gradus), fh, fv,
 *  adj {adj1:..}, fill {c,a} | {g:[c1,c2],ang} | null, ln {c,w,dash,head,tail,a} | null, sh {c,b,d,a} | null,
 *  tx {anchor, ins[4], paras:[{al,bu,sa,sb,ls,runs:[{t,sz,b,i,u,s,c,f,sp}]}]} | null,
 *  tb (matn qutisi), ph ('title'), lock, hid, img (rasm id), crop {l,t,r,b}, alt, tbl {...}
 */
class PptxElements
{
    public const W = 9144000;
    public const H = 6858000;

    public const MAX_SLIDES = 120;
    public const MAX_ELS = 300;
    public const MAX_TEXT = 20000;

    public const FONTS = [
        'Calibri', 'Arial', 'Georgia', 'Verdana', 'Tahoma', 'Trebuchet MS', 'Cambria', 'Times New Roman',
        'Courier New', 'Segoe UI', 'Palatino Linotype', 'Impact', 'Comic Sans MS', 'Century Gothic', 'Garamond',
    ];

    /** Ruxsat etilgan geometriyalar va ularning sozlanadigan parametrlari (OOXML nomlari, default qiymatlari). */
    public const GEOMS = [
        'rect' => [], 'roundRect' => ['adj' => 16667], 'ellipse' => [], 'triangle' => ['adj' => 50000],
        'rtTriangle' => [], 'diamond' => [], 'parallelogram' => ['adj' => 25000], 'trapezoid' => ['adj' => 25000],
        'hexagon' => ['adj' => 25000], 'octagon' => ['adj' => 29289], 'star5' => ['adj' => 19098], 'plus' => ['adj' => 25000],
        'rightArrow' => ['adj1' => 50000, 'adj2' => 50000], 'leftArrow' => ['adj1' => 50000, 'adj2' => 50000],
        'upArrow' => ['adj1' => 50000, 'adj2' => 50000], 'downArrow' => ['adj1' => 50000, 'adj2' => 50000],
        'chevron' => ['adj' => 50000], 'homePlate' => ['adj' => 50000], 'snip1Rect' => ['adj' => 16667],
        'snip2SameRect' => ['adj1' => 16667, 'adj2' => 0], 'snip2DiagRect' => ['adj1' => 0, 'adj2' => 16667],
        'round2SameRect' => ['adj1' => 16667, 'adj2' => 0], 'foldedCorner' => ['adj' => 16667],
        'wave' => ['adj1' => 12500, 'adj2' => 0], 'flowChartOffpageConnector' => [], 'donut' => ['adj' => 25000],
        'line' => [],
    ];

    public const KINDS = ['matn', 'tarif', 'bosqichlar', 'xronologiya', 'kartalar', 'taqqoslash', 'xulosa', 'reja', 'title', 'thanks', 'bolim'];
    public const TRANSITIONS = ['none', 'fade', 'push', 'wipe', 'cover', 'split'];
    public const DASHES = ['solid', 'dash', 'sysDot', 'dashDot', 'lgDash'];
    public const ENDS = ['none', 'triangle', 'arrow', 'stealth', 'oval', 'diamond'];

    /** Tahrirlash uchun kerakli PHP kengaytmalari (dom, zip, gd) mavjudmi. */
    public static function available(): bool
    {
        return class_exists('DOMDocument') && class_exists('ZipArchive') && function_exists('imagecreatefromstring');
    }

    // ------------------------------------------------------------------ sanitize helpers

    public static function str($v, int $max): string
    {
        if (!is_scalar($v)) {
            return '';
        }
        $s = (string)$v;
        if (!preg_match('//u', $s)) {
            $s = function_exists('mb_convert_encoding') ? mb_convert_encoding($s, 'UTF-8', 'UTF-8') : '';
            if (!preg_match('//u', $s)) {
                return '';
            }
        }
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\x{FFFE}|\x{FFFF}/u', '', $s) ?? '';
        if (function_exists('mb_substr')) {
            return mb_substr($s, 0, $max);
        }
        return substr($s, 0, $max);
    }

    public static function intIn($v, int $min, int $max, int $def): int
    {
        if (is_bool($v) || !is_numeric($v)) {
            return $def;
        }
        $f = (float)$v;
        if (is_nan($f) || is_infinite($f)) {
            return $def;
        }
        return (int)max($min, min($max, round($f)));
    }

    public static function floatIn($v, float $min, float $max, float $def): float
    {
        if (is_bool($v) || !is_numeric($v)) {
            return $def;
        }
        $f = (float)$v;
        if (is_nan($f) || is_infinite($f)) {
            return $def;
        }
        return max($min, min($max, $f));
    }

    public static function color($v, ?string $def = null): ?string
    {
        if (is_string($v) && preg_match('/^#?([0-9A-Fa-f]{6})$/', $v, $m)) {
            return strtoupper($m[1]);
        }
        return $def;
    }

    public static function safeId($v, string $prefix = 'e'): string
    {
        if (is_string($v) && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $v)) {
            return $v;
        }
        return $prefix . bin2hex(random_bytes(5));
    }

    private static function enum($v, array $allowed, string $def): string
    {
        return is_string($v) && in_array($v, $allowed, true) ? $v : $def;
    }

    private static function bool($v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }

    // ------------------------------------------------------------------ sanitize (kiruvchi JSON -> toza model)

    public static function sanitizeSem($raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $points = [];
        foreach (array_slice(is_array($raw['points'] ?? null) ? $raw['points'] : [], 0, 8) as $p) {
            if (!is_array($p)) {
                continue;
            }
            $side = self::enum($p['side'] ?? '', ['', 'chap', 'ong', 'left', 'right'], '');
            $points[] = ['head' => self::str($p['head'] ?? '', 120), 'text' => self::str($p['text'] ?? '', 600), 'side' => $side];
        }
        $strs = function ($arr, int $n, int $len) {
            $o = [];
            foreach (array_slice(is_array($arr) ? $arr : [], 0, $n) as $s) {
                $t = self::str($s, $len);
                if ($t !== '') {
                    $o[] = $t;
                }
            }
            return $o;
        };
        return [
            'kind' => self::enum($raw['kind'] ?? 'matn', self::KINDS, 'matn'),
            'title' => self::str($raw['title'] ?? '', 200),
            'lead' => self::str($raw['lead'] ?? '', 700),
            'points' => $points,
            'left_title' => self::str($raw['left_title'] ?? '', 80),
            'right_title' => self::str($raw['right_title'] ?? '', 80),
            'items' => $strs($raw['items'] ?? [], 12, 100),
            'bullets' => $strs($raw['bullets'] ?? [], 6, 160),
            'image' => (is_string($raw['image'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $raw['image'])) ? $raw['image'] : null,
        ];
    }

    private static function sanitizeFill($f)
    {
        if (!is_array($f)) {
            return null;
        }
        if (isset($f['g']) && is_array($f['g']) && count($f['g']) >= 2) {
            $c1 = self::color($f['g'][0] ?? null, 'FFFFFF');
            $c2 = self::color($f['g'][1] ?? null, '000000');
            $ga = is_array($f['ga'] ?? null) ? $f['ga'] : [];
            return ['g' => [$c1, $c2], 'ga' => [self::intIn($ga[0] ?? 100, 0, 100, 100), self::intIn($ga[1] ?? 100, 0, 100, 100)],
                'ang' => self::intIn($f['ang'] ?? 90, 0, 359, 90)];
        }
        $c = self::color($f['c'] ?? null);
        if ($c === null) {
            return null;
        }
        return ['c' => $c, 'a' => self::intIn($f['a'] ?? 100, 0, 100, 100)];
    }

    private static function sanitizeLn($l)
    {
        if (!is_array($l)) {
            return null;
        }
        $c = self::color($l['c'] ?? null);
        if ($c === null) {
            return null;
        }
        return [
            'c' => $c,
            'w' => self::intIn($l['w'] ?? 19050, 0, 254000, 19050),
            'dash' => self::enum($l['dash'] ?? 'solid', self::DASHES, 'solid'),
            'head' => self::enum($l['head'] ?? 'none', self::ENDS, 'none'),
            'tail' => self::enum($l['tail'] ?? 'none', self::ENDS, 'none'),
            'a' => self::intIn($l['a'] ?? 100, 0, 100, 100),
        ];
    }

    private static function sanitizeRun($r): ?array
    {
        if (!is_array($r)) {
            return null;
        }
        $t = self::str($r['t'] ?? '', 5000);
        $out = [
            't' => $t,
            'sz' => self::floatIn($r['sz'] ?? 18, 4, 400, 18),
            'b' => self::bool($r['b'] ?? false),
            'i' => self::bool($r['i'] ?? false),
            'u' => self::bool($r['u'] ?? false),
            's' => self::bool($r['s'] ?? false),
            'c' => self::color($r['c'] ?? null, '262626'),
            'f' => self::enum($r['f'] ?? 'Calibri', self::FONTS, 'Calibri'),
            'sp' => self::intIn($r['sp'] ?? 0, -2000, 20000, 0),
        ];
        $out['sz'] = round($out['sz'] * 2) / 2; // 0.5 pt qadam
        return $out;
    }

    private static function sanitizeTx($tx, int &$budget)
    {
        if (!is_array($tx)) {
            return null;
        }
        $ins = is_array($tx['ins'] ?? null) ? array_values($tx['ins']) : [91440, 45720, 91440, 45720];
        $paras = [];
        foreach (array_slice(is_array($tx['paras'] ?? null) ? $tx['paras'] : [], 0, 200) as $p) {
            if (!is_array($p)) {
                continue;
            }
            $runs = [];
            foreach (array_slice(is_array($p['runs'] ?? null) ? $p['runs'] : [], 0, 100) as $r) {
                $run = self::sanitizeRun($r);
                if ($run === null) {
                    continue;
                }
                $len = function_exists('mb_strlen') ? mb_strlen($run['t']) : strlen($run['t']);
                if ($budget - $len < 0) {
                    $run['t'] = '';
                } else {
                    $budget -= $len;
                }
                $runs[] = $run;
            }
            $paras[] = [
                'al' => self::enum($p['al'] ?? 'l', ['l', 'ctr', 'r', 'just'], 'l'),
                'bu' => self::enum($p['bu'] ?? 'none', ['none', 'dot', 'num'], 'none'),
                'sa' => self::intIn($p['sa'] ?? 0, 0, 200, 0),
                'sb' => self::intIn($p['sb'] ?? 0, 0, 200, 0),
                'ls' => self::intIn($p['ls'] ?? 100, 50, 400, 100),
                'runs' => $runs,
            ];
        }
        if (!$paras) {
            $paras[] = ['al' => 'l', 'bu' => 'none', 'sa' => 0, 'sb' => 0, 'ls' => 100, 'runs' => []];
        }
        return [
            'anchor' => self::enum($tx['anchor'] ?? 't', ['t', 'ctr', 'b'], 't'),
            'ins' => [
                self::intIn($ins[0] ?? 91440, 0, 2000000, 91440), self::intIn($ins[1] ?? 45720, 0, 2000000, 45720),
                self::intIn($ins[2] ?? 91440, 0, 2000000, 91440), self::intIn($ins[3] ?? 45720, 0, 2000000, 45720),
            ],
            'paras' => $paras,
        ];
    }

    private static function sanitizeTable($t, int $w)
    {
        if (!is_array($t) || !is_array($t['rows'] ?? null) || !$t['rows']) {
            return null;
        }
        $rows = array_slice($t['rows'], 0, 30);
        $ncols = 0;
        foreach ($rows as $r) {
            $ncols = max($ncols, is_array($r) ? count($r) : 0);
        }
        $ncols = max(1, min(12, $ncols));
        $outRows = [];
        foreach ($rows as $r) {
            $cells = [];
            for ($c = 0; $c < $ncols; $c++) {
                $cell = is_array($r) && is_array($r[$c] ?? null) ? $r[$c] : [];
                $cells[] = [
                    't' => self::str($cell['t'] ?? '', 500),
                    'b' => self::bool($cell['b'] ?? false),
                    'c' => self::color($cell['c'] ?? null),
                    'f' => self::color($cell['f'] ?? null),
                    'al' => self::enum($cell['al'] ?? 'l', ['l', 'ctr', 'r'], 'l'),
                ];
            }
            $outRows[] = $cells;
        }
        $cols = [];
        $sum = 0;
        for ($c = 0; $c < $ncols; $c++) {
            $cw = self::intIn($t['cols'][$c] ?? (int)($w / $ncols), 100000, 9144000, (int)($w / $ncols));
            $cols[] = $cw;
            $sum += $cw;
        }
        return [
            'rows' => $outRows,
            'cols' => $cols,
            'rh' => self::intIn($t['rh'] ?? 400000, 150000, 3000000, 400000),
            'hdr' => self::bool($t['hdr'] ?? true),
            'hc' => self::color($t['hc'] ?? null, '4472C4'),
            'hf' => self::color($t['hf'] ?? null, 'FFFFFF'),
            'bc' => self::color($t['bc'] ?? null, 'BFBFBF'),
            'fc' => self::color($t['fc'] ?? null, '262626'),
            'sz' => self::floatIn($t['sz'] ?? 14, 6, 60, 14),
            'font' => self::enum($t['font'] ?? 'Calibri', self::FONTS, 'Calibri'),
            'band' => self::bool($t['band'] ?? false),
        ];
    }

    public static function sanitizeEl($e, array $imgIds, int &$textBudget): ?array
    {
        if (!is_array($e)) {
            return null;
        }
        $t = self::enum($e['t'] ?? 'shape', ['shape', 'image', 'line', 'table'], 'shape');
        $o = [
            'id' => self::safeId($e['id'] ?? null),
            't' => $t,
            'n' => self::str($e['n'] ?? '', 60),
            'x' => self::intIn($e['x'] ?? 0, -2 * self::W, 3 * self::W, 0),
            'y' => self::intIn($e['y'] ?? 0, -2 * self::H, 3 * self::H, 0),
            'w' => self::intIn($e['w'] ?? 914400, 0, 4 * self::W, 914400),
            'h' => self::intIn($e['h'] ?? 914400, 0, 4 * self::H, 914400),
            'r' => round(fmod(self::floatIn($e['r'] ?? 0, -3600, 3600, 0) + 360000, 360), 2),
            'fh' => self::bool($e['fh'] ?? false),
            'fv' => self::bool($e['fv'] ?? false),
            'lock' => self::bool($e['lock'] ?? false),
            'hid' => self::bool($e['hid'] ?? false),
        ];
        if ($t !== 'line') {
            $o['w'] = max(1000, $o['w']);
            $o['h'] = max(1000, $o['h']);
        }
        $o['alt'] = self::str($e['alt'] ?? '', 200);

        if ($t === 'shape' || $t === 'line' || $t === 'image') {
            $g = self::enum($e['g'] ?? ($t === 'line' ? 'line' : 'rect'), array_keys(self::GEOMS), 'rect');
            if ($t === 'line') {
                $g = 'line';
            } elseif ($g === 'line') {
                $g = 'rect';
            }
            if ($t === 'image' && !in_array($g, ['rect', 'roundRect', 'ellipse'], true)) {
                $g = 'rect';
            }
            $o['g'] = $g;
            $adj = [];
            foreach (self::GEOMS[$g] as $k => $_def) {
                if (isset($e['adj'][$k])) {
                    $adj[$k] = self::intIn($e['adj'][$k], -100000, 100000, self::GEOMS[$g][$k]);
                }
            }
            $o['adj'] = $adj;
        }

        if ($t === 'shape' || $t === 'line') {
            $o['fill'] = $t === 'line' ? null : self::sanitizeFill($e['fill'] ?? null);
            $o['ln'] = self::sanitizeLn($e['ln'] ?? null);
            if ($t === 'line' && $o['ln'] === null) {
                $o['ln'] = ['c' => '000000', 'w' => 19050, 'dash' => 'solid', 'head' => 'none', 'tail' => 'none', 'a' => 100];
            }
            $sh = $e['sh'] ?? null;
            $o['sh'] = is_array($sh) && self::color($sh['c'] ?? null) !== null ? [
                'c' => self::color($sh['c']),
                'b' => self::intIn($sh['b'] ?? 50800, 0, 635000, 50800),
                'd' => self::intIn($sh['d'] ?? 38100, 0, 635000, 38100),
                'ang' => self::intIn($sh['ang'] ?? 45, 0, 359, 45),
                'a' => self::intIn($sh['a'] ?? 40, 0, 100, 40),
            ] : null;
            if ($t === 'shape') {
                $o['tx'] = self::sanitizeTx($e['tx'] ?? null, $textBudget);
                $o['tb'] = self::bool($e['tb'] ?? false);
                $o['ph'] = (($e['ph'] ?? '') === 'title') ? 'title' : '';
            }
        } elseif ($t === 'image') {
            $img = $e['img'] ?? null;
            if (!is_string($img) || !isset($imgIds[$img])) {
                return null;
            }
            $o['img'] = $img;
            $o['ln'] = self::sanitizeLn($e['ln'] ?? null);
            $c = is_array($e['crop'] ?? null) ? $e['crop'] : [];
            $crop = [
                'l' => self::floatIn($c['l'] ?? 0, 0, 90, 0), 't' => self::floatIn($c['t'] ?? 0, 0, 90, 0),
                'r' => self::floatIn($c['r'] ?? 0, 0, 90, 0), 'b' => self::floatIn($c['b'] ?? 0, 0, 90, 0),
            ];
            if ($crop['l'] + $crop['r'] > 95) {
                $crop['l'] = $crop['r'] = 0;
            }
            if ($crop['t'] + $crop['b'] > 95) {
                $crop['t'] = $crop['b'] = 0;
            }
            $o['crop'] = $crop;
            $sh = $e['sh'] ?? null;
            $o['sh'] = is_array($sh) && self::color($sh['c'] ?? null) !== null ? [
                'c' => self::color($sh['c']), 'b' => self::intIn($sh['b'] ?? 50800, 0, 635000, 50800),
                'd' => self::intIn($sh['d'] ?? 38100, 0, 635000, 38100), 'ang' => self::intIn($sh['ang'] ?? 45, 0, 359, 45),
                'a' => self::intIn($sh['a'] ?? 40, 0, 100, 40),
            ] : null;
        } elseif ($t === 'table') {
            $tbl = self::sanitizeTable($e['tbl'] ?? null, $o['w']);
            if ($tbl === null) {
                return null;
            }
            $o['tbl'] = $tbl;
            $o['w'] = array_sum($tbl['cols']);
            $o['h'] = $tbl['rh'] * count($tbl['rows']);
        }
        return $o;
    }

    public static function sanitizeSlide($raw, array $imgIds): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $budget = self::MAX_TEXT * 4;
        $els = [];
        $seen = [];
        $hasTitle = false;
        foreach (array_slice(is_array($raw['els'] ?? null) ? $raw['els'] : [], 0, self::MAX_ELS) as $e) {
            $c = self::sanitizeEl($e, $imgIds, $budget);
            if ($c === null) {
                continue;
            }
            while (isset($seen[$c['id']])) {
                $c['id'] = self::safeId(null);
            }
            $seen[$c['id']] = true;
            if (($c['ph'] ?? '') === 'title') {
                if ($hasTitle) {
                    $c['ph'] = '';
                }
                $hasTitle = true;
            }
            $els[] = $c;
        }
        return [
            'id' => self::safeId($raw['id'] ?? null, 's'),
            'mode' => self::enum($raw['mode'] ?? 'auto', ['auto', 'free'], 'auto'),
            'tr' => self::enum($raw['tr'] ?? 'none', self::TRANSITIONS, 'none'),
            'an' => self::bool($raw['an'] ?? false),
            'notes' => is_string($raw['notes'] ?? null) ? PptxNotes::clean(self::str($raw['notes'], 3000)) : '',
            'sem' => self::sanitizeSem($raw['sem'] ?? []),
            'els' => $els,
        ];
    }

    // ------------------------------------------------------------------ XML yozish

    private static function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function rgb(string $c, int $alpha = 100): string
    {
        return '<a:srgbClr val="' . $c . '">' . ($alpha < 100 ? '<a:alpha val="' . ($alpha * 1000) . '"/>' : '') . '</a:srgbClr>';
    }

    private static function fillXml($f): string
    {
        if (!is_array($f)) {
            return '<a:noFill/>';
        }
        if (isset($f['g'])) {
            return '<a:gradFill rotWithShape="1"><a:gsLst><a:gs pos="0">' . self::rgb($f['g'][0], (int)($f['ga'][0] ?? 100)) . '</a:gs><a:gs pos="100000">'
                . self::rgb($f['g'][1], (int)($f['ga'][1] ?? 100)) . '</a:gs></a:gsLst><a:lin ang="' . ($f['ang'] * 60000) . '" scaled="0"/></a:gradFill>';
        }
        return '<a:solidFill>' . self::rgb($f['c'], $f['a'] ?? 100) . '</a:solidFill>';
    }

    private static function lnXml($l, bool $line = false): string
    {
        if (!is_array($l) || $l['w'] <= 0) {
            return '<a:ln><a:noFill/></a:ln>';
        }
        $x = '<a:ln w="' . $l['w'] . '"><a:solidFill>' . self::rgb($l['c'], $l['a'] ?? 100) . '</a:solidFill>';
        if (($l['dash'] ?? 'solid') !== 'solid') {
            $x .= '<a:prstDash val="' . $l['dash'] . '"/>';
        }
        $x .= '<a:round/>';
        if (($l['head'] ?? 'none') !== 'none') {
            $x .= '<a:headEnd type="' . $l['head'] . '" w="med" len="med"/>';
        }
        if (($l['tail'] ?? 'none') !== 'none') {
            $x .= '<a:tailEnd type="' . $l['tail'] . '" w="med" len="med"/>';
        }
        return $x . '</a:ln>';
    }

    private static function shXml($sh): string
    {
        if (!is_array($sh)) {
            return '';
        }
        return '<a:effectLst><a:outerShdw blurRad="' . $sh['b'] . '" dist="' . $sh['d'] . '" dir="' . ($sh['ang'] * 60000)
            . '" algn="ctr" rotWithShape="0">' . self::rgb($sh['c'], $sh['a']) . '</a:outerShdw></a:effectLst>';
    }

    private static function xfrm(array $e, string $tag = 'a:xfrm'): string
    {
        $a = '';
        if ($e['r'] != 0) {
            $a .= ' rot="' . (int)round($e['r'] * 60000) . '"';
        }
        if (!empty($e['fh'])) {
            $a .= ' flipH="1"';
        }
        if (!empty($e['fv'])) {
            $a .= ' flipV="1"';
        }
        return '<' . $tag . $a . '><a:off x="' . $e['x'] . '" y="' . $e['y'] . '"/><a:ext cx="' . $e['w'] . '" cy="' . $e['h'] . '"/></' . $tag . '>';
    }

    private static function geomXml(string $g, array $adj): string
    {
        $av = '';
        foreach ($adj as $k => $v) {
            if (isset(self::GEOMS[$g][$k])) {
                $av .= '<a:gd name="' . $k . '" fmla="val ' . (int)$v . '"/>';
            }
        }
        return '<a:prstGeom prst="' . $g . '"><a:avLst>' . $av . '</a:avLst></a:prstGeom>';
    }

    private static function runXml(array $r, string $tag = 'a:rPr'): string
    {
        $attrs = ' lang="uz-UZ" sz="' . (int)round($r['sz'] * 100) . '" b="' . ($r['b'] ? 1 : 0) . '" i="' . ($r['i'] ? 1 : 0) . '"';
        if ($r['u']) {
            $attrs .= ' u="sng"';
        }
        if ($r['s']) {
            $attrs .= ' strike="sngStrike"';
        }
        if ($r['sp']) {
            $attrs .= ' spc="' . (int)$r['sp'] . '"';
        }
        return '<' . $tag . $attrs . ' dirty="0"><a:solidFill>' . self::rgb($r['c']) . '</a:solidFill><a:latin typeface="'
            . self::x($r['f']) . '"/><a:cs typeface="' . self::x($r['f']) . '"/></' . $tag . '>';
    }

    private static function parasXml(array $paras): string
    {
        $out = '';
        foreach ($paras as $p) {
            $firstSz = 18;
            foreach ($p['runs'] as $r) {
                $firstSz = $r['sz'];
                break;
            }
            $bu = '<a:buNone/>';
            $mar = ' marL="0" indent="0"';
            if ($p['bu'] === 'dot') {
                $m = (int)round($firstSz * 12700 * 1.5);
                $mar = ' marL="' . $m . '" indent="-' . $m . '"';
                $bu = '<a:buFont typeface="Arial"/><a:buChar char="&#8226;"/>';
            } elseif ($p['bu'] === 'num') {
                $m = (int)round($firstSz * 12700 * 1.8);
                $mar = ' marL="' . $m . '" indent="-' . $m . '"';
                $bu = '<a:buFont typeface="+mj-lt"/><a:buAutoNum type="arabicPeriod"/>';
            }
            $x = '<a:p><a:pPr' . $mar . ' algn="' . $p['al'] . '"><a:lnSpc><a:spcPct val="' . ($p['ls'] * 1000) . '"/></a:lnSpc>'
                . '<a:spcBef><a:spcPts val="' . ($p['sb'] * 100) . '"/></a:spcBef><a:spcAft><a:spcPts val="' . ($p['sa'] * 100)
                . '"/></a:spcAft>' . $bu . '</a:pPr>';
            $last = null;
            foreach ($p['runs'] as $r) {
                $last = $r;
                if ($r['t'] === '') {
                    continue;
                }
                $parts = explode("\n", $r['t']);
                foreach ($parts as $i => $part) {
                    if ($i > 0) {
                        $x .= '<a:br>' . self::runXml($r) . '</a:br>';
                    }
                    if ($part !== '') {
                        $x .= '<a:r>' . self::runXml($r) . '<a:t>' . self::x($part) . '</a:t></a:r>';
                    }
                }
            }
            $x .= self::runXml($last ?? ['sz' => 18, 'b' => false, 'i' => false, 'u' => false, 's' => false, 'sp' => 0, 'c' => '262626', 'f' => 'Calibri'], 'a:endParaRPr');
            $out .= $x . '</a:p>';
        }
        return $out;
    }

    private static function txBodyXml(array $tx): string
    {
        return '<p:txBody><a:bodyPr wrap="square" lIns="' . $tx['ins'][0] . '" tIns="' . $tx['ins'][1] . '" rIns="' . $tx['ins'][2]
            . '" bIns="' . $tx['ins'][3] . '" anchor="' . $tx['anchor'] . '" rtlCol="0"><a:noAutofit/></a:bodyPr><a:lstStyle/>'
            . self::parasXml($tx['paras']) . '</p:txBody>';
    }

    private static function cNvPr(int $id, array $e): string
    {
        return '<p:cNvPr id="' . $id . '" name="' . self::x($e['n'] !== '' ? $e['n'] : ('Element ' . $id)) . '"'
            . ($e['alt'] !== '' ? ' descr="' . self::x($e['alt']) . '"' : '') . ($e['hid'] ? ' hidden="1"' : '') . '/>';
    }

    private static function spXml(array $e, int $id): string
    {
        $isPh = ($e['ph'] ?? '') === 'title';
        $nv = '<p:nvSpPr>' . self::cNvPr($id, $e)
            . ($isPh ? '<p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr>' : '<p:cNvSpPr' . (!empty($e['tb']) ? ' txBox="1"' : '') . '/>')
            . '<p:nvPr>' . ($isPh ? '<p:ph type="title"/>' : '') . '</p:nvPr></p:nvSpPr>';
        $sp = '<p:spPr>' . self::xfrm($e) . self::geomXml($e['g'], $e['adj'] ?? []) . self::fillXml($e['fill'] ?? null)
            . self::lnXml($e['ln'] ?? null) . self::shXml($e['sh'] ?? null) . '</p:spPr>';
        $tx = (isset($e['tx']) && is_array($e['tx'])) ? self::txBodyXml($e['tx']) : '';
        return '<p:sp>' . $nv . $sp . $tx . '</p:sp>';
    }

    private static function lineXml(array $e, int $id): string
    {
        return '<p:sp><p:nvSpPr>' . self::cNvPr($id, $e) . '<p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr>' . self::xfrm($e)
            . '<a:prstGeom prst="line"><a:avLst/></a:prstGeom>' . self::lnXml($e['ln'], true) . self::shXml($e['sh'] ?? null) . '</p:spPr></p:sp>';
    }

    private static function picXml(array $e, int $id, string $rid): string
    {
        $c = $e['crop'];
        $src = ($c['l'] || $c['t'] || $c['r'] || $c['b'])
            ? '<a:srcRect l="' . (int)round($c['l'] * 1000) . '" t="' . (int)round($c['t'] * 1000) . '" r="' . (int)round($c['r'] * 1000)
              . '" b="' . (int)round($c['b'] * 1000) . '"/>' : '';
        return '<p:pic><p:nvPicPr>' . self::cNvPr($id, $e) . '<p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr>'
            . '<p:blipFill><a:blip r:embed="' . $rid . '"/>' . $src . '<a:stretch><a:fillRect/></a:stretch></p:blipFill>'
            . '<p:spPr>' . self::xfrm($e) . self::geomXml($e['g'], $e['adj'] ?? []) . self::lnXml($e['ln'] ?? null) . self::shXml($e['sh'] ?? null) . '</p:spPr></p:pic>';
    }

    private static function tableXml(array $e, int $id): string
    {
        $t = $e['tbl'];
        $border = function (string $tag) use ($t) {
            return '<a:' . $tag . ' w="9525"><a:solidFill>' . self::rgb($t['bc']) . '</a:solidFill></a:' . $tag . '>';
        };
        $grid = '';
        foreach ($t['cols'] as $w) {
            $grid .= '<a:gridCol w="' . $w . '"/>';
        }
        $rows = '';
        foreach ($t['rows'] as $ri => $row) {
            $isHdr = $t['hdr'] && $ri === 0;
            $rows .= '<a:tr h="' . $t['rh'] . '">';
            foreach ($row as $cell) {
                $fill = $isHdr ? $t['hc'] : ($cell['f'] ?? (($t['band'] && $ri % 2 === 0) ? 'F2F2F2' : null));
                $color = $isHdr ? $t['hf'] : ($cell['c'] ?? $t['fc']);
                $run = ['sz' => $t['sz'], 'b' => $isHdr || $cell['b'], 'i' => false, 'u' => false, 's' => false, 'sp' => 0, 'c' => $color, 'f' => $t['font']];
                $para = '<a:p><a:pPr algn="' . $cell['al'] . '"/>' . ($cell['t'] !== '' ? '<a:r>' . self::runXml($run) . '<a:t>' . self::x($cell['t']) . '</a:t></a:r>' : '')
                    . self::runXml($run, 'a:endParaRPr') . '</a:p>';
                $rows .= '<a:tc><a:txBody><a:bodyPr/><a:lstStyle/>' . $para . '</a:txBody><a:tcPr marL="91440" marR="91440" marT="45720" marB="45720" anchor="ctr">'
                    . $border('lnL') . $border('lnR') . $border('lnT') . $border('lnB')
                    . ($fill ? '<a:solidFill>' . self::rgb($fill) . '</a:solidFill>' : '<a:noFill/>') . '</a:tcPr></a:tc>';
            }
            $rows .= '</a:tr>';
        }
        return '<p:graphicFrame><p:nvGraphicFramePr>' . self::cNvPr($id, $e) . '<p:cNvGraphicFramePr><a:graphicFrameLocks noGrp="1"/></p:cNvGraphicFramePr><p:nvPr/></p:nvGraphicFramePr>'
            . '<p:xfrm><a:off x="' . $e['x'] . '" y="' . $e['y'] . '"/><a:ext cx="' . $e['w'] . '" cy="' . $e['h'] . '"/></p:xfrm>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/table"><a:tbl><a:tblPr' . ($t['hdr'] ? ' firstRow="1"' : '')
            . '/><a:tblGrid>' . $grid . '</a:tblGrid>' . $rows . '</a:tbl></a:graphicData></a:graphic></p:graphicFrame>';
    }

    /** Slayd XML. $ridMap: rasm id => rId. Qaytadi: [xml, ishlatilgan rasm id'lar]. */
    /** Animatsiya qilinadigan element: fon, to'liq ekranli qatlam, sarlavha, teg va raqamdan boshqalar. */
    private static function animatable(array $e): bool
    {
        if (!empty($e['lock']) || ($e['ph'] ?? '') === 'title' || in_array($e['n'] ?? '', ['Fon', 'Fon rasm', 'Slayd raqami', 'Teg', 'Sarlavha'], true)) {
            return false;
        }
        return !((int)$e['w'] >= self::W * 0.95 && (int)$e['h'] >= self::H * 0.95);
    }

    public static function slideXml(array $slide, array $ridMap): string
    {
        $shapes = '';
        $id = 1;
        $animIds = [];
        foreach ($slide['els'] as $e) {
            $id++;
            if (!empty($slide['an']) && self::animatable($e) && !($e['t'] === 'image' && !isset($ridMap[$e['img'] ?? '']))) {
                $animIds[] = $id;
            }
            switch ($e['t']) {
                case 'image':
                    if (!isset($ridMap[$e['img']])) {
                        $id--;
                        break;
                    }
                    $shapes .= self::picXml($e, $id, $ridMap[$e['img']]);
                    break;
                case 'line':
                    $shapes .= self::lineXml($e, $id);
                    break;
                case 'table':
                    $shapes .= self::tableXml($e, $id);
                    break;
                default:
                    $shapes .= self::spXml($e, $id);
            }
        }
        $tr = '';
        switch ($slide['tr'] ?? 'none') {
            case 'fade':
                $tr = '<p:transition spd="med"><p:fade/></p:transition>';
                break;
            case 'push':
                $tr = '<p:transition spd="med"><p:push dir="l"/></p:transition>';
                break;
            case 'wipe':
                $tr = '<p:transition spd="med"><p:wipe dir="l"/></p:transition>';
                break;
            case 'cover':
                $tr = '<p:transition spd="med"><p:cover dir="l"/></p:transition>';
                break;
            case 'split':
                $tr = '<p:transition spd="med"><p:split orient="horz" dir="out"/></p:transition>';
                break;
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">
<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>' . $shapes . '</p:spTree></p:cSld>
<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>' . $tr . ($animIds ? PptxAnim::timing($animIds) : '') . '
</p:sld>';
    }

    // ------------------------------------------------------------------ paket yig'ish

    /**
     * Tekshirilgan slaydlardan PPTX fayl yaratadi.
     * $imagePaths: rasm id => [path, ext]. Qaytadi: PPTX fayl yo'li.
     */
    public static function buildPackage(array $slides, array $design, array $imagePaths): string
    {
        $mediaIdx = [];
        $parts = [];
        foreach ($slides as $slide) {
            $rid = [];
            $media = [];
            $n = 1;
            foreach ($slide['els'] as $e) {
                if ($e['t'] === 'image' && isset($imagePaths[$e['img']]) && !isset($rid[$e['img']]) && is_file($imagePaths[$e['img']]['path'])) {
                    $n++;
                    $rid[$e['img']] = 'rId' . $n;
                    if (!isset($mediaIdx[$e['img']])) {
                        $mediaIdx[$e['img']] = 'image' . (count($mediaIdx) + 1) . '.' . $imagePaths[$e['img']]['ext'];
                    }
                    $media['rId' . $n] = ['name' => $mediaIdx[$e['img']], 'path' => $imagePaths[$e['img']]['path']];
                }
            }
            $parts[] = ['xml' => self::slideXml($slide, $rid), 'media' => $media, 'notes' => (string)($slide['notes'] ?? '')];
        }
        if (!$parts) {
            $parts[] = ['xml' => self::slideXml(['els' => [], 'tr' => 'none'], []), 'media' => []];
        }
        return PptxGenerator::generateFromParts($parts, $design);
    }

    /** Tayyor PPTX'ni qayta tekshiradi: XML butunligi, bog'lanishlar, noyob id'lar. [ok, xatolar]. */
    public static function verifyPackage(string $path): array
    {
        $errors = [];
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return [false, ['zip ochilmadi']];
        }
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[$zip->getNameIndex($i)] = true;
        }
        if (($zip->getNameIndex(0) ?? '') !== '[Content_Types].xml') {
            $errors[] = 'Content_Types birinchi emas';
        }
        $prev = libxml_use_internal_errors(true);
        foreach (array_keys($names) as $n) {
            if (!preg_match('/\.(xml|rels)$/', $n)) {
                continue;
            }
            $doc = new DOMDocument();
            if (!$doc->loadXML($zip->getFromName($n), LIBXML_NONET)) {
                $errors[] = "XML buzuq: $n";
                libxml_clear_errors();
                continue;
            }
            if (substr($n, -5) === '.rels') {
                $base = dirname(dirname($n));
                foreach ($doc->getElementsByTagName('Relationship') as $rel) {
                    $target = $rel->getAttribute('Target');
                    if ($rel->getAttribute('TargetMode') === 'External') {
                        $errors[] = "tashqi bog'lanish: $n";
                        continue;
                    }
                    $full = $base === '.' ? $target : $base . '/' . $target;
                    $parts = [];
                    foreach (explode('/', $full) as $seg) {
                        if ($seg === '..') {
                            array_pop($parts);
                        } elseif ($seg !== '' && $seg !== '.') {
                            $parts[] = $seg;
                        }
                    }
                    $norm = implode('/', $parts);
                    if (!isset($names[$norm])) {
                        $errors[] = "yo'q fayl: $norm ($n)";
                    }
                }
            }
            if (preg_match('#^ppt/slides/slide\d+\.xml$#', $n)) {
                $ids = [];
                foreach ($doc->getElementsByTagName('cNvPr') as $c) {
                    $ids[] = $c->getAttribute('id');
                }
                if (count($ids) !== count(array_unique($ids))) {
                    $errors[] = "takroriy shape id: $n";
                }
                $relName = 'ppt/slides/_rels/' . basename($n) . '.rels';
                $relXml = isset($names[$relName]) ? $zip->getFromName($relName) : '';
                preg_match_all('/r:embed="([^"]+)"/', $zip->getFromName($n), $m);
                foreach ($m[1] as $rid) {
                    if (strpos($relXml, 'Id="' . $rid . '"') === false) {
                        $errors[] = "rasm bog'lanishi yo'q $rid ($n)";
                    }
                }
            }
        }
        libxml_use_internal_errors($prev);
        $zip->close();
        return [!$errors, $errors];
    }

    // ------------------------------------------------------------------ sem (mazmun) -> elementlar

    /** Mazmun (sem) dan PptxLayouts orqali element ro'yxati hosil qiladi. */
    public static function elementsFromSem(array $sem, array $design, int $n, int $total, ?string $imagePath): array
    {
        $slide = [
            'kind' => $sem['kind'], 'title' => $sem['title'], 'lead' => $sem['lead'], 'points' => $sem['points'],
            'left_title' => $sem['left_title'], 'right_title' => $sem['right_title'], 'items' => $sem['items'],
            'bullets' => $sem['bullets'],
        ];
        $xml = PptxGenerator::buildSlideXml($slide, $n, $total, $imagePath, $design);
        return self::importSlideXml($xml, $sem['image'] ?? null);
    }

    /**
     * Bot yaratgan slaydlar ($final, worker.php) -> tahrirlovchi modeli.
     * $imageIdByPath: lokal rasm yo'li => saqlangan rasm id.
     */
    public static function slidesFromGenerated(array $final, array $design, array $imageIdByPath): array
    {
        $total = count($final);
        $out = [];
        foreach ($final as $i => $s) {
            $path = $s['image'] ?? null;
            $imgId = ($path && isset($imageIdByPath[$path])) ? $imageIdByPath[$path] : null;
            $pts = [];
            foreach (($s['points'] ?? []) as $p) {
                $pts[] = is_array($p) ? $p : ['head' => '', 'text' => (string)$p, 'side' => ''];
            }
            $sem = self::sanitizeSem([
                'kind' => $s['kind'] ?? 'matn', 'title' => $s['title'] ?? '', 'lead' => $s['lead'] ?? '', 'points' => $pts,
                'left_title' => $s['left_title'] ?? '', 'right_title' => $s['right_title'] ?? '', 'items' => $s['items'] ?? [],
                'bullets' => $s['bullets'] ?? [], 'image' => $imgId,
            ]);
            $els = self::elementsFromSem($sem, $design, $i + 1, $total, $imgId && $path ? $path : null);
            $pro = !empty($design['pro']);
            $style = (string)Engine::c('PPTX_TRANSITION_STYLE', 'fade');
            $out[] = ['id' => 's' . ($i + 1), 'mode' => 'auto',
                'tr' => ($pro && Engine::c('PPTX_TRANSITIONS', true)) ? (in_array($style, self::TRANSITIONS, true) ? $style : 'fade') : 'none',
                'an' => $pro && Engine::c('PPTX_ANIMATIONS', true) && !in_array($sem['kind'], ['title', 'thanks', 'bolim'], true),
                'notes' => PptxNotes::clean((string)($s['notes'] ?? '')),
                'sem' => $sem, 'els' => $els];
        }
        return $out;
    }

    // ------------------------------------------------------------------ IMPORT: layout XML -> elementlar

    private const NAME_MAP = [
        'ContentBg' => 'Fon', 'EdgeBg' => 'Fon', 'Bg' => 'Fon', 'Title' => 'Sarlavha', 'Tag' => 'Teg', 'Pill' => 'Teg',
        'SlideNumber' => 'Slayd raqami', 'Brand' => 'Brend', 'Card' => 'Karta', 'LeadBand' => 'Izoh foni', 'LeadText' => 'Izoh',
        'DefBox' => "Ta'rif bloki", 'DefText' => "Ta'rif matni", 'Info' => "Ma'lumot", 'Panel' => 'Panel', 'PanelText' => 'Panel matni',
        'Picture' => 'Rasm', 'Quote' => 'Qo\'shtirnoq', 'KeyLabel' => 'Yorliq',
        'Overlay' => 'Qoraytirish qatlami', 'OverlayB' => 'Qoraytirish (pastki)', 'Glow' => 'Yorug\'lik', 'Ring' => 'Halqa',
        'TagDot' => 'Teg nuqtasi', 'PillDot' => 'Nuqta', 'TitleBar' => 'Sarlavha chizig\'i', 'InfoCard' => "Ma'lumot kartasi", 'Bar' => 'Chiziq',
    ];

    private static function friendlyName(string $name, bool $hasText, string $kind): string
    {
        $base = preg_replace('/\d+$/', '', $name);
        if (isset(self::NAME_MAP[$base])) {
            return self::NAME_MAP[$base];
        }
        $map = [
            'Num' => 'Raqam', 'Point' => 'Punkt', 'GCard' => 'Karta', 'GHead' => 'Karta sarlavhasi', 'GText' => 'Karta matni',
            'MiniCard' => 'Mini karta', 'MiniText' => 'Mini karta matni', 'Step' => 'Bosqich', 'StepCard' => 'Bosqich kartasi',
            'StepText' => 'Bosqich matni', 'TCard' => 'Davr kartasi', 'TText' => 'Davr matni', 'Dot' => 'Nuqta', 'CRow' => 'Xulosa qatori',
            'CNum' => 'Xulosa raqami', 'CText' => 'Xulosa matni', 'AItem' => 'Reja qatori', 'ANum' => 'Reja raqami', 'AText' => 'Reja matni',
            'CBody' => 'Ustun', 'CHead' => 'Ustun sarlavhasi', 'VS' => 'VS', 'Deco' => 'Bezak', 'Sep' => 'Chiziq',
        ];
        foreach ($map as $k => $v) {
            if (strpos($base, $k) === 0) {
                return $v;
            }
        }
        return $hasText ? 'Matn' : ($kind === 'pic' ? 'Rasm' : 'Shakl');
    }

    /** PptxLayouts hosil qilgan slayd XML'ini element ro'yxatiga aylantiradi. */
    public static function importSlideXml(string $xml, ?string $imageId): array
    {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            return [];
        }
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $xp->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $els = [];
        foreach ($xp->query('/p:sld/p:cSld/p:spTree/*[self::p:sp or self::p:pic]') as $node) {
            $isPic = $node->localName === 'pic';
            $cnv = $xp->query('.//p:cNvPr', $node)->item(0);
            $name = $cnv ? $cnv->getAttribute('name') : '';
            $off = $xp->query('.//a:xfrm/a:off', $node)->item(0);
            $ext = $xp->query('.//a:xfrm/a:ext', $node)->item(0);
            if (!$off || !$ext) {
                continue;
            }
            $xf = $xp->query('.//a:xfrm', $node)->item(0);
            $geomNode = $xp->query('./p:spPr/a:prstGeom', $node)->item(0);
            $g = $geomNode ? $geomNode->getAttribute('prst') : 'rect';
            $adj = [];
            if ($geomNode) {
                foreach ($xp->query('./a:avLst/a:gd', $geomNode) as $gd) {
                    if (preg_match('/^val\s+(-?\d+)$/', $gd->getAttribute('fmla'), $m)) {
                        $adj[$gd->getAttribute('name')] = (int)$m[1];
                    }
                }
            }
            $e = [
                'id' => 'e' . (count($els) + 1),
                't' => $isPic ? 'image' : 'shape',
                'x' => (int)$off->getAttribute('x'), 'y' => (int)$off->getAttribute('y'),
                'w' => (int)$ext->getAttribute('cx'), 'h' => (int)$ext->getAttribute('cy'),
                'r' => $xf->hasAttribute('rot') ? ((int)$xf->getAttribute('rot')) / 60000 : 0,
                'g' => $g, 'adj' => $adj,
            ];
            $lnNode = $xp->query('./p:spPr/a:ln', $node)->item(0);
            $ln = null;
            if ($lnNode && !$xp->query('./a:noFill', $lnNode)->length) {
                $c = $xp->query('./a:solidFill/a:srgbClr', $lnNode)->item(0);
                if ($c) {
                    $al = $xp->query('./a:alpha', $c)->item(0);
                    $ln = ['c' => $c->getAttribute('val'), 'w' => (int)($lnNode->getAttribute('w') ?: 12700),
                        'a' => $al ? (int)round(((int)$al->getAttribute('val')) / 1000) : 100];
                }
            }
            $e['ln'] = $ln;

            if ($isPic) {
                if (!$imageId) {
                    continue;
                }
                $e['img'] = $imageId;
                $e['n'] = $name === 'Fon rasm' ? 'Fon rasm' : 'Rasm';
                $sr = $xp->query('./p:blipFill/a:srcRect', $node)->item(0);
                $pc = static fn($a) => $sr ? max(0, min(80, round(((int)$sr->getAttribute($a)) / 1000, 1))) : 0;
                $e['crop'] = ['l' => $pc('l'), 't' => $pc('t'), 'r' => $pc('r'), 'b' => $pc('b')];
                $els[] = $e;
                continue;
            }

            $fillNode = $xp->query('./p:spPr/a:solidFill/a:srgbClr', $node)->item(0);
            $alphaOf = fn($n) => ($al = $xp->query('./a:alpha', $n)->item(0)) ? (int)round(((int)$al->getAttribute('val')) / 1000) : 100;
            $e['fill'] = $fillNode ? ['c' => $fillNode->getAttribute('val'), 'a' => $alphaOf($fillNode)] : null;
            $stops = $xp->query('./p:spPr/a:gradFill/a:gsLst/a:gs/a:srgbClr', $node);
            if (!$fillNode && $stops->length >= 2) {
                $lin = $xp->query('./p:spPr/a:gradFill/a:lin', $node)->item(0);
                $last = $stops->item($stops->length - 1);
                $first = $stops->item(0);
                $e['fill'] = ['g' => [$first->getAttribute('val'), $last->getAttribute('val')], 'ga' => [$alphaOf($first), $alphaOf($last)],
                    'ang' => $lin ? (int)round(((int)$lin->getAttribute('ang')) / 60000) % 360 : 90];
            }
            $e['tb'] = $cnv && $xp->query('.//p:cNvSpPr[@txBox="1"]', $node)->length > 0;
            $e['ph'] = $xp->query('.//p:nvPr/p:ph[@type="title"]', $node)->length ? 'title' : '';

            $body = $xp->query('./p:txBody', $node)->item(0);
            $hasText = false;
            if ($body) {
                $bp = $xp->query('./a:bodyPr', $body)->item(0);
                $tx = [
                    'anchor' => $bp ? ($bp->getAttribute('anchor') ?: 't') : 't',
                    'ins' => [
                        $bp && $bp->hasAttribute('lIns') ? (int)$bp->getAttribute('lIns') : 91440,
                        $bp && $bp->hasAttribute('tIns') ? (int)$bp->getAttribute('tIns') : 45720,
                        $bp && $bp->hasAttribute('rIns') ? (int)$bp->getAttribute('rIns') : 91440,
                        $bp && $bp->hasAttribute('bIns') ? (int)$bp->getAttribute('bIns') : 45720,
                    ],
                    'paras' => [],
                ];
                foreach ($xp->query('./a:p', $body) as $pn) {
                    $ppr = $xp->query('./a:pPr', $pn)->item(0);
                    $para = ['al' => $ppr ? ($ppr->getAttribute('algn') ?: 'l') : 'l', 'bu' => 'none', 'sa' => 0, 'sb' => 0, 'ls' => 100, 'runs' => []];
                    if ($ppr) {
                        $sa = $xp->query('./a:spcAft/a:spcPts', $ppr)->item(0);
                        $sb = $xp->query('./a:spcBef/a:spcPts', $ppr)->item(0);
                        $ls = $xp->query('./a:lnSpc/a:spcPct', $ppr)->item(0);
                        $para['sa'] = $sa ? (int)round(((int)$sa->getAttribute('val')) / 100) : 0;
                        $para['sb'] = $sb ? (int)round(((int)$sb->getAttribute('val')) / 100) : 0;
                        $para['ls'] = $ls ? (int)round(((int)$ls->getAttribute('val')) / 1000) : 100;
                    }
                    foreach ($xp->query('./a:r', $pn) as $rn) {
                        $rp = $xp->query('./a:rPr', $rn)->item(0);
                        $tn = $xp->query('./a:t', $rn)->item(0);
                        $col = $rp ? $xp->query('./a:solidFill/a:srgbClr', $rp)->item(0) : null;
                        $lat = $rp ? $xp->query('./a:latin', $rp)->item(0) : null;
                        $para['runs'][] = [
                            't' => $tn ? $tn->textContent : '',
                            'sz' => $rp && $rp->getAttribute('sz') ? ((int)$rp->getAttribute('sz')) / 100 : 18,
                            'b' => $rp && $rp->getAttribute('b') === '1',
                            'i' => $rp && $rp->getAttribute('i') === '1',
                            'c' => $col ? $col->getAttribute('val') : '262626',
                            'f' => $lat ? $lat->getAttribute('typeface') : 'Calibri',
                        ];
                        $hasText = $hasText || ($tn && trim($tn->textContent) !== '');
                    }
                    $tx['paras'][] = $para;
                }
                $e['tx'] = $tx;
            } else {
                $e['tx'] = null;
            }

            $isBg = $e['x'] <= 0 && $e['y'] <= 0 && $e['w'] >= self::W && $e['h'] >= self::H && !$hasText && strpos($name, 'Overlay') !== 0;
            $e['n'] = $isBg ? 'Fon' : self::friendlyName($name, $hasText, 'shape');
            $e['lock'] = $isBg;
            $els[] = $e;
        }
        return $els;
    }
}
