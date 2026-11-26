// AI Studiya: rasm → 3D → AI rejissyor → jonli sahna → video
import * as THREE from 'three';
import { h, openSheet, toast, confirmDialog, row, toggle, slider, textInput } from './dom.js';
import { pickFile, importFile } from '../io.js';
import { loadImageCanvas, imageTo3D } from '../ai/image3d.js';
import { analyzeImage, makePlan, applyPlan } from '../ai/director.js';
import { cloudProviders, canvasToPng } from '../ai/cloud3d.js';
import { isModelCached, MODELS } from '../ai/runtime.js';
import { recordVideo } from './timeline.js';
import { parseDescription, buildCreature } from '../ai/textgen.js';
import { icon } from '../icons.js';
import { t } from '../i18n.js';

const KEYS = 'mini3d.aikeys';
const loadKeys = () => {
  try {
    return JSON.parse(localStorage.getItem(KEYS) || '{}');
  } catch {
    return {};
  }
};
const saveKeys = (k) => {
  try {
    localStorage.setItem(KEYS, JSON.stringify(k));
  } catch {
    /* */
  }
};

export function openAIStudio(editor, app) {
  const st = { canvas: null, file: null, mode: 'phone', director: true, sensitivity: 0.5, depth: 0.3 };
  const keys = loadKeys();
  const preview = h('div.ai-preview', h('div.ai-ph', h('span', { html: icon('image', 40) }), h('div', t('ai.pickHint'))));
  const modeList = h('div.ai-modes');
  const progress = h('div.ai-progress', h('div.ai-bar', h('div.ai-fill')), h('div.ai-ptext'));
  const result = h('div.ai-result');
  const startBtn = h('button.btn.primary.ai-start', { type: 'button', disabled: true }, h('span', { html: icon('magic', 18) }), t('ai.start'));

  const pick = async () => {
    const f = await pickFile('image/*');
    if (!f) return;
    try {
      st.file = f;
      st.canvas = await loadImageCanvas(f, 1024);
      preview.innerHTML = '';
      const img = h('img', { src: st.canvas.toDataURL('image/jpeg', 0.85), alt: '' });
      preview.append(img, h('button.btn.ai-change', { type: 'button', on: { click: pick } }, t('ai.change')));
      startBtn.disabled = false;
    } catch {
      toast(t('err.image'), 'error');
    }
  };
  preview.addEventListener('click', (e) => {
    if (!st.canvas && e.target.closest('.ai-ph')) pick();
  });

  // Rejimlar
  const modes = [
    { id: 'phone', icon: 'zap', label: t('ai.modePhone'), sub: t('ai.modePhoneSub') },
    ...cloudProviders.map((p) => ({ id: p.id, icon: 'globe', label: p.label, sub: p.free ? t('ai.cloudFree') : t('ai.cloudPaid'), provider: p })),
  ];
  const renderModes = async () => {
    modeList.innerHTML = '';
    const cached = await isModelCached('depth').catch(() => false);
    for (const m of modes) {
      let sub = m.sub;
      if (m.id === 'phone') sub += ' · ' + (cached ? t('ai.modelReady') : t('ai.modelDownload').replace('{mb}', MODELS.depth.sizeMB));
      if (m.provider?.keyName) sub += ' · ' + (keys[m.provider.keyName] ? t('ai.keyOk') : t('ai.keyMissing'));
      const b = h(
        'button.ai-mode' + (st.mode === m.id ? '.active' : ''),
        { type: 'button', on: { click: () => ((st.mode = m.id), renderModes(), renderOpts()) } },
        h('span.lb-ic', { html: icon(m.icon) }),
        h('span.lb-text', h('span.lb-label', m.label), h('span.lb-sub', sub))
      );
      modeList.append(b);
    }
  };

  const opts = h('div.ai-opts');
  const renderOpts = () => {
    opts.innerHTML = '';
    opts.append(row(t('ai.director'), toggle(st.director, (v) => (st.director = v))));
    if (st.mode === 'phone') {
      opts.append(
        row(t('ai.sensitivity'), slider(st.sensitivity, { min: 0, max: 1, step: 0.05, onInput: (v) => (st.sensitivity = v) })),
        row(t('ai.depthAmount'), slider(st.depth, { min: 0.05, max: 1, step: 0.05, onInput: (v) => (st.depth = v) }))
      );
    }
    const prov = cloudProviders.find((p) => p.id === st.mode);
    if (prov) {
      const keyName = prov.keyName || 'hfToken';
      opts.append(
        row(
          prov.keyName ? t('ai.apiKey') : t('ai.hfToken'),
          textInput(keys[keyName] || '', {
            placeholder: prov.keyName ? '••••' : t('ai.optional'),
            onChange: (v) => {
              keys[keyName] = v.trim();
              saveKeys(keys);
              renderModes();
            },
          })
        ),
        h('p.hint', prov.free ? t('ai.freeHint') : t('ai.paidHint'))
      );
    }
  };

  const setProgress = (p, key) => {
    progress.classList.add('show');
    progress.querySelector('.ai-fill').style.width = Math.round(p * 100) + '%';
    progress.querySelector('.ai-ptext').textContent = t('ai.step.' + key) + ' — ' + Math.round(p * 100) + '%';
  };

  startBtn.addEventListener('click', async () => {
    if (!st.canvas) return;
    if (editor.content.children.length && app.dirty && !(await confirmDialog(t('ai.newSceneConfirm')))) return;
    startBtn.disabled = true;
    result.innerHTML = '';
    try {
      const res = await run(editor, app, st, keys, setProgress);
      setProgress(1, 'done');
      result.append(
        h('div.ai-title', t('ai.decisions')),
        h('ul.ai-steps', ...res.steps.map((s) => h('li', { html: s }))),
        h(
          'div.dialog-actions',
          h('button.btn', { type: 'button', on: { click: () => sheet.close() } }, t('ai.view')),
          h('button.btn.primary', { type: 'button', on: { click: () => (sheet.close(), recordVideo(editor.animator, editor, app)) } }, h('span', { html: icon('record', 18) }), t('anim.record'))
        )
      );
      result.scrollIntoView?.({ behavior: 'smooth' });
    } catch (e) {
      console.error(e);
      progress.classList.remove('show');
      const msg = String(e.message || t('err.tool'));
      result.append(h('div.ai-error', '⚠️ ' + (msg.startsWith('quota:') ? t('ai.quota') : msg)));
    } finally {
      startBtn.disabled = false;
    }
  });

  // ---------- Matndan yaratish ----------
  const ta = h('textarea.input.ai-text', { rows: 3, placeholder: t('tg.placeholder') });
  const examples = ['tg.ex1', 'tg.ex2', 'tg.ex3', 'tg.ex4', 'tg.ex5'].map((k) => h('button.chip', { type: 'button', on: { click: () => ((ta.value = t(k)), preview2()) } }, t(k)));
  const understoodEl = h('div.ai-understood');
  const preview2 = () => {
    const { understood } = parseDescription(ta.value);
    understoodEl.innerHTML = '';
    if (!ta.value.trim()) return;
    understoodEl.append(...understood.map(([k, v, w]) => h('span.chip.on', k + ': ' + v + (w && w !== '—' ? ` («${w}»)` : ''))));
  };
  ta.addEventListener('input', preview2);
  const genBtn = h('button.btn.primary.ai-start', { type: 'button' }, h('span', { html: icon('magic', 18) }), t('tg.create'));
  const result2 = h('div.ai-result');
  genBtn.addEventListener('click', async () => {
    if (!ta.value.trim()) return toast(t('tg.empty'), 'error');
    if (editor.content.children.length && app.dirty && !(await confirmDialog(t('ai.newSceneConfirm')))) return;
    const { spec, understood } = parseDescription(ta.value);
    app.busy(true, t('tg.building'));
    await new Promise((r) => setTimeout(r, 50));
    try {
      editor.animator.stop();
      editor.clear();
      const root = buildCreature(editor, spec);
      editor.syncLightProxies();
      editor.emit('sceneGraphChanged');
      editor.history.reset();
      // Kamerani butun maxluqqa moslash (old-chap tomondan, 3/4 ko'rinish)
      root.updateMatrixWorld(true);
      const bb = new THREE.Box3();
      root.traverse((o) => o.isMesh && o.visible && bb.expandByObject(o));
      const c = bb.getCenter(new THREE.Vector3()), r = bb.getSize(new THREE.Vector3()).length() * 0.5;
      editor.orbit.target.copy(c);
      editor.camera.position.copy(c).add(new THREE.Vector3(-0.7, 0.32, 0.64).normalize().multiplyScalar(r * 2.3));
      editor.orbit.update();
      app.projectId = null;
      app.projectName = root.name;
      app.updateTitle();
      app.markSaved();
      app.showTimeline(true);
      editor.animator.play();
      result2.innerHTML = '';
      result2.append(
        h('div.ai-title', t('tg.understood')),
        h('ul.ai-steps', ...understood.map(([k, v]) => h('li', k + ': ' + v))),
        h('p.hint', t('tg.editHint')),
        h(
          'div.dialog-actions',
          h('button.btn', { type: 'button', on: { click: () => sheet.close() } }, t('ai.view')),
          h('button.btn.primary', { type: 'button', on: { click: () => (sheet.close(), recordVideo(editor.animator, editor, app)) } }, h('span', { html: icon('record', 18) }), t('anim.record'))
        )
      );
    } catch (e) {
      console.error(e);
      result2.innerHTML = '';
      result2.append(h('div.ai-error', '⚠️ ' + (e.message || t('err.tool'))));
    } finally {
      app.busy(false);
    }
  });
  const textPane = h('div.ai-pane', h('p.hint', t('tg.intro')), ta, h('div.ai-chips', ...examples), understoodEl, genBtn, result2);
  const imagePane = h('div.ai-pane', h('p.hint', t('ai.intro')), preview, h('div.menu-label', t('ai.mode')), modeList, opts, startBtn, progress, result);
  const tabImg = h('button.panel-tab.active', { type: 'button' }, '🖼 ' + t('tg.fromImage'));
  const tabTxt = h('button.panel-tab', { type: 'button' }, '✍️ ' + t('tg.fromText'));
  const showPane = (txt) => {
    tabTxt.classList.toggle('active', txt);
    tabImg.classList.toggle('active', !txt);
    textPane.style.display = txt ? '' : 'none';
    imagePane.style.display = txt ? 'none' : '';
  };
  tabImg.addEventListener('click', () => showPane(false));
  tabTxt.addEventListener('click', () => showPane(true));
  showPane(false);
  const body = h('div.ai-studio', h('div.panel-tabs.ai-tabs', tabImg, tabTxt), imagePane, textPane);
  const sheet = openSheet('✨ ' + t('ai.title'), body, { wide: true });
  renderModes();
  renderOpts();
}

