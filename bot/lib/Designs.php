<?php

require_once __DIR__ . '/ProDeck.php';

/**
 * Slayd dizaynlari ro'yxati.
 *
 * Oddiy (1-12) dizaynlar config.php dagi PPTX_DESIGNS dan olinadi.
 * «⭐ PRO» dizaynlar (13+) — ProDeck (16:9, mavzuga oid foto fon, shisha kartalar, o'tish effekti, animatsiya, nutq matni).
 * Ular shu yerda, shu sababli eski config.php ni o'zgartirmasdan ham ishlaydi.
 * O'chirish: config.php → define('PRO_ENABLED', false);
 */
function proDesigns(): array
{
    $out = [];
    $i = 13;
    foreach (ProDeck::THEMES as $key => $t) {
        $out[$key] = [
            'label' => $i . '. ⭐ PRO — ' . $t['label'], 'accent' => $t['acc'], 'title' => $t['bg2'], 'bg' => $t['bg'],
            'layout' => 'cards', 'skin' => 'classic', 'pro' => true, 'prodeck' => true,
        ];
        $i++;
    }
    return $out;
}

/** Barcha dizaynlar: config (1-12) + PRO (13+, yoqilgan bo'lsa). */
function pptxDesigns(): array
{
    static $all = null;
    if ($all === null) {
        $base = defined('PPTX_DESIGNS') ? PPTX_DESIGNS : [];
        $all = ProDeck::enabled() ? $base + proDesigns() : $base;
    }
    return $all;
}

/** Faqat oddiy (PRO bo'lmagan) dizaynlar. */
function standardDesigns(): array
{
    return array_filter(pptxDesigns(), fn($d) => empty($d['pro']));
}

function isProDesign(?string $key): bool
{
    $d = $key !== null ? (pptxDesigns()[$key] ?? null) : null;
    return $d !== null && !empty($d['pro']);
}

/** Dizayn tanlash tugmalari (oddiy 1-12 + «PRO» tugmasi). */
function designPickerKeyboard(string $volumeKey): array
{
    $buttons = [];
    $row = [];
    foreach (standardDesigns() as $key => $d) {
        $row[] = ['text' => (string)(count($buttons) * 4 + count($row) + 1), 'callback_data' => "design:{$key}:{$volumeKey}"];
        if (count($row) === 4) {
            $buttons[] = $row;
            $row = [];
        }
    }
    if ($row) {
        $buttons[] = $row;
    }
    if (ProDeck::enabled()) {
        $buttons[] = [['text' => '⭐ PRO dizaynlar — rasmli fon + effektlar + nutq matni', 'callback_data' => "designpro:{$volumeKey}"]];
    }
    return ['inline_keyboard' => $buttons];
}

/** PRO dizaynlar tugmalari: har biri nomi bilan, pastda «orqaga». */
function proPickerKeyboard(string $volumeKey): array
{
    $rows = [];
    foreach (proDesigns() as $key => $d) {
        $rows[] = [['text' => '⭐ ' . ProDeck::THEMES[$key]['label'], 'callback_data' => "design:{$key}:{$volumeKey}"]];
    }
    $rows[] = [['text' => '⬅️ Oddiy dizaynlar', 'callback_data' => "designstd:{$volumeKey}"]];
    return ['inline_keyboard' => $rows];
}
