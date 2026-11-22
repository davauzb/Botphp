<?php

/**
 * RASM QIDIRUV (14-12): mavzuga ANIQ mos rasm.
 *
 *  1) Qidiruv iborasi: AI rejadagi inglizcha «rasm» iborasi (aniq ko'rinadigan narsa), bo'lmasa — keshlangan tarjima.
 *  2) Bir vaqtda (parallel) bir nechta manbadan 10-40 ta nomzod olinadi:
 *       kalitsiz:  Wikipedia (shaxs/joy/hodisa uchun aynan o'sha rasm), Openverse (800 mln+ ochiq rasm), Wikimedia Commons
 *       kalitli:   Pexels, Pixabay, Unsplash (bepul kalitlar — admin panel → Tizim → «Rasm kalitlari»), Serper (Google, pullik)
 *     Kalit qo'yilmagan manba shunchaki o'tkazib yuboriladi — kalitsiz manbalar har doim ishlaydi.
 *  3) Har nomzod BALLANADI: tavsif/teg/sarlavhasida qidiruv so'zlari va mavzu so'zlari qanchalik bor, o'lcham, yo'nalish (keng),
 *     manba sifati; mos kelmaydiganlar (kamida bitta asosiy so'z yo'q) chiqarib tashlanadi.
 *  4) (Ixtiyoriy, standart — yoqilgan) Rasmni tushunadigan AI (Gemini) eng yaxshi 3 nomzodni ko'rib «mosmi?» deb tekshiradi.
 *  5) Hech narsa mos kelmasa — AI rasm chizadi (Pollinations, kalitsiz) — faqat oxirgi chora.
 * Natijalar keshlanadi (bir xil ibora — 30 kun), bir taqdimotda bir rasm takrorlanmaydi.
 */
class ImageSearch
{
    /** Shu jarayonda allaqachon ishlatilgan rasm URL'lari (bir taqdimotda bir xil rasm takrorlanmasin). */
    private static array $seen = [];
    /** Mavzuning inglizcha nomi (baholashda qo'shimcha so'zlar) — chaqiruvchi o'rnatadi. */
    public static string $topicEn = '';

    public const PROVIDERS = [
        'pexels' => ['Pexels', 'https://www.pexels.com/api/', "Bepul, darhol beriladi. Sifatli fotolar, oyiga 20 000 so'rov"],
        'pixabay' => ['Pixabay', 'https://pixabay.com/api/docs/', 'Bepul, darhol beriladi. Foto va illyustratsiyalar, aniq teglar'],
        'unsplash' => ['Unsplash', 'https://unsplash.com/developers', "Bepul (soatiga 50 so'rov). «Access Key» ni kiriting"],
        'serper' => ['Serper (Google rasmlar)', 'https://serper.dev', "Pullik (2 500 ta bepul so'rov bilan boshlanadi)"],
    ];

    private const STOP = ['the', 'and', 'for', 'with', 'from', 'into', 'its', 'their', 'of', 'in', 'on', 'at', 'to', 'by', 'an', 'a', 'or', 'as', 'is', 'are',
        'role', 'use', 'using', 'based', 'study', 'analysis', 'modern', 'development', 'features', 'impact', 'importance', 'issues', 'problems',
        'prospects', 'current', 'state', 'new', 'some', 'about', 'concept', 'concepts', 'types', 'main', 'basic', 'general', 'overview', 'introduction',
        'conclusion', 'significance', 'essence', 'factors', 'aspects', 'methods', 'system', 'process', 'image', 'photo', 'picture'];

    // ------------------------------------------------------------------ kalitlar (admin panel > config.php)

    public static function key(string $p): string
    {
        $v = class_exists('Settings') ? Settings::raw('IMGKEY:' . $p) : null;
        if (is_string($v) && $v !== '') {
            return $v;
        }
        $c = defined('IMAGE_PROVIDERS') ? (string)(IMAGE_PROVIDERS[$p] ?? '') : '';
        return ($c !== '' && !str_contains($c, '_HERE') && !str_contains($c, 'YOUR')) ? $c : '';
    }

