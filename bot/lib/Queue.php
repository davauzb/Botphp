<?php

class Queue
{
    public static function createJob(
        int $telegramId,
        string $type,
        string $topic,
        string $volumeKey,
        int $price,
        bool $isFree,
        bool $paidUpfront = false,
        ?string $designKey = null,
        ?string $lang = null,
        ?array $student = null
    ): int {
        $pdo = db();
        // 'pending' — worker darhol ishlay boshlaydi (bepul urinish YOKI balansdan
        // allaqachon yechilgan bo'lsa). 'awaiting_payment' — eski, endi asosiy oqimda
        // ishlatilmaydi, lekin orqaga moslik uchun qoldirilgan.
        $status = ($isFree || $paidUpfront) ? 'pending' : 'awaiting_payment';
        $stmt = $pdo->prepare(
            'INSERT INTO jobs (telegram_id, type, topic, volume_key, price, is_free, design_key, lang, student, status, created_at, updated_at)
             VALUES (:tid, :type, :topic, :vk, :price, :free, :dk, :lang, :student, :status, :now, :now)'
        );
        $stmt->execute([
            'tid' => $telegramId, 'type' => $type, 'topic' => $topic, 'vk' => $volumeKey,
            'price' => $price, 'free' => $isFree ? 1 : 0, 'dk' => $designKey, 'lang' => I18n::norm($lang), 'student' => $student ? json_encode($student, JSON_UNESCAPED_UNICODE) : null, 'status' => $status, 'now' => nowTs(),
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Navbatdagi bitta ishni "band qiladi". BEGIN IMMEDIATE bilan
     * transaction ochish — bir nechta worker parallel ishlasa ham,
     * ikkitasi bitta jobni bir vaqtda olib ketmasligini kafolatlaydi.
     */
    public static function claimNext(): ?array
    {
        $pdo = db();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $row = $pdo->query(
                "SELECT * FROM jobs WHERE status = 'pending' ORDER BY id ASC LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $pdo->exec('COMMIT');
                return null;
            }

            $upd = $pdo->prepare(
                "UPDATE jobs SET status = 'processing', updated_at = :now WHERE id = :id AND status = 'pending'"
            );
            $upd->execute(['now' => nowTs(), 'id' => $row['id']]);

            if ($upd->rowCount() === 0) {
                // boshqa worker ulgurib oldi
                $pdo->exec('COMMIT');
                return null;
            }

            $pdo->exec('COMMIT');
            return $row;
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            logError('queue', 'claimNext xato: ' . $e->getMessage());
            return null;
        }
    }

    public static function markDone(int $jobId, string $resultFile): void
    {
        $stmt = db()->prepare(
            "UPDATE jobs SET status = 'done', result_file = :f, updated_at = :now WHERE id = :id"
        );
        $stmt->execute(['f' => $resultFile, 'now' => nowTs(), 'id' => $jobId]);
    }

    public static function markFailed(int $jobId, string $error): void
    {
        $pdo = db();
        $job = $pdo->query("SELECT retries FROM jobs WHERE id = $jobId")->fetch(PDO::FETCH_ASSOC);
        $retries = ((int)($job['retries'] ?? 0)) + 1;

        if ($retries < MAX_RETRIES) {
            // qayta urinish uchun yana "pending" holatiga qaytaramiz
            $stmt = $pdo->prepare(
                "UPDATE jobs SET status = 'pending', retries = :r, error_message = :e, updated_at = :now WHERE id = :id"
            );
            $stmt->execute(['r' => $retries, 'e' => $error, 'now' => nowTs(), 'id' => $jobId]);
        } else {
            $stmt = $pdo->prepare(
                "UPDATE jobs SET status = 'failed', retries = :r, error_message = :e, updated_at = :now WHERE id = :id"
            );
            $stmt->execute(['r' => $retries, 'e' => $error, 'now' => nowTs(), 'id' => $jobId]);
        }
    }
}
