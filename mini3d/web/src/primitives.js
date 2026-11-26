// ============================================================
//  SHAKLLAR (PRIMITIVLAR) REESTRI
//  Yangi shakl qo'shish uchun registerPrimitive({...}) chaqiring.
//  Parametrlar ro'yxati asosida "Xususiyatlar" panelidagi
//  boshqaruv elementlari avtomatik yaratiladi.
// ============================================================
import * as THREE from 'three';
import { mergeGeometries, mergeVertices } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import { TextGeometry } from 'three/examples/jsm/geometries/TextGeometry.js';
import { FontLoader } from 'three/examples/jsm/loaders/FontLoader.js';
import fontData from 'three/examples/fonts/helvetiker_regular.typeface.json';

const DEG = Math.PI / 180;
let _font = null;
const font = () => (_font ||= new FontLoader().parse(fontData));

/** @type {Map<string, PrimitiveDef>} */
export const primitives = new Map();

/**
 * @typedef {Object} ParamDef
 * @property {string} key
 * @property {string} label    - i18n kaliti yoki matn
 * @property {'number'|'int'|'bool'|'text'} type
 * @property {number} [min] @property {number} [max] @property {number} [step]
 * @property {*} default
 *
 * @typedef {Object} PrimitiveDef
 * @property {string} id
 * @property {string} label    - i18n kaliti
 * @property {string} icon     - icons.js dagi belgi nomi
 * @property {string} category - 'basic' | 'extra' | 'shape'
 * @property {ParamDef[]} params
 * @property {(p:Object)=>THREE.BufferGeometry} build
 * @property {boolean} [lift]  - qo'shilganda yerdan ko'tarilsinmi (default: true)
 */
export function registerPrimitive(def) {
  primitives.set(def.id, { lift: true, category: 'basic', ...def });
}

export function defaultParams(id) {
  const def = primitives.get(id);
  const out = {};
  for (const p of def.params) out[p.key] = p.default;
  return out;
}

/** Shakl geometriyasini parametrlar bo'yicha qurib beradi. */
export function buildGeometry(id, params) {
  const def = primitives.get(id);
  if (!def) throw new Error('Unknown primitive: ' + id);
  const p = { ...defaultParams(id), ...params };
  for (const pd of def.params) {
    if (pd.type === 'number' || pd.type === 'int') {
      let v = Number(p[pd.key]);
      if (!Number.isFinite(v)) v = pd.default;
      if (pd.min != null) v = Math.max(pd.min, v);
      if (pd.max != null) v = Math.min(pd.max, v);
      if (pd.type === 'int') v = Math.round(v);
      p[pd.key] = v;
    }
  }
  const g = def.build(p);
  g.computeBoundingBox();
  g.computeBoundingSphere();
  return g;
}

/**
 * Maxsus geometriyalarni oddiy BufferGeometry ga aylantiradi,
 * shunda saqlash/yuklash (JSON) har doim ishlaydi.
 */
export function toPlain(geo) {
  const g = new THREE.BufferGeometry();
  for (const name of Object.keys(geo.attributes)) g.setAttribute(name, geo.attributes[name].clone());
  if (geo.index) g.setIndex(geo.index.clone());
  for (const grp of geo.groups) g.addGroup(grp.start, grp.count, grp.materialIndex);
  geo.dispose();
  return g;
}

const n = (key, label, def, min, max, step = 0.1) => ({ key, label, type: 'number', default: def, min, max, step });
const i = (key, label, def, min, max) => ({ key, label, type: 'int', default: def, min, max, step: 1 });
const b = (key, label, def) => ({ key, label, type: 'bool', default: def });
const s = (key, label, def) => ({ key, label, type: 'text', default: def });

