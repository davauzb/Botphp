<?php

/**
 * ICHKI UNIKALLIK: yangi ish bot avval yozgan barcha ishlar (oxirgi 500 ta) bilan solishtiriladi — 5 so'zli bo'laklar
 * (shingle) izi bo'yicha. Biror qism avvalgi ish bilan juda o'xshash chiqsa (masalan, shu mavzu boshqa talabaga ham
 * yozilgan bo'lsa), o'sha qism AI yordamida qayta ifodalanadi. Natija — «Unikallik: 96%» (bot bazasi bo'yicha).
 * ESLATMA: bu Antiplagiat.ru / Turnitin tekshiruvi EMAS — internetdagi manbalar bilan solishtirmaydi.
 * O'chirish: admin panel → Sozlamalar → «Unikallik tekshiruvi».
 */
class Uniq
{
    public const LIMIT = 0.30;          // qism shu ulushdan ko'p mos kelsa — qayta ifodalanadi

    public static function enabled(): bool
    {
        return (bool)Engine::c('UNIQ_CHECK', true);
    }

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS doc_fp (job_id INTEGER PRIMARY KEY, tg INTEGER NOT NULL, fp BLOB NOT NULL, ts INTEGER NOT NULL)');
    }

    /** @return array<int,true> shingle xeshlari (namunaviy: har 3-chisi) */
    public static function fp(string $text): array
    {
        $text = mb_strtolower(preg_replace('/^\s*\|.*$/mu', ' ', $text) ?? $text);
        $w = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        for ($i = 0, $n = count($w) - 4; $i < $n; $i++) {
            $h = crc32($w[$i] . ' ' . $w[$i + 1] . ' ' . $w[$i + 2] . ' ' . $w[$i + 3] . ' ' . $w[$i + 4]);
            if ($h % 3 === 0) {
                $out[$h] = true;
            }
        }
        return $out;
    }

    /** Bazadagi oxirgi izlar (bir marta yuklanadi). @return array<int,array<int,true>> job_id => fp */
    public static function corpus(int $excludeJob = 0): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            foreach (db()->query('SELECT job_id, fp FROM doc_fp ORDER BY job_id DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $arr = unpack('N*', (string)$r['fp']) ?: [];
                $cache[(int)$r['job_id']] = array_fill_keys($arr, true);
            }
        }
        unset($cache[$excludeJob]);
        return $cache;
    }

    /** Eng katta o'xshashlik ulushi (0..1): matn izining qanchasi bitta avvalgi ishda bor. */
    public static function maxOverlap(array $fp, array $corpus): float
    {
        $n = count($fp);
        if ($n < 15) {
            return 0.0;
        }
        $best = 0;
        foreach ($corpus as $other) {
            $c = count(array_intersect_key($fp, $other));
            if ($c > $best) {
                $best = $c;
            }
        }
        return $best / $n;
    }

    public static function store(int $jobId, int $tg, array $fp): void
    {
        if (!$fp) {
            return;
        }
        $keys = array_slice(array_keys($fp), 0, 6000);
        db()->prepare('INSERT OR REPLACE INTO doc_fp (job_id, tg, fp, ts) VALUES (?,?,?,?)')->execute([$jobId, $tg, pack('N*', ...$keys), time()]);
    }
}
