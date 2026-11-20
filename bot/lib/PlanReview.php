<?php

/**
 * REJA TASDIQLASH bosqichi (mustaqil ish, referat, slayd).
 *
 * Reja (bob / slayd sarlavhalari) tuzilgach ish qisqa to'xtaydi va foydalanuvchiga reja ko'rsatiladi:
 *   ✅ Tasdiqlash   ✏️ O'zgartirish (sarlavhalarni o'zi yozadi)   🔄 Boshqa reja (ko'pi bilan 2 marta)
 * Javob bo'lmasa PLAN_REVIEW_SECONDS (standart 5 daqiqa) dan keyin reja AVTOMATIK tasdiqlanadi — ish hech qachon qotib qolmaydi.
 * Pul buyurtma ochilganda yechilgan; reja bosqichida ham «✖️ Bekor qilish» ishlaydi va pul qaytariladi.
 *
 * Tugma bosilishi jobs.plan_action ustuniga yoziladi ('ok' | 'rg' | 'edit:<json>'), qadam uni keyingi tickda o'qiydi —
 * shu tufayli tick bilan to'qnashuv (state'ni bir vaqtda yozish) bo'lmaydi.
 */
class PlanReview
{
    public const MAX_REGEN = 2;

    public static function enabled(): bool
    {
        return (bool)Engine::c('PLAN_REVIEW_ENABLED', true);
    }

    public static function waitSeconds(): int
    {
        return max(60, (int)Engine::c('PLAN_REVIEW_SECONDS', 300));
    }

    public static function stepDef(): array
    {
        return ['id' => 'plan', 'k' => 'plan_review', 'w' => 0.3, 'label' => 'Reja tasdiqlanishi kutilmoqda'];
    }

    private static function stripNum(string $t): string
    {
        return trim((string)preg_replace('/^\s*\d+[\.\)]\s*/u', '', $t));
    }

    /** Rejadagi sarlavhalar (raqamsiz). */
    public static function items(array $state): array
    {
        if (($state['type'] ?? '') === 'slayd') {
            return array_map(fn($o) => (string)($o['sarlavha'] ?? ''), $state['res']['outline'] ?? []);
        }
        return array_map(fn($t) => self::stripNum((string)$t), $state['res']['titles'] ?? []);
    }

    // ------------------------------------------------------------------ qadam

    public static function exec(array $job, array &$state): void
    {
        if (!self::enabled()) {
            return;
        }
        $jobId = (int)$job['id'];
        $action = (string)($job['plan_action'] ?? '');
        if (empty($state['res']['plan_sent'])) {
            self::send($job, $state);
            self::clearAction($jobId);
            throw new StepWaitException(self::nextWake($state));
        }
        if ($action === 'ok') {
            self::clearAction($jobId);
            self::close($job, $state, null);
            return;
        }
        if (strpos($action, 'edit:') === 0) {
            $lines = json_decode(substr($action, 5), true);
            self::clearAction($jobId);
            if (is_array($lines) && count($lines) === count(self::items($state))) {
                self::applyEdit($state, array_map('strval', $lines));
                self::close($job, $state, '✏️ Reja yangilandi — matn yozilmoqda.');
                return;
            }
            self::close($job, $state, null);
            return;
        }
        if ($action === 'rg') {
            if ((int)($state['res']['plan_regens'] ?? 0) < self::MAX_REGEN) {
                self::regenerate($state);          // AI band bo'lsa istisno ketadi — plan_action saqlanib qoladi, keyingi tickda qayta uriniladi
                self::clearAction($jobId);
                self::removeKeyboard($state);
                unset($state['res']['plan_sent']);
                self::send($job, $state);
                throw new StepWaitException(self::nextWake($state));
            }
            self::clearAction($jobId);
            self::close($job, $state, null);
            return;
        }
        if ($action !== '') {
            self::clearAction($jobId);             // noma'lum buyruq — e'tiborsiz
        }
        if (time() - (int)$state['res']['plan_sent'] >= self::waitSeconds()) {
            self::close($job, $state, '⏱ Javob kelmadi — reja avtomatik tasdiqlandi, matn yozilmoqda.');
            return;
        }
        throw new StepWaitException(self::nextWake($state));
    }

    private static function clearAction(int $jobId): void
    {
        db()->prepare('UPDATE jobs SET plan_action = NULL WHERE id = ?')->execute([$jobId]);
    }

