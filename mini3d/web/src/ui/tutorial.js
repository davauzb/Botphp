// Ilova ichidagi dars: "Donghu uslubidagi maxluqni qo'lda yasash"
import { h, openSheet } from './dom.js';
import { addTemplate } from '../templates.js';
import { t } from '../i18n.js';

export function openTutorial(editor, app) {
  const steps = [
    { k: 'tut.1', action: () => addTemplate(editor, 'dragon'), btn: 'tut.do1' },
    { k: 'tut.2' },
    { k: 'tut.3' },
    { k: 'tut.4' },
    { k: 'tut.5' },
    { k: 'tut.6' },
    { k: 'tut.7' },
    { k: 'tut.8' },
    { k: 'tut.9' },
    { k: 'tut.10' },
    { k: 'tut.11' },
  ];
  const body = h(
    'div.help.tutorial',
    h('p', t('tut.intro')),
    h(
      'ol.tut-steps',
      ...steps.map((s) =>
        h(
          'li',
          h('span', { html: t(s.k) }),
          s.action ? h('button.btn.primary.tut-btn', { type: 'button', on: { click: () => (sh.close(), s.action()) } }, t(s.btn)) : null
        )
      )
    ),
    h('p.hint', t('tut.tip'))
  );
  const sh = openSheet('🎓 ' + t('tut.title'), body, { wide: true });
  void app;
}
