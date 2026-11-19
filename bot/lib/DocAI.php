<?php

require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/DocStore.php';

/**
 * Hujjat tahrirlovchisida BITTA bo'lim matnini AI bilan o'zgartirish (qayta yozish, qisqartirish, kengaytirish, soddalashtirish,
 * rasmiy uslub, xatolarni to'g'rilash yoki erkin ko'rsatma). Natija faqat qaytariladi — mijoz «Qo'llash» bosgandagina saqlanadi.
 */
class DocAI
{
    public const MODES = [
        'rewrite' => "Matnni BOSHQACHA so'zlar bilan qayta yozing: ma'nosi saqlansin, lekin jumlalar yangi, ravon va jonli bo'lsin. Hajmi taxminan o'sha-o'sha qolsin.",
        'shorter' => "Matnni QISQARTIRING (taxminan 35-45% qisqa): asosiy mazmun va ketma-ketlik saqlansin, ortiqcha takrorlar tushirilsin.",
        'longer' => "Matnni KENGAYTIRING (taxminan 35-45% uzunroq): har bir fikrga izoh, sabab-oqibat yoki umumiy misol qo'shing, lekin yangi aniq raqam/sana/ism o'ylab topmang.",
        'simpler' => "Matnni SODDALASHTIRING: murakkab atamalarni tushunarli so'zlar bilan izohlang, jumlalar qisqa va ravshan bo'lsin.",
        'formal' => "Matnni RASMIY-ILMIY uslubda qayta yozing: akademik ish talablariga mos, aniq, jiddiy va neytral ohangda.",
        'fix' => "Matndagi imlo, tinish belgilari, grammatika va uslub xatolarini TO'G'RILANG; mazmun va jumlalar tuzilishi iloji boricha o'zgarmasin.",
    ];

    public const MAX_INPUT = 12000;       // belgi — AI'ga shundan uzun bo'lim berilmaydi (xarajat va javob kesilishi)

    public static function enabled(): bool
    {
        return (bool)Engine::c('EDITOR_AI_ENABLED', true);
    }

    /** Qaytadi: ['ok'=>true,'text'=>...] yoki ['ok'=>false,'error'=>'mode'|'empty'|'too_long'|'busy'|'ai'|'bad_ai']. */
    public static function rewrite(string $heading, string $text, string $mode, string $note, string $topic, string $lang): array
    {
        $text = DocStore::clean($text, self::MAX_INPUT + 1, true);
        $heading = DocStore::clean($heading, 200);
        $note = DocStore::clean($note, 200);
        if ($text === '') {
            return ['ok' => false, 'error' => 'empty'];
        }
        if (mb_strlen($text) > self::MAX_INPUT) {
            return ['ok' => false, 'error' => 'too_long'];
        }
        if (!isset(self::MODES[$mode]) && $note === '') {
            return ['ok' => false, 'error' => 'mode'];
        }
        $instruction = $note !== ''
            ? "Foydalanuvchi ko'rsatmasi: «{$note}». " . (isset(self::MODES[$mode]) && $mode !== 'rewrite' ? self::MODES[$mode] : '')
            : self::MODES[$mode];

        $words = countWordsSimple($text);
        $prompt = 'Ish mavzusi: "' . DocStore::clean($topic, 200) . "\".\nBo'lim sarlavhasi: \"{$heading}\".\n\n"
            . "Quyida shu bo'limning joriy matni:\n<<<\n{$text}\n>>>\n\nVazifa: {$instruction}\n"
            . "Talablar:\n- Faqat YANGILANGAN bo'lim matnini qaytaring (sarlavhasiz, izohsiz, kirish gapsiz).\n"
            . "- Oddiy matn: markdown belgilari (#, *, **), ro'yxat belgilari va JSON BO'LMASIN. Abzatslar bo'sh qator bilan ajratilsin.\n"
            . "- Yangi aniq raqam, sana, ism yoki manba O'YLAB TOPMANG.\n"
            . I18n::directive($lang);

        try {
            AIClient::setDeadline(microtime(true) + 70);
            $raw = AIClient::generateText(
                $prompt,
                "Siz akademik hujjat matnini tahrirlovchi yordamchisiz. Faqat tayyor matnni yozing.",
                min(7000, (int)($words * 3.2) + 600),
                ['temperature' => 0.55]
            );
        } catch (AIUnavailableException $e) {
            return ['ok' => false, 'error' => 'busy'];
        } catch (Throwable $e) {
            logError('doc_ai', $e->getMessage());
            return ['ok' => false, 'error' => 'ai'];
        } finally {
            AIClient::setDeadline(null);
        }

        $out = self::cleanOutput($raw);
        if (countWordsSimple($out) < max(min(5, $words), (int)($words * 0.2))) {      // AI bo'sh yoki juda qisqa javob berdi
            return ['ok' => false, 'error' => 'bad_ai'];
        }
        return ['ok' => true, 'text' => DocStore::clean($out, DocStore::MAX_TEXT, true)];
    }

    private static function cleanOutput(string $raw): string
    {
        $t = trim($raw);
        $t = preg_replace('/```[a-z]*\n?|```/i', '', $t) ?? $t;
        $t = preg_replace('/^\s{0,3}#{1,6}\s*/m', '', $t) ?? $t;
        $t = preg_replace('/(\*\*|__|\*|`)/', '', $t) ?? $t;
        $t = preg_replace('/^\s*[-•]\s+/m', '', $t) ?? $t;
        $t = preg_replace('/^\s*<<<\s*|\s*>>>\s*$/u', '', $t) ?? $t;
        $t = preg_replace("/^(Albatta|Mana|Quyida|Конечно|Вот|Here is|Sure)[^\n]{0,80}:\s*\n/iu", '', $t) ?? $t;
        return trim($t);
    }
}
