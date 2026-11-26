// ============================================================
//  ORGANIK TANA (Metaball / SDF birlashtirish)
//  Guruhdagi oddiy shakllarni (shar, kapsula, silindr, kub ...) bitta
//  uzluksiz, silliq "teri"ga aylantiradi — professional dasturlardagi
//  "ZSpheres / voxel merge" g'oyasi. Natijani keyin sculpt qilish mumkin.
//
//  - Har bir shakl o'zining masofa funksiyasi (SDF) bilan ifodalanadi
//  - Silliq birlashma (smooth-min) — bo'g'imlar tabiiy qo'shiladi
//  - Marching cubes bilan yuza quriladi
//  - Ranglar: har bir nuqta eng yaqin shakl rangini oladi (aralashib)
//  - Avtomatik UV (tekstura uchun) — uch tomonlama proyeksiya
// ============================================================
import * as THREE from 'three';
import { MarchingCubes } from 'three/examples/jsm/objects/MarchingCubes.js';
import { mergeVertices } from 'three/examples/jsm/utils/BufferGeometryUtils.js';

const _p = new THREE.Vector3();

// ---------- Masofa funksiyalari (lokal koordinatada) ----------
function sdSphere(p, r) {
  return p.length() - r;
}
function sdCapsuleY(p, r, h) {
  const y = Math.max(-h, Math.min(h, p.y));
  return Math.hypot(p.x, p.y - y, p.z) - r;
}
function sdRoundCone(p, r1, r2, h) {
  // Y o'qi bo'ylab: pastda r1 (y=-h), tepada r2 (y=+h)
  const q = Math.hypot(p.x, p.z);
  const y = p.y + h;
  const H = 2 * h;
  const b = (r1 - r2) / H;
  const a = Math.sqrt(Math.max(0, 1 - b * b));
  const k = -q * b + y * a; // proyeksiya
  if (k < 0) return Math.hypot(q, y) - r1;
  if (k > a * H) return Math.hypot(q, y - H) - r2;
  return q * a + y * b - r1;
}
function sdBox(p, bx, by, bz, r) {
  const qx = Math.abs(p.x) - bx + r, qy = Math.abs(p.y) - by + r, qz = Math.abs(p.z) - bz + r;
  const ox = Math.max(qx, 0), oy = Math.max(qy, 0), oz = Math.max(qz, 0);
  return Math.hypot(ox, oy, oz) + Math.min(Math.max(qx, qy, qz), 0) - r;
}
function smin(a, b, k) {
  if (k <= 0) return Math.min(a, b);
  const h = Math.max(k - Math.abs(a - b), 0) / k;
  return Math.min(a, b) - h * h * k * 0.25;
}

