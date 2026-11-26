// ============================================================
//  NAMUNA LOYIHALAR — ilovaning imkoniyatlarini ko'rsatish uchun.
//  Har biri muharrir API si orqali "qo'lda" yig'iladi, shuning uchun
//  ochilgandan keyin hamma qismini tahrirlash mumkin.
//  Yangi namuna qo'shish: samples ro'yxatiga {id,label,desc,icon,build}.
// ============================================================
import * as THREE from 'three';
import { effectDefaults } from './effects.js';
import { subdivideOp } from './tools.js';

const UP = new THREE.Vector3(0, 1, 0);

/** Qulay quruvchi: shakl, material, joylashuv, yo'nalish */
function builder(E) {
  const add = (id, params, o = {}) => {
    const m = E.createPrimitive(id, params);
    if (o.name) m.name = E.uniqueName(o.name);
    const mat = m.material, c = o.mat || {};
    mat.color.set(c.color || '#cccccc');
    mat.metalness = c.metal ?? 0;
    mat.roughness = c.rough ?? 0.5;
    if (c.emissive) {
      mat.emissive.set(c.emissive);
      mat.emissiveIntensity = c.ei ?? 1;
    }
    mat.flatShading = !!o.flat;
    m.position.set(...(o.pos || [0, 0, 0]));
    if (o.rot) m.rotation.set(...o.rot);
    if (o.scale) m.scale.set(...o.scale);
    if (o.dir) {
      const d = new THREE.Vector3(...o.dir).normalize();
      if (o.planeNormal) {
        // Y — yo'nalish, Z — tekislik normali (yassi pat tekislikda yotadi)
        const zN = new THREE.Vector3(...o.planeNormal);
        zN.addScaledVector(d, -zN.dot(d)).normalize();
        const x = new THREE.Vector3().crossVectors(d, zN).normalize();
        m.quaternion.setFromRotationMatrix(new THREE.Matrix4().makeBasis(x, d, zN));
      } else m.quaternion.setFromUnitVectors(UP, d);
      if (o.base) {
        const len = o.len ?? params.height ?? 1;
        m.position.set(...o.base).addScaledVector(d, len / 2);
      }
    }
    E.addObject(m, { parent: o.parent || E.content, select: false, commit: false });
    return m;
  };
  const group = (name, parent = E.content, pos = [0, 0, 0]) => {
    const g = E.createGroup();
    g.name = name;
    g.position.set(...pos);
    E.addObject(g, { parent, select: false, commit: false });
    return g;
  };
  const fx = (type, name, parent, pos, params = {}) => {
    const g = E.effects.create(type);
    g.name = name;
    g.position.set(...pos);
    g.userData.effect.params = { ...effectDefaults(type), ...params };
    E.addObject(g, { parent: parent || E.content, select: false, commit: false });
    return g;
  };
  const light = (type, name, pos, color, intensity, parent) => {
    const L = E.createLight(type);
    L.name = name;
    L.position.set(...pos);
    L.color.set(color);
    L.intensity = intensity;
    L.castShadow = false;
    E.addObject(L, { parent: parent || E.content, select: false, commit: false });
    return L;
  };
  return { add, group, fx, light };
}

