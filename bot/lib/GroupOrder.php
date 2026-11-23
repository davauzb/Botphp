<?php

/**
 * 👥 Guruh buyurtmasi: guruh sardori bir yo'la bir nechta talaba uchun buyurtma beradi.
 *  Oqim: tur + hajm (+ slayd dizayni) tanlanadi → mavzu so'ralganda «👥 Guruh uchun» tugmasi →
 *  ro'yxat yuboriladi («Familiya Ism — Mavzu», har biri yangi qatorda) → narx/chegirma ko'rsatiladi →
 *  «✅ Tasdiqlash» → balansdan bir marta yechiladi va har bir talaba uchun alohida ish yaratiladi
 *  (titulda — o'sha talabaning ismi; guruh, universitet, kafedra — buyurtmachinikidek).
 *  Fayllar tayyor bo'lishi bilan bittadan keladi (izohda talaba ismi). Bitta umumiy xabar, ko'p progress xabarlari emas.
 * Sozlamalar: GROUP_ENABLED, GROUP_DISCOUNT (foiz), GROUP_MIN (chegirma uchun kamida nechta), GROUP_MAX.
 */
class GroupOrder
{
    public static function enabled(): bool
    {
        return (bool)Engine::c('GROUP_ENABLED', true);
    }

    public static function discount(): int
    {
        return max(0, min(70, (int)Engine::c('GROUP_DISCOUNT', 15)));
    }

    public static function minForDiscount(): int
    {
        return max(2, (int)Engine::c('GROUP_MIN', 3));
    }

    public static function max(): int
    {
        return max(2, min(40, (int)Engine::c('GROUP_MAX', 30)));
    }

    public static function button(string $type, string $vk): ?array
    {
        if (!self::enabled() || in_array($type, ['test'], true)) {
            return null;
        }
        return ['inline_keyboard' => [[['text' => '👥 Guruh uchun (bir nechta talaba)', 'callback_data' => "grp:s:$type:$vk"]]]];
    }

    /** Bir dona narxi (PRO dizayn bo'lsa — PRO narx). */
    private static function unitPrice(int $tg, string $type, string $vk): ?int
    {
        $vols = DocTypes::volumes($type) ?? [];
        if (!isset($vols[$vk])) {
            return null;
        }
        $price = (int)$vols[$vk]['price'];
        if ($type === 'slayd') {
            $st = db()->prepare('SELECT pending_design_key FROM users WHERE telegram_id = ?');
            $st->execute([$tg]);
            if (isProDesign((string)($st->fetchColumn() ?: ''))) {
                $price = ProDeck::price($price);
            }
        }
        return $price;
    }

    private static function eachPrice(int $unit, int $n): int
    {
        if ($n < self::minForDiscount() || self::discount() <= 0) {
            return $unit;
        }
        return (int)(ceil($unit * (100 - self::discount()) / 100 / 100) * 100);   // 100 so'mga yaxlitlab
    }

    public static function start(int $tg, string $type, string $vk): void
    {
        if (!self::enabled() || !DocTypes::exists($type) || self::unitPrice($tg, $type, $vk) === null) {
            Telegram::sendMessage($tg, "Bu so'rov eskirgan. Qaytadan boshlang: /start");
            return;
        }
        $st = db()->prepare('SELECT state FROM users WHERE telegram_id = ?');
        $st->execute([$tg]);
        if ((string)$st->fetchColumn() !== "awaiting_topic:$type:$vk") {
            Telegram::sendMessage($tg, "Bu so'rov eskirgan. Buyurtmani qaytadan boshlang.");
            return;
        }
        setUserState($tg, "awaiting_group:$type:$vk");
        $unit = self::unitPrice($tg, $type, $vk);
        $d = self::discount();
        Telegram::sendMessage($tg, "👥 <b>Guruh buyurtmasi</b> — " . Telegram::escape(DocTypes::label($type)) . "\n\n"
            . "Har bir talabani <b>yangi qatorda</b> shunday yozing:\n<code>Familiya Ism — Mavzu</code>\n\nMasalan:\n"
            . "<code>Aliyev Vali — Raqamli iqtisodiyot\nKarimova Nodira — Bozor iqtisodiyoti asoslari\nToshmatov Sardor — Soliq tizimi</code>\n\n"
            . "💳 Bittasi: " . number_format($unit, 0, '', ' ') . " so'm"
            . ($d > 0 ? "\n🎁 " . self::minForDiscount() . " va undan ko'p talaba uchun — har biriga <b>{$d}% chegirma</b>" : '')
            . "\n📌 Guruh, universitet va kafedra — profilingizdagidek bo'ladi. Ko'pi bilan " . self::max() . " ta.",
            ['inline_keyboard' => [[['text' => '✖️ Bekor qilish', 'callback_data' => 'grp:no']]]]);
    }

