<?php

/**
 * HUJJAT TILI (o'zbek / rus / ingliz).
 *
 * Foydalanuvchi «🌐 Til» (/til) orqali yaratiladigan hujjat (mustaqil ish, referat, slayd) tilini tanlaydi. Bot interfeysi
 * (tugmalar, xabarlar) o'zbekcha qoladi — o'zgaradigani: AI yozadigan matn, titul varag'i, "Reja", "Kirish", "Xulosa",
 * adabiyotlar, slayd teglari ("TA'RIF", "XULOSA" ...) va "Rahmat!" slaydi.
 */
class I18n
{
    public const DEFAULT = 'uz';
    public const NAMES = ['uz' => "🇺🇿 O'zbekcha", 'ru' => '🇷🇺 Русский', 'en' => '🇬🇧 English'];

    private const T = [
        'uz' => [
            'tag_matn' => "MA'LUMOT", 'tag_tarif' => "TA'RIF", 'tag_bosqichlar' => 'BOSQICHLAR', 'tag_xronologiya' => 'KETMA-KETLIK',
            'tag_kartalar' => "ASOSIY YO'NALISHLAR", 'tag_taqqoslash' => 'TAQQOSLASH', 'tag_xulosa' => 'XULOSA', 'tag_reja' => 'REJA',
            'key_features' => 'ASOSIY BELGILARI', 'pill_end' => 'YAKUN', 'pill_presentation' => 'TAQDIMOT',
            'agenda' => 'Reja', 'thanks' => 'Rahmat!', 'thanks_sub' => "E'tiboringiz uchun rahmat",
            'executor' => 'Bajaruvchi', 'group' => 'Guruh', 'topic' => 'Mavzu', 'plan_upper' => 'REJA:',
            'intro' => 'Kirish', 'conclusion' => 'Xulosa', 'biblio' => 'Foydalanilgan adabiyotlar', 'biblio_match' => 'adabiyot',
            'year_suffix' => "o'quv yili", 'checked_by' => 'Qabul qildi', 'contents' => 'MUNDARIJA', 'note_word' => 'Eslatma', 'default_panel' => "Mavzu bo'yicha asosiy xulosalar",
            'doc_MUSTAQIL ISH' => 'MUSTAQIL ISH', 'doc_REFERAT' => 'REFERAT',
            'biblio_text' => "1. Mavzuga oid darsliklar va o'quv qo'llanmalari.\n"
                . "2. Tegishli ilmiy jurnallardagi maqolalar va tadqiqot ishlari.\n"
                . "3. Rasmiy me'yoriy-huquqiy hujjatlar va statistik ma'lumotlar (mavjud bo'lsa).\n"
                . "4. Ishonchli internet resurslari va elektron ta'lim platformalari.\n\n"
                . "Eslatma: yuqoridagi ro'yxat umumlashtirilgan. Aniq muallif, sarlavha va nashr yilini "
                . "ilmiy rahbaringiz bilan kelishgan holda o'zingiz to'ldirishingiz tavsiya etiladi.",
        ],
        'ru' => [
            'tag_matn' => 'ИНФОРМАЦИЯ', 'tag_tarif' => 'ОПРЕДЕЛЕНИЕ', 'tag_bosqichlar' => 'ЭТАПЫ', 'tag_xronologiya' => 'ХРОНОЛОГИЯ',
            'tag_kartalar' => 'ОСНОВНЫЕ НАПРАВЛЕНИЯ', 'tag_taqqoslash' => 'СРАВНЕНИЕ', 'tag_xulosa' => 'ВЫВОДЫ', 'tag_reja' => 'ПЛАН',
            'key_features' => 'ОСНОВНЫЕ ПРИЗНАКИ', 'pill_end' => 'ФИНАЛ', 'pill_presentation' => 'ПРЕЗЕНТАЦИЯ',
            'agenda' => 'План', 'thanks' => 'Спасибо!', 'thanks_sub' => 'Благодарим за внимание',
            'executor' => 'Исполнитель', 'group' => 'Группа', 'topic' => 'Тема', 'plan_upper' => 'ПЛАН:',
            'intro' => 'Введение', 'conclusion' => 'Заключение', 'biblio' => 'Список использованной литературы', 'biblio_match' => 'литератур',
            'year_suffix' => 'учебный год', 'checked_by' => 'Принял(а)', 'contents' => 'СОДЕРЖАНИЕ', 'note_word' => 'Примечание', 'default_panel' => 'Основные выводы по теме',
            'doc_MUSTAQIL ISH' => 'САМОСТОЯТЕЛЬНАЯ РАБОТА', 'doc_REFERAT' => 'РЕФЕРАТ',
            'biblio_text' => "1. Учебники и учебные пособия по теме.\n"
                . "2. Статьи в научных журналах и исследовательские работы по теме.\n"
                . "3. Официальные нормативно-правовые документы и статистические данные (при наличии).\n"
                . "4. Надёжные интернет-ресурсы и электронные образовательные платформы.\n\n"
                . "Примечание: приведённый список обобщённый. Точных авторов, названия и год издания рекомендуется "
                . "дополнить самостоятельно по согласованию с научным руководителем.",
        ],
        'en' => [
            'tag_matn' => 'INFORMATION', 'tag_tarif' => 'DEFINITION', 'tag_bosqichlar' => 'STAGES', 'tag_xronologiya' => 'TIMELINE',
            'tag_kartalar' => 'KEY AREAS', 'tag_taqqoslash' => 'COMPARISON', 'tag_xulosa' => 'CONCLUSIONS', 'tag_reja' => 'AGENDA',
            'key_features' => 'KEY FEATURES', 'pill_end' => 'THE END', 'pill_presentation' => 'PRESENTATION',
            'agenda' => 'Agenda', 'thanks' => 'Thank you!', 'thanks_sub' => 'Thank you for your attention',
            'executor' => 'Prepared by', 'group' => 'Group', 'topic' => 'Topic', 'plan_upper' => 'CONTENTS:',
            'intro' => 'Introduction', 'conclusion' => 'Conclusion', 'biblio' => 'References', 'biblio_match' => 'reference',
            'year_suffix' => 'academic year', 'checked_by' => 'Checked by', 'contents' => 'CONTENTS', 'note_word' => 'Note', 'default_panel' => 'Key takeaways on the topic',
            'doc_MUSTAQIL ISH' => 'INDEPENDENT STUDY PAPER', 'doc_REFERAT' => 'ESSAY',
            'biblio_text' => "1. Textbooks and study guides on the subject.\n"
                . "2. Articles in relevant academic journals and research papers.\n"
                . "3. Official regulatory documents and statistical data (where available).\n"
                . "4. Reliable internet resources and e-learning platforms.\n\n"
                . "Note: the list above is general. It is recommended that you add exact authors, titles and publication "
                . "years yourself, in agreement with your academic supervisor.",
        ],
    ];

