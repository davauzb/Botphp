<?php

/**
 * 🗣 O'zbekcha matnni OVOZ UCHUN tayyorlash (matnni o'qishdan oldin): raqam, sana, vaqt, foiz, pul, qisqartmalar
 * so'z bilan yoziladi; sarlavha/ro'yxat qatorlari gapga aylantiriladi (to'g'ri pauza bo'lishi uchun).
 *   «24-noyabr»  → «yigirma to'rtinchi noyabr»     «2026-yil» → «ikki ming yigirma oltinchi yil»
 *   «17:30»      → «o'n yetti o'ttiz»           «25%»      → «yigirma besh foiz»
 *   «3,5»        → «uch butun o'ndan besh»          «12 000 so'm» → «o'n ikki ming so'm»
 *   «5-7 bet»    → «besh-yetti bet»                 «h.k.»     → «hokazo»
 */
class UzSpeak
{
    private const ONES = ['', 'bir', 'ikki', 'uch', "to'rt", 'besh', 'olti', 'yetti', 'sakkiz', "to'qqiz"];
    private const TENS = ['', "o'n", 'yigirma', "o'ttiz", 'qirq', 'ellik', 'oltmish', 'yetmish', 'sakson', "to'qson"];
    private const BIG = [1000000000000 => 'trillion', 1000000000 => 'milliard', 1000000 => 'million', 1000 => 'ming'];

    /** Butun son → so'z (0 … 999 trillion). */
    public static function num(int $n): string
    {
        if ($n === 0) {
            return 'nol';
        }
        if ($n < 0) {
            return 'minus ' . self::num(-$n);
        }
        $parts = [];
        foreach (self::BIG as $v => $w) {
            if ($n >= $v) {
                $q = intdiv($n, $v);
                $parts[] = ($q === 1 && $v === 1000 ? '' : self::under1000($q) . ' ') . $w;   // 1000 — «ming»
                $n %= $v;
            }
        }
        if ($n > 0) {
            $parts[] = self::under1000($n);
        }
        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');
    }

    private static function under1000(int $n): string
    {
        $out = [];
        if ($n >= 100) {
            $h = intdiv($n, 100);
            $out[] = ($h === 1 ? '' : self::ONES[$h] . ' ') . 'yuz';
            $n %= 100;
        }
        if ($n >= 10) {
            $out[] = self::TENS[intdiv($n, 10)];
            $n %= 10;
        }
        if ($n > 0) {
            $out[] = self::ONES[$n];
        }
        return trim(implode(' ', $out));
    }

    /** Tartib son: «birinchi», «yigirma to'rtinchi», «ikki ming yigirma oltinchi». */
    public static function ordinal(int $n): string
    {
        $w = self::num($n);
        return preg_match("/[aeiouAEIOU]$/u", $w) ? $w . 'nchi' : $w . 'inchi';
    }

    /** Raqam guruhlari («12 000», «1.250.000») → oddiy butun son satri. */
    private static function digits(string $s): string
    {
        return preg_replace('/[\s.\x{00A0}\x{202F}]/u', '', $s) ?? $s;
    }

