<?php

/**
 * 🌐 TARJIMA: tayyor taqdimot (PPTX) yoki hujjat (DOCX) boshqa tilga o'giriladi — DIZAYN O'ZGARMAYDI:
 * fayl ichidagi matn bo'laklari (paragraflar) ajratib olinadi, 70 tadan AI'ga yuboriladi va xuddi o'sha joyga qaytariladi.
 * Ranglar, rasmlar, animatsiyalar, shriftlar, bet raqamlari — hammasi asl holicha qoladi.
 *
 * Oqim: fayl ostidagi «🌐 Tarjima» → til tanlanadi → narx balansdan yechiladi → 'tarjima' turidagi buyurtma
 * (volume_key = "<asl buyurtma ID>:<til>") → qadamlar: tr_prep → tr_batch… → tr_build → deliver.
 * Narx: admin panel → Sozlamalar → «Tarjima narxi» (standart 2 000 so'm). O'chirish: «🌐 Tarjima».
 */
class Translate
{
    public const LANGS = ['uz' => "🇺🇿 O'zbekcha", 'ru' => '🇷🇺 Русский', 'en' => '🇬🇧 English'];
    private const BATCH = 70;

    public static function enabled(): bool
    {
        return (bool)Engine::c('TRANSLATE_ENABLED', true);
    }

    public static function price(): int
    {
        return max(0, (int)Engine::c('TRANSLATE_PRICE', 2000));
    }

    /** Tarjima qilsa bo'ladigan buyurtmami (tayyor, fayli bor). */
    public static function canTranslate(array $j): bool
    {
        return self::enabled() && $j['status'] === 'done' && (!empty($j['tg_file_id']) || (!empty($j['result_file']) && is_file((string)$j['result_file'])));
    }

    public static function button(int $jobId): array
    {
        return ['text' => '🌐 Boshqa tilga tarjima', 'callback_data' => 'tr:' . $jobId];
    }

    // ------------------------------------------------------------------ bot: tugmalar

    public static function ask(int $tg, int $jobId): void
    {
        $j = self::job($jobId);
        if (!$j || (int)$j['telegram_id'] !== $tg || !self::canTranslate($j)) {
            Telegram::sendMessage($tg, "Bu buyurtmani tarjima qilib bo'lmaydi (fayli topilmadi).");
            return;
        }
        $cur = I18n::norm($j['lang'] ?? 'uz');
        $row = [];
        foreach (self::LANGS as $k => $label) {
            if ($k !== $cur) {
                $row[] = ['text' => $label, 'callback_data' => "trl:$jobId:$k"];
            }
        }
        $p = self::price();
        Telegram::sendMessage($tg, "🌐 <b>Tarjima</b> — «" . Telegram::escape((string)$j['topic']) . "»\n\nDizayn, rasmlar va tuzilma o'zgarmaydi, faqat matn o'giriladi."
            . "\nNarxi: <b>" . ($p > 0 ? number_format($p, 0, '', ' ') . " so'm" : 'bepul') . "</b>\n\nQaysi tilga?", ['inline_keyboard' => [$row]]);
    }

    public static function order(int $tg, int $jobId, string $lang): void
    {
        $j = self::job($jobId);
        if (!$j || (int)$j['telegram_id'] !== $tg || !isset(self::LANGS[$lang]) || !self::canTranslate($j)) {
            return;
        }
        $last = db()->prepare("SELECT COUNT(*) FROM jobs WHERE telegram_id = ? AND type = 'tarjima' AND volume_key = ? AND status IN ('pending','processing')");
        $last->execute([$tg, "$jobId:$lang"]);
        if ((int)$last->fetchColumn() > 0) {
            Telegram::sendMessage($tg, "⏳ Bu tarjima allaqachon tayyorlanmoqda.");
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
            $id = Queue::createJob($tg, 'tarjima', (string)$j['topic'], "$jobId:$lang", $price, false, true, $j['design_key'] ?: null, $lang, is_array($student) ? $student : null);
            db()->prepare('UPDATE jobs SET paid_by = ?, parent_id = ? WHERE id = ?')->execute([$price > 0 ? 'balance' : 'free', $jobId, $id]);
        } catch (Throwable $e) {
            if ($price > 0) {
                db()->prepare('UPDATE users SET balance = balance + ? WHERE telegram_id = ?')->execute([$price, $tg]);
            }
            logError('translate', $e->getMessage());
            Telegram::sendMessage($tg, "⚠️ Tarjima buyurtmasi yaratilmadi, pul qaytarildi.");
            return;
        }
        Engine::onJobPending($id, "✅ Tarjima boshlandi: " . self::LANGS[$lang] . ($price > 0 ? "  ·  💳 " . number_format($price, 0, '', ' ') . " so'm" : ''));
    }

