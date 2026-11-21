<?php

/**
 * 📱 BUYURTMA MINI APP: bot menyusidagi «📱 Ilova» tugmasi Telegram ichida chiroyli forma ochadi —
 * tur → hajm → (taqdimot bo'lsa) dizayn → mavzu va titul ma'lumotlari → narx va to'lov usuli ko'rinadi → «Buyurtma berish».
 * Buyurtma bot orqali berilgandagi bilan bir xil qoidalarda yaratiladi (bepul urinish, obuna, balansdan atomik yechish).
 * Xavfsizlik: har so'rov Telegram initData imzosi (HMAC) bilan tekshiriladi; boshqa odam nomidan buyurtma berib bo'lmaydi.
 * Yoqish: admin panel → Sozlamalar → «📱 Buyurtma Mini App» (PUBLIC_BASE_URL https:// bo'lishi shart).
 */
class MiniApp
{
    public static function enabled(): bool
    {
        return (bool)Engine::c('MINIAPP_ENABLED', false) && defined('PUBLIC_BASE_URL') && str_starts_with((string)PUBLIC_BASE_URL, 'https://');
    }

    public static function url(): string
    {
        return rtrim((string)PUBLIC_BASE_URL, '/') . '/app/index.php';
    }

    /** Forma uchun ma'lumot: turlar, hajmlar (narx bilan), dizaynlar, balans, bepul urinish, obuna, oldingi titul ma'lumotlari. */
    public static function data(int $tg): array
    {
        $u = db()->prepare('SELECT balance, free_used FROM users WHERE telegram_id = ?');
        $u->execute([$tg]);
        $user = $u->fetch(PDO::FETCH_ASSOC) ?: ['balance' => 0, 'free_used' => 1];
        $types = [];
        foreach (DocTypes::all() as $t) {
            $vols = [];
            $free = DocTypes::freeKey($t);
            foreach (DocTypes::volumes($t) ?? [] as $vk => $v) {
                $vols[] = ['k' => (string)$vk, 'label' => (string)$v['label'], 'price' => (int)$v['price'],
                    'free' => !(int)$user['free_used'] && $free !== null && (string)$free === (string)$vk,
                    'sub' => Growth::subCovers($tg, (int)$v['price'])];
            }
            $types[] = ['k' => $t, 'title' => DocTypes::title($t), 'menu' => DocTypes::T[$t]['menu'], 'kind' => DocTypes::kind($t), 'vols' => $vols];
        }
        $designs = [];
        foreach (pptxDesigns() as $k => $d) {
            $designs[] = ['k' => $k, 'label' => preg_replace('/^\d+\.\s*(⭐ PRO — )?/u', '', (string)$d['label']), 'acc' => (string)($d['accent'] ?? '4472C4'),
                'bg' => (string)($d['bg'] ?? ($d['title'] ?? '1F4E79')), 'pro' => !empty($d['pro'])];
        }
        $saved = StudentFlow::saved($tg);
        return ['ok' => true, 'types' => $types, 'designs' => $designs, 'balance' => (int)$user['balance'], 'sub' => Growth::sub($tg),
            'pro_pct' => (int)Engine::c('PRO_SURCHARGE_PERCENT', 60), 'sub_max' => Growth::subEnabled() ? Growth::subMaxPrice() : 0, 'student' => $saved, 'req' => Requirements::pending($tg) !== '',
            'lang' => I18n::userLang($tg), 'maintenance' => Settings::maintenance() && !AdminSecurity::isAdmin($tg)];
    }

