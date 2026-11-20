<?php

class ImageSearch
{
    /**
     * Mavzuga mos rasmni topib, diskka yuklab, fayl yo'lini qaytaradi.
     * Hech qanday provider ishlamasa (kalit yo'q/limit/xato) — null qaytaradi,
     * chaqiruvchi kod buni "rasmsiz davom et" deb tushunishi kerak. Bot hech qachon
     * shu sababli yiqilib qolmaydi.
     */
    /** Shu jarayonda allaqachon ishlatilgan rasm URL'lari (bir taqdimotda bir xil rasm takrorlanmasin). */
    private static array $seen = [];

    /**
     * @param bool $wide true — PRO taqdimot: katta (kamida ~1200 px), keng (landshaft) rasm kerak; kalitli manbalar topmasa,
     *                   kalitsiz zaxira manbalar (AI rasm yaratish, Wikimedia) ishlatiladi.
     */
    public static function fetchImage(string $uzbekQuery, bool $wide = false, string $englishQuery = ''): ?string
    {
        // Inglizcha ibora reja bilan birga kelgan bo'lsa — tarjima uchun alohida AI so'rovi yuborilmaydi (token va vaqt tejaladi)
        $query = $englishQuery !== '' ? $englishQuery : self::translateToEnglish($uzbekQuery);
        $minW = $wide ? 1100 : 0;

        $sources = [
            fn() => self::tryUnsplash($query, $wide),
            fn() => self::tryPexels($query, $wide),
            fn() => self::trySerper($query),
        ];
        if ($wide && (bool)Engine::c('IMAGE_AI_FALLBACK', true)) {
            $sources[] = fn() => self::tryPollinations($query);
        }
        if ($wide && (bool)Engine::c('IMAGE_WIKIMEDIA_FALLBACK', true)) {
            $sources[] = fn() => self::tryWikimedia($query);
        }
        foreach ($sources as $src) {
            $url = $src();
            if (!$url || isset(self::$seen[$url])) {
                continue;
            }
            $path = self::download($url, $minW, str_contains($url, 'pollinations.ai') ? 45 : 20);
            if ($path) {
                self::$seen[$url] = true;
                return $path;
            }
        }
        return null;
    }

    /** Inglizcha ibora (keshlangan tarjima) — adabiyotlar qidiruvi uchun ham. */
    public static function english(string $text): string
    {
        return self::translateToEnglish($text);
    }

    private static function translateToEnglish(string $text): string
    {
        $ck = 'imgq_' . md5(mb_strtolower(trim($text)));
        try {                       // bir xil mavzu/sarlavha qayta tarjima qilinmaydi (30 kun)
            $st = db()->prepare('SELECT v FROM engine_kv WHERE k = ? AND ts > ?');
            $st->execute([$ck, time() - 30 * 86400]);
            $hit = $st->fetchColumn();
            if (is_string($hit) && $hit !== '') {
                return $hit;
            }
        } catch (Throwable $e) {
        }
        try {
            $translated = AIClient::generateText(
                "Translate this to a short English image-search phrase (2-5 words), reply with ONLY the phrase: \"$text\"",
                'You are a translation tool. Output only the translated phrase, nothing else.',
                200 // reasoning modellar (gpt-oss) tokenning bir qismini "o'ylash"ga sarflaydi: 30 yetmay bo'sh javob chiqib, marshrutni sovitib yuborardi
            );
            $translated = trim($translated, " \t\n\r\0\x0B\"'.");
            // AI qisqa ibora o'rniga uzun/ko'p qatorli javob bersa — asl so'rov bilan davom etamiz
            if ($translated === '' || mb_strlen($translated) > 90 || str_contains($translated, "\n")) {
                return $text;
            }
            try {
                db()->prepare('INSERT INTO engine_kv (k, v, ts) VALUES (?, ?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v, ts = excluded.ts')
                    ->execute([$ck, $translated, time()]);
            } catch (Throwable $e) {
            }
            return $translated;
        } catch (Throwable $e) {
            return $text; // tarjima ishlamasa, asl so'rov bilan davom etamiz
        }
    }

    private static function isConfigured(string $key): bool
    {
        return $key && !str_contains($key, '_HERE');
    }

    private static function tryUnsplash(string $query, bool $wide = false): ?string
    {
        $key = IMAGE_PROVIDERS['unsplash'] ?? '';
        if (!self::isConfigured($key)) {
            return null;
        }
        try {
            $url = 'https://api.unsplash.com/search/photos?' . http_build_query([
                'query' => $query, 'per_page' => 5, 'orientation' => 'landscape',
            ]);
            $json = self::curlGetJson($url, ["Authorization: Client-ID $key"]);
            foreach (($json['results'] ?? []) as $r) {
                $u = $r['urls']['regular'] ?? null;
                if ($u && !isset(self::$seen[$u])) {
                    return $u;
                }
            }
            return null;
        } catch (Throwable $e) {
            logError('image_search', 'Unsplash xato: ' . $e->getMessage());
            return null;
        }
    }