// ---------------------------------------------------------------
//  1) OLOV FENIKS-AJDAR (donghua uslubidagi maxluq)
// ---------------------------------------------------------------
function buildPhoenix(E) {
  const { add, group, fx, light } = builder(E);
  const C = {
    red: { color: '#b0141f', rough: 0.4, metal: 0.15 },
    darkred: { color: '#6e0a12', rough: 0.45, metal: 0.15 },
    cream: { color: '#f3e4c8', rough: 0.55 },
    gold: { color: '#ffc94d', metal: 1, rough: 0.22 },
    fire1: { color: '#d8200a', emissive: '#c01800', ei: 0.45, rough: 0.5 },
    fire2: { color: '#ff6a10', emissive: '#f04a00', ei: 0.55, rough: 0.5 },
    fire3: { color: '#ffb81a', emissive: '#f59a00', ei: 0.7, rough: 0.5 },
    eye: { color: '#fff3a0', emissive: '#ffd000', ei: 4 },
    gem: { color: '#22c55e', emissive: '#16a34a', ei: 2, metal: 0.3, rough: 0.1 },
  };
  const root = group('Olov feniks-ajdar');
  root.userData.motion = { type: 'bob', axis: 'y', amount: 0.04, speed: 0.5, phase: 0 };

  // ---- Tana ----
  const body = group('Tana', root);
  const torso = add('sphere', { radius: 1, widthSegments: 32, heightSegments: 20 }, { name: 'Gavda', parent: body, pos: [0.1, 1.65, 0], scale: [1.45, 0.78, 0.78], mat: C.red });
  add('sphere', { radius: 1, widthSegments: 28, heightSegments: 18 }, { name: 'Koʻkrak', parent: body, pos: [-0.8, 1.6, 0], scale: [0.62, 0.78, 0.66], mat: C.cream });
  add('sphere', { radius: 1, widthSegments: 28, heightSegments: 16 }, { name: 'Qorin', parent: body, pos: [0.15, 1.28, 0], scale: [1.1, 0.42, 0.62], mat: C.cream });
  void torso;
  for (let k = 0; k < 6; k++) {
    const x = -0.7 + k * 0.38;
    add('cone', { radius: 0.13, height: 0.55 - k * 0.04, radialSegments: 4 }, { name: 'Orqa tikan', parent: body, base: [x, 2.3 - Math.abs(x) * 0.12, 0], dir: [0.45, 1, 0], len: 0.55 - k * 0.04, mat: k % 2 ? C.fire2 : C.fire3, flat: true });
  }
  for (const z of [-1, 1]) {
    add('sphere', { radius: 1, widthSegments: 16, heightSegments: 10 }, { name: 'Yelka zirhi', parent: body, pos: [-0.55, 1.95, 0.58 * z], scale: [0.42, 0.3, 0.14], rot: [0.5 * z, 0, -0.3], mat: C.gold, flat: true });
    add('cone', { radius: 0.12, height: 0.6, radialSegments: 4 }, { name: 'Zirh tikani', parent: body, base: [-0.5, 2.1, 0.62 * z], dir: [0.6, 1, 0.4 * z], len: 0.6, mat: C.gold, flat: true });
    add('sphere', { radius: 1, widthSegments: 16, heightSegments: 10 }, { name: 'Son zirhi', parent: body, pos: [0.85, 1.55, 0.6 * z], scale: [0.38, 0.28, 0.12], rot: [0.4 * z, 0, 0.2], mat: C.gold, flat: true });
  }

  // ---- Bosh (bo'yin asosida aylanadi) ----
  const head = group('Bosh', root, [-0.95, 1.95, 0]);
  head.userData.motion = { type: 'sway', axis: 'z', amount: 6, speed: 0.5, phase: 90 };
  const H = (x, y, z) => [x + 0.95, y - 1.95, z];
  add('cylinder', { radiusTop: 0.27, radiusBottom: 0.45, height: 1.15, radialSegments: 20 }, { name: 'Boʻyin', parent: head, pos: H(-1.18, 2.3, 0), rot: [0, 0, 0.55], mat: C.red });
  add('cylinder', { radiusTop: 0.22, radiusBottom: 0.36, height: 1.0, radialSegments: 20 }, { name: 'Boʻyin oldi', parent: head, pos: H(-1.27, 2.22, 0), rot: [0, 0, 0.55], mat: C.cream });
  add('sphere', { radius: 0.4, widthSegments: 24, heightSegments: 16 }, { name: 'Kalla', parent: head, pos: H(-1.62, 2.86, 0), scale: [1.15, 0.9, 0.85], mat: C.red });
  add('cone', { radius: 0.16, height: 0.55, radialSegments: 12 }, { name: 'Tumshuq', parent: head, base: H(-1.9, 2.8, 0), dir: [-1, -0.35, 0], len: 0.55, mat: C.gold });
  for (const z of [-1, 1]) {
    add('sphere', { radius: 0.07, widthSegments: 12, heightSegments: 8 }, { name: 'Koʻz', parent: head, pos: H(-1.88, 2.96, 0.2 * z), mat: C.eye });
    add('cone', { radius: 0.06, height: 0.5, radialSegments: 6 }, { name: 'Shox', parent: head, base: H(-1.6, 3.15, 0.18 * z), dir: [0.8, 1, 0.35 * z], len: 0.5, mat: C.gold });
  }
  add('octahedron', { radius: 0.07, detail: 0 }, { name: 'Peshona javohiri', parent: head, pos: H(-1.96, 3.08, 0), scale: [0.6, 1.3, 1], mat: C.gem });
  const crest = [[-1.75, 0.0, 0.75, C.fire3], [-1.6, 0.12, 0.95, C.fire2], [-1.6, -0.12, 0.95, C.fire2], [-1.45, 0.2, 1.05, C.fire1], [-1.45, -0.2, 1.05, C.fire1], [-1.45, 0, 1.15, C.fire3], [-1.3, 0.25, 0.9, C.fire2], [-1.3, -0.25, 0.9, C.fire2]];
  for (const [x, z, h, m] of crest) {
    add('cone', { radius: 0.17, height: h, radialSegments: 4 }, { name: 'Olov yol', parent: head, base: H(x, 3.1, z), dir: [0.9 + (x + 1.75) * 1.2, 1, z * 1.6], len: h, scale: [1, 1, 0.35], mat: m, flat: true, planeNormal: [0, 0, 1] });
  }
  fx('fire', 'Toj olovi', head, H(-1.45, 3.2, 0), { rate: 50, size: 0.3, speed: 1.0, radius: 0.12, life: 0.6, opacity: 0.8 });

  // ---- Oyoqlar ----
  const legs = group('Oyoqlar', root);
  for (const [lx, lz, nm] of [[-0.75, 0.42, 'Old oʻng'], [-0.75, -0.42, 'Old chap'], [0.85, 0.45, 'Orqa oʻng'], [0.85, -0.45, 'Orqa chap']]) {
    const leg = group(nm + ' oyoq', legs, [lx, 0, lz]);
    add('capsule', { radius: 0.22, length: 0.45, capSegments: 6, radialSegments: 16 }, { name: 'Son', parent: leg, pos: [0.05, 1.12, 0], rot: [0, 0, -0.25], mat: C.red });
    add('cylinder', { radiusTop: 0.15, radiusBottom: 0.11, height: 0.75, radialSegments: 12 }, { name: 'Boldir', parent: leg, pos: [0.02, 0.5, 0], rot: [0, 0, 0.12], mat: C.darkred });
    add('cone', { radius: 0.14, height: 0.4, radialSegments: 4 }, { name: 'Tizza zirhi', parent: leg, base: [-0.12, 0.75, 0], dir: [-0.4, 1, 0], len: 0.4, mat: C.gold, flat: true });
    add('capsule', { radius: 0.08, length: 0.3, capSegments: 4, radialSegments: 10 }, { name: 'Panja', parent: leg, pos: [-0.06, 0.08, 0], rot: [0, 0, Math.PI / 2], scale: [1, 1, 1.8], mat: C.darkred });
    for (const cz of [-0.11, 0, 0.11]) {
      add('cone', { radius: 0.045, height: 0.24, radialSegments: 8 }, { name: 'Tirnoq', parent: leg, base: [-0.25, 0.07, cz], dir: [-1, -0.3, cz * 2], len: 0.24, mat: C.gold });
    }
  }

  // ---- Dum (asosidan chayqaladi) ----
  const tail = group('Dum', root, [1.45, 1.55, 0]);
  tail.userData.motion = { type: 'sway', axis: 'y', amount: 14, speed: 0.45, phase: 0 };
  let prev = new THREE.Vector3(0, 0, 0);
  const N = 9;
  for (let k = 0; k < N; k++) {
    const tt = (k + 1) / N;
    const pt = new THREE.Vector3(tt * 1.6, Math.sin(tt * Math.PI * 0.85) * 1.1 + tt * 0.4, Math.sin(tt * Math.PI) * 0.25);
    const d = pt.clone().sub(prev);
    const r = 0.3 * (1 - tt * 0.75);
    add('cylinder', { radiusTop: r * 0.85, radiusBottom: r, height: d.length() * 1.15, radialSegments: 14 }, { name: 'Dum boʻlagi', parent: tail, base: prev.toArray(), dir: d.toArray(), len: d.length() * 1.15, mat: k % 2 ? C.red : C.darkred });
    if (k >= 3) {
      for (const sd of [-1, 1]) {
        const h = 0.5 + tt * 0.7;
        add('cone', { radius: 0.3, height: h, radialSegments: 4 }, { name: 'Dum pati', parent: tail, base: pt.toArray(), dir: [0.5, 0.6, 0.8 * sd], len: h, scale: [1, 1, 0.22], mat: k > 6 ? C.fire3 : C.fire2, flat: true, planeNormal: [0, 1, -0.6 * sd] });
      }
    }
    prev = pt;
  }
  for (let k = 0; k < 5; k++) {
    const h = 0.9 + k * 0.15;
    add('cone', { radius: 0.3, height: h, radialSegments: 4 }, { name: 'Dum uchi pati', parent: tail, base: prev.toArray(), dir: [0.4 + k * 0.25, 1, (k - 2) * 0.35], len: h, scale: [1, 1, 0.25], mat: [C.fire1, C.fire2, C.fire3, C.fire2, C.fire1][k], flat: true, planeNormal: [0, 0, 1] });
  }
  fx('fire', 'Dum olovi', tail, prev.toArray(), { rate: 110, size: 0.5, speed: 1.4, radius: 0.25, opacity: 0.8 });

  // ---- Qanotlar (yelkadan qoqiladi) ----
  for (const z of [-1, 1]) {
    const wing = group(z > 0 ? 'Oʻng qanot' : 'Chap qanot', root, [-0.35, 2.15, 0.5 * z]);
    wing.userData.motion = { type: 'flap', axis: 'x', amount: 28, speed: 0.9, phase: z > 0 ? 0 : 180 };
    const boneDir = new THREE.Vector3(0.35, 1, 0.75 * z).normalize();
    add('cylinder', { radiusTop: 0.08, radiusBottom: 0.16, height: 1.4, radialSegments: 10 }, { name: 'Qanot suyagi', parent: wing, base: [0, 0, 0], dir: boneDir.toArray(), len: 1.4, mat: C.red });
    const tip = boneDir.clone().multiplyScalar(1.4);
    add('cone', { radius: 0.13, height: 0.5, radialSegments: 4 }, { name: 'Qanot zirhi', parent: wing, base: tip.toArray(), dir: [-0.6, 0.9, 0.3 * z], len: 0.5, mat: C.gold, flat: true });
    const F = 12;
    const dirOf = (a) => new THREE.Vector3(Math.sin(a) + 0.2, Math.cos(a) * 1.1 + 0.15, 0.85 * z).normalize();
    const wingNormal = new THREE.Vector3().crossVectors(dirOf(0.15), dirOf(1.7)).normalize();
    for (let k = 0; k < F; k++) {
      const tt = k / (F - 1);
      const a = 0.15 + tt * 1.55;
      const dir = dirOf(a);
      const base = boneDir.clone().multiplyScalar(0.25 + (1 - tt) * 1.0);
      const h = 1.1 + Math.sin(tt * Math.PI) * 1.3 + (1 - tt) * 0.4;
      const m = h > 2.2 ? C.fire3 : h > 1.7 ? C.fire2 : C.fire1;
      add('cone', { radius: 0.34, height: h, radialSegments: 4 }, { name: 'Olov pat', parent: wing, base: base.toArray(), dir: dir.toArray(), len: h, scale: [1, 1, 0.14], mat: m, flat: true, planeNormal: wingNormal.toArray() });
      if (k % 2 === 0) add('cone', { radius: 0.3, height: h * 0.6, radialSegments: 4 }, { name: 'Ichki pat', parent: wing, base: base.clone().addScaledVector(wingNormal, 0.03).toArray(), dir: dir.toArray(), len: h * 0.6, scale: [1, 1, 0.14], mat: C.red, flat: true, planeNormal: wingNormal.toArray() });
    }
    fx('sparks', 'Qanot uchqunlari', wing, dirOf(0.9).multiplyScalar(1.8).toArray(), { rate: 14, radius: 0.6, size: 0.06, speed: 0.8, opacity: 0.8 });
  }

  // ---- Atrof ----
  fx('sparks', 'Uchqunlar', root, [0, 0.2, 0], { rate: 30, radius: 2.2, size: 0.06, speed: 1.2, life: 2.6 });
  fx('smoke', 'Tutun', root, [0.4, 0, 0], { rate: 8, radius: 1.4, size: 1.6, opacity: 0.25, speed: 0.35 });
  light('PointLight', 'Olov yorugʻi', [0, 2.6, 0], '#ff6a1a', 10);
  light('PointLight', 'Orqa nur', [2.5, 3.5, -2.5], '#ffb347', 14);

  // Tanadagi asosiy shakllarni silliqlaymiz (yumaloq, tirik ko'rinish)
  for (const o of [...body.children, ...head.children]) {
    if (o.isMesh && ['Gavda', 'Koʻkrak', 'Qorin', 'Kalla'].includes(o.name)) subdivideOp(E, o, { levels: 1, smooth: true, crease: 180 });
  }

  Object.assign(E.sceneSettings, {
    background: '#140f14', grid: false, axes: false, studioIntensity: 0.85, envIntensity: 0.8, exposure: 1.05,
    bloom: true, bloomStrength: 0.75, bloomRadius: 0.4, bloomThreshold: 1.05, fog: true,
    anim: { duration: 6, fps: 30, loop: true },
  });
  return { camera: [[-5.6, 3.3, 6.4], [0.2, 1.9, 0]] };
}

