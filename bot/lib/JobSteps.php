<?php

require_once __DIR__ . '/JobFunctions.php';
require_once __DIR__ . '/PlanReview.php';
require_once __DIR__ . '/DocStore.php';
require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/SpeechDoc.php';
require_once __DIR__ . '/Bibliography.php';
require_once __DIR__ . '/DocTypes.php';
require_once __DIR__ . '/SlidePreview.php';

/**
 * Ish turlari uchun reja (qadamlar) va har bir qadamni bajarish.
 * Har qadam natijasi $state['res'] ga yoziladi va bazaga saqlanadi — uzilsa, shu joydan davom etadi.
 */
class JobSteps
{
    private const PART_WORDS = 520;     // bitta AI so'rovida yoziladigan taxminiy so'z soni (har so'rov ~20-40s ichida tugashi uchun)

    // ------------------------------------------------------------------ reja

    /** Buyurtma yaratilganda saqlangan talaba ma'lumoti (jobs.student); eski buyurtmalarda — foydalanuvchi profili. */
    private static function jobStudent(array $job, int $chatId): array
    {
        $s = json_decode((string)($job['student'] ?? ''), true);
        if (is_array($s) && !empty($s['full_name'])) {
            return $s;
        }
        return fetchStudentInfo($chatId);
    }

    public static function init(array $job): array
    {
        $type = (string)$job['type'];
        $chatId = (int)$job['telegram_id'];
        $steps = [];
        $lang = I18n::norm($job['lang'] ?? I18n::userLang($chatId));
        $p = ['topic' => (string)$job['topic'], 'student' => self::jobStudent($job, $chatId), 'lang' => $lang];

        if (DocTypes::isDoc($type)) {
            $vols = DocTypes::volumes($type) ?? [];
            $v = $vols[$job['volume_key']] ?? reset($vols);
            $kind = DocTypes::kind($type);
            $p += ['docType' => DocTypes::docType($type), 'docLabel' => DocTypes::label($type), 'kind' => $kind, 'dtype' => $type];
            $res = [];
            if ($type === 'tezis') {
                $words = max(400, (int)$v['min_words']);
                $p += ['chapters' => 0, 'per' => $words, 'parts' => 1, 'partWords' => $words, 'sub' => 0];
                $steps[] = ['id' => 'tezis', 'k' => 'tezis', 'w' => max(2.0, $words / 100), 'label' => 'Tezis matni yozilmoqda'];
            } else {
                if ($kind === 'article') {          // maqola: tuzilma qat'iy — reja uchun AI so'rovi yuborilmaydi
                    $chapters = 2;
                    $res['titles'] = [I18n::t('art_method', $lang), I18n::t('art_results', $lang)];
                    $res['subs'] = [];
                    $per = max(300, (int)round($v['min_words'] / 3.2));
                    $sub = 0;
                } else {
                    $chapters = max(1, (int)$v['chapters']);
                    $per = max(150, (int)round($v['min_words'] / ($chapters + 1)));
                    $sub = array_key_exists('sub', $v) ? (int)$v['sub'] : ($type === 'referat' ? 0 : ($per >= 1200 ? 3 : ($per >= 450 ? 2 : 0)));
                }
                $parts = $sub > 0 ? $sub : max(1, (int)ceil($per / self::PART_WORDS));
                $partWords = (int)ceil($per / $parts);
                $p += ['chapters' => $chapters, 'per' => $per, 'parts' => $parts, 'partWords' => $partWords, 'sub' => $sub];
                if ($kind === 'paper') {
                    $steps[] = ['id' => 'outline', 'k' => 'outline', 'w' => 1.5, 'label' => 'Reja va bob sarlavhalari tuzilmoqda'];
                    if (PlanReview::enabled()) {
                        $steps[] = PlanReview::stepDef();
                    }
                }
                $steps[] = ['id' => 'intro', 'k' => 'intro', 'w' => max(1.0, $per * 0.7 / 100), 'label' => 'Kirish qismi yozilmoqda'];
                for ($c = 1; $c <= $chapters; $c++) {
                    for ($j = 1; $j <= $parts; $j++) {
                        $steps[] = ['id' => "c{$c}p{$j}", 'k' => 'chapter', 'c' => $c, 'j' => $j, 'w' => max(1.0, $partWords / 100),
                            'label' => ($kind === 'article' ? ($c === 1 ? 'Metodologiya' : 'Natijalar va muhokama') : "{$c}-bob") . ' yozilmoqda'
                                . ($parts > 1 ? ($sub > 0 ? " ({$c}.{$j}-band)" : " ({$j}/{$parts} qism)") : '')];
                    }
                }
                $steps[] = ['id' => 'concl', 'k' => 'concl', 'w' => max(1.0, $per * 0.7 / 100), 'label' => 'Xulosa yozilmoqda'];
                if ($kind === 'article') {
                    $steps[] = ['id' => 'abstract', 'k' => 'abstract', 'w' => 1.0, 'label' => 'Annotatsiya va kalit so\'zlar'];
                }
            }
            $steps[] = ['id' => 'biblio', 'k' => 'biblio', 'w' => 1.0, 'label' => 'Haqiqiy ilmiy manbalar qidirilmoqda'];
            if (DocTypes::images($type) > 0 && (bool)Engine::c('DOC_IMAGES', true)) {
                $steps[] = ['id' => 'dimg', 'k' => 'doc_images', 'w' => 1.5, 'label' => 'Rasmlar tanlanmoqda'];
            }
            $steps[] = ['id' => 'build', 'k' => 'build_doc', 'w' => 2.0, 'label' => 'Hujjat (DOCX) shakllantirilmoqda'];
            $steps[] = ['id' => 'deliver', 'k' => 'deliver', 'w' => 1.5, 'label' => 'Telegram’ga yuborilmoqda'];
            return ['v' => 1, 'type' => $type, 'steps' => $steps, 'i' => 0, 'p' => $p, 'res' => $res, 'att' => [], 'dur' => [], 'shrink' => []];
        } elseif ($type === 'slayd') {
            $v = SLAYD_VOLUMES[$job['volume_key']] ?? array_values(SLAYD_VOLUMES)[0];
            // Muqova + Reja + "Rahmat!" slaydlari avtomatik qo'shiladi (+3), shuning uchun AI'dan shuncha kam so'raymiz —
            // aks holda "8-10 slayd" tarifida 11 ta slayd chiqardi.
            $count = max(4, (int)$v['slides'] - 3);
            $nb = (int)ceil($count / 4);
            $dk = (string)($job['design_key'] ?? '');
            $p += ['count' => $count, 'designKey' => isset(pptxDesigns()[$dk]) ? $dk : DEFAULT_PPTX_DESIGN];
            $steps[] = ['id' => 'outline', 'k' => 'slide_outline', 'w' => 2.0, 'label' => 'Taqdimot rejasi tuzilmoqda'];
            if (PlanReview::enabled()) {
                $steps[] = PlanReview::stepDef();
            }
            for ($b = 0; $b < $nb; $b++) {
                $from = $b * 4 + 1;
                $to = min($count, $b * 4 + 4);
                $steps[] = ['id' => "b{$b}", 'k' => 'slide_batch', 'b' => $b, 'w' => 3.0, 'label' => "Slaydlar {$from}-{$to} matni yozilmoqda"];
            }
            $steps[] = ['id' => 'images', 'k' => 'slide_images', 'w' => 2.0, 'label' => 'Rasmlar tanlanmoqda'];
            $steps[] = ['id' => 'build', 'k' => 'slide_build', 'w' => 3.0, 'label' => 'Taqdimot (PPTX) shakllantirilmoqda'];
            if (SlidePreview::enabled()) {
                $steps[] = ['id' => 'preview', 'k' => 'slide_preview', 'w' => 1.0, 'label' => 'Slaydlar ko\'rinishi tayyorlanmoqda'];
            }
            $steps[] = ['id' => 'deliver', 'k' => 'deliver', 'w' => 1.5, 'label' => 'Telegram’ga yuborilmoqda'];
        } else {
            throw new RuntimeException("Noma'lum job turi: $type");
        }
        return ['v' => 1, 'type' => $type, 'steps' => $steps, 'i' => 0, 'p' => $p, 'res' => [], 'att' => [], 'dur' => [], 'shrink' => []];
    }

