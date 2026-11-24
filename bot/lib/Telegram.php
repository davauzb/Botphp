<?php

class Telegram
{
    /**
     * Har qanday Telegram API metodini chaqiradi.
     * Hosting/tarmoq muammolarida (timeout, DNS, vaqtincha uzilish) 3 marta
     * qayta uradi, orasiga kutish (backoff) qo'yadi — bitta muvaqqat uzilish
     * butun so'rovni yiqitmaydi.
     */
    /** Rangli tugmalar o'chirilgan/qo'llab-quvvatlanmagan bo'lsa true bo'ladi (shu so'rov davomida). */
    private static bool $noStyle = false;

    /**
     * 🎨 RANGLI TUGMALAR (Bot API 10.4: «style» — primary ko'k, success yashil, danger qizil).
     * Tugma vazifasiga qarab rang o'zi beriladi; Telegram rad etsa — shu so'rov rangsiz qayta yuboriladi.
     * O'chirish: admin panel → Sozlamalar → «🎨 Rangli tugmalar».
     */
    public static function colorize(array $markup): array
    {
        $pick = function (array $b): ?string {
            if (isset($b['style'])) {
                return null;
            }
            $t = (string)($b['text'] ?? '');
            $d = (string)($b['callback_data'] ?? '');
            if (preg_match('/^(job:c:|topup_reject:|humo_cancel:|sup:cancel)/', $d) || preg_match('/^(✖️|❌|🗑)/u', $t)) {
                return 'danger';
            }
            if (preg_match('/^(stu:use:|sub:buy|grp:ok|job:xy:|vs:ok|rw:|topup_approve:|plan:ok:|upd:go|humo_check:)/', $d) || preg_match('/^(✅|💎|🔁 Ha|🚀)/u', $t)) {
                return 'success';
            }
            if (preg_match('/^(vol:|edit:|job:r:|tr:|trl:|pdf:|grp:s:|tts:menu|tts:fallback|vs:g:|rp:|rpp:|rpm:|topup_amount:|topup_method:|design(std|pro)?:|ro:|rt:|dl:|plan:ed:|ord:)/', $d) || isset($b['web_app'])) {
                return 'primary';
            }
            return null;
        };
        foreach (['inline_keyboard', 'keyboard'] as $k) {
            if (!isset($markup[$k]) || !is_array($markup[$k])) {
                continue;
            }
            foreach ($markup[$k] as $ri => $row) {
                foreach ((array)$row as $bi => $b) {
                    if (!is_array($b)) {
                        continue;
                    }
                    $style = $pick($b);
                    if ($k === 'keyboard' && $style === null) {           // pastki menyu: buyurtma turlari ko'k, balans yashil
                        $t = (string)($b['text'] ?? '');
                        $style = class_exists('DocTypes') && DocTypes::fromText($t) !== null ? 'primary' : ($t === '💰 Balans' || $t === '💎 Obuna' ? 'success' : null);
                    }
                    if ($style !== null) {
                        $markup[$k][$ri][$bi]['style'] = $style;
                    }
                }
            }
        }
        return $markup;
    }