    public static function keysStatus(): array
    {
        $out = [];
        foreach (self::PROVIDERS as $k => [$name, $url, $note]) {
            $db = class_exists('Settings') ? (string)(Settings::raw('IMGKEY:' . $k) ?? '') : '';
            $key = self::key($k);
            $out[] = ['k' => $k, 'name' => $name, 'url' => $url, 'note' => $note, 'set' => $key !== '', 'tail' => $key !== '' ? '…' . substr($key, -4) : '',
                'from' => $db !== '' ? 'panel' : ($key !== '' ? 'config' : '')];
        }
        return $out;
    }

    /** Kalitni sinab ko'radi (bitta kichik so'rov). */
    public static function testKey(string $p, string $key): array
    {
        $q = 'mountain lake';
        $res = match ($p) {
            'pexels' => self::multi([['pexels', 'https://api.pexels.com/v1/search?' . http_build_query(['query' => $q, 'per_page' => 1]), ["Authorization: $key"]]]),
            'pixabay' => self::multi([['pixabay', 'https://pixabay.com/api/?' . http_build_query(['key' => $key, 'q' => $q, 'per_page' => 3]), []]]),
            'unsplash' => self::multi([['unsplash', 'https://api.unsplash.com/search/photos?' . http_build_query(['query' => $q, 'per_page' => 1]), ["Authorization: Client-ID $key"]]]),
            'serper' => ['serper' => self::serperRaw($q, $key)],
            default => [],
        };
        $d = $res[$p] ?? null;
        $ok = is_array($d) && (isset($d['photos'][0]) || isset($d['hits'][0]) || isset($d['results'][0]) || isset($d['images'][0]));
        return $ok ? ['ok' => true, 'msg' => '✅ Kalit ishlayapti'] : ['ok' => false, 'msg' => "❌ Kalit ishlamadi (noto'g'ri yoki limit tugagan)"];
    }

    // ------------------------------------------------------------------ asosiy