    // ------------------------------------------------------------------ bajarish

    public static function exec(array $step, array $job, array &$state): void
    {
        switch ($step['k']) {
            case 'outline':
                self::outline($state);
                break;
            case 'plan_review':
                PlanReview::exec($job, $state);
                break;
            case 'intro':
            case 'chapter':
            case 'concl':
                self::stepWrite($step, $state);
                break;
            case 'build_doc':
                self::stepBuildDoc($job, $state);
                break;
            case 'tezis':
                self::stepTezis($state);
                break;
            case 'abstract':
                self::stepAbstract($state);
                break;
            case 'biblio':
                self::stepBiblio($state);
                break;
            case 'doc_images':
                self::stepDocImages($state);
                break;
            case 'slide_outline':
                $state['res']['outline'] = generateSlideOutline($state['p']['topic'], (int)$state['p']['count'], [], (string)($state['p']['lang'] ?? 'uz'));
                break;
            case 'slide_batch':
                self::stepSlideBatch($step, $state);
                break;
            case 'slide_images':
                self::stepSlideImages($state);
                break;
            case 'slide_build':
                self::stepSlideBuild($job, $state);
                break;
            case 'slide_preview':
                self::stepSlidePreview($state);
                break;
            case 'deliver':
                self::stepDeliver($job, $state);
                break;
            default:
                throw new RuntimeException('Noma\'lum qadam: ' . $step['k']);
        }
    }

    // ---- mustaqil ish / referat ----

    private static function system(array $p): string
    {
        return "Siz o'zbek tilida ilmiy-akademik uslubda {$p['docLabel']} yozuvchi yordamchisiz. "
            . "Faqat ishonchli, umumiy tan olingan ma'lumotlardan foydalaning. Aniq statistik raqam, muallif ismi yoki sana "
            . "bilmasangiz — buni O'YLAB TOPMANG, umumiy tarzda yozing. Boshqa bo'limlarda aytilgan fikr va misollarni TAKRORLAMANG. "
            . "FAQAT oddiy matn yozing: markdown belgilari (#, *, **), sarlavha, JSON, \"Albatta\" kabi kirish "
            . "gaplar yoki izohlar BO'LMASIN. Abzatslarni bo'sh qator bilan ajrating. Mazmunan zarur bo'lsa (bo'limda ko'pi bilan bitta) "
            . "3-5 punktli qisqa ro'yxat qo'shish mumkin: har punkt alohida qatorda \"• \" belgisi bilan boshlansin." . I18n::directive($p['lang'] ?? 'uz');
    }