    private static function nextWake(array $state): int
    {
        $left = self::waitSeconds() - (time() - (int)($state['res']['plan_sent'] ?? time()));
        return max(3, min(45, $left));
    }

    private static function send(array $job, array &$state): void
    {
        $jobId = (int)$job['id'];
        $items = self::items($state);
        $isSlide = ($state['type'] ?? '') === 'slayd';
        $emoji = ['tarif' => '📖', 'matn' => '📝', 'kartalar' => '🗂', 'bosqichlar' => '🪜', 'xronologiya' => '🕰', 'taqqoslash' => '⚖️', 'xulosa' => '🏁'];
        $lines = [];
        foreach ($items as $i => $t) {
            $kind = $isSlide ? ($state['res']['outline'][$i]['turi'] ?? 'matn') : '';
            $lines[] = ($i + 1) . '. ' . ($isSlide ? ($emoji[$kind] ?? '📝') . ' ' : '') . Telegram::escape($t);
        }
        $topic = Telegram::escape(mb_substr((string)$job['topic'], 0, 100));
        $mins = (int)ceil(self::waitSeconds() / 60);
        $word = $isSlide ? 'Slayd' : 'Bob';
        $text = "📋 <b>Reja tayyor</b> — «{$topic}»\n\n" . implode("\n", $lines)
            . "\n\nMaqul bo'lsa «✅ Tasdiqlash»ni bosing. {$word} sarlavhalarini o'zingiz yozishingiz yoki boshqa reja so'rashingiz mumkin."
            . "\n⏱ {$mins} daqiqa ichida javob bo'lmasa, reja avtomatik tasdiqlanadi.";
        $kb = [[['text' => '✅ Tasdiqlash', 'callback_data' => "plan:ok:$jobId"]],
            [['text' => "✏️ O'zgartirish", 'callback_data' => "plan:ed:$jobId"]]];
        if ((int)($state['res']['plan_regens'] ?? 0) < self::MAX_REGEN) {
            $kb[1][] = ['text' => '🔄 Boshqa reja', 'callback_data' => "plan:rg:$jobId"];
        }
        $mid = Telegram::sendMessage((int)$job['telegram_id'], $text, ['inline_keyboard' => $kb]);
        $state['res']['plan_sent'] = time();
        $state['res']['plan_mid'] = $mid ?: 0;
        $state['res']['plan_chat'] = (int)$job['telegram_id'];
    }

    public static function removeKeyboard(array $state): void
    {
        $mid = (int)($state['res']['plan_mid'] ?? 0);
        $chat = (int)($state['res']['plan_chat'] ?? 0);
        if ($mid > 0 && $chat !== 0) {
            Telegram::call('editMessageReplyMarkup', ['chat_id' => $chat, 'message_id' => $mid, 'reply_markup' => json_encode(['inline_keyboard' => []])]);
        }
    }

    private static function close(array $job, array &$state, ?string $note): void
    {
        self::removeKeyboard($state);
        if ($note !== null) {
            Telegram::sendMessage((int)$job['telegram_id'], $note);
        }
        $state['res']['plan_ok'] = true;
    }

    // ------------------------------------------------------------------ o'zgartirish

    public static function applyEdit(array &$state, array $lines): void
    {
        if (($state['type'] ?? '') === 'slayd') {
            $topic = (string)($state['p']['topic'] ?? '');
            foreach ($state['res']['outline'] as $i => &$o) {
                $t = $lines[$i] ?? '';
                if ($t !== '' && $t !== $o['sarlavha']) {
                    $o['sarlavha'] = $t;
                    $o['fikr'] = "$topic mavzusining «{$t}» jihati";
                }
            }
            unset($o);
            return;
        }
        $titles = [];
        foreach ($lines as $i => $t) {
            $titles[] = ($i + 1) . '. ' . $t;
        }
        $state['res']['titles'] = $titles;
    }

    private static function regenerate(array &$state): void
    {
        $n = (int)($state['res']['plan_regens'] ?? 0) + 1;
        $state['res']['plan_regens'] = $n;
        $avoid = self::items($state);
        if (($state['type'] ?? '') === 'slayd') {
            $state['res']['outline'] = generateSlideOutline($state['p']['topic'], (int)$state['p']['count'], $avoid, (string)($state['p']['lang'] ?? 'uz'));
        } else {
            $state['res']['plan_avoid'] = $avoid;
            JobSteps::outline($state);
            unset($state['res']['plan_avoid']);
        }
    }