    private const ANGLES = [
        'ru' => [
            'теоретические основы и основные понятия темы', 'историческое развитие и этапы становления',
            'применение на практике, методы и инструменты', 'современные подходы и технологические решения',
            'существующие проблемы, недостатки и пути их устранения', 'сравнительный анализ опыта других отраслей и стран',
            'перспективы и тенденции дальнейшего развития',
        ],
        'en' => [
            'theoretical foundations and key concepts of the topic', 'historical development and stages of formation',
            'practical application, methods and tools', 'modern approaches and technological solutions',
            'existing problems, shortcomings and ways to address them', 'comparative analysis of experience in other fields and countries',
            'future prospects and development trends',
        ],
    ];

    /** AI reja ber(ol)masa ishlatiladigan umumiy slayd rejasi: [sarlavha, tur] */
    private const GENERIC_OUTLINE = [
        'uz' => [['Kirish', 'matn'], ["Asosiy tushunchalar va ta'rif", 'tarif'], ['Rivojlanish tarixi', 'xronologiya'],
            ['Asosiy turlari', 'kartalar'], ['Ishlash tartibi', 'bosqichlar'], ["Qo'llanilish sohalari", 'matn'],
            ['Afzalliklari va kamchiliklari', 'taqqoslash'], ['Muammolar va yechimlar', 'kartalar'],
            ['Kelajakdagi istiqbollari', 'matn'], ['Xulosa', 'xulosa']],
        'ru' => [['Введение', 'matn'], ['Основные понятия и определение', 'tarif'], ['История развития', 'xronologiya'],
            ['Основные виды', 'kartalar'], ['Порядок работы', 'bosqichlar'], ['Сферы применения', 'matn'],
            ['Преимущества и недостатки', 'taqqoslash'], ['Проблемы и решения', 'kartalar'],
            ['Перспективы развития', 'matn'], ['Выводы', 'xulosa']],
        'en' => [['Introduction', 'matn'], ['Key concepts and definition', 'tarif'], ['History of development', 'xronologiya'],
            ['Main types', 'kartalar'], ['How it works', 'bosqichlar'], ['Areas of application', 'matn'],
            ['Advantages and disadvantages', 'taqqoslash'], ['Problems and solutions', 'kartalar'],
            ['Future prospects', 'matn'], ['Conclusions', 'xulosa']],
    ];