    /**
     * Bob sarlavhalari rejasi (PlanReview «Boshqa reja» uchun ham ochiq). Bitta AI so'rovida: boblar, har bob ichidagi
     * bandlar (1.1, 1.2 …), mavzuning inglizcha nomi (adabiyotlar qidiruvi uchun) va boblar uchun inglizcha rasm iboralari.
     */
    public static function outline(array &$state): void
    {
        $p = $state['p'];
        $sub = (int)($p['sub'] ?? 0);
        $angles = array_slice(I18n::angles($p['lang'] ?? 'uz', chapterAngles()), 0, $p['chapters']);
        while (count($angles) < $p['chapters']) {
            $angles[] = 'mavzuning qo\'shimcha jihatlari va amaliy ahamiyati';
        }
        $list = implode("\n", array_map(fn($i, $a) => ($i + 1) . ". $a", array_keys($angles), $angles));
        $prompt = "\"{$p['topic']}\" mavzusidagi {$p['docLabel']} uchun ANIQ {$p['chapters']} ta bob sarlavhasini tuzing. "
            . "Boblar quyidagi yo'nalishlarda bo'lsin (har biri o'z yo'nalishiga mos, qisqa va aniq sarlavha):\n$list\n\n"
            . ($sub > 0 ? "Har bob ichida ANIQ $sub ta band (kichik bo'lim) sarlavhasi bo'lsin — bob mavzusini mantiqiy bo'laklarga ajratsin, bir-birini takrorlamasin. " : '')
            . "Yana: \"en\" — mavzuning inglizcha qisqa nomi (ilmiy manbalar qidirish uchun, 2-6 so'z); har bobga \"rasm\" — shu bob uchun "
            . "fotosurat qidirish iborasi INGLIZ tilida (2-5 so'z).\n"
            . 'Faqat JSON qaytaring: {"en":"...","boblar":[{"nom":"1. ...",' . ($sub > 0 ? '"bandlar":["...","..."],' : '') . '"rasm":"..."}]}';
        if (!empty($state['res']['plan_avoid'])) {      // foydalanuvchi «Boshqa reja» so'ragan — oldingisidan farq qilsin
            $prompt .= "\n\nFOYDALANUVCHIGA OLDINGI REJA YOQMADI. Quyidagi sarlavhalarni TAKRORLAMANG, boshqacha, yangicha so'zlar va yondashuv bilan yozing:\n- "
                . implode("\n- ", $state['res']['plan_avoid']);
        }
        $prompt .= I18n::directive($p['lang'] ?? 'uz');
        $titles = [];
        $subs = [];
        $rasm = [];
        $en = '';
        try {
            $raw = AIClient::generateText($prompt, "Faqat JSON formatda javob bering.", 700 + $p['chapters'] * ($sub > 0 ? 160 : 60), ['temperature' => 0.4]);
            $data = json_decode(extractJson($raw), true);
            $en = cleanImageQuery((string)($data['en'] ?? ''));
            foreach (($data['boblar'] ?? []) as $t) {
                if (is_string($t) && trim($t) !== '') {
                    $titles[] = trim($t);
                    $subs[] = [];
                    $rasm[] = '';
                } elseif (is_array($t) && trim((string)($t['nom'] ?? '')) !== '') {
                    $titles[] = trim((string)$t['nom']);
                    $bs = [];
                    foreach ((array)($t['bandlar'] ?? []) as $bnd) {
                        $bnd = trim(preg_replace('/^\s*\d+(\.\d+)*[\.\)]?\s*/u', '', (string)$bnd) ?? '');
                        if (mb_strlen($bnd) >= 3) {
                            $bs[] = mb_substr($bnd, 0, 140);
                        }
                    }
                    $subs[] = $bs;
                    $rasm[] = cleanImageQuery((string)($t['rasm'] ?? ''));
                }
            }
        } catch (AIUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            logError('steps', 'outline: ' . $e->getMessage());
        }
        $final = [];
        $finalSubs = [];
        for ($i = 0; $i < $p['chapters']; $i++) {
            $t = $titles[$i] ?? null;
            if ($t === null || mb_strlen($t) < 4) {
                $a = $angles[$i];
                $t = mb_strtoupper(mb_substr($a, 0, 1)) . mb_substr($a, 1);
            }
            $t = preg_replace('/^\s*\d+[\.\)]\s*/u', '', $t);
            $final[] = ($i + 1) . '. ' . mb_substr($t, 0, 140);
            $bs = array_slice($subs[$i] ?? [], 0, $sub);
            $finalSubs[] = $sub > 0 && count($bs) === $sub ? $bs : [];
        }
        $state['res']['titles'] = $final;
        $state['res']['subs'] = $finalSubs;
        $state['res']['rasm'] = array_slice($rasm, 0, $p['chapters']);
        if ($en !== '') {
            $state['res']['en'] = $en;
        }
    }

