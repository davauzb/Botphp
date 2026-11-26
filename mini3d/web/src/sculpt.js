// ============================================================
//  HAYKALTAROSHLIK (SCULPT) REJIMI
//  Barmoq bilan modelni "loy"dek shakllantirish.
//  - Cho'tkalar: chizish, loy, shishirish, silliqlash, tortish,
//    tekislash, siqish, chuqurlik (qirra)
//  - Simmetriya (X o'qi bo'yicha), teskari rejim
//  - Detal qo'shish (Loop subdivision)
//  - BVH (three-mesh-bvh) yordamida tezkor nur kesishuvi
//  - Faqat o'zgargan qismning normallari qayta hisoblanadi
//
//  Yangi cho'tka qo'shish: brushes ro'yxatiga {id,label,icon} va
//  _applyBrush() ichiga shu id uchun formula yozing.
// ============================================================
import * as THREE from 'three';
import { computeBoundsTree, disposeBoundsTree, acceleratedRaycast } from 'three-mesh-bvh';
import { mergeVertices } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import { LoopSubdivision } from 'three-subdivide';

THREE.BufferGeometry.prototype.computeBoundsTree = computeBoundsTree;
THREE.BufferGeometry.prototype.disposeBoundsTree = disposeBoundsTree;

export const brushes = [
  { id: 'draw', label: 'sculpt.draw', icon: 'bDraw' },
  { id: 'clay', label: 'sculpt.clay', icon: 'bClay' },
  { id: 'inflate', label: 'sculpt.inflate', icon: 'bInflate' },
  { id: 'smooth', label: 'sculpt.smooth', icon: 'smooth' },
  { id: 'grab', label: 'sculpt.grab', icon: 'bGrab' },
  { id: 'flatten', label: 'sculpt.flatten', icon: 'bFlatten' },
  { id: 'pinch', label: 'sculpt.pinch', icon: 'bPinch' },
  { id: 'crease', label: 'sculpt.crease', icon: 'bCrease' },
];

export const MAX_TRIS = 600000;

const _v = new THREE.Vector3();
const _n = new THREE.Vector3();
const _a = new THREE.Vector3();
const _b = new THREE.Vector3();
const _c = new THREE.Vector3();

export class Sculptor {
  constructor(editor) {
    this.editor = editor;
    this.mesh = null;
    this.brush = 'draw';
    this.radius = 45; // ekran pikselida
    this.strength = 0.5;
    this.invert = false;
    this.symmetry = true;
    this.stroke = null;
    this.raycaster = new THREE.Raycaster();
    this.raycaster.firstHitOnly = true;

    // Cho'tka halqasi (kursor)
    const ringGeo = new THREE.RingGeometry(0.94, 1, 48);
    this.cursor = new THREE.Mesh(ringGeo, new THREE.MeshBasicMaterial({ color: 0xffffff, transparent: true, opacity: 0.85, depthTest: false, side: THREE.DoubleSide }));
    this.cursor.renderOrder = 10;
    this.cursor.visible = false;
    this.cursorDot = new THREE.Mesh(new THREE.CircleGeometry(0.06, 16), this.cursor.material);
    this.cursor.add(this.cursorDot);
    editor.helpers.add(this.cursor);
  }

  get active() {
    return !!this.mesh;
  }

  // ---------------- Kirish / chiqish ----------------
  /** Meshni sculpt uchun tayyorlaydi (uchlarni birlashtiradi, BVH quradi). */
  enter(mesh) {
    if (!mesh?.isMesh) return false;
    if (this.mesh) this.exit();
    const E = this.editor;
    if (!mesh.userData.sculpt) {
      const old = mesh.geometry;
      let g = new THREE.BufferGeometry();
      g.setAttribute('position', old.attributes.position.clone());
      if (old.index) g.setIndex(old.index.clone());
      g = mergeVertices(g, 1e-5);
      g.computeVertexNormals();
      mesh.geometry = g;
      old.dispose();
      const mats = [].concat(mesh.material);
      if (mats.length > 1) mesh.material = mats[0];
      if (mesh.material.map) {
        mesh.material.map = null;
        mesh.material.needsUpdate = true;
      }
      mesh.userData = { ...mesh.userData, prim: 'custom', sculpt: true };
      delete mesh.userData.params;
    }
    this.mesh = mesh;
    this._prepare();
    E.select(mesh);
    E.setMode('sculpt');
    E.pointerInterceptor = this;
    E.emit('sculptChanged', true);
    E.emit('objectChanged', mesh, 'geometry');
    E.requestRender();
    return true;
  }