// ---------------------------------------------------------------
//  2) GULXAN (tunda olov)
// ---------------------------------------------------------------
function buildCampfire(E) {
  const { add, group, fx, light } = builder(E);
  const wood = { color: '#5a3a22', rough: 0.85 };
  const burnt = { color: '#2a1a12', rough: 0.9, emissive: '#7a1800', ei: 0.6 };
  const stone = { color: '#6f6d68', rough: 0.95 };
  add('circle', { radius: 6, segments: 64 }, { name: 'Yer', pos: [0, 0, 0], mat: { color: '#2b2a22', rough: 1 } });
  const fire = group('Gulxan');
  for (let k = 0; k < 6; k++) {
    const a = (k / 6) * Math.PI * 2;
    add('cylinder', { radiusTop: 0.09, radiusBottom: 0.11, height: 1.3, radialSegments: 10 }, { name: 'Oʻtin', parent: fire, pos: [Math.cos(a) * 0.32, 0.45, Math.sin(a) * 0.32], dir: [-Math.cos(a) * 0.55, 1, -Math.sin(a) * 0.55], mat: k % 2 ? wood : burnt });
  }
  for (let k = 0; k < 12; k++) {
    const a = (k / 12) * Math.PI * 2;
    add('dodecahedron', { radius: 0.2, detail: 0 }, { name: 'Tosh', parent: fire, pos: [Math.cos(a) * 0.95, 0.1, Math.sin(a) * 0.95], scale: [1.2, 0.7, 1], rot: [k, k * 2, 0], mat: stone, flat: true });
  }
  fx('fire', 'Olov', fire, [0, 0.15, 0], { rate: 140, size: 0.6, speed: 1.6, radius: 0.32, turbulence: 1, opacity: 0.75 });
  fx('sparks', 'Uchqunlar', fire, [0, 0.6, 0], { rate: 30, radius: 0.3, speed: 2.2, gravity: 0.2 });
  fx('smoke', 'Tutun', fire, [0, 1.6, 0], { rate: 14, size: 1.3, speed: 0.7, opacity: 0.35 });
  const L = light('PointLight', 'Olov nuri', [0, 1.0, 0], '#ff8a2a', 16, fire);
  L.userData.motion = { type: 'shake', axis: 'x', amount: 0.05, speed: 4, phase: 0 };
  Object.assign(E.sceneSettings, {
    background: '#070a12', grid: false, axes: false, studio: true, studioIntensity: 0.12, env: true, envIntensity: 0.08, exposure: 1.1,
    bloom: true, bloomStrength: 0.7, bloomRadius: 0.4, bloomThreshold: 0.95, fog: false, anim: { duration: 5, fps: 30, loop: true },
  });
  return { camera: [[3.2, 2.2, 3.8], [0, 0.7, 0]] };
}

