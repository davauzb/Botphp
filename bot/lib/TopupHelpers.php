<?php

require_once __DIR__ . '/Telegram.php';
require_once __DIR__ . '/Promo.php';

function getUserBalance(int $chatId): int
{
    $stmt = db()->prepare('SELECT balance FROM users WHERE telegram_id = :id');
    $stmt->execute(['id' => $chatId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

/**
 * $resumeType/$resumeVolumeKey — agar bu to'ldirish biror buyurtma jarayonida
 * (balans yetmagani uchun) boshlangan bo'lsa, tasdiqlangandan keyin foydalanuvchini
 * avtomatik o'sha buyurtmaga qaytarish uchun saqlanadi. Mustaqil balans to'ldirish
 * bo'lsa (masalan "💰 Balans" menyusidan), null/qoldirilishi mumkin.
 */
function createPendingTopup(
    int $telegramId,
    int $amount,
    string $method,
    ?string $resumeType = null,
    ?string $resumeVolumeKey = null
): int {
    $stmt = db()->prepare(
        "INSERT INTO topups (telegram_id, amount, method, status, resume_type, resume_volume_key, created_at, updated_at)
         VALUES (:tid, :amount, :method, 'pending', :rt, :rv, :now, :now)"
    );
    $stmt->execute([
        'tid' => $telegramId, 'amount' => $amount, 'method' => $method,
        'rt' => $resumeType, 'rv' => $resumeVolumeKey, 'now' => nowTs(),
    ]);
    return (int)db()->lastInsertId();
}

/**
 * To'ldirish tasdiqlanganda (Click/Payme avtomatik YOKI admin chekni qo'lda
 * tasdiqlaganda) chaqiriladi. Ikki marta chaqirilsa ham xavfsiz — faqat "pending"
 * holatdagi to'ldirish qayta ishlanadi.
 */
function markTopupPaid(int $topupId): void
{
    $pdo = db();
    $topup = null;
    $shouldNotify = false;
    $bonus = 0;

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $stmt = $pdo->prepare('SELECT * FROM topups WHERE id = :id');
        $stmt->execute(['id' => $topupId]);
        $topup = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$topup || $topup['status'] !== 'pending') {
            $pdo->exec('COMMIT');
            return;
        }

        $upd = $pdo->prepare("UPDATE topups SET status = 'approved', updated_at = :now WHERE id = :id");
        $upd->execute(['now' => nowTs(), 'id' => $topupId]);

        $balUpd = $pdo->prepare('UPDATE users SET balance = balance + :amt WHERE telegram_id = :tid');
        $balUpd->execute(['amt' => (int)$topup['amount'], 'tid' => (int)$topup['telegram_id']]);
        // Promokod / aksiya bonusi (shu tranzaksiyada — to'ldirish ikki marta qayta ishlansa ham bonus ikki marta berilmaydi)
        $bonus = Promo::applyOnTopup($pdo, (int)$topup['telegram_id'], (int)$topup['amount']);

        $pdo->exec('COMMIT');
        $shouldNotify = true;
        if (class_exists('AdminEvents')) {
            AdminEvents::topupPaid($topupId, (int)$topup['telegram_id'], (int)$topup['amount'], $bonus, (string)($topup['method'] ?? ''));
        }
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        logError('topup', 'markTopupPaid xato: ' . $e->getMessage());
        return;
    }

    if ($shouldNotify && $topup) {
        $chatId = (int)$topup['telegram_id'];
        // Chek yuborish/admin tekshiruvi holatida qolib ketmasin (buyurtmaga qaytish bo'lsa, resumeOrderIfPending o'zi holatni qo'yadi)
        try {
            db()->prepare("UPDATE users SET state = 'idle' WHERE telegram_id = :id AND (state LIKE 'awaiting_admin_review:%' OR state LIKE 'awaiting_receipt:%' OR state LIKE 'awaiting_topup_method:%')")
                ->execute(['id' => $chatId]);
        } catch (Throwable $e) {
            logError('topup', 'holatni tiklashda xato: ' . $e->getMessage());
        }
        try {
            Telegram::sendMessage(
                $chatId,
                "✅ Hisobingiz " . number_format((int)$topup['amount'], 0, '', ' ') . " so'mga to'ldirildi!"
                . ($bonus > 0 ? "\n🎁 Bonus: <b>+" . number_format($bonus, 0, '', ' ') . " so'm</b> qo'shildi." : '')
            );
        } catch (Throwable $e) {
            logError('topup', "Xabar yuborishda xato (balans baribir to'ldirildi): " . $e->getMessage());
        }
        resumeOrderIfPending($chatId, $topup['resume_type'] ?? null, $topup['resume_volume_key'] ?? null);
    }
}

function markTopupRejected(int $topupId, string $note = ''): void
{
    $stmt = db()->prepare('SELECT * FROM topups WHERE id = :id');
    $stmt->execute(['id' => $topupId]);
    $topup = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$topup || $topup['status'] !== 'pending') {
        return;
    }

    $upd = db()->prepare("UPDATE topups SET status = 'rejected', admin_note = :note, updated_at = :now WHERE id = :id");
    $upd->execute(['note' => $note, 'now' => nowTs(), 'id' => $topupId]);

    try {
        Telegram::sendMessage(
            (int)$topup['telegram_id'],
            "❌ Chekingiz tasdiqlanmadi. Iltimos, to'lovni tekshirib qaytadan yuboring yoki administrator bilan bog'laning."
            . ($note ? "\n\nSabab: " . Telegram::escape($note) : '')
        );
    } catch (Throwable $e) {
        logError('topup', 'Rad etish xabarini yuborishda xato: ' . $e->getMessage());
    }

    // Foydalanuvchi qayta urinishi mumkin bo'lsin — holatni tozalaymiz
    $stmt2 = db()->prepare("UPDATE users SET state = 'idle' WHERE telegram_id = :id");
    $stmt2->execute(['id' => (int)$topup['telegram_id']]);
}

