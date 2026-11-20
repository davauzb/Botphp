<?php

require_once __DIR__ . '/PptxElements.php';
require_once __DIR__ . '/I18n.php';

/** Tahrirlanadigan taqdimotlarni saqlash, rasmlar bilan ishlash va PPTX'ga eksport. */
class EditorStore
{
    public static function dir(string $pid): string
    {
        return rtrim(EDITOR_STORAGE_DIR, '/') . '/' . $pid . '/';
    }

    public static function validPid($pid): bool
    {
        return is_string($pid) && preg_match('/^[a-f0-9]{20}$/', $pid) === 1;
    }

    public static function designPayload(string $key, string $lang = 'uz'): array
    {
        $d = pptxDesigns()[$key] ?? pptxDesigns()[DEFAULT_PPTX_DESIGN];
        return ['key' => isset(pptxDesigns()[$key]) ? $key : DEFAULT_PPTX_DESIGN, 'accent' => $d['accent'], 'title' => $d['title'], 'layout' => 'cards', 'skin' => $d['skin'] ?? 'classic', 'lang' => I18n::norm($lang)]
            + (isset($d['bg']) ? ['bg' => $d['bg']] : []) + (!empty($d['pro']) ? ['pro' => true] : []);
    }

    // ---------------------------------------------------------------- yaratish (worker chaqiradi)

