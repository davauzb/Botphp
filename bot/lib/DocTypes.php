<?php

/**
 * Buyurtma turlari ro'yxati (yagona joy). Yangi tur shu yerga qo'shiladi.
 *
 *  kind: 'paper'   — titul varag'i + reja + boblar (mustaqil ish, referat, kurs ishi)
 *        'article' — maqola/tezis: titulsiz, sarlavha + muallif + (annotatsiya) + matn + adabiyotlar
 *        'slides'  — taqdimot
 *  const: config.php dagi hajm/narx jadvali nomi. Config'da bo'lmasa — shu yerdagi 'volumes' ishlatiladi
 *         (eski config.php bilan ham yangi turlar ishlaydi).
 *  Yangi turni o'chirish: config.php → define('KURS_ISHI_ENABLED', false); (MAQOLA_ENABLED, TEZIS_ENABLED)
 */
final class DocTypes
{
    public const T = [
        'mustaqil_ish' => ['label' => 'mustaqil ish', 'title' => 'Mustaqil ish', 'menu' => '📄 Mustaqil ish', 'cmd' => '/mustaqil_ish', 'doc' => 'MUSTAQIL ISH', 'const' => 'MUSTAQIL_ISH_VOLUMES', 'kind' => 'paper', 'images' => 2],
        'slayd' => ['label' => 'taqdimot', 'title' => 'Taqdimot', 'menu' => '📊 Slayd (taqdimot)', 'cmd' => '/slayd', 'doc' => '', 'const' => 'SLAYD_VOLUMES', 'kind' => 'slides'],
        'referat' => ['label' => 'referat', 'title' => 'Referat', 'menu' => '📝 Referat', 'cmd' => '/referat', 'doc' => 'REFERAT', 'const' => 'REFERAT_VOLUMES', 'kind' => 'paper', 'images' => 1],
        'kurs_ishi' => ['label' => 'kurs ishi', 'title' => 'Kurs ishi', 'menu' => '🎓 Kurs ishi', 'cmd' => '/kurs_ishi', 'doc' => 'KURS ISHI', 'const' => 'KURS_ISHI_VOLUMES', 'kind' => 'paper', 'images' => 3, 'flag' => 'KURS_ISHI_ENABLED',
            'volumes' => [
                '25-30' => ['label' => '25-30 bet', 'chapters' => 3, 'sub' => 3, 'min_words' => 6500, 'max_words' => 7500, 'price' => 18000],
                '30-40' => ['label' => '30-40 bet', 'chapters' => 3, 'sub' => 3, 'min_words' => 8500, 'max_words' => 10000, 'price' => 24000],
            ]],
        'maqola' => ['label' => 'ilmiy maqola', 'title' => 'Ilmiy maqola', 'menu' => '📰 Ilmiy maqola', 'cmd' => '/maqola', 'doc' => 'MAQOLA', 'const' => 'MAQOLA_VOLUMES', 'kind' => 'article', 'images' => 0, 'flag' => 'MAQOLA_ENABLED',
            'volumes' => [
                '5-7' => ['label' => '5-7 bet', 'chapters' => 2, 'min_words' => 1700, 'max_words' => 2200, 'price' => 7000],
                '8-10' => ['label' => '8-10 bet', 'chapters' => 2, 'min_words' => 2500, 'max_words' => 3200, 'price' => 9000],
            ]],
        'tezis' => ['label' => 'tezis', 'title' => 'Tezis', 'menu' => '🧾 Tezis', 'cmd' => '/tezis', 'doc' => 'TEZIS', 'const' => 'TEZIS_VOLUMES', 'kind' => 'article', 'images' => 0, 'flag' => 'TEZIS_ENABLED',
            'volumes' => [
                '2-3' => ['label' => '2-3 bet', 'chapters' => 0, 'min_words' => 600, 'max_words' => 900, 'price' => 3000],
            ]],
    ];

    public static function exists(string $t): bool
    {
        return isset(self::T[$t]) && self::enabled($t);
    }

    public static function enabled(string $t): bool
    {
        $f = self::T[$t]['flag'] ?? null;
        return $f === null || !defined($f) || (bool)constant($f);
    }

    /** Yoqilgan turlar (menyu tartibida). */
    public static function all(): array
    {
        return array_keys(array_filter(self::T, fn($v, $k) => self::enabled($k), ARRAY_FILTER_USE_BOTH));
    }

    public static function volumes(string $t): ?array
    {
        if (!isset(self::T[$t])) {
            return null;
        }
        $c = self::T[$t]['const'];
        return defined($c) ? constant($c) : (self::T[$t]['volumes'] ?? null);
    }

    public static function label(string $t): string
    {
        return self::T[$t]['label'] ?? $t;
    }

    public static function title(string $t): string
    {
        return self::T[$t]['title'] ?? $t;
    }

    public static function docType(string $t): string
    {
        return self::T[$t]['doc'] ?? 'MUSTAQIL ISH';
    }

    public static function kind(string $t): string
    {
        return self::T[$t]['kind'] ?? 'paper';
    }

    public static function isDoc(string $t): bool
    {
        return isset(self::T[$t]) && self::T[$t]['kind'] !== 'slides';
    }

    public static function images(string $t): int
    {
        return (int)(self::T[$t]['images'] ?? 0);
    }

    /** Menyu matni yoki /buyruq → tur kaliti. */
    public static function fromText(string $text): ?string
    {
        foreach (self::all() as $k) {
            if ($text === self::T[$k]['menu'] || $text === self::T[$k]['cmd']) {
                return $k;
            }
        }
        return null;
    }

    /** DOCX/DocStore uchun barcha hujjat turlari ('MUSTAQIL ISH', 'REFERAT', ...). */
    public static function docTypes(): array
    {
        return array_values(array_filter(array_map(fn($v) => $v['doc'], self::T)));
    }

    public static function isArticleDoc(string $docType): bool
    {
        return in_array($docType, ['MAQOLA', 'TEZIS'], true);
    }
}
