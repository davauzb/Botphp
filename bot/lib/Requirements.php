<?php

/**
 * O'QITUVCHI TALABLARI: mavzu so'ralayotganda mijoz topshiriq faylini (DOCX, PDF, TXT) yoki rasmini (skrinshot,
 * daftardagi talablar) yuborsa — matni ajratib olinadi va keyingi buyurtmaga biriktiriladi. Ish boshida bitta kichik
 * AI so'rovi talablarni 3-10 ta qisqa bandga keltiradi, ular hamma bosqichlarda (reja, boblar, slaydlar) hisobga olinadi.
 *
 *  • DOCX/TXT — PHP o'zi o'qiydi; PDF — serverda pdftotext bo'lsa u bilan, bo'lmasa oddiy usulda (skaner PDF o'qilmaydi).
 *  • Rasm — rasmni tushunadigan AI modeli (masalan Gemini) bo'lsa o'qiladi, bo'lmasa mijozdan matn bilan yozish so'raladi.
 *  • Mijoz shunchaki matn ko'rinishida ham yozishi mumkin: «Talab: ...» deb boshlasa.
 * O'chirish: admin panel → Sozlamalar → «O'qituvchi talablari».
 */
class Requirements
{
    private const MAX = 4000;

    public static function enabled(): bool
    {
        return (bool)Engine::c('REQUIREMENTS_ENABLED', true);
    }

    public static function pending(int $tg): string
    {
        $st = db()->prepare('SELECT pending_req FROM users WHERE telegram_id = ?');
        $st->execute([$tg]);
        return (string)$st->fetchColumn();
    }

    public static function clear(int $tg): void
    {
        db()->prepare('UPDATE users SET pending_req = NULL WHERE telegram_id = ?')->execute([$tg]);
    }

    private static function store(int $tg, string $text): int
    {
        $cur = self::pending($tg);
        $all = trim($cur . ($cur !== '' ? "\n\n" : '') . $text);
        $all = mb_substr($all, 0, self::MAX);
        db()->prepare('UPDATE users SET pending_req = ? WHERE telegram_id = ?')->execute([$all, $tg]);
        return mb_strlen($all);
    }

    /** Buyurtma yaratilgach: kutilayotgan talablar buyurtmaga o'tkaziladi. */
    public static function attach(int $tg, int $jobId): void
    {
        $r = self::pending($tg);
        if ($r === '') {
            return;
        }
        db()->prepare('UPDATE jobs SET requirements = ? WHERE id = ?')->execute([$r, $jobId]);
        self::clear($tg);
    }

    /** «Mavzuni yozing» xabari ostidagi izoh. */
    public static function hint(): string
    {
        return self::enabled() ? "\n\n📎 <i>O'qituvchi talablari bormi? Mavzudan oldin topshiriq faylini (DOCX, PDF) yoki rasmini yuboring — ish shunga moslab yoziladi.</i>" : '';
    }

