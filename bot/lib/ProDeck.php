<?php

require_once __DIR__ . '/ProArt.php';
require_once __DIR__ . '/I18n.php';

/**
 * PRO TAQDIMOT generatori — 16:9, to'liq ekranli mavzuga oid fon rasmlari, shisha (glass) kartalar,
 * slaydlararo o'tish effektlari, elementlarning ketma-ket paydo bo'lish animatsiyalari va har slayd uchun nutq matni (notes).
 *
 * Mustaqil modul: oddiy dizaynlar (PptxGenerator/PptxLayouts) ga tegmaydi. Tashqi kutubxona kerak emas — faqat PHP + GD + ZipArchive.
 * Slaydlar tahrirlovchi (WebApp) modeliga o'tkazilmaydi — PRO taqdimot PowerPoint/Keynote/Google Slides'da tahrirlanadi.
 */
class ProDeck
{
    public const W = 12192000;
    public const H = 6858000;
    private const M = 720000;                 // chetki bo'sh joy
    private const FONT = 'Segoe UI';
    private const CHAR_K = 0.56;              // o'rtacha belgi kengligi / shrift o'lchami (konservativ — matn har doim sig'adi)

    /** @var array<string,array> */
    public const THEMES = [
        'pro_night' => ['label' => 'Tungi osmon', 'bg' => '0A1120', 'bg2' => '10264A', 'acc' => '38BDF8', 'acc2' => '818CF8', 'txt' => 'F1F5F9', 'mut' => 'C3D1E2', 'sub' => '8FA6BF', 'dark' => true],
        'pro_aurora' => ['label' => 'Shimol shafag\'i', 'bg' => '120A24', 'bg2' => '2B1556', 'acc' => 'B79CFF', 'acc2' => 'F472B6', 'txt' => 'F5F0FF', 'mut' => 'D3C8EE', 'sub' => 'A596C8', 'dark' => true],
        'pro_emerald' => ['label' => 'Zumrad', 'bg' => '04201A', 'bg2' => '0B3B30', 'acc' => '34D399', 'acc2' => '2DD4BF', 'txt' => 'ECFDF5', 'mut' => 'BFE3D6', 'sub' => '86B5A5', 'dark' => true],
        'pro_sunset' => ['label' => 'Quyosh botishi', 'bg' => '1C0F12', 'bg2' => '4A1E1E', 'acc' => 'FB923C', 'acc2' => 'FB7185', 'txt' => 'FFF4EC', 'mut' => 'EAD0C4', 'sub' => 'B99485', 'dark' => true],
        'pro_light' => ['label' => 'Yorug\' minimal', 'bg' => 'F6F8FC', 'bg2' => 'DDE7F7', 'acc' => '2563EB', 'acc2' => '7C3AED', 'txt' => '0F172A', 'mut' => '334155', 'sub' => '64748B', 'dark' => false],
    ];

    public static function enabled(): bool
    {
        return (bool)\Engine::c('PRO_ENABLED', true) && ProArt::available() && class_exists('ZipArchive');
    }

    public static function isPro(?string $key): bool
    {
        return $key !== null && isset(self::THEMES[$key]);
    }

    /** PRO narxi: asosiy narxga PRO_SURCHARGE_PERCENT foiz qo'shiladi (500 so'mga yaxlitlanadi). */
    public static function price(int $base): int
    {
        $pct = max(0, (int)\Engine::c('PRO_SURCHARGE_PERCENT', 60));
        return (int)(round($base * (100 + $pct) / 100 / 500) * 500);
    }

    // ================================================================== holat (bitta slayd chizilayotganda)

    private static array $T = [];
    private static string $lang = 'uz';
    private static int $id = 1;
    private static array $anim = [];
    private static array $media = [];       // nom => yo'l (joriy slayd)
    private static array $rid = [];         // nom => rId
    private static string $bgName = '';

    private static function nid(): int
    {
        return ++self::$id;
    }

    // ================================================================== XML primitivlari