    private static function colorsOn(): bool
    {
        if (self::$noStyle || !class_exists('Engine')) {
            return false;
        }
        try {
            return (bool)Engine::c('BUTTON_COLORS', true);
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function call(string $method, array $params = [], array $files = []): ?array
    {
        $plainMarkup = null;
        if (isset($params['reply_markup']) && is_string($params['reply_markup']) && self::colorsOn()) {
            $m = json_decode($params['reply_markup'], true);
            if (is_array($m)) {
                $styled = json_encode(self::colorize($m), JSON_UNESCAPED_UNICODE);
                if ($styled !== false && $styled !== $params['reply_markup']) {
                    $plainMarkup = $params['reply_markup'];
                    $params['reply_markup'] = $styled;
                }
            }
        }
        $res = self::rawCall($method, $params, $files);
        if ($plainMarkup !== null && is_array($res) && empty($res['ok']) && (int)($res['error_code'] ?? 0) === 400
            && preg_match('/style|button|reply markup|keyboard/i', (string)($res['description'] ?? ''))) {
            self::$noStyle = true;                     // Telegram rangni qabul qilmadi — rangsiz qayta
            $params['reply_markup'] = $plainMarkup;
            $res = self::rawCall($method, $params, $files);
        }
        return $res;
    }

    private static function rawCall(string $method, array $params = [], array $files = []): ?array
    {
        $url = TELEGRAM_API . $method;
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            $payload = $files ? self::buildMultipart($params, $files) : $params;

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $curlErrno = curl_errno($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($curlErrno === 0 && $response !== false) {
                $data = json_decode($response, true);
                if (is_array($data) && ($data['ok'] ?? false)) {
                    return $data;
                }
                // Telegram 429 (too many requests) qaytarsa, kutib qayta urinamiz
                if ($httpCode === 429 && isset($data['parameters']['retry_after'])) {
                    sleep((int)$data['parameters']['retry_after']);
                    continue;
                }
                logError('telegram', "API xato ($method): " . $response);
                return $data;
            }

            logError('telegram', "cURL xato ($method), urinish $attempt: errno=$curlErrno");
            usleep(300000 * $attempt); // 0.3s, 0.6s, 0.9s — progressiv kutish
        }

        return null;
    }

    /** Xabar yuboradi. Qaytadi: yuborilgan xabar id'si (bilib bo'lmasa null). */
    public static function sendMessage(int $chatId, string $text, ?array $keyboard = null): ?int
    {
        $params = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($keyboard) {
            $params['reply_markup'] = json_encode($keyboard);
        }
        $r = self::call('sendMessage', $params);
        return isset($r['result']['message_id']) ? (int)$r['result']['message_id'] : null;
    }

    /**
     * Xabarni TEZ tahrirlaydi: bloklamaydi (kutish yo'q), 429 bo'lsa qancha kutish kerakligini qaytaradi.
     * Qaytadi: ['ok'=>bool, 'retry'=>int soniya|null, 'gone'=>xabar topilmadi, 'same'=>o'zgarish yo'q]
     */
    public static function editQuick(int $chatId, int $messageId, string $text, ?array $keyboard = null): array
    {
        $params = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true'];
        if ($keyboard !== null) {
            $params['reply_markup'] = json_encode($keyboard);
        }
        $ch = curl_init(TELEGRAM_API . 'editMessageText');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $params, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 7, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_NOSIGNAL => true]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = is_string($resp) ? json_decode($resp, true) : null;
        $out = ['ok' => false, 'retry' => null, 'gone' => false, 'same' => false];
        if (is_array($data) && !empty($data['ok'])) {
            $out['ok'] = true;
            return $out;
        }
        $desc = (string)($data['description'] ?? '');
        if ($code === 429) {
            $out['retry'] = max(1, (int)($data['parameters']['retry_after'] ?? 5));
        } elseif (stripos($desc, 'not modified') !== false) {
            $out['same'] = true;
        } elseif (stripos($desc, 'not found') !== false || stripos($desc, "can't be edited") !== false) {
            $out['gone'] = true;
        }
        return $out;
    }

    public static function answerCallbackQuery(string $id, string $text = '', bool $alert = false): void
    {
        self::call('answerCallbackQuery', ['callback_query_id' => $id, 'text' => $text, 'show_alert' => $alert ? 'true' : 'false']);
    }

    public static function deleteMessage(int $chatId, int $messageId): void
    {
        self::call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    }

    /** Oxirgi muvaffaqiyatli sendDocument qaytargan Telegram file_id (keyinroq qayta yuborish uchun saqlanadi). */
    public static ?string $lastFileId = null;

    /** Telegram'da allaqachon turgan faylni file_id orqali qayta yuboradi (qayta yuklash shart emas). */
    public static function sendDocumentById(int $chatId, string $fileId, string $caption = '', ?array $keyboard = null): bool
    {
        $params = ['chat_id' => $chatId, 'document' => $fileId, 'caption' => $caption, 'parse_mode' => 'HTML'];
        if ($keyboard) {
            $params['reply_markup'] = json_encode($keyboard);
        }
        $r = self::call('sendDocument', $params);
        return (bool)($r['ok'] ?? false);
    }

    public static function sendDocument(int $chatId, string $filePath, string $caption = '', ?array $keyboard = null, ?string $filename = null): bool
    {
        self::$lastFileId = null;
        if (!file_exists($filePath)) {
            logError('telegram', "Yuborilmoqchi bo'lgan fayl topilmadi: $filePath");
            return false;
        }
        $params = ['chat_id' => $chatId, 'caption' => $caption, 'parse_mode' => 'HTML'];
        if ($keyboard) {
            $params['reply_markup'] = json_encode($keyboard);
        }
        $result = self::call('sendDocument', $params, [
            'document' => $filename !== null ? [$filePath, $filename] : $filePath,
        ]);
        if (!empty($result['ok'])) {
            self::$lastFileId = (string)($result['result']['document']['file_id'] ?? '') ?: null;
        }
        return (bool)($result['ok'] ?? false);
    }

    /**
     * Telegram'da allaqachon mavjud fayl (file_id) orqali rasm yuboradi — qayta
     * yuklashning hojati yo'q. Chekni foydalanuvchidan qabul qilib, o'zgarishsiz
     * adminga forward qilishda ishlatiladi.
     */
    /**
     * Rasm yuboradi. $photo YOKI Telegram file_id (masalan foydalanuvchi yuborgan
     * chekni forward qilishda), YOKI mahalliy diskdagi fayl yo'li (masalan dizayn
     * namunalari kollaji) bo'lishi mumkin — avtomatik aniqlanadi.
     */
    public static function sendPhoto(int $chatId, string $photo, string $caption = '', ?array $keyboard = null): bool
    {
        $params = ['chat_id' => $chatId, 'caption' => $caption, 'parse_mode' => 'HTML'];
        if ($keyboard) {
            $params['reply_markup'] = json_encode($keyboard);
        }

        if (is_file($photo)) {
            $result = self::call('sendPhoto', $params, ['photo' => $photo]);
        } else {
            $params['photo'] = $photo;
            $result = self::call('sendPhoto', $params);
        }
        return (bool)($result['ok'] ?? false);
    }

    private static function buildMultipart(array $params, array $files): array
    {
        foreach ($files as $key => $path) {
            // Fayl nomi sifatida to'liq server yo'li emas, faqat fayl nomi yuborilsin (ichki papka tuzilmasi oshkor bo'lmasin)
            $params[$key] = is_array($path) ? new CURLFile($path[0], null, $path[1]) : new CURLFile($path, null, basename($path));
        }
        return $params;
    }

    public static function notifyAdmin(string $message): void
    {
        if (ADMIN_CHAT_ID) {
            self::sendMessage(ADMIN_CHAT_ID, "⚠️ " . $message);
        }
    }

    /**
     * Foydalanuvchi kiritgan matnni (mavzu va h.k.) xabarga qo'shishdan oldin
     * shu funksiya orqali o'tkazish SHART — aks holda mavzuda "<", ">" yoki "&"
     * belgisi bo'lsa (masalan matematik mavzular), Telegram xabarni HTML sifatida
     * noto'g'ri talqin qilib, butunlay yubormay qo'yadi.
     */
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