async function run(editor, app, st, keys, setProgress) {
  const E = editor;
  let root, box, maskInfo = null;
  const steps = [];
  if (st.mode === 'phone') {
    const r = await imageTo3D(st.canvas, { onProgress: setProgress, sensitivity: st.sensitivity, depthScale: st.depth });
    maskInfo = { mask: r.mask, gw: r.gw, gh: r.gh };
    // Rasmda yorug'lik allaqachon bor: rang asosan "o'zidan nur" (emissive) orqali,
    // qolgani — shakl ko'rinishi uchun yengil yoritish
    const mat = new THREE.MeshStandardMaterial({ map: r.texture, color: '#8c8c8c', roughness: 0.6, metalness: 0.0, envMapIntensity: 0.4 });
    mat.emissiveMap = r.texture;
    mat.emissive.set('#ffffff');
    mat.emissiveIntensity = 0.5;
    const mesh = new THREE.Mesh(r.geometry, mat);
    mesh.name = t('ai.modelName');
    mesh.userData = { prim: 'custom' };
    mesh.castShadow = mesh.receiveShadow = true;
    root = new THREE.Group();
    root.name = 'AI: ' + (st.file?.name || 'model').replace(/\.[^.]+$/, '');
    root.add(mesh);
    box = { W: r.W, H: r.H, depth: r.W * 0.15 };
    const tris = r.geometry.index.count / 3;
    steps.push(`🧠 ${t('ai.depthDone')}: ${r.gw}×${r.gh}, ${Math.round(tris).toLocaleString()} ${t('stats.tris')}`);
  } else {
    const prov = cloudProviders.find((p) => p.id === st.mode);
    const png = await canvasToPng(st.canvas);
    const out = await prov.run(png, { key: keys[prov.keyName], token: keys.hfToken, onProgress: setProgress });
    const file = new File([out.bytes], 'ai-model.' + out.ext);
    const imp = await importFile(file);
    root = imp.object;
    root.name = 'AI (' + prov.label + ')';
    // balandligini 3 birlikka keltiramiz
    root.updateMatrixWorld(true);
    const bb = new THREE.Box3().setFromObject(root);
    const s = 3 / Math.max(0.001, bb.max.y - bb.min.y);
    root.scale.multiplyScalar(s);
    root.updateMatrixWorld(true);
    bb.setFromObject(root);
    root.position.y -= bb.min.y;
    const size = bb.getSize(new THREE.Vector3());
    box = { W: Math.max(size.x, 1), H: 3, depth: size.z };
    steps.push(`☁️ ${prov.label}: ${t('ai.cloudDone')}`);
  }
  setProgress(0.9, 'director');
  E.animator.stop();
  E.clear();
  E.addObject(root, { select: false, commit: false });
  if (st.director) {
    const analysis = analyzeImage(st.canvas, maskInfo);
    const plan = makePlan(analysis);
    if (st.mode === 'phone') {
      const mesh = root.children[0];
      mesh.material.emissiveIntensity = 0.45 + plan.glow * 0.08;
      plan.scene.bloomThreshold = 1.05;
      plan.scene.bloomStrength = Math.min(plan.scene.bloomStrength, 0.6);
      mesh.material.needsUpdate = true;
    } else if (plan.motion === 'wave') {
      let verts = 0;
      root.traverse((o) => o.isMesh && (verts += o.geometry.attributes.position.count));
      if (verts > 150000) plan.motion = 'bob';
    }
    applyPlan(E, root, plan, box);
    steps.push(...plan.steps);
  } else {
    E.applySceneSettings();
  }
  E.syncLightProxies();
  E.emit('sceneGraphChanged');
  E.history.reset();
  E.camera.position.set(box.W * 0.35, box.H * 0.55, Math.max(box.W, box.H) * 1.55);
  E.orbit.target.set(0, box.H * 0.5, 0);
  E.orbit.update();
  app.projectId = null;
  app.projectName = root.name;
  app.updateTitle();
  app.markSaved();
  app.showTimeline(true);
  E.animator.play();
  return { steps };
}