    /**
     * Mavzu kutilayotganda kelgan fayl/rasm/«Talab:» matni. Qaytadi: true — xabar shu yerda qayta ishlandi.
     */
    public static function handle(int $tg, array $message): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $text = trim((string)($message['text'] ?? ''));
        if ($text !== '' && preg_match('/^(talab|talablar|требования|requirements)\s*[:\-]\s*(.+)$/isu', $text, $m)) {
            $n = self::store($tg, trim($m[2]));
            Telegram::sendMessage($tg, "📎 Talablar qabul qilindi ($n belgi). Endi <b>mavzuni</b> yozing ✏️");
            return true;
        }
        $doc = $message['document'] ?? null;
        $photo = $message['photo'] ?? null;
        if (!$doc && !$photo) {
            return false;
        }
        try {
            if ($photo) {
                $p = end($photo);
                $raw = self::download((string)$p['file_id'], 8 * 1024 * 1024);
                $out = $raw !== null ? self::fromImage($raw) : '';
                if ($out === '') {
                    Telegram::sendMessage($tg, "🖼 Rasmdagi matnni o'qib bo'lmadi. Talablarni matn ko'rinishida yuboring:\n<code>Talab: 15 bet, 3 bob, kamida 10 ta manba…</code>\nYoki to'g'ridan-to'g'ri mavzuni yozing.");
                    return true;
                }
            } else {
                $name = mb_strtolower((string)($doc['file_name'] ?? ''));
                $size = (int)($doc['file_size'] ?? 0);
                if ($size > 10 * 1024 * 1024) {
                    Telegram::sendMessage($tg, "⚠️ Fayl juda katta (10 MB dan oshmasin).");
                    return true;
                }
                $ext = pathinfo($name, PATHINFO_EXTENSION);
                if (!in_array($ext, ['docx', 'txt', 'pdf', 'jpg', 'jpeg', 'png'], true)) {
                    Telegram::sendMessage($tg, "⚠️ Faqat DOCX, PDF, TXT yoki rasm yuboring. (Eski .doc bo'lsa — Word'da «Save as .docx» qiling.)");
                    return true;
                }
                $raw = self::download((string)$doc['file_id'], 10 * 1024 * 1024);
                if ($raw === null) {
                    Telegram::sendMessage($tg, "⚠️ Faylni yuklab bo'lmadi (Telegram 20 MB dan katta fayllarni bermaydi). Qayta urinib ko'ring.");
                    return true;
                }
                $out = match ($ext) {
                    'docx' => self::fromDocx($raw),
                    'pdf' => self::fromPdf($raw),
                    'txt' => self::clean(mb_convert_encoding($raw, 'UTF-8', 'UTF-8, Windows-1251, CP1252')),
                    default => self::fromImage($raw),
                };
                if (trim($out) === '') {
                    Telegram::sendMessage($tg, "⚠️ Fayldan matn topilmadi (skaner qilingan PDF bo'lishi mumkin). Rasm qilib yoki matn bilan yuboring:\n<code>Talab: …</code>");
                    return true;
                }
            }
        } catch (Throwable $e) {
            logError('req', 'Talab fayli: ' . $e->getMessage());
            Telegram::sendMessage($tg, "⚠️ Faylni o'qishda xatolik. Talablarni matn ko'rinishida yuboring: <code>Talab: …</code>");
            return true;
        }
        $n = self::store($tg, $out);
        $preview = mb_substr(preg_replace('/\s+/u', ' ', $out) ?? $out, 0, 220);
        Telegram::sendMessage($tg, "📎 <b>Talablar qabul qilindi</b> ($n belgi):\n<i>" . Telegram::escape($preview) . (mb_strlen($out) > 220 ? '…' : '') . "</i>\n\n"
            . "Ish shu talablarga moslab yoziladi. Endi <b>mavzuni</b> yozing ✏️\n(Yana fayl yuborsangiz — qo'shiladi; bekor qilish: /talab_ochir)");
        return true;
    }

    // ------------------------------------------------------------------ AI: talablar xulosasi (ish boshida, bir marta)

    /** 3-10 ta qisqa band (≤ 700 belgi) — hamma promptlarga qo'shiladi. */
    public static function digest(string $raw, string $topic): string
    {
        $raw = mb_substr(trim($raw), 0, self::MAX);
        if ($raw === '') {
            return '';
        }
        $prompt = "Quyida o'qituvchining \"$topic\" mavzusidagi topshiriq bo'yicha talablari (fayldan olingan matn, keraksiz qismlar ham bo'lishi mumkin). "
            . "Ishni YOZISHGA tegishli aniq talablarni 3-10 ta qisqa bandga keltiring (tuzilma, bo'limlar, uslub, misollar, manbalar, alohida savollar va h.k.). "
            . "Shrift, hoshiya, chop etish kabi texnik talablarni va hajmni yozmang. Har band \"- \" bilan, jami 700 belgidan oshmasin. "
            . "Talab bo'lmasa, faqat \"-\" yozing.\n\nMATN:\n$raw";
        $out = AIClient::generateText($prompt, 'Faqat bandlar ro\'yxatini qaytaring.', 600, ['temperature' => 0.2]);
        $out = trim(preg_replace('/^\s*[\*•]\s*/mu', '- ', $out) ?? $out);
        return mb_strlen($out) < 5 ? '' : mb_substr($out, 0, 900);
    }

    /** Barcha AI so'rovlariga qo'shiladigan qisqa ko'rsatma. */
    public static function context(string $digest): string
    {
        return $digest === '' ? '' : "\n\nO'QITUVCHI TALABLARI (iloji boricha ularga amal qiling, mavzudan chetga chiqmang):\n" . $digest;
    }

    // ------------------------------------------------------------------ fayldan matn

    private static function download(string $fileId, int $max): ?string
    {
        $r = Telegram::call('getFile', ['file_id' => $fileId]);
        $path = (string)($r['result']['file_path'] ?? '');
        if ($path === '' || (int)($r['result']['file_size'] ?? 0) > $max) {
            return null;
        }
        $ch = curl_init(TELEGRAM_FILE_API . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code === 200 && is_string($body) && strlen($body) <= $max) ? $body : null;
    }

    private static function clean(string $s): string
    {
        $s = preg_replace("/[ \t\x{00A0}]+/u", ' ', $s) ?? $s;
        $s = preg_replace("/\n\s*\n+/u", "\n", $s) ?? $s;
        return trim(mb_substr($s, 0, self::MAX));
    }

    public static function fromDocx(string $raw): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rq');
        file_put_contents($tmp, $raw);
        $zip = new ZipArchive();
        $xml = '';
        if ($zip->open($tmp) === true) {
            $xml = (string)$zip->getFromName('word/document.xml');
            $zip->close();
        }
        @unlink($tmp);
        if ($xml === '') {
            return '';
        }
        $xml = preg_replace('#</w:p>#', "\n", $xml) ?? $xml;
        $xml = preg_replace('#<w:tab/>#', ' ', $xml) ?? $xml;
        return self::clean(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    public static function fromPdf(string $raw): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rq');
        file_put_contents($tmp, $raw);
        $out = '';
        if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
            $o = [];
            @exec('pdftotext -l 15 -enc UTF-8 ' . escapeshellarg($tmp) . ' - 2>/dev/null', $o, $rc);
            if ($rc === 0) {
                $out = implode("\n", $o);
            }
        }
        @unlink($tmp);
        if (trim($out) === '') {
            $out = self::pdfFallback($raw);
        }
        return self::clean($out);
    }

    /** pdftotext yo'q hostinglar uchun: siqilgan oqimlardan (Tj/TJ) oddiy matn. Faqat lotin/oddiy kodlangan PDF'lar uchun. */
    private static function pdfFallback(string $raw): string
    {
        $text = '';
        if (preg_match_all('#stream\r?\n(.*?)\r?\nendstream#s', $raw, $m)) {
            foreach (array_slice($m[1], 0, 80) as $s) {
                $d = @gzuncompress($s);
                if ($d === false) {
                    $d = @gzinflate(substr($s, 2));
                }
                if (!is_string($d) || !str_contains($d, 'T')) {
                    continue;
                }
                if (preg_match_all('#\[(.*?)\]\s*TJ|\((.*?)(?<!\\\\)\)\s*Tj|(T\*|Td|TD|ET)#s', $d, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $x) {
                        if (!empty($x[3])) {
                            $text .= "\n";
                        } elseif (!empty($x[2])) {
                            $text .= stripcslashes($x[2]);
                        } elseif (!empty($x[1]) && preg_match_all('#\((.*?)(?<!\\\\)\)#s', $x[1], $p)) {
                            $text .= stripcslashes(implode('', $p[1]));
                        }
                    }
                }
            }
        }
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8, CP1252');
        return preg_match_all('/\p{L}{3,}/u', $text) > 10 ? $text : '';
    }

    /** Rasm → matn (rasmni tushunadigan AI modeli orqali). */
    public static function fromImage(string $raw): string
    {
        if (!AIClient::hasVision()) {
            return '';
        }
        if (function_exists('imagecreatefromstring') && strlen($raw) > 900000) {      // kichraytirib yuboramiz
            $im = @imagecreatefromstring($raw);
            if ($im) {
                $w = imagesx($im);
                $h = imagesy($im);
                $k = min(1, 1600 / max($w, $h));
                $dst = imagecreatetruecolor(max(1, (int)($w * $k)), max(1, (int)($h * $k)));
                imagecopyresampled($dst, $im, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
                ob_start();
                imagejpeg($dst, null, 82);
                $raw = (string)ob_get_clean();
            }
        }
        try {
            $out = AIClient::generateText("Rasmdagi o'qituvchi topshirig'i / talablar matnini to'liq va aniq ko'chirib yozing. Talab bo'lmasa, faqat \"-\" yozing.",
                '', 1500, ['temperature' => 0.1, 'image' => base64_encode($raw)]);
        } catch (Throwable $e) {
            logError('req', 'Rasm o\'qilmadi: ' . $e->getMessage());
            return '';
        }
        $out = trim($out);
        return mb_strlen($out) < 8 ? '' : self::clean($out);
    }
}
