// ============================================================
//  MODELLASH VOSITALARI
//  Har bir vosita: { id, label, icon, needs: 'mesh'|'object'|null, run(editor, ui) }
//  Yangi vosita qo'shish: registerTool({...}) — u "Vositalar" menyusida
//  avtomatik paydo bo'ladi.
// ============================================================
import * as THREE from 'three';
import { Brush, Evaluator, ADDITION, SUBTRACTION, INTERSECTION } from 'three-bvh-csg';
import { mergeGeometries, mergeVertices, toCreasedNormals } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import { LoopSubdivision } from 'three-subdivide';
import { organicMesh } from './organic.js';
import { MeshBVH } from 'three-mesh-bvh';
import { MarchingCubes } from 'three/examples/jsm/objects/MarchingCubes.js';
import { deepClone, disposeObject } from './editor.js';
import { t } from './i18n.js';

export const tools = [];
export function registerTool(def) {
  tools.push(def);
}

// ---------- Boolean (CSG) ----------
function brushFrom(mesh) {
  let g = mesh.geometry.clone();
  if (g.index) g = g.toNonIndexed();
  if (!g.attributes.normal) g.computeVertexNormals();
  if (!g.attributes.uv) {
    g.setAttribute('uv', new THREE.Float32BufferAttribute(new Float32Array(g.attributes.position.count * 2), 2));
  }
  for (const name of Object.keys(g.attributes)) {
    if (!['position', 'normal', 'uv'].includes(name)) g.deleteAttribute(name);
  }
  g.clearGroups();
  const b = new Brush(g);
  mesh.updateWorldMatrix(true, false);
  mesh.matrixWorld.decompose(b.position, b.quaternion, b.scale);
  b.updateMatrixWorld();
  return b;
}

export function booleanOp(editor, a, b, op, keepCutter = false) {
  const ops = { union: ADDITION, subtract: SUBTRACTION, intersect: INTERSECTION };
  const ev = new Evaluator();
  ev.attributes = ['position', 'normal', 'uv'];
  ev.useGroups = false;
  const result = ev.evaluate(brushFrom(a), brushFrom(b), ops[op]);
  const geo = result.geometry;
  if (!geo.attributes.position || geo.attributes.position.count === 0) throw new Error('empty');

  // Natija dunyo koordinatalarida — markazini obyekt joyiga ko'chiramiz
  geo.computeBoundingBox();
  const center = geo.boundingBox.getCenter(new THREE.Vector3());
  geo.translate(-center.x, -center.y, -center.z);
  const mesh = new THREE.Mesh(geo, [].concat(a.material)[0].clone());
  mesh.position.copy(center);
  mesh.name = editor.uniqueName(`${a.name} (${t('bool.' + op)})`);
  mesh.castShadow = mesh.receiveShadow = true;
  mesh.userData = { prim: 'custom' };

  const parent = editor.content;
  editor.select(null);
  a.removeFromParent();
  disposeObject(a);
  if (!keepCutter) {
    b.removeFromParent();
    disposeObject(b);
  } else {
    b.visible = false;
  }
  return editor.addObject(mesh, { parent, label: 'boolean' });
}

// ---------- Massiv (Array) ----------
export function arrayOp(editor, obj, { count, offset, mode, radius, axis }) {
  const parent = obj.parent || editor.content;
  const created = [];
  const base = obj.position.clone();
  for (let k = 1; k < count; k++) {
    const c = deepClone(obj);
    c.name = editor.uniqueName(obj.name.replace(/ \d+$/, ''));
    if (mode === 'circle') {
      // Aylana bo'ylab: markaz obyektdan "radius" masofada, tanlangan o'q atrofida
      const a = (k / count) * Math.PI * 2;
      const ax = new THREE.Vector3(axis === 'x' ? 1 : 0, axis === 'y' ? 1 : 0, axis === 'z' ? 1 : 0);
      const arm = axis === 'x' ? new THREE.Vector3(0, 0, radius) : new THREE.Vector3(radius, 0, 0);
      const center = base.clone().sub(arm);
      c.position.copy(center).add(arm.clone().applyAxisAngle(ax, a));
      c.quaternion.premultiply(new THREE.Quaternion().setFromAxisAngle(ax, a));
    } else {
      c.position.copy(base).addScaledVector(offset, k);
    }
    parent.add(c);
    created.push(c);
  }
  editor.syncLightProxies();
  editor.emit('sceneGraphChanged');
  editor.commit('array');
  return created;
}

