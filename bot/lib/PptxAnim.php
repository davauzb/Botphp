<?php

/**
 * PRO taqdimotlar uchun effektlar (PPTX XML):
 *  - transition(): slaydlar orasidagi o'tish (fade, push, wipe, cover, split, dissolve)
 *  - timing(): shakllarning birin-ketin "fade" bilan paydo bo'lishi (avtomatik, bosish talab qilinmaydi)
 * Ikkalasi ham <p:sld> ichida clrMapOvr'dan KEYIN: avval transition, keyin timing.
 */
class PptxAnim
{
    public static function transition(string $style): string
    {
        return match ($style) {
            'push' => '<p:transition spd="med"><p:push dir="u"/></p:transition>',
            'wipe' => '<p:transition spd="med"><p:wipe dir="l"/></p:transition>',
            'cover' => '<p:transition spd="med"><p:cover dir="l"/></p:transition>',
            'split' => '<p:transition spd="med"><p:split orient="horz" dir="out"/></p:transition>',
            'dissolve' => '<p:transition spd="med"><p:dissolve/></p:transition>',
            'none' => '',
            default => '<p:transition spd="med"><p:fade/></p:transition>',
        };
    }

    /**
     * $spids — shakl id'lari (slayd ichida noyob), paydo bo'lish tartibida.
     * Birinchisi slayd ochilishi bilan o'zi boshlanadi, qolganlari ketma-ket kechikish bilan (jami ~2 soniyadan oshmaydi).
     */
    public static function timing(array $spids, int $fadeMs = 450, int $maxTotalMs = 2200): string
    {
        $spids = array_values(array_unique(array_map('intval', $spids)));
        if (!$spids) {
            return '';
        }
        $n = count($spids);
        $step = $n > 1 ? min(120, (int)floor($maxTotalMs / ($n - 1))) : 0;
        $id = 4;
        $effects = '';
        foreach ($spids as $i => $spid) {
            $delay = $i * $step;
            $c1 = ++$id;
            $c2 = ++$id;
            $c3 = ++$id;
            $effects .= '<p:par><p:cTn id="' . $c1 . '" presetID="10" presetClass="entr" presetSubtype="0" fill="hold" nodeType="'
                . ($i === 0 ? 'afterEffect' : 'withEffect') . '"><p:stCondLst><p:cond delay="' . $delay . '"/></p:stCondLst><p:childTnLst>'
                . '<p:set><p:cBhvr><p:cTn id="' . $c2 . '" dur="1" fill="hold"><p:stCondLst><p:cond delay="0"/></p:stCondLst></p:cTn>'
                . '<p:tgtEl><p:spTgt spid="' . $spid . '"/></p:tgtEl><p:attrNameLst><p:attrName>style.visibility</p:attrName></p:attrNameLst></p:cBhvr>'
                . '<p:to><p:strVal val="visible"/></p:to></p:set>'
                . '<p:animEffect transition="in" filter="fade"><p:cBhvr><p:cTn id="' . $c3 . '" dur="' . $fadeMs . '"/>'
                . '<p:tgtEl><p:spTgt spid="' . $spid . '"/></p:tgtEl></p:cBhvr></p:animEffect>'
                . '</p:childTnLst></p:cTn></p:par>';
        }
        return '<p:timing><p:tnLst><p:par><p:cTn id="1" dur="indefinite" restart="never" nodeType="tmRoot"><p:childTnLst>'
            . '<p:seq concurrent="1" nextAc="seek"><p:cTn id="2" dur="indefinite" nodeType="mainSeq"><p:childTnLst>'
            . '<p:par><p:cTn id="3" fill="hold"><p:stCondLst><p:cond delay="indefinite"/><p:cond evt="onBegin" delay="0"><p:tn val="2"/></p:cond></p:stCondLst><p:childTnLst>'
            . '<p:par><p:cTn id="4" fill="hold"><p:stCondLst><p:cond delay="0"/></p:stCondLst><p:childTnLst>'
            . $effects
            . '</p:childTnLst></p:cTn></p:par></p:childTnLst></p:cTn></p:par>'
            . '</p:childTnLst></p:cTn><p:prevCondLst><p:cond evt="onPrev" delay="0"><p:tgtEl><p:sldTgt/></p:tgtEl></p:cond></p:prevCondLst>'
            . '<p:nextCondLst><p:cond evt="onNext" delay="0"><p:tgtEl><p:sldTgt/></p:tgtEl></p:cond></p:nextCondLst></p:seq>'
            . '</p:childTnLst></p:cTn></p:par></p:tnLst></p:timing>';
    }
}
