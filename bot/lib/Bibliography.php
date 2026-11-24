<?php

/**
 * HAQIQIY adabiyotlar ro'yxati — Crossref ochiq ilmiy bazasidan (kalit kerak emas, bepul).
 *
 * Mavzu (inglizcha ibora) bo'yicha haqiqatda mavjud maqola va kitoblar topiladi, sarlavhasi mavzuga mosligi tekshiriladi,
 * ko'p iqtibos qilinganlari oldinga chiqadi va O'zbekiston OTMlarida qabul qilingan (GOST'ga yaqin) shaklda yoziladi:
 *   Ouyang F., Jiao P. Artificial intelligence in education: The three paradigms // Computers and Education: Artificial
 *   Intelligence. – 2021. – Vol. 2. – P. 100020. – DOI: 10.1016/j.caeai.2021.100020
 *
 * Hech narsa topilmasa yoki tarmoq ishlamasa — bo'sh massiv (bot umumiy ro'yxat bilan davom etadi).
 * Natija 30 kun keshlanadi (bir xil mavzuga qayta so'rov yuborilmaydi).
 * O'chirish: config.php → define('REAL_BIBLIO', false);
 */
class Bibliography
{
    private const STOP = ['the', 'and', 'for', 'with', 'from', 'into', 'its', 'their', 'role', 'use', 'using', 'based', 'study', 'analysis',
        'modern', 'development', 'features', 'impact', 'importance', 'issues', 'problems', 'prospects', 'current', 'state', 'case', 'new', 'some', 'about'];

    public static function enabled(): bool
    {
        return (bool)Engine::c('REAL_BIBLIO', true) && function_exists('curl_init');
    }

    /** @return string[] tayyor yozuvlar (raqamsiz) */
    public static function find(string $enQuery, int $limit = 8, int $timeout = 12): array
    {
        $enQuery = trim(preg_replace('/\s+/u', ' ', $enQuery) ?? '');
        if (!self::enabled() || $enQuery === '' || preg_match('/[\x{0400}-\x{04FF}]/u', $enQuery)) {
            return [];
        }
        $ck = 'bib_' . md5(mb_strtolower($enQuery) . '|' . $limit);
        try {
            $st = db()->prepare('SELECT v FROM engine_kv WHERE k = ? AND ts > ?');
            $st->execute([$ck, time() - 30 * 86400]);
            $hit = $st->fetchColumn();
            if (is_string($hit) && ($arr = json_decode($hit, true)) && is_array($arr)) {
                return $arr;
            }
        } catch (Throwable $e) {
        }

        $items = self::crossref($enQuery, $timeout);
        $words = self::keywords($enQuery);
        $scored = [];
        $seen = [];
        foreach ($items as $it) {
            $title = trim(strip_tags(html_entity_decode((string)($it['title'][0] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $title = preg_replace('/\s+/u', ' ', $title) ?? $title;
            if (preg_match('/\p{Lu}{4}/u', $title) && $title === mb_strtoupper($title)) {      // BUTUNLAY KATTA HARFLI sarlavha → Oddiy yozuv
                $title = mb_strtoupper(mb_substr($title, 0, 1)) . mb_strtolower(mb_substr($title, 1));
                $title = preg_replace_callback('/\b(uzbekistan|republic|tashkent|samarkand|aral|covid)\b/u', fn($m) => mb_convert_case($m[1], MB_CASE_TITLE), $title) ?? $title;
            }
            if (mb_strlen($title) < 12 || mb_strlen($title) > 260) {
                continue;
            }
            $key = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $title) ?? $title);
            if (isset($seen[$key])) {
                continue;
            }
            $authors = self::authors($it['author'] ?? []);
            $year = (int)($it['issued']['date-parts'][0][0] ?? 0);
            if ($authors === '' || $year < 1990 || $year > (int)date('Y') + 1) {
                continue;
            }
            // mavzuga moslik: sarlavhada kalit so'zlardan kamida yarmi (va kamida bittasi) bo'lsin
            $tl = mb_strtolower($title);
            $hits = 0;
            foreach ($words as $w) {
                if (mb_strpos($tl, $w) !== false) {
                    $hits++;
                }
            }
            if (!$words || $hits < max(1, (int)ceil(count($words) / 2))) {
                continue;
            }
            $seen[$key] = true;
            $cites = (int)($it['is-referenced-by-count'] ?? 0);
            $score = $hits * 10 + log(1 + $cites) * 3 + ($year >= date('Y') - 8 ? 4 : 0);
            $scored[] = [$score, $year, self::format($it, $title, $authors, $year)];
        }
        usort($scored, fn($a, $b) => $b[0] <=> $a[0]);
        $top = array_slice($scored, 0, $limit);
        usort($top, fn($a, $b) => strcmp($a[2], $b[2]));             // alifbo tartibida (OTM talabi)
        $out = array_map(fn($x) => $x[2], $top);
        if ($out) {
            try {
                db()->prepare('INSERT INTO engine_kv (k, v, ts) VALUES (?, ?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v, ts = excluded.ts')
                    ->execute([$ck, json_encode($out, JSON_UNESCAPED_UNICODE), time()]);
            } catch (Throwable $e) {
            }
        }
        return $out;
    }

    /** Bibliografiya bo'limi matni: haqiqiy manbalar + rasmiy elektron resurslar (raqamlangan). */
    public static function sectionText(array $refs, string $lang = 'uz'): string
    {
        $lines = [];
        $n = 1;
        foreach ($refs as $r) {
            $lines[] = $n++ . '. ' . $r;
        }
        $res = [
            'uz' => ['Ziyonet — axborot-ta\'lim portali. – URL: https://www.ziyonet.uz', 'Google Scholar — ilmiy maqolalar qidiruv tizimi. – URL: https://scholar.google.com'],
            'ru' => ['Ziyonet — информационно-образовательный портал. – URL: https://www.ziyonet.uz', 'Google Scholar — поисковая система научных публикаций. – URL: https://scholar.google.com'],
            'en' => ['Ziyonet — educational information portal. – URL: https://www.ziyonet.uz', 'Google Scholar — search engine for scholarly literature. – URL: https://scholar.google.com'],
        ][$lang] ?? [];
        foreach ($res as $r) {
            $lines[] = $n++ . '. ' . $r;
        }
        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------ ichki

    private static function keywords(string $q): array
    {
        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q)) ?: [] as $w) {
            if (mb_strlen($w) >= 4 && !in_array($w, self::STOP, true)) {
                $out[] = mb_strlen($w) > 6 ? mb_substr($w, 0, mb_strlen($w) - 2) : $w;   // oddiy "o'zak" (education/educational)
            }
        }
        return array_slice(array_values(array_unique($out)), 0, 5);
    }

