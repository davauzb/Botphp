// ============================================================
//  SKELET SHABLONLARI — qo'lda personaj yasashni tezlashtiradi.
//  Har biri shar/kapsulalardan iborat guruh. Shakllarni siljitib,
//  kattalashtirib o'zingizga moslang, so'ng Vositalar → Organik tana.
// ============================================================
import * as THREE from 'three';
import { builder } from './samples.js';

const RED = '#a50c17', CREAM = '#ecd6b0', SKIN = '#e0b48e', DARK = '#3a2a24', GREEN = '#2f7d4f', GOLDY = '#d9a441';

function base(E, name) {
  const { add, group } = builder(E);
  const g = group(name);
  const ball = (pos, r, color, scale) => add('sphere', { radius: r, widthSegments: 16, heightSegments: 10 }, { parent: g, pos, scale, mat: { color } });
  const caps = (pos, r, len, rot, color) => add('capsule', { radius: r, length: len, capSegments: 4, radialSegments: 10 }, { parent: g, pos, rot, mat: { color } });
  const chain = (pts, r0, r1, color, n = 24) => {
    const curve = new THREE.CatmullRomCurve3(pts.map((p) => new THREE.Vector3(...p)));
    for (let k = 0; k <= n; k++) {
      const t = k / n;
      ball(curve.getPoint(t).toArray(), r0 + (r1 - r0) * t, color);
    }
    return curve;
  };
  return { g, ball, caps, chain };
}

export const templates = [
  {
    id: 'dragon', label: 'tpl.dragon', icon: 'wing',
    build(E) {
      const { g, ball, caps, chain } = base(E, 'Skelet: ajdar');
      ball([0.2, 1.75, 0], 1, RED, [1.3, 0.78, 0.76]);
      ball([-0.78, 1.7, 0], 1, CREAM, [0.6, 0.8, 0.52]);
      ball([0.1, 1.33, 0], 1, CREAM, [1.0, 0.42, 0.58]);
      ball([1.15, 1.62, 0], 1, RED, [0.72, 0.68, 0.68]);
      chain([[-1.0, 2.3, 0], [-1.25, 2.65, 0], [-1.45, 3.05, 0]], 0.46, 0.32, RED, 6);
      ball([-1.62, 3.32, 0], 0.36, RED, [1.15, 0.92, 0.9]);
      ball([-1.9, 3.22, 0], 0.22, RED, [1.25, 0.8, 0.85]);
      for (const [x, z] of [[-0.68, 0.55], [-0.68, -0.55], [1.12, 0.58], [1.12, -0.58]]) {
        const zs = Math.sign(z);
        caps([x, 1.12, z], 0.28, 0.5, [0.18 * zs, 0, x < 0 ? 0.15 : -0.2], RED);
        caps([x + (x < 0 ? -0.05 : 0.12), 0.55, z * 1.12], 0.17, 0.48, [0.12 * zs, 0, x < 0 ? -0.1 : 0.35], RED);
        caps([x - 0.1, 0.13, z * 1.18], 0.12, 0.3, [0, 0, Math.PI / 2], RED);
      }
      chain([[1.5, 1.6, 0], [2.3, 1.3, 0], [3.0, 1.4, 0], [3.4, 2.0, 0], [3.3, 2.7, 0]], 0.42, 0.1, RED, 26);
      return g;
    },
  },
  {
    id: 'loong', label: 'tpl.loong', icon: 'spring',
    build(E) {
      // Xitoy ajdari (loong) — uzun, to'lqinsimon tana: donghu uslubi
      const { g, ball, caps, chain } = base(E, 'Skelet: uzun ajdar');
      const pts = [];
      for (let k = 0; k <= 10; k++) {
        const t = k / 10;
        pts.push([-3 + t * 6.5, 1.6 + Math.sin(t * Math.PI * 2.2) * 0.8 + t * 0.3, Math.cos(t * Math.PI * 1.6) * 0.9]);
      }
      chain(pts, 0.38, 0.08, GREEN, 60);
      ball([-3.25, 1.75, 0.9], 0.42, GREEN, [1.25, 0.85, 0.9]);
      ball([-3.65, 1.65, 0.9], 0.25, GREEN, [1.4, 0.7, 0.85]);
      for (const [t, side] of [[0.15, 1], [0.15, -1], [0.55, 1], [0.55, -1]]) {
        const i = Math.round(t * 10);
        const p = pts[i];
        caps([p[0], p[1] - 0.45, p[2] + 0.3 * side], 0.11, 0.45, [0.4 * side, 0, 0.2], GREEN);
      }
      return g;
    },
  },
  {
    id: 'human', label: 'tpl.human', icon: 'capsule',
    build(E) {
      const { g, ball, caps } = base(E, 'Skelet: odam');
      ball([0, 3.35, 0], 0.26, SKIN, [0.9, 1.1, 1]);
      caps([0, 3.0, 0], 0.09, 0.15, [0, 0, 0], SKIN);
      ball([0, 2.55, 0], 0.42, SKIN, [1.0, 0.85, 0.6]);
      ball([0, 2.15, 0], 0.32, SKIN, [0.95, 0.9, 0.6]);
      ball([0, 1.82, 0], 0.36, SKIN, [1.05, 0.7, 0.65]);
      for (const s of [-1, 1]) {
        ball([0.42 * s, 2.72, 0], 0.15, SKIN);
        caps([0.55 * s, 2.4, 0], 0.11, 0.42, [0, 0, 0.15 * s], SKIN);
        caps([0.65 * s, 1.92, 0.05], 0.09, 0.42, [-0.2, 0, 0.08 * s], SKIN);
        ball([0.7 * s, 1.6, 0.1], 0.1, SKIN, [0.8, 1.2, 0.6]);
        caps([0.18 * s, 1.35, 0], 0.16, 0.55, [0, 0, 0.03 * s], SKIN);
        caps([0.2 * s, 0.62, 0], 0.12, 0.55, [0, 0, 0], SKIN);
        caps([0.2 * s, 0.08, 0.1], 0.08, 0.2, [Math.PI / 2, 0, 0], DARK);
      }
      return g;
    },
  },
  {
    id: 'bird', label: 'tpl.bird', icon: 'wing',
    build(E) {
      const { g, ball, caps, chain } = base(E, 'Skelet: qush');
      ball([0, 1.4, 0], 0.55, RED, [1.25, 0.9, 0.85]);
      ball([-0.35, 1.35, 0], 0.4, CREAM, [0.8, 0.9, 0.75]);
      chain([[-0.45, 1.7, 0], [-0.6, 2.0, 0], [-0.65, 2.25, 0]], 0.25, 0.2, RED, 5);
      ball([-0.72, 2.4, 0], 0.24, RED);
      for (const s of [-1, 1]) {
        caps([0.05, 0.75, 0.22 * s], 0.06, 0.55, [0, 0, 0.1], GOLDY);
        caps([-0.05, 0.42, 0.22 * s], 0.035, 0.12, [0, 0, Math.PI / 2], GOLDY);
      }
      chain([[0.6, 1.45, 0], [1.1, 1.35, 0], [1.5, 1.2, 0]], 0.25, 0.08, RED, 8);
      return g;
    },
  },
];

export function addTemplate(editor, id) {
  const tpl = templates.find((x) => x.id === id);
  const g = tpl.build(editor);
  editor.syncLightProxies();
  editor.emit('sceneGraphChanged');
  editor.commit('template');
  editor.select(g);
  editor.focus(g);
  return g;
}
