<?php

/**
 * ♻️ QISMINI QAYTA YOZISH: tayyor ishning bitta bobini (hujjat) yoki bitta slaydini (taqdimot) mijoz tanlagan usulda qayta yozish.
 * Usullar: kengaytirish, soddalashtirish (qisqartirish), boshqacha yozish, o'z izohi bilan. Qolgan qismlar o'zgarmaydi.
 *
 * Oqim: fayl ostida «♻️ Qismini qayta yozish» → qism → usul → (izoh) → narx balansdan → 'qayta' buyurtma
 * (volume_key = "<asl ID>:<qism>:<usul>", requirements = izoh) → qr_write → qr_build → deliver (yangilangan to'liq fayl).
 * Ish tahrirlanadigan nusxa (DocStore/EditorStore) ustida bajariladi — tahrirlovchida ham yangi matn ko'rinadi.
 * Narx: Sozlamalar → «Qismini qayta yozish narxi» (standart 1 000 so'm). PRO taqdimotlar (tahrirlanadigan nusxasi yo'q) — qo'llanmaydi.
 */
class PartRewrite
{
    public const MODES = ['ken' => '📏 Kengaytirish', 'qis' => '✂️ Soddalashtirish', 'bosh' => '🔄 Boshqacha yozish', 'izoh' => "✍️ O'z izohim bilan"];

    public static function enabled(): bool
    {
        return (bool)Engine::c('PART_REWRITE_ENABLED', true);
    }

    public static function price(): int
    {
        return max(0, (int)Engine::c('PART_REWRITE_PRICE', 1000));
    }

