// "Sozlamalar" paneli: sahna (loyiha) va ilova sozlamalari.
import { h, section, row, toggle, colorInput, slider, numberInput, select } from './dom.js';
import { saveSettings } from '../storage.js';
import { languages, setLang, getLang, t } from '../i18n.js';

const bgPresets = ['#1e232b', '#0d0f12', '#2a2f3a', '#3b4252', '#e9edf2', '#ffffff', '#1b2a3a', '#2b1d2f'];

export function createSettings(editor, ui) {
  const el = h('div.settings');

  const render = () => {
    el.innerHTML = '';
    const s = editor.sceneSettings;
    const set = (k) => (v) => editor.setSceneSetting(k, v);
    const app = editor.settings;
    const setApp = (k, after) => (v) => {
      app[k] = v;
      saveSettings(app);
      after?.();
    };

    const bg = colorInput(s.background, {
      onInput: (v) => editor.setSceneSetting('background', v, false),
      onChange: (v) => editor.setSceneSetting('background', v),
    });
    el.append(
      section(
        t('set.scene'),
        row(t('set.background'), bg),
        h(
          'div.swatches',
          ...bgPresets.map((c) =>
            h('button.swatch', { type: 'button', style: { background: c }, on: { click: () => ((bg.value = c), editor.setSceneSetting('background', c)) } })
          )
        ),
        row(t('set.grid'), toggle(s.grid, set('grid'))),
        row(t('set.axes'), toggle(s.axes, set('axes'))),
        row(t('set.shadows'), toggle(s.shadows, set('shadows'))),
        row(t('set.ground'), toggle(s.ground, set('ground'))),
        row(t('set.fog'), toggle(s.fog, set('fog')))
      ),
      section(
        t('set.lighting'),
        row(t('set.env'), toggle(s.env, set('env'))),
        row(t('set.envIntensity'), slider(s.envIntensity, { min: 0, max: 3, step: 0.05, onInput: (v) => editor.setSceneSetting('envIntensity', v, false), onChange: set('envIntensity') })),
        row(t('set.studio'), toggle(s.studio, set('studio'))),
        row(t('set.studioIntensity'), slider(s.studioIntensity, { min: 0, max: 3, step: 0.05, onInput: (v) => editor.setSceneSetting('studioIntensity', v, false), onChange: set('studioIntensity') })),
        row(t('set.exposure'), slider(s.exposure, { min: 0.1, max: 3, step: 0.05, onInput: (v) => editor.setSceneSetting('exposure', v, false), onChange: set('exposure') }))
      ),
      section(
        t('set.effects'),
        row(t('set.bloom'), toggle(s.bloom, set('bloom'))),
        row(t('set.bloomStrength'), slider(s.bloomStrength ?? 0.8, { min: 0, max: 3, step: 0.05, onInput: (v) => editor.setSceneSetting('bloomStrength', v, false), onChange: set('bloomStrength') })),
        row(t('set.bloomRadius'), slider(s.bloomRadius ?? 0.4, { min: 0, max: 1, step: 0.02, onInput: (v) => editor.setSceneSetting('bloomRadius', v, false), onChange: set('bloomRadius') })),
        row(t('set.bloomThreshold'), slider(s.bloomThreshold ?? 1.0, { min: 0, max: 3, step: 0.05, onInput: (v) => editor.setSceneSetting('bloomThreshold', v, false), onChange: set('bloomThreshold') })),
        row(t('set.particles'), toggle(editor.effects?.enabled ?? true, (v) => editor.effects && ((editor.effects.enabled = v), editor.requestRender())))
      ),
      section(
        t('set.snapping'),
        row(t('set.snapMove'), numberInput(app.snapMove, { step: 0.05, min: 0.001, onChange: setApp('snapMove', () => editor.applySnap()) })),
        row(t('set.snapRotate'), numberInput(app.snapRotate, { step: 1, min: 1, max: 180, decimals: 1, onChange: setApp('snapRotate', () => editor.applySnap()) })),
        row(t('set.snapScale'), numberInput(app.snapScale, { step: 0.05, min: 0.001, onChange: setApp('snapScale', () => editor.applySnap()) }))
      ),
      section(
        t('set.app'),
        row(
          t('set.quality'),
          select(
            [
              { value: 'auto', label: t('q.auto') },
              { value: 'low', label: t('q.low') },
              { value: 'medium', label: t('q.medium') },
              { value: 'high', label: t('q.high') },
            ],
            app.quality,
            setApp('quality', () => editor.applyQuality())
          )
        ),
        row(t('set.autosave'), toggle(app.autosave, setApp('autosave'))),
        row(
          t('set.language'),
          select(
            languages.map((l) => ({ value: l.id, label: l.label })),
            getLang(),
            (v) => {
              setApp('lang')(v);
              setLang(v);
              location.reload();
            }
          )
        )
      )
    );
  };

  editor.on('projectLoaded', render);
  editor.on('historyChanged', () => {
    if (!el.contains(document.activeElement)) render();
  });
  render();
  return { el, refresh: render };
}
