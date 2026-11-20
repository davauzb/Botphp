<?php

require_once __DIR__ . '/I18n.php';

/**
 * Tahrirlovchida BITTA slaydni AI bilan qayta yozish.
 *
 * Slayd mazmuni (sem) JSON ko'rinishida AI'ga beriladi, AI shu tuzilmani saqlagan holda matnni o'zgartiradi
 * (qayta yozish / qisqartirish / kengaytirish / soddalashtirish / rasmiy uslub / xatolarni to'g'rilash yoki erkin ko'rsatma).
 * Natija PptxElements::sanitizeSem orqali tozalanadi — AI javobi hech qachon XML'ga "xom" holda tushmaydi.
 * Maket turi, rasm va punktlar tartibi saqlanadi; yangi maket (els) mijoz «Qo'llash» bosganda mavjud `regen` orqali quriladi.
 */
class EditorAI
{
    public const MODES = [
        'rewrite' => "Matnni BOSHQACHA so'zlar bilan qayta yozing: ma'nosi saqlansin, lekin jumlalar yangi, ravon va jonli bo'lsin.",
        'shorter' => "Matnni QISQARTIRING: har bir fikrni ixchamroq ifodalang (taxminan 30-40% qisqa), asosiy mazmun yo'qolmasin.",
        'longer' => "Matnni KENGAYTIRING: har bir punktga qisqa misol yoki izoh qo'shing (taxminan 30-40% uzunroq), lekin tarqoq bo'lmasin.",
        'simpler' => "Matnni SODDALASHTIRING: murakkab atamalarni oddiy so'zlar bilan tushuntiring, jumlalar qisqa va tushunarli bo'lsin.",
        'formal' => "Matnni RASMIY-ILMIY uslubda qayta yozing: akademik taqdimotga mos, aniq va jiddiy ohangda.",
        'fix' => "Matndagi imlo, grammatika va uslub xatolarini TO'G'RILANG; mazmun va jumlalar iloji boricha o'zgarmasin.",
    ];

    /** Qayta yozish mumkin bo'lgan maket turlari (reja/muqova/yakun — qisqa servis slaydlari, AI shart emas). */
    public const KINDS = ['matn', 'tarif', 'kartalar', 'bosqichlar', 'xronologiya', 'taqqoslash', 'xulosa'];

    public static function enabled(): bool
    {
        return (bool)Engine::c('EDITOR_AI_ENABLED', true);
    }

