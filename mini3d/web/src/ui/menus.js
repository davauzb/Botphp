// Menyular va dialoglar: Qo'shish, Fayl, Vositalar, Ko'rinishlar, Loyihalar.
import * as THREE from 'three';
import { h, openSheet, gridButtons, listButton, toast, confirmDialog, promptDialog, row, numberInput, select, toggle } from './dom.js';
import { primitives } from '../primitives.js';
import { exportFormats, exportModel, saveFile, importFile, importAccept, pickFile, dataURLToArrayBuffer, isAndroid } from '../io.js';
import { booleanOp, arrayOp, mirrorOp, dropToGround, centerOrigin, resetTransform, mergeIntoMesh, bakeGeometry, smoothNormals, tools } from '../tools.js';
import { projects } from '../storage.js';
import { icon } from '../icons.js';
import { t } from '../i18n.js';

export function createMenus(editor, app) {
  // =============== QO'SHISH ===============
  function openAddMenu() {
    const add = (fn) => () => {
      s.close();
      fn();
    };
    const byCat = (cat) =>
      [...primitives.values()]
        .filter((p) => p.category === cat)
        .map((p) => ({ icon: p.icon, label: t(p.label), onClick: add(() => editor.addObject(editor.createPrimitive(p.id))) }));
    const body = h(
      'div',
      h('div.menu-label', t('add.basic')),
      gridButtons(byCat('basic')),
      h('div.menu-label', t('add.extra')),
      gridButtons(byCat('extra')),
      h('div.menu-label', t('add.shapes')),
      gridButtons(byCat('shape')),
      h('div.menu-label', t('add.lights')),
      gridButtons(
        [
          ['PointLight', 'bulb'],
          ['SpotLight', 'spot'],
          ['DirectionalLight', 'sun'],
          ['HemisphereLight', 'hemi'],
          ['AmbientLight', 'ambient'],
        ].map(([type, ic]) => ({ icon: ic, label: t('light.' + type), onClick: add(() => editor.addObject(editor.createLight(type))) }))
      ),
      h('div.menu-label', t('add.other')),
      gridButtons([
        { icon: 'group', label: t('obj.group'), onClick: add(() => editor.addObject(editor.createGroup())) },
        { icon: 'upload', label: t('file.import'), onClick: add(() => importDialog()) },
      ])
    );
    const s = openSheet(t('add.title'), body, { wide: true });
  }

  // =============== FAYL ===============
  function openFileMenu() {
    const go = (fn) => () => {
      s.close();
      fn();
    };
    const body = h(
      'div.list',
      listButton('file', t('file.new'), t('file.newSub'), go(newProject)),
      listButton('save', t('file.save'), app.projectName ? app.projectName : t('file.saveSub'), go(() => saveProject(false))),
      listButton('save', t('file.saveAs'), null, go(() => saveProject(true))),
      listButton('folder', t('file.projects'), t('file.projectsSub'), go(openProjects)),
      h('div.menu-sep'),
      listButton('upload', t('file.import'), 'GLB, glTF, OBJ, STL, PLY, .m3d', go(importDialog)),
      listButton('download', t('file.export'), 'GLB, glTF, OBJ, STL, PLY', go(exportDialog)),
      listButton('camera', t('file.screenshot'), t('file.screenshotSub'), go(screenshot)),
      listButton('share', t('file.exportProject'), t('file.exportProjectSub'), go(exportProject)),
      h('div.menu-sep'),
      listButton('help', t('file.help'), null, go(openHelp)),
      listButton('info', t('file.about'), null, go(openAbout))
    );
    const s = openSheet(t('file.title'), body);
  }

  async function newProject() {
    if (editor.content.children.length && !(await confirmDialog(t('file.newConfirm'), { danger: true }))) return;
    editor.clear();
    app.projectId = null;
    app.projectName = '';
    app.updateTitle();
    toast(t('file.newDone'));
  }

  function makeThumb() {
    try {
      const url = editor.screenshot(1);
      const img = new Image();
      return new Promise((res) => {
        img.onload = () => {
          const c = document.createElement('canvas');
          const k = 160 / Math.max(img.width, img.height);
          c.width = Math.round(img.width * k);
          c.height = Math.round(img.height * k);
          c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
          res(c.toDataURL('image/jpeg', 0.75));
        };
        img.onerror = () => res(null);
        img.src = url;
      });
    } catch {
      return Promise.resolve(null);
    }
  }

  async function saveProject(asNew) {
    let name = app.projectName;
    if (asNew || !app.projectId) {
      name = await promptDialog(t('file.projectName'), name || t('file.untitled'));
      if (!name) return;
    }
    const id = asNew || !app.projectId ? 'p' + Date.now().toString(36) : app.projectId;
    try {
      await projects.save({ id, name, thumb: await makeThumb(), data: editor.serializeProject() });
      app.projectId = id;
      app.projectName = name;
      app.updateTitle();
      app.markSaved();
      toast(t('file.saved') + ': ' + name, 'ok');
    } catch (e) {
      console.error(e);
      toast(t('err.save'), 'error');
    }
  }

  async function openProjects() {
    const listEl = h('div.projects', h('div.loading', t('loading')));
    const s = openSheet(t('file.projects'), listEl, { wide: true });
    const items = await projects.list();
    listEl.innerHTML = '';
    if (!items.length) {
      listEl.append(h('div.tree-empty', t('file.noProjects')));
      return;
    }
    for (const p of items) {
      const card = h(
        'div.project-card',
        h('button.project-open', { type: 'button', on: { click: () => openProject(p.id, s) } }, h('div.project-thumb', p.thumb ? h('img', { src: p.thumb, alt: '' }) : h('span', { html: icon('cube', 36) })), h('div.project-name', p.name), h('div.project-date', new Date(p.updated).toLocaleString())),
        h('button.icon-btn.project-del', {
          type: 'button',
          title: t('delete'),
          html: icon('trash', 18),
          on: {
            click: async () => {
              if (!(await confirmDialog(t('file.deleteConfirm') + ' "' + p.name + '"', { danger: true, ok: t('delete') }))) return;
              await projects.remove(p.id);
              card.remove();
              if (app.projectId === p.id) {
                app.projectId = null;
                app.updateTitle();
              }
            },
          },
        })
      );
      listEl.append(card);
    }
  }

  async function openProject(id, sheet) {
    try {
      const p = await projects.get(id);
      await editor.loadProject(p.data);
      app.projectId = p.id;
      app.projectName = p.name;
      app.updateTitle();
      app.markSaved();
      sheet?.close();
      toast(t('file.opened') + ': ' + p.name);
    } catch (e) {
      console.error(e);
      toast(t('err.open'), 'error');
    }
  }

  async function importDialog() {
    const f = await pickFile(importAccept);
    if (!f) return;
    app.busy(true, t('loading'));
    try {
      const res = await importFile(f);
      if (res.kind === 'project') {
        await editor.loadProject(res.data);
        app.projectId = null;
        app.projectName = f.name.replace(/\.(m3d\.)?json$|\.m3d$/i, '');
        app.updateTitle();
      } else {
        editor.addObject(res.object, { label: 'import' });
        editor.focus(res.object);
      }
      toast(t('file.imported') + ': ' + f.name, 'ok');
    } catch (e) {
      console.error(e);
      toast(t('err.import'), 'error');
    } finally {
      app.busy(false);
    }
  }

  function exportDialog() {
    let onlySelected = false;
    const fmtBtns = exportFormats.map((f) => ({
      icon: 'download',
      label: f.label,
      title: t(f.desc),
      onClick: async () => {
        s.close();
        app.busy(true, t('file.exporting'));
        try {
          const data = await exportModel(editor, f.id, { onlySelected });
          const base = (onlySelected && editor.selected?.name) || app.projectName || 'mini3d';
          const where = await saveFile(`${safeName(base)}.${f.ext}`, data, f.mime);
          toast(t('file.savedTo') + ' ' + where, 'ok');
        } catch (e) {
          console.error(e);
          toast(t('err.export'), 'error');
        } finally {
          app.busy(false);
        }
      },
    }));
    const body = h(
      'div',
      editor.selected ? row(t('export.onlySelected'), toggle(false, (v) => (onlySelected = v))) : null,
      gridButtons(fmtBtns),
      h('div.hint', ...exportFormats.map((f) => h('div', h('b', f.label + ': '), t(f.desc))))
    );
    const s = openSheet(t('file.export'), body);
  }

  async function exportProject() {
    try {
      const json = JSON.stringify(editor.serializeProject());
      const where = await saveFile(`${safeName(app.projectName || 'mini3d')}.m3d.json`, json, 'application/json', { share: isAndroid() });
      toast(t('file.savedTo') + ' ' + where, 'ok');
    } catch (e) {
      console.error(e);
      toast(t('err.export'), 'error');
    }
  }

  async function screenshot() {
    try {
      const url = editor.screenshot(2);
      const where = await saveFile(`${safeName(app.projectName || 'mini3d')}-${Date.now()}.png`, dataURLToArrayBuffer(url), 'image/png');
      toast(t('file.savedTo') + ' ' + where, 'ok');
    } catch (e) {
      console.error(e);
      toast(t('err.export'), 'error');
    }
  }

  // =============== VOSITALAR ===============
  function openToolsMenu() {
    const obj = editor.selected;
    const go = (fn) => () => {
      s.close();
      if (!editor.selected) return toast(t('err.selectFirst'), 'error');
      fn(editor.selected);
    };
    const isMesh = obj?.isMesh;
    const items = [
      { icon: 'boolean', label: t('tool.boolean'), onClick: go(booleanDialog), disabled: !isMesh },
      { icon: 'array', label: t('tool.array'), onClick: go(arrayDialog) },
      { icon: 'mirror', label: t('tool.mirror'), onClick: go(mirrorDialog) },
      { icon: 'ground', label: t('tool.ground'), onClick: go((o) => dropToGround(editor, o)) },
      { icon: 'center', label: t('tool.center'), onClick: go((o) => centerOrigin(editor, o)) },
      { icon: 'reset', label: t('tool.reset'), onClick: go((o) => resetTransform(editor, o)) },
      { icon: 'merge', label: t('tool.merge'), onClick: go((o) => safe(() => mergeIntoMesh(editor, o))), disabled: !(obj?.isGroup || obj?.children?.some((c) => c.isMesh)) },
      { icon: 'smooth', label: t('tool.smooth'), onClick: go((o) => safe(() => smoothNormals(editor, o, true))), disabled: !isMesh },
      { icon: 'cube', label: t('tool.flat'), onClick: go((o) => safe(() => smoothNormals(editor, o, false))), disabled: !isMesh },
      { icon: 'bake', label: t('tool.bake'), onClick: go((o) => bakeGeometry(editor, o)), disabled: !(isMesh && obj.userData.params) },
      { icon: 'up', label: t('tool.up'), onClick: go((o) => editor.moveInOrder(o, -1)) },
      { icon: 'down', label: t('tool.down'), onClick: go((o) => editor.moveInOrder(o, 1)) },
      // Plaginlar orqali qo'shilgan vositalar
      ...tools.map((tool) => ({ icon: tool.icon || 'wand', label: tool.label, onClick: go((o) => tool.run(editor, o)) })),
    ].filter((x) => !x.disabled);
    const body = h('div', obj ? h('div.menu-label', t('tool.for') + ': ' + obj.name) : h('div.hint', t('err.selectFirst')), obj ? gridButtons(items) : null);
    const s = openSheet(t('tool.title'), body, { wide: true });
  }

  function safe(fn) {
    try {
      fn();
    } catch (e) {
      console.error(e);
      toast(t('err.tool'), 'error');
    }
  }

  function meshOptions(exclude) {
    const out = [];
    editor.content.traverse((o) => {
      if (o.isMesh && o !== exclude && editor.selectableFor(o) === o) out.push({ value: o.uuid, label: o.name || o.type });
    });
    return out;
  }

  function booleanDialog(a) {
    const opts = meshOptions(a);
    if (!opts.length) return toast(t('bool.needTwo'), 'error');
    let op = 'subtract', bId = opts[opts.length - 1].value, keep = false;
    const body = h(
      'div.dialog',
      h('p.hint', t('bool.hint')),
      row(t('bool.operation'), select(['union', 'subtract', 'intersect'].map((v) => ({ value: v, label: t('bool.' + v) })), op, (v) => (op = v))),
      row(t('bool.with'), select(opts, bId, (v) => (bId = v))),
      row(t('bool.keep'), toggle(false, (v) => (keep = v))),
      h(
        'div.dialog-actions',
        h('button.btn', { type: 'button', on: { click: () => s.close() } }, t('cancel')),
        h(
          'button.btn.primary',
          {
            type: 'button',
            on: {
              click: () => {
                const b = editor.content.getObjectByProperty('uuid', bId);
                s.close();
                app.busy(true, t('bool.working'));
                setTimeout(() => {
                  try {
                    booleanOp(editor, a, b, op, keep);
                    toast(t('done'), 'ok');
                  } catch (e) {
                    console.error(e);
                    toast(t('err.boolean'), 'error');
                  } finally {
                    app.busy(false);
                  }
                }, 30);
              },
            },
          },
          t('apply')
        )
      )
    );
    const s = openSheet(t('tool.boolean'), body);
  }

  function arrayDialog(obj) {
    const st = { count: 5, mode: 'line', dx: 1.2, dy: 0, dz: 0, radius: 2, axis: 'y' };
    const lineRows = h('div', row('X', numberInput(st.dx, { onInput: (v) => (st.dx = v) })), row('Y', numberInput(st.dy, { onInput: (v) => (st.dy = v) })), row('Z', numberInput(st.dz, { onInput: (v) => (st.dz = v) })));
    const circRows = h('div', { style: { display: 'none' } }, row(t('array.radius'), numberInput(st.radius, { min: 0.01, onInput: (v) => (st.radius = v) })), row(t('array.axis'), select(['x', 'y', 'z'].map((v) => ({ value: v, label: v.toUpperCase() })), 'y', (v) => (st.axis = v))));
    const body = h(
      'div.dialog',
      row(t('array.count'), numberInput(st.count, { step: 1, min: 2, max: 200, decimals: 0, onInput: (v) => (st.count = Math.round(v)) })),
      row(
        t('array.mode'),
        select([{ value: 'line', label: t('array.line') }, { value: 'circle', label: t('array.circle') }], 'line', (v) => {
          st.mode = v;
          lineRows.style.display = v === 'line' ? '' : 'none';
          circRows.style.display = v === 'circle' ? '' : 'none';
        })
      ),
      lineRows,
      circRows,
      h(
        'div.dialog-actions',
        h('button.btn', { type: 'button', on: { click: () => s.close() } }, t('cancel')),
        h(
          'button.btn.primary',
          {
            type: 'button',
            on: {
              click: () => {
                s.close();
                const n = Math.max(2, Math.min(200, st.count | 0));
                arrayOp(editor, obj, { count: n, mode: st.mode, offset: new THREE.Vector3(st.dx, st.dy, st.dz), radius: st.radius, axis: st.axis });
                toast(t('done'), 'ok');
              },
            },
          },
          t('apply')
        )
      )
    );
    const s = openSheet(t('tool.array'), body);
  }

  function mirrorDialog(obj) {
    let copy = true;
    const go = (axis) => () => {
      s.close();
      mirrorOp(editor, obj, axis, copy);
    };
    const body = h(
      'div.dialog',
      row(t('mirror.copy'), toggle(true, (v) => (copy = v))),
      gridButtons([
        { icon: 'mirror', label: t('mirror.x'), onClick: go('x') },
        { icon: 'mirror', label: t('mirror.y'), onClick: go('y') },
        { icon: 'mirror', label: t('mirror.z'), onClick: go('z') },
      ])
    );
    const s = openSheet(t('tool.mirror'), body);
  }

  // =============== KO'RINISHLAR ===============
  function openViewsMenu() {
    const v = (name) => () => {
      s.close();
      editor.setView(name);
    };
    const body = h(
      'div',
      gridButtons([
        { icon: 'cube', label: t('view.persp'), onClick: v('persp') },
        { icon: 'up', label: t('view.top'), onClick: v('top') },
        { icon: 'down', label: t('view.bottom'), onClick: v('bottom') },
        { icon: 'focus', label: t('view.front'), onClick: v('front') },
        { icon: 'focus', label: t('view.back'), onClick: v('back') },
        { icon: 'chevronRight', label: t('view.right'), onClick: v('right') },
        { icon: 'undo', label: t('view.left'), onClick: v('left') },
        { icon: 'home', label: t('view.all'), onClick: () => (s.close(), editor.focus(null)) },
      ]),
      h('div.menu-sep'),
      row(t('view.autorotate'), toggle(editor.orbit.autoRotate, (on) => editor.setAutoRotate(on)))
    );
    const s = openSheet(t('view.title'), body);
  }

  // =============== YORDAM ===============
  function openHelp() {
    const items = ['help.1', 'help.2', 'help.3', 'help.4', 'help.5', 'help.6', 'help.7', 'help.8'];
    openSheet(t('file.help'), h('div.help', ...items.map((k) => h('p', { html: t(k) }))));
  }

  function openAbout() {
    openSheet(
      t('file.about'),
      h(
        'div.help',
        h('div.about-logo', { html: icon('cube', 56) }),
        h('h3', 'Mini 3D Muharrir'),
        h('p', t('about.text')),
        h('p.hint', 'three.js r170 · three-bvh-csg · v' + (window.AndroidBridge?.version?.() || '1.0'))
      )
    );
  }

  return { openAddMenu, openFileMenu, openToolsMenu, openViewsMenu, saveProject, importDialog, screenshot, openProject };
}

function safeName(s) {
  return String(s).replace(/[\\/:*?"<>|]+/g, '_').trim().slice(0, 60) || 'mini3d';
}