/** Meshdan SDF "primitiv"ini yasaydi (root lokal koordinatasiga nisbatan). */
function makePrim(mesh, rootInv) {
  const m = new THREE.Matrix4().multiplyMatrices(rootInv, mesh.matrixWorld);
  const inv = m.clone().invert();
  const s = new THREE.Vector3().setFromMatrixScale(m);
  const minS = Math.min(Math.abs(s.x), Math.abs(s.y), Math.abs(s.z)) || 1;
  const P = mesh.userData.params || {};
  const id = mesh.userData.prim;
  const g = mesh.geometry;
  if (!g.boundingBox) g.computeBoundingBox();
  const bb = g.boundingBox;
  let fn;
  if (id === 'sphere' || id === 'clayBall' || id === 'icosahedron' || id === 'dodecahedron') {
    const r = P.radius ?? (bb.max.x - bb.min.x) / 2;
    fn = (p) => sdSphere(p, r);
  } else if (id === 'capsule' || id === 'clayCapsule') {
    const r = P.radius ?? 0.35, h = (P.length ?? 0.8) / 2;
    fn = (p) => sdCapsuleY(p, r, h);
  } else if (id === 'cylinder') {
    const h = (P.height ?? 1) / 2;
    fn = (p) => sdRoundCone(p, P.radiusBottom ?? 0.5, Math.max(P.radiusTop ?? 0.5, 0.001), h);
  } else if (id === 'cone') {
    const h = (P.height ?? 1) / 2;
    fn = (p) => sdRoundCone(p, P.radius ?? 0.5, 0.01, h);
  } else if (id === 'box' || id === 'clayBlock') {
    const bx = (P.width ?? 1) / 2, by = (P.height ?? 1) / 2, bz = (P.depth ?? 1) / 2;
    const r = Math.min(bx, by, bz) * 0.3;
    fn = (p) => sdBox(p, bx, by, bz, r);
  } else {
    // boshqa shakllar: chegaraviy ellipsoid
    const c = bb.getCenter(new THREE.Vector3());
    const e = bb.getSize(new THREE.Vector3()).multiplyScalar(0.5);
    const r = Math.min(e.x, e.y, e.z) || 0.1;
    fn = (p) => {
      _p.set((p.x - c.x) / (e.x || 1), (p.y - c.y) / (e.y || 1), (p.z - c.z) / (e.z || 1));
      return (_p.length() - 1) * r;
    };
  }
  // dunyo (root) bo'yicha chegaraviy quti — tezlashtirish uchun
  const wb = bb.clone().applyMatrix4(m);
  const mat = [].concat(mesh.material)[0];
  return {
    inv,
    scale: minS,
    fn,
    box: wb,
    color: mat?.color ? mat.color.clone() : new THREE.Color(1, 1, 1),
    blend: mesh.userData.blend, // shaxsiy silliqlik (ixtiyoriy)
  };
}

/**
 * Guruhni organik tanaga aylantiradi.
 * @param root  THREE.Group (yoki bitta mesh) — ichidagi barcha meshlar
 * @param opts  { resolution=96, blend=0.15, smoothIter=2, uv=true, colors=true }
 * @returns THREE.Mesh (root bilan bir xil transformda)
 */
export function organicMesh(root, opts = {}) {
  const res = Math.max(32, Math.min(160, opts.resolution || 96));
  const blend = opts.blend ?? 0.15;
  root.updateWorldMatrix(true, true);
  const rootInv = root.matrixWorld.clone().invert();
  const prims = [];
  root.traverse((o) => {
    if (o.isMesh && o.visible && o.geometry?.attributes.position) prims.push(makePrim(o, rootInv));
  });
  if (!prims.length) throw new Error('empty');

  // Umumiy chegara (kub — to'r bir xil bo'lishi uchun)
  const bounds = new THREE.Box3();
  prims.forEach((p) => bounds.union(p.box));
  bounds.expandByScalar(blend * 1.5 + 0.02);
  const center = bounds.getCenter(new THREE.Vector3());
  const size = bounds.getSize(new THREE.Vector3());
  const half = Math.max(size.x, size.y, size.z) / 2;
  const pad = 2 / res; // chetdagi bo'sh qatlam
  const ext = half * (1 + pad * 2);

  const mc = new MarchingCubes(res, new THREE.MeshBasicMaterial(), false, false, Math.min(1500000, res * res * 60));
  mc.isolation = 0;
  const field = mc.field;
  const N = res;
  const cell = (2 * ext) / N; // to'r qadami (dunyo birligida)
  const lp = new THREE.Vector3();
  const wp = new THREE.Vector3();
  const margin = blend + cell * 2;
  // Har bir primitiv uchun kengaytirilgan quti
  for (const p of prims) p.boxM = p.box.clone().expandByScalar(margin);

  for (let z = 0; z < N; z++) {
    const wz = center.z + ((z - N / 2) / (N / 2)) * ext;
    for (let y = 0; y < N; y++) {
      const wy = center.y + ((y - N / 2) / (N / 2)) * ext;
      const off = z * N * N + y * N;
      for (let x = 0; x < N; x++) {
        const wx = center.x + ((x - N / 2) / (N / 2)) * ext;
        wp.set(wx, wy, wz);
        let d = 1e9;
        for (let i = 0; i < prims.length; i++) {
          const pr = prims[i];
          if (!pr.boxM.containsPoint(wp)) continue;
          lp.copy(wp).applyMatrix4(pr.inv);
          const di = pr.fn(lp) * pr.scale;
          d = smin(d, di, pr.blend ?? blend);
        }
        // chegaradagi kataklar ochiq qolmasin
        if (x < 1 || y < 1 || z < 1 || x > N - 3 || y > N - 3 || z > N - 3) d = Math.max(d, cell);
        field[off + x] = d > 1e8 ? -cell * 4 : -d / cell; // ichkarida musbat
      }
    }
  }
  mc.update();
  const count = mc.count;
  if (count < 3) throw new Error('empty');

  let g = new THREE.BufferGeometry();
  const pos = new Float32Array(count * 3);
  for (let i = 0; i < count; i++) {
    pos[i * 3] = center.x + mc.positionArray[i * 3] * ext;
    pos[i * 3 + 1] = center.y + mc.positionArray[i * 3 + 1] * ext;
    pos[i * 3 + 2] = center.z + mc.positionArray[i * 3 + 2] * ext;
  }
  mc.geometry.dispose();
  g.setAttribute('position', new THREE.BufferAttribute(pos, 3));
  g = mergeVertices(g, cell * 1e-3);
  fixWinding(g, prims, blend);
  taubinSmooth(g, opts.smoothIter ?? 2);
  g.computeVertexNormals();

  if (opts.colors !== false) addColors(g, prims, blend);
  if (opts.uv !== false) addBoxUV(g, opts.uvScale || 1);
  g.computeBoundingBox();
  g.computeBoundingSphere();
  return g;
}