    public static function valid($lang): bool
    {
        return is_string($lang) && isset(self::NAMES[$lang]);
    }

    public static function norm($lang): string
    {
        return self::valid($lang) ? $lang : self::DEFAULT;
    }

    public static function t(string $key, ?string $lang = null): string
    {
        $lang = self::norm($lang);
        return self::T[$lang][$key] ?? self::T[self::DEFAULT][$key] ?? $key;
    }

    public static function angles(?string $lang, array $uzDefault): array
    {
        $lang = self::norm($lang);
        return self::ANGLES[$lang] ?? $uzDefault;
    }

    public static function genericOutline(?string $lang): array
    {
        return self::GENERIC_OUTLINE[self::norm($lang)];
    }

    /** Vazirlik nomi: o'zbekcha — MINISTRY_NAME; boshqa tillar uchun MINISTRY_NAME_RU / _EN (berilmasa ko'rsatilmaydi). */
    public static function ministry(?string $lang): string
    {
        $lang = self::norm($lang);
        if ($lang === 'uz') {
            return defined('MINISTRY_NAME') ? (string)MINISTRY_NAME : '';
        }
        $c = 'MINISTRY_NAME_' . strtoupper($lang);
        return defined($c) ? (string)constant($c) : '';
    }

    /** AI so'rovlariga qo'shiladigan til ko'rsatmasi (o'zbek uchun bo'sh — so'rovlar allaqachon o'zbekcha). */
    public static function directive(?string $lang): string
    {
        switch (self::norm($lang)) {
            case 'ru':
                return "\n\nLANGUAGE: Напишите ВСЕ тексты (заголовки, пункты, абзацы, значения строк JSON) ТОЛЬКО на русском языке, "
                    . "по-узбекски НЕ пишите. Названия ключей JSON, значения поля \"turi\" (matn, tarif, kartalar, bosqichlar, xronologiya, "
                    . "taqqoslash, xulosa) и \"tomon\" (chap, ong) оставьте точно как указано в инструкции.";
            case 'en':
                return "\n\nLANGUAGE: Write ALL text (titles, bullet points, paragraphs, JSON string values) ONLY in English; "
                    . "do NOT write in Uzbek. Keep the JSON key names, the values of the \"turi\" field (matn, tarif, kartalar, bosqichlar, "
                    . "xronologiya, taqqoslash, xulosa) and \"tomon\" (chap, ong) exactly as given in the instructions.";
            default:
                return '';
        }
    }

    // ------------------------------------------------------------------ foydalanuvchi sozlamasi

    public static function ensureSchema(PDO $pdo): void
    {
        foreach (['users', 'jobs'] as $table) {
            $have = [];
            foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $have[$col['name']] = true;
            }
            if (!isset($have['lang'])) {
                try {
                    $pdo->exec("ALTER TABLE $table ADD COLUMN lang TEXT");
                } catch (Throwable $e) {
                }
            }
        }
    }

    public static function userLang(int $chatId): string
    {
        try {
            $st = db()->prepare('SELECT lang FROM users WHERE telegram_id = ?');
            $st->execute([$chatId]);
            return self::norm($st->fetchColumn() ?: null);
        } catch (Throwable $e) {
            return self::DEFAULT;
        }
    }

    public static function setUserLang(int $chatId, string $lang): void
    {
        db()->prepare('UPDATE users SET lang = ? WHERE telegram_id = ?')->execute([self::norm($lang), $chatId]);
    }

    public static function hintLine(int $chatId): string
    {
        return "\n🌐 Hujjat tili: <b>" . self::NAMES[self::userLang($chatId)] . "</b> (o'zgartirish: /til)";
    }

    public static function keyboard(string $current): array
    {
        $row = [];
        foreach (self::NAMES as $code => $label) {
            $row[] = ['text' => ($code === $current ? '✅ ' : '') . $label, 'callback_data' => 'lang:' . $code];
        }
        return ['inline_keyboard' => [$row]];
    }
}