  exit() {
    if (!this.mesh) return;
    const E = this.editor;
    this.mesh.geometry.disposeBoundsTree?.();
    this.mesh.raycast = THREE.Mesh.prototype.raycast;
    this.mesh = null;
    this.topo = null;
    this.cursor.visible = false;
    E.pointerInterceptor = null;
    if (E.mode === 'sculpt') E.setMode('translate');
    E.emit('sculptChanged', false);
    E.requestRender();
  }

  _prepare() {
    const g = this.mesh.geometry;
    g.computeBoundsTree({ maxLeafTris: 10 });
    this.mesh.raycast = acceleratedRaycast;
    this.topo = buildTopology(g);
  }

  triangleCount() {
    const g = this.mesh?.geometry;
    if (!g) return 0;
    return (g.index ? g.index.count : g.attributes.position.count) / 3;
  }

  /** Detal qo'shish: har bir uchburchak 4 ga bo'linadi (silliq Loop). */
  subdivide() {
    const mesh = this.mesh;
    if (!mesh) return false;
    if (this.triangleCount() * 4 > MAX_TRIS) return false;
    const old = mesh.geometry;
    old.disposeBoundsTree?.();
    const src = new THREE.BufferGeometry();
    src.setAttribute('position', old.attributes.position);
    src.setIndex(old.index);
    for (const k of ['color', 'uv']) if (old.attributes[k]) src.setAttribute(k, old.attributes[k]);
    let g = LoopSubdivision.modify(src, 1, { split: false, preserveEdges: false, uvSmooth: true });
    for (const k of Object.keys(g.attributes)) if (!['position', 'color', 'uv'].includes(k)) g.deleteAttribute(k);
    const expected = old.attributes.position.count * 4.5;
    let merged = mergeVertices(g, 1e-6);
    if (merged.attributes.position.count > expected) {
      // rang/UV tufayli uchlar birlashmadi — faqat pozitsiya bilan
      for (const k of ['color', 'uv']) if (g.attributes[k]) g.deleteAttribute(k);
      merged = mergeVertices(g, 1e-6);
    }
    g = merged;
    g.computeVertexNormals();
    mesh.geometry = g;
    old.dispose();
    this._prepare();
    this.editor.emit('objectChanged', mesh, 'geometry');
    this.editor.emit('sceneGraphChanged');
    this.editor.commit('subdivide');
    return true;
  }

  /** Butun modelni bir marta silliqlash */
  relax(iterations = 2) {
    if (!this.mesh) return;
    const pos = this.mesh.geometry.attributes.position;
    const all = new Uint32Array(pos.count);
    for (let i = 0; i < all.length; i++) all[i] = i;
    const w = new Float32Array(pos.count).fill(1);
    for (let k = 0; k < iterations; k++) this._smooth(all, w, all.length, 0.5);
    this._finishGeometry(all, all.length, true);
    this.editor.commit('sculpt-relax');
  }

  // ---------------- Kursor hodisalari (editor.pointerInterceptor) ----------------
  down(e) {
    if (!this.mesh || (e.pointerType === 'mouse' && e.button !== 0)) return false;
    if (this.stroke) return true;
    const hit = this._raycast(e.clientX, e.clientY);
    if (!hit) return false;
    const pos = this.mesh.geometry.attributes.position;
    this.stroke = {
      start: performance.now(),
      backup: pos.array.slice(),
      last: hit.local.clone(),
      lastScreen: { x: e.clientX, y: e.clientY },
      dabs: 0,
      touched: false,
    };
    if (this.brush === 'grab') this._grabStart(hit, e);
    else this._dab(hit);
    this._showCursor(hit);
    return true;
  }

