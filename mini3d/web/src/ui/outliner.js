// "Sahna" paneli: obyektlar daraxti (ierarxiya) va statistika.
import { h } from './dom.js';
import { icon } from '../icons.js';
import { t } from '../i18n.js';

function iconFor(o) {
  if (o.isLight) return { PointLight: 'bulb', SpotLight: 'spot', DirectionalLight: 'sun', HemisphereLight: 'hemi', AmbientLight: 'ambient' }[o.type] || 'bulb';
  if (o.userData.imported) return 'layers';
  if (o.isGroup) return 'group';
  const map = { box: 'cube', sphere: 'sphere', cylinder: 'cylinder', cone: 'cone', torus: 'torus', plane: 'plane', capsule: 'capsule', pyramid: 'pyramid', text: 'text', star: 'star', heart: 'heart', arch: 'arch', stairs: 'stairs', spring: 'spring', gear: 'gear', torusKnot: 'knot', ring: 'ring', circle: 'circle', tube: 'cylinder' };
  return map[o.userData.prim] || 'cube';
}

export function createOutliner(editor) {
  const list = h('div.tree');
  const stats = h('div.stats');
  const collapsed = new Set();
  const el = h('div.outliner', stats, list);

  const render = () => {
    list.innerHTML = '';
    const s = editor.stats();
    stats.innerHTML = '';
    stats.append(
      h('span', h('b', String(s.objects)), ' ' + t('stats.objects')),
      h('span', h('b', String(s.lights)), ' ' + t('stats.lights')),
      h('span', h('b', s.tris.toLocaleString()), ' ' + t('stats.tris'))
    );
    if (!editor.content.children.length) {
      list.append(h('div.tree-empty', t('outliner.empty')));
      return;
    }
    const walk = (node, depth) => {
      for (const o of node.children) {
        const isModel = !!o.userData.imported;
        const hasKids = o.children.length > 0 && !isModel;
        const open = !collapsed.has(o.uuid);
        const item = h(
          'div.tree-item' + (o === editor.selected ? '.selected' : '') + (o.visible ? '' : '.hidden'),
          { style: { paddingLeft: 8 + depth * 16 + 'px' } },
          h(
            'button.tree-twisty' + (hasKids ? '' : '.blank'),
            { type: 'button', html: hasKids ? icon(open ? 'chevronDown' : 'chevronRight', 16) : '', on: { click: (e) => (e.stopPropagation(), hasKids && (open ? collapsed.add(o.uuid) : collapsed.delete(o.uuid), render())) } }
          ),
          h('span.tree-ic', { html: icon(iconFor(o), 18) }),
          h('span.tree-name', o.name || o.type),
          o.userData.locked
            ? h('button.tree-act', { type: 'button', title: t('obj.locked'), html: icon('lock', 16), on: { click: (e) => (e.stopPropagation(), (o.userData.locked = false), editor.commit('lock'), render()) } })
            : null,
          h('button.tree-act', {
            type: 'button',
            title: t('obj.visible'),
            html: icon(o.visible ? 'eye' : 'eyeOff', 16),
            on: {
              click: (e) => {
                e.stopPropagation();
                o.visible = !o.visible;
                editor.commit('visible');
                editor.emit('sceneGraphChanged');
                editor.emit('objectChanged', o, 'visible');
              },
            },
          })
        );
        item.addEventListener('click', () => editor.select(o));
        list.append(item);
        if (hasKids && open) walk(o, depth + 1);
      }
    };
    walk(editor.content, 0);
  };

  let pending = false;
  const schedule = () => {
    if (pending) return;
    pending = true;
    requestAnimationFrame(() => {
      pending = false;
      render();
    });
  };
  editor.on('sceneGraphChanged', schedule);
  editor.on('selectionChanged', schedule);
  editor.on('projectLoaded', schedule);
  render();
  return { el, refresh: render };
}