    private static function job(int $id): ?array
    {
        $st = db()->prepare('SELECT * FROM jobs WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ------------------------------------------------------------------ ish qadamlari

    public static function steps(): array
    {
        return [
            ['id' => 'trp', 'k' => 'tr_prep', 'w' => 1.0, 'label' => 'Fayl tayyorlanmoqda'],
            ['id' => 'trb', 'k' => 'tr_batch', 'w' => 6.0, 'label' => 'Matn tarjima qilinmoqda'],
            ['id' => 'trx', 'k' => 'tr_build', 'w' => 1.0, 'label' => 'Tarjima qilingan fayl yig\'ilmoqda'],
            ['id' => 'deliver', 'k' => 'deliver', 'w' => 1.0, 'label' => 'Telegram’ga yuborilmoqda'],
        ];
    }

    /** Asl faylni topadi (serverda bo'lmasa — Telegram'dan yuklab oladi) va matn bo'laklarini ajratadi. */
    public static function prep(array $job, array &$state): void
    {
        [$srcId] = explode(':', (string)$job['volume_key'] . ':', 2);
        $src = self::job((int)$srcId);
        if (!$src || (int)$src['telegram_id'] !== (int)$job['telegram_id']) {
            throw new RuntimeException('Asl buyurtma topilmadi');
        }
        $file = (string)($src['result_file'] ?? '');
        $ext = strtolower(pathinfo($file !== '' ? $file : (string)($src['tg_file_name'] ?? ''), PATHINFO_EXTENSION));
        $local = rtrim(GENERATED_DIR, '/') . '/tr_' . bin2hex(random_bytes(5)) . '.' . ($ext ?: 'docx');
        if ($file !== '' && is_file($file)) {
            copy($file, $local);
        } elseif (!empty($src['tg_file_id'])) {
            $r = Telegram::call('getFile', ['file_id' => (string)$src['tg_file_id']]);
            $path = (string)($r['result']['file_path'] ?? '');
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: $ext;
            $local = preg_replace('/\.\w+$/', '.' . $ext, $local) ?? $local;
            $ch = curl_init(TELEGRAM_FILE_API . $path);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40]);
            $body = curl_exec($ch);
            $ok = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
            curl_close($ch);
            if ($path === '' || !$ok || !is_string($body) || strlen($body) < 500) {
                throw new RuntimeException('Asl faylni Telegram\'dan yuklab bo\'lmadi');
            }
            file_put_contents($local, $body);
        } else {
            throw new RuntimeException('Asl fayl topilmadi');
        }
        if (!in_array($ext, ['pptx', 'docx'], true)) {
            throw new RuntimeException("Bu fayl turini tarjima qilib bo'lmaydi: $ext");
        }
        $state['res']['tmp'][] = $local;
        $state['res']['src'] = $local;
        $state['res']['ext'] = $ext;
        $state['res']['from'] = I18n::norm($src['lang'] ?? 'uz');
        $state['res']['segs'] = self::extract($local, $ext);
        $state['res']['tr'] = [];
        if (!$state['res']['segs']) {
            throw new RuntimeException('Faylda matn topilmadi');
        }
    }