  move(e) {
    const st = this.stroke;
    if (!st) return;
    if (this.brush === 'grab') return this._grabMove(e);
    const hit = this._raycast(e.clientX, e.clientY);
    if (!hit) return;
    // Oraliq nuqtalar: cho'tka izi uzluksiz bo'lsin
    const spacing = Math.max(hit.radius * 0.18, 1e-5);
    const dist = hit.local.distanceTo(st.last);
    const steps = Math.min(12, Math.floor(dist / spacing));
    if (steps < 1) return this._showCursor(hit);
    for (let k = 1; k <= steps; k++) {
      const p = st.last.clone().lerp(hit.local, k / steps);
      this._dab({ ...hit, local: p }, k < steps);
    }
    st.last.copy(hit.local);
    this._showCursor(hit);
  }

  up() {
    const st = this.stroke;
    if (!st) return;
    this.stroke = null;
    this.cursor.visible = false;
    const g = this.mesh.geometry;
    g.boundsTree?.refit();
    g.computeBoundingBox();
    g.computeBoundingSphere();
    if (st.touched) {
      this.editor.emit('objectChanged', this.mesh, 'geometry');
      this.editor.commit('sculpt');
    }
    this.editor.requestRender();
  }

  /** Ikkinchi barmoq tegsa: chiziq bekor qilinadi (kamera boshqaruviga o'tiladi). */
  cancel() {
    const st = this.stroke;
    if (!st) return;
    this.stroke = null;
    this.cursor.visible = false;
    const g = this.mesh.geometry;
    if (performance.now() - st.start < 400) {
      g.attributes.position.array.set(st.backup);
      g.attributes.position.needsUpdate = true;
      g.computeVertexNormals();
      g.boundsTree?.refit();
    } else if (st.touched) {
      g.boundsTree?.refit();
      this.editor.commit('sculpt');
    }
    this.editor.requestRender();
  }

  // ---------------- Ichki hisob-kitoblar ----------------
  _raycast(cx, cy) {
    const E = this.editor;
    const rect = E.renderer.domElement.getBoundingClientRect();
    const ndc = new THREE.Vector2(((cx - rect.left) / rect.width) * 2 - 1, -((cy - rect.top) / rect.height) * 2 + 1);
    this.raycaster.setFromCamera(ndc, E.camera);
    const hits = this.raycaster.intersectObject(this.mesh, false);
    if (!hits.length) return null;
    const h = hits[0];
    const local = this.mesh.worldToLocal(h.point.clone());
    // Ekran pikselidan dunyo radiusiga o'tkazish
    const d = h.point.distanceTo(E.camera.position);
    const worldPerPx = (2 * d * Math.tan(THREE.MathUtils.degToRad(E.camera.fov) / 2)) / rect.height;
    const s = this.mesh.getWorldScale(_v);
    const scale = (Math.abs(s.x) + Math.abs(s.y) + Math.abs(s.z)) / 3 || 1;
    const radius = (this.radius * worldPerPx) / scale;
    const faceNormal = h.face ? h.face.normal.clone() : new THREE.Vector3(0, 1, 0);
    return { point: h.point, local, radius, worldRadius: this.radius * worldPerPx, normal: faceNormal, screen: { x: cx, y: cy } };
  }

  _showCursor(hit) {
    const c = this.cursor;
    const n = hit.normal.clone().transformDirection(this.mesh.matrixWorld);
    c.position.copy(hit.point).addScaledVector(n, hit.worldRadius * 0.02);
    c.quaternion.setFromUnitVectors(new THREE.Vector3(0, 0, 1), n);
    c.scale.setScalar(hit.worldRadius);
    c.material.color.set(this.invert ? 0x6ab7ff : this.brush === 'smooth' ? 0x8ef0a8 : 0xffffff);
    c.visible = true;
    this.editor.requestRender();
  }