/** Yuzalar tashqariga qaragan bo'lishi kerak — tekshirib, kerak bo'lsa aylantiramiz. */
function fixWinding(g, prims, blend) {
  const idx = g.index.array;
  const pos = g.attributes.position.array;
  // bir nechta uchburchakning normalini "tashqari" yo'nalish bilan solishtiramiz
  let agree = 0, total = 0;
  const a = new THREE.Vector3(), b = new THREE.Vector3(), c = new THREE.Vector3(), n = new THREE.Vector3(), m = new THREE.Vector3();
  const step = Math.max(1, Math.floor(idx.length / 3 / 200));
  for (let f = 0; f < idx.length / 3; f += step) {
    a.fromArray(pos, idx[f * 3] * 3);
    b.fromArray(pos, idx[f * 3 + 1] * 3);
    c.fromArray(pos, idx[f * 3 + 2] * 3);
    n.subVectors(b, a).cross(c.clone().sub(a)).normalize();
    m.copy(a).add(b).add(c).multiplyScalar(1 / 3);
    const sdf = (q) => {
      let d = 1e9;
      for (const p of prims) d = smin(d, p.fn(_p.copy(q).applyMatrix4(p.inv)) * p.scale, p.blend ?? blend);
      return d;
    };
    const e = 1e-3;
    const out = sdf(m.clone().addScaledVector(n, e)) > sdf(m.clone().addScaledVector(n, -e));
    if (out) agree++;
    total++;
  }
  if (agree < total / 2) {
    for (let f = 0; f < idx.length; f += 3) {
      const t = idx[f + 1];
      idx[f + 1] = idx[f + 2];
      idx[f + 2] = t;
    }
  }
}