// ---------- Ko'zgu (Mirror) ----------
export function mirrorOp(editor, obj, axis, copy = true) {
  const target = copy ? deepClone(obj) : obj;
  target.scale[axis] *= -1;
  target.position[axis] = -obj.position[axis];
  if (copy) {
    target.name = editor.uniqueName(obj.name + ' (mirror)');
    return editor.addObject(target, { parent: obj.parent || editor.content, label: 'mirror' });
  }
  editor.emit('objectChanged', obj, 'transform');
  editor.commit('mirror');
  return obj;
}

// ---------- Yerga qo'yish ----------
export function dropToGround(editor, obj) {
  const box = new THREE.Box3().setFromObject(obj);
  if (box.isEmpty()) return;
  const dy = -box.min.y;
  const worldPos = obj.getWorldPosition(new THREE.Vector3());
  worldPos.y += dy;
  obj.parent.worldToLocal(worldPos);
  obj.position.copy(worldPos);
  editor.emit('objectChanged', obj, 'transform');
  editor.commit('ground');
}

export function centerOrigin(editor, obj) {
  obj.position.set(0, obj.position.y, 0);
  editor.emit('objectChanged', obj, 'transform');
  editor.commit('center');
}

export function resetTransform(editor, obj) {
  obj.rotation.set(0, 0, 0);
  obj.scale.set(1, 1, 1);
  editor.emit('objectChanged', obj, 'transform');
  editor.commit('reset');
}

/** Bir nechta meshni bitta meshga birlashtiradi (guruh yoki import qilingan model uchun). */
export function mergeIntoMesh(editor, root) {
  const geos = [];
  let material = null;
  root.updateWorldMatrix(true, true);
  const inv = new THREE.Matrix4().copy(root.matrixWorld).invert();
  root.traverse((o) => {
    if (!o.isMesh || !o.visible) return;
    let g = o.geometry.clone();
    if (g.index) g = g.toNonIndexed();
    for (const name of Object.keys(g.attributes)) if (!['position', 'normal', 'uv'].includes(name)) g.deleteAttribute(name);
    if (!g.attributes.normal) g.computeVertexNormals();
    if (!g.attributes.uv) g.setAttribute('uv', new THREE.Float32BufferAttribute(new Float32Array(g.attributes.position.count * 2), 2));
    g.clearGroups();
    g.applyMatrix4(new THREE.Matrix4().multiplyMatrices(inv, o.matrixWorld));
    geos.push(g);
    material ||= [].concat(o.material)[0].clone();
  });
  if (!geos.length) throw new Error('empty');
  const merged = mergeGeometries(geos);
  geos.forEach((g) => g.dispose());
  const mesh = new THREE.Mesh(merged, material);
  mesh.name = root.name;
  mesh.position.copy(root.position);
  mesh.quaternion.copy(root.quaternion);
  mesh.scale.copy(root.scale);
  mesh.castShadow = mesh.receiveShadow = true;
  mesh.userData = { prim: 'custom' };
  const parent = root.parent;
  editor.select(null);
  root.removeFromParent();
  disposeObject(root);
  return editor.addObject(mesh, { parent, label: 'merge' });
}

/** Shakl geometriyasini "muzlatish": parametrlarni olib tashlab, oddiy meshga aylantirish */
export function bakeGeometry(editor, mesh) {
  mesh.userData = { ...mesh.userData, prim: 'custom' };
  delete mesh.userData.params;
  editor.emit('objectChanged', mesh, 'geometry');
  editor.commit('bake');
}

/** Silliqlash: normallarni qayta hisoblash (silliq yoki tekis yuzalar) */
export function smoothNormals(editor, mesh, smooth) {
  const old = mesh.geometry;
  let g = old.clone();
  if (smooth) {
    g.deleteAttribute('normal');
    g = mergeVertices(g, 1e-4);
    g.computeVertexNormals();
  } else {
    if (g.index) g = g.toNonIndexed();
    g.computeVertexNormals();
  }
  mesh.geometry = g;
  old.dispose();
  if (mesh.userData.prim && mesh.userData.prim !== 'custom') {
    mesh.userData = { ...mesh.userData, prim: 'custom' };
    delete mesh.userData.params;
  }
  editor.emit('objectChanged', mesh, 'geometry');
  editor.commit('normals');
}

// ---------- Silliqlash (Subdivision) ----------
/**
 * Loop subdivision: burchakli shakllarni yumaloq, silliq qiladi.
 * levels: 1-3, smooth=false bo'lsa faqat bo'laklarga bo'linadi (shakl o'zgarmaydi).
 * Guruh tanlansa — ichidagi barcha meshlarga qo'llanadi.
 */