  /** Radius ichidagi uchlar va ularning og'irliklari (simmetriya bilan). */
  _gather(center, radius) {
    const pos = this.mesh.geometry.attributes.position.array;
    const n = pos.length / 3;
    const r2 = radius * radius;
    const sym = this.symmetry;
    const cx = center.x, cy = center.y, cz = center.z;
    const idx = this._idxBuf && this._idxBuf.length >= n ? this._idxBuf : (this._idxBuf = new Uint32Array(n));
    const w = this._wBuf && this._wBuf.length >= n ? this._wBuf : (this._wBuf = new Float32Array(n));
    const side = this._sideBuf && this._sideBuf.length >= n ? this._sideBuf : (this._sideBuf = new Int8Array(n));
    let count = 0;
    for (let i = 0; i < n; i++) {
      const x = pos[i * 3], y = pos[i * 3 + 1], z = pos[i * 3 + 2];
      const dy = y - cy, dz = z - cz;
      const dx1 = x - cx;
      let d2 = dx1 * dx1 + dy * dy + dz * dz;
      let sd = 1;
      if (sym) {
        const dx2 = x + cx;
        const d2m = dx2 * dx2 + dy * dy + dz * dz;
        if (d2m < d2) {
          d2 = d2m;
          sd = -1;
        }
      }
      if (d2 < r2) {
        const t = 1 - d2 / r2;
        idx[count] = i;
        w[count] = t * t * (3 - 2 * t); // silliq so'nish
        side[count] = sd;
        count++;
      }
    }
    return count;
  }

  _dab(hit, deferNormals = false) {
    const g = this.mesh.geometry;
    const pos = g.attributes.position.array;
    const nor = g.attributes.normal.array;
    const r = hit.radius;
    const count = this._gather(hit.local, r);
    if (!count) return;
    const idx = this._idxBuf, w = this._wBuf, side = this._sideBuf;
    const k = this.strength * (this.invert ? -1 : 1);
    const center = hit.local;

    // O'rtacha normal va nuqta (simmetrik tomon uchun X teskari)
    const avgN = _n.set(0, 0, 0);
    const avgP = _a.set(0, 0, 0);
    let wsum = 0;
    for (let j = 0; j < count; j++) {
      const i = idx[j], ww = w[j], sd = side[j];
      avgN.x += nor[i * 3] * ww * sd;
      avgN.y += nor[i * 3 + 1] * ww;
      avgN.z += nor[i * 3 + 2] * ww;
      avgP.x += pos[i * 3] * ww * sd;
      avgP.y += pos[i * 3 + 1] * ww;
      avgP.z += pos[i * 3 + 2] * ww;
      wsum += ww;
    }
    if (avgN.lengthSq() < 1e-12) avgN.copy(hit.normal);
    avgN.normalize();
    avgP.multiplyScalar(1 / Math.max(wsum, 1e-9));

    const b = this.brush;
    if (b === 'smooth') {
      this._smooth(idx, w, count, Math.min(1, this.strength * 0.9 + 0.1));
    } else {
      for (let j = 0; j < count; j++) {
        const i = idx[j], ww = w[j], sd = side[j];
        const i3 = i * 3;
        const px = pos[i3], py = pos[i3 + 1], pz = pos[i3 + 2];
        const nx = avgN.x * sd, ny = avgN.y, nz = avgN.z;
        let dx = 0, dy = 0, dz = 0;
        switch (b) {
          case 'draw': {
            const m = k * r * 0.1 * ww;
            dx = nx * m; dy = ny * m; dz = nz * m;
            break;
          }
          case 'inflate': {
            const m = k * r * 0.08 * ww;
            dx = nor[i3] * m; dy = nor[i3 + 1] * m; dz = nor[i3 + 2] * m;
            break;
          }
          case 'clay': {
            // Tekislik: o'rtacha nuqtadan biroz yuqorida — undan pastdagi uchlar ko'tariladi
            const ox = avgP.x * sd + nx * r * 0.12 * k, oy = avgP.y + ny * r * 0.12 * k, oz = avgP.z + nz * r * 0.12 * k;
            const d = (px - ox) * nx + (py - oy) * ny + (pz - oz) * nz;
            if ((k > 0 && d < 0) || (k < 0 && d > 0)) {
              const m = -d * ww * 0.6 * Math.abs(this.strength);
              dx = nx * m; dy = ny * m; dz = nz * m;
            }
            break;
          }
          case 'flatten': {
            const d = (px - avgP.x * sd) * nx + (py - avgP.y) * ny + (pz - avgP.z) * nz;
            const m = -d * ww * 0.5 * this.strength;
            dx = nx * m; dy = ny * m; dz = nz * m;
            break;
          }
          case 'pinch': {
            const cxs = center.x * sd;
            const m = ww * 0.12 * k;
            let tx = cxs - px, ty = center.y - py, tz = center.z - pz;
            const dn = tx * nx + ty * ny + tz * nz;
            tx -= nx * dn; ty -= ny * dn; tz -= nz * dn;
            dx = tx * m; dy = ty * m; dz = tz * m;
            break;
          }
          case 'crease': {
            const cxs = center.x * sd;
            const m = ww * 0.15 * Math.abs(this.strength);
            let tx = cxs - px, ty = center.y - py, tz = center.z - pz;
            const dn = tx * nx + ty * ny + tz * nz;
            tx -= nx * dn; ty -= ny * dn; tz -= nz * dn;
            const dig = -k * r * 0.06 * ww;
            dx = tx * m + nx * dig; dy = ty * m + ny * dig; dz = tz * m + nz * dig;
            break;
          }
        }
        pos[i3] = px + dx;
        pos[i3 + 1] = py + dy;
        pos[i3 + 2] = pz + dz;
      }
    }
    if (this.symmetry) this._fixCenterSeam(idx, count);
    this.stroke && (this.stroke.touched = true);
    this._finishGeometry(idx, count, !deferNormals || ++this.stroke.dabs % 2 === 0);
  }