    /** Buyurtma berish. $in: type, vk, topic, design, student{first,last,group,univ,kaf,teacher}, lang */
    public static function place(int $tg, array $in): array
    {
        if (Settings::maintenance() && !AdminSecurity::isAdmin($tg)) {
            return ['ok' => false, 'msg' => 'Botda texnik ishlar olib borilmoqda'];
        }
        $type = (string)($in['type'] ?? '');
        $vk = (string)($in['vk'] ?? '');
        $vols = DocTypes::exists($type) ? (DocTypes::volumes($type) ?? []) : [];
        if (!isset($vols[$vk])) {
            return ['ok' => false, 'msg' => 'Tur yoki hajm noto\'g\'ri'];
        }
        $topic = trim(preg_replace('/\s+/u', ' ', (string)($in['topic'] ?? '')) ?? '');
        if (mb_strlen($topic) < 3 || mb_strlen($topic) > 300) {
            return ['ok' => false, 'msg' => 'Mavzu 3-300 belgi bo\'lsin'];
        }
        // titul ma'lumotlari (StudentFlow bilan bir xil tekshiruv)
        $d = [];
        foreach (array_merge(StudentFlow::FIELDS, StudentFlow::EXTRA) as $f) {
            $raw = trim((string)($in['student'][$f] ?? ''));
            if ($raw === '' && in_array($f, StudentFlow::EXTRA, true)) {
                $d[$f] = '';
                continue;
            }
            $v = StudentFlow::clean($f, $raw);
            if ($v === null) {
                return ['ok' => false, 'msg' => ['first' => 'Ism', 'last' => 'Familya', 'group' => 'Guruh', 'univ' => 'Universitet', 'kaf' => 'Kafedra', 'teacher' => "O'qituvchi"][$f] . " noto'g'ri", 'field' => $f];
            }
            $d[$f] = $v;
        }
        $st = db()->prepare('SELECT * FROM users WHERE telegram_id = ?');
        $st->execute([$tg]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if (!$user || !empty($user['banned'])) {
            return ['ok' => false, 'msg' => 'Avval botga /start yozing'];
        }
        $last = db()->prepare('SELECT created_at FROM jobs WHERE telegram_id = ? ORDER BY id DESC LIMIT 1');
        $last->execute([$tg]);
        if (nowTs() - (int)$last->fetchColumn() < USER_COOLDOWN_SECONDS) {
            return ['ok' => false, 'msg' => 'Biroz kuting va qayta urinib ko\'ring'];
        }
        $designKey = null;
        $price = (int)$vols[$vk]['price'];
        $isPro = false;
        if ($type === 'slayd') {
            $designKey = (string)($in['design'] ?? '');
            if (!isset(pptxDesigns()[$designKey])) {
                $designKey = DEFAULT_PPTX_DESIGN;
            }
            $isPro = isProDesign($designKey);
            if ($isPro) {
                $price = ProDeck::price($price);
            }
        }
        // titulni profilga saqlaymiz (keyingi safar oldindan to'ldiriladi)
        $s = StudentFlow::toStudent($d);
        db()->prepare('UPDATE users SET full_name = ?, first_name = ?, last_name = ?, university = ?, group_name = ?, kafedra = ?, teacher = ? WHERE telegram_id = ?')
            ->execute([$s['full_name'], $s['first_name'], $s['last_name'], $s['university'], $s['group_name'], $s['kafedra'], $s['teacher'], $tg]);

        $isFree = false;
        $free = DocTypes::freeKey($type);
        if (!$isPro && (int)$user['free_used'] === 0 && $free !== null && (string)$free === $vk) {
            $c = db()->prepare('UPDATE users SET free_used = 1 WHERE telegram_id = ? AND free_used = 0');
            $c->execute([$tg]);
            $isFree = $c->rowCount() > 0;
        }
        $paidBy = $isFree ? 'free' : 'balance';
        if (!$isFree && Growth::subCovers($tg, $price) && Growth::subTake($tg)) {
            $paidBy = 'sub';
        } elseif (!$isFree) {
            $b = db()->prepare('UPDATE users SET balance = balance - ? WHERE telegram_id = ? AND balance >= ?');
            $b->execute([$price, $tg, $price]);
            if (!$b->rowCount()) {
                return ['ok' => false, 'msg' => "Balans yetarli emas: kerak " . number_format($price, 0, '', ' ') . " so'm", 'topup' => max(0, $price - (int)$user['balance'])];
            }
        }
        $lang = in_array(($in['lang'] ?? ''), ['uz', 'ru', 'en'], true) ? (string)$in['lang'] : I18n::userLang($tg);
        try {
            $jobId = Queue::createJob($tg, $type, $topic, $vk, $price, $isFree, !$isFree, $designKey, $lang, $s);
            db()->prepare('UPDATE jobs SET paid_by = ? WHERE id = ?')->execute([$paidBy, $jobId]);
            Requirements::attach($tg, $jobId);
        } catch (Throwable $e) {
            logError('miniapp', 'createJob: ' . $e->getMessage());
            if ($isFree) {
                db()->prepare('UPDATE users SET free_used = 0 WHERE telegram_id = ?')->execute([$tg]);
            } elseif ($paidBy === 'sub') {
                Growth::subGive($tg);
            } else {
                db()->prepare('UPDATE users SET balance = balance + ? WHERE telegram_id = ?')->execute([$price, $tg]);
            }
            return ['ok' => false, 'msg' => 'Buyurtma yaratilmadi, pul qaytarildi'];
        }
        db()->prepare("UPDATE users SET state = 'idle' WHERE telegram_id = ?")->execute([$tg]);
        $label = (string)$vols[$vk]['label'];
        $head = $isFree ? "✅ Bepul buyurtmangiz qabul qilindi! (📱 ilova orqali)\n📏 Hajm: $label"
            : ($paidBy === 'sub' ? "✅ Buyurtma qabul qilindi! (📱 ilova orqali)\n📏 Hajm: $label  ·  💎 Obunadan"
                : "✅ Buyurtma qabul qilindi! (📱 ilova orqali)\n📏 Hajm: $label  ·  💳 Balansdan: " . number_format($price, 0, '', ' ') . " so'm");
        Engine::onJobPending($jobId, $head);
        try {
            AdminEvents::newOrder($jobId, $tg, $type, $topic, $vk, $price, $isFree, $s, $user['username'] ?? null);
        } catch (Throwable $e) {
        }
        return ['ok' => true, 'id' => $jobId, 'msg' => 'Buyurtma qabul qilindi'];
    }

    /** Telegram initData imzosini tekshiradi (24 soatgacha amal qiladi). Qaytadi: telegram_id yoki 0. */
    public static function verify(string $initData): int
    {
        if ($initData === '' || strlen($initData) > 4096) {
            return 0;
        }
        parse_str($initData, $pairs);
        $hash = (string)($pairs['hash'] ?? '');
        if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
            return 0;
        }
        unset($pairs['hash']);
        ksort($pairs);
        $lines = [];
        foreach ($pairs as $k => $v) {
            if (!is_string($v)) {
                return 0;
            }
            $lines[] = $k . '=' . $v;
        }
        $calc = hash_hmac('sha256', implode("\n", $lines), hash_hmac('sha256', BOT_TOKEN, 'WebAppData', true));
        $age = time() - (int)($pairs['auth_date'] ?? 0);
        if (!hash_equals($calc, $hash) || $age > 86400 || $age < -60) {
            return 0;
        }
        $u = json_decode((string)($pairs['user'] ?? ''), true);
        return is_array($u) && is_int($u['id'] ?? null) && $u['id'] > 0 ? (int)$u['id'] : 0;
    }
}
