<?php
require_once __DIR__ . '/I18n.php';
/**
 * Ish qadamlari uchun yordamchi funksiyalar (avval worker.php ichida edi).
 * Bu fayl faqat funksiyalarni e'lon qiladi — o'zi hech narsa bajarmaydi.
 */

/**
 * Referal bonusini beradi — FAQAT foydalanuvchining birinchi PULLIK buyurtmasida
 * (bepul urinishda emas, chunki bu holda referal soxta/faolsiz bo'lishi mumkin edi).
 */
function maybeRewardReferrer(int $chatId, bool $wasFree): void
{
    if ($wasFree) {
        return;
    }

    $stmt = db()->prepare('SELECT referred_by, referral_rewarded FROM users WHERE telegram_id = :id');
    $stmt->execute(['id' => $chatId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !$user['referred_by'] || (int)$user['referral_rewarded'] === 1) {
        return;
    }

    $referrerId = (int)$user['referred_by'];

    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        // Yana bir marotaba tekshiramiz (BEGIN IMMEDIATE ichida) — ikki job deyarli
        // bir vaqtda tugasa ham, bonus faqat BIR MARTA berilishini kafolatlaydi.
        $check = $pdo->prepare('SELECT referral_rewarded FROM users WHERE telegram_id = :id');
        $check->execute(['id' => $chatId]);
        if ((int)$check->fetchColumn() === 1) {
            $pdo->exec('COMMIT');
            return;
        }

        $pdo->prepare('UPDATE users SET referral_rewarded = 1 WHERE telegram_id = :id')->execute(['id' => $chatId]);
        $pdo->prepare(
            'UPDATE users SET balance = balance + :b, referral_count = referral_count + 1, referral_earned = referral_earned + :b WHERE telegram_id = :id'
        )->execute(['b' => (int)Engine::c('REFERRAL_BONUS', 3000), 'id' => $referrerId]);

        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        logError('referral', 'maybeRewardReferrer xato: ' . $e->getMessage());
        return;
    }

    try {
        Telegram::sendMessage(
            $referrerId,
            "🎉 Sizning taklifingiz bilan kelgan foydalanuvchi birinchi buyurtmasini berdi!\n"
            . "Balansingizga " . number_format((int)Engine::c('REFERRAL_BONUS', 3000), 0, '', ' ') . " so'm qo'shildi."
        );
    } catch (Throwable $e) {
        logError('referral', 'Referal xabarini yuborishda xato: ' . $e->getMessage());
    }
}

/**
 * So'zlarni sanaydi (probel bo'yicha) — generatsiya qilingan matn buyurtma
 * qilingan hajmga (bet soniga) mos kelayotganini tekshirish uchun.
 */
function countWords(string $text): int
{
    $parts = preg_split('/\s+/u', trim($text));
    return $parts ? count(array_filter($parts)) : 0;
}