export function subdivideOp(editor, obj, { levels = 1, smooth = true, crease = 60 } = {}) {
  const meshes = [];
  obj.traverse((o) => o.isMesh && !o.userData.sculpt && meshes.push(o));
  if (obj.isMesh && obj.userData.sculpt) meshes.push(obj);
  let total = 0;
  for (const m of meshes) {
    const g = m.geometry;
    total += ((g.index ? g.index.count : g.attributes.position.count) / 3) * Math.pow(4, levels);
  }
  if (total > 800000) throw new Error('too-many');
  for (const m of meshes) {
    const old = m.geometry;
    const src = old.clone();
    for (const k of Object.keys(src.attributes)) if (!['position', 'normal', 'uv'].includes(k)) src.deleteAttribute(k);
    src.clearGroups();
    let g = LoopSubdivision.modify(src, levels, { split: true, uvSmooth: false, preserveEdges: false, flatOnly: !smooth, maxTriangles: 400000 });
    src.dispose();
    if (m.userData.sculpt) {
      g.deleteAttribute('uv');
      g.deleteAttribute('normal');
      g = mergeVertices(g, 1e-6);
      g.computeVertexNormals();
    } else {
      g.deleteAttribute('normal');
      g = toCreasedNormals(g, (crease * Math.PI) / 180);
    }
    g.computeBoundingBox();
    g.computeBoundingSphere();
    m.geometry = g;
    old.dispose();
    if (m.userData.params) {
      m.userData = { ...m.userData, prim: 'custom' };
      delete m.userData.params;
    }
    if (Array.isArray(m.material)) m.material = m.material[0];
    editor.emit('objectChanged', m, 'geometry');
  }
  editor.emit('sceneGraphChanged');
  editor.commit('subdivide');
  return meshes.length;
}

// ---------- Markaz nuqtasi (pivot) ----------
/**
 * Obyektning aylanish markazini ko'chiradi (obyekt joyidan qimirlamaydi).
 * mode: 'center' | 'bottom' | 'top' | 'world' (dunyo markazi 0,0,0)
 */
export function setPivot(editor, obj, mode = 'center') {
  obj.updateWorldMatrix(true, true);
  let P;
  if (mode === 'world') P = new THREE.Vector3();
  else {
    const box = new THREE.Box3().setFromObject(obj);
    if (box.isEmpty()) return;
    P = box.getCenter(new THREE.Vector3());
    if (mode === 'bottom') P.y = box.min.y;
    if (mode === 'top') P.y = box.max.y;
  }
  const localP = obj.worldToLocal(P.clone());
  if (obj.isMesh) {
    if (obj.userData.params) {
      obj.userData = { ...obj.userData, prim: 'custom' };
      delete obj.userData.params;
    }
    obj.geometry.translate(-localP.x, -localP.y, -localP.z);
    obj.geometry.boundsTree && obj.geometry.computeBoundsTree?.();
  }
  for (const c of obj.children) c.position.sub(localP);
  const parentP = obj.parent.worldToLocal(P.clone());
  obj.position.copy(parentP);
  // kalit kadrlardagi pozitsiyalarni ham moslaymiz
  if (obj.userData.keys) {
    const off = new THREE.Vector3();
    for (const k of obj.userData.keys) {
      const q = new THREE.Quaternion().fromArray(k.q);
      const s = new THREE.Vector3().fromArray(k.s);
      off.copy(localP).multiply(s).applyQuaternion(q);
      k.p = new THREE.Vector3().fromArray(k.p).add(off).toArray();
    }
  }
  editor.emit('objectChanged', obj, 'geometry');
  editor.emit('objectChanged', obj, 'transform');
  editor.commit('pivot');
}

// ---------- Organik tana ----------
/**
 * Guruhdagi shakllarni bitta silliq "teri"ga aylantiradi (keyin sculpt qilish mumkin).
 * keep=true — asl guruh yashirilib saqlanadi (keyin qayta tahrirlash uchun).
 */
