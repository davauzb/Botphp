// Animatsiya vaqt chizig'i: ijro, kalit kadrlar, sozlamalar, video yozish.
import { h, iconBtn, toast, openSheet, row, numberInput, toggle } from './dom.js';
import { Animator } from '../animation.js';
import { saveFile } from '../io.js';
import { t } from '../i18n.js';

export function createTimeline(editor, animator, app) {
  const el = h('div.timeline');
  const playBtn = iconBtn('play', t('anim.play'), () => animator.toggle(), 'tl-play');
  const slider = h('input.tl-slider', { type: 'range', min: '0', step: '0.001' });
  const marks = h('div.tl-marks');
  const timeLbl = h('span.tl-time', '0.0s');
  const track = h('div.tl-track', marks, slider);

  slider.addEventListener('input', () => {
    if (animator.playing) animator.pause();
    animator.setTime(+slider.value);
  });

  el.append(
    playBtn,
    iconBtn('prevKey', t('anim.prevKey'), () => animator.jumpKey(editor.selected, -1)),
    track,
    timeLbl,
    iconBtn('nextKey', t('anim.nextKey'), () => animator.jumpKey(editor.selected, 1)),
    iconBtn('keyAdd', t('anim.addKey'), () => {
      if (!editor.selected) return toast(t('err.selectFirst'), 'error');
      animator.addKey(editor.selected);
      toast(t('anim.keyAdded') + ' ' + animator.time.toFixed(2) + 's');
    }, 'tl-key'),
    iconBtn('keyDel', t('anim.delKey'), () => {
      if (!animator.removeKey(editor.selected)) toast(t('anim.noKeyHere'));
    }),
    iconBtn('settings', t('anim.settings'), () => openSettings())
  );

  function syncTime() {
    const d = animator.settings.duration;
    slider.max = String(d);
    slider.value = String(animator.time);
    timeLbl.textContent = animator.time.toFixed(1) + 's';
  }
  function syncMarks() {
    marks.innerHTML = '';
    const keys = editor.selected?.userData?.keys || [];
    const d = animator.settings.duration || 1;
    for (const k of keys) marks.append(h('span.tl-mark', { style: { left: `calc(${(k.t / d) * 100}% )` } }));
  }
  function syncPlay() {
    playBtn.innerHTML = '';
    playBtn.append(h('span', { html: (animator.playing ? iconSvg('pause') : iconSvg('play')) }));
  }

  editor.on('animTime', syncTime);
  editor.on('animState', syncPlay);
  editor.on('selectionChanged', syncMarks);
  editor.on('keysChanged', syncMarks);
  editor.on('historyChanged', () => {
    syncMarks();
    syncTime();
  });
  editor.on('projectLoaded', () => {
    animator.stop();
    syncMarks();
    syncTime();
  });

  function openSettings() {
    const s = animator.settings;
    const body = h(
      'div.dialog',
      row(t('anim.duration'), numberInput(s.duration, { step: 0.5, min: 0.5, max: 120, decimals: 2, onChange: (v) => ((s.duration = Math.max(0.5, v)), syncTime(), syncMarks(), editor.commit('anim')) })),
      row(t('anim.fps'), numberInput(s.fps, { step: 1, min: 10, max: 60, decimals: 0, onChange: (v) => ((s.fps = Math.round(v)), editor.commit('anim')) })),
      row(t('anim.loop'), toggle(s.loop, (v) => ((s.loop = v), editor.commit('anim')))),
      h('div.menu-sep'),
      h('p.hint', t('anim.help')),
      h(
        'div.dialog-actions',
        editor.selected?.userData?.keys ? h('button.btn', { type: 'button', on: { click: () => (animator.clearKeys(editor.selected), sh.close()) } }, t('anim.clearKeys')) : null,
        h('button.btn.primary', { type: 'button', on: { click: () => (sh.close(), recordVideo(animator, editor, app)) } }, h('span', { html: iconSvg('record') }), t('anim.record'))
      )
    );
    const sh = openSheet(t('anim.settings'), body);
  }

  syncTime();
  syncPlay();
  return { el, syncTime, syncMarks };
}

import { icon as iconSvg } from '../icons.js';

/** Videoga yozish (MP4 yoki WebM) va saqlash. */
export async function recordVideo(animator, editor, app) {
  if (!Animator.videoMime()) return toast(t('anim.noVideo'), 'error');
  if (!animator.animated().length && !editor.effects?.list.length) {
    toast(t('anim.nothing'), 'error');
    return;
  }
  app.busy(true, t('anim.recording') + ' 0%');
  try {
    const { blob, ext } = await animator.record({ onProgress: (p) => app.busy(true, t('anim.recording') + ' ' + Math.round(p * 100) + '%') });
    app.busy(true, t('file.exporting'));
    const name = `${(app.projectName || 'mini3d').replace(/[\\/:*?"<>|]+/g, '_')}-${Date.now()}.${ext}`;
    const where = await saveFile(name, blob, blob.type || 'video/' + ext);
    toast(t('file.savedTo') + ' ' + where, 'ok');
  } catch (e) {
    console.error(e);
    toast(t('anim.noVideo'), 'error');
  } finally {
    app.busy(false);
  }
}