function fetchStudentInfo(int $chatId): array
{
    $stmt = db()->prepare('SELECT full_name, university, group_name FROM users WHERE telegram_id = :id');
    $stmt->execute(['id' => $chatId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

/**
 * Bobларга taqsimlanadigan standart "burchaklar" — har biri mavzuning boshqa
 * qirrasiga qaratilgan, shu bilan boblar bir-birini takrorlamaydi.
 */
function chapterAngles(): array
{
    return [
        "mavzuning nazariy asoslari va asosiy tushunchalari",
        "tarixiy rivojlanishi va shakllanish bosqichlari",
        "amaliyotda qo'llanilishi, usullari va vositalari",
        "zamonaviy yondashuvlar va texnologik yechimlar",
        "mavjud muammolari, kamchiliklari va ularni bartaraf etish yo'llari",
        "boshqa soha/mamlakatlar tajribasi bilan qiyosiy tahlili",
        "kelajakdagi istiqbollari va rivojlanish tendensiyalari",
    ];
}

/**
 * Ikki matn qanchalik bir-biriga o'xshashligini o'lchaydi (0 = umuman
 * o'xshamaydi, 1 = deyarli bir xil). Umumiy/qisqa so'zlar (3 harfdan kam)
 * chiqarib tashlanadi — shunda "va", "bu" kabi so'zlar soxta o'xshashlik
 * ko'rsatmaydi.
 */
function textSimilarity(string $a, string $b): float
{
    // Ba'zi arzon shared hosting'larda mbstring kengaytmasi o'chirilgan bo'lishi mumkin —
    // shunday holatda oddiy funksiyalarga tushamiz, xato bermaydi.
    $toLower = function_exists('mb_strtolower') ? 'mb_strtolower' : 'strtolower';
    $strlen = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';

    $toWords = function (string $t) use ($toLower, $strlen): array {
        $words = preg_split('/\s+/u', $toLower(trim($t)));
        $words = array_filter($words ?: [], fn($w) => $strlen($w) > 3);
        return array_unique($words);
    };
    $wordsA = $toWords($a);
    $wordsB = $toWords($b);
    if (!$wordsA || !$wordsB) {
        return 0.0;
    }
    $intersection = count(array_intersect($wordsA, $wordsB));
    $union = count(array_unique(array_merge($wordsA, $wordsB)));
    return $union > 0 ? $intersection / $union : 0.0;
}

/**
 * Slaydlar orasida punktlar bir-biriga juda o'xshab qolganini tekshiradi
 * (masalan 3-slaydda aytilgan gap 7-slaydda ham deyarli o'zgarmasdan takrorlangan bo'lsa).
 */
function hasRepeatedSlides(array $slides, float $threshold = 0.5): bool
{
    $texts = array_map(fn($s) => implode(' ', $s['punktlar'] ?? []), $slides);
    $n = count($texts);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            if (textSimilarity($texts[$i], $texts[$j]) > $threshold) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Taqdimot slaydlarining matnini tayyorlaydi.
 * Jarayon 2 bosqichli: (1) butun taqdimot REJASI (sarlavha + slayd turi + fikr), so'ng
 * (2) slaydlar 4 tadan guruhlab TO'LIQ matn bilan to'ldiriladi. Shunda har bir slayd
 * batafsil bo'ladi, javob kesilib qolmaydi va slaydlar bir-birini takrorlamaydi.
 */
function slideSystemPrompt(string $lang = 'uz'): string
{
    return "Siz o'zbek tilida taqdimot (slayd) matni tuzuvchi tajribali yordamchisiz. Faqat ishonchli, "
        . "umumiy ma'lumotlardan foydalaning, aniq raqam/sana/ismni aniq bilmasangiz o'ylab topmang. "
        . "HAR BIR SLAYD boshqa slaydlarda aytilgan fikrni TAKRORLAMASLIGI shart. "
        . "Matn mazmunli, tushunarli va to'liq jumlalardan iborat bo'lsin (bir-ikki so'zli quruq punktlar emas). "
        . "Faqat JSON formatda javob bering, boshqa hech narsa yozmang."
        . I18n::directive($lang);
}

/** Reja: [['sarlavha'=>..., 'turi'=>..., 'fikr'=>...], ...] — ANIQ $count ta element. */
function generateSlideOutline(string $topic, int $count, array $avoid = [], string $lang = 'uz'): array
{
    $prompt = "\"$topic\" mavzusidagi taqdimot uchun ANIQ $count ta slayd rejasini tuzing "
        . "(birinchisi — kirish, oxirgisi — xulosa). Har slayd mavzuning BOSHQA qirrasini yoritsin "
        . "(ta'rifi, tarixi, turlari, ishlash usuli, qo'llanilishi, afzallik va kamchiliklari, muammolari, istiqbollari va h.k.). "
        . "Sarlavha 2-7 so'zdan iborat bo'lsin. Har slayd uchun ko'rinish turini tanlang:\n"
        . "- \"tarif\": asosiy tushuncha ta'rifi\n"
        . "- \"matn\": batafsil tushuntirish\n"
        . "- \"kartalar\": bir nechta tur/yo'nalish/omilni alohida-alohida ko'rsatish\n"
        . "- \"bosqichlar\": jarayon yoki ish tartibi (qadamlar)\n"
        . "- \"xronologiya\": tarixiy rivojlanish yoki ketma-ketlik\n"
        . "- \"taqqoslash\": ikki tomonni solishtirish (afzallik/kamchilik, eski/yangi va h.k.)\n"
        . "- \"xulosa\": faqat OXIRGI slayd uchun\n"
        . "Turlar xilma-xil bo'lsin: ketma-ket ikki slayd bir xil turda bo'lmasin va kamida 4 xil tur ishlating. "
        . "Ikkinchi slayd \"tarif\" bo'lsin. "
        . (sectionCount($count) > 0 ? "Slaydlarni (oxirgi xulosadan tashqari) ketma-ket ANIQ " . sectionCount($count) . " ta mantiqiy bo'limga ajrating: har slaydga "
            . "\"bolim\" — u tegishli bo'limning 2-4 so'zli nomi (bir bo'limdagi slaydlarda aynan bir xil yozilsin). " : '')
        . "Har slaydga \"rasm\" — shu slayd uchun fotosurat qidirish iborasi, INGLIZ tilida, 2-5 so'z (mavzuga aniq bog'liq). "
        . "Yana \"en\" — butun mavzuning inglizcha qisqa nomi (2-6 so'z). "
        . 'JSON: {"en":"...","slaydlar":[{"sarlavha":"...","turi":"...","fikr":"bu slaydda nima yoritilishi (1 jumla)","rasm":"..."}]}';
    if ($avoid) {      // «Boshqa reja»: oldingi sarlavhalar yoqmagan
        $prompt .= "\n\nFOYDALANUVCHIGA OLDINGI REJA YOQMADI. Quyidagi sarlavhalarni TAKRORLAMANG, boshqacha qirralar va yangicha so'zlar bilan tuzing:\n- "
            . implode("\n- ", $avoid);
    }
    $prompt .= I18n::directive($lang);

    $outline = [];
    for ($attempt = 0; $attempt < 2 && count($outline) < (int)ceil($count * 0.7); $attempt++) {
        $raw = AIClient::generateText($prompt, slideSystemPrompt($lang), min(6000, $count * 140 + 800));
        $data = json_decode(extractJson($raw), true);
        $outline = [];
        $enTopic = cleanImageQuery((string)($data['en'] ?? ''));
        foreach (($data['slaydlar'] ?? []) as $s) {
            $t = trim((string)($s['sarlavha'] ?? ''));
            if ($t === '') {
                continue;
            }
            $outline[] = [
                'sarlavha' => $t,
                'turi' => strtolower(trim((string)($s['turi'] ?? 'matn'))),
                'fikr' => trim((string)($s['fikr'] ?? '')),
                'rasm' => cleanImageQuery((string)($s['rasm'] ?? '')),
                'bolim' => mb_substr(trim((string)($s['bolim'] ?? '')), 0, 80),
            ];
        }
        if (count($outline) < (int)ceil($count * 0.7)) {
            logError('worker', "Slayd rejasi yetarli chiqmadi (" . count($outline) . "/$count) — qayta so'raladi.");
        }
    }

    // AI reja bera olmasa ham bot yiqilmasin: umumiy reja
    if (count($outline) < (int)ceil($count * 0.7)) {
        $generic = I18n::genericOutline($lang);
        $outline = [];
        for ($i = 0; $i < $count; $i++) {
            $g = $generic[min($i, count($generic) - 1)];
            if ($i === $count - 1) {
                $g = [$generic[count($generic) - 1][0], 'xulosa'];
            } elseif ($i >= count($generic) - 1) {
                $g = [['uz' => "Qo'shimcha jihatlar ", 'ru' => 'Дополнительные аспекты ', 'en' => 'Additional aspects '][I18n::norm($lang)] . ($i - count($generic) + 3), 'matn'];
            }
            $outline[] = ['sarlavha' => $g[0], 'turi' => $g[1], 'fikr' => "$topic mavzusining «{$g[0]}» jihati"];
        }
    }

    $outline = array_slice($outline, 0, $count);
    if (!empty($enTopic) && $outline) {
        $outline[0]['en_topic'] = $enTopic;          // muqova rasmi uchun (alohida tarjima so'rovi kerak bo'lmaydi)
    }

    // Turlarni tekshiramiz: noma'lum tur -> matn; xulosa faqat oxirida; ketma-ket bir xil tur bo'lmasin
    $rotation = ['kartalar', 'matn', 'bosqichlar', 'xronologiya', 'taqqoslash'];
    $last = count($outline) - 1;
    $rot = 0;
    $prev = '';
    foreach ($outline as $i => &$o) {
        if (!in_array($o['turi'], SLIDE_KINDS, true)) {
            $o['turi'] = 'matn';
        }
        if ($i === $last) {
            $o['turi'] = 'xulosa';
        } elseif ($o['turi'] === 'xulosa') {
            $o['turi'] = 'matn';
        }
        if ($i === 1 && $count > 3) {
            $o['turi'] = 'tarif';
        }
        if ($o['turi'] === $prev && $i !== $last) {
            $o['turi'] = $rotation[$rot++ % count($rotation)];
            if ($o['turi'] === $prev) {
                $o['turi'] = $rotation[$rot++ % count($rotation)];
            }
        }
        $prev = $o['turi'];
    }
    unset($o);

    return $outline;
}

/** Bitta guruh slaydlarning to'liq matnini so'raydi. Qaytadi: rejadagi tartibda massiv (yo'q bo'lganlari null). */
function generateSlideBatch(string $topic, array $outline, array $indexes, string $lang = 'uz', bool $notes = false): array
{
    // Boshqa slaydlar faqat sarlavhasi bilan (takrorlanmaslik uchun shu yetarli) — har so'rovda kirish tokenlari kamayadi
    $planText = '';
    foreach ($outline as $i => $o) {
        if (!in_array($i, $indexes, true)) {
            $planText .= ($i + 1) . ') ' . $o['sarlavha'] . "\n";
        }
    }

    $todo = '';
    foreach ($indexes as $i) {
        $o = $outline[$i];
        $n = SLIDE_KIND_POINTS[$o['turi']];
        $extra = match ($o['turi']) {
            'tarif' => "\"izoh\" — mavzuning TO'LIQ ta'rifi (35-50 so'z); punktlar: 3 ta asosiy belgi/xususiyat.",
            'taqqoslash' => "punktlar: 6 ta — 3 tasida \"tomon\":\"chap\", 3 tasida \"tomon\":\"ong\"; hamda \"chap_nomi\" va \"ong_nomi\" (1-3 so'z) maydonlari.",
            'xulosa' => "punktlar: 5 ta qisqa xulosa (har biri 12-20 so'z); \"izoh\" — umumiy yakuniy fikr (25-35 so'z).",
            'xronologiya' => "punktlar: 5 ta; \"bosh\" da davr/bosqich nomi (masalan «1990-yillar: ...»).",
            'bosqichlar' => "punktlar: 4 ta ketma-ket qadam.",
            'kartalar' => "punktlar: 4 ta alohida yo'nalish/tur (har birining o'z nomi bo'lsin).",
            default => "punktlar: $n ta.",
        };
        $todo .= ($i + 1) . ') "' . $o['sarlavha'] . '" (turi: ' . $o['turi'] . ') — ' . $o['fikr'] . '. ' . $extra . "\n";
    }

    $prompt = "Mavzu: \"$topic\".\nTaqdimotning to'liq rejasi (boshqa slaydlar bilan takrorlanmaslik uchun):\n$planText\n"
        . "Endi FAQAT quyidagi slaydlarning TO'LIQ matnini yozing:\n$todo\n"
        . "Talablar:\n"
        . "- \"izoh\": slaydning asosiy fikrini ochib beruvchi 1-2 jumla (20-35 so'z).\n"
        . "- \"punktlar\": har biri {\"bosh\": 2-5 so'zli sarlavha, \"matn\": 1-2 jumlali aniq tushuntirish (15-28 so'z)}.\n"
        . "- Har bir punkt bitta yangi fikrni ochsin, misol yoki izoh bilan; umumiy gaplar va takrorlardan qoching.\n"
        . "- Slaydlarni shu tartibda, har biri uchun bitta obyekt qilib qaytaring.\n"
        . ($notes ? "- \"nutq\": taqdimotchi shu slaydda og'zaki aytadigan 3-4 jumlalik tabiiy nutq matni (45-70 so'z), slayddagi gapni takrorlamasdan, tushuntirib va bog'lab.\n" : '')
        . 'JSON: {"slaydlar":[{"izoh":"...",' . ($notes ? '"nutq":"...",' : '') . '"punktlar":[{"bosh":"...","matn":"..."}]}]}  '
        . '(taqqoslash uchun qo\'shimcha: "chap_nomi","ong_nomi" va punktda "tomon").';

    $prompt .= I18n::directive($lang);
    $maxTokens = min(8000, count($indexes) * ($notes ? 1400 : 1100) + 1200);
    $raw = AIClient::generateText($prompt, slideSystemPrompt($lang), $maxTokens);
    $data = json_decode(extractJson($raw), true);
    $items = $data['slaydlar'] ?? [];
    $result = [];
    foreach (array_values($indexes) as $k => $idx) {
        // Faqat kamida 2 ta punkti bor element yaroqli. Avval "izoh"i bor, lekin punktlari yo'q javob ham "muvaffaqiyatli"
        // hisoblanib, slayd bitta zaglushka matn bilan (katta bo'sh karta ichida) chiqib ketardi.
        $it = $items[$k] ?? null;
        $result[$idx] = (is_array($it) && is_array($it['punktlar'] ?? null) && count($it['punktlar']) >= 2) ? $it : null;
    }
    return $result;
}

/** AI javobidagi slaydni PptxGenerator formatiga o'tkazadi. */
function buildSlideFromAi(array $o, ?array $ai): array
{
    $slide = ['kind' => $o['turi'], 'title' => $o['sarlavha'], 'lead' => '', 'points' => []];
    if ($ai) {
        $slide['lead'] = trim((string)($ai['izoh'] ?? ''));
        $nutq = trim((string)($ai['nutq'] ?? ''));
        if ($nutq !== '') {
            $slide['notes'] = mb_substr($nutq, 0, 1500);
        }
        foreach (($ai['punktlar'] ?? []) as $p) {
            if (is_string($p) && trim($p) !== '') {
                $slide['points'][] = ['head' => '', 'text' => trim($p), 'side' => ''];
            } elseif (is_array($p)) {
                $h = trim((string)($p['bosh'] ?? $p['sarlavha'] ?? ''));
                $t = trim((string)($p['matn'] ?? ''));
                if ($h === '' && $t === '') {
                    continue;
                }
                $slide['points'][] = ['head' => $h, 'text' => $t, 'side' => strtolower(trim((string)($p['tomon'] ?? '')))];
            }
        }
        $slide['left_title'] = trim((string)($ai['chap_nomi'] ?? ''));
        $slide['right_title'] = trim((string)($ai['ong_nomi'] ?? ''));
    }
    if (!$slide['points']) {
        // AI bu slayd uchun matn bermagan — kamida rejadagi fikr ko'rinsin
        $slide['kind'] = 'matn';
        $slide['points'] = [['head' => '', 'text' => $o['fikr'] ?: $o['sarlavha'], 'side' => '']];
    } elseif ($slide['kind'] === 'taqqoslash' && count($slide['points']) < 4) {
        $slide['kind'] = 'kartalar';
    } elseif ($slide['kind'] === 'bosqichlar' && count($slide['points']) < 3) {
        $slide['kind'] = 'matn';
    }
    return $slide;
}

function extractJson(string $raw): string
{
    $start = strpos($raw, '{');
    $end = strrpos($raw, '}');
    if ($start === false || $end === false) {
        return '';
    }
    return substr($raw, $start, $end - $start + 1);
}

/** AI bergan inglizcha rasm iborasini tozalaydi (bo'sh/uzun/kirill bo'lsa — bo'sh qaytadi, keyin tarjima zaxirasi ishlaydi). */
function cleanImageQuery(string $q): string
{
    $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '', " \t\n\r\"'.,;:");
    if ($q === '' || mb_strlen($q) > 80 || preg_match('/[\x{0400}-\x{04FF}]/u', $q) || !preg_match('/^[\p{L}\p{N} \'\-&]+$/u', $q)) {
        return '';
    }
    return $q;
}

/** Taqdimotga qo'shiladigan bo'lim ajratuvchi slaydlar soni (AI so'ralmaydi, tarif oralig'idan chiqmaydi). */
function sectionCount(int $contentCount): int
{
    if (!(bool)Engine::c('SECTION_SLIDES', true)) {
        return 0;
    }
    return $contentCount >= 14 ? 3 : ($contentCount >= 8 ? 2 : 0);
}

/**
 * Kontent slaydlarini bo'limlarga ajratadi. AI bergan "bolim" nomlari yaroqli bo'lsa (ketma-ket, har birida kamida 2 slayd,
 * soni to'g'ri) — o'shalar ishlatiladi, aks holda slaydlar teng bo'linadi va bo'lim nomi birinchi slayd sarlavhasidan olinadi.
 * Qaytadi: [startIndex => ['name' => ..., 'titles' => [...]]] (xulosa slaydi oxirgi bo'limga qo'shilmaydi, ajratuvchisiz qoladi).
 */
function buildSections(array $outline, array $slides, int $nsec): array
{
    $n = count($slides);
    if ($nsec < 2 || $n < $nsec * 2 + 1) {
        return [];
    }
    $body = $n - 1;                      // oxirgi — xulosa
    $groups = [];
    $prev = null;
    for ($i = 0; $i < $body; $i++) {
        $name = trim((string)($outline[$i]['bolim'] ?? ''));
        $key = mb_strtolower($name);
        if ($name === '' ) {
            $groups = [];
            break;
        }
        if ($key !== $prev) {
            $groups[] = ['start' => $i, 'name' => $name, 'len' => 0];
            $prev = $key;
        }
        $groups[count($groups) - 1]['len']++;
    }
    $ok = count($groups) >= 2 && count($groups) <= $nsec + 1;
    foreach ($groups as $g) {
        $ok = $ok && $g['len'] >= 2;
    }
    if (!$ok) {
        $groups = [];
        for ($k = 0; $k < $nsec; $k++) {
            $st = (int)floor($k * $body / $nsec);
            $en = (int)floor(($k + 1) * $body / $nsec);
            $groups[] = ['start' => $st, 'name' => (string)($slides[$st]['title'] ?? ''), 'len' => $en - $st];
        }
    }
    $out = [];
    foreach ($groups as $g) {
        $titles = [];
        for ($i = $g['start']; $i < $g['start'] + $g['len']; $i++) {
            $titles[] = (string)($slides[$i]['title'] ?? '');
        }
        $out[$g['start']] = ['name' => $g['name'], 'titles' => array_values(array_filter($titles))];
    }
    return $out;
}
