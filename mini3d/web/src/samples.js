// ============================================================
//  NAMUNA LOYIHALAR — ilovaning imkoniyatlarini ko'rsatish uchun.
//  Har biri muharrir API si orqali "qo'lda" yig'iladi, shuning uchun
//  ochilgandan keyin hamma qismini tahrirlash mumkin.
//  Yangi namuna qo'shish: samples ro'yxatiga {id,label,desc,icon,build}.
// ============================================================
import * as THREE from 'three';
import { effectDefaults } from './effects.js';
import { organicMesh } from './organic.js';
import { applyPattern } from './textures.js';

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
//  1) OLOV FENIKS-AJDAR — "Organik tana" + tangacha + patlar
// ---------------------------------------------------------------
function buildPhoenix(E) {
  const { add, group, fx, light } = builder(E);
  const RED = '#a50c17', DRED = '#6e0810', CREAM = '#ecd6b0';
  const gold = { color: '#ffc94d', metal: 1, rough: 0.2 };
  const root = group('Olov feniks-ajdar');
  root.userData.motion = { type: 'bob', axis: 'y', amount: 0.03, speed: 0.5, phase: 0 };

  // ===== 1. Skelet (shakllardan) → organik tana =====
  const sk = group('Tana skeleti', root);
  const ball = (pos, r, color, scale) => add('sphere', { radius: r, widthSegments: 16, heightSegments: 10 }, { parent: sk, pos, scale, mat: { color } });
  const caps = (pos, r, len, rot, color) => add('capsule', { radius: r, length: len, capSegments: 4, radialSegments: 10 }, { parent: sk, pos, rot, mat: { color } });
  // tana
  ball([0.2, 1.75, 0], 1, RED, [1.3, 0.78, 0.76]);
  ball([-0.78, 1.7, 0], 1, CREAM, [0.6, 0.8, 0.52]);
  ball([0.1, 1.33, 0], 1, CREAM, [1.0, 0.42, 0.58]);
  ball([1.15, 1.62, 0], 1, RED, [0.72, 0.68, 0.68]);
  // bo'yin (orqasi qizil, oldi krem)
  const neck = [[-1.0, 2.3, 0.46], [-1.22, 2.62, 0.4], [-1.38, 2.9, 0.35], [-1.48, 3.12, 0.32]];
  neck.forEach(([x, y, r]) => ball([x + 0.06, y, 0], r, RED));
  neck.forEach(([x, y, r]) => ball([x - 0.2, y - 0.05, 0], r * 0.66, CREAM, [1, 1, 0.7]));
  // bosh
  ball([-1.62, 3.32, 0], 0.36, RED, [1.15, 0.92, 0.9]);
  ball([-1.86, 3.22, 0], 0.22, RED, [1.2, 0.8, 0.85]);
  for (const z of [-1, 1]) ball([-1.72, 3.16, 0.15 * z], 0.17, CREAM);
  // oyoqlar
  const legsAt = [[-0.68, 0.55], [-0.68, -0.55], [1.12, 0.58], [1.12, -0.58]];
  for (const [x, z] of legsAt) {
    const zs = Math.sign(z);
    caps([x, 1.12, z], 0.28, 0.5, [0.18 * zs, 0, x < 0 ? 0.15 : -0.2], RED);
    caps([x + (x < 0 ? -0.05 : 0.12), 0.55, z * 1.12], 0.17, 0.48, [0.12 * zs, 0, x < 0 ? -0.1 : 0.35], DRED);
    caps([x - 0.1, 0.13, z * 1.18], 0.12, 0.3, [0, 0, Math.PI / 2], DRED);
  }
  // dum: S-shaklida yuqoriga buraladi
  const tailCurve = new THREE.CatmullRomCurve3([
    new THREE.Vector3(1.5, 1.6, 0), new THREE.Vector3(2.2, 1.3, 0.05), new THREE.Vector3(2.85, 1.35, 0.1),
    new THREE.Vector3(3.3, 1.85, 0.08), new THREE.Vector3(3.45, 2.5, 0), new THREE.Vector3(3.25, 3.05, -0.05), new THREE.Vector3(2.95, 3.25, -0.05),
  ]);
  const TN = 34;
  for (let k = 0; k <= TN; k++) {
    const t = k / TN;
    ball(tailCurve.getPoint(t).toArray(), 0.42 * (1 - t * 0.78), k % 7 === 3 ? DRED : RED);
  }
  const body = organicMesh(sk, { resolution: 112, blend: 0.22 });
  const bodyMat = new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.5, metalness: 0.12, envMapIntensity: 0.6 });
  applyPattern(bodyMat, 'scales', { repeat: 1.3, glow: '#ff4a0a', glowIntensity: 0.8 });
  const skin = new THREE.Mesh(body, bodyMat);
  skin.name = 'Tana (organik)';
  skin.userData = { prim: 'custom', sculpt: true };
  skin.castShadow = skin.receiveShadow = true;
  E.addObject(skin, { parent: root, select: false, commit: false });
  sk.visible = false;
  sk.name = 'Tana skeleti (asl)';

  // ===== 2. Patlar uchun yordamchi =====
  const featherMat = (m, glow = 1.2) => {
    m.material.color.set('#ffffff');
    m.material.side = THREE.DoubleSide;
    m.material.roughness = 0.55;
    m.material.metalness = 0.05;
    applyPattern(m.material, 'feather', { repeat: 1, glow: '#ff6a10', glowIntensity: glow });
  };
  const feather = (parent, base, dir, normal, len, width, cBase, cTip, bend = 0.12, glow = 1.2) => {
    const m = add('feather', { length: len, width, bend, barbs: 6, thickness: 0.015, colorBase: cBase, colorTip: cTip }, { name: 'Pat', parent, base, dir, len: 0, planeNormal: normal });
    m.position.set(...base);
    featherMat(m, glow);
    return m;
  };
  const horn = (parent, base, dir, normal, length, radius, bend, mat = gold, name = 'Shox') => {
    const m = add('horn', { length, radius, bend, tip: 0.04, segments: 14, sides: 10 }, { name, parent, base, dir, len: 0, planeNormal: normal, mat });
    m.position.set(...base);
    return m;
  };

  // ===== 3. Bosh: tumshuq, ko'zlar, shoxlar, olovli yol =====
  const head = group('Bosh bezaklari', root, [-1.6, 3.3, 0]);
  head.userData.motion = { type: 'sway', axis: 'z', amount: 4, speed: 0.5, phase: 90 };
  const Hp = (x, y, z) => [x + 1.6, y - 3.3, z];
  horn(head, Hp(-1.98, 3.24, 0), [-1, -0.15, 0], [0, 0, 1], 0.42, 0.11, -55, gold, 'Tumshuq');
  for (const z of [-1, 1]) {
    add('sphere', { radius: 0.07, widthSegments: 12, heightSegments: 8 }, { name: 'Koʻz', parent: head, pos: Hp(-1.97, 3.4, 0.17 * z), scale: [0.7, 1, 1], mat: { color: '#fff1a0', emissive: '#ffcc00', ei: 5 } });
    horn(head, Hp(-1.55, 3.6, 0.16 * z), [0.6, 1, 0.45 * z], [0, 0, 1], 0.75, 0.07, 70, gold, 'Shox');
    horn(head, Hp(-1.7, 3.52, 0.2 * z), [0.4, 1, 0.7 * z], [0, 0, 1], 0.4, 0.05, 50, gold, 'Kichik shox');
  }
  add('octahedron', { radius: 0.06, detail: 0 }, { name: 'Peshona javohiri', parent: head, pos: Hp(-1.93, 3.5, 0), scale: [0.6, 1.4, 1], mat: { color: '#22c55e', emissive: '#16a34a', ei: 2.5, metal: 0.3, rough: 0.1 } });
  for (let k = 0; k < 11; k++) {
    const a = (k / 10 - 0.5) * 1.6;
    const len = 0.7 + Math.cos(a) * 0.55;
    feather(head, Hp(-1.62 + Math.abs(a) * 0.1, 3.55, a * 0.18), [0.55 + Math.abs(a) * 0.3, 1, a * 0.9], [-Math.sin(a), 0, Math.cos(a)], len, 0.26, '#c4161c', '#ffd23f', 0.2, 1.6);
  }
  fx('fire', 'Toj olovi', head, Hp(-1.5, 3.95, 0), { rate: 45, size: 0.32, speed: 0.9, radius: 0.18, life: 0.6, opacity: 0.7 });

  // ===== 4. Yol (bo'yin va orqa bo'ylab olov patlar) =====
  const mane = group('Yol', root);
  for (let k = 0; k < 16; k++) {
    const t = k / 15;
    const x = -1.4 + t * 2.4, y = 3.0 - t * 0.95 - Math.sin(t * Math.PI) * 0.15;
    for (const z of [-0.12, 0.12]) {
      feather(mane, [x, y, z], [0.9, 0.8 - t * 0.4, z * 3], [0, -0.3, 1], 0.55 + (1 - t) * 0.35, 0.22, '#a3101b', t < 0.5 ? '#ffb020' : '#ff7a1a', 0.25, 1.3);
    }
  }

  // ===== 5. Oltin zirhlar va tirnoqlar =====
  const armor = group('Oltin zirhlar', root);
  for (const z of [-1, 1]) {
    for (let k = 0; k < 4; k++) horn(armor, [-0.55 + k * 0.12, 2.1 - k * 0.12, 0.62 * z], [0.7 + k * 0.2, 1 - k * 0.15, 0.55 * z], [0, 0, z], 0.55 - k * 0.08, 0.09, 45, gold, 'Yelka zirhi');
    for (let k = 0; k < 3; k++) horn(armor, [1.0 + k * 0.12, 1.85 - k * 0.1, 0.62 * z], [0.8, 0.7, 0.5 * z], [0, 0, z], 0.45 - k * 0.08, 0.08, 40, gold, 'Son zirhi');
  }
  for (const [x, z] of legsAt) {
    const zs = Math.sign(z);
    horn(armor, [x - 0.08, 0.78, z * 1.12], [-0.4, 1, 0.3 * zs], [0, 0, 1], 0.35, 0.08, 30, gold, 'Tizza zirhi');
    for (const cz of [-0.11, 0, 0.11]) horn(armor, [x - 0.3, 0.1, z * 1.18 + cz], [-1, 0.25, cz * 2], [0, 0, 1], 0.28, 0.05, -80, gold, 'Tirnoq');
  }

  // ===== 6. Qanotlar =====
  for (const z of [-1, 1]) {
    const wing = group(z > 0 ? 'Oʻng qanot' : 'Chap qanot', root, [-0.3, 2.35, 0.55 * z]);
    wing.userData.motion = { type: 'flap', axis: 'x', amount: 16, speed: 0.7, phase: z > 0 ? 0 : 180 };
    const U = new THREE.Vector3(0.45, 1, 0.75 * z).normalize(); // old qirra
    const V = new THREE.Vector3(1, -0.15, 0.45 * z).normalize(); // orqa yo'nalish
    const Nw = new THREE.Vector3().crossVectors(U, V).normalize().multiplyScalar(z);
    const Lw = 2.1;
    horn(wing, [0, 0, 0], U.toArray(), Nw.toArray(), Lw * 0.9, 0.1, -25, { color: DRED, rough: 0.45, metal: 0.2 }, 'Qanot suyagi');
    horn(wing, U.clone().multiplyScalar(Lw * 0.55).toArray(), [-0.3, 1, 0.2 * z], Nw.toArray(), 0.6, 0.09, -40, gold, 'Qanot zirhi');
    horn(wing, U.clone().multiplyScalar(Lw * 0.95).toArray(), U.clone().add(new THREE.Vector3(-0.4, 0, 0)).toArray(), Nw.toArray(), 0.5, 0.08, -60, gold, 'Qanot tirnogʻi');
    const rows = [
      { n: 17, lenK: 1.0, w: 0.34, cb: '#b0121c', ct: '#ffd23f', off: 0, glow: 1.5 },
      { n: 15, lenK: 0.66, w: 0.3, cb: '#8e0c16', ct: '#ff8a1c', off: 0.04, glow: 1.0 },
      { n: 12, lenK: 0.38, w: 0.26, cb: '#7c0a12', ct: '#ff4a14', off: 0.08, glow: 0.6 },
    ];
    for (const r of rows) {
      for (let k = 0; k < r.n; k++) {
        const s = (k + 0.5) / r.n;
        const base = U.clone().multiplyScalar(s * Lw * 0.98).addScaledVector(Nw, r.off);
        const dir = V.clone().multiplyScalar(1 - s * 0.55).add(U.clone().multiplyScalar(s * 0.85 + 0.05)).normalize();
        const len = (1.0 + 1.7 * Math.pow(s, 1.2)) * r.lenK;
        feather(wing, base.toArray(), dir.toArray(), Nw.toArray(), len, r.w, r.cb, r.ct, 0.1, r.glow);
      }
    }
    fx('fire', 'Qanot olovi', wing, U.clone().multiplyScalar(Lw).add(V.clone().multiplyScalar(1.4)).toArray(), { rate: 60, size: 0.45, speed: 0.9, radius: 0.5, opacity: 0.55, life: 0.8 });
    fx('sparks', 'Qanot uchqunlari', wing, U.clone().multiplyScalar(Lw * 0.7).add(V.clone().multiplyScalar(1.2)).toArray(), { rate: 14, radius: 0.8, size: 0.06, speed: 0.7, opacity: 0.85 });
  }

  // ===== 7. Dum patlari va olovi =====
  const tailFx = group('Dum olovi', root, tailCurve.getPoint(0.55).toArray());
  tailFx.userData.motion = { type: 'sway', axis: 'y', amount: 6, speed: 0.45, phase: 0 };
  const T0 = tailCurve.getPoint(0.55);
  for (let k = 0; k < 26; k++) {
    const t = 0.55 + (k / 25) * 0.45;
    const p = tailCurve.getPoint(t).sub(T0);
    const tan = tailCurve.getTangent(t);
    const side = k % 2 ? 1 : -1;
    const out = new THREE.Vector3(0, 0, side).add(new THREE.Vector3(-tan.y, tan.x, 0).multiplyScalar(0.8)).normalize();
    feather(tailFx, p.toArray(), out.clone().add(tan.clone().multiplyScalar(0.6)).toArray(), new THREE.Vector3().crossVectors(out, tan).toArray(), 0.6 + t * 0.9, 0.3, '#b0121c', '#ffc21a', 0.3, 1.5);
  }
  const tip = tailCurve.getPoint(1).sub(T0);
  for (let k = 0; k < 7; k++) {
    const a = (k / 6 - 0.5) * 2.2;
    feather(tailFx, tip.toArray(), [-0.6 + Math.sin(a) * 0.4, 1, Math.cos(a) * 0.9], [Math.cos(a), 0, -Math.sin(a)], 1.3 + Math.cos(a) * 0.4, 0.4, '#c4161c', '#ffe066', 0.35, 1.8);
  }
  fx('fire', 'Dum uchi olovi', tailFx, tip.toArray(), { rate: 120, size: 0.6, speed: 1.4, radius: 0.35, opacity: 0.75 });

  // ===== 8. Atrof: pol, uchqun, tutun, yorug'lik =====
  const floor = add('plane', { width: 24, height: 24, segments: 1 }, { name: 'Pol', pos: [0, 0, 0], mat: { color: '#0b0909', rough: 0.55, metal: 0.25 } });
  floor.material.envMapIntensity = 0.15;
  fx('sparks', 'Uchqunlar', root, [0.6, 0.3, 0], { rate: 40, radius: 3, size: 0.06, speed: 1.1, life: 3 });
  fx('smoke', 'Tutun', E.content, [0.6, 0, 0], { rate: 10, radius: 3, size: 2.2, opacity: 0.18, speed: 0.25 });
  light('PointLight', 'Olov yorugʻi', [0.2, 3.2, 0], '#ff6a1a', 14);
  light('PointLight', 'Old nur', [-3, 2.5, 2.5], '#ffb070', 12);
  light('PointLight', 'Orqa nur', [2.5, 4, -2.5], '#ff9a40', 20);

  Object.assign(E.sceneSettings, {
    background: '#1d1b20', grid: false, axes: false, studio: true, studioIntensity: 0.6, env: true, envIntensity: 0.7, exposure: 1.0,
    bloom: true, bloomStrength: 0.75, bloomRadius: 0.45, bloomThreshold: 1.0, fog: true, shadows: true, ground: false,
    anim: { duration: 6, fps: 30, loop: true },
  });
  return { camera: [[-6.4, 2.4, 5.6], [0.5, 1.95, 0]] };
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