    private static function stepWrite(array $step, array &$state): void
    {
        $p = $state['p'];
        $key = $step['id'];
        $titles = $state['res']['titles'] ?? [];
        $shrink = (float)($state['shrink'][$key] ?? 1.0);
        $attempt = (int)($state['att'][$key] ?? 0);
        $role = $step['k'];

        if ($role === 'intro') {
            $target = (int)round($p['per'] * 0.7 * $shrink);
            $prompt = "\"{$p['topic']}\" mavzusidagi {$p['docLabel']} uchun KIRISH qismini yozing (taxminan $target so'z): mavzuning dolzarbligi, "
                . "maqsad va vazifalari, ishning tuzilishi. Ish boblari:\n" . implode("\n", $titles);
        } elseif ($role === 'concl') {
            $target = (int)round($p['per'] * 0.7 * $shrink);
            $prompt = "\"{$p['topic']}\" mavzusidagi {$p['docLabel']} uchun XULOSA qismini yozing (taxminan $target so'z): har bir bobdan kelib chiqadigan "
                . "asosiy xulosalar va amaliy tavsiyalar. Ish boblari:\n" . implode("\n", $titles);
        } else {
            $c = (int)$step['c'];
            $j = (int)$step['j'];
            $target = (int)round($p['partWords'] * $shrink);
            $title = $titles[$c - 1] ?? ($c . '. Bob');
            $others = [];
            foreach ($titles as $i => $t) {
                if ($i !== $c - 1) {
                    $others[] = $t;
                }
            }
            $subList = $state['res']['subs'][$c - 1] ?? [];
            $subTitle = $subList[$j - 1] ?? '';
            if ($subTitle !== '') {
                $siblings = [];
                foreach ($subList as $k => $st) {
                    if ($k !== $j - 1) {
                        $siblings[] = $c . '.' . ($k + 1) . '. ' . $st;
                    }
                }
                $prompt = "\"{$p['topic']}\" mavzusidagi {$p['docLabel']}ning «{$title}» bobidagi «{$c}.{$j}. {$subTitle}» bandini yozing. Taxminan $target so'z. "
                    . "Band sarlavhasini yozmang (u avtomatik qo'yiladi). Faqat shu band mavzusini yoriting; shu bobning boshqa bandlari (ularni takrorlamang):\n"
                    . implode("\n", $siblings) . "\nBoshqa boblar:\n" . implode("\n", $others);
            } else {
                $prompt = "\"{$p['topic']}\" mavzusidagi {$p['docLabel']}ning quyidagi bobini yozing: «{$title}». Taxminan $target so'z. "
                    . "Bu bob FAQAT o'z mavzusiga qaratilsin; quyidagi boshqa boblarning mazmunini takrorlamang:\n" . implode("\n", $others);
            }
            if ($c === 2 && $j === 1 && (bool)Engine::c('DOC_TABLES', true) && empty($state['res']['table_asked'])) {
                $prompt .= "\n\nMatn ichida mavzuga mos BITTA kichik jadval bering (raqamlarsiz, sifat bo'yicha taqqoslash yoki tasnif: 3-5 qator, 2-4 ustun). "
                    . "Jadval FAQAT shu formatda (boshqa markdown ishlatmang):\nJADVAL: jadval nomi\n| Ustun 1 | Ustun 2 |\n| qiymat | qiymat |\n"
                    . "Jadvaldan oldin uni tanishtiruvchi va keyin izohlovchi oddiy abzats bo'lsin.";
            }
            if ($p['parts'] > 1 && $subTitle === '') {
                $prompt .= "\n\nBu bobning {$j}/{$p['parts']} qismi.";
                if ($j > 1) {
                    $prev = $state['res']['t']["c{$c}p" . ($j - 1)] ?? '';
                    $tail = function_exists('mb_substr') ? mb_substr($prev, -350) : substr($prev, -350);
                    $prompt .= " Oldingi qism shunday tugagan: «…{$tail}». Uni takrorlamasdan mantiqiy davom eting.";
                }
                if ($j < $p['parts']) {
                    $prompt .= " Bobni yakunlamang — keyingi qism davom etadi.";
                }
            }
            $seen = [];
            foreach (($state['res']['t'] ?? []) as $k => $txt) {
                if (preg_match('/^c(\d+)p1$/', $k, $mm) && (int)$mm[1] < $c) {
                    $first = trim(preg_split('/(?<=[.!?])\s/u', trim($txt), 2)[0] ?? '');
                    if ($first !== '') {
                        $seen[] = '- ' . (function_exists('mb_substr') ? mb_substr($first, 0, 160) : substr($first, 0, 160));
                    }
                }
            }
            if ($seen) {
                $prompt .= "\n\nOldingi boblar shunday boshlangan (bunday fikrlarni qaytarmang):\n" . implode("\n", $seen);
            }
        }
        $prompt .= I18n::directive($p['lang'] ?? 'uz');
        if ($attempt > 0) {
            $prompt .= "\n\nDIQQAT: oldingi urinishda natija yetarli bo'lmadi. Bu safar $target so'zga yaqin, mazmunli, misol va tushuntirishlar bilan to'liq yozing.";
        }

        $maxTokens = min(7000, (int)($target * 2.6) + 600);
        $raw = AIClient::generateText($prompt, self::system($p), $maxTokens);
        $text = self::cleanBody($raw);
        $words = countWords($text);

        $retry = null;
        if ($words < $target * 0.5) {
            $retry = new StepRetryException("Matn juda qisqa ($words/$target so'z)");
        } elseif ($role === 'chapter') {
            foreach (($state['res']['t'] ?? []) as $k => $prevText) {
                if ($k !== $key && preg_match('/^c(\d+)p/', $k, $mm) && (int)$mm[1] < (int)$step['c'] && textSimilarity($text, $prevText) > 0.5) {
                    $retry = new StepRetryException('Bob oldingi bob bilan juda o\'xshash');
                    break;
                }
            }
        }
        if ($words < 25) {
            throw new StepRetryException('AI bo\'sh yoki juda qisqa javob qaytardi');
        }
        if ($retry) {
            $retry->maxAttempts = 2;
            $retry->acceptAfterRetries = true;
            $retry->acceptFn = function (array &$st) use ($key, $text) {
                $st['res']['t'][$key] = $text;
            };
            throw $retry;
        }
        $state['res']['t'][$key] = $text;
    }

    /** AI javobidagi markdown va ortiqcha belgilarni tozalaydi. */
    public static function cleanBody(string $raw): string
    {
        $t = trim($raw);
        if ($t !== '' && ($t[0] === '{' || strpos($t, '"matn"') !== false)) {         // tasodifan JSON qaytarilgan bo'lsa
            $d = json_decode(extractJson($t), true);
            if (is_array($d)) {
                $parts = [];
                array_walk_recursive($d, function ($v) use (&$parts) {
                    if (is_string($v)) {
                        $parts[] = $v;
                    }
                });
                $t = implode("\n\n", $parts);
            }
        }
        $t = preg_replace('/```[a-z]*\n?|```/i', '', $t) ?? $t;
        $t = preg_replace('/^\s{0,3}#{1,6}\s*/m', '', $t) ?? $t;
        $t = preg_replace('/^\s*[-•*]\s+/mu', '• ', $t) ?? $t;            // ro'yxat punktlari DOCX'da haqiqiy ro'yxatga aylanadi
        $t = preg_replace('/(\*\*|__|\*|`)/', '', $t) ?? $t;
        $t = preg_replace("/^(Albatta|Mana|Quyida)[^\n]{0,80}:\s*\n/iu", '', $t) ?? $t;
        $t = preg_replace("/\r\n?/", "\n", $t) ?? $t;
        $t = preg_replace("/\n{3,}/", "\n\n", $t) ?? $t;
        return trim($t);
    }

    /** Deadline yaqin bo'lsa — keyingi tickda davom. */
    private static function needTime(int $sec): void
    {
        if (AIClient::$deadline !== null && microtime(true) > AIClient::$deadline - $sec) {
            throw new StepContinueException();
        }
    }