    private static function crossref(string $q, int $timeout): array
    {
        $url = 'https://api.crossref.org/works?' . http_build_query([
            'query.bibliographic' => $q,
            'rows' => 30,
            'select' => 'title,author,issued,container-title,publisher,type,DOI,is-referenced-by-count,page,volume,issue',
            'filter' => 'type:journal-article,type:book,type:monograph,type:book-chapter,type:proceedings-article,from-pub-date:1995',
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT => 'StudyBot/1.0 (academic reference helper)', CURLOPT_FOLLOWLOCATION => true]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !is_string($body)) {
            logError('biblio', "Crossref javobi: HTTP $code");
            return [];
        }
        $d = json_decode($body, true);
        return is_array($d['message']['items'] ?? null) ? $d['message']['items'] : [];
    }

    /** «Familiya I.O., Familiya I.» — ko'pi bilan 3 muallif, ko'p bo'lsa «va boshq.» (et al.). */
    private static function authors(array $list): string
    {
        $names = [];
        foreach ($list as $a) {
            $fam = trim((string)($a['family'] ?? ''));
            if (mb_strlen($fam) < 2 || mb_strlen($fam) > 40) {
                continue;
            }
            $ini = '';
            foreach (preg_split('/[\s\-]+/u', trim((string)($a['given'] ?? ''))) ?: [] as $g) {
                if ($g !== '') {
                    $ini .= mb_strtoupper(mb_substr($g, 0, 1)) . '.';
                }
            }
            $names[] = mb_convert_case($fam, MB_CASE_TITLE) . ($ini !== '' ? ' ' . $ini : '');
        }
        if (!$names) {
            return '';
        }
        return count($names) > 3 ? implode(', ', array_slice($names, 0, 3)) . ' et al.' : implode(', ', $names);
    }

    private static function format(array $it, string $title, string $authors, int $year): string
    {
        $type = (string)($it['type'] ?? '');
        $cont = trim(strip_tags((string)($it['container-title'][0] ?? '')));
        $pub = trim((string)($it['publisher'] ?? ''));
        $title = rtrim($title, '. ');
        if (in_array($type, ['book', 'monograph'], true)) {
            $s = $authors . ' ' . $title . '. –' . ($pub !== '' ? ' ' . $pub . ',' : '') . ' ' . $year . '.';
        } else {
            $s = $authors . ' ' . $title . ($cont !== '' ? ' // ' . rtrim($cont, '. ') . '.' : '.');
            $s .= ' – ' . $year . '.';
            if (!empty($it['volume'])) {
                $s .= ' – Vol. ' . $it['volume'] . (!empty($it['issue']) ? ', № ' . $it['issue'] : '') . '.';
            }
            if (!empty($it['page'])) {
                $s .= ' – P. ' . str_replace('-', '–', (string)$it['page']) . '.';
            }
        }
        if (!empty($it['DOI'])) {
            $s .= ' – DOI: ' . $it['DOI'];
        }
        return preg_replace('/\s+/u', ' ', $s) ?? $s;
    }
}