    public static function prepare(string $t): string
    {
        // 1) Qisqartmalar va belgilar
        $abbr = [
            '/\bva h\.\s?k\.?/u' => 'va hokazo', '/\bh\.\s?k\.?/u' => 'hokazo', '/\bmlrd\.?(?=\s|$)/u' => 'milliard', '/\bmln\.?(?=\s|$)/u' => 'million',
            '/\bming\.(?=\s)/u' => 'ming', '/\bkm\b/u' => 'kilometr', '/\bkg\b/u' => 'kilogramm', '/\bsm\b/u' => 'santimetr', '/\bmm\b/u' => 'millimetr',
            '/\bt\.y\.?/u' => 'tug\'ilgan yili', '/\bmasalan:/u' => 'masalan,', '/\bva b\.\s?/u' => 'va boshqalar ', '/\bb\.\s?q\./u' => 'boshqalar',
            '/№\s?/u' => 'raqam ', '/§\s?/u' => 'paragraf ', '/\s&\s/u' => ' va ', '/\+(?=\s?\d)/u' => 'plyus ',
        ];
        foreach ($abbr as $re => $to) {
            $t = preg_replace($re, $to, $t) ?? $t;
        }
        $t = preg_replace('/(\d)\s?\$/u', '$1 dollar', $t) ?? $t;
        $t = preg_replace('/\$\s?(\d[\d\s.,]*)/u', '$1 dollar', $t) ?? $t;
        $t = preg_replace('/(\d)\s?€/u', '$1 yevro', $t) ?? $t;

        // 1b) Telefon raqami: guruhlab o'qiladi (+998 90 123 45 67 → plyus to'qqiz yuz to'qson sakkiz, to'qson, bir yuz…)
        $t = preg_replace_callback('/(\+?998[\s-]?)?\(?(\d{2})\)?[\s-]?(\d{3})[\s-]?(\d{2})[\s-]?(\d{2})\b/u', function ($m) {
            $g = array_values(array_filter([trim($m[1], " -+") !== '' ? trim($m[1], " -+") : null, $m[2], $m[3], $m[4], $m[5]], fn($x) => $x !== null));
            return (str_starts_with(trim($m[0]), '+') ? 'plyus ' : '') . implode(', ', array_map(fn($x) => $x[0] === '0' && strlen($x) > 1
                ? implode(' ', array_map(fn($c) => self::num((int)$c), str_split($x))) : self::num((int)$x), $g));
        }, $t) ?? $t;
        $t = preg_replace('/plyus plyus /u', 'plyus ', $t) ?? $t;

        // 2) Vaqt: 17:30 → o'n yetti-yu o'ttiz, 09:05 → to'qqiz nol besh
        $t = preg_replace_callback('/\b([01]?\d|2[0-3]):([0-5]\d)\b/u', function ($m) {
            $h = self::num((int)$m[1]);
            $mm = (int)$m[2];
            if ($mm === 0) {
                return $h;
            }
            return $h . ($m[2][0] === '0' ? ' nol ' . self::num($mm) : ' ' . self::num($mm));
        }, $t) ?? $t;

        // 3) Sana: 24.11.2026 → yigirma to'rtinchi noyabr, ikki ming yigirma oltinchi yil
        $months = [1 => 'yanvar', 'fevral', 'mart', 'aprel', 'may', 'iyun', 'iyul', 'avgust', 'sentabr', 'oktabr', 'noyabr', 'dekabr'];
        $t = preg_replace_callback('/\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b/u', function ($m) use ($months) {
            $mo = (int)$m[2];
            return isset($months[$mo]) ? self::num((int)$m[3]) . ' yil ' . self::ordinal((int)$m[1]) . ' ' . $months[$mo] : $m[0];
        }, $t) ?? $t;

        // 4) Foiz va o'nli kasr: 25% → yigirma besh foiz, 3,5 → uch butun o'ndan besh
        $dec = function (string $int, string $frac): string {
            $f = rtrim($frac, '0');
            if ($f === '') {
                return self::num((int)self::digits($int));
            }
            $den = [1 => "o'ndan", 2 => 'yuzdan', 3 => 'mingdan'][strlen($f)] ?? null;
            return $den ? self::num((int)self::digits($int)) . ' butun ' . $den . ' ' . self::num((int)$f) : self::num((int)self::digits($int)) . ' butun ' . implode(' ', array_map(fn($c) => self::num((int)$c), str_split($f)));
        };
        $sfx = "(?:\\s?-?(i|ni|ga|da|dan|gacha|lik|ini|iga|ida|idan)(?!\\p{L}))?";               // «78% i», «78%ga» → «foizi», «foizga»
        $t = preg_replace_callback('/(\d+)[,.](\d{1,3})\s?%' . $sfx . '/u', fn($m) => $dec($m[1], $m[2]) . ' foiz' . ($m[3] ?? ''), $t) ?? $t;
        $t = preg_replace_callback('/(\d[\d\s]*?)\s?%' . $sfx . '/u', fn($m) => self::num((int)self::digits($m[1])) . ' foiz' . ($m[2] ?? ''), $t) ?? $t;

        // 5) Oraliq: 5-7 → besh-yetti, 2020–2025 → ikki ming yigirma–ikki ming yigirma besh
        $t = preg_replace_callback('/\b(\d{1,4})\s?[-–]\s?(\d{1,4})\b(?![-–]?\p{L})/u', fn($m) => self::num((int)$m[1]) . '-' . self::num((int)$m[2]), $t) ?? $t;

        // 6) Tartib son: 24-noyabr, 2026-yil, 1-bob, 3-sinf (raqam + chiziqcha + so'z)
        $t = preg_replace_callback('/\b(\d{1,7})\s?[-–](?=\p{L})(inchi|nchi|chi)?/u', fn($m) => self::ordinal((int)$m[1]) . ' ', $t) ?? $t;
        $t = preg_replace('/(inchi|nchi) (?=\p{L})/u', '$1 ', $t) ?? $t;

        // 7) O'nli kasr va katta sonlar (bo'sh joy/nuqta bilan guruhlangan: 12 000, 1.250.000)
        $t = preg_replace_callback('/\b(\d{1,3}(?:[ .\x{00A0}]\d{3})+)(?:,(\d{1,3}))?\b/u', fn($m) => isset($m[2]) && $m[2] !== '' ? $dec($m[1], $m[2]) : self::num((int)self::digits($m[1])), $t) ?? $t;
        $t = preg_replace_callback('/\b(\d+),(\d{1,3})\b/u', fn($m) => $dec($m[1], $m[2]), $t) ?? $t;
        // versiya / ko'p nuqtali raqam «7.3.1» → «yetti nuqta uch nuqta bir»
        $t = preg_replace_callback('/\b\d{1,3}(?:\.\d{1,3}){2,}\b/u', fn($m) => implode(' nuqta ', array_map(fn($x) => self::num((int)$x), explode('.', $m[0]))), $t) ?? $t;
        // band raqami «1.2.» → «bir nuqta ikki», o'nli kasr nuqta bilan «0.5» → «nol butun o'ndan besh»
        $t = preg_replace_callback('/\b(\d{1,2})\.(\d{1,2})\.(?=\s|$)/u', fn($m) => self::num((int)$m[1]) . ' nuqta ' . self::num((int)$m[2]) . '.', $t) ?? $t;
        $t = preg_replace_callback('/\b(\d+)\.(\d{1,2})\b(?![.\d])/u', fn($m) => $dec($m[1], $m[2]), $t) ?? $t;
        $t = preg_replace_callback('/\b\d{1,15}\b/u', fn($m) => strlen($m[0]) > 1 && $m[0][0] === '0' ? implode(' ', array_map(fn($c) => self::num((int)$c), str_split($m[0]))) : self::num((int)$m[0]), $t) ?? $t;

        // 8) Tuzilma → pauza: sarlavha, ro'yxat qatorlari, tire
        $lines = [];
        foreach (preg_split('/\R/u', $t) ?: [] as $l) {
            $l = trim(preg_replace('/^\s*([•▪◦●\-–*]|\d+[.)])\s+/u', '', $l) ?? $l);
            if ($l === '') {
                $lines[] = '';
                continue;
            }
            if (!preg_match('/[.!?…:;,]$/u', $l)) {
                $l .= '.';                                      // sarlavha/ro'yxat oxirida pauza bo'lsin
            }
            $lines[] = $l;
        }
        $t = implode("\n", $lines);
        $t = preg_replace('/\s+[—–]\s+/u', ', ', $t) ?? $t;      // tire — vergul kabi qisqa pauza
        $t = preg_replace('/\(([^()]{1,80})\)/u', ', $1,', $t) ?? $t; // qavs ichi — pauza bilan
        $t = preg_replace('/\s+([,.!?;:])/u', '$1', $t) ?? $t;
        $t = preg_replace('/,\s*([.!?])/u', '$1', $t) ?? $t;
        $t = preg_replace('/[ \t]{2,}/u', ' ', $t) ?? $t;
        $t = preg_replace('/([.!?,])\1+/u', '$1', $t) ?? $t;
        return $t;
    }
}
