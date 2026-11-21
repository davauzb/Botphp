<?php

/**
 * HIMOYA SAVOLLARI: komissiya berishi mumkin bo'lgan 10 ta savol va qisqa javob (bitta kichik AI so'rovi).
 * Taqdimotda — konspektga qo'shiladi; hujjatlarda — alohida «Himoya savollari» DOCX bo'lib keladi.
 * O'chirish: admin panel → Sozlamalar → «Himoya savollari» (yoki config.php → define('DEFENSE_QA', false)).
 */
class Defense
{
    public static function enabled(): bool
    {
        return (bool)Engine::c('DEFENSE_QA', true);
    }

    /** @return array<int,array{q:string,a:string}> */
    public static function make(string $topic, array $titles, string $lang, string $what): array
    {
        $list = implode("\n", array_map(fn($t) => '- ' . preg_replace('/^\d+[\.\)]\s*/u', '', (string)$t), array_slice($titles, 0, 14)));
        $prompt = "\"$topic\" mavzusidagi {$what} himoyasida komissiya (o'qituvchi) berishi mumkin bo'lgan 10 ta savol va har biriga "
            . "qisqa, aniq javob (35-60 so'z) tuzing. Savollar ishning tuzilishiga mos va har xil turda bo'lsin: tushuncha, sabab, misol, "
            . "taqqoslash, amaliy ahamiyat, xulosa. Ish tuzilishi:\n$list\n"
            . 'JSON: {"savollar":[{"s":"savol?","j":"javob"}]}' . I18n::directive($lang);
        $raw = AIClient::generateText($prompt, "Faqat JSON formatda javob bering.", 2600, ['temperature' => 0.5]);
        $d = json_decode(extractJson($raw), true);
        $out = [];
        foreach ((array)($d['savollar'] ?? []) as $x) {
            $q = trim((string)($x['s'] ?? ''));
            $a = trim((string)($x['j'] ?? ''));
            if ($q !== '' && $a !== '') {
                $out[] = ['q' => mb_substr($q, 0, 300), 'a' => mb_substr($a, 0, 900)];
            }
        }
        return array_slice($out, 0, 12);
    }
}