// ---------------------------------------------------------------
//  3) SEHRLI KRISTALL
// ---------------------------------------------------------------
function buildCrystal(E) {
  const { add, group, fx, light } = builder(E);
  const crystal = { color: '#7f5cff', metal: 0.2, rough: 0.08, emissive: '#3b1fb8', ei: 0.9 };
  const rock = { color: '#3a3446', rough: 0.9 };
  add('dodecahedron', { radius: 1.2, detail: 1 }, { name: 'Qoya', pos: [0, 0.2, 0], scale: [1.4, 0.45, 1.2], mat: rock, flat: true });
  const core = group('Kristall', E.content, [0, 1.8, 0]);
  core.userData.motion = { type: 'bob', axis: 'y', amount: 0.15, speed: 0.4, phase: 0 };
  const gem = add('octahedron', { radius: 0.6, detail: 0 }, { name: 'Asosiy kristall', parent: core, scale: [0.8, 1.6, 0.8], mat: crystal, flat: true });
  gem.userData.motion = { type: 'spin', axis: 'y', amount: 1, speed: 0.15, phase: 0 };
  const orbit = group('Aylanuvchi kristallar', core);
  orbit.userData.motion = { type: 'spin', axis: 'y', amount: -1, speed: 0.1, phase: 0 };
  for (let k = 0; k < 6; k++) {
    const a = (k / 6) * Math.PI * 2;
    const c = add('octahedron', { radius: 0.16, detail: 0 }, { name: 'Kichik kristall', parent: orbit, pos: [Math.cos(a) * 1.3, Math.sin(k) * 0.25, Math.sin(a) * 1.3], scale: [0.7, 1.4, 0.7], mat: { ...crystal, color: '#a98bff' }, flat: true });
    c.userData.motion = { type: 'bob', axis: 'y', amount: 0.12, speed: 0.7, phase: k * 60 };
  }
  for (let k = 0; k < 7; k++) {
    const a = (k / 7) * Math.PI * 2 + 0.3;
    add('octahedron', { radius: 0.25, detail: 0 }, { name: 'Yerdagi kristall', pos: [Math.cos(a) * 1.2, 0.45, Math.sin(a) * 0.95], dir: [Math.cos(a) * 0.5, 1, Math.sin(a) * 0.5], scale: [0.6, 1.8, 0.6], mat: crystal, flat: true });
  }
  fx('magic', 'Sehr', core, [0, -0.6, 0], { radius: 1.0, rate: 90 });
  fx('energy', 'Energiya', core, [0, 0, 0], { radius: 0.9, rate: 80, size: 0.18 });
  light('PointLight', 'Kristall nuri', [0, 1.8, 0], '#8a6bff', 30);
  Object.assign(E.sceneSettings, {
    background: '#0b0a16', grid: false, axes: false, studioIntensity: 0.4, envIntensity: 0.5, exposure: 1.1,
    bloom: true, bloomStrength: 0.9, bloomRadius: 0.45, bloomThreshold: 0.85, anim: { duration: 8, fps: 30, loop: true },
  });
  return { camera: [[3.6, 2.6, 4.6], [0, 1.4, 0]] };
}

async function run(E, fn) {
  E.animator?.stop();
  E.clear();
  const { camera } = fn(E);
  E.applySceneSettings();
  const data = E.serializeProject({ compact: true });
  data.camera = { position: camera[0], target: camera[1] };
  return data;
}

export const samples = [
  { id: 'phoenix', label: 'sample.phoenix', desc: 'sample.phoenixDesc', icon: 'flame', build: (E) => run(E, buildPhoenix) },
  { id: 'campfire', label: 'sample.campfire', desc: 'sample.campfireDesc', icon: 'smoke', build: (E) => run(E, buildCampfire) },
  { id: 'crystal', label: 'sample.crystal', desc: 'sample.crystalDesc', icon: 'magic', build: (E) => run(E, buildCrystal) },
];
