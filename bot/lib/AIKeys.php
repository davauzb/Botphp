<?php

/**
 * AI KALITLARI — admin paneldan (config.php ni tahrirlamasdan) qo'shish, o'chirish, sinash.
 * Bazadagi kalitlar config.php dagi AI_PROVIDERS ga QO'SHIMCHA marshrut sifatida ishlaydi (router hammasini birga taqsimlaydi).
 * Faqat «Egasi» (owner) roli boshqaradi; panelda kalitning faqat oxirgi 4 belgisi ko'rinadi.
 */
class AIKeys
{
    /** Tayyor provayderlar: nom => [endpoint, modellar, izoh] */
    public const PRESETS = [
        'gemini' => ['https://generativelanguage.googleapis.com/v1beta/openai/chat/completions', 'gemini-flash-latest,gemini-flash-lite-latest', 'Google AI Studio — bepul (aistudio.google.com/apikey)'],
        'groq' => ['https://api.groq.com/openai/v1/chat/completions', 'openai/gpt-oss-120b', 'Groq — bepul, juda tez (console.groq.com/keys)'],
        'cerebras' => ['https://api.cerebras.ai/v1/chat/completions', 'gpt-oss-120b', 'Cerebras — bepul limit (cloud.cerebras.ai)'],
        'openrouter' => ['https://openrouter.ai/api/v1/chat/completions', 'meta-llama/llama-3.3-70b-instruct:free', 'OpenRouter — :free modellar bepul'],
        'openai' => ['https://api.openai.com/v1/chat/completions', 'gpt-4o-mini', 'OpenAI — pullik'],
        'deepseek' => ['https://api.deepseek.com/chat/completions', 'deepseek-chat', 'DeepSeek — arzon'],
    ];

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS ai_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, provider TEXT NOT NULL, endpoint TEXT NOT NULL, models TEXT NOT NULL,
            api_key TEXT NOT NULL, priority INTEGER NOT NULL DEFAULT 5, enabled INTEGER NOT NULL DEFAULT 1, note TEXT, added_by INTEGER, created_at INTEGER)');
    }

    /** AIClient::configuredRoutes() uchun: AI_PROVIDERS formatidagi qo'shimcha provayderlar. */
    public static function providers(): array
    {
        try {
            $rows = db()->query('SELECT * FROM ai_keys WHERE enabled = 1')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $models = array_values(array_filter(array_map('trim', explode(',', (string)$r['models']))));
            if (!$models) {
                continue;
            }
            $p = ['name' => (string)$r['provider'], 'endpoint' => (string)$r['endpoint'], 'models' => $models, 'keys' => [(string)$r['api_key']],
                'priority' => (int)$r['priority'], 'max_parallel' => 2];
            if ((string)$r['provider'] === 'openrouter') {
                $p['headers'] = ['HTTP-Referer' => 'https://t.me/', 'X-Title' => 'StudyBot'];
            }
            $out[] = $p;
        }
        return $out;
    }

    public static function list(): array
    {
        $rows = db()->query('SELECT * FROM ai_keys ORDER BY priority, id')->fetchAll(PDO::FETCH_ASSOC);
        $st = AIClient::status();
        $byTail = [];
        foreach ($st['routes'] as $r) {
            $byTail[$r['provider'] . $r['key']][] = $r;
        }
        $items = array_map(function ($r) use ($byTail) {
            $tail = '…' . substr((string)$r['api_key'], -4);
            $routes = $byTail[$r['provider'] . $tail] ?? [];
            return ['id' => (int)$r['id'], 'provider' => (string)$r['provider'], 'models' => (string)$r['models'], 'key' => $tail,
                'priority' => (int)$r['priority'], 'enabled' => (bool)$r['enabled'], 'note' => (string)($r['note'] ?? ''), 'created_at' => (int)$r['created_at'],
                'state' => $routes ? $routes[0]['state'] : ($r['enabled'] ? 'new' : 'off'), 'ok' => array_sum(array_column($routes, 'ok')),
                'err' => array_sum(array_column($routes, 'err')), 'last_error' => (string)($routes[0]['last_error'] ?? '')];
        }, $rows);
        $presets = [];
        foreach (self::PRESETS as $k => [$e, $m, $n]) {
            $presets[] = ['k' => $k, 'models' => $m, 'note' => $n];
        }
        return ['items' => $items, 'routes' => $st['routes'], 'summary' => ['total' => $st['total'], 'ready' => $st['ready'], 'cooling' => $st['cooling'], 'dead' => $st['dead']],
            'presets' => $presets];
    }

    public static function add(int $admin, string $provider, string $key, string $models, string $endpoint, int $priority, string $note): array
    {
        $key = trim($key);
        if (strlen($key) < 16 || strlen($key) > 300 || preg_match('/\s/', $key)) {
            return ['ok' => false, 'msg' => "Kalit noto'g'ri ko'rinadi"];
        }
        $provider = strtolower(trim($provider));
        if (isset(self::PRESETS[$provider])) {
            $endpoint = self::PRESETS[$provider][0];
            $models = trim($models) !== '' ? $models : self::PRESETS[$provider][1];
        } else {
            $provider = preg_replace('/[^a-z0-9_\-]/', '', $provider) ?: 'custom';
            if (!preg_match('#^https://[a-z0-9.\-]+(:\d+)?/[\w\-./]*$#i', $endpoint)) {
                return ['ok' => false, 'msg' => "Endpoint https://…/chat/completions ko'rinishida bo'lsin"];
            }
        }
        $ml = array_filter(array_map('trim', explode(',', $models)), fn($m) => (bool)preg_match('#^[\w.:/\-]{2,80}$#', $m));
        $models = implode(',', array_slice(array_values($ml), 0, 4));
        if ($models === '') {
            return ['ok' => false, 'msg' => 'Model nomi kerak'];
        }
        $dup = db()->prepare('SELECT COUNT(*) FROM ai_keys WHERE api_key = ?');
        $dup->execute([$key]);
        if ((int)$dup->fetchColumn()) {
            return ['ok' => false, 'msg' => "Bu kalit allaqachon qo'shilgan"];
        }
        $test = self::probe(['provider' => $provider, 'endpoint' => $endpoint, 'model' => explode(',', $models)[0], 'api_key' => $key]);
        db()->prepare('INSERT INTO ai_keys (provider, endpoint, models, api_key, priority, enabled, note, added_by, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$provider, $endpoint, $models, $key, max(1, min(9, $priority)), $test['ok'] ? 1 : 0, mb_substr(trim($note), 0, 80), $admin, time()]);
        AdminSecurity::audit('ai_key_add', $admin, $provider, '…' . substr($key, -4) . ($test['ok'] ? '' : ' (sinovdan o\'tmadi)'));
        return $test['ok']
            ? ['ok' => true, 'msg' => "✅ Kalit ishlayapti va qo'shildi (" . $test['ms'] . ' ms)']
            : ['ok' => true, 'msg' => "⚠️ Kalit saqlandi, lekin sinovdan o'tmadi va o'chirib qo'yildi: " . $test['msg'], 'warn' => true];
    }

    public static function toggle(int $admin, int $id, bool $on): array
    {
        db()->prepare('UPDATE ai_keys SET enabled = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
        if ($on) {
            self::unblock($id);
        }
        AdminSecurity::audit('ai_key_toggle', $admin, (string)$id, $on ? 'on' : 'off');
        return ['ok' => true, 'msg' => $on ? 'Yoqildi' : "O'chirildi"];
    }

    public static function delete(int $admin, int $id): array
    {
        db()->prepare('DELETE FROM ai_keys WHERE id = ?')->execute([$id]);
        AdminSecurity::audit('ai_key_delete', $admin, (string)$id);
        return ['ok' => true, 'msg' => "Kalit o'chirildi"];
    }

    public static function test(int $id): array
    {
        $st = db()->prepare('SELECT * FROM ai_keys WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return ['ok' => false, 'msg' => 'Topilmadi'];
        }
        $t = self::probe(['provider' => $r['provider'], 'endpoint' => $r['endpoint'], 'model' => explode(',', (string)$r['models'])[0], 'api_key' => $r['api_key']]);
        if ($t['ok']) {
            self::unblock($id);
        }
        return ['ok' => $t['ok'], 'msg' => $t['ok'] ? "✅ Ishlayapti (" . $t['ms'] . ' ms)' : '❌ ' . $t['msg']];
    }

    /** Sinov muvaffaqiyatli bo'lsa — routerdagi «o'lik/sovitish» belgisini olib tashlaymiz. */
    private static function unblock(int $id): void
    {
        $st = db()->prepare('SELECT * FROM ai_keys WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return;
        }
        foreach (explode(',', (string)$r['models']) as $m) {
            $rid = substr(sha1($r['provider'] . '|' . $r['api_key'] . '|' . trim($m)), 0, 20);
            db()->prepare('UPDATE ai_routes SET perm = 0, cooldown_until = 0, fail_streak = 0 WHERE route = ?')->execute([$rid]);
        }
    }

    /** Bitta kichik so'rov bilan kalitni tekshiradi. */
    private static function probe(array $r): array
    {
        $t0 = microtime(true);
        $ch = curl_init($r['endpoint']);
        $payload = ['model' => $r['model'], 'messages' => [['role' => 'user', 'content' => 'Reply with: OK']], 'max_tokens' => 16, 'temperature' => 0];
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $r['api_key']], CURLOPT_POSTFIELDS => json_encode($payload)]);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $ms = (int)((microtime(true) - $t0) * 1000);
        if ($code === 200) {
            $d = json_decode($body, true);
            if (isset($d['choices'][0])) {
                return ['ok' => true, 'ms' => $ms];
            }
        }
        $msg = match (true) {
            $code === 0 => 'Tarmoq xatosi: ' . $err,
            $code === 401 => 'Kalit yaroqsiz (401)',
            $code === 402 || $code === 403 => "Ruxsat yoki kredit yo'q ($code)",
            $code === 404 => 'Model yoki endpoint topilmadi (404)',
            $code === 429 => "Limit tugagan (429) — kalit to'g'ri, keyinroq ishlaydi",
            default => "HTTP $code: " . mb_substr(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '', 0, 140),
        };
        return ['ok' => $code === 429, 'ms' => $ms, 'msg' => $msg];
    }
}