    private static function clean(string $s, int $max): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
        $s = preg_replace('/\s+/u', ' ', $s) ?? '';
        return trim(function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max));
    }

    /**
     * Qaytadi: ['ok'=>true,'sem'=>[...]] yoki ['ok'=>false,'error'=>'kind'|'busy'|'ai'|'bad_ai'|'mode'].
     */
    public static function rewrite(array $semRaw, string $mode, string $note, string $topic, string $lang): array
    {
        $sem = PptxElements::sanitizeSem($semRaw);
        if (!in_array($sem['kind'], self::KINDS, true) || !$sem['points']) {
            return ['ok' => false, 'error' => 'kind'];
        }
        $note = self::clean($note, 200);
        if (!isset(self::MODES[$mode]) && $note === '') {
            return ['ok' => false, 'error' => 'mode'];
        }
        $instruction = $note !== ''
            ? "Foydalanuvchi ko'rsatmasi: «{$note}». " . (isset(self::MODES[$mode]) && $mode !== 'rewrite' ? self::MODES[$mode] : '')
            : self::MODES[$mode];

        $cur = ['sarlavha' => $sem['title'], 'izoh' => $sem['lead'], 'punktlar' => []];
        foreach ($sem['points'] as $p) {
            $row = ['bosh' => $p['head'], 'matn' => $p['text']];
            if ($sem['kind'] === 'taqqoslash') {
                $row['tomon'] = in_array($p['side'], ['ong', 'right'], true) ? 'ong' : 'chap';
            }
            $cur['punktlar'][] = $row;
        }
        if ($sem['kind'] === 'taqqoslash') {
            $cur['chap_nomi'] = $sem['left_title'];
            $cur['ong_nomi'] = $sem['right_title'];
        }
        $n = count($sem['points']);
        $prompt = 'Taqdimot mavzusi: "' . self::clean($topic, 200) . "\".\nQuyida taqdimotning BITTA slaydi JSON ko'rinishida berilgan:\n"
            . json_encode($cur, JSON_UNESCAPED_UNICODE) . "\n\nVazifa: " . $instruction . "\n"
            . "Talablar:\n- JSON tuzilmasini AYNAN saqlang: bir xil kalitlar va ANIQ {$n} ta punkt, tartibi o'sha-o'sha"
            . ($sem['kind'] === 'taqqoslash' ? ", har bir punktning \"tomon\" qiymati (chap/ong) o'zgarmasin" : '') . ".\n"
            . "- Yangi raqam, sana, ism yoki fakt O'YLAB TOPMANG.\n"
            . "- Sarlavha qisqa (2-7 so'z) bo'lsin; punkt \"bosh\" i 2-5 so'z.\n"
            . "- Faqat shu JSON obyektini qaytaring, boshqa hech narsa yozmang."
            . I18n::directive($lang);

        try {
            AIClient::setDeadline(microtime(true) + 55);
            $raw = AIClient::generateText(
                $prompt,
                "Siz taqdimot matnini tahrirlovchi yordamchisiz. Faqat JSON formatda javob bering.",
                min(4000, 700 + $n * 350),
                ['temperature' => 0.6]
            );
        } catch (AIUnavailableException $e) {
            return ['ok' => false, 'error' => 'busy'];
        } catch (Throwable $e) {
            logError('editor_ai', $e->getMessage());
            return ['ok' => false, 'error' => 'ai'];
        } finally {
            AIClient::setDeadline(null);
        }

        $a = strpos($raw, '{');
        $b = strrpos($raw, '}');
        $data = ($a !== false && $b !== false && $b > $a) ? json_decode(substr($raw, $a, $b - $a + 1), true) : null;
        if (!is_array($data) || !is_array($data['punktlar'] ?? null)) {
            return ['ok' => false, 'error' => 'bad_ai'];
        }
        $items = array_values(array_filter($data['punktlar'], 'is_array'));
        if (count($items) < max(2, $n - 1)) {      // AI punktlarni tashlab yuborgan — natijani qabul qilmaymiz
            return ['ok' => false, 'error' => 'bad_ai'];
        }

        $out = $sem;
        $title = trim((string)($data['sarlavha'] ?? ''));
        $out['title'] = $title !== '' ? $title : $sem['title'];
        if ($sem['lead'] !== '' || trim((string)($data['izoh'] ?? '')) !== '') {
            $lead = trim((string)($data['izoh'] ?? ''));
            $out['lead'] = $lead !== '' ? $lead : $sem['lead'];
        }
        $pts = [];
        foreach ($sem['points'] as $i => $old) {
            $it = $items[$i] ?? null;
            $head = $it ? trim((string)($it['bosh'] ?? $it['sarlavha'] ?? '')) : '';
            $text = $it ? trim((string)($it['matn'] ?? '')) : '';
            if ($head === '' && $text === '') {      // shu punkt bo'sh keldi — eskisi qoladi
                $pts[] = $old;
                continue;
            }
            $pts[] = ['head' => $head !== '' ? $head : $old['head'], 'text' => $text !== '' ? $text : $old['text'], 'side' => $old['side']];
        }
        $out['points'] = $pts;
        if ($sem['kind'] === 'taqqoslash') {
            $l = trim((string)($data['chap_nomi'] ?? ''));
            $r = trim((string)($data['ong_nomi'] ?? ''));
            $out['left_title'] = $l !== '' ? $l : $sem['left_title'];
            $out['right_title'] = $r !== '' ? $r : $sem['right_title'];
        }
        return ['ok' => true, 'sem' => PptxElements::sanitizeSem($out)];
    }
}