// ---------- Asosiy shakllar ----------
registerPrimitive({
  id: 'box', label: 'prim.box', icon: 'cube',
  params: [n('width', 'p.width', 1, 0.01, 100), n('height', 'p.height', 1, 0.01, 100), n('depth', 'p.depth', 1, 0.01, 100), i('segments', 'p.segments', 1, 1, 64)],
  build: (p) => new THREE.BoxGeometry(p.width, p.height, p.depth, p.segments, p.segments, p.segments),
});
registerPrimitive({
  id: 'sphere', label: 'prim.sphere', icon: 'sphere',
  params: [n('radius', 'p.radius', 0.6, 0.01, 100), i('widthSegments', 'p.wseg', 32, 3, 128), i('heightSegments', 'p.hseg', 20, 2, 128)],
  build: (p) => new THREE.SphereGeometry(p.radius, p.widthSegments, p.heightSegments),
});
registerPrimitive({
  id: 'cylinder', label: 'prim.cylinder', icon: 'cylinder',
  params: [n('radiusTop', 'p.rtop', 0.5, 0, 100), n('radiusBottom', 'p.rbottom', 0.5, 0, 100), n('height', 'p.height', 1, 0.01, 100), i('radialSegments', 'p.rseg', 32, 3, 128), b('openEnded', 'p.open', false)],
  build: (p) => new THREE.CylinderGeometry(p.radiusTop, p.radiusBottom, p.height, p.radialSegments, 1, p.openEnded),
});
registerPrimitive({
  id: 'cone', label: 'prim.cone', icon: 'cone',
  params: [n('radius', 'p.radius', 0.5, 0.01, 100), n('height', 'p.height', 1, 0.01, 100), i('radialSegments', 'p.rseg', 32, 3, 128)],
  build: (p) => new THREE.ConeGeometry(p.radius, p.height, p.radialSegments),
});
registerPrimitive({
  id: 'torus', label: 'prim.torus', icon: 'torus',
  params: [n('radius', 'p.radius', 0.5, 0.01, 100), n('tube', 'p.tube', 0.2, 0.005, 50), i('radialSegments', 'p.rseg', 16, 3, 64), i('tubularSegments', 'p.tseg', 48, 3, 256), n('arc', 'p.arc', 360, 1, 360, 5)],
  build: (p) => new THREE.TorusGeometry(p.radius, p.tube, p.radialSegments, p.tubularSegments, p.arc * DEG),
});
registerPrimitive({
  id: 'plane', label: 'prim.plane', icon: 'plane', lift: false,
  params: [n('width', 'p.width', 2, 0.01, 1000), n('height', 'p.length', 2, 0.01, 1000), i('segments', 'p.segments', 1, 1, 128)],
  build: (p) => new THREE.PlaneGeometry(p.width, p.height, p.segments, p.segments).rotateX(-Math.PI / 2),
});
registerPrimitive({
  id: 'capsule', label: 'prim.capsule', icon: 'capsule',
  params: [n('radius', 'p.radius', 0.35, 0.01, 100), n('length', 'p.length', 0.8, 0, 100), i('capSegments', 'p.capseg', 8, 1, 32), i('radialSegments', 'p.rseg', 24, 3, 64)],
  build: (p) => new THREE.CapsuleGeometry(p.radius, p.length, p.capSegments, p.radialSegments),
});
registerPrimitive({
  id: 'pyramid', label: 'prim.pyramid', icon: 'pyramid',
  params: [n('radius', 'p.base', 0.7, 0.01, 100), n('height', 'p.height', 1, 0.01, 100), i('sides', 'p.sides', 4, 3, 16)],
  build: (p) => {
    const g = new THREE.ConeGeometry(p.radius, p.height, p.sides);
    if (p.sides === 4) g.rotateY(Math.PI / 4);
    return toPlain(g);
  },
});

// ---------- Sculpt (haykaltaroshlik) uchun asoslar ----------
const sculptBase = (geo) => {
  geo.deleteAttribute('uv');
  geo.deleteAttribute('normal');
  const g = mergeVertices(geo, 1e-5);
  g.computeVertexNormals();
  return g;
};
registerPrimitive({
  id: 'clayBall', label: 'prim.clayBall', icon: 'sphere', category: 'sculpt',
  params: [n('radius', 'p.radius', 0.7, 0.05, 100), i('detail', 'p.detail', 28, 4, 60)],
  build: (p) => sculptBase(new THREE.IcosahedronGeometry(p.radius, p.detail)),
});
registerPrimitive({
  id: 'clayBlock', label: 'prim.clayBlock', icon: 'cube', category: 'sculpt',
  params: [n('width', 'p.width', 1, 0.05, 100), n('height', 'p.height', 1, 0.05, 100), n('depth', 'p.depth', 1, 0.05, 100), i('segments', 'p.segments', 24, 2, 64)],
  build: (p) => sculptBase(new THREE.BoxGeometry(p.width, p.height, p.depth, p.segments, p.segments, p.segments)),
});
registerPrimitive({
  id: 'clayCapsule', label: 'prim.clayCapsule', icon: 'capsule', category: 'sculpt',
  params: [n('radius', 'p.radius', 0.4, 0.05, 100), n('length', 'p.length', 1, 0, 100), i('detail', 'p.detail', 24, 4, 64)],
  build: (p) => sculptBase(new THREE.CapsuleGeometry(p.radius, p.length, Math.max(4, p.detail >> 1), p.detail * 2)),
});

