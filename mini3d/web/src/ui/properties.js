// "Xususiyatlar" paneli: tanlangan obyektning barcha sozlamalari.
import * as THREE from 'three';
import { h, section, row, numberInput, slider, toggle, colorInput, select, textInput, vec3Inputs, iconBtn, toast } from './dom.js';
import { primitives } from '../primitives.js';
import { materialTypes, materialPresets, palette, convertMaterial, applyPreset } from '../materials.js';
import { imageFileToTexture, pickFile } from '../io.js';
import { isDescendant } from '../editor.js';
import { icon } from '../icons.js';
import { t } from '../i18n.js';
import { effects, effectDefaults } from '../effects.js';
import { motions } from '../animation.js';
import { patterns, applyPattern } from '../textures.js';

const R2D = THREE.MathUtils.RAD2DEG;
const D2R = THREE.MathUtils.DEG2RAD;

export function createProperties(editor, ui) {
  const el = h('div.props');
  let live = null; // transformni jonli yangilash uchun
  let uniformScale = true;

  const rerender = () => {
    el.innerHTML = '';
    live = null;
    const obj = editor.selected;
    if (!obj) return el.append(emptyState());
    el.append(header(obj), transformSection(obj));
    if (obj.isMesh && obj.userData.prim && primitives.has(obj.userData.prim)) el.append(geometrySection(obj));
    if (obj.isMesh && obj.material) el.append(materialSection(obj));
    if (obj.isLight) el.append(lightSection(obj));
    if (obj.userData.effect && effects.has(obj.userData.effect.type)) el.append(effectSection(obj));
    if (obj.isMesh) el.append(sculptSection(obj));
    el.append(animSection(obj));
    el.append(objectSection(obj));
    if (!obj.isLight) el.append(infoSection(obj));
  };

  // ---------- Bo'sh holat ----------
  function emptyState() {
    return h(
      'div.empty',
      h('div.empty-ic', { html: icon('cube', 44) }),
      h('p', t('props.empty')),
      h('button.btn.primary', { type: 'button', on: { click: () => ui.openAddMenu() } }, h('span', { html: icon('plus', 18) }), t('add.title'))
    );
  }

  // ---------- Sarlavha (nom) ----------
  function header(obj) {
    const typeLabel = obj.userData.prim && primitives.has(obj.userData.prim) ? t(primitives.get(obj.userData.prim).label) : obj.isLight ? t('light.' + obj.type) : obj.userData.effect && effects.has(obj.userData.effect.type) ? t('props.effect') + ' · ' + t(effects.get(obj.userData.effect.type).label) : obj.userData.imported ? t('obj.model') : obj.isGroup ? t('obj.group') : t('obj.mesh');
    const name = textInput(obj.name, {
      onInput: (v) => {
        obj.name = v;
        editor.emit('sceneGraphChanged');
      },
      onChange: () => editor.commit('rename'),
    });
    return h('div.props-head', h('div.props-type', typeLabel), name);
  }

  // ---------- Transform ----------
  function transformSection(obj) {
    const pos = vec3Inputs(obj.position, {
      step: 0.1,
      onInput: (a, v) => {
        obj.position[a] = v;
        editor.requestRender();
      },
      onChange: () => editor.commit('transform'),
    });
    const rotDeg = { x: obj.rotation.x * R2D, y: obj.rotation.y * R2D, z: obj.rotation.z * R2D };
    const rot = vec3Inputs(rotDeg, {
      step: 5,
      decimals: 1,
      onInput: (a, v) => {
        obj.rotation[a] = v * D2R;
        editor.requestRender();
      },
      onChange: () => editor.commit('transform'),
    });
    const scl = vec3Inputs(obj.scale, {
      step: 0.1,
      onInput: (a, v) => {
        if (uniformScale && obj.scale[a] !== 0) {
          const k = v / obj.scale[a];
          obj.scale.multiplyScalar(k);
          scl.setValue(obj.scale);
        } else obj.scale[a] = v;
        editor.requestRender();
      },
      onChange: () => editor.commit('transform'),
    });
    const lockBtn = h('button.chip' + (uniformScale ? '.on' : ''), { type: 'button', title: t('props.uniform') }, t('props.uniform'));
    lockBtn.addEventListener('click', () => {
      uniformScale = !uniformScale;
      lockBtn.classList.toggle('on', uniformScale);
    });

    live = () => {
      pos.setValue(obj.position);
      rot.setValue({ x: obj.rotation.x * R2D, y: obj.rotation.y * R2D, z: obj.rotation.z * R2D });
      scl.setValue(obj.scale);
      dimsUpdate?.();
    };

    const items = [h('div.sub-label', t('props.position')), pos];
    if (!obj.isAmbientLight && !obj.isHemisphereLight) {
      if (!obj.isLight || obj.isSpotLight) items.push(h('div.sub-label', t('props.rotation')), rot);
      if (!obj.isLight) items.push(h('div.sub-label', t('props.scale'), lockBtn), scl);
    }
    return section(t('props.transform'), ...items);
  }

  // ---------- Geometriya parametrlari ----------
  function geometrySection(obj) {
    const def = primitives.get(obj.userData.prim);
    const params = obj.userData.params || {};
    const rows = def.params.map((p) => {
      let ctrl;
      if (p.type === 'bool') ctrl = toggle(params[p.key], (v) => editor.updatePrimitive(obj, { [p.key]: v }));
      else if (p.type === 'color')
        ctrl = colorInput(params[p.key], {
          onInput: (v) => editor.updatePrimitive(obj, { [p.key]: v }, false),
          onChange: (v) => editor.updatePrimitive(obj, { [p.key]: v }),
        });
      else if (p.type === 'text')
        ctrl = textInput(params[p.key], {
          onInput: (v) => editor.updatePrimitive(obj, { [p.key]: v }, false),
          onChange: (v) => editor.updatePrimitive(obj, { [p.key]: v }),
        });
      else
        ctrl = numberInput(params[p.key], {
          step: p.step,
          min: p.min,
          max: p.max,
          decimals: p.type === 'int' ? 0 : 3,
          onInput: (v) => editor.updatePrimitive(obj, { [p.key]: v }, false),
          onChange: (v) => editor.updatePrimitive(obj, { [p.key]: v }),
        });
      return row(t(p.label), ctrl);
    });
    return section(t('props.geometry') + ' · ' + t(def.label), ...rows);
  }

  // ---------- Material ----------
  function materialSection(obj) {
    const getMat = () => [].concat(obj.material)[0];
    const setMat = (m) => {
      if (Array.isArray(obj.material)) obj.material = obj.material.map(() => m);
      else obj.material = m;
    };
    const mat = getMat();
    const changed = (commit) => {
      getMat().needsUpdate = true;
      editor.requestRender();
      if (commit) editor.commit('material');
    };
    const items = [];

    items.push(
      row(
        t('mat.type'),
        select(
          materialTypes.map((m) => ({ value: m.id, label: t(m.label) })),
          mat.type,
          (v) => {
            const old = getMat();
            setMat(convertMaterial(old, v));
            old.dispose();
            changed(true);
            rerender();
          }
        )
      )
    );

    // Tayyor andozalar
    if ('metalness' in mat || 'color' in mat) {
      items.push(
        h(
          'div.presets',
          ...materialPresets.map((p) =>
            h(
              'button.preset',
              {
                type: 'button',
                title: t(p.label),
                on: {
                  click: () => {
                    applyPreset(getMat(), p);
                    changed(true);
                    rerender();
                  },
                },
              },
              h('span.preset-ball', { style: { background: presetBg(p) } }),
              h('span.preset-name', t(p.label))
            )
          )
        )
      );
    }

    if (mat.color) {
      const ci = colorInput('#' + mat.color.getHexString(), {
        onInput: (v) => {
          getMat().color.set(v);
          editor.requestRender();
        },
        onChange: () => changed(true),
      });
      const sw = h(
        'div.swatches',
        ...palette.map((c) =>
          h('button.swatch', {
            type: 'button',
            style: { background: c },
            title: c,
            on: {
              click: () => {
                getMat().color.set(c);
                ci.value = c;
                changed(true);
              },
            },
          })
        )
      );
      items.push(row(t('mat.color'), ci), sw);
    }
    if ('metalness' in mat) {
      items.push(row(t('mat.metalness'), slider(mat.metalness, { onInput: (v) => ((getMat().metalness = v), editor.requestRender()), onChange: () => changed(true) })));
      items.push(row(t('mat.roughness'), slider(mat.roughness, { onInput: (v) => ((getMat().roughness = v), editor.requestRender()), onChange: () => changed(true) })));
    }
    if (mat.isMeshPhysicalMaterial) {
      items.push(row(t('mat.clearcoat'), slider(mat.clearcoat, { onInput: (v) => ((getMat().clearcoat = v), editor.requestRender()), onChange: () => changed(true) })));
      items.push(row(t('mat.transmission'), slider(mat.transmission, { onInput: (v) => ((getMat().transmission = v), changed(false)), onChange: () => changed(true) })));
      items.push(row(t('mat.ior'), slider(mat.ior, { min: 1, max: 2.333, step: 0.01, onInput: (v) => ((getMat().ior = v), editor.requestRender()), onChange: () => changed(true) })));
      items.push(row(t('mat.sheen'), slider(mat.sheen, { onInput: (v) => ((getMat().sheen = v), changed(false)), onChange: () => changed(true) })));
    }
    items.push(
      row(
        t('mat.opacity'),
        slider(mat.opacity, {
          onInput: (v) => {
            const m = getMat();
            m.opacity = v;
            m.transparent = v < 1;
            m.depthWrite = v >= 1;
            changed(false);
          },
          onChange: () => changed(true),
        })
      )
    );
    if (mat.emissive) {
      items.push(
        row(
          t('mat.emissive'),
          colorInput('#' + mat.emissive.getHexString(), {
            onInput: (v) => {
              getMat().emissive.set(v);
              editor.requestRender();
            },
            onChange: () => changed(true),
          })
        )
      );
      items.push(row(t('mat.emissiveIntensity'), slider(mat.emissiveIntensity, { min: 0, max: 10, step: 0.1, onInput: (v) => ((getMat().emissiveIntensity = v), editor.requestRender()), onChange: () => changed(true) })));
    }
    if ('wireframe' in mat) items.push(row(t('mat.wireframe'), toggle(mat.wireframe, (v) => ((getMat().wireframe = v), changed(true)))));
    if ('flatShading' in mat) items.push(row(t('mat.flat'), toggle(mat.flatShading, (v) => ((getMat().flatShading = v), changed(true)))));
    items.push(row(t('mat.doubleSide'), toggle(mat.side === THREE.DoubleSide, (v) => ((getMat().side = v ? THREE.DoubleSide : THREE.FrontSide), changed(true)))));

    // Protsedural naqsh (tangacha, lava, pat)
    if ('map' in mat && !mat.isMeshNormalMaterial) {
      const cur = mat.map?.userData?.pattern || '';
      const glowOn = !!mat.emissiveMap?.userData?.pattern;
      let rep = mat.map?.userData?.pattern ? mat.map.repeat.x : 4;
      const apply = (id, glow) => {
        applyPattern(getMat(), id, { repeat: rep, glow: glow ? '#' + (getMat().emissive?.getHexString?.() !== '000000' ? getMat().emissive.getHexString() : 'ff5a10') : null });
        if (!glow && getMat().emissiveMap?.userData?.pattern) getMat().emissiveMap = null;
        changed(true);
        rerender();
      };
      items.push(
        h('div.sub-label', t('mat.pattern')),
        row(t('mat.pattern'), select([{ value: '', label: '— ' + t('anim.none') + ' —' }, ...Object.entries(patterns).map(([id, d]) => ({ value: id, label: t(d.label) }))], cur, (v) => apply(v || null, glowOn || v === 'lava')))
      );
      if (cur) {
        items.push(row(t('mat.patternGlow'), toggle(glowOn, (v) => apply(cur, v))));
        items.push(row(t('mat.repeat'), numberInput(rep, { step: 1, min: 0.5, max: 64, onChange: (v) => { rep = v; for (const k of ['map', 'emissiveMap', 'bumpMap']) getMat()[k]?.repeat.set(v, v); changed(true); } })));
      }
    }

    // Tekstura
    if ('map' in mat && !mat.isMeshNormalMaterial && !mat.map?.userData?.pattern) {
      const texRow = h('div.tex-row');
      const thumb = h('div.tex-thumb');
      if (mat.map?.image) {
        try {
          const img = mat.map.image;
          const c = document.createElement('canvas');
          c.width = c.height = 64;
          c.getContext('2d').drawImage(img, 0, 0, 64, 64);
          thumb.style.backgroundImage = `url(${c.toDataURL()})`;
        } catch {
          /* rasm hali yuklanmagan */
        }
      }
      texRow.append(
        thumb,
        h(
          'button.btn',
          {
            type: 'button',
            on: {
              click: async () => {
                const f = await pickFile('image/*');
                if (!f) return;
                try {
                  const tex = await imageFileToTexture(f);
                  const m = getMat();
                  m.map?.dispose();
                  m.map = tex;
                  if (m.color) m.color.set('#ffffff');
                  changed(true);
                  rerender();
                } catch {
                  toast(t('err.image'), 'error');
                }
              },
            },
          },
          h('span', { html: icon('image', 18) }),
          mat.map ? t('mat.changeTexture') : t('mat.addTexture')
        ),
        mat.map
          ? iconBtn('trash', t('mat.removeTexture'), () => {
              const m = getMat();
              m.map.dispose();
              m.map = null;
              changed(true);
              rerender();
            })
          : null
      );
      items.push(h('div.sub-label', t('mat.texture')), texRow);
      if (mat.map) {
        const rep = mat.map.repeat;
        items.push(
          row(
            t('mat.repeat'),
            numberInput(rep.x, { step: 0.5, min: 0.01, onInput: (v) => (rep.set(v, v), editor.requestRender()), onChange: () => changed(true) })
          )
        );
      }
    }
    return section(t('props.material'), ...items);
  }

  function presetBg(p) {
    const c = p.color;
    if (p.opacity) return `radial-gradient(circle at 35% 30%, #fff8, ${c}80 60%)`;
    if (p.metalness > 0.5) return `radial-gradient(circle at 35% 30%, #fff, ${c} 45%, #222 100%)`;
    return `radial-gradient(circle at 35% 30%, #fff6, ${c} 55%)`;
  }

  // ---------- Yorug'lik ----------
  function lightSection(L) {
    const ch = (commit) => {
      editor.syncLightProxies();
      editor.requestRender();
      if (commit) editor.commit('light');
    };
    const items = [
      row(t('mat.color'), colorInput('#' + L.color.getHexString(), { onInput: (v) => (L.color.set(v), ch(false)), onChange: () => ch(true) })),
    ];
    const maxI = L.isPointLight || L.isSpotLight ? 200 : 10;
    items.push(row(t('light.intensity'), slider(L.intensity, { min: 0, max: maxI, step: maxI / 200, onInput: (v) => ((L.intensity = v), editor.requestRender()), onChange: () => ch(true) })));
    if (L.isHemisphereLight) items.push(row(t('light.groundColor'), colorInput('#' + L.groundColor.getHexString(), { onInput: (v) => (L.groundColor.set(v), editor.requestRender()), onChange: () => ch(true) })));
    if (L.isPointLight || L.isSpotLight) {
      items.push(row(t('light.distance'), numberInput(L.distance, { step: 0.5, min: 0, onInput: (v) => ((L.distance = v), editor.requestRender()), onChange: () => ch(true) })));
      items.push(row(t('light.decay'), slider(L.decay, { min: 0, max: 3, step: 0.1, onInput: (v) => ((L.decay = v), editor.requestRender()), onChange: () => ch(true) })));
    }
    if (L.isSpotLight) {
      items.push(row(t('light.angle'), slider(L.angle * R2D, { min: 1, max: 89, step: 1, onInput: (v) => ((L.angle = v * D2R), editor.requestRender()), onChange: () => ch(true) })));
      items.push(row(t('light.penumbra'), slider(L.penumbra, { onInput: (v) => ((L.penumbra = v), editor.requestRender()), onChange: () => ch(true) })));
    }
    if (L.shadow) items.push(row(t('light.castShadow'), toggle(L.castShadow, (v) => ((L.castShadow = v), ch(true)))));
    return section(t('props.light'), ...items);
  }

  // ---------- Effekt parametrlari ----------
  function effectSection(obj) {
    const fx = obj.userData.effect;
    const def = effects.get(fx.type);
    fx.params = { ...effectDefaults(fx.type), ...(fx.params || {}) };
    const P = fx.params;
    const rows = def.params.map((p) => {
      let ctrl;
      if (p.type === 'color') {
        ctrl = colorInput(P[p.key], { onInput: (v) => (P[p.key] = v), onChange: () => editor.commit('effect') });
      } else {
        ctrl = slider(P[p.key], {
          min: p.min,
          max: p.max,
          step: p.step,
          onInput: (v) => (P[p.key] = v),
          onChange: () => editor.commit('effect'),
        });
      }
      return row(t(p.label), ctrl);
    });
    return section(t('props.effect') + ' · ' + t(def.label), ...rows);
  }

  // ---------- Sculpt ----------
  function sculptSection(obj) {
    const tris = Math.round(((obj.geometry.index ? obj.geometry.index.count : obj.geometry.attributes.position?.count || 0) / 3));
    return section(
      t('props.sculpt'),
      h('p.hint', t('sculpt.about')),
      h(
        'div.btn-row',
        h('button.btn.primary', { type: 'button', on: { click: () => ui.enterSculpt(obj) } }, h('span', { html: icon('brush', 18) }), t('sculpt.start')),
        h('button.btn', { type: 'button', on: { click: () => ui.subdivideDialog(obj) } }, h('span', { html: icon('subdivide', 18) }), t('tool.subdivide'))
      ),
      h('div.hint', tris.toLocaleString() + ' ' + t('stats.tris'))
    );
  }

  // ---------- Animatsiya ----------
  function animSection(obj) {
    const items = [];
    const m = obj.userData.motion;
    items.push(
      row(
        t('anim.motion'),
        select([{ value: '', label: '— ' + t('anim.none') + ' —' }, ...Object.entries(motions).map(([id, d]) => ({ value: id, label: t(d.label) }))], m?.type || '', (v) => {
          ui.animator.setMotion(obj, v || null);
          rerender();
        })
      )
    );
    if (m?.type) {
      const mm = obj.userData.motion;
      const unit = motions[mm.type].unit;
      items.push(row(t('anim.axis'), select(['x', 'y', 'z'].map((a) => ({ value: a, label: a.toUpperCase() })), mm.axis, (v) => ((mm.axis = v), editor.commit('motion')))));
      const maxAmt = unit === '°' ? 180 : unit === '×' ? 1 : unit === 'm' ? 3 : 1;
      if (mm.type !== 'spin') items.push(row(t('anim.amount') + (unit && unit !== '×' ? ` (${unit})` : ''), slider(mm.amount, { min: 0, max: maxAmt, step: maxAmt / 200, onInput: (v) => (mm.amount = v), onChange: () => editor.commit('motion') })));
      items.push(row(t('anim.speed'), slider(mm.speed, { min: 0, max: mm.type === 'shake' ? 20 : 4, step: 0.05, onInput: (v) => (mm.speed = v), onChange: () => editor.commit('motion') })));
      items.push(row(t('anim.phase'), slider(mm.phase || 0, { min: 0, max: 360, step: 5, onInput: (v) => (mm.phase = v), onChange: () => editor.commit('motion') })));
      items.push(h('p.hint', t('anim.motionHint')));
    }
    const nk = obj.userData.keys?.length || 0;
    items.push(
      h(
        'div.btn-row',
        h('button.btn', { type: 'button', on: { click: () => (ui.showTimeline(true), ui.animator.addKey(obj), rerender()) } }, h('span', { html: icon('keyAdd', 18) }), t('anim.addKey')),
        nk ? h('button.btn', { type: 'button', on: { click: () => (ui.animator.clearKeys(obj), rerender()) } }, h('span', { html: icon('trash', 18) }), t('anim.clearKeys') + ` (${nk})`) : null
      )
    );
    return section(t('props.anim'), ...items);
  }

  // ---------- Obyekt sozlamalari ----------
  function objectSection(obj) {
    const items = [
      row(t('obj.visible'), toggle(obj.visible, (v) => {
        obj.visible = v;
        editor.emit('sceneGraphChanged');
        editor.commit('visible');
      })),
      row(t('obj.locked'), toggle(!!obj.userData.locked, (v) => {
        obj.userData.locked = v;
        editor.select(null);
        editor.select(obj);
        editor.emit('sceneGraphChanged');
        editor.commit('lock');
      })),
    ];
    if (obj.isMesh || obj.isGroup) {
      const setShadow = (key, v) => {
        obj.traverse((o) => o.isMesh && (o[key] = v));
        editor.applySceneSettings();
        editor.commit('shadow');
      };
      items.push(row(t('obj.castShadow'), toggle(obj.castShadow ?? true, (v) => ((obj.castShadow = v), setShadow('castShadow', v)))));
      items.push(row(t('obj.receiveShadow'), toggle(obj.receiveShadow ?? true, (v) => ((obj.receiveShadow = v), setShadow('receiveShadow', v)))));
    }

    // Ota obyekt (ierarxiya)
    const options = [{ value: '', label: '— ' + t('obj.root') + ' —' }];
    const walk = (node, depth) => {
      for (const c of node.children) {
        if (c === obj || isDescendant(c, obj) || c.isLight) continue;
        if (c.parent && editor.selectableFor(c) !== c) continue; // import qilingan model ichi
        options.push({ value: c.uuid, label: '  '.repeat(depth) + (c.name || c.type) });
        walk(c, depth + 1);
      }
    };
    walk(editor.content, 0);
    items.push(
      row(
        t('obj.parent'),
        select(options, obj.parent === editor.content ? '' : obj.parent?.uuid || '', (v) => {
          const p = v ? editor.content.getObjectByProperty('uuid', v) : editor.content;
          if (!editor.setParent(obj, p)) toast(t('err.parent'), 'error');
          rerender();
        })
      )
    );
    return section(t('props.object'), ...items);
  }

  // ---------- Ma'lumot (o'lcham, uchburchaklar) ----------
  let dimsUpdate = null;
  function infoSection(obj) {
    const dims = h('div.info-val');
    const counts = h('div.info-val');
    dimsUpdate = () => {
      const box = new THREE.Box3().setFromObject(obj);
      if (box.isEmpty()) return (dims.textContent = '—');
      const s = box.getSize(new THREE.Vector3());
      dims.textContent = `${s.x.toFixed(2)} × ${s.y.toFixed(2)} × ${s.z.toFixed(2)}`;
    };
    dimsUpdate();
    let v = 0, tr = 0;
    obj.traverse((o) => {
      if (!o.isMesh) return;
      const pos = o.geometry.attributes.position;
      if (!pos) return;
      v += pos.count;
      tr += (o.geometry.index ? o.geometry.index.count : pos.count) / 3;
    });
    counts.textContent = `${v.toLocaleString()} / ${Math.round(tr).toLocaleString()}`;
    return section(t('props.info'), row(t('info.size'), dims), row(t('info.vertsTris'), counts));
  }

  editor.on('selectionChanged', rerender);
  editor.on('objectChanged', (obj, kind) => {
    if (obj !== editor.selected) return;
    if (kind === 'transform' && live) live();
    else if (kind === 'geometry') dimsUpdate?.();
    else if (kind !== 'transform') rerender();
  });
  editor.on('projectLoaded', rerender);
  rerender();
  return { el, refresh: rerender };
}