    /** Tezis: bitta so'rovda butun matn (sarlavhasiz ilmiy uslub). */
    private static function stepTezis(array &$state): void
    {
        $p = $state['p'];
        $target = (int)round($p['per'] * (float)($state['shrink']['tezis'] ?? 1.0));
        $prompt = "\"{$p['topic']}\" mavzusida ilmiy konferensiya uchun TEZIS yozing (taxminan $target so'z). Tuzilishi: mavzuning dolzarbligi, "
            . "tadqiqot maqsadi, asosiy fikrlar va natijalar, qisqa xulosa va taklif. 4-6 abzats, ilmiy-rasmiy uslubda, sarlavhalarsiz.";
        $raw = AIClient::generateText($prompt . I18n::directive($p['lang'] ?? 'uz'), self::system($p), min(5000, (int)($target * 2.6) + 600));
        $text = self::cleanBody($raw);
        if (countWords($text) < $target * 0.5) {
            $e = new StepRetryException('Tezis juda qisqa');
            $e->maxAttempts = 2;
            $e->acceptAfterRetries = true;
            $e->acceptFn = function (array &$st) use ($text) {
                $st['res']['t']['tezis'] = $text;
            };
            throw $e;
        }
        $state['res']['t']['tezis'] = $text;
    }

    /** Maqola: annotatsiya, kalit so'zlar va inglizcha nom — kirish va xulosa asosida (kichik so'rov). */
    private static function stepAbstract(array &$state): void
    {
        $p = $state['p'];
        $t = $state['res']['t'] ?? [];
        $intro = mb_substr((string)($t['intro'] ?? ''), 0, 900);
        $concl = mb_substr((string)($t['concl'] ?? ''), 0, 700);
        $prompt = "\"{$p['topic']}\" mavzusidagi ilmiy maqola uchun annotatsiya va kalit so'zlar tuzing.\nKirish: «{$intro}»\nXulosa: «{$concl}»\n"
            . 'JSON: {"annotatsiya":"60-90 so\'zli annotatsiya: maqsad, usul, natija","kalit":["5-7 ta kalit so\'z"],"en":"mavzuning inglizcha qisqa nomi"}'
            . I18n::directive($p['lang'] ?? 'uz');
        try {
            $d = json_decode(extractJson(AIClient::generateText($prompt, 'Faqat JSON formatda javob bering.', 900, ['temperature' => 0.4])), true) ?: [];
        } catch (AIUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            $d = [];
        }
        $state['res']['abstract'] = trim((string)($d['annotatsiya'] ?? ''));
        $kw = array_values(array_filter(array_map(fn($k) => trim((string)$k), (array)($d['kalit'] ?? []))));
        $state['res']['keywords'] = array_slice($kw, 0, 8);
        $en = cleanImageQuery((string)($d['en'] ?? ''));
        if ($en !== '') {
            $state['res']['en'] = $en;
        }
    }

    /** Haqiqiy adabiyotlar (Crossref). Topilmasa — umumiy ro'yxat qoladi. */
    private static function stepBiblio(array &$state): void
    {
        if (isset($state['res']['biblio']) || !Bibliography::enabled()) {
            return;
        }
        self::needTime(20);
        $en = (string)($state['res']['en'] ?? '');
        if ($en === '') {
            $en = ImageSearch::english((string)$state['p']['topic']);
        }
        $n = ['kurs_ishi' => 12, 'mustaqil_ish' => 8, 'maqola' => 10, 'referat' => 6, 'tezis' => 5][$state['p']['dtype'] ?? ''] ?? 8;
        $refs = [];
        try {
            $refs = Bibliography::find($en, $n);
        } catch (Throwable $e) {
            logError('steps', 'Adabiyotlar: ' . $e->getMessage());
        }
        $state['res']['biblio'] = count($refs) >= 3 ? $refs : [];
    }

    /** Hujjat uchun 1-3 rasm (boblar mavzusiga). */
    private static function stepDocImages(array &$state): void
    {
        $p = $state['p'];
        $max = min(DocTypes::images((string)($p['dtype'] ?? '')), count($state['res']['titles'] ?? []));
        for ($i = 0; $i < $max; $i++) {
            if (isset($state['res']['dimg'][$i])) {
                continue;
            }
            self::needTime(14);
            $title = preg_replace('/^\d+\.\s*/u', '', (string)$state['res']['titles'][$i]);
            $en = (string)($state['res']['rasm'][$i] ?? '');
            $path = null;
            try {
                $path = ImageSearch::fetchImage(mb_substr((string)$p['topic'], 0, 80) . ': ' . $title, true, $en);
            } catch (Throwable $e) {
                logError('steps', 'Hujjat rasmi: ' . $e->getMessage());
            }
            $state['res']['dimg'][$i] = $path ? ['path' => $path, 'cap' => mb_substr($title, 0, 120)] : null;
            if ($path) {
                $state['res']['tmp'][] = $path;
            }
        }
    }

