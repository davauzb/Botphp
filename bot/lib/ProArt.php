<?php

/**
 * PRO taqdimot uchun rasm ishlovi (GD): rasmni 16:9 ga qirqish, matn o'qilishi uchun qoraytirish (gradient),
 * yon panel uchun qirqish + chetini fon rangiga singdirish va rasm topilmaganda — mavzuga mos mavhum (abstract) fon chizish.
 *
 * Hammasi serverda, tashqi xizmatsiz: rasm topilmasa ham taqdimot "bo'sh" ko'rinmaydi.
 * Natija har doim JPEG (1920x1080 gacha, ~100-300 KB) — PPTX hajmi kichik bo'lib qoladi.
 */
class ProArt
{
    public const FW = 1920;
    public const FH = 1080;
    private const MAX_PIXELS = 36000000;     // 36 megapiksel (≈ 144 MB RGBA) dan kattasini ochmaymiz — hostingda xotira tugab qolmasin

    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /** opacity 0..1 (1 = to'liq qoplaydi) -> GD alfa (0 = qoplaydi, 127 = shaffof) */
    private static function a(float $opacity): int
    {
        return (int)round(127 * (1 - max(0.0, min(1.0, $opacity))));
    }

    private static function out(string $prefix = 'pro_'): string
    {
        $dir = defined('GENERATED_DIR') ? GENERATED_DIR : sys_get_temp_dir() . '/';
        return $dir . $prefix . bin2hex(random_bytes(6)) . '.jpg';
    }

    public static function available(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagejpeg');
    }

