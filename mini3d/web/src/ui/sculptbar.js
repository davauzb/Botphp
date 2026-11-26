// Sculpt rejimi paneli: cho'tkalar, radius, kuch, simmetriya, detal.
import { h, iconBtn, toast } from './dom.js';
import { brushes, MAX_TRIS } from '../sculpt.js';
import { icon } from '../icons.js';
import { t } from '../i18n.js';

export function createSculptBar(editor, sculptor, settings, save) {
  const el = h('div.sculptbar');
  const brushRow = h('div.sb-brushes');
  const info = h('span.sb-info');

  const brushBtns = brushes.map((b) => {
    const btn = h('button.sb-brush', { type: 'button', title: t(b.label) }, h('span', { html: icon(b.icon, 22) }), h('span.sb-label', t(b.label)));
    btn.addEventListener('click', () => {
      sculptor.brush = b.id;
      settings.sculptBrush = b.id;
      save();
      sync();
    });
    btn.dataset.id = b.id;
    return btn;
  });
  brushRow.append(...brushBtns);

  const range = (label, min, max, step, get, set) => {
    const input = h('input.slider', { type: 'range', min: String(min), max: String(max), step: String(step) });
    input.value = String(get());
    const val = h('span.sb-val', String(Math.round(get() * (max <= 1 ? 100 : 1))));
    input.addEventListener('input', () => {
      set(+input.value);
      val.textContent = String(Math.round(+input.value * (max <= 1 ? 100 : 1)));
    });
    input.addEventListener('change', save);
    return h('label.sb-range', h('span.sb-rl', label), input, val);
  };

  const invBtn = iconBtn('invert', t('sculpt.invert'), () => {
    sculptor.invert = !sculptor.invert;
    sync();
  }, 'sb-tog');
  const symBtn = iconBtn('symmetry', t('sculpt.symmetry'), () => {
    sculptor.symmetry = !sculptor.symmetry;
    settings.sculptSym = sculptor.symmetry;
    save();
    sync();
    toast(sculptor.symmetry ? t('sculpt.symOn') : t('sculpt.symOff'));
  }, 'sb-tog');
  const detailBtn = iconBtn('detail', t('sculpt.subdivide'), () => {
    if (!sculptor.subdivide()) toast(t('sculpt.tooMany') + ' (' + MAX_TRIS.toLocaleString() + ')', 'error');
    else toast(t('sculpt.subdivided') + ': ' + Math.round(sculptor.triangleCount()).toLocaleString());
    sync();
  }, 'sb-tog');
  const relaxBtn = iconBtn('smooth', t('sculpt.relax'), () => {
    sculptor.relax(2);
    toast(t('done'));
  }, 'sb-tog');
  const doneBtn = h('button.btn.primary.sb-done', { type: 'button', on: { click: () => sculptor.exit() } }, h('span', { html: icon('check', 18) }), t('sculpt.done'));

  el.append(
    brushRow,
    h(
      'div.sb-row',
      range(t('sculpt.radius'), 8, 160, 1, () => sculptor.radius, (v) => ((sculptor.radius = v), (settings.sculptRadius = v))),
      range(t('sculpt.strength'), 0.05, 1, 0.01, () => sculptor.strength, (v) => ((sculptor.strength = v), (settings.sculptStrength = v)))
    ),
    h('div.sb-row', invBtn, symBtn, detailBtn, relaxBtn, info, h('div.spacer'), doneBtn)
  );

  function sync() {
    brushBtns.forEach((b) => b.classList.toggle('active', b.dataset.id === sculptor.brush));
    invBtn.classList.toggle('active', sculptor.invert);
    symBtn.classList.toggle('active', sculptor.symmetry);
    if (sculptor.mesh) info.textContent = Math.round(sculptor.triangleCount()).toLocaleString() + ' ▲';
  }

  // Saqlangan sozlamalar
  sculptor.brush = settings.sculptBrush || 'draw';
  sculptor.radius = settings.sculptRadius || 45;
  sculptor.strength = settings.sculptStrength ?? 0.5;
  sculptor.symmetry = settings.sculptSym ?? true;

  editor.on('sculptChanged', (on) => {
    el.classList.toggle('show', on);
    document.body.classList.toggle('sculpting', on);
    if (on) {
      // faqat sculpt uchun: tezkor ko'rsatma
      if (!settings.sculptHintShown) {
        settings.sculptHintShown = true;
        save();
        toast(t('sculpt.hint'));
      }
    }
    sync();
  });
  editor.on('historyChanged', sync);
  sync();
  return { el, sync };
}