  /** Simmetriyada markazda (X=0) turgan uchlar markazda qolsin — chok ochilmasin */
  _fixCenterSeam(idx, count) {
    const backup = this.stroke?.backup;
    if (!backup) return;
    const pos = this.mesh.geometry.attributes.position.array;
    for (let j = 0; j < count; j++) {
      const i3 = idx[j] * 3;
      if (Math.abs(backup[i3]) < 1e-6) pos[i3] = 0;
    }
  }

  _smooth(idx, w, count, amount) {
    const g = this.mesh.geometry;
    const pos = g.attributes.position.array;
    const { vfOff, vfList } = this.topo;
    const index = g.index.array;
    const tmp = new Float32Array(count * 3);
    for (let j = 0; j < count; j++) {
      const i = idx[j];
      let sx = 0, sy = 0, sz = 0, c = 0;
      for (let f = vfOff[i]; f < vfOff[i + 1]; f++) {
        const fi = vfList[f] * 3;
        for (let q = 0; q < 3; q++) {
          const v = index[fi + q];
          if (v === i) continue;
          sx += pos[v * 3]; sy += pos[v * 3 + 1]; sz += pos[v * 3 + 2];
          c++;
        }
      }
      if (!c) {
        tmp[j * 3] = pos[i * 3]; tmp[j * 3 + 1] = pos[i * 3 + 1]; tmp[j * 3 + 2] = pos[i * 3 + 2];
        continue;
      }
      const t = w[j] * amount;
      tmp[j * 3] = pos[i * 3] + (sx / c - pos[i * 3]) * t;
      tmp[j * 3 + 1] = pos[i * 3 + 1] + (sy / c - pos[i * 3 + 1]) * t;
      tmp[j * 3 + 2] = pos[i * 3 + 2] + (sz / c - pos[i * 3 + 2]) * t;
    }
    for (let j = 0; j < count; j++) {
      const i = idx[j];
      pos[i * 3] = tmp[j * 3]; pos[i * 3 + 1] = tmp[j * 3 + 1]; pos[i * 3 + 2] = tmp[j * 3 + 2];
    }
  }