// ---------- Qo'shimcha shakllar ----------
registerPrimitive({
  id: 'icosahedron', label: 'prim.ico', icon: 'ico', category: 'extra',
  params: [n('radius', 'p.radius', 0.6, 0.01, 100), i('detail', 'p.detail', 0, 0, 6)],
  build: (p) => new THREE.IcosahedronGeometry(p.radius, p.detail),
});
registerPrimitive({
  id: 'dodecahedron', label: 'prim.dodeca', icon: 'ico', category: 'extra',
  params: [n('radius', 'p.radius', 0.6, 0.01, 100), i('detail', 'p.detail', 0, 0, 6)],
  build: (p) => new THREE.DodecahedronGeometry(p.radius, p.detail),
});
registerPrimitive({
  id: 'octahedron', label: 'prim.octa', icon: 'diamond', category: 'extra',
  params: [n('radius', 'p.radius', 0.6, 0.01, 100), i('detail', 'p.detail', 0, 0, 6)],
  build: (p) => new THREE.OctahedronGeometry(p.radius, p.detail),
});
registerPrimitive({
  id: 'tetrahedron', label: 'prim.tetra', icon: 'pyramid', category: 'extra',
  params: [n('radius', 'p.radius', 0.6, 0.01, 100), i('detail', 'p.detail', 0, 0, 6)],
  build: (p) => new THREE.TetrahedronGeometry(p.radius, p.detail),
});
registerPrimitive({
  id: 'torusKnot', label: 'prim.knot', icon: 'knot', category: 'extra',
  params: [n('radius', 'p.radius', 0.45, 0.01, 100), n('tube', 'p.tube', 0.14, 0.005, 50), i('tubularSegments', 'p.tseg', 128, 8, 512), i('radialSegments', 'p.rseg', 16, 3, 64), i('p', 'p.p', 2, 1, 20), i('q', 'p.q', 3, 1, 20)],
  build: (p) => new THREE.TorusKnotGeometry(p.radius, p.tube, p.tubularSegments, p.radialSegments, p.p, p.q),
});
registerPrimitive({
  id: 'circle', label: 'prim.circle', icon: 'circle', category: 'extra', lift: false,
  params: [n('radius', 'p.radius', 1, 0.01, 1000), i('segments', 'p.segments', 48, 3, 256)],
  build: (p) => new THREE.CircleGeometry(p.radius, p.segments).rotateX(-Math.PI / 2),
});
registerPrimitive({
  id: 'ring', label: 'prim.ring', icon: 'ring', category: 'extra', lift: false,
  params: [n('innerRadius', 'p.rin', 0.5, 0, 1000), n('outerRadius', 'p.rout', 1, 0.01, 1000), i('segments', 'p.segments', 48, 3, 256)],
  build: (p) => new THREE.RingGeometry(Math.min(p.innerRadius, p.outerRadius - 0.001), p.outerRadius, p.segments).rotateX(-Math.PI / 2),
});
registerPrimitive({
  id: 'tube', label: 'prim.tube', icon: 'cylinder', category: 'extra',
  params: [n('outer', 'p.rout', 0.5, 0.02, 100), n('inner', 'p.rin', 0.38, 0.01, 100), n('height', 'p.height', 1, 0.01, 100), i('segments', 'p.rseg', 48, 3, 128)],
  build: (p) => {
    const inner = Math.min(p.inner, p.outer - 0.005);
    const h = p.height / 2;
    const pts = [new THREE.Vector2(inner, -h), new THREE.Vector2(p.outer, -h), new THREE.Vector2(p.outer, h), new THREE.Vector2(inner, h), new THREE.Vector2(inner, -h)];
    const g = new THREE.LatheGeometry(pts, p.segments);
    g.deleteAttribute('normal');
    const m = mergeVertices(g, 1e-5);
    // Tekis yuzalar uchun "toNonIndexed" + normal qayta hisoblash
    const flat = m.toNonIndexed();
    flat.computeVertexNormals();
    return toPlain(flat);
  },
});