/**
 * Balans yetarli bo'lib qolgach, foydalanuvchi to'ldirishga jo'natilgan buyurtmasini
 * avtomatik davom ettiradi — qaytadan hajm tanlashning hojati yo'q.
 */
function resumeOrderIfPending(int $chatId, ?string $type, ?string $volumeKey): void
{
    if (!$type || !$volumeKey || $type === '_none') {
        return;
    }

    // webhook.php dagi volumesFor() bu yerda mavjud emas (click.php/payme.php orqali ham chaqiriladi) — o'zimiz tanlaymiz.
    // Avval "referat" turi e'tiborga olinmagan edi: referat uchun balans to'ldirilgach buyurtma davom ettirilmay qolardi.
    $volumes = DocTypes::volumes((string)$type) ?? [];
    if (!isset($volumes[$volumeKey])) {
        return;
    }

    $stmt = db()->prepare('SELECT balance, full_name, university, group_name FROM users WHERE telegram_id = :id');
    $stmt->execute(['id' => $chatId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || (int)$user['balance'] < $volumes[$volumeKey]['price']) {
        return; // hali ham yetarli emas (masalan qisman to'ldirilgan bo'lsa)
    }

    $profileComplete = !empty($user['full_name']) && !empty($user['university']) && !empty($user['group_name']);
    $stateStmt = db()->prepare('UPDATE users SET state = :s WHERE telegram_id = :id');

    if (!$profileComplete) {
        $stateStmt->execute(['s' => "awaiting_profile_name:{$type}:{$volumeKey}", 'id' => $chatId]);
        Telegram::sendMessage(
            $chatId,
            "Endi buyurtmangizni davom ettiramiz. Titul varag'i uchun ma'lumotlaringiz kerak:\n\n"
            . "F.I.Sh ni to'liq kiriting (masalan: Aliyev Vali Sobirovich):"
        );
        return;
    }

    if ($type === 'slayd') {
        // Slayd uchun mavzudan oldin DIZAYN tanlanadi — bu funksiya webhook.php'dan
        // mustaqil (click.php/payme.php orqali ham chaqirilishi mumkin), shuning
        // uchun kollaj yuborish mantig'i shu yerda o'zicha takrorlangan.
        $stateStmt->execute(['s' => "awaiting_design:{$volumeKey}", 'id' => $chatId]);
        Telegram::sendMessage($chatId, "Endi buyurtmangizni davom ettiramiz.");
        Telegram::sendPhoto(
            $chatId,
            __DIR__ . '/../assets/design_preview.jpg',
            "Taqdimot uchun dizaynni tanlang (raqamlar rasmdagi tartib bilan mos)." . (ProDeck::enabled() ? "\n\n⭐ PRO dizaynlar — pastdagi tugmada." : ''),
            designPickerKeyboard($volumeKey)
        );
        return;
    }

    $stateStmt->execute(['s' => "awaiting_topic:{$type}:{$volumeKey}", 'id' => $chatId]);
    $label = DocTypes::label((string)$type);
    Telegram::sendMessage($chatId, "Endi buyurtmangizni davom ettiramiz. " . ucfirst($label) . " mavzusini yozing:");
}