export function organicMerge(editor, root, { resolution = 96, blend = 0.15, keep = true } = {}) {
  const g = organicMesh(root, { resolution, blend });
  const mat = new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.5, metalness: 0.1 });
  const mesh = new THREE.Mesh(g, mat);
  mesh.name = editor.uniqueName(root.name + ' (organik)');
  mesh.userData = { prim: 'custom', sculpt: true };
  mesh.castShadow = mesh.receiveShadow = true;
  mesh.position.copy(root.position);
  mesh.quaternion.copy(root.quaternion);
  mesh.scale.copy(root.scale);
  const parent = root.parent || editor.content;
  editor.select(null);
  if (keep) {
    root.visible = false;
    root.name = root.name.includes('(asl)') ? root.name : root.name + ' (asl)';
  } else {
    root.removeFromParent();
    disposeObject(root);
  }
  return editor.addObject(mesh, { parent, label: 'organic' });
}

// ---------- Naqsh bo'rttirish (geometriyani siljitish) ----------
// 3D Worley (hujayra) shovqini: tangacha, yoriq, teri g'adir-budurligi
function hash3(x, y, z) {
  let h = (x * 374761393 + y * 668265263 + z * 2147483647) | 0;
  h = Math.imul(h ^ (h >>> 13), 1274126177);
  return ((h ^ (h >>> 16)) >>> 0) / 4294967295;
}
function worley(x, y, z) {
  const xi = Math.floor(x), yi = Math.floor(y), zi = Math.floor(z);
  let f1 = 9, f2 = 9;
  for (let dz = -1; dz <= 1; dz++)
    for (let dy = -1; dy <= 1; dy++)
      for (let dx = -1; dx <= 1; dx++) {
        const cx = xi + dx, cy = yi + dy, cz = zi + dz;
        const px = cx + hash3(cx, cy, cz), py = cy + hash3(cy, cz, cx), pz = cz + hash3(cz, cx, cy);
        const d = (px - x) ** 2 + (py - y) ** 2 + (pz - z) ** 2;
        if (d < f1) { f2 = f1; f1 = d; } else if (d < f2) f2 = d;
      }
  return [Math.sqrt(f1), Math.sqrt(f2)];
}
function vnoise(x, y, z) {
  const xi = Math.floor(x), yi = Math.floor(y), zi = Math.floor(z);
  const fx = x - xi, fy = y - yi, fz = z - zi;
  const s = (t) => t * t * (3 - 2 * t);
  const u = s(fx), v = s(fy), w = s(fz);
  const L = (a, b, t) => a + (b - a) * t;
  const h = (a, b, c) => hash3(xi + a, yi + b, zi + c);
  return L(L(L(h(0, 0, 0), h(1, 0, 0), u), L(h(0, 1, 0), h(1, 1, 0), u), v), L(L(h(0, 0, 1), h(1, 0, 1), u), L(h(0, 1, 1), h(1, 1, 1), u), v), w);
}
export const displacePatterns = {
  scales: (x, y, z) => {
    const [f1, f2] = worley(x, y, z);
    return Math.min(1, (f2 - f1) * 2.2) ** 0.6; // har bir hujayra — qavariq tangacha
  },
  cracks: (x, y, z) => {
    const [f1, f2] = worley(x, y, z);
    return Math.min(1, (f2 - f1) * 6); // chegaralar chuqur
  },
  skin: (x, y, z) => (vnoise(x, y, z) * 0.6 + vnoise(x * 2.1, y * 2.1, z * 2.1) * 0.3 + vnoise(x * 4.3, y * 4.3, z * 4.3) * 0.1),
};

/** Naqshni normal bo'ylab "o'yib/bo'rttirib" chiqaradi. Niqob hurmat qilinadi. */
export function displacePattern(editor, mesh, { pattern = 'scales', strength = 0.03, scale = 8 } = {}) {
  const g = mesh.geometry;
  if (!g.attributes.normal) g.computeVertexNormals();
  const pos = g.attributes.position.array, nor = g.attributes.normal.array;
  const mask = g.attributes.mask?.array;
  const f = displacePatterns[pattern];
  g.computeBoundingSphere();
  const k = scale / Math.max(0.01, g.boundingSphere.radius);
  for (let i = 0; i < pos.length / 3; i++) {
    const m = mask ? 1 - mask[i] : 1;
    if (m <= 0) continue;
    const h = (f(pos[i * 3] * k, pos[i * 3 + 1] * k, pos[i * 3 + 2] * k) - 0.5) * strength * m;
    pos[i * 3] += nor[i * 3] * h;
    pos[i * 3 + 1] += nor[i * 3 + 1] * h;
    pos[i * 3 + 2] += nor[i * 3 + 2] * h;
  }
  g.attributes.position.needsUpdate = true;
  g.computeVertexNormals();
  g.computeBoundingBox();
  g.computeBoundingSphere();
  if (g.boundsTree) g.boundsTree.refit();
  if (mesh.userData.params) {
    mesh.userData = { ...mesh.userData, prim: 'custom' };
    delete mesh.userData.params;
  }
  editor.emit('objectChanged', mesh, 'geometry');
  editor.commit('displace');
}

