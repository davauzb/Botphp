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