    /** Bo'laklarni 70 tadan tarjima qiladi; vaqt tugasa — keyingi tickda davom (StepContinueException). */
    public static function batch(array &$state): void
    {
        $segs = (array)$state['res']['segs'];
        $to = (string)($state['p']['lang'] ?? 'en');
        $names = ['uz' => "o'zbek (lotin yozuvida)", 'ru' => 'rus', 'en' => 'ingliz'];
        $student = (array)($state['p']['student'] ?? []);
        $keep = array_filter([(string)($student['full_name'] ?? ''), (string)($student['university'] ?? ''), (string)($student['group_name'] ?? ''), (string)($student['teacher'] ?? '')]);
        while (count((array)$state['res']['tr']) < count($segs)) {
            if (AIClient::$deadline !== null && microtime(true) > AIClient::$deadline - 45) {
                throw new StepContinueException();
            }
            $from = count((array)$state['res']['tr']);
            $chunk = array_slice($segs, $from, self::BATCH);
            $prompt = "Quyidagi JSON massivdagi har bir matnni {$names[$to]} tiliga tarjima qiling. Ilmiy-akademik uslubni saqlang. "
                . "Kishi ismlari, universitet nomlari, guruh raqamlari, URL, DOI, formulalar, raqamlar va adabiyotlar ro'yxatidagi bibliografik yozuvlarni O'ZGARTIRMANG."
                . ($keep ? ' Ayniqsa bularni o\'zgartirmang: ' . implode('; ', $keep) . '.' : '')
                . " Javob — FAQAT xuddi shuncha (" . count($chunk) . " ta) elementli JSON massiv, tartib saqlansin.\n\n"
                . json_encode(array_values($chunk), JSON_UNESCAPED_UNICODE);
            $out = null;
            for ($try = 0; $try < 2 && $out === null; $try++) {
                $raw = AIClient::generateText($prompt, 'Siz professional tarjimonsiz. Faqat JSON massiv qaytaring.', 7000, ['temperature' => 0.2]);
                $raw = trim($raw);
                if (preg_match('/\[[\s\S]*\]/u', $raw, $m)) {
                    $arr = json_decode($m[0], true);
                    if (is_array($arr) && count($arr) === count($chunk)) {
                        $out = array_map(fn($x, $orig) => is_string($x) && trim($x) !== '' ? $x : $orig, array_values($arr), array_values($chunk));
                    }
                }
            }
            if ($out === null) {
                logError('translate', "Bo'lak $from tarjima qilinmadi — asl matn qoldirildi");
                $out = array_values($chunk);
            }
            $state['res']['tr'] = array_merge((array)$state['res']['tr'], $out);
        }
    }

    public static function build(array $job, array &$state): void
    {
        $src = (string)$state['res']['src'];
        $ext = (string)$state['res']['ext'];
        $lang = (string)($state['p']['lang'] ?? 'en');
        $base = preg_replace('/[^\p{L}\p{N}]+/u', '_', mb_substr((string)$job['topic'], 0, 40)) ?: 'tarjima';
        $dst = rtrim(GENERATED_DIR, '/') . '/' . trim($base, '_') . '_' . $lang . '_' . substr(bin2hex(random_bytes(3)), 0, 4) . '.' . $ext;
        copy($src, $dst);
        self::apply($dst, $ext, (array)$state['res']['tr'], $lang);
        $state['res']['file'] = $dst;
        unset($state['res']['segs'], $state['res']['tr']);
    }

    // ------------------------------------------------------------------ OOXML: matnni ajratish va qaytarish