    /**
     * Bot yaratgan slaydlardan tahrirlanadigan nusxa yaratadi. Xato bo'lsa null qaytaradi (taqdimot baribir yuboriladi).
     * $final: worker.php dagi slaydlar (rasm yo'llari bilan).
     */
    public static function createFromGenerated(int $tgId, string $title, string $designKey, array $final, string $lang = 'uz'): ?string
    {
        try {
            if (!EditorSecurity::enabled()) {
                return null;
            }
            $design = self::designPayload($designKey, $lang);
            $pid = bin2hex(random_bytes(10));
            if (!is_dir(self::dir($pid)) && !@mkdir(self::dir($pid), 0700, true)) {
                return null;
            }
            self::protectDir(rtrim(EDITOR_STORAGE_DIR, '/'));

            $idByPath = [];
            $imgIds = [];
            foreach ($final as $s) {
                $path = $s['image'] ?? null;
                if ($path && is_file($path) && !isset($idByPath[$path])) {
                    $img = self::addImage($pid, (string)file_get_contents($path), true);
                    if (is_array($img)) {
                        $idByPath[$path] = $img['id'];
                    }
                }
            }
            $slides = PptxElements::slidesFromGenerated($final, $design, $idByPath);
            $imgIds = self::imageIds($pid);
            $clean = [];
            foreach ($slides as $s) {
                $c = PptxElements::sanitizeSlide($s, $imgIds);
                if ($c) {
                    $clean[] = $c;
                }
            }
            $now = time();
            db()->prepare('INSERT INTO presentations (id, telegram_id, title, design_key, design_json, data, version, created_at, updated_at)
                           VALUES (?,?,?,?,?,?,1,?,?)')
                ->execute([$pid, $tgId, PptxElements::str($title, 200), $design['key'], json_encode($design), json_encode($clean, JSON_UNESCAPED_UNICODE), $now, $now]);
            EditorSecurity::cleanup();
            return $pid;
        } catch (Throwable $e) {
            logError('editor', 'Tahrirlanadigan nusxa yaratilmadi: ' . $e->getMessage());
            return null;
        }
    }

    public static function protectDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\nDeny from all\nOptions -Indexes\n");
        }
        $ix = $dir . '/index.html';
        if (!is_file($ix)) {
            @file_put_contents($ix, '');
        }
    }

    // ---------------------------------------------------------------- o'qish / yozish

    public static function get(string $pid): ?array
    {
        if (!self::validPid($pid)) {
            return null;
        }
        $st = db()->prepare('SELECT * FROM presentations WHERE id = ?');
        $st->execute([$pid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['slides'] = json_decode((string)$row['data'], true) ?: [];
        $row['design'] = json_decode((string)$row['design_json'], true) ?: self::designPayload(DEFAULT_PPTX_DESIGN);
        return $row;
    }

    public static function getOwned(string $pid, int $tgId): ?array
    {
        $p = self::get($pid);
        return ($p && (int)$p['telegram_id'] === $tgId) ? $p : null;
    }

    public static function listForUser(int $tgId, int $limit = 8): array
    {
        $st = db()->prepare('SELECT id, title, created_at, updated_at FROM presentations WHERE telegram_id = ? ORDER BY created_at DESC LIMIT ' . (int)$limit);
        $st->execute([$tgId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * O'zgargan slaydlarni (delta) saqlaydi. $order — yangi tartib (slayd id'lari), $changed — id => slayd.
     * Qaytadi: ['ok'=>bool, 'version'=>int, 'error'=>string, 'slides'=>[id=>tozalangan slayd]].
     */
    public static function save(string $pid, array $order, array $changed, ?string $title, ?string $designKey, int $baseVersion): array
    {
        $pdo = db();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $p = self::get($pid);
            if (!$p) {
                $pdo->exec('ROLLBACK');
                return ['ok' => false, 'error' => 'not_found'];
            }
            if ((int)$p['version'] !== $baseVersion) {
                $pdo->exec('ROLLBACK');
                return ['ok' => false, 'error' => 'conflict', 'version' => (int)$p['version']];
            }
            if (count($order) < 1 || count($order) > PptxElements::MAX_SLIDES) {
                $pdo->exec('ROLLBACK');
                return ['ok' => false, 'error' => 'bad_order'];
            }
            $imgIds = self::imageIds($pid);
            $existing = [];
            foreach ($p['slides'] as $s) {
                $existing[$s['id']] = $s;
            }
            $out = [];
            $returned = [];
            $seen = [];
            foreach ($order as $id) {
                if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id) || isset($seen[$id])) {
                    $pdo->exec('ROLLBACK');
                    return ['ok' => false, 'error' => 'bad_order'];
                }
                $seen[$id] = true;
                if (isset($changed[$id])) {
                    $raw = $changed[$id];
                    if (is_array($raw)) {
                        $raw['id'] = $id;
                    }
                    $c = PptxElements::sanitizeSlide($raw, $imgIds);
                    if (!$c) {
                        $pdo->exec('ROLLBACK');
                        return ['ok' => false, 'error' => 'bad_slide'];
                    }
                    $c['id'] = $id;
                    $out[] = $c;
                    $returned[$id] = $c;
                } elseif (isset($existing[$id])) {
                    $out[] = $existing[$id];
                } else {
                    $pdo->exec('ROLLBACK');
                    return ['ok' => false, 'error' => 'unknown_slide'];
                }
            }
            $json = json_encode($out, JSON_UNESCAPED_UNICODE);
            if ($json === false || strlen($json) > 12 * 1024 * 1024) {
                $pdo->exec('ROLLBACK');
                return ['ok' => false, 'error' => 'too_large'];
            }
            $design = $p['design'];
            if ($designKey !== null && isset(pptxDesigns()[$designKey])) {
                $design = self::designPayload($designKey, (string)($p['design']['lang'] ?? 'uz'));
            }
            $newTitle = $title !== null ? PptxElements::str($title, 200) : $p['title'];
            $v = (int)$p['version'] + 1;
            $pdo->prepare('UPDATE presentations SET data = ?, version = ?, updated_at = ?, title = ?, design_key = ?, design_json = ? WHERE id = ?')
                ->execute([$json, $v, time(), $newTitle, $design['key'], json_encode($design), $pid]);
            $pdo->exec('COMMIT');
            return ['ok' => true, 'version' => $v, 'slides' => $returned];
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable $e2) {
            }
            logError('editor', 'save xato: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'server'];
        }
    }

    // ---------------------------------------------------------------- rasmlar

    public static function imageIds(string $pid): array
    {
        $st = db()->prepare('SELECT id FROM pres_images WHERE pres_id = ?');
        $st->execute([$pid]);
        $o = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $o[$id] = true;
        }
        return $o;
    }

    public static function imageMap(string $pid): array
    {
        $st = db()->prepare('SELECT id, ext FROM pres_images WHERE pres_id = ?');
        $st->execute([$pid]);
        $o = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $o[$r['id']] = ['path' => self::dir($pid) . $r['id'] . '.' . $r['ext'], 'ext' => $r['ext']];
        }
        return $o;
    }

    public static function imageInfo(string $pid, string $imgId): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $imgId)) {
            return null;
        }
        $st = db()->prepare('SELECT id, ext, w, h FROM pres_images WHERE id = ? AND pres_id = ?');
        $st->execute([$imgId, $pid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || !in_array($r['ext'], ['jpg', 'png'], true)) {
            return null;
        }
        $path = self::dir($pid) . $r['id'] . '.' . $r['ext'];
        return is_file($path) ? ['path' => $path, 'ext' => $r['ext'], 'w' => (int)$r['w'], 'h' => (int)$r['h'], 'id' => $r['id']] : null;
    }

    /**
     * Yuklangan rasmni TEKSHIRADI va QAYTA KODLAYDI (GD): zararli qo'shimcha ma'lumot, EXIF va polyglot fayllar yo'qoladi.
     * Qaytadi: ['id','ext','w','h'] yoki xato kodi (string).
     */
    public static function addImage(string $pid, string $bytes, bool $skipQuota = false)
    {
        if (!function_exists('imagecreatefromstring')) {
            return 'no_gd';
        }
        if ($bytes === '' || strlen($bytes) > EDITOR_MAX_UPLOAD_BYTES) {
            return 'too_big';
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            return 'bad_type';
        }
        [$w, $h] = [(int)$info[0], (int)$info[1]];
        if ($w < 1 || $h < 1 || $w > 9000 || $h > 9000 || $w * $h > 40000000) {
            return 'bad_size';
        }
        if (!$skipQuota) {
            $st = db()->prepare('SELECT COUNT(*) FROM pres_images WHERE pres_id = ?');
            $st->execute([$pid]);
            if ((int)$st->fetchColumn() >= EDITOR_MAX_IMAGES) {
                return 'quota';
            }
        }
        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            return 'bad_image';
        }
        $max = 2400;
        $scale = min(1.0, $max / max($w, $h));
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $png = $info[2] !== IMAGETYPE_JPEG;
        $dst = imagecreatetruecolor($nw, $nh);
        if ($png) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        $id = 'i' . bin2hex(random_bytes(6));
        $ext = $png ? 'png' : 'jpg';
        $dir = self::dir($pid);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $path = $dir . $id . '.' . $ext;
        $ok = $png ? imagepng($dst, $path, 6) : imagejpeg($dst, $path, 88);
        imagedestroy($dst);
        if (!$ok) {
            return 'write_fail';
        }
        @chmod($path, 0600);
        db()->prepare('INSERT INTO pres_images (id, pres_id, ext, bytes, w, h, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$id, $pid, $ext, (int)filesize($path), $nw, $nh, time()]);
        return ['id' => $id, 'ext' => $ext, 'w' => $nw, 'h' => $nh];
    }

    public static function deletePresentation(string $pid): void
    {
        if (!self::validPid($pid)) {
            return;
        }
        $dir = self::dir($pid);
        if (is_dir($dir)) {
            foreach (glob($dir . '*') ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($dir);
        }
        $pdo = db();
        foreach (['pres_images', 'edit_tokens', 'edit_sessions'] as $t) {
            $pdo->prepare("DELETE FROM $t WHERE pres_id = ?")->execute([$pid]);
        }
        $pdo->prepare('DELETE FROM presentations WHERE id = ?')->execute([$pid]);
    }

    // ---------------------------------------------------------------- maket qayta qo'llash va eksport

    /** Mazmundan (sem) elementlarni qayta hosil qiladi. $n/$total — slayd o'rni (raqam uchun). */
    public static function regen(string $pid, array $semRaw, string $designKey, int $n, int $total): array
    {
        $cur = self::get($pid);
        $design = self::designPayload($designKey, (string)($cur['design']['lang'] ?? 'uz'));
        $sem = PptxElements::sanitizeSem($semRaw);
        $imgPath = null;
        if ($sem['image']) {
            $info = self::imageInfo($pid, $sem['image']);
            if ($info) {
                $imgPath = $info['path'];
            } else {
                $sem['image'] = null;
            }
        }
        $els = PptxElements::elementsFromSem($sem, $design, max(1, $n), max(1, $total), $imgPath);
        $slide = PptxElements::sanitizeSlide(['id' => 'tmp', 'mode' => 'auto', 'sem' => $sem, 'els' => $els], self::imageIds($pid));
        return ['sem' => $sem, 'els' => $slide ? $slide['els'] : []];
    }

    /** Saqlangan taqdimotdan PPTX yaratadi va TEKSHIRADI. Qaytadi: [yo'l, xatolar]. */
    public static function exportFile(string $pid): array
    {
        $p = self::get($pid);
        if (!$p) {
            return [null, ['not_found']];
        }
        $imgs = self::imageMap($pid);
        $slides = [];
        $ids = self::imageIds($pid);
        foreach ($p['slides'] as $s) {
            $c = PptxElements::sanitizeSlide($s, $ids);
            if ($c) {
                $slides[] = $c;
            }
        }
        $path = PptxElements::buildPackage($slides, $p['design'], $imgs);
        [$ok, $errors] = PptxElements::verifyPackage($path);
        if (!$ok) {
            @unlink($path);
            logError('editor', "Eksport tekshiruvidan o'tmadi ($pid): " . implode('; ', $errors));
            return [null, $errors];
        }
        return [$path, []];
    }

    public static function fileSafeName(string $title): string
    {
        $t = preg_replace('/[^\p{L}\p{N}_ -]+/u', '', $title) ?? '';
        $t = trim(preg_replace('/\s+/u', '_', $t) ?? '', '_');
        $t = function_exists('mb_substr') ? mb_substr($t, 0, 40) : substr($t, 0, 40);
        return ($t !== '' ? $t : 'taqdimot') . '.pptx';
    }
}