// ---------- Voxel remesh ----------
/**
 * Modelni bir xil zichlikdagi yangi to'rga aylantiradi (sculpt paytida cho'zilgan
 * uchburchaklarni tuzatadi, teshik/kesishmalarni yopadi). Ranglar ko'chiriladi.
 */
export async function voxelRemesh(editor, mesh, { resolution = 96, onProgress } = {}) {
  const src = mesh.geometry;
  const bvh = src.boundsTree || new MeshBVH(src);
  src.computeBoundingBox();
  const bb = src.boundingBox.clone();
  const size = bb.getSize(new THREE.Vector3());
  const center = bb.getCenter(new THREE.Vector3());
  const N = resolution;
  const ext = (Math.max(size.x, size.y, size.z) / 2) * (1 + 4 / N);
  const cell = (2 * ext) / N;
  const dist = new Float32Array(N * N * N).fill(cell * 3);
  const near = new Uint8Array(N * N * N);
  const nsign = new Int8Array(N * N * N); // sirtga yaqin kataklar: +1 ichkari, -1 tashqari
  const p = new THREE.Vector3();
  const sp = src.attributes.position, si0 = src.index;
  const ta = new THREE.Vector3(), tb = new THREE.Vector3(), tc = new THREE.Vector3(), fn = new THREE.Vector3();
  const faceNormal = (f) => {
    const a = si0 ? si0.getX(f * 3) : f * 3, b = si0 ? si0.getX(f * 3 + 1) : f * 3 + 1, c = si0 ? si0.getX(f * 3 + 2) : f * 3 + 2;
    ta.fromBufferAttribute(sp, a); tb.fromBufferAttribute(sp, b); tc.fromBufferAttribute(sp, c);
    return fn.subVectors(tb, ta).cross(tc.sub(ta));
  };
  const target = { point: new THREE.Vector3(), distance: 0, faceIndex: 0 };
  for (let z = 0; z < N; z++) {
    for (let y = 0; y < N; y++) {
      for (let x = 0; x < N; x++) {
        p.set(center.x + ((x - N / 2) / (N / 2)) * ext, center.y + ((y - N / 2) / (N / 2)) * ext, center.z + ((z - N / 2) / (N / 2)) * ext);
        const r = bvh.closestPointToPoint(p, target, 0, cell * 2);
        if (r) {
          const i = z * N * N + y * N + x;
          dist[i] = target.distance;
          if (target.distance < cell * 1.5) {
            near[i] = 1;
            const nrm = faceNormal(target.faceIndex);
            nsign[i] = p.clone().sub(target.point).dot(nrm) > 0 ? -1 : 1;
          }
        }
      }
    }
    if (z % 8 === 0) {
      onProgress?.((z / N) * 0.8);
      await new Promise((r) => setTimeout(r, 0));
    }
  }
  // Tashqarini chegaradan "suv bosish" bilan aniqlaymiz (devor — sirtga yaqin kataklar)
  const outside = new Uint8Array(N * N * N);
  const stack = [];
  const push = (i) => {
    if (!outside[i] && !near[i]) {
      outside[i] = 1;
      stack.push(i);
    }
  };
  for (let a = 0; a < N; a++)
    for (let b = 0; b < N; b++) {
      push(a * N * N + b * N);
      push(a * N * N + b * N + N - 1);
      push(a * N * N + b);
      push(a * N * N + (N - 1) * N + b);
      push(a * N + b);
      push((N - 1) * N * N + a * N + b);
    }
  while (stack.length) {
    const i = stack.pop();
    const x = i % N, y = ((i / N) | 0) % N, z = (i / (N * N)) | 0;
    if (x > 0) push(i - 1);
    if (x < N - 1) push(i + 1);
    if (y > 0) push(i - N);
    if (y < N - 1) push(i + N);
    if (z > 0) push(i - N * N);
    if (z < N - 1) push(i + N * N);
  }
  const mc = new MarchingCubes(N, new THREE.MeshBasicMaterial(), false, false, Math.min(1500000, N * N * 60));
  mc.isolation = 0;
  for (let i = 0; i < dist.length; i++) {
    // ichkarida musbat; sirt atrofida masofa bo'yicha
    let v;
    if (near[i]) v = nsign[i] * dist[i];
    else v = outside[i] ? -dist[i] : dist[i];
    mc.field[i] = v / cell;
    const x = i % N, y = ((i / N) | 0) % N, z = (i / (N * N)) | 0;
    if (x < 1 || y < 1 || z < 1 || x > N - 3 || y > N - 3 || z > N - 3) mc.field[i] = -1;
  }
  onProgress?.(0.85);
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
  // yuzalar yo'nalishini tekshirish: normal tashqariga qarashi kerak
  g.computeVertexNormals();
  {
    const n = g.attributes.normal.array, pp = g.attributes.position.array;
    let out = 0, tot = 0;
    for (let i = 0; i < pp.length / 3; i += 37) {
      const v = new THREE.Vector3(pp[i * 3], pp[i * 3 + 1], pp[i * 3 + 2]).sub(center);
      out += v.dot(new THREE.Vector3(n[i * 3], n[i * 3 + 1], n[i * 3 + 2])) > 0 ? 1 : 0;
      tot++;
    }
    if (out < tot / 2) {
      const idx = g.index.array;
      for (let f = 0; f < idx.length; f += 3) { const t = idx[f + 1]; idx[f + 1] = idx[f + 2]; idx[f + 2] = t; }
      g.computeVertexNormals();
    }
  }
  // Ranglarni eng yaqin nuqtadan ko'chiramiz
  if (src.attributes.color) {
    const sc = src.attributes.color, si = src.index;
    const pp = g.attributes.position.array;
    const col = new Float32Array(pp.length);
    for (let i = 0; i < pp.length / 3; i++) {
      p.set(pp[i * 3], pp[i * 3 + 1], pp[i * 3 + 2]);
      if (bvh.closestPointToPoint(p, target, 0, cell * 4)) {
        const f = target.faceIndex;
        const a = si ? si.getX(f * 3) : f * 3;
        col[i * 3] = sc.getX(a); col[i * 3 + 1] = sc.getY(a); col[i * 3 + 2] = sc.getZ(a);
      } else col.fill(1, i * 3, i * 3 + 3);
    }
    g.setAttribute('color', new THREE.BufferAttribute(col, 3));
  }
  g.computeBoundingBox();
  g.computeBoundingSphere();
  const old = mesh.geometry;
  old.disposeBoundsTree?.();
  mesh.geometry = g;
  old.dispose();
  mesh.userData = { ...mesh.userData, prim: 'custom', sculpt: true };
  delete mesh.userData.params;
  onProgress?.(1);
  editor.emit('objectChanged', mesh, 'geometry');
  editor.emit('sceneGraphChanged');
  editor.commit('remesh');
  return g;
}