    private static function esc(string $t): string
    {
        return htmlspecialchars($t, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** 'RRGGBB' yoki ['RRGGBB', shaffoflik-foizi (100 = to'liq qoplaydi)] */
    private static function clr($c): string
    {
        if (is_array($c)) {
            return '<a:srgbClr val="' . $c[0] . '"><a:alpha val="' . (int)round($c[1] * 1000) . '"/></a:srgbClr>';
        }
        return '<a:srgbClr val="' . $c . '"/>';
    }

    private static function fill($f): string
    {
        if ($f === null) {
            return '<a:noFill/>';
        }
        if (is_array($f) && isset($f['grad'])) {
            return '<a:gradFill rotWithShape="1"><a:gsLst><a:gs pos="0">' . self::clr($f['grad'][0]) . '</a:gs><a:gs pos="100000">' . self::clr($f['grad'][1])
                . '</a:gs></a:gsLst><a:lin ang="' . (int)(($f['ang'] ?? 0) * 60000) . '" scaled="0"/></a:gradFill>';
        }
        return '<a:solidFill>' . self::clr($f) . '</a:solidFill>';
    }

    private static function ln($c, int $w = 12700): string
    {
        return $c === null ? '<a:ln><a:noFill/></a:ln>' : '<a:ln w="' . $w . '"><a:solidFill>' . self::clr($c) . '</a:solidFill></a:ln>';
    }

    private static function run(string $t, float $sz, $color, bool $b = false, bool $i = false, int $spc = 0): string
    {
        $f = self::FONT;
        return '<a:r><a:rPr lang="' . (self::$lang === 'ru' ? 'ru-RU' : (self::$lang === 'en' ? 'en-US' : 'uz-UZ')) . '" sz="' . (int)round($sz * 100) . '" b="' . ($b ? 1 : 0) . '" i="' . ($i ? 1 : 0)
            . '"' . ($spc ? ' spc="' . $spc . '"' : '') . ' dirty="0">' . (is_array($color) && isset($color['grad']) ? self::fill($color + ['ang' => 0]) : '<a:solidFill>' . self::clr($color) . '</a:solidFill>') . '<a:latin typeface="' . $f . '"/><a:cs typeface="' . $f . '"/></a:rPr><a:t>'
            . self::esc($t) . '</a:t></a:r>';
    }

    private static function para(string $runs, string $algn = 'l', float $aftPt = 0, int $lnPct = 100, float $befPt = 0): string
    {
        return '<a:p><a:pPr marL="0" indent="0" algn="' . $algn . '"><a:lnSpc><a:spcPct val="' . ($lnPct * 1000) . '"/></a:lnSpc><a:spcBef><a:spcPts val="' . (int)round($befPt * 100)
            . '"/></a:spcBef><a:spcAft><a:spcPts val="' . (int)round($aftPt * 100) . '"/></a:spcAft><a:buNone/></a:pPr>' . $runs . '</a:p>';
    }

    /**
     * Shakl. $adj — yumaloq burchak (roundRect) uchun; $ph — placeholder turi ('title' va h.k.).
     */
    private static function sp(
        string $name, string $geom, int $x, int $y, int $w, int $h, $fill = null, $line = null, int $lw = 12700,
        string $paras = '', string $anchor = 't', array $ins = [0, 0, 0, 0], ?int $adj = null, bool $txBox = false, ?string $ph = null, bool $anim = false
    ): string {
        $id = self::nid();
        if ($anim) {
            self::$anim[] = $id;
        }
        $av = $adj !== null ? '<a:avLst><a:gd name="adj" fmla="val ' . $adj . '"/></a:avLst>' : '<a:avLst/>';
        $tx = $paras !== ''
            ? '<p:txBody><a:bodyPr wrap="square" lIns="' . $ins[0] . '" tIns="' . $ins[1] . '" rIns="' . $ins[2] . '" bIns="' . $ins[3] . '" anchor="' . $anchor
              . '" rtlCol="0"><a:noAutofit/></a:bodyPr><a:lstStyle/>' . $paras . '</p:txBody>'
            : '';
        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="' . self::esc($name) . '"/><p:cNvSpPr' . ($ph ? '><a:spLocks noGrp="1"/></p:cNvSpPr' : ($txBox ? ' txBox="1"/' : '/')) . '><p:nvPr>'
            . ($ph ? '<p:ph type="' . $ph . '"/>' : '') . '</p:nvPr></p:nvSpPr><p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $w . '" cy="' . $h
            . '"/></a:xfrm><a:prstGeom prst="' . $geom . '">' . $av . '</a:prstGeom>' . self::fill($fill) . self::ln($line, $lw) . '</p:spPr>' . $tx . '</p:sp>';
    }

    private static function tbox(string $name, int $x, int $y, int $w, int $h, string $paras, string $anchor = 't', bool $anim = false): string
    {
        return self::sp($name, 'rect', $x, $y, $w, $h, null, null, 0, $paras, $anchor, [0, 0, 0, 0], null, true, null, $anim);
    }

    /** Guruh: ichidagi shakllar mutlaq koordinatada; animatsiya butun guruhga tushadi. */
    private static function grp(string $name, int $x, int $y, int $w, int $h, string $children, bool $anim = true): string
    {
        $id = self::nid();
        if ($anim) {
            self::$anim[] = $id;
        }
        return '<p:grpSp><p:nvGrpSpPr><p:cNvPr id="' . $id . '" name="' . self::esc($name) . '"/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="' . $x . '" y="' . $y
            . '"/><a:ext cx="' . $w . '" cy="' . $h . '"/><a:chOff x="' . $x . '" y="' . $y . '"/><a:chExt cx="' . $w . '" cy="' . $h . '"/></a:xfrm></p:grpSpPr>' . $children . '</p:grpSp>';
    }

    private static function reg(string $path): string
    {
        $name = 'm' . substr(md5($path), 0, 10) . '.jpg';
        self::$media[$name] = $path;
        if (!isset(self::$rid[$name])) {
            self::$rid[$name] = 'rId' . (count(self::$rid) + 2);
        }
        return $name;
    }

    private static function pic(string $name, string $path, int $x, int $y, int $w, int $h, bool $round = true, bool $anim = false): string
    {
        $n = self::reg($path);
        $id = self::nid();
        if ($anim) {
            self::$anim[] = $id;
        }
        $geom = $round ? '<a:prstGeom prst="roundRect"><a:avLst><a:gd name="adj" fmla="val 4500"/></a:avLst></a:prstGeom>' : '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>';
        return '<p:pic><p:nvPicPr><p:cNvPr id="' . $id . '" name="' . self::esc($name) . '"/><p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr><p:blipFill><a:blip r:embed="'
            . self::$rid[$n] . '"/><a:stretch><a:fillRect/></a:stretch></p:blipFill><p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $w . '" cy="' . $h . '"/></a:xfrm>' . $geom
            . ($round ? self::ln([self::$T['acc'], 55], 19050) : '') . '</p:spPr></p:pic>';
    }

    // ================================================================== matn o'lchash

    private static function clip(string $t, int $max): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
        if (mb_strlen($t) <= $max) {
            return $t;
        }
        $cut = mb_substr($t, 0, $max - 1);
        $sp = mb_strrpos($cut, ' ');
        return rtrim($sp !== false && $sp > $max * 0.6 ? mb_substr($cut, 0, $sp) : $cut, " ,;:-") . '…';
    }

    private static function lines(string $t, float $wPt, float $pt, bool $bold = false): int
    {
        $cw = $pt * (self::CHAR_K + ($bold ? 0.045 : 0));
        $cap = max(4, (int)floor($wPt / $cw));
        $n = 0;
        foreach (preg_split('/\R/u', $t) ?: [''] as $seg) {
            $cur = 0;
            $n++;
            foreach (preg_split('/\s+/u', trim($seg)) ?: [] as $w) {
                $l = mb_strlen($w);
                if ($cur === 0) {
                    $cur = $l;
                } elseif ($cur + 1 + $l <= $cap) {
                    $cur += 1 + $l;
                } else {
                    $n++;
                    $cur = $l;
                }
                while ($cur > $cap) {         // juda uzun so'z — majburan bo'linadi
                    $n++;
                    $cur -= $cap;
                }
            }
        }
        return max(1, $n);
    }

    /**
     * Eng katta sig'adigan shrift o'lchamini topadi. $blocks: [[matn, qalin?, o'lchamKoeffitsienti, keyingiBo'shliqPt], ...]
     * Qaytadi: [pt, ishlatilgan balandlik EMU].
     */
    private static function fit(array $blocks, int $wEmu, int $hEmu, float $max, float $min): array
    {
        $wPt = $wEmu / 12700;
        for ($pt = $max; $pt >= $min; $pt -= 1) {
            $tot = 0.0;
            foreach ($blocks as [$t, $b, $k, $gap]) {
                $tot += self::lines($t, $wPt, $pt * $k, $b) * $pt * $k * 1.22 + $gap;
            }
            if ($tot * 12700 <= $hEmu) {
                return [$pt, (int)($tot * 12700)];
            }
        }
        $tot = 0.0;
        foreach ($blocks as [$t, $b, $k, $gap]) {
            $tot += self::lines($t, $wPt, $min * $k, $b) * $min * $k * 1.22 + $gap;
        }
        return [$min, (int)($tot * 12700)];
    }

    /** Berilgan shriftda bloklar egallaydigan balandlik (EMU). */
    private static function measure(array $blocks, int $wEmu, float $pt): int
    {
        $wPt = $wEmu / 12700;
        $tot = 0.0;
        foreach ($blocks as [$t, $b, $k, $gap]) {
            $tot += self::lines($t, $wPt, $pt * $k, $b) * $pt * $k * 1.22 + $gap;
        }
        return (int)($tot * 12700);
    }

    // ================================================================== umumiy bezaklar

    private static function card(string $name, int $x, int $y, int $w, int $h, int $adj = 5500, bool $strong = false): string
    {
        $T = self::$T;
        $fill = $T['dark'] ? ['FFFFFF', $strong ? 12 : 7] : ['FFFFFF', $strong ? 96 : 82];
        $line = $T['dark'] ? ['FFFFFF', $strong ? 24 : 15] : ['0F172A', 9];
        return self::sp($name, 'roundRect', $x, $y, $w, $h, $fill, $line, 12700, '', 't', [0, 0, 0, 0], $adj);
    }

    private static function onAcc(): string
    {
        $h = self::$T['acc'];
        $l = (0.2126 * hexdec(substr($h, 0, 2)) + 0.7152 * hexdec(substr($h, 2, 2)) + 0.0722 * hexdec(substr($h, 4, 2))) / 255;
        return $l > 0.55 ? '07182B' : 'FFFFFF';
    }

    private static function badge(string $name, int $x, int $y, int $d, string $text, float $sz): string
    {
        $T = self::$T;
        return self::sp($name, 'ellipse', $x, $y, $d, $d, ['grad' => [$T['acc'], $T['acc2']], 'ang' => 45], null, 0,
            self::para(self::run($text, $sz, self::onAcc(), true), 'ctr'), 'ctr', [0, 0, 0, 0]);
    }

    /** Tepa-chapdagi kichik "teg" (slayd turi) + pastki progress chizig'i + slayd raqami. */
    private static function chrome(int $n, int $total, string $tag, int $tagX = self::M): string
    {
        $T = self::$T;
        $W = self::W;
        $H = self::H;
        $s = '';
        if ($tag !== '') {
            $tw = 380000 + mb_strlen($tag) * 105000;
            $s .= self::sp('Teg', 'roundRect', $tagX, 430000, $tw, 310000, ['FFFFFF', $T['dark'] ? 6 : 0], [$T['acc'], 70], 12700,
                self::para(self::run($tag, 11, $T['acc'], true, false, 160), 'ctr'), 'ctr', [0, 0, 0, 0], 50000);
        }
        $s .= self::tbox('Slayd raqami', $W - self::M - 2000000, $H - 470000, 2000000, 300000,
            self::para(self::run(sprintf('%02d', $n) . ' / ' . sprintf('%02d', $total), 12, $T['sub'], false, false, 100), 'r'), 'ctr');
        $s .= self::sp('Progress fon', 'rect', 0, $H - 54000, $W, 54000, $T['dark'] ? ['FFFFFF', 9] : ['0F172A', 8]);
        $s .= self::sp('Progress', 'rect', 0, $H - 54000, (int)($W * $n / max(1, $total)), 54000, ['grad' => [$T['acc'], $T['acc2']], 'ang' => 0]);
        return $s;
    }

    /**
     * Sarlavha (+ ixtiyoriy izoh). Qaytadi: [xml, tana boshlanadigan y].
     */
    private static function head(string $title, string $lead, int $x, int $w, float $titleMax = 38): array
    {
        $T = self::$T;
        $title = self::clip($title, 110);
        $y = 870000;
        [$pt, $th] = self::fit([[$title, true, 1.0, 0]], $w, 1180000, $titleMax, 24);
        $th = max($th, (int)($pt * 1.25 * 12700));
        $s = self::sp('Sarlavha', 'rect', $x, $y, $w, $th + 40000, null, null, 0, self::para(self::run($title, $pt, $T['txt'], true), 'l', 0, 96), 't', [0, 0, 0, 0], null, true, 'title', true);
        $next = $y + $th + 140000;
        $lead = self::clip($lead, 240);
        if ($lead !== '') {
            [$lp, $lh] = self::fit([[$lead, false, 1.0, 0]], $w, 760000, 18, 13);
            $s .= self::tbox('Izoh', $x, $next, $w, $lh + 30000, self::para(self::run($lead, $lp, $T['sub'], false, true), 'l', 0, 105), 't', true);
            $next += $lh + 130000;
        }
        return [$s, $next + 90000];
    }

    /** Punkt: ['head','text','side']. Matn qisqartiriladi. */
    private static function points(array $slide, int $max = 8): array
    {
        $out = [];
        foreach (($slide['points'] ?? ($slide['bullets'] ?? [])) as $p) {
            if (is_string($p)) {
                $p = ['head' => '', 'text' => $p, 'side' => ''];
            }
            if (!is_array($p)) {
                continue;
            }
            $h = self::clip((string)($p['head'] ?? ''), 70);
            $t = self::clip((string)($p['text'] ?? ''), 240);
            if ($h !== '' || $t !== '') {
                $out[] = ['head' => $h, 'text' => $t, 'side' => strtolower((string)($p['side'] ?? ''))];
            }
        }
        return array_slice($out, 0, $max);
    }

    /** Punkt matni (sarlavha + izoh) paragraflari. */
    private static function pointParas(array $p, float $sz, string $algn = 'l'): string
    {
        $T = self::$T;
        $s = '';
        if ($p['head'] !== '') {
            $s .= self::para(self::run($p['head'], $sz + 1.5, $T['txt'], true), $algn, $p['text'] !== '' ? 2 : 0, 100);
        }
        if ($p['text'] !== '') {
            $s .= self::para(self::run($p['text'], $sz, $T['mut']), $algn, 0, 102);
        }
        return $s;
    }

    private static function pointBlocks(array $p): array
    {
        $b = [];
        if ($p['head'] !== '') {
            $b[] = [$p['head'], true, 1.1, $p['text'] !== '' ? 2 : 0];
        }
        if ($p['text'] !== '') {
            $b[] = [$p['text'], false, 1.0, 0];
        }
        return $b;
    }

    /** Hamma punktga umumiy shrift (eng qiyin punktga moslab). */
    private static function commonSize(array $pts, int $w, int $h, float $max, float $min): float
    {
        $sz = $max;
        foreach ($pts as $p) {
            [$pt] = self::fit(self::pointBlocks($p), $w, $h, $max, $min);
            $sz = min($sz, $pt);
        }
        return $sz;
    }

    // ================================================================== maketlar

    /** MUQOVA: to'liq ekranli rasm, chapda sarlavha, pastda shisha karta (ijrochi ma'lumoti). */
    private static function cover(array $s, int $n, int $total): string
    {
        $T = self::$T;
        $W = self::W;
        $H = self::H;
        $title = self::clip((string)($s['title'] ?? ''), 130);
        $words = preg_split('/\s+/u', $title) ?: [$title];
        $hi = -1;
        for ($i = count($words) - 1; $i >= 0; $i--) {
            if (mb_strlen($words[$i]) >= 4 && count($words) >= 2) {
                $hi = $i;
                break;
            }
        }
        $tw = 7600000;
        [$pt, $th] = self::fit([[$title, true, 1.0, 0]], $tw, 2650000, 60, 26);
        $runs = '';
        foreach ($words as $i => $w) {
            $runs .= self::run($w . ($i < count($words) - 1 ? ' ' : ''), $pt, $i === $hi ? $T['acc'] : $T['txt'], true);
        }
        $out = self::sp('Teg', 'roundRect', self::M, 1250000, 2250000, 330000, ['FFFFFF', 6], [$T['acc'], 70], 12700,
            self::para(self::run(I18n::t('pill_presentation', self::$lang), 12, $T['acc'], true, false, 200), 'ctr'), 'ctr', [0, 0, 0, 0], 50000);
        $out .= self::sp('Sarlavha', 'rect', self::M, 1800000, $tw, $th + 60000, null, null, 0, self::para($runs, 'l', 0, 94), 't', [0, 0, 0, 0], null, true, 'title', true);
        $out .= self::sp('Chiziq', 'roundRect', self::M, 1800000 + $th + 230000, 1100000, 60000, ['grad' => [$T['acc'], $T['acc2']], 'ang' => 0], null, 0, '', 't', [0, 0, 0, 0], 50000, false, null, true);

        $lines = array_values(array_filter(array_map('strval', $s['bullets'] ?? [])));
        if ($lines) {
            $ph = 200000 + count($lines) * 390000;
            $py = $H - 700000 - $ph;
            $paras = '';
            foreach ($lines as $i => $l) {
                $paras .= self::para(self::run(self::clip($l, 90), $i === 0 ? 17 : 15, $i === 0 ? $T['txt'] : $T['mut'], $i === 0), 'l', 4);
            }
            $inner = self::card('Karta', self::M, $py, 6400000, $ph, 9000, true)
                . self::sp('Aksent', 'roundRect', self::M + 170000, $py + 150000, 60000, $ph - 300000, ['grad' => [$T['acc'], $T['acc2']], 'ang' => 90], null, 0, '', 't', [0, 0, 0, 0], 50000)
                . self::tbox('Ijrochi', self::M + 400000, $py, 5800000, $ph, $paras, 'ctr');
            $out .= self::grp('Ijrochi kartasi', self::M, $py, 6400000, $ph, $inner);
        }
        return $out . self::chrome($n, $total, '');
    }

    /** YAKUN: markazda katta "Rahmat!". */
    private static function thanks(array $s, int $n, int $total): string
    {
        $T = self::$T;
        $W = self::W;
        $title = self::clip((string)($s['title'] ?? ''), 60);
        [$pt] = self::fit([[$title, true, 1.0, 0]], 9800000, 1700000, 84, 40);
        $out = self::sp('Teg', 'roundRect', (int)(($W - 1900000) / 2), 1750000, 1900000, 330000, ['FFFFFF', 6], [$T['acc'], 70], 12700,
            self::para(self::run(I18n::t('pill_end', self::$lang), 12, $T['acc'], true, false, 200), 'ctr'), 'ctr', [0, 0, 0, 0], 50000);
        $out .= self::sp('Sarlavha', 'rect', 1200000, 2350000, $W - 2400000, 1750000, null, null, 0, self::para(self::run($title, $pt, $T['txt'], true), 'ctr', 0, 94), 'ctr', [0, 0, 0, 0], null, true, 'title', true);
        $out .= self::sp('Chiziq', 'roundRect', (int)(($W - 1400000) / 2), 4250000, 1400000, 60000, ['grad' => [$T['acc'], $T['acc2']], 'ang' => 0], null, 0, '', 't', [0, 0, 0, 0], 50000, false, null, true);
        $sub = trim((string)(($s['bullets'][0] ?? '')));
        if ($sub !== '') {
            $out .= self::tbox('Izoh', 1500000, 4550000, $W - 3000000, 600000, self::para(self::run(self::clip($sub, 90), 22, $T['mut']), 'ctr'), 't', true);
        }
        return $out . self::chrome($n, $total, '');
    }

    /** BO'LIM: ulkan gradient raqam, bo'lim nomi va ichidagi slaydlar ro'yxati (AI so'rovisiz). */
    private static function section(array $s, int $n, int $total): string
    {
        $T = self::$T;
        $W = self::W;
        $num = preg_replace('/\D/', '', (string)($s['lead'] ?? '')) ?: (string)$n;
        $num = str_pad(substr($num, 0, 2), 2, '0', STR_PAD_LEFT);
        $title = self::clip((string)($s['title'] ?? ''), 90);
        $items = array_slice(array_values(array_filter(array_map(fn($t) => self::clip((string)$t, 70), $s['items'] ?? []))), 0, 5);
        $x = self::M + 80000;
        $out = self::tbox('Raqam', $x - 60000, 900000, 5200000, 2500000,
            self::para(self::run($num, 170, ['grad' => [$T['acc'], $T['acc2']]], true), 'l', 0, 80), 'b');
        $out .= self::sp('Chiziq', 'roundRect', $x, 3550000, 1300000, 60000, ['grad' => [$T['acc'], $T['acc2']], 'ang' => 0], null, 0, '', 't', [0, 0, 0, 0], 50000, false, null, true);
        $out .= self::tbox('Teg', $x, 3720000, 7000000, 330000,
            self::para(self::run(mb_strtoupper(I18n::t('tag_bolim', self::$lang)) . ' ' . (int)$num, 13, $T['acc'], true, false, 300), 'l'), 'ctr', true);
        [$pt, $th] = self::fit([[$title, true, 1.0, 0]], 7800000, 1500000, 48, 26);
        $out .= self::sp('Sarlavha', 'rect', $x, 4100000, 7800000, $th + 60000, null, null, 0, self::para(self::run($title, $pt, $T['txt'], true), 'l', 0, 94), 't', [0, 0, 0, 0], null, true, 'title', true);
        if ($items) {
            $paras = '';
            foreach ($items as $it) {
                $paras .= self::para(self::run('—  ' . $it, 16, $T['mut']), 'l', 5);
            }
            $cw = 3400000;
            $cx = $W - self::M - $cw;
            $ch = 380000 + count($items) * 420000;
            $cy = 1400000;
            $inner = self::card('Karta', $cx, $cy, $cw, $ch, 7000)
                . self::tbox('Ichida', $cx + 260000, $cy + 190000, $cw - 420000, $ch - 300000, $paras, 'ctr');
            $out .= self::grp('Bo\'lim ichida', $cx, $cy, $cw, $ch, $inner);
        }
        return $out . self::chrome($n, $total, '');
    }

    /** REJA: 2 ustunli raqamlangan kartalar. */
    private static function agenda(array $s, int $n, int $total): string
    {
        $T = self::$T;
        [$out, $y0] = self::head((string)($s['title'] ?? ''), '', self::M, self::W - 2 * self::M);
        $items = array_slice(array_map(fn($i) => self::clip((string)$i, 80), $s['items'] ?? []), 0, 8);
        $cnt = max(1, count($items));
        $cols = $cnt > 4 ? 2 : 1;
        $rows = (int)ceil($cnt / $cols);
        $gap = 170000;
        $areaH = self::H - 700000 - $y0;
        $cw = $cols === 2 ? (int)((self::W - 2 * self::M - $gap) / 2) : 8600000;
        $ch = min(980000, (int)(($areaH - ($rows - 1) * $gap) / $rows));
        $y0 += (int)(max(0, $areaH - $rows * $ch - ($rows - 1) * $gap) * 0.4);     // ro'yxat bo'sh joyda muvozanatli turadi
        $x0 = self::M;
        foreach ($items as $i => $it) {
            $c = (int)floor($i / $rows);
            $r = $i % $rows;
            $x = $x0 + $c * ($cw + $gap);
            $y = $y0 + $r * ($ch + $gap);
            [$pt] = self::fit([[$it, false, 1.0, 0]], $cw - 1150000, $ch - 80000, 23, 13);
            $inner = self::card('Karta', $x, $y, $cw, $ch)
                . self::tbox('Raqam', $x + 140000, $y, 860000, $ch, self::para(self::run(sprintf('%02d', $i + 1), 28, $T['acc'], true), 'l'), 'ctr')
                . self::tbox('Matn', $x + 1020000, $y, $cw - 1150000, $ch, self::para(self::run($it, $pt, $T['txt']), 'l', 0, 100), 'ctr');
            $out .= self::grp('Reja ' . ($i + 1), $x, $y, $cw, $ch, $inner);
        }
        return $out . self::chrome($n, $total, I18n::t('tag_reja', self::$lang));
    }

    /** Raqamli qatorlar (matn slaydi). */
    private static function rows(array $pts, int $x, int $y, int $w, int $h): string
    {
        $T = self::$T;
        $N = max(1, count($pts));
        $gap = $N > 5 ? 80000 : 120000;
        $rowH = (int)(($h - ($N - 1) * $gap) / $N);
        $d = max(260000, min(470000, $rowH - 150000));
        $pad = 170000;
        $tx = $x + $pad + $d + 190000;
        $tw = $w - ($tx - $x) - $pad;
        $sz = self::commonSize($pts, $tw, $rowH - 70000, 20, 11);
        $out = '';
        foreach ($pts as $i => $p) {
            $ry = $y + $i * ($rowH + $gap);
            $inner = self::card('Karta', $x, $ry, $w, $rowH, 7000)
                . self::badge('Raqam', $x + $pad, $ry + (int)(($rowH - $d) / 2), $d, (string)($i + 1), $d > 400000 ? 17 : 14)
                . self::tbox('Matn', $tx, $ry, $tw, $rowH, self::pointParas($p, $sz), 'ctr');
            $out .= self::grp('Punkt ' . ($i + 1), $x, $ry, $w, $rowH, $inner);
        }
        return $out;
    }

    /** MATN: (rasm paneli) + raqamli qatorlar. */
    private static function textSlide(array $s, int $n, int $total, ?string $panel): string
    {
        $W = self::W;
        $H = self::H;
        $pts = self::points($s, 6);
        $kind = I18n::t('tag_' . (($s['kind'] ?? 'matn') === 'tarif' ? 'tarif' : 'matn'), self::$lang);
        $out = '';
        $pw = 4200000;
        if ($panel) {
            $left = $n % 2 === 0;
            $px = $left ? 0 : $W - $pw;
            $out .= self::pic('Rasm', $panel, $px, 0, $pw, $H, false, true);
            $cx = $left ? $pw + 420000 : self::M;
            $cw = $W - $pw - 420000 - self::M;
        } else {
            $cx = self::M;
            $cw = $W - 2 * self::M;
        }
        [$h, $y0] = self::head((string)($s['title'] ?? ''), (string)($s['lead'] ?? ''), $cx, $cw, $panel ? 34 : 38);
        $out .= $h;
        $bottom = $H - 640000;
        if (!$panel && count($pts) >= 5) {          // keng ekranda 5-6 punkt: 2 ustun
            $half = (int)ceil(count($pts) / 2);
            $gw = (int)(($cw - 160000) / 2);
            $out .= self::rows(array_slice($pts, 0, $half), $cx, $y0, $gw, $bottom - $y0)
                . self::rows(array_slice($pts, $half), $cx + $gw + 160000, $y0, $gw, (int)(($bottom - $y0) * (count($pts) - $half) / $half));
        } else {
            $out .= self::rows($pts, $cx, $y0, $cw, min($bottom - $y0, (count($pts) <= 4 ? 1150000 : 880000) * max(1, count($pts))));
        }
        return $out . self::chrome($n, $total, $kind, $panel && $n % 2 === 0 ? $cx : self::M);
    }

    /**
     * TA'RIF: tepada butun kenglikda katta ta'rif bloki (qo'shtirnoq + aksent chiziq), pastda asosiy belgilar
     * gorizontal kartalarda (raqam + sarlavha + izoh). Ta'rif matni hajmiga qarab blok balandligi o'zgaradi.
     */
    private static function definition(array $s, int $n, int $total): string
    {
        $T = self::$T;
        $W = self::W;
        $H = self::H;
        $pts = self::points($s, 4);
        $lead = self::clip((string)($s['lead'] ?? ''), 330);
        if ($lead === '' && $pts) {
            $lead = $pts[0]['text'] !== '' ? $pts[0]['text'] : $pts[0]['head'];
            $pts = array_slice($pts, 1);
        }
        [$out, $y0] = self::head((string)($s['title'] ?? ''), '', self::M, $W - 2 * self::M);
        $bw = $W - 2 * self::M;
        $bottom = $H - 680000;
        $avail = $bottom - $y0;
        $tx = self::M + 1150000;
        $twd = $bw - 1150000 - 450000;
        // ta'rif bloki uchun joy: belgilar bo'lsa ~45%, bo'lmasa hammasi
        $maxBox = $pts ? (int)($avail * 0.47) : $avail;
        [$pt] = self::fit([[$lead, false, 1.0, 0]], $twd, $maxBox - 360000, $pts ? 24 : 32, 15);
        $bh = $pts ? min($maxBox, max(1150000, self::measure([[$lead, false, 1.0, 0]], $twd, $pt) + 400000)) : $avail;
        $inner = self::card('Ta\'rif foni', self::M, $y0, $bw, $bh, 4500, true)
            . self::sp('Aksent', 'roundRect', self::M, $y0, 90000, $bh, ['grad' => [$T['acc'], $T['acc2']], 'ang' => 90], null, 0, '', 't', [0, 0, 0, 0], 50000)
            . self::tbox('Qo\'shtirnoq', self::M + 300000, $y0 + 60000, 900000, 900000, self::para(self::run('“', 88, $T['acc'], true), 'l'), 't')
            . self::tbox('Ta\'rif', $tx, $y0, $twd, $bh, self::para(self::run($lead, $pt, $T['txt']), 'l', 0, 108), 'ctr');
        $out .= self::grp('Ta\'rif', self::M, $y0, $bw, $bh, $inner);
        if ($pts) {
            $ly = $y0 + $bh + 230000;
            $out .= self::tbox('Belgilar sarlavhasi', self::M, $ly, $bw, 300000, self::para(self::run(I18n::t('key_features', self::$lang), 12, $T['acc'], true, false, 160), 'l'), 'ctr', true);
            $cy = $ly + 400000;
            $cnt = count($pts);
            $gap = 190000;
            $cw = (int)(($bw - ($cnt - 1) * $gap) / $cnt);
            $d = 440000;
            $tw = $cw - 2 * 220000;
            $chMax = $bottom - $cy;
            $sz = self::commonSize($pts, $tw, $chMax - $d - 330000, 18, 11);
            $need = 0;
            foreach ($pts as $p) {
                $need = max($need, self::measure(self::pointBlocks($p), $tw, $sz));
            }
            $ch = min($chMax, $need + $d + 480000);
            foreach ($pts as $i => $p) {
                $x = self::M + $i * ($cw + $gap);
                $inner = self::card('Karta', $x, $cy, $cw, $ch, 7000)
                    . self::badge('Raqam', $x + 220000, $cy + 200000, $d, (string)($i + 1), 16)
                    . self::tbox('Matn', $x + 220000, $cy + 200000 + $d + 100000, $tw, $ch - $d - 330000, self::pointParas($p, $sz), 't');
                $out .= self::grp('Belgi ' . ($i + 1), $x, $cy, $cw, $ch, $inner);
            }
        }
        return $out . self::chrome($n, $total, I18n::t('tag_tarif', self::$lang));
    }

    /** KARTALAR: 2-6 ta shisha karta. */
    private static function cards(array $s, int $n, int $total): string
    {
        $T = self::$T;
        $pts = self::points($s, 6);
        [$out, $y0] = self::head((string)($s['title'] ?? ''), (string)($s['lead'] ?? ''), self::M, self::W - 2 * self::M);
        $cnt = max(1, count($pts));
        $cols = $cnt <= 2 ? $cnt : ($cnt === 4 ? 2 : 3);
        $rows = (int)ceil($cnt / $cols);
        $gap = 190000;
        $areaW = self::W - 2 * self::M;
        $areaH = self::H - 660000 - $y0;
        $cw = (int)(($areaW - ($cols - 1) * $gap) / $cols);
        $ch = min(2600000, (int)(($areaH - ($rows - 1) * $gap) / $rows));
        $d = 520000;
        $tw = $cw - 2 * 240000;
        $sz = self::commonSize($pts, $tw, $ch - $d - 330000, 18, 11);
        foreach ($pts as $i => $p) {
            $c = $i % $cols;
            $r = (int)floor($i / $cols);
            $x = self::M + $c * ($cw + $gap);
            $y = $y0 + $r * ($ch + $gap);
            $inner = self::card('Karta', $x, $y, $cw, $ch, 6000)
                . self::badge('Raqam', $x + 240000, $y + 220000, $d, (string)($i + 1), 18)
                . self::tbox('Matn', $x + 240000, $y + 220000 + $d + 90000, $tw, $ch - $d - 330000, self::pointParas($p, $sz), 't');
            $out .= self::grp('Karta ' . ($i + 1), $x, $y, $cw, $ch, $inner);
        }
        return $out . self::chrome($n, $total, I18n::t('tag_kartalar', self::$lang));
    }

    /** BOSQICHLAR: gorizontal oqim (3-6 bosqich). */
    private static function steps(array $s, int $n, int $total): string
    {
        $T = self::$T;
        $pts = self::points($s, 6);
        [$out, $y0] = self::head((string)($s['title'] ?? ''), (string)($s['lead'] ?? ''), self::M, self::W - 2 * self::M);
        $cnt = max(1, count($pts));
        $gap = 170000;
        $areaW = self::W - 2 * self::M;
        $cw = (int)(($areaW - ($cnt - 1) * $gap) / $cnt);
        $d = 640000;
        $ny = $y0 + 20000;
        $cy = $ny + $d + 170000;
        $ch = min(self::H - 660000 - $cy, 3000000);
        $tw = $cw - 2 * 170000;
        $sz = self::commonSize($pts, $tw, $ch - 200000, 20, 11);
        $need = 0;
        foreach ($pts as $p) {
            $need = max($need, self::measure(self::pointBlocks($p), $tw, $sz));
        }
        $full = $ch;
        $ch = min($ch, max((int)($ch * 0.5), $need + 420000));
        $shift = (int)(($full - $ch) * 0.4);
        $ny += $shift;
        $cy += $shift;
        // ulovchi chiziq
        $out .= self::sp('Chiziq', 'rect', self::M + (int)($cw / 2), $ny + (int)($d / 2) - 15000, $areaW - $cw, 30000, [$T['acc'], 45], null, 0, '', 't', [0, 0, 0, 0], null, false, null, true);
        foreach ($pts as $i => $p) {
            $x = self::M + $i * ($cw + $gap);
            $inner = self::badge('Raqam', $x + (int)(($cw - $d) / 2), $ny, $d, (string)($i + 1), 22)
                . self::card('Karta', $x, $cy, $cw, $ch, 7000)
                . self::tbox('Matn', $x + 170000, $cy + 100000, $tw, $ch - 200000, self::pointParas($p, $sz, 'ctr'), 'ctr');
            $out .= self::grp('Bosqich ' . ($i + 1), $x, $ny, $cw, $cy + $ch - $ny, $inner);
        }
        return $out . self::chrome($n, $total, I18n::t('tag_bosqichlar', self::$lang));
    }

    /** XRONOLOGIYA: markaziy chiziq, kartalar tepa/pastda navbatma-navbat. */
    private static function timeline(array $s, int $n, int $total): string
    {
        $T = self::$T;
        $pts = self::points($s, 6);
        [$out, $y0] = self::head((string)($s['title'] ?? ''), (string)($s['lead'] ?? ''), self::M, self::W - 2 * self::M);
        $cnt = max(1, count($pts));
        $areaW = self::W - 2 * self::M;
        $bottom = self::H - 640000;
        $midY = (int)(($y0 + $bottom) / 2);
        $cardH = (int)(($bottom - $y0) / 2) - 330000;
        $slot = (int)($areaW / $cnt);
        $cw = min(3700000, (int)($slot * ($cnt > 1 ? 1.9 : 1.0)));
        $cw = max($cw, 2200000);
        $sz = self::commonSize($pts, $cw - 320000, $cardH - 120000, 17, 11);
        $out .= self::sp('Chiziq', 'rect', self::M, $midY - 15000, $areaW, 30000, [$T['acc'], 55], null, 0, '', 't', [0, 0, 0, 0], null, false, null, true);
        foreach ($pts as $i => $p) {
            $nx = self::M + (int)($slot * ($i + 0.5));
            $up = $i % 2 === 0;
            $x = max(self::M, min(self::W - self::M - $cw, $nx - (int)($cw / 2)));
            $cy = $up ? $midY - 260000 - $cardH : $midY + 260000;
            $dotD = 300000;
            $stemY = $up ? $cy + $cardH : $midY;
            $stemH = $up ? $midY - ($cy + $cardH) : $cy - $midY;
            $inner = self::sp('Nuqta', 'ellipse', $nx - (int)($dotD / 2), $midY - (int)($dotD / 2), $dotD, $dotD, ['grad' => [$T['acc'], $T['acc2']], 'ang' => 45], [$T['dark'] ? $T['bg'] : 'FFFFFF', 100], 38100)
                . self::sp('Tayoq', 'rect', $nx - 9525, $stemY, 19050, $stemH, [$T['acc'], 60])
                . self::card('Karta', $x, $cy, $cw, $cardH, 7000)
                . self::tbox('Matn', $x + 160000, $cy, $cw - 320000, $cardH, self::timelineParas($p, $sz), 'ctr');
            $top = min($cy, $midY - (int)($dotD / 2));
            $bot = max($cy + $cardH, $midY + (int)($dotD / 2));
            $out .= self::grp('Voqea ' . ($i + 1), min($x, $nx - (int)($dotD / 2)), $top, max($x + $cw, $nx + (int)($dotD / 2)) - min($x, $nx - (int)($dotD / 2)), $bot - $top, $inner);
        }
        return $out . self::chrome($n, $total, I18n::t('tag_xronologiya', self::$lang));
    }

    private static function timelineParas(array $p, float $sz): string
    {
        $T = self::$T;
        $s = '';
        if ($p['head'] !== '') {
            $s .= self::para(self::run($p['head'], $sz + 3, $T['acc'], true), 'l', 3);
        }
        if ($p['text'] !== '') {
            $s .= self::para(self::run($p['text'], $sz, $T['mut']), 'l', 0, 102);
        }
        return $s;
    }

    /** TAQQOSLASH: ikki ustun + "VS". */
    private static function compare(array $s, int $n, int $total): string
    {
        $T = self::$T;
        $pts = self::points($s, 8);
        [$out, $y0] = self::head((string)($s['title'] ?? ''), (string)($s['lead'] ?? ''), self::M, self::W - 2 * self::M);
        $L = [];
        $R = [];
        foreach ($pts as $p) {
            if (in_array($p['side'], ['ong', 'o\'ng', 'right', 'r'], true)) {
                $R[] = $p;
            } else {
                $L[] = $p;
            }
        }
        if (!$R || !$L) {                       // tomonlar berilmagan — teng bo'lamiz
            $half = (int)ceil(count($pts) / 2);
            $L = array_slice($pts, 0, $half);
            $R = array_slice($pts, $half);
        }
        $gap = 560000;
        $colW = (int)((self::W - 2 * self::M - $gap) / 2);
        $hh = 520000;
        $bottom = self::H - 640000;
        $lt = self::clip((string)($s['left_title'] ?? ''), 40) ?: '—';
        $rt = self::clip((string)($s['right_title'] ?? ''), 40) ?: '—';
        foreach ([[$L, self::M, $lt, [$T['acc'], $T['acc2']]], [$R, self::M + $colW + $gap, $rt, [$T['acc2'], $T['acc']]]] as $ci => [$list, $x, $ttl, $g]) {
            $out .= self::grp('Sarlavha ' . ($ci + 1), $x, $y0, $colW, $hh, self::sp('Chap/ong', 'roundRect', $x, $y0, $colW, $hh, ['grad' => $g, 'ang' => 0], null, 0,
                self::para(self::run($ttl, 19, self::onAcc(), true), 'ctr'), 'ctr', [90000, 0, 90000, 0], 14000));
            $list = array_slice($list, 0, 4);
            $cnt = max(1, count($list));
            $ry = $y0 + $hh + 150000;
            $gp = 110000;
            $rh = (int)(($bottom - $ry - ($cnt - 1) * $gp) / $cnt);
            $rh = min($rh, 1050000);
            $sz = self::commonSize($list, $colW - 520000, $rh - 70000, 17, 11);
            foreach ($list as $i => $p) {
                $y = $ry + $i * ($rh + $gp);
                $inner = self::card('Karta', $x, $y, $colW, $rh, 7000)
                    . self::sp('Belgi', 'ellipse', $x + 190000, $y + (int)(($rh - 150000) / 2), 150000, 150000, ['grad' => $g, 'ang' => 45])
                    . self::tbox('Matn', $x + 470000, $y, $colW - 620000, $rh, self::pointParas($p, $sz), 'ctr');
                $out .= self::grp('Band ' . ($ci + 1) . '.' . ($i + 1), $x, $y, $colW, $rh, $inner);
            }
        }
        $vsD = 600000;
        $out .= self::sp('VS', 'ellipse', (int)((self::W - $vsD) / 2), $y0 + (int)(($hh - $vsD) / 2), $vsD, $vsD, $T['dark'] ? $T['bg'] : 'FFFFFF', [$T['acc'], 100], 28575,
            self::para(self::run('VS', 15, $T['acc'], true), 'ctr'), 'ctr', [0, 0, 0, 0], null, false, null, true);
        return $out . self::chrome($n, $total, I18n::t('tag_taqqoslash', self::$lang));
    }

    /** XULOSA: katta sarlavha + belgilar ro'yxati. */
    private static function conclusion(array $s, int $n, int $total): string
    {
        $T = self::$T;
        $pts = self::points($s, 6);
        [$out, $y0] = self::head((string)($s['title'] ?? ''), (string)($s['lead'] ?? ''), self::M, self::W - 2 * self::M);
        $cnt = max(1, count($pts));
        $cols = $cnt > 3 ? 2 : 1;
        $per = (int)ceil($cnt / $cols);
        $gap = 150000;
        $cw = $cols === 2 ? (int)((self::W - 2 * self::M - $gap) / 2) : self::W - 2 * self::M;
        $areaH = self::H - 660000 - $y0;
        $rh = min(1050000, (int)(($areaH - ($per - 1) * $gap) / $per));
        $d = 420000;
        $sz = self::commonSize($pts, $cw - 330000 - $d - 190000, $rh - 70000, 18, 11);
        foreach ($pts as $i => $p) {
            $c = (int)floor($i / $per);
            $r = $i % $per;
            $x = self::M + $c * ($cw + $gap);
            $y = $y0 + $r * ($rh + $gap);
            $inner = self::card('Karta', $x, $y, $cw, $rh, 7000, true)
                . self::sp('Belgi', 'ellipse', $x + 170000, $y + (int)(($rh - $d) / 2), $d, $d, ['grad' => [$T['acc'], $T['acc2']], 'ang' => 45], null, 0,
                    self::para(self::run('✓', 16, self::onAcc(), true), 'ctr'), 'ctr', [0, 0, 0, 0])
                . self::tbox('Matn', $x + 170000 + $d + 190000, $y, $cw - 330000 - $d - 190000, $rh, self::pointParas($p, $sz), 'ctr');
            $out .= self::grp('Xulosa ' . ($i + 1), $x, $y, $cw, $rh, $inner);
        }
        return $out . self::chrome($n, $total, I18n::t('tag_xulosa', self::$lang));
    }

    // ================================================================== animatsiya / o'tish

    private static function transition(string $kind, bool $first, bool $last): string
    {
        if ($first || $last) {
            return '<p:transition spd="slow"><p:fade/></p:transition>';
        }
        return match ($kind) {
            'matn', 'tarif' => '<p:transition spd="med"><p:push dir="l"/></p:transition>',
            'bosqichlar', 'xronologiya' => '<p:transition spd="med"><p:wipe dir="r"/></p:transition>',
            default => '<p:transition spd="med"><p:fade/></p:transition>',
        };
    }

    /** Elementlar slayd ochilgach O'ZI (bosishsiz) birin-ketin paydo bo'ladi: "Fade" kirish effekti. */
    private static function timing(array $ids): string
    {
        if (!$ids) {
            return '';
        }
        $tid = 4;
        $eff = '';
        foreach ($ids as $k => $sid) {
            $delay = 180 + $k * 230;
            $a = ++$tid;
            $b = ++$tid;
            $c = ++$tid;
            $eff .= '<p:par><p:cTn id="' . $a . '" presetID="10" presetClass="entr" presetSubtype="0" fill="hold" nodeType="withEffect"><p:stCondLst><p:cond delay="' . $delay . '"/></p:stCondLst><p:childTnLst>'
                . '<p:set><p:cBhvr><p:cTn id="' . $b . '" dur="1" fill="hold"><p:stCondLst><p:cond delay="0"/></p:stCondLst></p:cTn><p:tgtEl><p:spTgt spid="' . $sid . '"/></p:tgtEl>'
                . '<p:attrNameLst><p:attrName>style.visibility</p:attrName></p:attrNameLst></p:cBhvr><p:to><p:strVal val="visible"/></p:to></p:set>'
                . '<p:animEffect transition="in" filter="fade"><p:cBhvr><p:cTn id="' . $c . '" dur="550"/><p:tgtEl><p:spTgt spid="' . $sid . '"/></p:tgtEl></p:cBhvr></p:animEffect>'
                . '</p:childTnLst></p:cTn></p:par>';
        }
        return '<p:timing><p:tnLst><p:par><p:cTn id="1" dur="indefinite" restart="never" nodeType="tmRoot"><p:childTnLst>'
            . '<p:seq concurrent="1" nextAc="seek"><p:cTn id="2" dur="indefinite" nodeType="mainSeq"><p:childTnLst>'
            . '<p:par><p:cTn id="3" fill="hold"><p:stCondLst><p:cond delay="indefinite"/><p:cond evt="onBegin" delay="0"><p:tn val="2"/></p:cond></p:stCondLst><p:childTnLst>'
            . '<p:par><p:cTn id="4" fill="hold"><p:stCondLst><p:cond delay="0"/></p:stCondLst><p:childTnLst>' . $eff . '</p:childTnLst></p:cTn></p:par>'
            . '</p:childTnLst></p:cTn></p:par></p:childTnLst></p:cTn><p:prevCondLst><p:cond evt="onPrev" delay="0"><p:tgtEl><p:sldTgt/></p:tgtEl></p:cond></p:prevCondLst>'
            . '<p:nextCondLst><p:cond evt="onNext" delay="0"><p:tgtEl><p:sldTgt/></p:tgtEl></p:cond></p:nextCondLst></p:seq></p:childTnLst></p:cTn></p:par></p:tnLst></p:timing>';
    }

    // ================================================================== paket

    /**
     * @param array  $final   [['kind'=>'title'|'reja'|'matn'|...,'title'=>..,'points'=>[..],'image'=>path?,'notes'=>str?], ...]
     * @param string $themeKey THEMES kaliti
     * @return string  PPTX fayl yo'li
     */
    public static function generate(array $final, string $themeKey, string $lang = 'uz', string $docTitle = ''): string
    {
        $theme = self::THEMES[$themeKey] ?? self::THEMES['pro_night'];
        self::$T = $theme;
        self::$lang = I18n::norm($lang);
        $total = count($final);
        $tmp = [];
        $abs = [];
        $absBg = function (int $v) use (&$abs, &$tmp, $theme): ?string {
            $v %= 3;
            if (!array_key_exists($v, $abs)) {
                $abs[$v] = ProArt::abstractBg($theme, $v);
                if ($abs[$v]) {
                    $tmp[] = $abs[$v];
                }
            }
            return $abs[$v];
        };
        $coverSrc = null;
        foreach ($final as $s) {
            if (($s['kind'] ?? '') === 'title' && !empty($s['image']) && is_file($s['image'])) {
                $coverSrc = $s['image'];
            }
        }

        $parts = [];
        $mediaAll = [];
        foreach ($final as $i => $s) {
            $n = $i + 1;
            $kind = (string)($s['kind'] ?? 'matn');
            self::$id = 1;
            self::$anim = [];
            self::$media = [];
            self::$rid = [];
            $bg = null;
            $panel = null;
            $img = (!empty($s['image']) && is_file($s['image'])) ? $s['image'] : null;

            if ($kind === 'title') {
                $bg = $coverSrc ? ProArt::photoFull($coverSrc, $theme, 'left') : null;
                $bg = $bg ?: $absBg(0);
                if ($bg && $coverSrc) {
                    $tmp[] = $bg;
                }
            } elseif ($kind === 'thanks') {
                $bg = $coverSrc ? ProArt::photoFull($coverSrc, $theme, 'dim') : null;
                if ($bg) {
                    $tmp[] = $bg;
                }
                $bg = $bg ?: $absBg(1);
            } elseif (!in_array($kind, ['matn', 'reja'], true) && $img) {
                $bg = ProArt::photoFull($img, $theme, 'dim');
                if ($bg) {
                    $tmp[] = $bg;
                }
                $bg = $bg ?: $absBg($n);
            } else {
                $bg = $absBg($n);
                if ($kind === 'matn' && $img) {
                    $left = $n % 2 === 0;
                    $pf = 4200000 / self::W;
                    $panel = ProArt::photoPanel($img, $theme, 4200000, self::H, $left ? 'right' : 'left', $bg, $left ? 0.0 : 1 - $pf, $left ? $pf : 1.0);
                    if ($panel) {
                        $tmp[] = $panel;
                    }
                }
            }
            $bgName = $bg ? self::reg($bg) : '';

            $shapes = match (true) {
                $kind === 'title' => self::cover($s, $n, $total),
                $kind === 'thanks' => self::thanks($s, $n, $total),
                $kind === 'reja' => self::agenda($s, $n, $total),
                $kind === 'bolim' => self::section($s, $n, $total),
                $kind === 'tarif' => self::definition($s, $n, $total),
                $kind === 'kartalar' => self::cards($s, $n, $total),
                $kind === 'bosqichlar' => self::steps($s, $n, $total),
                $kind === 'xronologiya' => self::timeline($s, $n, $total),
                $kind === 'taqqoslash' => self::compare($s, $n, $total),
                $kind === 'xulosa' => self::conclusion($s, $n, $total),
                default => self::textSlide($s, $n, $total, $panel),
            };
            $bgXml = $bgName ? '<p:bg><p:bgPr><a:blipFill dpi="0" rotWithShape="1"><a:blip r:embed="' . self::$rid[$bgName] . '"/><a:srcRect/><a:stretch><a:fillRect/></a:stretch></a:blipFill><a:effectLst/></p:bgPr></p:bg>'
                : '<p:bg><p:bgPr><a:solidFill><a:srgbClr val="' . $theme['bg'] . '"/></a:solidFill><a:effectLst/></p:bgPr></p:bg>';
            $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
                . '<p:cSld>' . $bgXml . '<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>' . $shapes . '</p:spTree></p:cSld>'
                . '<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>' . self::transition($kind, $i === 0, $i === $total - 1) . self::timing(self::$anim) . '</p:sld>';
            $parts[] = ['xml' => $xml, 'media' => self::$media, 'rid' => self::$rid, 'notes' => trim((string)($s['notes'] ?? ''))];
            foreach (self::$media as $nm => $pth) {
                $mediaAll[$nm] = $pth;
            }
        }

        $path = (defined('GENERATED_DIR') ? GENERATED_DIR : sys_get_temp_dir() . '/') . 'pro_slayd_' . uniqid() . '.pptx';
        try {
            self::writePackage($path, $parts, $mediaAll, $theme, $docTitle);
        } finally {
            foreach ($tmp as $t) {
                @unlink($t);
            }
        }
        return $path;
    }

    private static function writePackage(string $path, array $parts, array $media, array $theme, string $docTitle): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('PPTX fayl yaratib bo\'lmadi: ' . $path);
        }
        $count = count($parts);
        $hasNotes = false;
        foreach ($parts as $p) {
            if ($p['notes'] !== '') {
                $hasNotes = true;
            }
        }
        $NS = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';
        $hdr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

        // [Content_Types]
        $ct = $hdr . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/><Default Extension="jpg" ContentType="image/jpeg"/><Default Extension="png" ContentType="image/png"/>'
            . '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
            . '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
            . '<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
            . '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        if ($hasNotes) {
            $ct .= '<Override PartName="/ppt/notesMasters/notesMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.notesMaster+xml"/>'
                . '<Override PartName="/ppt/theme/theme2.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>';
        }
        for ($i = 1; $i <= $count; $i++) {
            $ct .= '<Override PartName="/ppt/slides/slide' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
            if ($parts[$i - 1]['notes'] !== '') {
                $ct .= '<Override PartName="/ppt/notesSlides/notesSlide' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.notesSlide+xml"/>';
            }
        }
        $zip->addFromString('[Content_Types].xml', $ct . '</Types>');
        $zip->addFromString('_rels/.rels', $hdr . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $R . '/officeDocument" Target="ppt/presentation.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="' . $R . '/extended-properties" Target="docProps/app.xml"/></Relationships>');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $zip->addFromString('docProps/core.xml', $hdr . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . self::esc($docTitle) . '</dc:title><dc:creator>StudyBot</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified></cp:coreProperties>');
        $zip->addFromString('docProps/app.xml', $hdr . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Microsoft Office PowerPoint</Application><Slides>' . $count . '</Slides></Properties>');

        // presentation
        $sld = '';
        $prels = '<Relationship Id="rIdMaster" Type="' . $R . '/slideMaster" Target="slideMasters/slideMaster1.xml"/><Relationship Id="rIdTheme" Type="' . $R . '/theme" Target="theme/theme1.xml"/>';
        for ($i = 1; $i <= $count; $i++) {
            $sld .= '<p:sldId id="' . (255 + $i) . '" r:id="rIdSlide' . $i . '"/>';
            $prels .= '<Relationship Id="rIdSlide' . $i . '" Type="' . $R . '/slide" Target="slides/slide' . $i . '.xml"/>';
        }
        if ($hasNotes) {
            $prels .= '<Relationship Id="rIdNotesMaster" Type="' . $R . '/notesMaster" Target="notesMasters/notesMaster1.xml"/>';
        }
        $zip->addFromString('ppt/presentation.xml', $hdr . '<p:presentation ' . $NS . '><p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rIdMaster"/></p:sldMasterIdLst>'
            . ($hasNotes ? '<p:notesMasterIdLst><p:notesMasterId r:id="rIdNotesMaster"/></p:notesMasterIdLst>' : '')
            . '<p:sldIdLst>' . $sld . '</p:sldIdLst><p:sldSz cx="' . self::W . '" cy="' . self::H . '"/><p:notesSz cx="6858000" cy="9144000"/></p:presentation>');
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $hdr . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $prels . '</Relationships>');

        // master / layout / theme
        $dark = $theme['dark'];
        $clrMap = $dark
            ? '<p:clrMap bg1="dk1" tx1="lt1" bg2="dk2" tx2="lt2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
            : '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>';
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', $hdr . '<p:sldMaster ' . $NS . '><p:cSld><p:bg><p:bgPr><a:solidFill><a:srgbClr val="' . $theme['bg'] . '"/></a:solidFill><a:effectLst/></p:bgPr></p:bg>'
            . '<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>'
            . '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Title Placeholder 1"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="' . self::M . '" y="870000"/><a:ext cx="' . (self::W - 2 * self::M) . '" cy="1000000"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr>'
            . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="uz-UZ"/></a:p></p:txBody></p:sp></p:spTree></p:cSld>' . $clrMap
            . '<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>'
            . '<p:txStyles><p:titleStyle><a:lvl1pPr algn="l"><a:defRPr sz="3600" b="1"><a:solidFill><a:schemeClr val="tx1"/></a:solidFill><a:latin typeface="' . self::FONT . '"/></a:defRPr></a:lvl1pPr></p:titleStyle>'
            . '<p:bodyStyle><a:lvl1pPr marL="0" indent="0"><a:buNone/><a:defRPr sz="2000"><a:solidFill><a:schemeClr val="tx1"/></a:solidFill><a:latin typeface="' . self::FONT . '"/></a:defRPr></a:lvl1pPr></p:bodyStyle>'
            . '<p:otherStyle><a:lvl1pPr><a:defRPr sz="1800"><a:solidFill><a:schemeClr val="tx1"/></a:solidFill><a:latin typeface="' . self::FONT . '"/></a:defRPr></a:lvl1pPr></p:otherStyle></p:txStyles></p:sldMaster>');
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', $hdr . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $R . '/slideLayout" Target="../slideLayouts/slideLayout1.xml"/><Relationship Id="rId2" Type="' . $R . '/theme" Target="../theme/theme1.xml"/></Relationships>');
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', $hdr . '<p:sldLayout ' . $NS . ' type="titleOnly" preserve="1"><p:cSld name="PRO"><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>'
            . '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Title 1"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr><p:spPr/><p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="uz-UZ"/></a:p></p:txBody></p:sp>'
            . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>');
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $hdr . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $R . '/slideMaster" Target="../slideMasters/slideMaster1.xml"/></Relationships>');
        $themeXml = self::themeXml($theme);
        $zip->addFromString('ppt/theme/theme1.xml', $themeXml);

        if ($hasNotes) {
            $zip->addFromString('ppt/theme/theme2.xml', $themeXml);
            $zip->addFromString('ppt/notesMasters/notesMaster1.xml', $hdr . '<p:notesMaster ' . $NS . '><p:cSld><p:bg><p:bgRef idx="1001"><a:schemeClr val="bg1"/></p:bgRef></p:bg><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>'
                . '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Slide Image Placeholder 1"/><p:cNvSpPr><a:spLocks noGrp="1" noRot="1" noChangeAspect="1"/></p:cNvSpPr><p:nvPr><p:ph type="sldImg" idx="2"/></p:nvPr></p:nvSpPr>'
                . '<p:spPr><a:xfrm><a:off x="685800" y="1143000"/><a:ext cx="5486400" cy="3086100"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/><a:ln w="12700"><a:solidFill><a:prstClr val="black"/></a:solidFill></a:ln></p:spPr></p:sp>'
                . '<p:sp><p:nvSpPr><p:cNvPr id="3" name="Notes Placeholder 2"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="body" sz="quarter" idx="3"/></p:nvPr></p:nvSpPr>'
                . '<p:spPr><a:xfrm><a:off x="685800" y="4400550"/><a:ext cx="5486400" cy="3600450"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr>'
                . '<p:txBody><a:bodyPr vert="horz" lIns="91440" tIns="45720" rIns="91440" bIns="45720" rtlCol="0"/><a:lstStyle/><a:p><a:pPr lvl="0"/><a:r><a:rPr lang="uz-UZ"/><a:t>Notes</a:t></a:r></a:p></p:txBody></p:sp>'
                . '</p:spTree></p:cSld><p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
                . '<p:notesStyle><a:lvl1pPr marL="0" algn="l" defTabSz="914400" rtl="0" eaLnBrk="1" latinLnBrk="0" hangingPunct="1"><a:defRPr sz="1200" kern="1200"><a:solidFill><a:schemeClr val="tx1"/></a:solidFill><a:latin typeface="+mn-lt"/></a:defRPr></a:lvl1pPr></p:notesStyle></p:notesMaster>');
            $zip->addFromString('ppt/notesMasters/_rels/notesMaster1.xml.rels', $hdr . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="' . $R . '/theme" Target="../theme/theme2.xml"/></Relationships>');
        }

        // slaydlar
        $added = [];
        foreach ($parts as $i => $p) {
            $n = $i + 1;
            $rels = '<Relationship Id="rId1" Type="' . $R . '/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>';
            foreach ($p['rid'] as $name => $rid) {
                $rels .= '<Relationship Id="' . $rid . '" Type="' . $R . '/image" Target="../media/' . $name . '"/>';
                if (!isset($added[$name]) && isset($media[$name])) {
                    $zip->addFile($media[$name], 'ppt/media/' . $name);
                    $added[$name] = true;
                }
            }
            if ($p['notes'] !== '') {
                $rels .= '<Relationship Id="rIdNotes" Type="' . $R . '/notesSlide" Target="../notesSlides/notesSlide' . $n . '.xml"/>';
                $paras = '';
                foreach (preg_split('/\R+/u', $p['notes']) ?: [] as $line) {
                    if (trim($line) !== '') {
                        $paras .= '<a:p><a:r><a:rPr lang="' . (self::$lang === 'ru' ? 'ru-RU' : (self::$lang === 'en' ? 'en-US' : 'uz-UZ')) . '" dirty="0"/><a:t>' . self::esc(trim($line)) . '</a:t></a:r></a:p>';
                    }
                }
                $zip->addFromString('ppt/notesSlides/notesSlide' . $n . '.xml', $hdr . '<p:notes ' . $NS . '><p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>'
                    . '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Slide Image Placeholder 1"/><p:cNvSpPr><a:spLocks noGrp="1" noRot="1" noChangeAspect="1"/></p:cNvSpPr><p:nvPr><p:ph type="sldImg"/></p:nvPr></p:nvSpPr><p:spPr/></p:sp>'
                    . '<p:sp><p:nvSpPr><p:cNvPr id="3" name="Notes Placeholder 2"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr><p:spPr/>'
                    . '<p:txBody><a:bodyPr/><a:lstStyle/>' . $paras . '</p:txBody></p:sp></p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:notes>');
                $zip->addFromString('ppt/notesSlides/_rels/notesSlide' . $n . '.xml.rels', $hdr . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    . '<Relationship Id="rId1" Type="' . $R . '/notesMaster" Target="../notesMasters/notesMaster1.xml"/><Relationship Id="rId2" Type="' . $R . '/slide" Target="../slides/slide' . $n . '.xml"/></Relationships>');
            }
            $zip->addFromString('ppt/slides/slide' . $n . '.xml', $p['xml']);
            $zip->addFromString('ppt/slides/_rels/slide' . $n . '.xml.rels', $hdr . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
        }
        if (!$zip->close() || !is_file($path)) {
            throw new RuntimeException('PPTX faylni diskka yozib bo\'lmadi: ' . $path);
        }
    }

    private static function themeXml(array $t): string
    {
        $f = self::FONT;
        $dk1 = $t['dark'] ? $t['bg'] : $t['txt'];
        $lt1 = $t['dark'] ? $t['txt'] : $t['bg'];
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="StudyBot PRO"><a:themeElements><a:clrScheme name="StudyBot PRO">'
            . '<a:dk1><a:srgbClr val="' . $dk1 . '"/></a:dk1><a:lt1><a:srgbClr val="' . $lt1 . '"/></a:lt1><a:dk2><a:srgbClr val="' . $t['bg2'] . '"/></a:dk2><a:lt2><a:srgbClr val="' . $t['mut'] . '"/></a:lt2>'
            . '<a:accent1><a:srgbClr val="' . $t['acc'] . '"/></a:accent1><a:accent2><a:srgbClr val="' . $t['acc2'] . '"/></a:accent2><a:accent3><a:srgbClr val="' . $t['sub'] . '"/></a:accent3>'
            . '<a:accent4><a:srgbClr val="FBBF24"/></a:accent4><a:accent5><a:srgbClr val="34D399"/></a:accent5><a:accent6><a:srgbClr val="F472B6"/></a:accent6>'
            . '<a:hlink><a:srgbClr val="' . $t['acc'] . '"/></a:hlink><a:folHlink><a:srgbClr val="' . $t['acc2'] . '"/></a:folHlink></a:clrScheme>'
            . '<a:fontScheme name="StudyBot PRO"><a:majorFont><a:latin typeface="' . $f . '"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont><a:minorFont><a:latin typeface="' . $f . '"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont></a:fontScheme>'
            . '<a:fmtScheme name="StudyBot PRO"><a:fillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:fillStyleLst>'
            . '<a:lnStyleLst><a:ln w="6350"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln><a:ln w="12700"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln><a:ln w="19050"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln></a:lnStyleLst>'
            . '<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>'
            . '<a:bgFillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:bgFillStyleLst></a:fmtScheme></a:themeElements></a:theme>';
    }
}