    private static function parts(ZipArchive $z, string $ext): array
    {
        $out = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = (string)$z->getNameIndex($i);
            $ok = $ext === 'pptx'
                ? preg_match('#^ppt/(slides/slide|notesSlides/notesSlide)\d+\.xml$#', $n)
                : preg_match('#^word/(document|footnotes|header\d*|footer\d*)\.xml$#', $n);
            if ($ok) {
                $out[] = $n;
            }
        }
        natsort($out);
        return array_values($out);
    }

    /** Paragraf: matn yugurtmalari (run) bo'yicha. Tab/maydon bo'lsa — har run alohida; aks holda butun paragraf bitta bo'lak. */
    private static function units(string $xml, string $ext): array
    {
        $pTag = $ext === 'pptx' ? 'a:p' : 'w:p';
        $tTag = $ext === 'pptx' ? 'a:t' : 'w:t';
        $units = [];
        if (!preg_match_all('#<' . $pTag . '\b[^>]*>.*?</' . $pTag . '>#s', $xml, $ps, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        foreach ($ps[0] as [$pxml, $pos]) {
            if (!preg_match_all('#<' . $tTag . '(?:\s[^>]*)?>(.*?)</' . $tTag . '>#s', $pxml, $ts, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $split = $ext === 'docx' && preg_match('#<w:tab/>|<w:fldChar|<w:instrText#', $pxml);
            $runs = [];
            foreach ($ts[1] as [$txt, $off]) {
                $runs[] = ['off' => $pos + $off, 'len' => strlen($txt), 'text' => html_entity_decode($txt, ENT_QUOTES | ENT_XML1, 'UTF-8')];
            }
            if ($split) {
                foreach ($runs as $r) {
                    $units[] = [$r];
                }
            } else {
                $units[] = $runs;
            }
        }
        return $units;
    }

    private static function translatable(string $s): bool
    {
        return (bool)preg_match('/\p{L}{2,}/u', $s) && !preg_match('#^\s*(https?://|www\.|doi:|10\.\d{4,})#i', $s);
    }

    public static function extract(string $file, string $ext): array
    {
        $z = new ZipArchive();
        if ($z->open($file) !== true) {
            throw new RuntimeException('Fayl ochilmadi');
        }
        $segs = [];
        foreach (self::parts($z, $ext) as $n) {
            foreach (self::units((string)$z->getFromName($n), $ext) as $runs) {
                $s = implode('', array_column($runs, 'text'));
                if (self::translatable($s)) {
                    $segs[] = mb_substr($s, 0, 3000);
                }
            }
        }
        $z->close();
        return $segs;
    }

    public static function apply(string $file, string $ext, array $tr, string $lang): void
    {
        $z = new ZipArchive();
        if ($z->open($file) !== true) {
            throw new RuntimeException('Fayl ochilmadi');
        }
        $k = 0;
        foreach (self::parts($z, $ext) as $n) {
            $xml = (string)$z->getFromName($n);
            $edits = [];
            foreach (self::units($xml, $ext) as $runs) {
                $s = implode('', array_column($runs, 'text'));
                if (!self::translatable($s)) {
                    continue;
                }
                $new = (string)($tr[$k++] ?? $s);
                foreach ($runs as $i => $r) {
                    $edits[] = [$r['off'], $r['len'], $i === 0 ? htmlspecialchars($new, ENT_XML1 | ENT_QUOTES, 'UTF-8') : ''];
                }
            }
            usort($edits, fn($a, $b) => $b[0] <=> $a[0]);              // oxiridan boshiga — ofsetlar buzilmaydi
            foreach ($edits as [$off, $len, $rep]) {
                $xml = substr_replace($xml, $rep, $off, $len);
            }
            if ($ext === 'docx') {
                $xml = preg_replace('#<w:t>#', '<w:t xml:space="preserve">', $xml) ?? $xml;
            }
            $tag = ['uz' => 'uz-UZ', 'ru' => 'ru-RU', 'en' => 'en-US'][$lang] ?? 'en-US';
            $xml = preg_replace('#(<a:rPr\b[^>]*?\blang=")[^"]*(")#', '${1}' . $tag . '${2}', $xml) ?? $xml;   // imlo tekshiruvi to'g'ri tilda
            $z->addFromString($n, $xml);
        }
        $z->close();
    }
}