    private static function stepBuildDoc(array $job, array &$state): void
    {
        $p = $state['p'];
        $lang = (string)($p['lang'] ?? 'uz');
        $t = $state['res']['t'] ?? [];
        $kind = (string)($p['kind'] ?? 'paper');
        $sections = [];
        $images = [];
        if ($kind === 'article' && !empty($state['res']['abstract'])) {
            $sections[] = ['heading' => I18n::t('annotation', $lang), 'text' => $state['res']['abstract']];
            if (!empty($state['res']['keywords'])) {
                $sections[] = ['heading' => I18n::t('keywords', $lang), 'text' => implode(', ', $state['res']['keywords'])];
            }
        }
        if (!empty($t['tezis'])) {
            $sections[] = ['heading' => '—', 'text' => $t['tezis']];
        }
        if (!empty($t['intro'])) {
            $sections[] = ['heading' => I18n::t('intro', $lang), 'text' => $t['intro']];
        }
        $imgN = 0;
        foreach (($state['res']['titles'] ?? []) as $i => $title) {
            $c = $i + 1;
            $subs = $state['res']['subs'][$i] ?? [];
            $texts = [];
            for ($j = 1; $j <= ($p['parts'] ?? 1); $j++) {
                $body = trim((string)($t["c{$c}p{$j}"] ?? ''));
                if ($body === '') {
                    continue;
                }
                $sub = $subs[$j - 1] ?? '';
                if ($sub !== '') {
                    // AI ba'zan band sarlavhasini o'zi ham yozib yuboradi — takrorlanmasin
                    $body = preg_replace('/^\s*' . preg_quote("{$c}.{$j}", '/') . '\.?\s*[^\n]{0,160}\n+/u', '', $body) ?? $body;
                    $texts[] = "{$c}.{$j}. {$sub}\n\n" . $body;
                } else {
                    $texts[] = $body;
                }
            }
            if (!$texts) {
                continue;
            }
            $img = $state['res']['dimg'][$i] ?? null;
            if ($img && is_file((string)$img['path'])) {
                $imgN++;
                $images[$imgN] = $img['path'];
                // rasm bobning birinchi abzatsidan keyin (band sarlavhasi bo'lsa — undan keyingi abzatsdan keyin)
                $first = $texts[0];
                $parts = preg_split("/\n\n/u", $first, 3);
                $at = ($subs && count($parts) >= 2) ? 2 : 1;
                $head = implode("\n\n", array_slice($parts, 0, $at));
                $tail = implode("\n\n", array_slice($parts, $at));
                $texts[0] = $head . "\n\n[RASM {$imgN}: {$img['cap']}]" . ($tail !== '' ? "\n\n" . $tail : '');
            }
            $sections[] = ['heading' => $title, 'text' => implode("\n\n", $texts)];
        }
        if (!empty($t['concl'])) {
            $sections[] = ['heading' => I18n::t('conclusion', $lang), 'text' => $t['concl']];
        }
        if (!$sections) {
            throw new RuntimeException('Hujjat uchun matn yo\'q');
        }
        $refs = $state['res']['biblio'] ?? [];
        if ($refs) {
            $sections[] = ['heading' => I18n::t('biblio', $lang), 'text' => Bibliography::sectionText($refs, $lang)];
        } else {
            $sections = DocxGenerator::withBiblio($sections, $lang);          // umumiy ro'yxat (manba topilmasa)
        }
        $state['res']['file'] = DocxGenerator::generate($p['topic'], $sections, $p['student'], $p['docType'], $lang, false, ['images' => $images]);
        try {                // tahrirlash uchun nusxa; xato bo'lsa ham hujjat yuboriladi
            $state['res']['pres'] = DocStore::createFromGenerated((int)$job['telegram_id'], $p['topic'], $p['docType'], $lang, $p['student'], $sections, $images);
        } catch (Throwable $e) {
            logError('steps', 'Hujjat nusxasi: ' . $e->getMessage());
        }
        unset($state['res']['t']);        // bazadagi holat kichik bo'lib qolsin
    }

    // ---- slayd ----

    private static function stepSlideBatch(array $step, array &$state): void
    {
        $outline = $state['res']['outline'] ?? [];
        $b = (int)$step['b'];
        $idx = array_values(array_filter(range($b * 4, $b * 4 + 3), fn($i) => isset($outline[$i])));
        if (!$idx) {
            return;
        }
        // Faqat hali matni olinmagan slaydlarni so'raymiz (qayta urinishda tayyorlari qayta yozilmaydi)
        $todo = array_values(array_filter($idx, fn($i) => empty($state['res']['ai'][$i])));
        if ($todo) {
            $res = generateSlideBatch($state['p']['topic'], $outline, $todo, (string)($state['p']['lang'] ?? 'uz'), isProDesign($state['p']['designKey'] ?? null));
            foreach ($res as $i => $item) {
                if ($item) {
                    $state['res']['ai'][$i] = $item;
                }
            }
            // To'plam javobi buzilsa/uzilsa (uzun JSON), yetishmagan slaydlarni BITTALAB so'raymiz — kichik so'rov ancha ishonchli
            foreach ($todo as $i) {
                if (!empty($state['res']['ai'][$i])) {
                    continue;
                }
                try {
                    $one = generateSlideBatch($state['p']['topic'], $outline, [$i], (string)($state['p']['lang'] ?? 'uz'), isProDesign($state['p']['designKey'] ?? null));
                    if (!empty($one[$i])) {
                        $state['res']['ai'][$i] = $one[$i];
                    }
                } catch (AIUnavailableException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    logError('steps', "Slayd #" . ($i + 1) . " matnini bittalab olishda xato: " . $e->getMessage());
                }
            }
        }
        $missing = count(array_filter($idx, fn($i) => empty($state['res']['ai'][$i])));
        if ($missing > 0) {
            $e = new StepRetryException("Slayd matni to'liq chiqmadi ($missing/" . count($idx) . " yetishmaydi)");
            $e->maxAttempts = 3;
            $e->acceptAfterRetries = true;
            $e->acceptFn = function (array &$st) {
            };
            throw $e;
        }
    }

