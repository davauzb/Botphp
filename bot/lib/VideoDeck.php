<?php

/**
 * 🎬 OVOZLI VIDEOTAQDIMOT (ixtiyoriy): taqdimot slaydlari PNG ga aylantiriladi (LibreOffice), har slayd uchun nutq matni
 * ovozga aylantiriladi (Google Gemini TTS) va ffmpeg bilan bitta MP4 videoga yig'iladi. Fayl taqdimotdan keyin alohida keladi.
 *
 * Talablar (hammasi bo'lmasa — funksiya jim o'chib turadi, buyurtma odatdagidek bajariladi):
 *   • serverda ffmpeg va LibreOffice (soffice), PHP'da proc_open ruxsat etilgan;
 *   • Gemini kaliti (AI_PROVIDERS dagi 'gemini' yoki paneldan qo'shilgan) — TTS shu kalit bilan;
 *   • admin panel → Sozlamalar → «🎬 Ovozli videotaqdimot» yoqilgan.
 * Eslatma: ovoz sifati tilga bog'liq; o'zbek tilida talaffuz mukammal bo'lmasligi mumkin (rus/ingliz — yaxshi).
 */
class VideoDeck
{
    private const TTS_MODEL = 'gemini-2.5-flash-preview-tts';

    public static function ffmpeg(): ?string
    {
        static $p = false;
        if ($p !== false) {
            return $p;
        }
        $p = null;
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (!function_exists('proc_open') || in_array('proc_open', $disabled, true)) {
            return null;
        }
        foreach ([defined('FFMPEG_PATH') ? (string)FFMPEG_PATH : '', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/bin/ffmpeg'] as $c) {
            if ($c !== '' && @is_executable($c)) {
                return $p = $c;
            }
        }
        return null;
    }

    public static function ttsKey(): string
    {
        if (defined('GEMINI_TTS_KEY') && GEMINI_TTS_KEY !== '') {
            return (string)GEMINI_TTS_KEY;
        }
        $providers = AI_PROVIDERS;
        if (class_exists('AIKeys')) {
            $providers = array_merge($providers, AIKeys::providers());
        }
        foreach ($providers as $pr) {
            if (stripos((string)($pr['endpoint'] ?? ''), 'generativelanguage.googleapis.com') !== false) {
                foreach ((array)($pr['keys'] ?? []) as $k) {
                    if (is_string($k) && strlen($k) > 20 && !preg_match('/^PUT_|YOUR_/i', $k)) {
                        return $k;
                    }
                }
            }
        }
        return '';
    }

    public static function enabled(): bool
    {
        return (bool)Engine::c('VIDEO_ENABLED', false) && self::ffmpeg() !== null && SlidePdf::sofficePath() !== null
            && (self::ttsKey() !== '' || (class_exists('Voice') && Voice::installed()));
    }

    /** Diagnostika uchun: nima yetishmayapti. */
    public static function missing(): array
    {
        $m = [];
        if (!self::ffmpeg()) {
            $m[] = 'ffmpeg';
        }
        if (!SlidePdf::sofficePath()) {
            $m[] = 'LibreOffice';
        }
        if (self::ttsKey() === '' && !(class_exists('Voice') && Voice::installed())) {
            $m[] = "Ovoz moduli (Tizim → Ovoz) yoki Gemini kaliti";
        }
        return $m;
    }

    /** Har slayd uchun aytiladigan matn: nutq (notes) bo'lsa — u, aks holda sarlavha + punktlar. */
    public static function narration(array $final): array
    {
        $out = [];
        foreach ($final as $s) {
            $t = trim((string)($s['notes'] ?? ''));
            if ($t === '') {
                $parts = [trim((string)($s['title'] ?? ''))];
                foreach ((array)($s['points'] ?? $s['bullets'] ?? []) as $pt) {
                    $parts[] = is_array($pt) ? trim(($pt['head'] ?? '') . ' ' . ($pt['text'] ?? '')) : trim((string)$pt);
                }
                if (!empty($s['lead'])) {
                    $parts[] = (string)$s['lead'];
                }
                $t = implode('. ', array_filter($parts, fn($x) => $x !== '' && !preg_match('/^\d+$/', $x)));
            }
            $t = preg_replace('/\s+/u', ' ', strip_tags($t)) ?? $t;
            $out[] = mb_substr($t, 0, 700);
        }
        return $out;
    }

    /** Matn → PCM (s16le, 24 kHz, mono) fayl. */
    public static function tts(string $text, string $lang, string $dst): bool
    {
        if (trim($text) === '') {
            return false;
        }
        // 🔊 Edge neyron ovozi o'rnatilgan bo'lsa — u (o'zbekcha talaffuzi ancha tabiiy): MP3 → PCM
        if (class_exists('Voice') && Voice::installed() && self::ffmpeg()) {
            $mp3 = $dst . '.mp3';
            if (Voice::edgeMp3($text, $lang, $mp3)
                && self::run([(string)self::ffmpeg(), '-y', '-loglevel', 'error', '-i', $mp3, '-f', 's16le', '-ac', '1', '-ar', '24000', $dst], 60)
                && is_file($dst) && filesize($dst) > 4800) {
                @unlink($mp3);
                return true;
            }
            @unlink($mp3);
        }
        if (self::ttsKey() === '') {
            return false;
        }
        $style = ['uz' => "O'zbek tilida", 'ru' => 'По-русски', 'en' => 'In English'][$lang] ?? '';
        $body = ['contents' => [['parts' => [['text' => ($style !== '' ? $style . ', ' : '') . "xotirjam, aniq ma'ruza ohangida o'qing: " . $text]]]],
            'generationConfig' => ['responseModalities' => ['AUDIO'], 'speechConfig' => ['voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => 'Kore']]]]];
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . self::TTS_MODEL . ':generateContent');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . self::ttsKey()], CURLOPT_POSTFIELDS => json_encode($body)]);
        $res = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) {
            logError('video', "TTS HTTP $code: " . mb_substr($res, 0, 200));
            return false;
        }
        $d = json_decode($res, true);
        $b64 = (string)($d['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? '');
        $pcm = $b64 !== '' ? base64_decode($b64, true) : false;
        return $pcm !== false && strlen($pcm) > 4800 && file_put_contents($dst, $pcm) !== false;
    }

    private static function run(array $args, int $timeout): bool
    {
        $cmd = implode(' ', array_map('escapeshellarg', $args));
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return false;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $end = microtime(true) + $timeout;
        $code = -1;
        while (true) {
            fread($pipes[1], 65536);
            fread($pipes[2], 65536);
            $st = proc_get_status($proc);
            if (!$st['running']) {
                $code = (int)$st['exitcode'];
                break;
            }
            if (microtime(true) > $end) {
                @proc_terminate($proc, 9);
                break;
            }
            usleep(100000);
        }
        foreach ($pipes as $p) {
            @fclose($p);
        }
        @proc_close($proc);
        return $code === 0;
    }

    /** Bitta slayd: rasm + ovoz (ovoz bo'lmasa — 4 soniya jim) → MP4 bo'lak. */
    public static function segment(string $png, ?string $pcm, string $dst): bool
    {
        $ff = (string)self::ffmpeg();
        $vf = 'scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2:color=white,format=yuv420p';
        $args = [$ff, '-y', '-loglevel', 'error', '-loop', '1', '-framerate', '2', '-i', $png];
        if ($pcm !== null && is_file($pcm)) {
            array_push($args, '-f', 's16le', '-ar', '24000', '-ac', '1', '-i', $pcm, '-af', 'apad=pad_dur=0.8', '-shortest');
        } else {
            array_push($args, '-f', 'lavfi', '-i', 'anullsrc=r=24000:cl=mono', '-t', '4');
        }
        array_push($args, '-vf', $vf, '-c:v', 'libx264', '-tune', 'stillimage', '-preset', 'veryfast', '-r', '2', '-c:a', 'aac', '-b:a', '96k', '-ar', '44100', $dst);
        return self::run($args, 60) && is_file($dst) && filesize($dst) > 1000;
    }

    public static function concat(array $segments, string $dst): bool
    {
        $list = $dst . '.txt';
        file_put_contents($list, implode("\n", array_map(fn($f) => "file '" . str_replace("'", "'\\''", $f) . "'", $segments)));
        $ok = self::run([(string)self::ffmpeg(), '-y', '-loglevel', 'error', '-f', 'concat', '-safe', '0', '-i', $list, '-c', 'copy', '-movflags', '+faststart', $dst], 90);
        @unlink($list);
        return $ok && is_file($dst) && filesize($dst) > 5000;
    }
}
