<?php

/**
 * 🎉 Avtomatik aksiyalar kalendari: bayram/mavsum kunlarida balansni to'ldirishga avtomatik bonus
 * va (ixtiyoriy) o'sha kuni ertalab barcha mijozlarga bir martalik tabrik + aksiya xabari.
 * Ro'yxat — Sozlamalar → «🎉 Aksiya kunlari», har qatorda: «MM-DD|foiz|Nomi» yoki oraliq «MM-DD..MM-DD|foiz|Nomi».
 * Bonus hamma uchun aksiya (TOPUP_BONUS_PERCENT) bilan qo'shilmaydi — kattasi olinadi.
 */
class PromoDays
{
    public const DEFAULT = "01-01|20|Yangi yil\n03-08|20|8-mart — Xotin-qizlar kuni\n03-21|25|Navro'z bayrami\n05-09|15|Xotira va qadrlash kuni\n"
        . "08-25..08-31|20|Yangi o'quv yiliga tayyorgarlik\n09-01|25|Mustaqillik kuni\n10-01|20|O'qituvchi va murabbiylar kuni\n"
        . "12-08|15|Konstitutsiya kuni\n12-20..12-31|15|Qishki sessiya mavsumi\n05-20..05-31|15|Yozgi sessiya mavsumi";

    public static function enabled(): bool
    {
        return (bool)Engine::c('PROMO_CALENDAR', true);
    }

    private static function localNow(): int
    {
        return time() + (defined('ADMIN_TZ_OFFSET') ? (int)ADMIN_TZ_OFFSET : 18000);
    }

    /** @return array<int,array{from:string,to:string,pct:int,name:string}> */
    public static function rules(): array
    {
        $out = [];
        foreach (preg_split('/\R/u', (string)Engine::c('PROMO_DAYS', self::DEFAULT)) ?: [] as $line) {
            if (!preg_match('/^\s*(\d{2}-\d{2})(?:\s*\.\.\s*(\d{2}-\d{2}))?\s*\|\s*(\d{1,3})\s*\|\s*(.{2,80}?)\s*$/u', $line, $m)) {
                continue;
            }
            $pct = (int)$m[3];
            if ($pct < 1 || $pct > 100) {
                continue;
            }
            $out[] = ['from' => $m[1], 'to' => ($m[2] ?? '') !== '' ? $m[2] : $m[1], 'pct' => $pct, 'name' => $m[4]];
        }
        return $out;
    }

    /** Bugungi aksiya yoki null. */
    public static function today(?int $ts = null): ?array
    {
        if (!self::enabled()) {
            return null;
        }
        $md = gmdate('m-d', $ts ?? self::localNow());
        $best = null;
        foreach (self::rules() as $r) {
            $in = $r['from'] <= $r['to'] ? ($md >= $r['from'] && $md <= $r['to']) : ($md >= $r['from'] || $md <= $r['to']);   // yil oxiridan o'tuvchi oraliq
            if ($in && (!$best || $r['pct'] > $best['pct'])) {
                $best = $r;
            }
        }
        return $best;
    }

    /** To'ldirish bonusi foizi: hamma uchun aksiya va bugungi bayram aksiyasining kattasi. */
    public static function bonusPercent(): int
    {
        $base = (int)Engine::c('TOPUP_BONUS_PERCENT', 0);
        $t = self::today();
        return max($base, $t ? (int)$t['pct'] : 0);
    }

    /** Mijozga ko'rsatiladigan qisqa satr (aksiya kuni bo'lsa). */
    public static function line(): string
    {
        $t = self::today();
        return $t && (int)$t['pct'] > (int)Engine::c('TOPUP_BONUS_PERCENT', 0)
            ? "🎉 <b>" . Telegram::escape($t['name']) . "</b> munosabati bilan bugun balansni to'ldirishga <b>+{$t['pct']}% bonus</b>!"
            : '';
    }

    /** Maintenance'dan: aksiya kuni soat 10:00 dan keyin — bir marta hammaga xabar (rassilka navbati orqali). */
    public static function run(): void
    {
        $t = self::today();
        if (!$t || !(bool)Engine::c('PROMO_BROADCAST', true)) {
            return;
        }
        $now = self::localNow();
        $h = (int)gmdate('G', $now);
        if ($h < 10 || $h > 19) {
            return;
        }
        // oraliq aksiyada faqat birinchi kuni yuboriladi
        $key = 'promo_bc:' . gmdate('Y', $now) . ':' . $t['from'] . ':' . $t['name'];
        if (Engine::kvGet($key)) {
            return;
        }
        if ((int)db()->query("SELECT COUNT(*) FROM broadcasts WHERE status = 'running'")->fetchColumn() > 0) {
            return;                                    // admin rassilkasi ketmoqda — keyinroq
        }
        Engine::kvSet($key, '1');
        $min = (int)Engine::c('TOPUP_BONUS_MIN_AMOUNT', 0);
        $text = "🎉 **" . $t['name'] . "** muborak!\n\n"
            . "Shu munosabat bilan " . ($t['from'] === $t['to'] ? 'bugun' : 'shu kunlarda') . " balansni to'ldirsangiz — **+{$t['pct']}% bonus** qo'shiladi"
            . ($min > 0 ? " (" . number_format($min, 0, '', ' ') . " so'mdan boshlab)" : '') . ".\n\n"
            . "Masalan: 20 000 so'm to'ldirsangiz — balansingizga " . number_format(20000 + (int)floor(20000 * $t['pct'] / 100), 0, '', ' ') . " so'm tushadi.\n"
            . "💰 Botda «Balans» tugmasini bosing.";
        try {
            $r = Maintenance::create(defined('ADMIN_CHAT_ID') ? (int)ADMIN_CHAT_ID : 0, $text, null, null, 'all');
            Telegram::notifyAdmin("🎉 Aksiya kalendari: «" . Telegram::escape($t['name']) . "» (+{$t['pct']}%) — " . Telegram::escape((string)($r['msg'] ?? '')));
        } catch (Throwable $e) {
            logError('promo', 'Aksiya xabari yuborilmadi: ' . $e->getMessage());
        }
    }
}
