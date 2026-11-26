// Ilovaning asosiy tuzilishi: yuqori panel, vositalar, ko'rish oynasi, yon/pastki panel.
import { h, iconBtn, toast, closeTopSheet } from './dom.js';
import { createProperties } from './properties.js';
import { createOutliner } from './outliner.js';
import { createSettings } from './settings.js';
import { createMenus } from './menus.js';
import { saveSettings, projects } from '../storage.js';
import { dropToGround } from '../tools.js';
import { Sculptor } from '../sculpt.js';
import { EffectSystem } from '../effects.js';
import { Animator } from '../animation.js';
import { createSculptBar } from './sculptbar.js';
import { createTimeline } from './timeline.js';
import { openAIStudio } from './aistudio.js';
import { icon } from '../icons.js';
import { t } from '../i18n.js';

export function createApp(root, editorFactory, settings) {
  // ---------- Tuzilma ----------
  const viewport = h('div#viewport');
  const title = h('div.title', 'Mini 3D');
  const undoBtn = iconBtn('undo', t('undo'), () => undo());
  const redoBtn = iconBtn('redo', t('redo'), () => redo());
  const topbar = h('header.topbar');
  const toolstrip = h('div.toolstrip');
  const selbar = h('div.selbar');
  const fab = h('button.fab', { type: 'button', title: t('add.title'), html: icon('plus', 28) });
  const panelTabs = h('div.panel-tabs');
  const panelBody = h('div.panel-body');
  const panel = h('aside.panel', h('div.panel-handle', h('div.grabber'), panelTabs), panelBody);
  const busyEl = h('div.busy', h('div.spinner'), h('div.busy-text'));
  const vpWrap = h('div.viewport-wrap', viewport, toolstrip, selbar, fab);
  const workspace = h('main.workspace', vpWrap, panel);
  root.append(topbar, workspace, busyEl);

  const editor = editorFactory(viewport);
  const sculptor = (editor.sculptor = new Sculptor(editor));
  const effectSystem = (editor.effects = new EffectSystem(editor));
  const animator = (editor.animator = new Animator(editor));
  const app = {
    animator,
    sculptor,
    effectSystem,
    editor,
    projectId: null,
    projectName: '',
    dirty: false,
    updateTitle() {
      title.textContent = (app.projectName || t('file.untitled')) + (app.dirty ? ' •' : '');
    },
    markSaved() {
      app.dirty = false;
      app.updateTitle();
    },
    busy(on, text = '') {
      busyEl.classList.toggle('on', on);
      busyEl.querySelector('.busy-text').textContent = text;
    },
    openAddMenu: () => menus.openAddMenu(),
    setPanel,
    enterSculpt,
    showTimeline,
    subdivideDialog: (o) => menus.subdivideDialog(o),
  };
  const menus = createMenus(editor, app);

  let animBtn;
  topbar.append(
    iconBtn('menu', t('file.title'), () => menus.openFileMenu()),
    title,
    h('div.spacer'),
    undoBtn,
    redoBtn,
    iconBtn('magic', t('ai.title'), () => openAIStudio(editor, app), 'ai-btn'),
    (animBtn = iconBtn('film', t('anim.title'), () => showTimeline(!timelineOn))),
    iconBtn('views', t('view.title'), () => menus.openViewsMenu()),
    iconBtn('wand', t('tool.title'), () => menus.openToolsMenu())
  );

  // ---------- Chap vositalar paneli ----------
  const modes = [
    ['select', 'select', t('mode.select')],
    ['translate', 'move', t('mode.move')],
    ['rotate', 'rotate', t('mode.rotate')],
    ['scale', 'scale', t('mode.scale')],
  ];
  const modeBtns = modes.map(([mode, ic, label]) => {
    const b = iconBtn(ic, label, () => editor.setMode(mode), 'tool');
    b.dataset.mode = mode;
    return b;
  });
  const snapBtn = iconBtn('magnet', t('mode.snap'), () => {
    settings.snap = !settings.snap;
    saveSettings(settings);
    editor.applySnap();
    syncTools();
    toast(settings.snap ? t('mode.snapOn') : t('mode.snapOff'));
  }, 'tool');
  const spaceBtn = iconBtn('globe', t('mode.space'), () => {
    settings.space = settings.space === 'world' ? 'local' : 'world';
    saveSettings(settings);
    editor.applySnap();
    syncTools();
    toast(settings.space === 'world' ? t('mode.world') : t('mode.local'));
  }, 'tool');
  const sculptBtn = iconBtn('brush', t('sculpt.title'), () => (sculptor.active ? sculptor.exit() : enterSculpt(editor.selected)), 'tool');
  toolstrip.append(...modeBtns, sculptBtn, h('div.ts-sep'), snapBtn, spaceBtn);

  function syncTools() {
    modeBtns.forEach((b) => b.classList.toggle('active', b.dataset.mode === editor.mode));
    sculptBtn.classList.toggle('active', editor.mode === 'sculpt');
    snapBtn.classList.toggle('active', !!settings.snap);
    spaceBtn.innerHTML = icon(settings.space === 'world' ? 'globe' : 'local');
    spaceBtn.classList.toggle('active', settings.space === 'local');
  }
  editor.on('modeChanged', (mode) => {
    if (mode !== 'sculpt' && sculptor.active) sculptor.exit();
    syncTools();
  });

  // ---------- Sculpt ----------
  function enterSculpt(obj) {
    if (animator.playing) animator.pause();
    if (!obj || !obj.isMesh) {
      // tanlanmagan bo'lsa — yangi "loy shar" yaratamiz
      if (obj && !obj.isMesh) return toast(t('sculpt.needMesh'), 'error');
      obj = editor.createPrimitive('clayBall');
      obj.material.color.set('#c9a27e');
      obj.material.roughness = 0.6;
      editor.addObject(obj, { label: 'add' });
      editor.focus(obj);
    }
    sculptor.enter(obj);
    setPanel(activeTab, 'min');
  }
  editor.on('selectionChanged', (obj) => {
    if (sculptor.active && obj !== sculptor.mesh && !editor.history.busy) sculptor.exit();
  });
  const sculptBar = createSculptBar(editor, sculptor, settings, () => saveSettings(settings));

  // ---------- Animatsiya vaqt chizig'i ----------
  const timeline = createTimeline(editor, animator, app);
  let timelineOn = false;
  function showTimeline(on) {
    timelineOn = on;
    timeline.el.classList.toggle('show', on);
    workspace.classList.toggle('timeline-on', on);
    animBtn?.classList.toggle('active', on);
    if (!on && animator.playing) animator.pause();
    timeline.syncMarks();
    timeline.syncTime();
  }
  syncTools();

  // ---------- Tanlangan obyekt uchun tezkor amallar ----------
  selbar.append(
    iconBtn('sliders', t('panel.props'), () => setPanel('props', 'mid')),
    iconBtn('copy', t('duplicate'), () => editor.selected && editor.duplicate(editor.selected)),
    iconBtn('focus', t('focus'), () => editor.focus()),
    iconBtn('ground', t('tool.ground'), () => editor.selected && dropToGround(editor, editor.selected)),
    iconBtn('wand', t('tool.title'), () => menus.openToolsMenu()),
    iconBtn('trash', t('delete'), () => editor.selected && editor.removeObject(editor.selected), 'danger')
  );
  editor.on('selectionChanged', (obj) => selbar.classList.toggle('show', !!obj));
  fab.addEventListener('click', () => menus.openAddMenu());

  vpWrap.append(sculptBar.el, timeline.el);

  // ---------- Panel (xususiyatlar / sahna / sozlamalar) ----------
  const props = createProperties(editor, app);
  const outliner = createOutliner(editor);
  const settingsPanel = createSettings(editor, app);
  const tabs = [
    { id: 'props', icon: 'sliders', label: t('panel.props'), el: props.el },
    { id: 'scene', icon: 'layers', label: t('panel.scene'), el: outliner.el },
    { id: 'settings', icon: 'settings', label: t('panel.settings'), el: settingsPanel.el },
  ];
  let activeTab = 'props';
  let panelState = 'min'; // min | mid | max (faqat telefon portret rejimida)
  const tabBtns = tabs.map((tb) => {
    const b = h('button.panel-tab', { type: 'button' }, h('span', { html: icon(tb.icon, 18) }), h('span', tb.label));
    b.addEventListener('click', () => {
      if (activeTab === tb.id && panelState !== 'min') setPanel(tb.id, 'min');
      else setPanel(tb.id, panelState === 'min' ? 'mid' : panelState);
    });
    return b;
  });
  const expandBtn = iconBtn('chevronUp', t('panel.expand'), () => setPanel(activeTab, panelState === 'max' ? 'mid' : 'max'), 'panel-expand');
  panelTabs.append(...tabBtns, expandBtn);
  panel.querySelector('.grabber').addEventListener('click', () => setPanel(activeTab, panelState === 'min' ? 'mid' : 'min'));

  function setPanel(tabId, state = panelState) {
    activeTab = tabId;
    panelState = state;
    panelBody.innerHTML = '';
    panelBody.append(tabs.find((x) => x.id === tabId).el);
    tabBtns.forEach((b, k) => b.classList.toggle('active', tabs[k].id === tabId));
    panel.dataset.state = state;
    workspace.dataset.panel = state;
    editor.resize();
    expandBtn.innerHTML = icon(state === 'max' ? 'chevronDown' : 'chevronUp');
  }
  setPanel('props', 'min');

  // ---------- Undo / Redo ----------
  // Undo/redo paytida sculpt rejimi saqlanib qoladi
  async function keepSculpt(fn) {
    if (animator.playing) animator.pause();
    const id = sculptor.active ? sculptor.mesh.uuid : null;
    const r = await fn();
    if (id) {
      const m = editor.content.getObjectByProperty('uuid', id);
      if (m && m.isMesh) sculptor.enter(m);
    }
    return r;
  }
  async function undo() {
    if (await keepSculpt(() => editor.history.undo())) toast(t('undone'));
  }
  async function redo() {
    if (await keepSculpt(() => editor.history.redo())) toast(t('redone'));
  }
  const syncHistory = () => {
    undoBtn.disabled = !editor.history.canUndo;
    redoBtn.disabled = !editor.history.canRedo;
  };
  editor.on('historyChanged', syncHistory);
  syncHistory();

  // ---------- Avtosaqlash ----------
  let autosaveTimer = null;
  editor.on('changed', () => {
    app.dirty = true;
    app.updateTitle();
    if (!settings.autosave) return;
    clearTimeout(autosaveTimer);
    autosaveTimer = setTimeout(autosave, 1500);
  });
  async function autosave() {
    try {
      await projects.save({ id: '__autosave', name: app.projectName, projectId: app.projectId, data: editor.serializeProject({ compact: true }) });
    } catch (e) {
      console.warn('autosave failed', e);
    }
  }
  app.restoreAutosave = async () => {
    try {
      const a = await projects.get('__autosave');
      if (a?.data) {
        await editor.loadProject(a.data);
        app.projectId = a.projectId || null;
        app.projectName = a.name || '';
        return true;
      }
    } catch (e) {
      console.warn('autosave restore failed', e);
    }
    return false;
  };
  document.addEventListener('visibilitychange', () => {
    if (document.hidden && settings.autosave && app.dirty) autosave();
  });

  // ---------- Klaviatura ----------
  window.addEventListener('keydown', (e) => {
    if (/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName)) return;
    const k = e.key.toLowerCase();
    const ctrl = e.ctrlKey || e.metaKey;
    if (ctrl && k === 'z') (e.preventDefault(), e.shiftKey ? redo() : undo());
    else if (ctrl && k === 'y') (e.preventDefault(), redo());
    else if (ctrl && k === 'd') (e.preventDefault(), editor.selected && editor.duplicate(editor.selected));
    else if (ctrl && k === 's') (e.preventDefault(), menus.saveProject(false));
    else if (k === 'q') editor.setMode('select');
    else if (k === 'w' || k === 'g') editor.setMode('translate');
    else if (k === 'e') editor.setMode('rotate');
    else if (k === 'r') editor.setMode('scale');
    else if (k === 'f') editor.focus();
    else if (k === 'delete' || k === 'backspace') editor.selected && editor.removeObject(editor.selected);
    else if (k === 'escape') sculptor.active ? sculptor.exit() : editor.select(null);
    else if (k === ' ' && timelineOn) (e.preventDefault(), animator.toggle());
    else if (k === 'b') sculptor.active ? sculptor.exit() : enterSculpt(editor.selected);
    else if (k === 'k' && editor.selected) animator.addKey(editor.selected);
    else if (k === 'a' && e.shiftKey) menus.openAddMenu();
  });

  // ---------- Android "Orqaga" tugmasi ----------
  window.__onBack = () => {
    if (closeTopSheet()) return true;
    if (animator.recording) return true;
    if (sculptor.active) {
      sculptor.exit();
      return true;
    }
    if (animator.playing) {
      animator.pause();
      return true;
    }
    if (panelState !== 'min' && window.matchMedia('(max-width: 819px)').matches) {
      setPanel(activeTab, 'min');
      return true;
    }
    if (editor.selected) {
      editor.select(null);
      return true;
    }
    return false;
  };

  app.updateTitle();
  return app;
}