// ---------- Yelpig'ich massiv (patlar, tikanlar, yol) ----------
/**
 * Obyekt nusxalarini o'z markazi atrofida yelpig'ichdek taratadi.
 * Patning markazi uning asosida bo'lgani uchun qanot/dum/yol yasash oson.
 */
export function fanArray(editor, obj, { count = 9, angle = 90, axis = 'z', step = [0, 0, 0], scaleEnd = 0.6, colorShift = 0 } = {}) {
  const parent = obj.parent || editor.content;
  const ax = new THREE.Vector3(axis === 'x' ? 1 : 0, axis === 'y' ? 1 : 0, axis === 'z' ? 1 : 0);
  const baseQ = obj.quaternion.clone();
  const baseS = obj.scale.clone();
  const baseP = obj.position.clone();
  const created = [];
  for (let k = 1; k < count; k++) {
    const t = k / (count - 1);
    const c = deepClone(obj);
    c.name = editor.uniqueName(obj.name.replace(/ \d+$/, ''));
    c.quaternion.copy(baseQ).premultiply(new THREE.Quaternion().setFromAxisAngle(ax, THREE.MathUtils.degToRad(angle * t)));
    c.scale.copy(baseS).multiplyScalar(1 + (scaleEnd - 1) * t);
    c.position.copy(baseP).add(new THREE.Vector3(...step).multiplyScalar(k));
    if (colorShift && c.material?.color) {
      const hsl = {};
      c.material.color.getHSL(hsl);
      c.material.color.setHSL((hsl.h + colorShift * t + 1) % 1, hsl.s, hsl.l);
    }
    parent.add(c);
    created.push(c);
  }
  editor.syncLightProxies();
  editor.emit('sceneGraphChanged');
  editor.commit('fan');
  return created;
}
