<?php

require_once __DIR__ . '/DocStore.php';
require_once __DIR__ . '/DocAI.php';
require_once __DIR__ . '/SlidePdf.php';

/**
 * Hujjat tahrirlovchisi API'si. editor/api.php sessiya egasi hujjat (documents) ekanini aniqlagach shu yerga yo'naltiradi.
 * out() va readJson() — editor/api.php dagi umumiy funksiyalar (har xato holatda yagona "404" qaytaradi).
 */
class DocApi
{
    public static function handle(string $action, array $sess, array $doc): void
    {
        $pid = (string)$doc['id'];
        $tg = (int)$sess['telegram_id'];

        switch ($action) {
            case 'ping':
                out(['ok' => true]);

            case 'logout':
                EditorSecurity::revokeSession($sess['sess_hash']);
                out(['ok' => true]);

            case 'load':
                out(['ok' => true, 'doc' => [
                    'id' => $pid, 'title' => (string)$doc['title'], 'type' => (string)$doc['doc_type'], 'lang' => (string)$doc['lang'],
                    'version' => (int)$doc['version'], 'student' => (object)$doc['student'], 'sections' => $doc['sections'],
                ], 'stats' => DocStore::stats($doc['sections']), 'caps' => [
                    'ai' => DocAI::enabled(), 'pdf' => (bool)SlidePdf::sofficePath(),
                    'max_sections' => DocStore::MAX_SECTIONS, 'max_text' => DocStore::MAX_TEXT, 'max_heading' => DocStore::MAX_HEADING,
                    'ai_max_input' => DocAI::MAX_INPUT,
                ]]);

            case 'save':
                if (!EditorSecurity::rate("dsave:$pid", 300, 600)) {
                    EditorSecurity::deny(429);
                }
                $b = readJson(2621440);
                $res = DocStore::save($pid, $tg, (int)($b['version'] ?? 0), $b);
                if (empty($res['ok'])) {
                    $err = (string)($res['error'] ?? 'server');
                    out(['ok' => false, 'error' => $err], $err === 'conflict' ? 409 : 422);
                }
                out($res);

            case 'dai':
                if (!DocAI::enabled()) {
                    out(['ok' => false, 'error' => 'off'], 501);
                }
                $perHour = max(1, (int)Engine::c('EDITOR_AI_PER_HOUR', 20));
                if (!EditorSecurity::rate("dai:$pid", $perHour, 3600) || !EditorSecurity::rate("daiu:$tg", $perHour * 3, 3600)) {
                    out(['ok' => false, 'error' => 'rate'], 429);
                }
                $b = readJson(131072);
                $res = DocAI::rewrite(
                    is_string($b['heading'] ?? null) ? $b['heading'] : '',
                    is_string($b['text'] ?? null) ? $b['text'] : '',
                    is_string($b['mode'] ?? null) ? $b['mode'] : '',
                    is_string($b['note'] ?? null) ? $b['note'] : '',
                    (string)$doc['title'],
                    (string)$doc['lang']
                );
                if (empty($res['ok'])) {
                    $err = (string)($res['error'] ?? 'ai');
                    out(['ok' => false, 'error' => $err], $err === 'busy' ? 503 : 422);
                }
                EditorSecurity::audit('doc_ai', $tg, $pid);
                out(['ok' => true, 'text' => $res['text']]);

            case 'export':
                $b = readJson(4096);
                $format = (($b['format'] ?? '') === 'pdf') ? 'pdf' : 'docx';
                if (!EditorSecurity::rate("dexp:$pid", 8, 600) || ($format === 'pdf' && !EditorSecurity::rate("dexppdf:$pid", 4, 600))) {
                    out(['ok' => false, 'error' => 'rate'], 429);
                }
                @set_time_limit(180);
                $fresh = DocStore::getOwned($pid, $tg) ?: $doc;
                try {
                    $docx = DocStore::exportDocx($fresh);
                } catch (Throwable $e) {
                    logError('docstore', "DOCX eksport ($pid): " . $e->getMessage());
                    out(['ok' => false, 'error' => 'build'], 500);
                }
                $path = $docx;
                $ext = 'docx';
                if ($format === 'pdf') {
                    if (!SlidePdf::sofficePath()) {
                        @unlink($docx);
                        out(['ok' => false, 'error' => 'no_pdf'], 501);
                    }
                    $pdf = SlidePdf::convertToPdf($docx);
                    @unlink($docx);
                    if (!$pdf) {
                        logError('docstore', "PDF yaratilmadi ($pid)");
                        out(['ok' => false, 'error' => 'build'], 500);
                    }
                    $path = $pdf;
                    $ext = 'pdf';
                }
                $title = Telegram::escape((string)$fresh['title']);
                $caption = ($ext === 'pdf' ? '📄 PDF tayyor' : '✅ Tahrirlangan hujjat tayyor') . ": <b>{$title}</b>\n"
                    . "Pastdagi tugma orqali yana tahrirlashingiz mumkin.";
                $sent = Telegram::sendDocument($tg, $path, $caption, ['inline_keyboard' => [[
                    ['text' => '✏️ Qayta tahrirlash', 'callback_data' => 'edit:' . $pid],
                ]]], DocStore::fileSafeName((string)$fresh['title'], $ext));
                @unlink($path);
                if (!$sent) {
                    out(['ok' => false, 'error' => 'telegram'], 502);
                }
                db()->prepare('UPDATE documents SET exported_at = ? WHERE id = ?')->execute([time(), $pid]);
                EditorSecurity::audit($ext === 'pdf' ? 'doc_export_pdf' : 'doc_export', $tg, $pid);
                out(['ok' => true]);
        }
        EditorSecurity::deny();
    }
}