    /** Ro'yxatni ajratish: [[name, topic], ...] va xatolar. */
    public static function parse(string $text): array
    {
        $items = [];
        $errors = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $i => $line) {
            $line = trim(preg_replace('/^\s*(\d+[\.\)]|[•\-–])\s*/u', '', $line) ?? $line);
            if ($line === '') {
                continue;
            }
            $p = preg_split('/\s+[—–\-|]\s+|\s*[—|:]\s*/u', $line, 2);
            if (count($p) < 2) {
                $errors[] = ($i + 1) . "-qator: «—» belgisi topilmadi";
                continue;
            }
            $name = trim(preg_replace('/\s+/u', ' ', $p[0]) ?? '');
            $topic = trim(preg_replace('/\s+/u', ' ', $p[1]) ?? '');
            if (mb_strlen($name) < 3 || mb_strlen($name) > 60 || preg_match('/[<>\d]/u', $name)) {
                $errors[] = ($i + 1) . "-qator: ism noto'g'ri";
                continue;
            }
            if (mb_strlen($topic) < 3 || mb_strlen($topic) > 300) {
                $errors[] = ($i + 1) . "-qator: mavzu juda " . (mb_strlen($topic) < 3 ? 'qisqa' : 'uzun');
                continue;
            }
            $items[] = [$name, $topic];
        }
        return [$items, $errors];
    }

    public static function onText(int $tg, string $state, string $text): void
    {
        [, $type, $vk] = array_pad(explode(':', $state, 3), 3, '');
        [$items, $errors] = self::parse($text);
        if ($errors && count($errors) > count($items)) {
            Telegram::sendMessage($tg, "⚠️ Ro'yxatni tushunmadim:\n• " . Telegram::escape(implode("\n• ", array_slice($errors, 0, 6)))
                . "\n\nHar qatorda: <code>Familiya Ism — Mavzu</code>. Qaytadan yuboring:");
            return;
        }
        if (count($items) < 2) {
            Telegram::sendMessage($tg, "Guruh buyurtmasi uchun kamida 2 ta talaba kerak. Bitta bo'lsa — oddiy buyurtma bering. Qaytadan yuboring:");
            return;
        }
        if (count($items) > self::max()) {
            Telegram::sendMessage($tg, "Ko'pi bilan " . self::max() . " ta talaba (siz " . count($items) . " ta yubordingiz). Ro'yxatni qisqartirib qayta yuboring:");
            return;
        }
        $unit = self::unitPrice($tg, $type, $vk);
        if ($unit === null) {
            setUserState($tg, 'idle');
            Telegram::sendMessage($tg, "Xatolik — qaytadan boshlang: /start");
            return;
        }
        $each = self::eachPrice($unit, count($items));
        $total = $each * count($items);
        Engine::kvSet('grp:' . $tg, json_encode(['type' => $type, 'vk' => $vk, 'items' => $items, 'each' => $each, 'unit' => $unit], JSON_UNESCAPED_UNICODE));
        $lines = [];
        foreach ($items as $k => [$n, $t]) {
            $lines[] = ($k + 1) . ". <b>" . Telegram::escape($n) . "</b> — " . Telegram::escape($t);
        }
        $save = ($unit - $each) * count($items);
        Telegram::sendMessage($tg, "👥 <b>Guruh buyurtmasi</b> — " . Telegram::escape(DocTypes::label($type)) . "\n\n" . implode("\n", $lines)
            . ($errors ? "\n\n⚠️ O'tkazib yuborildi: " . Telegram::escape(implode('; ', $errors)) : '')
            . "\n\n💳 Jami: <b>" . number_format($total, 0, '', ' ') . " so'm</b> (" . count($items) . " × " . number_format($each, 0, '', ' ') . ")"
            . ($save > 0 ? "\n🎁 Tejaldi: " . number_format($save, 0, '', ' ') . " so'm" : '')
            . "\n💰 Balansingiz: " . number_format(getUserBalance($tg), 0, '', ' ') . " so'm",
            ['inline_keyboard' => [[['text' => '✅ Tasdiqlash', 'callback_data' => 'grp:ok'], ['text' => '✖️ Bekor qilish', 'callback_data' => 'grp:no']]]]);
    }

    public static function cancel(int $tg): void
    {
        db()->prepare('DELETE FROM engine_kv WHERE k = ?')->execute(['grp:' . $tg]);
        setUserState($tg, 'idle');
        Telegram::sendMessage($tg, "Bekor qilindi.", mainMenu());
    }

    public static function confirm(int $tg): void
    {
        $kv = Engine::kvGet('grp:' . $tg);
        $g = $kv ? json_decode((string)$kv['v'], true) : null;
        $st = db()->prepare('SELECT * FROM users WHERE telegram_id = ?');
        $st->execute([$tg]);
        $user = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!is_array($g) || empty($g['items']) || time() - (int)$kv['ts'] > 6 * 3600) {
            Telegram::sendMessage($tg, "Bu so'rov eskirgan yoki allaqachon tasdiqlangan.");
            return;
        }
        // Ikki marta bosilsa — faqat bittasi o'tadi (qoralama atomik olinadi)
        $claim = db()->prepare('DELETE FROM engine_kv WHERE k = ? AND ts = ?');
        $claim->execute(['grp:' . $tg, (int)$kv['ts']]);
        if (!$claim->rowCount()) {
            return;
        }
        // Narx o'zgargan bo'lsa (admin narxni o'zgartirdi / dizayn) — qayta hisoblaymiz
        $unit = self::unitPrice($tg, (string)$g['type'], (string)$g['vk']);
        if ($unit === null) {
            Telegram::sendMessage($tg, "Bu hajm endi mavjud emas — qaytadan boshlang.", mainMenu());
            return;
        }
        $n = count($g['items']);
        $each = self::eachPrice($unit, $n);
        $total = $each * $n;
        $b = db()->prepare('UPDATE users SET balance = balance - ? WHERE telegram_id = ? AND balance >= ?');
        $b->execute([$total, $tg, $total]);
        if (!$b->rowCount()) {
            Engine::kvSet('grp:' . $tg, (string)$kv['v']);    // qoralama saqlanadi — to'ldirgach yana tasdiqlash mumkin
            $need = max(0, $total - getUserBalance($tg));
            Telegram::sendMessage($tg, "⚠️ Balans yetarli emas: kerak " . number_format($total, 0, '', ' ') . " so'm (yana " . number_format($need, 0, '', ' ')
                . " so'm). Balansni to'ldirgach, shu yerdagi «✅ Tasdiqlash» ni bosing — ro'yxat saqlanib turadi.", ['inline_keyboard' => [
                    [['text' => "💳 Balansni to'ldirish", 'callback_data' => 'topup_amount:' . max((int)Engine::c('MIN_TOPUP_AMOUNT', 1000), (int)(ceil($need / 1000) * 1000))]],
                    [['text' => '✅ Tasdiqlash', 'callback_data' => 'grp:ok'], ['text' => '✖️ Bekor qilish', 'callback_data' => 'grp:no']],
                ]]);
            return;
        }
        if (str_starts_with((string)($user['state'] ?? ''), 'awaiting_group:')) {
            setUserState($tg, 'idle');
        }
        $base = StudentFlow::forJob($tg) ?: StudentFlow::toStudent(StudentFlow::saved($tg));
        $designKey = null;
        if ($g['type'] === 'slayd') {
            $designKey = (string)($user['pending_design_key'] ?? '');
            if (!isset(pptxDesigns()[$designKey])) {
                $designKey = DEFAULT_PPTX_DESIGN;
            }
        }
        $lang = I18n::userLang($tg);
        $ids = [];
        try {
            foreach ($g['items'] as [$name, $topic]) {
                $parts = preg_split('/\s+/u', $name) ?: [$name];
                $stu = array_merge($base, ['full_name' => $name, 'last_name' => (string)$parts[0], 'first_name' => trim(implode(' ', array_slice($parts, 1)))]);
                $id = Queue::createJob($tg, (string)$g['type'], (string)$topic, (string)$g['vk'], $each, false, true, $designKey, $lang, $stu);
                $ids[] = $id;
                // pmsg_id = -2: alohida progress xabari yo'q (bitta umumiy xabar)
                db()->prepare('UPDATE jobs SET paid_by = ?, group_id = ?, pmsg_id = -2 WHERE id = ?')->execute(['balance', $ids[0], $id]);
            }
        } catch (Throwable $e) {
            logError('group', $e->getMessage());
            $made = count($ids);
            if ($made) {
                db()->exec('UPDATE jobs SET status = \'cancelled\' WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
            }
            db()->prepare('UPDATE users SET balance = balance + ? WHERE telegram_id = ?')->execute([$total, $tg]);
            Telegram::sendMessage($tg, "⚠️ Guruh buyurtmasini yaratib bo'lmadi, pul to'liq qaytarildi.", mainMenu());
            return;
        }
        $perUser = max(1, (int)Engine::c('ENGINE_MAX_JOBS_PER_USER', 2));
        $eta = (int)ceil($n / $perUser) * Engine::typicalDuration((string)$g['type']);
        Telegram::sendMessage($tg, "✅ <b>Guruh buyurtmasi qabul qilindi!</b> (#" . $ids[0] . "–#" . end($ids) . ")\n"
            . "👥 {$n} ta talaba · 💳 Balansdan: " . number_format($total, 0, '', ' ') . " so'm\n\n"
            . "Har bir fayl tayyor bo'lishi bilan shu chatga keladi (izohida talaba ismi).\n⏱ Hammasi taxminan " . self::fmt($eta) . " ichida tayyor bo'ladi.\n"
            . "Holatini «📋 Buyurtmalarim» bo'limida ko'rishingiz mumkin.", mainMenu());
        try {
            AdminEvents::newOrder($ids[0], $tg, (string)$g['type'], "👥 Guruh: {$n} ta talaba", (string)$g['vk'], $total, false, $base, $user['username'] ?? null);
        } catch (Throwable $e) {
        }
        Engine::kvSet('last_kick', '1');
        Engine::kick(0);
    }

    private static function fmt(int $s): string
    {
        $m = max(1, (int)ceil($s / 60));
        return $m < 60 ? "$m daqiqa" : intdiv($m, 60) . ' soat' . ($m % 60 ? ' ' . ($m % 60) . ' daqiqa' : '');
    }

    /** Fayl izohi uchun: guruh ishida — kim uchun ekanligi. */
    public static function captionPrefix(array $job): string
    {
        if (empty($job['group_id'])) {
            return '';
        }
        $s = json_decode((string)($job['student'] ?? ''), true);
        return !empty($s['full_name']) ? '👥 <b>' . Telegram::escape((string)$s['full_name']) . "</b>\n" : '';
    }
}