    private static function job(int $id): ?array
    {
        $st = db()->prepare('SELECT * FROM jobs WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Tahrirlanadigan nusxa: ['kind'=>'doc'|'slides', 'store'=>array] yoki null. */
    private static function store(array $j): ?array
    {
        $pid = (string)($j['pres_id'] ?? '');
        if ($pid === '') {
            return null;
        }
        if ($j['type'] === 'slayd') {
            $p = EditorStore::get($pid);
            return $p ? ['kind' => 'slides', 'store' => $p] : null;
        }
        $d = DocStore::get($pid);
        return $d ? ['kind' => 'doc', 'store' => $d] : null;
    }

    /** Qayta yozish mumkin bo'lgan qismlar: [indeks => sarlavha]. */
    public static function parts(array $j): array
    {
        $s = self::store($j);
        if (!$s) {
            return [];
        }
        $out = [];
        if ($s['kind'] === 'doc') {
            $bib = mb_strtolower(I18n::t('biblio', (string)($j['lang'] ?: 'uz')));
            foreach ($s['store']['sections'] as $i => $sec) {
                $h = trim((string)$sec['heading']);
                if (mb_strtolower($h) === $bib || trim((string)$sec['text']) === '' || $h === I18n::t('keywords', (string)($j['lang'] ?: 'uz'))) {
                    continue;
                }
                $out[$i] = $h === '—' ? 'Asosiy matn' : $h;
            }
        } else {
            $n = 0;
            foreach ($s['store']['slides'] as $i => $sl) {
                $n++;
                $k = (string)($sl['sem']['kind'] ?? '');
                if (in_array($k, ['title', 'thanks', 'reja', 'bolim'], true) || empty($sl['sem']['points'])) {
                    continue;
                }
                $out[$i] = $n . '. ' . (string)($sl['sem']['title'] ?? 'Slayd');
            }
        }
        return $out;
    }

    public static function available(array $j): bool
    {
        return self::enabled() && $j['status'] === 'done' && !in_array($j['type'], ['tarjima', 'test'], true) && self::store($j) !== null;
    }

    public static function button(int $jobId): array
    {
        return ['text' => '♻️ Qismini qayta yozish', 'callback_data' => 'rp:' . $jobId];
    }

    // ------------------------------------------------------------------ bot: tugmalar

    public static function askPart(int $tg, int $jobId): void
    {
        $j = self::job($jobId);
        if (!$j || (int)$j['telegram_id'] !== $tg || !self::available($j)) {
            Telegram::sendMessage($tg, "Bu ishning qismlarini qayta yozib bo'lmaydi (tahrirlanadigan nusxasi yo'q).");
            return;
        }
        $rows = [];
        foreach (array_slice(self::parts($j), 0, 30, true) as $i => $t) {
            $rows[] = [['text' => mb_strimwidth($t, 0, 60, '…'), 'callback_data' => "rpp:$jobId:$i"]];
        }
        if (!$rows) {
            Telegram::sendMessage($tg, "Qayta yoziladigan qism topilmadi.");
            return;
        }
        $p = self::price();
        Telegram::sendMessage($tg, "♻️ <b>Qaysi qismini qayta yozamiz?</b>\nFaqat tanlangan qism o'zgaradi, qolganlari o'z holicha qoladi."
            . "\nNarxi: <b>" . ($p > 0 ? number_format($p, 0, '', ' ') . " so'm" : 'bepul') . "</b>", ['inline_keyboard' => $rows]);
    }

    public static function askMode(int $tg, int $jobId, int $idx): void
    {
        $j = self::job($jobId);
        if (!$j || (int)$j['telegram_id'] !== $tg || !isset(self::parts($j)[$idx])) {
            return;
        }
        $rows = [];
        foreach (self::MODES as $k => $label) {
            $rows[] = [['text' => $label, 'callback_data' => "rpm:$jobId:$idx:$k"]];
        }
        Telegram::sendMessage($tg, "«" . Telegram::escape(self::parts($j)[$idx]) . "» — qanday qayta yozamiz?", ['inline_keyboard' => $rows]);
    }

    /** Usul tanlandi: izoh kerak bo'lsa so'raydi, aks holda buyurtma. */
    public static function onMode(int $tg, int $jobId, int $idx, string $mode): void
    {
        if (!isset(self::MODES[$mode])) {
            return;
        }
        if ($mode === 'izoh') {
            setUserState($tg, "awaiting_rpnote:$jobId:$idx");
            Telegram::sendMessage($tg, "✍️ Nimani o'zgartirish kerak? Bitta xabarda yozing.\nMasalan: «ko'proq raqamlar va misollar qo'sh», «O'zbekiston tajribasini yoz».");
            return;
        }
        self::order($tg, $jobId, $idx, $mode, '');
    }

    public static function order(int $tg, int $jobId, int $idx, string $mode, string $note): void
    {
        $j = self::job($jobId);
        if (!$j || (int)$j['telegram_id'] !== $tg || !self::available($j) || !isset(self::parts($j)[$idx])) {
            Telegram::sendMessage($tg, "Bu so'rov eskirgan. Fayl ostidagi tugmadan qaytadan boshlang.");
            return;
        }
        $busy = db()->prepare("SELECT COUNT(*) FROM jobs WHERE telegram_id = ? AND type = 'qayta' AND status IN ('pending','processing')");
        $busy->execute([$tg]);
        if ((int)$busy->fetchColumn() > 0) {
            Telegram::sendMessage($tg, "⏳ Oldingi qayta yozish hali tugamadi — tugagach yana urinib ko'ring.");
            return;
        }
        $price = self::price();
        if ($price > 0) {
            $b = db()->prepare('UPDATE users SET balance = balance - ? WHERE telegram_id = ? AND balance >= ?');
            $b->execute([$price, $tg, $price]);
            if (!$b->rowCount()) {
                Telegram::sendMessage($tg, "⚠️ Balansingiz yetarli emas (kerak: " . number_format($price, 0, '', ' ') . " so'm). /balans orqali to'ldiring.");
                return;
            }
        }
        try {
            $student = json_decode((string)($j['student'] ?? ''), true);
            $id = Queue::createJob($tg, 'qayta', (string)$j['topic'], "$jobId:$idx:$mode", $price, false, true, $j['design_key'] ?: null, $j['lang'] ?: null, is_array($student) ? $student : null);
            db()->prepare('UPDATE jobs SET paid_by = ?, parent_id = ?, requirements = ? WHERE id = ?')
                ->execute([$price > 0 ? 'balance' : 'free', $jobId, $note !== '' ? mb_substr($note, 0, 1000) : null, $id]);
        } catch (Throwable $e) {
            if ($price > 0) {
                db()->prepare('UPDATE users SET balance = balance + ? WHERE telegram_id = ?')->execute([$price, $tg]);
            }
            logError('rewrite', $e->getMessage());
            Telegram::sendMessage($tg, "⚠️ Buyurtma yaratilmadi, pul qaytarildi.");
            return;
        }
        Engine::onJobPending($id, "♻️ Qayta yozish boshlandi: «" . Telegram::escape(self::parts($j)[$idx]) . "» · " . self::MODES[$mode]
            . ($price > 0 ? "  ·  💳 " . number_format($price, 0, '', ' ') . " so'm" : ''));
    }

    // ------------------------------------------------------------------ ish qadamlari

    public static function steps(): array
    {
        return [
            ['id' => 'qw', 'k' => 'qr_write', 'w' => 4.0, 'label' => 'Qism qayta yozilmoqda'],
            ['id' => 'qb', 'k' => 'qr_build', 'w' => 1.5, 'label' => 'Fayl yangilanmoqda'],
            ['id' => 'deliver', 'k' => 'deliver', 'w' => 1.0, 'label' => 'Telegram’ga yuborilmoqda'],
        ];
    }

    private static function parse(array $job): array
    {
        [$src, $idx, $mode] = array_pad(explode(':', (string)$job['volume_key']), 3, '');
        return [(int)$src, (int)$idx, $mode];
    }

    private static function instruction(string $mode, string $note, bool $slide): string
    {
        return match ($mode) {
            'ken' => $slide ? "Punktlarni kengaytiring: har biriga aniqroq tushuntirish va misol qo'shing (1-2 jumla)." : "Matnni taxminan 1,6 baravar KENGAYTIRING: yangi dalillar, aniq misollar va tushuntirishlar qo'shing.",
            'qis' => $slide ? "Punktlarni qisqaroq va soddaroq qiling — talaba oson tushuntira oladigan tilda." : "Matnni SODDALASHTIRING va taxminan 0,7 hajmgacha qisqartiring: oddiy, tushunarli tilda, asosiy fikrlarni saqlang.",
            'bosh' => "Mazmunini saqlagan holda BUTUNLAY BOSHQACHA yozing: boshqa so'zlar, gap tuzilishi va misollar bilan, hajm taxminan o'sha.",
            default => "Mijozning talabi bo'yicha qayta yozing: «" . $note . "»",
        };
    }

    public static function write(array $job, array &$state): void
    {
        if (isset($state['res']['new'])) {
            return;
        }
        [$srcId, $idx, $mode] = self::parse($job);
        $src = self::job($srcId);
        if (!$src || (int)$src['telegram_id'] !== (int)$job['telegram_id']) {
            throw new RuntimeException('Asl buyurtma topilmadi');
        }
        $s = self::store($src);
        if (!$s) {
            throw new RuntimeException('Tahrirlanadigan nusxa topilmadi');
        }
        $lang = (string)($job['lang'] ?: 'uz');
        $note = (string)($job['requirements'] ?? '');
        $topic = (string)$job['topic'];
        if ($s['kind'] === 'doc') {
            $sec = $s['store']['sections'][$idx] ?? null;
            if (!$sec) {
                throw new RuntimeException("Qism topilmadi");
            }
            $text = (string)$sec['text'];
            $words = max(80, count(preg_split('/\s+/u', trim($text)) ?: []));
            $target = (int)round($words * ($mode === 'ken' ? 1.6 : ($mode === 'qis' ? 0.7 : 1.0)));
            $prompt = "\"$topic\" mavzusidagi ishning «" . $sec['heading'] . "» qismini qayta yozing. " . self::instruction($mode, $note, false)
                . " Taxminan $target so'z. Qoidalar: «1.1. …» kabi band sarlavhasi qatorlari, «JADVAL:» va «|» bilan boshlanuvchi jadval qatorlari hamda "
                . "«[RASM n: …]» belgilarini O'ZGARTIRMASDAN joyida qoldiring. Faqat yangi matnni qaytaring, sarlavhasiz.\n\nHOZIRGI MATN:\n" . $text
                . I18n::directive($lang);
            $sys = "Siz ilmiy-akademik uslubda yozuvchi yordamchisiz. Faqat ishonchli ma'lumot yozing, raqam va manbalarni o'ylab topmang. Markdown ishlatmang.";
            $new = JobSteps::cleanBody(AIClient::generateText($prompt, $sys, min(9000, (int)($target * 2.8) + 600), ['temperature' => 0.7]));
            if (mb_strlen($new) < 0.35 * mb_strlen($text) && $mode !== 'qis') {
                throw new StepRetryException('Matn juda qisqa chiqdi');
            }
            $state['res']['new'] = self::keepBands($text, $new);
        } else {
            $sl = $s['store']['slides'][$idx] ?? null;
            if (!$sl) {
                throw new RuntimeException("Slayd topilmadi");
            }
            $sem = (array)$sl['sem'];
            $n = max(2, min(6, count((array)$sem['points'])));
            $cur = json_encode(['lead' => $sem['lead'] ?? '', 'points' => array_map(fn($p) => ['head' => $p['head'] ?? '', 'text' => $p['text'] ?? ''], (array)$sem['points'])], JSON_UNESCAPED_UNICODE);
            $prompt = "\"$topic\" taqdimotidagi «" . ($sem['title'] ?? '') . "» slaydini qayta yozing. " . self::instruction($mode, $note, true)
                . " Punktlar soni $n ta bo'lsin, har biri: qisqa sarlavha (2-5 so'z) va matn (1-2 jumla).\nHOZIRGI: $cur\n"
                . 'JSON: {"lead":"slayd uchun 1 jumlalik kirish (ixtiyoriy)","points":[{"head":"...","text":"..."}]}' . I18n::directive($lang);
            $d = json_decode(extractJson(AIClient::generateText($prompt, 'Faqat JSON formatda javob bering.', 2500, ['temperature' => 0.7])), true);
            $pts = [];
            foreach ((array)($d['points'] ?? []) as $p) {
                $t = trim((string)($p['text'] ?? ''));
                if ($t !== '') {
                    $pts[] = ['head' => trim((string)($p['head'] ?? '')), 'text' => $t, 'side' => ''];
                }
            }
            if (count($pts) < 2) {
                throw new StepRetryException('Slayd punktlari chiqmadi');
            }
            $state['res']['new'] = ['lead' => trim((string)($d['lead'] ?? ($sem['lead'] ?? ''))), 'points' => array_slice($pts, 0, 6)];
        }
    }

    /** Asl matndagi «1.1. …» band sarlavhalari yangi matnda yo'qolgan bo'lsa — paragraflarni bandlarga teng bo'lib, sarlavhalarni qaytaradi. */
    private static function keepBands(string $old, string $new): string
    {
        preg_match_all('/^\s*\d+\.\d+\.\s+[^\n]{2,160}$/mu', $old, $m);
        $heads = array_map('trim', $m[0]);
        if (count($heads) < 1) {
            return $new;
        }
        $have = 0;
        foreach ($heads as $h) {
            $have += mb_strpos($new, $h) !== false ? 1 : 0;
        }
        if ($have === count($heads)) {
            return $new;
        }
        $clean = trim(preg_replace('/^\s*\d+\.\d+\.\s+[^\n]{2,160}$/mu', '', $new) ?? $new);
        $paras = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/u', $clean) ?: []), fn($x) => $x !== ''));
        if (!$paras) {
            return $new;
        }
        $per = (int)max(1, ceil(count($paras) / count($heads)));
        $out = [];
        foreach ($heads as $i => $h) {
            $chunk = array_slice($paras, $i * $per, $per);
            if (!$chunk && $i > 0) {
                continue;
            }
            $out[] = $h . "\n\n" . implode("\n\n", $chunk);
        }
        return implode("\n\n", $out);
    }

    public static function build(array $job, array &$state): void
    {
        if (isset($state['res']['file'])) {
            return;
        }
        [$srcId, $idx] = self::parse($job);
        $src = self::job($srcId);
        $s = $src ? self::store($src) : null;
        if (!$s) {
            throw new RuntimeException('Tahrirlanadigan nusxa topilmadi');
        }
        $tg = (int)$job['telegram_id'];
        if ($s['kind'] === 'doc') {
            $d = $s['store'];
            $sections = $d['sections'];
            $sections[$idx]['text'] = (string)$state['res']['new'];
            $r = DocStore::save((string)$d['id'], $tg, (int)$d['version'], ['title' => $d['title'], 'student' => $d['student'], 'sections' => $sections]);
            if (empty($r['ok'])) {
                throw new RuntimeException('Hujjat saqlanmadi: ' . ($r['error'] ?? ''));
            }
            $state['res']['file'] = DocStore::exportDocx(DocStore::get((string)$d['id']));
        } else {
            $p = $s['store'];
            $slides = $p['slides'];
            $sl = $slides[$idx];
            $sem = array_merge((array)$sl['sem'], (array)$state['res']['new']);
            $order = array_map(fn($x) => (string)$x['id'], $slides);
            $re = EditorStore::regen((string)$p['id'], $sem, (string)($p['design']['key'] ?? DEFAULT_PPTX_DESIGN), $idx + 1, count($slides));
            $changed = [(string)$sl['id'] => ['id' => (string)$sl['id'], 'mode' => 'auto', 'tr' => $sl['tr'] ?? 'none', 'sem' => $re['sem'], 'els' => $re['els']]];
            $r = EditorStore::save((string)$p['id'], $order, $changed, null, null, (int)$p['version']);
            if (empty($r['ok'])) {
                throw new RuntimeException('Taqdimot saqlanmadi: ' . ($r['error'] ?? ''));
            }
            [$path, $errs] = EditorStore::exportFile((string)$p['id']);
            if (!$path) {
                throw new RuntimeException('Eksport xatosi: ' . implode('; ', $errs));
            }
            $state['res']['file'] = $path;
        }
        $state['res']['pres'] = (string)$src['pres_id'];
        unset($state['res']['new']);
    }
}
