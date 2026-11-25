// ============================================================
//  Mini 3D Muharrir — kirish nuqtasi
//  Tuzilma:
//    editor.js      - 3D yadro (sahna, kamera, tanlash, tarix)
//    primitives.js  - shakllar reestri (yangi shakl qo'shish shu yerda)
//    materials.js   - material turlari va andozalar
//    tools.js       - modellash vositalari (boolean, massiv, ko'zgu ...)
//    io.js          - import / eksport
//    storage.js     - loyihalarni saqlash
//    i18n.js        - tarjimalar (o'zbek / ingliz / rus)
//    ui/*           - interfeys
// ============================================================
import { Editor } from './editor.js';
import { createApp } from './ui/app.js';
import { loadSettings } from './storage.js';
import { setLang, t } from './i18n.js';
import { toast } from './ui/dom.js';

async function start() {
  const settings = loadSettings();
  setLang(settings.lang);
  document.documentElement.lang = settings.lang;

  let editor;
  try {
    const app = createApp(document.getElementById('app'), (container) => (editor = new Editor(container, settings)), settings);
    const restored = await app.restoreAutosave();
    if (!restored) {
      // Birinchi ishga tushirish: namunaviy sahna
      editor.addObject(editor.createPrimitive('box'), { select: false, commit: false });
      const s = editor.createPrimitive('sphere');
      s.position.x = 1.6;
      editor.addObject(s, { select: false, commit: false });
      const c = editor.createPrimitive('cone');
      c.position.x = -1.6;
      editor.addObject(c, { select: false, commit: false });
      editor.history.reset();
      editor.focus(null);
      app.markSaved();
      setTimeout(() => toast(t('welcome')), 400);
    } else {
      app.markSaved();
    }
    app.updateTitle();
    window.mini3d = { editor, app }; // konsol / plaginlar uchun
  } catch (e) {
    console.error(e);
    document.getElementById('app').innerHTML = `<div class="fatal"><h2>${t('err.webgl')}</h2><pre>${String(e && e.message)}</pre></div>`;
  } finally {
    document.getElementById('splash')?.classList.add('hide');
    setTimeout(() => document.getElementById('splash')?.remove(), 500);
  }
}

start();
