<?php

require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/DocxGenerator.php';
require_once __DIR__ . '/EditorStore.php';      // validPid() — editor/index.php faqat shu faylni yuklaydi

/**
 * Tahrirlanadigan HUJJATLAR (mustaqil ish, referat): bo'limlar matni bazada saqlanadi, mijoz Telegram WebApp'da tahrirlaydi,
 * so'ng yangi DOCX (yoki PDF) qilib chatga yuboriladi.
 *
 * Xavfsizlik: mijozdan kelgan HAR BIR qator sanitize() dan o'tadi (uzunlik chegaralari, boshqaruv belgilari olib tashlanadi);
 * DOCX generatori matnni XML'ga ekranlab yozadi — xom satr hech qachon XML'ga tushmaydi. Egalik (telegram_id) har so'rovda tekshiriladi.
 */
class DocStore
{
    public const MAX_SECTIONS = 40;
    public const MAX_HEADING = 200;
    public const MAX_TEXT = 60000;        // bitta bo'lim
    public const MAX_TOTAL = 400000;      // butun hujjat (belgi)
    public const TYPES = ['MUSTAQIL ISH', 'REFERAT', 'KURS ISHI', 'MAQOLA', 'TEZIS', 'ESSE', 'LABORATORIYA ISHI', 'AMALIYOT HISOBOTI'];

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS documents (
            id TEXT PRIMARY KEY, telegram_id INTEGER NOT NULL, title TEXT, doc_type TEXT, lang TEXT, student TEXT, data TEXT NOT NULL,
            version INTEGER NOT NULL DEFAULT 1, created_at INTEGER, updated_at INTEGER, exported_at INTEGER)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_docs_user ON documents(telegram_id, created_at)');
    }

    public static function enabled(): bool
    {
        return EditorSecurity::enabled() && class_exists('ZipArchive');
    }

    // ------------------------------------------------------------------ sanitize

    public static function clean($v, int $max, bool $multiline = false): string
    {
        if (!is_scalar($v)) {
            return '';
        }
        $s = (string)$v;
        if (!preg_match('//u', $s)) {                       // yaroqsiz UTF-8 — buzuq baytlarni olib tashlaymiz
            $s = (string)@iconv('UTF-8', 'UTF-8//IGNORE', $s);
        }
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{FFFE}\x{FFFF}]/u', '', $s) ?? '';
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        if ($multiline) {
            $s = preg_replace("/[ \t]+\n/u", "\n", $s) ?? $s;
            $s = preg_replace("/\n{3,}/u", "\n\n", $s) ?? $s;
        } else {
            $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        }
        $s = trim($s);
        return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
    }

    /** @return array{title:string, student:array, sections:array} */
    public static function sanitize(array $in): array
    {
        $st = is_array($in['student'] ?? null) ? $in['student'] : [];
        $student = [
            'full_name' => self::clean($st['full_name'] ?? '', 120),
            'university' => self::clean($st['university'] ?? '', 160),
            'group_name' => self::clean($st['group_name'] ?? '', 60),
        ];
        $sections = [];
        $total = 0;
        foreach (array_slice(array_values(is_array($in['sections'] ?? null) ? $in['sections'] : []), 0, self::MAX_SECTIONS) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $heading = self::clean($s['heading'] ?? '', self::MAX_HEADING);
            $text = self::clean($s['text'] ?? '', self::MAX_TEXT, true);
            if ($heading === '' && $text === '') {
                continue;
            }
            $total += mb_strlen($heading) + mb_strlen($text);
            if ($total > self::MAX_TOTAL) {
                break;
            }
            $sections[] = ['heading' => $heading !== '' ? $heading : '—', 'text' => $text];
        }
        return ['title' => self::clean($in['title'] ?? '', 300), 'student' => $student, 'sections' => $sections];
    }

    public static function stats(array $sections): array
    {
        $words = 0;
        foreach ($sections as $s) {
            $words += countWordsSimple((string)($s['text'] ?? ''));
        }
        return ['words' => $words, 'pages' => max(1, (int)ceil($words / 250))];
    }

    // ------------------------------------------------------------------ yaratish (worker chaqiradi)

    /** Yaratilgan hujjatdan tahrirlanadigan nusxa. Xato bo'lsa null (hujjat baribir yuboriladi). */
    /** Hujjat rasmlari papkasi (matndagi «[RASM n: …]» qatorlari uchun). */
    private static function imgDir(): string
    {
        return rtrim(EDITOR_STORAGE_DIR, '/') . '/docimg/';
    }

    /** Hujjatga tegishli rasmlar: [raqam => yo'l]. */
    public static function images(string $pid): array
    {
        $out = [];
        if (!EditorStore::validPid($pid)) {
            return $out;
        }
        foreach (glob(self::imgDir() . $pid . '_*.{jpg,png}', GLOB_BRACE) ?: [] as $f) {
            if (preg_match('/_(\d+)\.(jpg|png)$/', $f, $m)) {
                $out[(int)$m[1]] = $f;
            }
        }
        return $out;
    }

    public static function createFromGenerated(int $tgId, string $title, string $docType, string $lang, array $student, array $sections, array $images = []): ?string
    {
        try {
            if (!self::enabled()) {
                return null;
            }
            $san = self::sanitize(['title' => $title, 'student' => $student, 'sections' => $sections]);
            if (!$san['sections']) {
                return null;
            }
            $pid = bin2hex(random_bytes(10));
            $now = time();
            db()->prepare('INSERT INTO documents (id, telegram_id, title, doc_type, lang, student, data, version, created_at, updated_at) VALUES (?,?,?,?,?,?,?,1,?,?)')
                ->execute([
                    $pid, $tgId, $san['title'] !== '' ? $san['title'] : self::clean($title, 300),
                    in_array($docType, self::TYPES, true) ? $docType : 'MUSTAQIL ISH', I18n::norm($lang),
                    json_encode($san['student'], JSON_UNESCAPED_UNICODE), json_encode($san['sections'], JSON_UNESCAPED_UNICODE), $now, $now,
                ]);
            if ($images) {               // rasmlar tahrirlovchidan qayta eksport qilinganda ham saqlansin
                $dir = self::imgDir();
                if (is_dir($dir) || @mkdir($dir, 0755, true)) {
                    foreach ($images as $n => $src) {
                        $ext = strtolower(pathinfo((string)$src, PATHINFO_EXTENSION)) === 'png' ? 'png' : 'jpg';
                        if (is_file((string)$src)) {
                            @copy((string)$src, $dir . $pid . '_' . (int)$n . '.' . $ext);
                        }
                    }
                }
            }
            EditorSecurity::cleanup();
            return $pid;
        } catch (Throwable $e) {
            logError('docstore', 'Hujjat nusxasi yaratilmadi: ' . $e->getMessage());
            return null;
        }
    }

    // ------------------------------------------------------------------ o'qish / yozish

    public static function get(string $pid): ?array
    {
        if (!EditorStore::validPid($pid)) {
            return null;
        }
        $st = db()->prepare('SELECT * FROM documents WHERE id = ?');
        $st->execute([$pid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['sections'] = json_decode((string)$row['data'], true) ?: [];
        $row['student'] = json_decode((string)$row['student'], true) ?: [];
        return $row;
    }

    public static function getOwned(string $pid, int $tgId): ?array
    {
        $d = self::get($pid);
        return ($d && (int)$d['telegram_id'] === $tgId) ? $d : null;
    }

    public static function listForUser(int $tgId, int $limit = 6): array
    {
        $st = db()->prepare('SELECT id, title, doc_type, created_at FROM documents WHERE telegram_id = ? ORDER BY created_at DESC LIMIT ?');
        $st->bindValue(1, $tgId, PDO::PARAM_INT);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Saqlash (optimistik versiya tekshiruvi: ikki qurilmada tahrirlansa, eskisi yangisini bosib yubormasin).
     * Qaytadi: ['ok'=>true,'version'=>n,'stats'=>[...]] yoki ['ok'=>false,'error'=>'conflict'|'empty'|'not_found'].
     */
    public static function save(string $pid, int $tgId, int $baseVersion, array $payload): array
    {
        $san = self::sanitize($payload);
        if (!$san['sections']) {
            return ['ok' => false, 'error' => 'empty'];
        }
        $doc = self::getOwned($pid, $tgId);
        if (!$doc) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $title = $san['title'] !== '' ? $san['title'] : (string)$doc['title'];
        $st = db()->prepare('UPDATE documents SET title = ?, student = ?, data = ?, version = version + 1, updated_at = ? WHERE id = ? AND telegram_id = ? AND version = ?');
        $st->execute([$title, json_encode($san['student'], JSON_UNESCAPED_UNICODE), json_encode($san['sections'], JSON_UNESCAPED_UNICODE), time(), $pid, $tgId, $baseVersion]);
        if ($st->rowCount() === 0) {
            return ['ok' => false, 'error' => 'conflict'];
        }
        return ['ok' => true, 'version' => $baseVersion + 1, 'stats' => self::stats($san['sections'])];
    }

    public static function delete(string $pid): void
    {
        if (!EditorStore::validPid($pid)) {
            return;
        }
        $pdo = db();
        foreach (['edit_tokens', 'edit_sessions'] as $t) {
            $pdo->prepare("DELETE FROM $t WHERE pres_id = ?")->execute([$pid]);
        }
        $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$pid]);
    }

    // ------------------------------------------------------------------ eksport

    public static function fileSafeName(string $title, string $ext): string
    {
        $t = preg_replace('/[^\p{L}\p{N}_ -]+/u', '', $title) ?? '';
        $t = trim(preg_replace('/\s+/u', '_', $t) ?? '', '_');
        $t = function_exists('mb_substr') ? mb_substr($t, 0, 40) : substr($t, 0, 40);
        return ($t !== '' ? $t : 'hujjat') . '.' . $ext;
    }

    /** Saqlangan hujjatdan DOCX yaratadi. Qaytadi: fayl yo'li. */
    public static function exportDocx(array $doc): string
    {
        $san = self::sanitize(['title' => $doc['title'], 'student' => $doc['student'], 'sections' => $doc['sections']]);
        if (!$san['sections']) {
            throw new RuntimeException('Hujjat bo\'sh');
        }
        // Mijoz adabiyotlar bo'limini o'chirgan bo'lsa — hurmat qilamiz (avtomatik qayta qo'shilmaydi)
        return DocxGenerator::generate((string)$doc['title'], $san['sections'], $san['student'], (string)$doc['doc_type'], (string)$doc['lang'], false,
            ['images' => self::images((string)($doc['id'] ?? ''))]);
    }
}

/** So'z soni (probel bo'yicha) — JobFunctions yuklanmagan muhitlarda (editor/api.php) ham ishlashi uchun alohida. */
function countWordsSimple(string $text): int
{
    $text = trim($text);
    if ($text === '') {
        return 0;
    }
    $parts = preg_split('/\s+/u', $text);
    return $parts ? count(array_filter($parts, fn($x) => $x !== '')) : 0;
}
