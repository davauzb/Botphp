<?php

/**
 * HAR BUYURTMADA talaba ma'lumotlarini alohida so'rash: ism, familya, guruh, universitet.
 *
 * Oqim: hajm tanlandi → (oldingi ma'lumot bor bo'lsa «shu ma'lumotlar» / «o'zgartirish» tugmalari) → ism → familya → guruh → universitet
 * → buyurtma. Har bosqichda oldingi qiymat bor bo'lsa, «↩️ <oldingi>» tugmasi bilan o'zgartirmasdan o'tish mumkin.
 * Yig'ilgan ma'lumot (users.order_student, qoralama) buyurtma yaratilganda jobs.student ga NUSXALANADI —
 * shu sababli keyinroq profil o'zgarsa ham eski buyurtma titul varag'i o'zgarmaydi.
 * Oxirgi kiritilgan qiymatlar keyingi buyurtmada «oldingi» sifatida taklif qilinadi (users.full_name/university/group_name ham yangilanadi).
 *
 * state: awaiting_stu:<maydon>:<type>:<volume_key>   (type '_edit' — faqat ⚙️ Profil orqali tahrirlash)
 * callback: stu:use:<type>:<vk> | stu:new:<type>:<vk> | stu:keep:<maydon>:<type>:<vk>
 */
class StudentFlow
{
    public const FIELDS = ['first', 'last', 'group', 'univ'];
    /** Ixtiyoriy (titul uchun): kafedra va tekshiruvchi/ilmiy rahbar — «⏭ O'tkazib yuborish» mumkin. */
    public const EXTRA = ['kaf', 'teacher'];
    private const LABEL = ['first' => 'Ism', 'last' => 'Familya', 'group' => 'Guruh', 'univ' => 'Universitet', 'kaf' => 'Kafedra', 'teacher' => "O'qituvchi"];

    private static function extrasWanted(string $type): bool
    {
        return $type !== '_edit' && (bool)Engine::c('TITLE_EXTRAS', true) && DocTypes::kind($type) === 'paper';
    }

    public static function ensureSchema(PDO $pdo): void
    {
        foreach (['jobs' => ['student TEXT'], 'users' => ['order_student TEXT', 'first_name TEXT', 'last_name TEXT', 'kafedra TEXT', 'teacher TEXT']] as $t => $defs) {
            foreach ($defs as $def) {
                try {
                    $pdo->exec("ALTER TABLE $t ADD COLUMN $def");
                } catch (Throwable $e) {
                    // ustun allaqachon bor
                }
            }
        }
    }

    // ------------------------------------------------------------------ saqlangan (oldingi) ma'lumot

    /** Oldingi ma'lumot: ['first','last','group','univ'] (bo'sh bo'lishi mumkin). Eski profilda faqat F.I.Sh bo'lsa, undan ajratib olinadi. */
    public static function saved(int $uid): array
    {
        try {
            $st = db()->prepare('SELECT full_name, university, group_name, first_name, last_name, kafedra, teacher FROM users WHERE telegram_id = ?');
            $st->execute([$uid]);
        } catch (Throwable $e) {           // eski baza (ustunlar hali qo'shilmagan)
            $st = db()->prepare('SELECT full_name, university, group_name, first_name, last_name FROM users WHERE telegram_id = ?');
            $st->execute([$uid]);
        }
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $first = trim((string)($r['first_name'] ?? ''));
        $last = trim((string)($r['last_name'] ?? ''));
        if ($first === '' && $last === '' && !empty($r['full_name'])) {
            $parts = preg_split('/\s+/u', trim((string)$r['full_name'])) ?: [];
            $last = (string)($parts[0] ?? '');
            $first = (string)($parts[1] ?? '');
        }
        return ['first' => $first, 'last' => $last, 'group' => trim((string)($r['group_name'] ?? '')), 'univ' => trim((string)($r['university'] ?? '')),
            'kaf' => trim((string)($r['kafedra'] ?? '')), 'teacher' => trim((string)($r['teacher'] ?? ''))];
    }