    // ------------------------------------------------------------------ tugmalar va matn (webhook chaqiradi)

    /** Reja bosqichida kutayotgan ishni topadi. */
    private static function waitingJob(int $jobId, int $uid): ?array
    {
        $st = db()->prepare("SELECT * FROM jobs WHERE id = ? AND telegram_id = ? AND status IN ('pending','processing')");
        $st->execute([$jobId, $uid]);
        $job = $st->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            return null;
        }
        $state = $job['state'] ? json_decode((string)$job['state'], true) : null;
        if (!is_array($state) || empty($state['steps'])) {
            return null;
        }
        $cur = $state['steps'][(int)$state['i']] ?? null;
        if (!$cur || ($cur['k'] ?? '') !== 'plan_review') {
            return null;
        }
        $job['_state'] = $state;
        return $job;
    }

    public static function handleCallback(string $act, int $jobId, int $uid, string $cbId): void
    {
        $job = self::waitingJob($jobId, $uid);
        if (!$job) {
            Telegram::answerCallbackQuery($cbId, 'Reja bosqichi allaqachon tugagan — buyurtma davom etmoqda.');
            return;
        }
        $state = $job['_state'];
        if ($act === 'ok') {
            self::setAction($jobId, 'ok');
            Telegram::answerCallbackQuery($cbId, '✅ Tasdiqlandi');
        } elseif ($act === 'rg') {
            if ((int)($state['res']['plan_regens'] ?? 0) >= self::MAX_REGEN) {
                Telegram::answerCallbackQuery($cbId, "Boshqa reja so'rash limiti tugadi.", true);
                return;
            }
            self::setAction($jobId, 'rg');
            Telegram::answerCallbackQuery($cbId, '🔄 Yangi reja tuzilmoqda…');
        } elseif ($act === 'ed') {
            $n = count(self::items($state));
            setUserState($uid, "awaiting_plan_edit:$jobId");
            Telegram::answerCallbackQuery($cbId);
            $what = ($state['type'] ?? '') === 'slayd' ? 'slayd' : 'bob';
            Telegram::sendMessage(
                $uid,
                "✏️ Yangi sarlavhalarni yuboring: <b>aniq {$n} qator</b>, har qatorda bitta {$what} sarlavhasi (raqam yozish shart emas).\n"
                . "Quyidagini nusxalab, kerakli joyini o'zgartirsangiz bo'ladi:\n\n<code>" . Telegram::escape(implode("\n", self::items($state))) . "</code>\n\nBekor qilish: /start"
            );
        } else {
            Telegram::answerCallbackQuery($cbId);
        }
    }

    private static function setAction(int $jobId, string $action): void
    {
        db()->prepare('UPDATE jobs SET plan_action = ?, next_run_at = 0 WHERE id = ?')->execute([$action, $jobId]);
        Engine::kvSet('last_kick', '1');
        Engine::kick(0);
    }

    /** Foydalanuvchi yangi sarlavhalarni yubordi (state = awaiting_plan_edit:<jobId>). */
    public static function submitEdit(int $chatId, int $jobId, string $text): void
    {
        $job = self::waitingJob($jobId, $chatId);
        if (!$job) {
            setUserState($chatId, 'idle');
            Telegram::sendMessage($chatId, "Reja bosqichi allaqachon tugagan — buyurtma davom etmoqda.", mainMenu());
            return;
        }
        $need = count(self::items($job['_state']));
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $l) {
            $l = self::stripNum($l);
            if ($l !== '') {
                $lines[] = mb_substr($l, 0, 140);
            }
        }
        if (count($lines) !== $need) {
            Telegram::sendMessage($chatId, "⚠️ Aniq <b>{$need} qator</b> kerak, siz " . count($lines) . " qator yubordingiz. Qaytadan yuboring (yoki /start):");
            return;
        }
        setUserState($chatId, 'idle');
        self::setAction($jobId, 'edit:' . json_encode($lines, JSON_UNESCAPED_UNICODE));
        Telegram::sendMessage($chatId, "✅ Qabul qilindi, reja yangilanmoqda…");
    }
}