    /**
     * @param string $uzbekQuery  slayd/bob sarlavhasi (mavzu bilan)
     * @param bool   $wide        PRO: katta keng rasm (≥1100 px)
     * @param string $englishQuery AI rejadagi inglizcha ibora (bo'lsa — tarjima so'rovi yuborilmaydi)
     */
    public static function fetchImage(string $uzbekQuery, bool $wide = false, string $englishQuery = ''): ?string
    {
        $query = trim($englishQuery !== '' ? $englishQuery : self::translateToEnglish($uzbekQuery));
        if ($query === '') {
            return null;
        }
        $minW = $wide ? 1100 : 500;
        $cands = self::candidates($query, $wide);
        $kw = self::words($query);
        $tw = array_diff(self::words(self::$topicEn), $kw);
        $scored = [];
        foreach ($cands as $c) {
            if (isset(self::$seen[$c['url']])) {
                continue;
            }
            $s = self::score($c, $kw, $tw, $wide);
            if ($s > 0) {
                $scored[] = $c + ['score' => $s];
            }
        }
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        $check = (bool)Engine::c('IMAGE_AI_CHECK', true) && AIClient::hasVision();
        $checked = 0;
        foreach (array_slice($scored, 0, 8) as $c) {
            $path = self::download($c['url'], $minW, 20);
            if (!$path && !empty($c['alt'])) {
                $path = self::download($c['alt'], $minW, 20);
            }
            if (!$path) {
                continue;
            }
            if ($check && $checked < 3) {
                $checked++;
                $v = self::visionOk($path, $query);
                if ($v === false) {
                    @unlink($path);
                    continue;                      // AI: mos emas — keyingi nomzod
                }
            }
            self::$seen[$c['url']] = true;
            return $path;
        }
        // oxirgi chora: AI rasm chizadi (mavzuga mos, lekin fotosurat emas)
        if ((bool)Engine::c('IMAGE_AI_FALLBACK', true)) {
            $u = self::pollinationsUrl($query);
            if ($u && ($path = self::download($u, $wide ? 1100 : 0, 45))) {
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

    // ------------------------------------------------------------------ nomzodlar

    /** @return array<int,array{url:string,alt?:string,w:int,h:int,text:string,src:string,exact?:bool}> */
    private static function candidates(string $q, bool $wide): array
    {
        $ck = 'imgc_' . md5(mb_strtolower($q) . '|' . (int)$wide . '|' . implode(',', array_map(fn($k) => self::key($k) !== '' ? 1 : 0, array_keys(self::PROVIDERS))));
        try {
            $st = db()->prepare('SELECT v FROM engine_kv WHERE k = ? AND ts > ?');
            $st->execute([$ck, time() - 30 * 86400]);
            $hit = $st->fetchColumn();
            if (is_string($hit) && ($arr = json_decode($hit, true)) && is_array($arr)) {
                return $arr;
            }
        } catch (Throwable $e) {
        }
        $reqs = [];
        // kalitsiz
        $reqs[] = ['wikipedia', 'https://en.wikipedia.org/w/api.php?' . http_build_query(['action' => 'query', 'format' => 'json', 'generator' => 'search', 'gsrsearch' => $q,
            'gsrlimit' => 5, 'prop' => 'pageimages|description', 'piprop' => 'original|thumbnail', 'pithumbsize' => 1600, 'redirects' => 1]), []];
        $reqs[] = ['openverse', 'https://api.openverse.org/v1/images/?' . http_build_query(['q' => $q, 'page_size' => 20, 'mature' => 'false']
            + ($wide ? ['aspect_ratio' => 'wide', 'size' => 'large'] : [])), []];
        $reqs[] = ['commons', 'https://commons.wikimedia.org/w/api.php?' . http_build_query(['action' => 'query', 'format' => 'json', 'generator' => 'search',
            'gsrnamespace' => 6, 'gsrsearch' => $q . ' filetype:bitmap', 'gsrlimit' => 12, 'prop' => 'imageinfo', 'iiprop' => 'url|size|mime']), []];
        // kalitli (kalit bo'lsa)
        if (($k = self::key('pexels')) !== '') {
            $reqs[] = ['pexels', 'https://api.pexels.com/v1/search?' . http_build_query(['query' => $q, 'per_page' => 15] + ($wide ? ['orientation' => 'landscape'] : [])), ["Authorization: $k"]];
        }
        if (($k = self::key('pixabay')) !== '') {
            $reqs[] = ['pixabay', 'https://pixabay.com/api/?' . http_build_query(['key' => $k, 'q' => mb_substr($q, 0, 95), 'per_page' => 20, 'safesearch' => 'true', 'image_type' => 'all']
                + ($wide ? ['orientation' => 'horizontal', 'min_width' => 1200] : [])), []];
        }
        if (($k = self::key('unsplash')) !== '') {
            $reqs[] = ['unsplash', 'https://api.unsplash.com/search/photos?' . http_build_query(['query' => $q, 'per_page' => 15, 'content_filter' => 'high']
                + ($wide ? ['orientation' => 'landscape'] : [])), ["Authorization: Client-ID $k"]];
        }
        $res = self::multi($reqs);
        if (($k = self::key('serper')) !== '') {
            $res['serper'] = self::serperRaw($q, $k);
        }

        $out = [];
        foreach ((array)($res['wikipedia']['query']['pages'] ?? []) as $pg) {
            $o = $pg['original'] ?? null;
            $t = $pg['thumbnail'] ?? null;
            if (!$o && !$t) {
                continue;
            }
            $src = (string)($o['source'] ?? $t['source']);
            if (preg_match('/\.(svg|gif|tiff?)(\?|$)/i', $src) || preg_match('/flag|logo|coat_of_arms|emblem|map|icon|signature/i', $src)) {
                continue;
            }
            $out[] = ['url' => (string)($t['source'] ?? $src), 'alt' => $src, 'w' => (int)($o['width'] ?? $t['width'] ?? 0), 'h' => (int)($o['height'] ?? $t['height'] ?? 0),
                'text' => $pg['title'] . ' ' . ($pg['description'] ?? '') . ' ' . urldecode(basename($src)), 'src' => 'wikipedia', 'rank' => (int)($pg['index'] ?? 9),
                'title' => (string)$pg['title']];
        }
        foreach ((array)($res['openverse']['results'] ?? []) as $r) {
            $out[] = ['url' => (string)$r['url'], 'w' => (int)($r['width'] ?? 0), 'h' => (int)($r['height'] ?? 0),
                'text' => ($r['title'] ?? '') . ' ' . implode(' ', array_column((array)($r['tags'] ?? []), 'name')), 'src' => 'openverse'];
        }
        foreach ((array)($res['commons']['query']['pages'] ?? []) as $pg) {
            $ii = $pg['imageinfo'][0] ?? null;
            if (!$ii || !in_array($ii['mime'] ?? '', ['image/jpeg', 'image/png'], true)) {
                continue;
            }
            $out[] = ['url' => (string)($ii['thumburl'] ?? $ii['url']), 'alt' => (string)$ii['url'], 'w' => (int)($ii['width'] ?? 0), 'h' => (int)($ii['height'] ?? 0),
                'text' => preg_replace('/^File:|\.\w+$/', '', (string)$pg['title']), 'src' => 'commons'];
        }
        foreach ((array)($res['pexels']['photos'] ?? []) as $r) {
            $out[] = ['url' => (string)($r['src'][$wide ? 'large2x' : 'large'] ?? ''), 'w' => (int)$r['width'], 'h' => (int)$r['height'],
                'text' => (string)($r['alt'] ?? '') . ' ' . str_replace('-', ' ', basename(rtrim((string)($r['url'] ?? ''), '/'))), 'src' => 'pexels'];
        }
        foreach ((array)($res['pixabay']['hits'] ?? []) as $r) {
            $out[] = ['url' => (string)($r['largeImageURL'] ?? $r['webformatURL']), 'w' => (int)$r['imageWidth'], 'h' => (int)$r['imageHeight'],
                'text' => (string)($r['tags'] ?? ''), 'src' => 'pixabay'];
        }
        foreach ((array)($res['unsplash']['results'] ?? []) as $r) {
            $out[] = ['url' => (string)($r['urls']['regular'] ?? ''), 'w' => (int)$r['width'], 'h' => (int)$r['height'],
                'text' => trim(($r['alt_description'] ?? '') . ' ' . ($r['description'] ?? '') . ' ' . implode(' ', array_column((array)($r['tags'] ?? []), 'title'))), 'src' => 'unsplash'];
        }
        foreach (array_slice((array)($res['serper']['images'] ?? []), 0, 10) as $r) {
            $out[] = ['url' => (string)($r['imageUrl'] ?? ''), 'w' => (int)($r['imageWidth'] ?? 0), 'h' => (int)($r['imageHeight'] ?? 0),
                'text' => (string)($r['title'] ?? ''), 'src' => 'serper'];
        }
        $out = array_values(array_filter($out, fn($c) => $c['url'] !== '' && preg_match('#^https://#', $c['url'])));
        foreach ($out as &$c) {                       // Wikimedia asl fayllari juda katta — standart o'lchamli nusxa (1280/1920 px)
            $t = self::wmThumb($c['url'], (int)$c['w'], $wide);
            if ($t !== $c['url']) {
                $c['alt'] = $c['alt'] ?? $c['url'];
                $c['url'] = $t;
            }
        }
        unset($c);
        if ($out) {
            try {
                db()->prepare('INSERT INTO engine_kv (k, v, ts) VALUES (?, ?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v, ts = excluded.ts')
                    ->execute([$ck, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), time()]);
            } catch (Throwable $e) {
            }
        }
        return $out;
    }

    /** upload.wikimedia.org dagi asl fayl → standart o'lchamli nusxa (Wikimedia faqat 960/1280/1920… o'lchamlarni beradi). */
    private static function wmThumb(string $url, int $w, bool $wide): string
    {
        if (!preg_match('#^(https://upload\.wikimedia\.org/wikipedia/(?:commons|en))/([0-9a-f])/([0-9a-f]{2})/([^/?]+)$#', $url, $m)
            || !preg_match('/\.(jpe?g|png)$/i', $m[4])) {
            return $url;
        }
        $tw = $wide ? 1920 : 1280;
        if ($w > 0 && $w <= $tw) {
            return $url;                               // asl fayl o'zi kichik
        }
        return "{$m[1]}/thumb/{$m[2]}/{$m[3]}/{$m[4]}/{$tw}px-{$m[4]}";
    }

    /** Qidiruv so'zlari (kichik harf, o'zak). */
    private static function words(string $s): array
    {
        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s)) ?: [] as $w) {
            if (mb_strlen($w) >= 3 && !in_array($w, self::STOP, true)) {
                $out[] = mb_strlen($w) > 5 ? mb_substr($w, 0, mb_strlen($w) - 2) : $w;   // panels/panel, buildings/building
            }
        }
        return array_values(array_unique($out));
    }

    /** Nomzod bali (0 — mos emas). */
    private static function score(array $c, array $kw, array $tw, bool $wide): float
    {
        $text = ' ' . mb_strtolower(preg_replace('/[_\-]+/u', ' ', (string)$c['text']) ?? '') . ' ';
        $hit = 0;
        foreach ($kw as $w) {
            if (mb_strpos($text, $w) !== false) {
                $hit++;
            }
        }
        if (!$kw) {
            return 0;
        }
        $ratio = $hit / count($kw);
        $need = count($kw) >= 3 ? 0.5 : (count($kw) === 2 ? 0.5 : 1.0);
        if ($ratio + 1e-9 < $need) {
            return 0;                                // asosiy so'zlarning yarmi ham yo'q — mos emas
        }
        $s = $ratio * 10;
        foreach ($tw as $w) {
            if (mb_strpos($text, $w) !== false) {
                $s += 1.5;                           // umumiy mavzu so'zlari ham bor
            }
        }
        $s += ['pexels' => 2.0, 'unsplash' => 2.0, 'pixabay' => 1.5, 'serper' => 1.0, 'wikipedia' => 1.0, 'openverse' => 0.5, 'commons' => 0][$c['src']] ?? 0;
        if ($c['src'] === 'wikipedia') {             // maqola nomi iborani to'liq qamrasa — aynan o'sha narsa (shaxs/joy)
            $tl = mb_strtolower((string)($c['title'] ?? ''));
            $title = self::words($tl);
            if ($title && count(array_intersect($title, $kw)) === count($title)) {
                $s += 4 - min(3, (int)($c['rank'] ?? 0));
            }
        }
        $w = (int)$c['w'];
        $h = (int)$c['h'];
        if ($w > 0 && $h > 0) {
            if ($wide && $w < $h) {
                $s -= 4;                             // PRO slaydga tik rasm yaramaydi
            }
            if ($w >= 1200) {
                $s += 1;
            } elseif ($w < ($wide ? 1100 : 500)) {
                $s -= 5;
            }
        }
        return max(0.01, $s);
    }

    /** Rasmni tushunadigan AI: «bu rasm iboraga mosmi?» (true/false; aniqlab bo'lmasa — null). */
    private static function visionOk(string $path, string $query): ?bool
    {
        try {
            $raw = (string)file_get_contents($path);
            if (function_exists('imagecreatefromstring') && ($im = @imagecreatefromstring($raw))) {
                $k = min(1, 512 / max(imagesx($im), imagesy($im)));
                $d = imagecreatetruecolor(max(1, (int)(imagesx($im) * $k)), max(1, (int)(imagesy($im) * $k)));
                imagecopyresampled($d, $im, 0, 0, 0, 0, imagesx($d), imagesy($d), imagesx($im), imagesy($im));
                ob_start();
                imagejpeg($d, null, 75);
                $raw = (string)ob_get_clean();
            }
            $topic = self::$topicEn !== '' ? ' (presentation topic: "' . self::$topicEn . '")' : '';
            $a = AIClient::generateText("Does this image clearly show \"$query\"$topic, so it fits a student presentation slide about it? "
                . 'Answer only YES or NO.', '', 200, ['temperature' => 0, 'image' => base64_encode($raw)]);
            if (preg_match('/\b(yes|ha)\b/i', $a)) {
                return true;
            }
            return preg_match('/\b(no|yo\'?q)\b/i', $a) ? false : null;
        } catch (Throwable $e) {
            return null;                              // AI ishlamasa — tekshiruvsiz qabul
        }
    }

    // ------------------------------------------------------------------ tarmoq

    private static function ua(): string
    {
        return 'StudyBotImage/1.2 (' . (defined('PUBLIC_BASE_URL') && PUBLIC_BASE_URL !== '' ? PUBLIC_BASE_URL : 'https://github.com/davauzb/Botphp') . '; educational presentation generator) curl';
    }

    /** Parallel GET so'rovlar. @param array<int,array{0:string,1:string,2:array}> $reqs @return array<string,array> */
    private static function multi(array $reqs): array
    {
        $mh = curl_multi_init();
        $hs = [];
        foreach ($reqs as [$name, $url, $headers]) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_USERAGENT => self::ua(),
                CURLOPT_HTTPHEADER => $headers, CURLOPT_ENCODING => '']);
            curl_multi_add_handle($mh, $ch);
            $hs[$name] = $ch;
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $st === CURLM_OK);
        $out = [];
        foreach ($hs as $name => $ch) {
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body = (string)curl_multi_getcontent($ch);
            if ($code === 200) {
                $out[$name] = json_decode($body, true) ?: [];
            } elseif ($code !== 0) {
                logError('image_search', "$name: HTTP $code");
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    private static function serperRaw(string $q, string $key): array
    {
        $ch = curl_init('https://google.serper.dev/images');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ["X-API-KEY: $key", 'Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['q' => $q])]);
        $r = curl_exec($ch);
        curl_close($ch);
        return json_decode((string)$r, true) ?: [];
    }

    private static function pollinationsUrl(string $query): ?string
    {
        $q = trim(preg_replace('/[^\p{L}\p{N}\s,\-]/u', ' ', $query) ?? '');
        if ($q === '') {
            return null;
        }
        $prompt = $q . (self::$topicEn !== '' ? ', ' . self::$topicEn : '') . ', realistic photograph, natural light, high detail, no text, no watermark';
        return 'https://image.pollinations.ai/prompt/' . rawurlencode($prompt) . '?' . http_build_query([
            'width' => 1600, 'height' => 900, 'nologo' => 'true', 'seed' => random_int(1, 999999),
        ]);
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
                "Give a short English image-search phrase (2-5 words) that names a CONCRETE, photographable object or scene for this "
                . "presentation slide (no abstract words like development, importance, role). If it is about a specific person, place or event, use its English name. "
                . "Reply with ONLY the phrase: \"$text\"",
                'You are a search-query tool. Output only the phrase, nothing else.',
                200 // reasoning modellar (gpt-oss) tokenning bir qismini "o'ylash"ga sarflaydi
            );
            $translated = trim($translated, " \t\n\r\0\x0B\"'.");
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
            CURLOPT_USERAGENT => self::ua(),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_MAXFILESIZE => 12 * 1024 * 1024,
        ]);
        $data = curl_exec($ch);
        $ok = curl_errno($ch) === 0 && (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
        if (!$ok || !$data) {
            return null;
        }
        // Rasmning HAQIQIY formati (URL kengaytmasiga ishonib bo'lmaydi). PPTX faqat jpg/png/gif'ni ishonchli ko'rsatadi.
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
        // Juda katta rasmni (masalan Wikipedia asl fayli) 2000 px gacha kichraytiramiz — fayl hajmi oshib ketmasin
        if ((int)($info[0] ?? 0) > 2400 && function_exists('imagecreatefromstring') && ($im = @imagecreatefromstring($data))) {
            $k = 2000 / imagesx($im);
            $d = imagecreatetruecolor(2000, max(1, (int)(imagesy($im) * $k)));
            imagecopyresampled($d, $im, 0, 0, 0, 0, imagesx($d), imagesy($d), imagesx($im), imagesy($im));
            ob_start();
            imagejpeg($d, null, 85);
            $data = (string)ob_get_clean();
            $ext = 'jpg';
        }
        $path = GENERATED_DIR . 'img_' . uniqid() . '.' . $ext;
        if (file_put_contents($path, $data) === false) {
            return null;
        }
        return $path;
    }
}