    private static function complete(array $d): bool
    {
        foreach (self::FIELDS as $f) {
            if (trim((string)($d[$f] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    private static function draft(int $uid): array
    {
        $st = db()->prepare('SELECT order_student FROM users WHERE telegram_id = ?');
        $st->execute([$uid]);
        $d = json_decode((string)($st->fetchColumn() ?: ''), true);
        return is_array($d) ? $d : [];
    }

    private static function setDraft(int $uid, ?array $d): void
    {
        db()->prepare('UPDATE users SET order_student = ? WHERE telegram_id = ?')
            ->execute([$d === null ? null : json_encode($d, JSON_UNESCAPED_UNICODE), $uid]);
    }

    /** Titul varag'i uchun ko'rinish: ['full_name','university','group_name'] (+ first_name/last_name). */
    public static function toStudent(array $d): array
    {
        $first = trim((string)($d['first'] ?? ''));
        $last = trim((string)($d['last'] ?? ''));
        return [
            'full_name' => trim($last . ' ' . $first), 'first_name' => $first, 'last_name' => $last,
            'university' => trim((string)($d['univ'] ?? '')), 'group_name' => trim((string)($d['group'] ?? '')),
            'kafedra' => trim((string)($d['kaf'] ?? '')), 'teacher' => trim((string)($d['teacher'] ?? '')),
        ];
    }

    /** createJob uchun: shu foydalanuvchining oxirgi to'liq to'ldirilgan qoralamasi, bo'lmasa saqlangan profil. */
    public static function forJob(int $uid): array
    {
        $d = self::draft($uid);
        if (!self::complete($d)) {
            $d = self::saved($uid);
        }
        return self::complete($d) ? self::toStudent($d) : [];
    }

    // ------------------------------------------------------------------ oqim

    private static function summary(array $d): string
    {
        $e = fn($k) => Telegram::escape((string)($d[$k] ?? '—'));
        return "👤 Ism: <b>{$e('first')}</b>\n👤 Familya: <b>{$e('last')}</b>\n👥 Guruh: <b>{$e('group')}</b>\n🏫 Universitet: <b>{$e('univ')}</b>"
            . (trim((string)($d['kaf'] ?? '')) !== '' ? "\n🏛 Kafedra: <b>{$e('kaf')}</b>" : '')
            . (trim((string)($d['teacher'] ?? '')) !== '' ? "\n👨‍🏫 O'qituvchi: <b>{$e('teacher')}</b>" : '');
    }

    public static function begin(int $chatId, string $type, string $vk): void
    {
        $saved = self::saved($chatId);
        self::setDraft($chatId, null);
        if (self::complete($saved) && $type !== '_edit') {
            Telegram::sendMessage(
                $chatId,
                "📝 Titul varag'i uchun ma'lumotlar (har buyurtmada tasdiqlanadi):\n\n" . self::summary($saved)
                . (self::extrasWanted($type) && $saved['kaf'] === '' && $saved['teacher'] === '' ? "\n\n<i>💡 Titulga kafedra va o'qituvchi ismini ham qo'shish mumkin — «✏️ O'zgartirish».</i>" : '')
                . "\n\nShu buyurtma ham shu ma'lumotlar bilan tayyorlansinmi?",
                ['inline_keyboard' => [
                    [['text' => '✅ Ha, shu ma\'lumotlar', 'callback_data' => "stu:use:{$type}:{$vk}"]],
                    [['text' => '✏️ O\'zgartirish', 'callback_data' => "stu:new:{$type}:{$vk}"]],
                ]]
            );
            setUserState($chatId, 'idle');
            return;
        }
        self::ask($chatId, 'first', $type, $vk, $saved, true);
    }

    private static function prompt(string $f): string
    {
        return match ($f) {
            'first' => "👤 <b>Ismingizni</b> kiriting (masalan: Vali):",
            'last' => "👤 <b>Familyangizni</b> kiriting (masalan: Aliyev):",
            'group' => "👥 <b>Guruh</b> raqamingizni kiriting (masalan: GGI-1-24):",
            'kaf' => "🏛 <b>Kafedra</b> nomini kiriting (masalan: Axborot texnologiyalari kafedrasi) — titulda chiqadi.\nKerak bo'lmasa — «⏭ O'tkazib yuborish»:",
            'teacher' => "👨‍🏫 <b>O'qituvchi / ilmiy rahbar</b> F.I.Sh (masalan: Karimov A.B.) — titulda «Qabul qildi» (kurs ishida «Ilmiy rahbar») qatorida chiqadi.\nKerak bo'lmasa — «⏭ O'tkazib yuborish»:",
            default => "🏫 <b>Universitet</b> nomini kiriting (masalan: Toshkent Davlat Transport Universiteti):",
        };
    }

    private static function ask(int $chatId, string $f, string $type, string $vk, array $saved, bool $intro = false): void
    {
        setUserState($chatId, "awaiting_stu:{$f}:{$type}:{$vk}");
        $prev = trim((string)($saved[$f] ?? ''));
        $kb = null;
        $rows = [];
        if ($prev !== '') {
            $label = '↩️ ' . (mb_strlen($prev) > 40 ? mb_substr($prev, 0, 38) . '…' : $prev);
            $rows[] = [['text' => $label, 'callback_data' => "stu:keep:{$f}:{$type}:{$vk}"]];
        }
        if (in_array($f, self::EXTRA, true)) {
            $rows[] = [['text' => "⏭ O'tkazib yuborish", 'callback_data' => "stu:skip:{$f}:{$type}:{$vk}"]];
        }
        if ($rows) {
            $kb = ['inline_keyboard' => $rows];
        }
        $head = $intro ? "📝 Titul varag'i uchun ma'lumotlaringiz kerak (har buyurtma uchun alohida so'raladi).\n\n" : '';
        Telegram::sendMessage($chatId, $head . self::prompt($f), $kb);
    }

    /** Matn kiritildi. Qaytadi: true — qayta ishlandi. */
    public static function handleText(int $chatId, string $state, string $text): bool
    {
        [, $f, $type, $vk] = array_pad(explode(':', $state, 4), 4, '');
        if (!in_array($f, self::FIELDS, true) && !in_array($f, self::EXTRA, true)) {
            return false;
        }
        $v = self::clean($f, $text);
        if ($v === null) {
            Telegram::sendMessage($chatId, self::error($f));
            return true;
        }
        self::store($chatId, $f, $v, $type, $vk);
        return true;
    }

    /** «↩️ oldingi» tugmasi. */
    public static function handleCallback(int $chatId, string $data): void
    {
        $p = explode(':', $data, 5);   // stu, act, ...
        $act = $p[1] ?? '';
        if ($act === 'use' || $act === 'new') {
            [$type, $vk] = [$p[2] ?? '', $p[3] ?? ''];
            $saved = self::saved($chatId);
            if ($act === 'use' && self::complete($saved)) {
                self::finish($chatId, $saved, $type, $vk);
            } else {
                self::ask($chatId, 'first', $type, $vk, $saved);
            }
            return;
        }
        if ($act === 'keep' || $act === 'skip') {
            [$f, $type, $vk] = [$p[2] ?? '', $p[3] ?? '', $p[4] ?? ''];
            $u = db()->prepare('SELECT state FROM users WHERE telegram_id = ?');
            $u->execute([$chatId]);
            if ((string)$u->fetchColumn() !== "awaiting_stu:{$f}:{$type}:{$vk}") {
                return;                          // eski xabardagi tugma
            }
            if ($act === 'skip' && in_array($f, self::EXTRA, true)) {
                self::store($chatId, $f, '', $type, $vk);
                return;
            }
            $prev = self::saved($chatId)[$f] ?? '';
            if ((in_array($f, self::FIELDS, true) || in_array($f, self::EXTRA, true)) && $prev !== '') {
                self::store($chatId, $f, (string)$prev, $type, $vk);
            }
        }
    }

    private static function store(int $chatId, string $f, string $v, string $type, string $vk): void
    {
        $d = self::draft($chatId);
        $d[$f] = $v;
        self::setDraft($chatId, $d);
        $order = self::extrasWanted($type) ? array_merge(self::FIELDS, self::EXTRA) : self::FIELDS;
        $i = array_search($f, $order, true);
        $next = $i === false ? null : ($order[$i + 1] ?? null);
        if ($next !== null) {
            self::ask($chatId, $next, $type, $vk, self::saved($chatId));
            return;
        }
        self::finish($chatId, $d, $type, $vk);
    }

    private static function finish(int $chatId, array $d, string $type, string $vk): void
    {
        $s = self::toStudent($d);
        self::setDraft($chatId, $d);
        db()->prepare('UPDATE users SET full_name = ?, first_name = ?, last_name = ?, university = ?, group_name = ? WHERE telegram_id = ?')
            ->execute([mb_substr($s['full_name'], 0, 120), mb_substr($s['first_name'], 0, 60), mb_substr($s['last_name'], 0, 60),
                mb_substr($s['university'], 0, 120), mb_substr($s['group_name'], 0, 60), $chatId]);
        if (array_key_exists('kaf', $d) || array_key_exists('teacher', $d)) {
            try {
                db()->prepare('UPDATE users SET kafedra = ?, teacher = ? WHERE telegram_id = ?')
                    ->execute([mb_substr($s['kafedra'], 0, 120), mb_substr($s['teacher'], 0, 80), $chatId]);
            } catch (Throwable $e) {
            }
        }
        if ($type === '_edit') {
            self::setDraft($chatId, null);
            setUserState($chatId, 'idle');
            Telegram::sendMessage($chatId, "✅ Ma'lumotlaringiz yangilandi:\n\n" . self::summary($d), mainMenu());
            return;
        }
        setUserState($chatId, 'idle');
        Telegram::sendMessage($chatId, "✅ Ma'lumotlar qabul qilindi:\n\n" . self::summary($d));
        proceedAfterProfileReady($chatId, $type, $vk);
    }

    // ------------------------------------------------------------------ tekshiruv

    private static function titleCase(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    /** Tozalangan qiymat yoki null (noto'g'ri). */
    public static function clean(string $f, string $raw): ?string
    {
        $v = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F\x7F<>]/u', '', $raw) ?? '') ?? '');
        $len = mb_strlen($v);
        switch ($f) {
            case 'first':
            case 'last':
                if ($len < 2 || $len > 40 || !preg_match('/^[\p{L}\p{M}\'ʻʼ‘’`\-\. ]+$/u', $v)) {
                    return null;
                }
                return self::titleCase($v);
            case 'group':
                if ($len < 2 || $len > 30 || !preg_match('/^[\p{L}\p{N}\-_\.\/ ]+$/u', $v)) {
                    return null;
                }
                return mb_strtoupper($v);
            case 'teacher':
                if ($len < 3 || $len > 80 || !preg_match('/^[\p{L}\p{M}\'ʻʼ‘’`\-\., ]+$/u', $v)) {
                    return null;
                }
                return $v;
            default:
                if ($len < 3 || $len > 120) {
                    return null;
                }
                return $v;
        }
    }

    private static function error(string $f): string
    {
        return match ($f) {
            'first', 'last' => "⚠️ " . self::LABEL[$f] . " faqat harflardan iborat bo'lsin (2-40 belgi). Qayta kiriting:",
            'group' => "⚠️ Guruh nomi 2-30 belgi (harf, raqam, - ) bo'lsin. Qayta kiriting:",
            'teacher' => "⚠️ F.I.Sh faqat harflardan iborat bo'lsin (3-80 belgi). Qayta kiriting yoki «⏭ O'tkazib yuborish»:",
            'kaf' => "⚠️ Kafedra nomi 3-120 belgi bo'lsin. Qayta kiriting yoki «⏭ O'tkazib yuborish»:",
            default => "⚠️ Universitet nomi 3-120 belgi bo'lsin. Qayta kiriting:",
        };
    }
}
