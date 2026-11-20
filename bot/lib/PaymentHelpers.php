<?php

/**
 * To'lov muvaffaqiyatli bo'lganda: payment'ni "paid" qiladi va bog'liq jobni
 * "pending" holatiga o'tkazadi (worker endi uni ishlay boshlaydi).
 * Ikki marta chaqirilsa ham xavfsiz — Click/Payme bir xabarni qayta yuborishi mumkin,
 * lekin allaqachon "paid" bo'lgan payment uchun job qayta navbatga qo'yilmaydi.
 */
function markPaymentPaid(int $paymentId): void
{
    // 1-QADAM: faqat DB o'zgarishlari. Tashqi chaqiruv (Telegram) BU YERDA YO'Q —
    // shunda tarmoq xatosi tranzaksiyani yarim holatda qoldirmaydi.
    $pdo = db();
    $payment = null;
    $shouldNotify = false;

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = :id');
        $stmt->execute(['id' => $paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment || $payment['status'] === 'paid') {
            $pdo->exec('COMMIT');
            return;
        }

        $upd = $pdo->prepare("UPDATE payments SET status = 'paid', updated_at = :now WHERE id = :id");
        $upd->execute(['now' => nowTs(), 'id' => $paymentId]);

        $jobUpd = $pdo->prepare(
            "UPDATE jobs SET status = 'pending', updated_at = :now WHERE id = :jid AND status = 'awaiting_payment'"
        );
        $jobUpd->execute(['now' => nowTs(), 'jid' => (int)$payment['job_id']]);

        // MUHIM: bitta buyurtma uchun HAM Click, HAM Payme havolasi berilgan edi.
        // Foydalanuvchi ikkalasidan ham to'lab qo'yishi (ikki marta pul yechilishi)
        // mumkin edi. Endi biri to'langan zahoti, o'sha buyurtmaning boshqa
        // (hali to'lanmagan) to'lov yozuvini "cancelled" qilib qo'yamiz — pastda
        // Click/PaymePayment ikkalasi ham "cancelled" holatdagi buyurtmani ko'rib,
        // haqiqiy pul yechilishidan OLDIN (Prepare/CheckPerform bosqichida) rad etadi.
        $siblingUpd = $pdo->prepare(
            "UPDATE payments SET status = 'cancelled', updated_at = :now
             WHERE job_id = :jid AND id != :pid AND status = 'pending'"
        );
        $siblingUpd->execute(['now' => nowTs(), 'jid' => (int)$payment['job_id'], 'pid' => $paymentId]);

        $pdo->exec('COMMIT');
        $shouldNotify = true;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        logError('payment', 'markPaymentPaid xato: ' . $e->getMessage());
        return;
    }

    // 2-QADAM: DB xavfsiz saqlangandan KEYIN xabar yuboramiz.
    if ($shouldNotify && $payment) {
        try {
            require_once __DIR__ . '/Telegram.php';
            Engine::onJobPending((int)$payment['job_id'], "✅ To'lov qabul qilindi! Buyurtmangiz navbatga qo'yildi.");
        } catch (Throwable $e) {
            logError('payment', 'Xabar yuborishda xato (to\'lov baribir qabul qilindi): ' . $e->getMessage());
        }
    }
}

function markPaymentCancelled(int $paymentId): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = :id');
    $stmt->execute(['id' => $paymentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        return;
    }

    $jobStmt = $pdo->prepare('SELECT * FROM jobs WHERE id = :id');
    $jobStmt->execute(['id' => (int)$payment['job_id']]);
    $job = $jobStmt->fetch(PDO::FETCH_ASSOC);

    if ($job && in_array($job['status'], ['awaiting_payment', 'pending'], true)) {
        $upd = $pdo->prepare("UPDATE jobs SET status = 'cancelled', updated_at = :now WHERE id = :id");
        $upd->execute(['now' => nowTs(), 'id' => $job['id']]);
    } elseif ($job && in_array($job['status'], ['processing', 'done'], true)) {
        // Kamdan-kam holat: to'lov "to'landi" deb qayd etilgandan keyin, hujjat allaqachon
        // tayyorlanib/yuborilib bo'lgach bekor qilinsa — avtomatik qaytarib bo'lmaydi,
        // admin qo'lda hal qilishi uchun xabar beramiz.
        require_once __DIR__ . '/Telegram.php';
        Telegram::notifyAdmin("To'lov #$paymentId bekor qilindi, lekin job #{$job['id']} allaqachon {$job['status']} holatida — qo'lda tekshiring.");
    }

    $upd = $pdo->prepare("UPDATE payments SET status = 'cancelled', updated_at = :now WHERE id = :id");
    $upd->execute(['now' => nowTs(), 'id' => $paymentId]);
}

function createPendingPayment(int $telegramId, int $jobId, int $amount, string $provider): int
{
    $stmt = db()->prepare(
        "INSERT INTO payments (telegram_id, job_id, amount, status, provider, created_at, updated_at)
         VALUES (:tid, :jid, :amount, 'pending', :provider, :now, :now)"
    );
    $stmt->execute([
        'tid' => $telegramId, 'jid' => $jobId, 'amount' => $amount,
        'provider' => $provider, 'now' => nowTs(),
    ]);
    return (int)db()->lastInsertId();
}