    private static function tryPexels(string $query, bool $wide = false): ?string
    {
        $key = IMAGE_PROVIDERS['pexels'] ?? '';
        if (!self::isConfigured($key)) {
            return null;
        }
        try {
            $url = 'https://api.pexels.com/v1/search?' . http_build_query([
                'query' => $query, 'per_page' => 5, 'orientation' => 'landscape',
            ]);
            $json = self::curlGetJson($url, ["Authorization: $key"]);
            foreach (($json['photos'] ?? []) as $r) {
                $u = $r['src'][$wide ? 'large2x' : 'medium'] ?? null;
                if ($u && !isset(self::$seen[$u])) {
                    return $u;
                }
            }
            return null;
        } catch (Throwable $e) {
            logError('image_search', 'Pexels xato: ' . $e->getMessage());
            return null;
        }
    }

    private static function trySerper(string $query): ?string
    {
        $key = IMAGE_PROVIDERS['serper'] ?? '';
        if (!self::isConfigured($key)) {
            return null;
        }
        try {
            $ch = curl_init('https://google.serper.dev/images');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => ["X-API-KEY: $key", 'Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode(['q' => $query]),
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            $json = json_decode($response, true);
            return $json['images'][0]['imageUrl'] ?? null;
        } catch (Throwable $e) {
            logError('image_search', 'Serper xato: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Kalitsiz AI rasm yaratish (Pollinations): mavzuga mos, original, litsenziya muammosiz rasm.
     * URL o'zi rasm qaytaradi — shu URL download() orqali yuklanadi. Matn rasmga tushib qolmasligi uchun so'rovga "no text" qo'shiladi.
     */
    private static function tryPollinations(string $query): ?string
    {
        $q = trim(preg_replace('/[^\p{L}\p{N}\s,\-]/u', ' ', $query) ?? '');
        if ($q === '') {
            return null;
        }
        $prompt = $q . ', cinematic photograph, dramatic lighting, high detail, wide angle, no text, no watermark';
        return 'https://image.pollinations.ai/prompt/' . rawurlencode($prompt) . '?' . http_build_query([
            'width' => 1600, 'height' => 900, 'nologo' => 'true', 'seed' => random_int(1, 999999),
        ]);
    }

    /** Wikimedia Commons (kalitsiz): katta JPEG rasm. Litsenziyalar xilma-xil bo'lgani uchun faqat oxirgi zaxira. */
    private static function tryWikimedia(string $query): ?string
    {
        try {
            $url = 'https://commons.wikimedia.org/w/api.php?' . http_build_query([
                'action' => 'query', 'format' => 'json', 'generator' => 'search', 'gsrnamespace' => 6, 'gsrsearch' => $query . ' filetype:bitmap',
                'gsrlimit' => 10, 'prop' => 'imageinfo', 'iiprop' => 'url|size|mime', 'iiurlwidth' => 1600,
            ]);
            $json = self::curlGetJson($url, []);
            foreach (($json['query']['pages'] ?? []) as $pg) {
                $ii = $pg['imageinfo'][0] ?? null;
                if (!$ii || ($ii['mime'] ?? '') !== 'image/jpeg' || (int)($ii['width'] ?? 0) < 1400 || (int)($ii['height'] ?? 0) < 700
                    || (int)$ii['width'] < (int)$ii['height'] * 1.2) {
                    continue;
                }
                $u = $ii['thumburl'] ?? ($ii['url'] ?? null);
                if ($u && !isset(self::$seen[$u])) {
                    return $u;
                }
            }
        } catch (Throwable $e) {
            logError('image_search', 'Wikimedia xato: ' . $e->getMessage());
        }
        return null;
    }

    private static function curlGetJson(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'StudyBot/1.0 (education; +' . (defined('PUBLIC_BASE_URL') ? PUBLIC_BASE_URL : 'https://example.org') . ')',
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode((string)$response, true) ?: [];
    }

    private static function download(string $url, int $minWidth = 0, int $timeout = 20): ?string
    {
        if (!preg_match('#^https?://#i', $url)) {
            return null;     // file://, ftp:// va h.k. qabul qilinmaydi
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => 'StudyBot/1.0',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_MAXFILESIZE => 8 * 1024 * 1024,
        ]);
        $data = curl_exec($ch);
        $ok = curl_errno($ch) === 0 && (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);

        if (!$ok || !$data) {
            return null;
        }

        // Rasmning HAQIQIY formatini aniqlaymiz (URL kengaytmasiga ishonib bo'lmaydi —
        // ko'p rasm serverlari .jpg kengaytmasiz yoki noto'g'ri kengaytma bilan URL beradi).
        // PPTX faqat jpg/png/gif'ni ishonchli qo'llab-quvvatlaydi; boshqa formatlarda
        // (masalan webp) hujjat ochilmasligi mumkin, shu sabab bunday holatda rasmni
        // butunlay o'tkazib yuboramiz — slayd rasmsiz, lekin buzilmagan holda qoladi.
        $info = @getimagesizefromstring($data);
        $ext = match ($info['mime'] ?? '') {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            default => null,
        };
        if ($ext === null || ($minWidth > 0 && (int)($info[0] ?? 0) < $minWidth)) {
            return null;
        }

        $path = GENERATED_DIR . 'img_' . uniqid() . '.' . $ext;
        if (file_put_contents($path, $data) === false) {
            return null;
        }
        return $path;
    }
}