// ---------- Maxsus shakllar (Extrude asosida) ----------
function extrude(shape, depth, bevel) {
  const g = new THREE.ExtrudeGeometry(shape, {
    depth,
    bevelEnabled: bevel > 0,
    bevelThickness: bevel,
    bevelSize: bevel,
    bevelSegments: 3,
    curveSegments: 24,
  });
  g.center();
  return toPlain(g);
}

registerPrimitive({
  id: 'star', label: 'prim.star', icon: 'star', category: 'shape',
  params: [i('points', 'p.points', 5, 3, 32), n('outer', 'p.rout', 0.6, 0.02, 100), n('inner', 'p.rin', 0.28, 0.01, 100), n('depth', 'p.depth', 0.2, 0.01, 100), n('bevel', 'p.bevel', 0.02, 0, 1, 0.01)],
  build: (p) => {
    const sh = new THREE.Shape();
    for (let k = 0; k < p.points * 2; k++) {
      const r = k % 2 ? p.inner : p.outer;
      const a = (k / (p.points * 2)) * Math.PI * 2 + Math.PI / 2;
      const x = Math.cos(a) * r, y = Math.sin(a) * r;
      k ? sh.lineTo(x, y) : sh.moveTo(x, y);
    }
    sh.closePath();
    return extrude(sh, p.depth, p.bevel);
  },
});
registerPrimitive({
  id: 'heart', label: 'prim.heart', icon: 'heart', category: 'shape',
  params: [n('size', 'p.size', 1, 0.05, 100), n('depth', 'p.depth', 0.25, 0.01, 100), n('bevel', 'p.bevel', 0.04, 0, 1, 0.01)],
  build: (p) => {
    const s = p.size / 2;
    const sh = new THREE.Shape();
    sh.moveTo(0, -s * 0.9);
    sh.bezierCurveTo(-s * 0.2, -s * 0.6, -s, -s * 0.2, -s, s * 0.3);
    sh.bezierCurveTo(-s, s * 0.8, -s * 0.4, s, 0, s * 0.55);
    sh.bezierCurveTo(s * 0.4, s, s, s * 0.8, s, s * 0.3);
    sh.bezierCurveTo(s, -s * 0.2, s * 0.2, -s * 0.6, 0, -s * 0.9);
    return extrude(sh, p.depth, p.bevel);
  },
});
registerPrimitive({
  id: 'arch', label: 'prim.arch', icon: 'arch', category: 'shape',
  params: [n('width', 'p.width', 1.6, 0.1, 100), n('height', 'p.height', 1.6, 0.1, 100), n('thickness', 'p.thick', 0.25, 0.02, 50), n('depth', 'p.depth', 0.4, 0.01, 100)],
  build: (p) => {
    const w = p.width / 2, t = Math.min(p.thickness, w - 0.01);
    const r = w, ri = w - t;
    const legH = Math.max(0.01, p.height - r);
    const sh = new THREE.Shape();
    sh.moveTo(-w, 0);
    sh.lineTo(-w, legH);
    sh.absarc(0, legH, r, Math.PI, 0, true);
    sh.lineTo(w, 0);
    sh.lineTo(ri, 0);
    sh.lineTo(ri, legH);
    sh.absarc(0, legH, ri, 0, Math.PI, false);
    sh.lineTo(-ri, 0);
    sh.closePath();
    return extrude(sh, p.depth, 0);
  },
});
registerPrimitive({
  id: 'stairs', label: 'prim.stairs', icon: 'stairs', category: 'shape',
  params: [i('steps', 'p.steps', 6, 1, 100), n('width', 'p.width', 1.2, 0.05, 100), n('stepHeight', 'p.stepH', 0.18, 0.01, 10, 0.01), n('stepDepth', 'p.stepD', 0.28, 0.01, 10, 0.01)],
  build: (p) => {
    const parts = [];
    for (let k = 0; k < p.steps; k++) {
      const h = p.stepHeight * (k + 1);
      const g = new THREE.BoxGeometry(p.width, h, p.stepDepth);
      g.translate(0, h / 2, k * p.stepDepth);
      parts.push(g.toNonIndexed());
      g.dispose();
    }
    const m = mergeGeometries(parts);
    parts.forEach((g) => g.dispose());
    m.center();
    return toPlain(m);
  },
});
registerPrimitive({
  id: 'spring', label: 'prim.spring', icon: 'spring', category: 'shape',
  params: [n('radius', 'p.radius', 0.45, 0.02, 100), n('tube', 'p.tube', 0.06, 0.005, 10, 0.01), n('turns', 'p.turns', 5, 0.5, 100, 0.5), n('height', 'p.height', 1.2, 0.01, 100), i('segments', 'p.tseg', 200, 16, 2000)],
  build: (p) => {
    const pts = [];
    const N = p.segments;
    for (let k = 0; k <= N; k++) {
      const tt = k / N;
      const a = tt * p.turns * Math.PI * 2;
      pts.push(new THREE.Vector3(Math.cos(a) * p.radius, tt * p.height - p.height / 2, Math.sin(a) * p.radius));
    }
    const curve = new THREE.CatmullRomCurve3(pts);
    return toPlain(new THREE.TubeGeometry(curve, N, p.tube, 10, false));
  },
});
registerPrimitive({
  id: 'gear', label: 'prim.gear', icon: 'gear', category: 'shape',
  params: [i('teeth', 'p.teeth', 12, 4, 100), n('radius', 'p.radius', 0.6, 0.05, 100), n('toothDepth', 'p.toothD', 0.12, 0.01, 10, 0.01), n('hole', 'p.hole', 0.15, 0, 50, 0.01), n('depth', 'p.depth', 0.2, 0.01, 100)],
  build: (p) => {
    const sh = new THREE.Shape();
    const N = p.teeth;
    const r1 = p.radius - p.toothDepth, r2 = p.radius;
    for (let k = 0; k < N; k++) {
      const a0 = (k / N) * Math.PI * 2;
      const st = (Math.PI * 2) / N;
      const pts = [
        [r1, a0], [r2, a0 + st * 0.15], [r2, a0 + st * 0.45], [r1, a0 + st * 0.6],
      ];
      pts.forEach(([r, a], j) => {
        const x = Math.cos(a) * r, y = Math.sin(a) * r;
        k === 0 && j === 0 ? sh.moveTo(x, y) : sh.lineTo(x, y);
      });
    }
    sh.closePath();
    if (p.hole > 0 && p.hole < r1 - 0.01) {
      const h = new THREE.Path();
      h.absarc(0, 0, p.hole, 0, Math.PI * 2, true);
      sh.holes.push(h);
    }
    return extrude(sh, p.depth, 0);
  },
});
registerPrimitive({
  id: 'text', label: 'prim.text', icon: 'text', category: 'shape',
  params: [s('text', 'p.text', 'Salom'), n('size', 'p.size', 0.5, 0.02, 50, 0.05), n('depth', 'p.depth', 0.15, 0.005, 50, 0.01), n('bevel', 'p.bevel', 0.01, 0, 0.5, 0.005), i('curveSegments', 'p.quality', 6, 1, 24)],
  build: (p) => {
    // helvetiker shriftida o'zbekcha ' va ʻ belgilari uchun oddiy apostrofdan foydalanamiz
    const txt = String(p.text || ' ').replace(/[ʻʼ‘’`]/g, "'");
    const g = new TextGeometry(txt, {
      font: font(),
      size: p.size,
      depth: p.depth,
      curveSegments: p.curveSegments,
      bevelEnabled: p.bevel > 0,
      bevelThickness: p.bevel,
      bevelSize: p.bevel * 0.7,
      bevelSegments: 2,
    });
    g.center();
    return toPlain(g);
  },
});