    /** Fayldan GD rasmini ochadi (jpeg/png/gif). Katta yoki buzuq bo'lsa null. */
    public static function load(string $path): ?GdImage
    {
        if (!is_file($path)) {
            return null;
        }
        $info = @getimagesize($path);
        if (!$info || $info[0] < 200 || $info[1] < 120 || $info[0] * $info[1] > self::MAX_PIXELS) {
            return null;
        }
        $im = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            default => false,
        };
        return $im ?: null;
    }

    /** $src ni $w x $h ga "cover" usulida (markazdan) sig'diradi. */
    private static function cover(GdImage $src, int $w, int $h): GdImage
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $dst = imagecreatetruecolor($w, $h);
        $scale = max($w / $sw, $h / $sh);
        $cw = (int)round($w / $scale);
        $ch = (int)round($h / $scale);
        $sx = (int)round(($sw - $cw) / 2);
        $sy = (int)round(($sh - $ch) / 2.4);      // biroz yuqoriroqdan: odam/ob'ekt boshi kesilib qolmasin
        imagecopyresampled($dst, $src, 0, 0, max(0, $sx), max(0, $sy), $w, $h, $cw, $ch);
        return $dst;
    }

    private static function save(GdImage $im, string $path, int $q = 84): ?string
    {
        imageinterlace($im, true);
        $ok = @imagejpeg($im, $path, $q);
        imagedestroy($im);
        return $ok && is_file($path) ? $path : null;
    }

    // ------------------------------------------------------------------ rasm ustiga gradient

    /**
     * Matn o'qilishi uchun qoraytirish.
     *  mode 'left'  — chapdan o'ngga: chap tomon qalin qoplanadi, o'ng tomon rasm ochiq ko'rinadi (muqova)
     *  mode 'dim'   — butun rasm bir tekis qoraytiriladi + pastki va yuqori qismi biroz qoraroq (matnli slaydlar, "Rahmat")
     */
    private static function shade(GdImage $im, string $baseHex, string $mode): void
    {
        $w = imagesx($im);
        $h = imagesy($im);
        [$r, $g, $b] = self::rgb($baseHex);
        imagealphablending($im, true);
        if ($mode === 'left') {
            for ($x = 0; $x < $w; $x++) {
                $t = $x / $w;                                  // 0 chap .. 1 o'ng
                $op = $t < 0.5 ? 0.95 - ($t / 0.5) * 0.17 : max(0.0, 0.78 * (1 - ($t - 0.5) / 0.5) ** 1.15);       // matn tomoni deyarli to'liq qoraytiriladi
                $col = imagecolorallocatealpha($im, $r, $g, $b, self::a($op));
                imageline($im, $x, 0, $x, $h, $col);
            }
            for ($y = (int)($h * 0.7); $y < $h; $y++) {         // pastki qism: raqam va progress yaxshi ko'rinsin
                $op = 0.55 * (($y - $h * 0.7) / ($h * 0.3));
                imageline($im, 0, $y, $w, $y, imagecolorallocatealpha($im, $r, $g, $b, self::a($op)));
            }
            return;
        }
        // 'soft' — matnli slaydlar foni: rasm xiralashgan, kuchli qoraytirilgan (rang va kayfiyat qoladi, matn bilan urishmaydi)
        // 'dim'  — «Rahmat»: tiniq rasm, markazdagi yozuv o'qilishi uchun bir tekis qalin qoplama
        $base = $mode === 'soft' ? 0.70 : 0.66;
        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            $edge = $t < 0.18 ? (0.18 - $t) / 0.18 : ($t > 0.72 ? ($t - 0.72) / 0.28 : 0.0);
            $op = min(0.94, $base + 0.20 * $edge);
            imageline($im, 0, $y, $w, $y, imagecolorallocatealpha($im, $r, $g, $b, self::a($op)));
        }
    }

    /** Yumshoq xiralashtirish: kichraytirib-kattalashtirish (tez, GD'da ishonchli) + bir marta Gauss. */
    private static function blur(GdImage $im, int $factor = 14): GdImage
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $sw = max(8, intdiv($w, $factor));
        $sh = max(8, intdiv($h, $factor));
        $small = imagecreatetruecolor($sw, $sh);
        imagecopyresampled($small, $im, 0, 0, 0, 0, $sw, $sh, $w, $h);
        imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        $out = imagecreatetruecolor($w, $h);
        imagecopyresampled($out, $small, 0, 0, 0, 0, $w, $h, $sw, $sh);
        imagedestroy($small);
        imagefilter($out, IMG_FILTER_GAUSSIAN_BLUR);
        imagedestroy($im);
        return $out;
    }

    /** To'liq ekranli fon rasmi (1920x1080). Qaytadi: JPEG yo'li yoki null. */
    public static function photoFull(string $src, array $theme, string $mode): ?string
    {
        if (!self::available() || !($im0 = self::load($src))) {
            return null;
        }
        $im = self::cover($im0, self::FW, self::FH);
        imagedestroy($im0);
        if ($mode === 'soft') {
            $im = self::blur($im);
        }
        self::shade($im, $theme['bg'], $mode);
        return self::save($im, self::out('pf_'));
    }

    /**
     * Yon panel uchun rasm: $w x $h nisbatida qirqiladi, $fade tomoni ('right'|'left'|'') fon rangiga singadi.
     * Qaytadi: [yo'l] yoki null.
     */
    public static function photoPanel(string $src, array $theme, int $w, int $h, string $fade, ?string $bgPath = null, float $x0 = 0.0, float $x1 = 1.0): ?string
    {
        if (!self::available() || !($im0 = self::load($src))) {
            return null;
        }
        $pw = 1100;
        $ph = (int)round($pw * $h / $w);
        $im = self::cover($im0, $pw, $ph);
        imagedestroy($im0);
        [$r, $g, $b] = self::rgb($theme['bg']);
        imagealphablending($im, true);
        // umumiy yengil qoraytirish (rasm matn bilan "urishmasin")
        imagefilledrectangle($im, 0, 0, $pw, $ph, imagecolorallocatealpha($im, $r, $g, $b, self::a($theme['dark'] ? 0.18 : 0.26)));
        if ($fade !== '') {
            // Singish nishoni — slaydning HAQIQIY fon rasmi (gradient + yorug' dog'lar) shu joyidan qirqib olinadi,
            // shunda rasm fon bilan chokisiz qo'shiladi (tekis rang bilan singdirilsa, vertikal "chok" ko'rinib qolardi).
            $target = null;
            if ($bgPath && ($bgIm = self::load($bgPath))) {
                $bw = imagesx($bgIm);
                $bh = imagesy($bgIm);
                $target = imagecreatetruecolor($pw, $ph);
                imagecopyresampled($target, $bgIm, 0, 0, (int)round($bw * $x0), 0, $pw, $ph, max(1, (int)round($bw * ($x1 - $x0))), $bh);
                imagedestroy($bgIm);
            }
            $span = (int)($pw * ($theme['dark'] ? 0.42 : 0.56));
            for ($i = 0; $i < $span; $i++) {
                $t = 1 - $i / $span;                                   // chetda 1, ichkarida 0
                $op = min(1.0, $t ** ($theme['dark'] ? 1.6 : 1.25));
                $x = $fade === 'right' ? $pw - 1 - $i : $i;
                if ($target) {
                    imagecopymerge($im, $target, $x, 0, $x, 0, 1, $ph, (int)round($op * 100));
                } else {
                    imageline($im, $x, 0, $x, $ph, imagecolorallocatealpha($im, $r, $g, $b, self::a($op)));
                }
            }
            if ($target) {
                imagedestroy($target);
            }
        }
        return self::save($im, self::out('pp_'), 86);
    }

    // ------------------------------------------------------------------ mavhum fon (rasm topilmasa / sodda slaydlar)

    /**
     * Mavzu rangida zamonaviy mavhum fon: gradient + yorug' dog'lar + nozik kontur chiziqlar (topografik).
     * $variant 0..2 — dog'lar joylashuvi har xil, shunda slaydlar bir-biriga o'xshab ketmaydi.
     */
    public static function abstractBg(array $theme, int $variant = 0): ?string
    {
        if (!self::available()) {
            return null;
        }
        // Gradient va yumshoq yorug' dog'lar KICHIK rasmda piksel-piksel hisoblanadi (tez), keyin silliq kattalashtiriladi — "polosa"lar (banding) bo'lmaydi
        $sw = 384;
        $sh = 216;
        $small = imagecreatetruecolor($sw, $sh);
        $bg1 = self::rgb($theme['bg']);
        $bg2 = self::rgb($theme['bg2']);
        $v = $variant % 3;
        $spots = [
            0 => [[0.78, 0.16, 0.52, 0.55, 'acc', 1.0], [0.12, 0.92, 0.42, 0.42, 'acc2', 0.75]],
            1 => [[0.16, 0.2, 0.5, 0.5, 'acc', 0.95], [0.86, 0.85, 0.45, 0.45, 'acc2', 0.8]],
            2 => [[0.5, 0.5, 0.6, 0.6, 'acc', 0.55], [0.92, 0.1, 0.35, 0.4, 'acc2', 0.8]],
        ][$v];
        $spotRgb = [];
        foreach ($spots as $i => $sp) {
            $spotRgb[$i] = self::rgb($theme[$sp[4]]);
        }
        $gain = $theme['dark'] ? 0.5 : 0.34;
        for ($y = 0; $y < $sh; $y++) {
            $ty = $y / ($sh - 1);
            for ($x = 0; $x < $sw; $x++) {
                $c = [
                    $bg1[0] + ($bg2[0] - $bg1[0]) * $ty,
                    $bg1[1] + ($bg2[1] - $bg1[1]) * $ty,
                    $bg1[2] + ($bg2[2] - $bg1[2]) * $ty,
                ];
                foreach ($spots as $i => [$cx, $cy, $rx, $ry, , $str]) {
                    $dx = ($x / $sw - $cx) / $rx;
                    $dy = ($y / $sh - $cy) / $ry;
                    $k = $gain * $str * exp(-($dx * $dx + $dy * $dy) * 2.6);
                    if ($k > 0.004) {
                        $c[0] += ($spotRgb[$i][0] - $c[0]) * $k;
                        $c[1] += ($spotRgb[$i][1] - $c[1]) * $k;
                        $c[2] += ($spotRgb[$i][2] - $c[2]) * $k;
                    }
                }
                imagesetpixel($small, $x, $y, ((int)$c[0] << 16) | ((int)$c[1] << 8) | (int)$c[2]);
            }
        }
        $im = imagecreatetruecolor(self::FW, self::FH);
        imagecopyresampled($im, $small, 0, 0, 0, 0, self::FW, self::FH, $sw, $sh);
        imagedestroy($small);
        imagealphablending($im, true);
        $w = self::FW;
        $h = self::FH;
        $k = 1.2;                                                   // 1600x900 asosidagi koordinatalar -> 1920x1080
        // topografik konturlar
        [$ar, $ag, $ab] = self::rgb($theme['acc']);
        $lineCol = imagecolorallocatealpha($im, $ar, $ag, $ab, self::a($theme['dark'] ? 0.13 : 0.18));
        imagesetthickness($im, 2);
        [$ox, $oy] = [[1180, 380], [420, 560], [900, 300]][$v];
        for ($i = 1; $i <= 7; $i++) {
            imageellipse($im, (int)($ox * $k), (int)($oy * $k), (int)((160 + $i * 150) * $k), (int)((96 + $i * 88) * $k), $lineCol);
        }
        [$ox2, $oy2] = [[1300, 700], [1250, 240], [250, 700]][$v];
        for ($i = 1; $i <= 4; $i++) {
            imageellipse($im, (int)($ox2 * $k), (int)($oy2 * $k), (int)((200 + $i * 170) * $k), (int)((110 + $i * 80) * $k), $lineCol);
        }
        return self::save($im, self::out('ab_'), 88);
    }
}