    private static function stepSlideImages(array &$state): void
    {
        $outline = $state['res']['outline'] ?? [];
        $topic = (string)($state['p']['topic'] ?? '');
        $pro = isProDesign($state['p']['designKey'] ?? null);
        $want = [];                 // rasm olinadigan slaydlar (indekslar)
        if ($pro) {
            // PRO: muqova + imkon qadar ko'p slayd (tekis taqsimlab), ko'pi bilan PRO_MAX_IMAGES ta
            if (!isset($state['res']['cover'])) {
                if (AIClient::$deadline !== null && microtime(true) > AIClient::$deadline - 14) {
                    throw new StepContinueException();
                }
                $cp = null;
                try {
                    $cp = ImageSearch::fetchImage($topic, true, (string)($outline[0]['en_topic'] ?? ''));
                } catch (Throwable $e) {
                    logError('steps', 'Muqova rasmi: ' . $e->getMessage());
                }
                $state['res']['cover'] = $cp ?: '';
                if ($cp) {
                    $state['res']['tmp'][] = $cp;
                }
            }
            $elig = [];
            foreach ($outline as $i => $o) {
                if (($o['turi'] ?? 'matn') !== 'reja') {
                    $elig[] = $i;
                }
            }
            $max = max(1, (int)Engine::c('PRO_MAX_IMAGES', 6));
            if (count($elig) <= $max) {
                $want = $elig;
            } else {
                for ($k = 0; $k < $max; $k++) {
                    $want[] = $elig[(int)floor($k * count($elig) / $max)];
                }
            }
        } else {
            foreach ($outline as $i => $o) {
                if (in_array($o['turi'] ?? 'matn', ['matn', 'tarif'], true) && $i % 2 === 0) {
                    $want[] = $i;
                }
            }
        }
        foreach ($want as $i) {
            if (isset($state['res']['img'][$i])) {
                continue;
            }
            if (AIClient::$deadline !== null && microtime(true) > AIClient::$deadline - 14) {
                throw new StepContinueException();       // vaqt tugayapti — keyingi tickda davom
            }
            $o = $outline[$i];
            $q = ($o['sarlavha'] ?? '') ?: $topic;
            if ($pro && $topic !== '') {
                $q = mb_substr($topic, 0, 80) . ': ' . mb_substr((string)$q, 0, 60);
            }
            $en = (string)($o['rasm'] ?? '');
            $path = null;
            try {
                $path = ImageSearch::fetchImage($q, $pro, $en);
            } catch (Throwable $e) {
                logError('steps', 'Rasm: ' . $e->getMessage());
            }
            $state['res']['img'][$i] = $path ?: '';
            if ($path) {
                $state['res']['tmp'][] = $path;
            }
        }
    }

    private static function stepSlideBuild(array $job, array &$state): void
    {
        $p = $state['p'];
        $lang = I18n::norm($p['lang'] ?? null);
        $outline = $state['res']['outline'] ?? [];
        $slides = [];
        foreach ($outline as $i => $o) {
            $s = buildSlideFromAi($o, $state['res']['ai'][$i] ?? null);
            $img = $state['res']['img'][$i] ?? '';
            if ($img !== '' && is_file($img) && (in_array($s['kind'], ['matn', 'tarif'], true) || isProDesign($p['designKey'] ?? null))) {
                $s['image'] = $img;
            }
            $slides[] = $s;
        }
        if (hasRepeatedSlides(array_map(fn($s) => ['punktlar' => array_map(fn($q) => $q['text'], $s['points'])], $slides))) {
            logError('steps', "Diqqat: taqdimotda o'xshash slaydlar bor (topic: {$p['topic']}).");
        }
        $agendaItems = [];
        foreach ($slides as $s) {
            if ($s['kind'] !== 'xulosa') {
                $agendaItems[] = $s['title'];
            }
        }
        $agenda = ['kind' => 'reja', 'title' => I18n::t('agenda', $lang), 'items' => array_slice($agendaItems, 0, 8)];
        $info = [];
        if (!empty($p['student']['full_name'])) {
            $info[] = I18n::t('executor', $lang) . ': ' . $p['student']['full_name'];
        }
        if (!empty($p['student']['group_name'])) {
            $info[] = I18n::t('group', $lang) . ': ' . $p['student']['group_name'];
        }
        $isPro = isProDesign($p['designKey'] ?? null);
        $cover = (string)($state['res']['cover'] ?? '');
        $titleSlide = ['kind' => 'title', 'title' => $p['topic'], 'bullets' => $info];
        $thanksSlide = ['kind' => 'thanks', 'title' => I18n::t('thanks', $lang), 'bullets' => [I18n::t('thanks_sub', $lang)]];
        if ($isPro && $cover !== '' && is_file($cover)) {
            $titleSlide['image'] = $cover;
            $thanksSlide['image'] = $cover;
        }
        // Bo'lim ajratuvchi slaydlar (AI so'rovisiz; 10+ slaydli tariflarda, tarif oralig'i ichida)
        $sections = buildSections($outline, $slides, sectionCount(count($slides)));
        $body = [];
        $k = 0;
        foreach ($slides as $i => $sl) {
            if (isset($sections[$i])) {
                $k++;
                $body[] = ['kind' => 'bolim', 'title' => $sections[$i]['name'], 'lead' => sprintf('%02d', $k), 'items' => $sections[$i]['titles']];
            }
            $body[] = $sl;
        }
        $final = array_merge([$titleSlide, $agenda], $body, [$thanksSlide]);
        $design = pptxDesigns()[$p['designKey']] ?? pptxDesigns()[DEFAULT_PPTX_DESIGN];
        $design['lang'] = $lang;
        if ($isPro) {
            try {            // PRO (16:9, foto fon, effektlar, nutq matni); xato bo'lsa oddiy dizaynga o'tamiz — buyurtma yo'qolmasin
                $state['res']['file'] = ProDeck::generate($final, (string)$p['designKey'], $lang, (string)$p['topic']);
            } catch (Throwable $e) {
                logError('steps', 'PRO taqdimot qurilmadi, oddiy dizayn: ' . $e->getMessage());
                $isPro = false;
                $design = pptxDesigns()[DEFAULT_PPTX_DESIGN];
                $design['lang'] = $lang;
                $p['designKey'] = DEFAULT_PPTX_DESIGN;
            }
        }
        if (!$isPro) {
            $state['res']['file'] = PptxGenerator::generate($final, $design);
            try {            // tahrirlash uchun nusxa (rasmlar o'chirilishidan OLDIN); xato bo'lsa ham taqdimot yuboriladi
                $state['res']['pres'] = EditorStore::createFromGenerated((int)$job['telegram_id'], $p['topic'], $p['designKey'], $final, $lang);
            } catch (Throwable $e) {
                logError('steps', 'Tahrirlash nusxasi: ' . $e->getMessage());
            }
        }
        if (SpeechDoc::enabled()) {
            try {            // himoya uchun konspekt (AI'siz, tayyor matndan) — xato bo'lsa ham taqdimot yuboriladi
                $state['res']['extra'] = SpeechDoc::generate($final, (string)$p['topic'], $lang);
            } catch (Throwable $e) {
                logError('steps', 'Konspekt: ' . $e->getMessage());
            }
        }
        unset($state['res']['ai'], $state['res']['outline']);
    }