  /** Faqat o'zgargan uchlar atrofidagi normallarni qayta hisoblaydi. */
  _finishGeometry(idx, count, updateNormals = true) {
    const g = this.mesh.geometry;
    g.attributes.position.needsUpdate = true;
    if (updateNormals) {
      const { vfOff, vfList } = this.topo;
      const index = g.index.array;
      const pos = g.attributes.position.array;
      const nor = g.attributes.normal.array;
      const mark = this._mark && this._mark.length >= pos.length / 3 ? this._mark : (this._mark = new Uint8Array(pos.length / 3));
      const ring = [];
      for (let j = 0; j < count; j++) {
        const i = idx[j];
        for (let f = vfOff[i]; f < vfOff[i + 1]; f++) {
          const fi = vfList[f] * 3;
          for (let q = 0; q < 3; q++) {
            const v = index[fi + q];
            if (!mark[v]) {
              mark[v] = 1;
              ring.push(v);
            }
          }
        }
      }
      for (const v of ring) {
        mark[v] = 0;
        let nx = 0, ny = 0, nz = 0;
        for (let f = vfOff[v]; f < vfOff[v + 1]; f++) {
          const fi = vfList[f] * 3;
          const a = index[fi], b = index[fi + 1], c = index[fi + 2];
          _a.fromArray(pos, a * 3);
          _b.fromArray(pos, b * 3);
          _c.fromArray(pos, c * 3);
          _b.sub(_a);
          _c.sub(_a);
          _b.cross(_c); // maydon bo'yicha og'irlangan
          nx += _b.x; ny += _b.y; nz += _b.z;
        }
        const l = Math.hypot(nx, ny, nz) || 1;
        nor[v * 3] = nx / l; nor[v * 3 + 1] = ny / l; nor[v * 3 + 2] = nz / l;
      }
      g.attributes.normal.needsUpdate = true;
    }
    // BVH ni vaqti-vaqti bilan yangilaymiz (har dab'da emas — tezlik uchun)
    const now = performance.now();
    if (!this._lastRefit || now - this._lastRefit > 60) {
      g.boundsTree?.refit();
      this._lastRefit = now;
    }
    this.editor.requestRender();
  }

  // ---------------- Tortish (Grab) ----------------
  _grabStart(hit) {
    const count = this._gather(hit.local, hit.radius);
    const st = this.stroke;
    st.grab = {
      idx: this._idxBuf.slice(0, count),
      w: this._wBuf.slice(0, count),
      side: this._sideBuf.slice(0, count),
      orig: new Float32Array(count * 3),
      planePoint: hit.point.clone(),
      planeNormal: this.editor.camera.getWorldDirection(new THREE.Vector3()).negate(),
      startWorld: hit.point.clone(),
      startLocal: hit.local.clone(),
    };
    const pos = this.mesh.geometry.attributes.position.array;
    for (let j = 0; j < count; j++) {
      const i = st.grab.idx[j];
      st.grab.orig[j * 3] = pos[i * 3];
      st.grab.orig[j * 3 + 1] = pos[i * 3 + 1];
      st.grab.orig[j * 3 + 2] = pos[i * 3 + 2];
    }
    st.radius = hit.radius;
  }

  _grabMove(e) {
    const st = this.stroke;
    const G = st?.grab;
    if (!G) return;
    const E = this.editor;
    const rect = E.renderer.domElement.getBoundingClientRect();
    const ndc = new THREE.Vector2(((e.clientX - rect.left) / rect.width) * 2 - 1, -((e.clientY - rect.top) / rect.height) * 2 + 1);
    this.raycaster.setFromCamera(ndc, E.camera);
    const plane = new THREE.Plane().setFromNormalAndCoplanarPoint(G.planeNormal, G.planePoint);
    const p = new THREE.Vector3();
    if (!this.raycaster.ray.intersectPlane(plane, p)) return;
    const local = this.mesh.worldToLocal(p.clone());
    const d = local.sub(G.startLocal);
    const pos = this.mesh.geometry.attributes.position.array;
    const s = this.strength * 0.6 + 0.4;
    for (let j = 0; j < G.idx.length; j++) {
      const i = G.idx[j], ww = G.w[j] * s, sd = G.side[j];
      pos[i * 3] = G.orig[j * 3] + d.x * sd * ww;
      pos[i * 3 + 1] = G.orig[j * 3 + 1] + d.y * ww;
      pos[i * 3 + 2] = G.orig[j * 3 + 2] + d.z * ww;
    }
    st.touched = true;
    this._finishGeometry(G.idx, G.idx.length, true);
  }
}

/** Uch → yuzlar (CSR) ro'yxati. */
function buildTopology(g) {
  const index = g.index.array;
  const nv = g.attributes.position.count;
  const nf = index.length / 3;
  const vfOff = new Uint32Array(nv + 1);
  for (let i = 0; i < index.length; i++) vfOff[index[i] + 1]++;
  for (let i = 0; i < nv; i++) vfOff[i + 1] += vfOff[i];
  const fill = vfOff.slice(0, nv);
  const vfList = new Uint32Array(index.length);
  for (let f = 0; f < nf; f++) {
    for (let q = 0; q < 3; q++) {
      const v = index[f * 3 + q];
      vfList[fill[v]++] = f;
    }
  }
  return { vfOff, vfList };
}
