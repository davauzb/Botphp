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
        'esse' => ['label' => 'esse', 'title' => 'Esse (insho)', 'menu' => '✍️ Esse / insho', 'cmd' => '/esse', 'doc' => 'ESSE', 'const' => 'ESSE_VOLUMES', 'kind' => 'article', 'single' => 'esse', 'images' => 0, 'flag' => 'ESSE_ENABLED', 'biblio' => 4,
            'volumes' => [
                '2-3' => ['label' => '2-3 bet', 'chapters' => 0, 'min_words' => 700, 'max_words' => 950, 'price' => 3000],
                '4-5' => ['label' => '4-5 bet', 'chapters' => 0, 'min_words' => 1200, 'max_words' => 1500, 'price' => 5000],
            ]],
        'test' => ['label' => 'test savollari', 'title' => 'Test savollari', 'menu' => '❓ Test savollari', 'cmd' => '/test', 'doc' => 'TEST', 'const' => 'TEST_VOLUMES', 'kind' => 'test', 'images' => 0, 'flag' => 'TEST_ENABLED',
            'volumes' => [
                '20' => ['label' => '20 ta savol', 'questions' => 20, 'price' => 3000],
                '30' => ['label' => '30 ta savol', 'questions' => 30, 'price' => 4000],
                '50' => ['label' => '50 ta savol', 'questions' => 50, 'price' => 6000],
            ]],
        'lab' => ['label' => 'laboratoriya ishi', 'title' => 'Laboratoriya ishi', 'menu' => '🔬 Laboratoriya ishi', 'cmd' => '/laboratoriya', 'doc' => 'LABORATORIYA ISHI', 'const' => 'LAB_VOLUMES', 'kind' => 'paper', 'images' => 1, 'flag' => 'LAB_ENABLED', 'biblio' => 5, 'table_ch' => 3,
            'fixed' => [
                'uz' => ['Ishning maqsadi va nazariy asoslari', 'Kerakli asbob-uskunalar va ishni bajarish tartibi', 'Olingan natijalar va ularning tahlili'],
                'ru' => ['Цель работы и теоретические основы', 'Необходимое оборудование и порядок выполнения работы', 'Полученные результаты и их анализ'],
                'en' => ['Objective and theoretical background', 'Equipment and procedure', 'Results and analysis'],
            ],
            'volumes' => [
                '5-7' => ['label' => '5-7 bet', 'chapters' => 3, 'sub' => 0, 'min_words' => 1500, 'max_words' => 2000, 'price' => 5000],
                '8-10' => ['label' => '8-10 bet', 'chapters' => 3, 'sub' => 0, 'min_words' => 2400, 'max_words' => 3000, 'price' => 7000],
            ]],
        'amaliyot' => ['label' => 'amaliyot hisoboti', 'title' => 'Amaliyot hisoboti', 'menu' => '🏢 Amaliyot hisoboti', 'cmd' => '/amaliyot', 'doc' => 'AMALIYOT HISOBOTI', 'const' => 'AMALIYOT_VOLUMES', 'kind' => 'paper', 'images' => 1, 'flag' => 'AMALIYOT_ENABLED', 'biblio' => 6, 'table_ch' => 2,
            'fixed' => [
                'uz' => ["Amaliyot o'tilgan tashkilot haqida umumiy ma'lumot", 'Tashkilotning tuzilmasi va asosiy faoliyati', 'Amaliyot davomida bajarilgan ishlar', 'Aniqlangan muammolar va takliflar'],
                'ru' => ['Общие сведения об организации — месте практики', 'Структура и основная деятельность организации', 'Работы, выполненные в ходе практики', 'Выявленные проблемы и предложения'],
                'en' => ['General information about the host organization', 'Structure and main activities of the organization', 'Work performed during the internship', 'Identified problems and recommendations'],
            ],
            'volumes' => [
                '10-15' => ['label' => '10-15 bet', 'chapters' => 4, 'sub' => 0, 'min_words' => 2800, 'max_words' => 3600, 'price' => 9000],
                '20-25' => ['label' => '20-25 bet', 'chapters' => 4, 'sub' => 2, 'min_words' => 5200, 'max_words' => 6400, 'price' => 14000],
            ]],
        'diplom' => ['label' => 'bitiruv malakaviy ishi', 'title' => 'Diplom ishi (BMI)', 'menu' => '🎓 Diplom ishi (BMI)', 'cmd' => '/diplom', 'doc' => 'BITIRUV MALAKAVIY ISHI', 'const' => 'DIPLOM_VOLUMES', 'kind' => 'paper', 'images' => 4, 'flag' => 'DIPLOM_ENABLED', 'biblio' => 20, 'table_ch' => 2,
            'volumes' => [
                '50-60' => ['label' => '50-60 bet', 'chapters' => 3, 'sub' => 3, 'min_words' => 12500, 'max_words' => 14500, 'price' => 49000],
                '60-75' => ['label' => '60-75 bet', 'chapters' => 3, 'sub' => 4, 'min_words' => 15500, 'max_words' => 18000, 'price' => 59000],
            ]],
        'maruza' => ['label' => "ma'ruza matni", 'title' => "Ma'ruza matni", 'menu' => "🎙 Ma'ruza matni", 'cmd' => '/maruza', 'doc' => "MA'RUZA MATNI", 'const' => 'MARUZA_VOLUMES', 'kind' => 'paper', 'images' => 1, 'flag' => 'MARUZA_ENABLED', 'biblio' => 6,
            'concl_extra' => "Xulosadan keyin bo'sh qator qoldirib, «Nazorat savollari:» deb yozing va ma'ruza bo'yicha 6-8 ta nazorat savolini raqamlab bering (har biri alohida qatorda, «1) » ko'rinishida). Undan keyin «Tayanch iboralar:» deb 8-12 ta asosiy atamani vergul bilan sanab bering.",
            'volumes' => [
                '6-8' => ['label' => '6-8 bet', 'chapters' => 3, 'sub' => 0, 'min_words' => 1800, 'max_words' => 2300, 'price' => 5000],
                '10-12' => ['label' => '10-12 bet', 'chapters' => 3, 'sub' => 0, 'min_words' => 3000, 'max_words' => 3600, 'price' => 8000],
            ]],
        'keys' => ['label' => 'keys (vaziyatli masala)', 'title' => 'Keys (case study)', 'menu' => '💼 Keys (case study)', 'cmd' => '/keys', 'doc' => 'KEYS', 'const' => 'KEYS_VOLUMES', 'kind' => 'paper', 'images' => 0, 'flag' => 'KEYS_ENABLED', 'biblio' => 5, 'table_ch' => 2,
            'concl_extra' => "Xulosadan keyin bo'sh qator qoldirib, «Muhokama uchun savollar:» deb yozing va keys bo'yicha 4-6 ta savolni raqamlab bering (har biri alohida qatorda, «1) » ko'rinishida).",
            'fixed' => [
                'uz' => ['Vaziyat tavsifi (keys matni)', "Muammoni aniqlash va tahlil qilish", "Yechim variantlari va ularni baholash", "Tanlangan yechim va amalga oshirish rejasi"],
                'ru' => ['Описание ситуации (текст кейса)', 'Выявление и анализ проблемы', 'Варианты решения и их оценка', 'Выбранное решение и план реализации'],
                'en' => ['Case description', 'Problem identification and analysis', 'Alternative solutions and their evaluation', 'Chosen solution and implementation plan'],
            ],
            'volumes' => [
                '4-6' => ['label' => '4-6 bet', 'chapters' => 4, 'sub' => 0, 'min_words' => 1300, 'max_words' => 1700, 'price' => 5000],
                '8-10' => ['label' => '8-10 bet', 'chapters' => 4, 'sub' => 0, 'min_words' => 2400, 'max_words' => 3000, 'price' => 8000],
            ]],
        'krossvord' => ['label' => 'krossvord', 'title' => 'Krossvord', 'menu' => '🧩 Krossvord', 'cmd' => '/krossvord', 'doc' => 'KROSSVORD', 'const' => 'KROSSVORD_VOLUMES', 'kind' => 'cross', 'images' => 0, 'flag' => 'KROSSVORD_ENABLED',
            'volumes' => [
                '15' => ['label' => "15 ta so'z", 'words' => 15, 'price' => 3000],
                '25' => ['label' => "25 ta so'z", 'words' => 25, 'price' => 4000],
            ]],
    ];

    public static function exists(string $t): bool
    {
        return isset(self::T[$t]) && self::enabled($t);
    }

    public static function enabled(string $t): bool
    {
        $f = self::T[$t]['flag'] ?? null;
        return $f === null || (bool)Engine::c($f, true);
    }

    /** Yoqilgan turlar (menyu tartibida). */
    public static function all(): array
    {
        return array_keys(array_filter(self::T, fn($v, $k) => self::enabled($k), ARRAY_FILTER_USE_BOTH));
    }

    /** Hajm/narx jadvali. $withPrices = true — admin paneldagi narxlar qo'llanadi. */
    public static function volumes(string $t, bool $withPrices = true): ?array
    {
        if (!isset(self::T[$t])) {
            return null;
        }
        $c = self::T[$t]['const'];
        $v = defined($c) ? constant($c) : (self::T[$t]['volumes'] ?? null);
        if ($v && $withPrices && class_exists('Settings', false)) {
            foreach ($v as $k => $row) {
                $v[$k]['price'] = Settings::price($t, (string)$k, (int)$row['price']);
            }
        }
        return $v;
    }

    /** Bepul birinchi buyurtma tarifi (admin o'chirgan bo'lsa — null). */
    public static function freeKey(string $t): ?string
    {
        if (!(bool)Engine::c('FREE_TRIAL', true) || !defined('FREE_TIER_KEY')) {
            return null;
        }
        return FREE_TIER_KEY[$t] ?? null;
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
        return isset(self::T[$t]) && in_array(self::T[$t]['kind'], ['paper', 'article'], true);
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
        return array_values(array_filter(array_map(fn($v) => in_array($v['kind'], ['paper', 'article'], true) ? $v['doc'] : '', self::T)));
    }

    public static function isArticleDoc(string $docType): bool
    {
        return in_array($docType, ['MAQOLA', 'TEZIS', 'ESSE'], true);
    }

    /** Qat'iy tuzilmali turlar (laboratoriya, amaliyot): bob sarlavhalari tayyor — reja uchun AI so'rovi yuborilmaydi. */
    public static function fixed(string $t, string $lang): ?array
    {
        $f = self::T[$t]['fixed'] ?? null;
        return $f ? ($f[$lang] ?? $f['uz']) : null;
    }

    public static function opt(string $t, string $k, $default = null)
    {
        return self::T[$t][$k] ?? $default;
    }
}