/** Taubin silliqlash — marching cubes "pog'onalarini" yo'qotadi, hajmni saqlaydi. */
function taubinSmooth(g, iterations) {
  if (!iterations) return;
  const idx = g.index.array;
  const pos = g.attributes.position.array;
  const nv = pos.length / 3;
  // qo'shnilar (CSR)
  const deg = new Uint32Array(nv + 1);
  for (let i = 0; i < idx.length; i++) deg[idx[i] + 1] += 2;
  for (let i = 0; i < nv; i++) deg[i + 1] += deg[i];
  const fill = deg.slice(0, nv);
  const nb = new Uint32Array(idx.length * 2);
  for (let f = 0; f < idx.length; f += 3) {
    const a = idx[f], b = idx[f + 1], c = idx[f + 2];
    nb[fill[a]++] = b; nb[fill[a]++] = c;
    nb[fill[b]++] = a; nb[fill[b]++] = c;
    nb[fill[c]++] = a; nb[fill[c]++] = b;
  }
  const tmp = new Float32Array(pos.length);
  const pass = (lambda) => {
    for (let v = 0; v < nv; v++) {
      let sx = 0, sy = 0, sz = 0;
      const s = deg[v], e = deg[v + 1];
      const cnt = e - s;
      if (!cnt) {
        tmp[v * 3] = pos[v * 3]; tmp[v * 3 + 1] = pos[v * 3 + 1]; tmp[v * 3 + 2] = pos[v * 3 + 2];
        continue;
      }
      for (let k = s; k < e; k++) {
        const u = nb[k] * 3;
        sx += pos[u]; sy += pos[u + 1]; sz += pos[u + 2];
      }
      tmp[v * 3] = pos[v * 3] + (sx / cnt - pos[v * 3]) * lambda;
      tmp[v * 3 + 1] = pos[v * 3 + 1] + (sy / cnt - pos[v * 3 + 1]) * lambda;
      tmp[v * 3 + 2] = pos[v * 3 + 2] + (sz / cnt - pos[v * 3 + 2]) * lambda;
    }
    pos.set(tmp);
  };
  for (let i = 0; i < iterations; i++) {
    pass(0.5);
    pass(-0.53);
  }
}

/** Har bir uchga eng yaqin shakllarning rangini (aralashtirib) beradi. */
function addColors(g, prims, blend) {
  const pos = g.attributes.position.array;
  const nv = pos.length / 3;
  const col = new Float32Array(nv * 3);
  const p = new THREE.Vector3();
  const k = 1 / Math.max(blend * 0.6, 0.01);
  for (let v = 0; v < nv; v++) {
    p.fromArray(pos, v * 3);
    let wr = 0, wg = 0, wb = 0, ws = 0;
    for (const pr of prims) {
      if (!pr.boxM.containsPoint(p)) continue;
      const d = pr.fn(_p.copy(p).applyMatrix4(pr.inv)) * pr.scale;
      const w = Math.exp(-Math.max(d, 0) * k) * (d < 0 ? 1.5 : 1);
      wr += pr.color.r * w; wg += pr.color.g * w; wb += pr.color.b * w; ws += w;
    }
    if (ws > 0) {
      col[v * 3] = wr / ws; col[v * 3 + 1] = wg / ws; col[v * 3 + 2] = wb / ws;
    } else col.fill(1, v * 3, v * 3 + 3);
  }
  g.setAttribute('color', new THREE.BufferAttribute(col, 3));
}

/** Uch tomonlama (box) UV proyeksiya: tekstura (tangacha) uchun. */
export function addBoxUV(g, scale = 1) {
  const pos = g.attributes.position.array;
  if (!g.attributes.normal) g.computeVertexNormals();
  const nor = g.attributes.normal.array;
  const nv = pos.length / 3;
  const uv = new Float32Array(nv * 2);
  for (let v = 0; v < nv; v++) {
    const ax = Math.abs(nor[v * 3]), ay = Math.abs(nor[v * 3 + 1]), az = Math.abs(nor[v * 3 + 2]);
    const x = pos[v * 3] * scale, y = pos[v * 3 + 1] * scale, z = pos[v * 3 + 2] * scale;
    if (ax >= ay && ax >= az) {
      uv[v * 2] = z; uv[v * 2 + 1] = y;
    } else if (ay >= az) {
      uv[v * 2] = x; uv[v * 2 + 1] = z;
    } else {
      uv[v * 2] = x; uv[v * 2 + 1] = y;
    }
  }
  g.setAttribute('uv', new THREE.BufferAttribute(uv, 2));
}