    /** Telegram albomi uchun 4 ta slayd rasmi (xato bo'lsa — jim o'tkaziladi, fayl baribir yuboriladi). */
    private static function stepSlidePreview(array &$state): void
    {
        if (isset($state['res']['pv'])) {
            return;
        }
        $file = (string)($state['res']['file'] ?? '');
        $state['res']['pv'] = [];
        if ($file === '' || !is_file($file)) {
            return;
        }
        $left = AIClient::$deadline !== null ? AIClient::$deadline - microtime(true) - 5 : 40;
        if ($left < 12) {
            unset($state['res']['pv']);
            throw new StepContinueException();
        }
        $z = new ZipArchive();
        $total = 0;
        if ($z->open($file) === true) {
            for ($i = 0; $i < $z->numFiles; $i++) {
                $total += (int)preg_match('#^ppt/slides/slide\d+\.xml$#', (string)$z->getNameIndex($i));
            }
            $z->close();
        }
        $pv = SlidePreview::make($file, SlidePreview::pick($total), !empty($state['res']['pres']) ? (string)$state['res']['pres'] : null, min(45, $left));
        $state['res']['pv'] = $pv;
        foreach ($pv as $f) {
            $state['res']['tmp'][] = $f;
        }
    }

    // ---- yuborish va yakun ----

    private static function stepDeliver(array $job, array &$state): void
    {
        $chatId = (int)$job['telegram_id'];
        $file = (string)($state['res']['file'] ?? '');
        if (!empty($state['res']['sent'])) {
            return;
        }
        if ($file === '' || !is_file($file)) {
            throw new RuntimeException('Tayyor fayl topilmadi');
        }
        $topic = Telegram::escape((string)$state['p']['topic']);
        $vols = DocTypes::volumes((string)$job['type']) ?? [];
        $label = $vols[$job['volume_key']]['label'] ?? '';
        $what = DocTypes::label((string)$job['type']);
        $real = !empty($state['res']['biblio']) ? "\n📚 Adabiyotlar ro'yxatida " . count($state['res']['biblio']) . " ta haqiqiy ilmiy manba (Crossref) bor." : '';
        $warn = $job['type'] === 'slayd'
            ? "⚠️ Matn AI yordamida yaratildi — himoyadan oldin mazmunini bir bor ko'zdan kechiring."
            : "⚠️ Matn AI yordamida yaratildi — faktlar va sanalarni topshirishdan oldin albatta o'zingiz tekshirib chiqing.";
        $caption = "✅ \"{$topic}\" mavzusidagi {$what} tayyor ($label).{$real}\n$warn";
        $kb = !empty($state['res']['pres']) ? ['inline_keyboard' => [[['text' => '✏️ Tahrirlash', 'callback_data' => 'edit:' . $state['res']['pres']]]]] : null;
        if (!empty($state['res']['pv']) && empty($state['res']['pv_sent'])) {
            $state['res']['pv_sent'] = true;          // albom bir marta (qayta urinishda takrorlanmaydi)
            try {
                SlidePreview::send($chatId, $state['res']['pv'], "👀 <b>Taqdimotingizdan lavhalar</b>\nTo'liq fayl — pastda 👇");
            } catch (Throwable $e) {
                logError('steps', 'Preview yuborilmadi: ' . $e->getMessage());
            }
        }
        if (!Telegram::sendDocument($chatId, $file, $caption, $kb)) {
            throw new RuntimeException('Fayl Telegram orqali yuborilmadi');
        }
        $state['res']['sent'] = true;
        $extra = (string)($state['res']['extra'] ?? '');
        if ($extra !== '' && is_file($extra) && empty($state['res']['sent2'])) {
            $state['res']['sent2'] = true;
            $fid = Telegram::$lastFileId;
            try {
                Telegram::sendDocument($chatId, $extra, "🎤 Himoya uchun konspekt: har slaydning asosiy fikrlari" . (isProDesign($state['p']['designKey'] ?? null) ? " va og'zaki nutq matni" : '') . ". Chop etib, himoyaga tayyorlanishda foydalaning.");
            } catch (Throwable $e) {
                logError('steps', 'Konspekt yuborilmadi: ' . $e->getMessage());
            }
            Telegram::$lastFileId = $fid;            // «Buyurtmalarim» uchun asosiy fayl id'si saqlansin
            @unlink($extra);
        }
        // «Buyurtmalarim» uchun: fayl keyinroq ham (server nusxasi o'chgach) Telegram'dan qayta yuborilishi mumkin
        if (Telegram::$lastFileId) {
            try {
                db()->prepare('UPDATE jobs SET tg_file_id = ?, tg_file_name = ? WHERE id = ?')
                    ->execute([Telegram::$lastFileId, basename($file), (int)$job['id']]);
            } catch (Throwable $e) {
                logError('steps', 'file_id saqlanmadi: ' . $e->getMessage());
            }
        }
    }

    /** Ish tugagach: baholash so'rovi va referal bonusi. */
    public static function afterDone(array $job, array $state): void
    {
        $jobId = (int)$job['id'];
        $chatId = (int)$job['telegram_id'];
        try {
            Telegram::sendMessage($chatId, "Natijani qanday baholaysiz?", ['inline_keyboard' => [[
                ['text' => '⭐', 'callback_data' => "rate:{$jobId}:1"],
                ['text' => '⭐⭐', 'callback_data' => "rate:{$jobId}:2"],
                ['text' => '⭐⭐⭐', 'callback_data' => "rate:{$jobId}:3"],
                ['text' => '⭐⭐⭐⭐', 'callback_data' => "rate:{$jobId}:4"],
                ['text' => '⭐⭐⭐⭐⭐', 'callback_data' => "rate:{$jobId}:5"],
            ]]]);
            maybeRewardReferrer($chatId, (bool)$job['is_free']);
        } catch (Throwable $e) {
            logError('steps', 'afterDone: ' . $e->getMessage());
        }
    }
}
