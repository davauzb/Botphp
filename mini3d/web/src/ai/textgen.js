// ============================================================
//  MATNDAN MAXLUQ (oflayn, kalitsiz, bir zumda)
//  1) Tavsifni tahlil qilish — o'zbek, rus, ingliz so'zlari:
//     tur (ajdar, feniks, uzun ajdar, qush, hayvon, odam), stixiya
//     (olov, muz, chaqmoq...), ranglar, qanot, shox, tangacha, o'lcham
//  2) Protsedural generator: skelet → organik tana → tangacha naqshi →
//     patli qanotlar, shoxlar, tirnoqlar → effektlar → harakat → sahna
//  Har bir tushunilgan so'z ko'rsatiladi (tushuntiriladigan natija).
// ============================================================
import * as THREE from 'three';
import { builder } from '../samples.js';
import { organicMesh } from '../organic.js';
import { applyPattern } from '../textures.js';
import { t } from '../i18n.js';

// ---------- Lug'at (so'z boshlari bo'yicha, kichik harflarda) ----------
const TYPES = {
  phoenix: ['feniks', 'phoenix', 'феникс', 'semurg', 'simurg', 'humo', 'xumo'],
  loong: ['xitoy', 'uzun ajdar', 'loong', 'chinese', 'китайск', 'ilon', 'snake', 'serpent', 'змей', 'змея', 'naga'],
  dragon: ['ajdar', 'ajdaho', 'ajdarho', 'dragon', 'дракон', 'wyvern'],
  bird: ['qush', 'bird', 'птиц', 'burgut', 'eagle', 'орёл', 'орел', 'lochin', 'falcon', 'qarg', 'tovus', 'peacock'],
  beast: ["bo'ri", 'bori', 'wolf', 'волк', 'sher', 'lion', 'лев', "yo'lbars", 'yolbars', 'tiger', 'тигр', 'hayvon', 'beast', 'зверь', 'qilin', 'kirin', 'цилинь', 'ot ', 'horse', 'конь'],
  human: ['odam', 'inson', 'qahramon', 'jangchi', 'warrior', 'human', 'hero', 'человек', 'воин', 'герой', 'samuray', 'samurai', 'ninja'],
};
const ELEMENTS = {
  fire: ['olov', 'alanga', 'otash', 'yonuvchi', 'fire', 'flame', 'blaze', 'inferno', 'огн', 'огон', 'пламен', 'lava', 'лава', 'quyosh', 'sun'],
  ice: ['muz', 'qor ', 'qorli', 'sovuq', 'ice', 'frost', 'snow', 'лед', 'лёд', 'ледян', 'снег', 'мороз'],
  electric: ['chaqmoq', 'elektr', 'yashin', 'lightning', 'thunder', 'electric', 'storm', 'молни', 'гром', 'шторм'],
  nature: ['tabiat', "o'rmon", 'orman', 'barg', 'gul', 'nature', 'forest', 'leaf', 'лес', 'природ', 'лист'],
  magic: ['sehr', 'afsun', 'mistik', 'magic', 'mystic', 'arcane', 'star', 'yulduz', 'маги', 'волшеб', 'звезд'],
  dark: ["qorong'u", 'qorongu', 'tun', 'shayton', 'iblis', 'dark', 'shadow', 'demon', 'night', 'тьм', 'демон', 'тёмн', 'темн', 'ночн'],
  water: ['suv', 'dengiz', 'okean', 'water', 'sea', 'ocean', 'вод', 'море', 'океан'],
};
const COLORS = {
  '#b0121c': ['qizil', 'red', 'красн', 'alqizil', 'crimson'],
  '#ff7a1a': ["to'q sariq", 'toq sariq', 'orange', 'оранж', 'apelsin'],
  '#ffc21a': ['sariq', 'yellow', 'жёлт', 'желт'],
  '#ffc94d': ['oltin', 'tilla', 'gold', 'golden', 'золот'],
  '#d0d5dc': ['kumush', 'silver', 'серебр'],
  '#2f8f4e': ['yashil', 'green', 'зелён', 'зелен', 'zumrad', 'emerald'],
  '#1d5fd1': ["ko'k", 'kok', 'blue', 'синий', 'синя', 'голуб', 'moviy', 'havorang'],
  '#6b2fb3': ['binafsha', 'siyohrang', 'purple', 'violet', 'фиолет'],
  '#e05a9a': ['pushti', 'pink', 'розов'],
  '#ece6dc': ['oq', 'white', 'бел'],
  '#1c1a1f': ['qora', 'black', 'чёрн', 'черн'],
  '#6b4226': ['jigarrang', 'brown', 'коричн'],
};
const METALS = ['#ffc94d', '#d0d5dc'];
const NUMBERS = { bir: 1, ikki: 2, uch: 3, "to'rt": 4, tort: 4, one: 1, two: 2, three: 3, four: 4, один: 1, одним: 1, два: 2, две: 2, двумя: 2, три: 3, тремя: 3, четыре: 4, четырьмя: 4 };

const ELEMENT_DEFAULTS = {
  fire: { body: '#a50c17', belly: '#ecd6b0', accent: '#ffc94d', glow: '#ff4a0a', tip: '#ffd23f', fx: ['fire', 'sparks'], bg: '#1d1416', light: '#ff7a2a' },
  ice: { body: '#3a78c8', belly: '#e8f4ff', accent: '#d0e8ff', glow: '#7fd4ff', tip: '#e8fbff', fx: ['snow', 'magic'], bg: '#0e1a26', light: '#9fd8ff' },
  electric: { body: '#1f2f6e', belly: '#cfd8ff', accent: '#ffe066', glow: '#5ad1ff', tip: '#a8ecff', fx: ['energy', 'sparks'], bg: '#0b0f1e', light: '#7cc8ff' },
  nature: { body: '#2f7d4f', belly: '#e6dcb0', accent: '#c9a441', glow: '#9cff6a', tip: '#d4ff8a', fx: ['magic'], bg: '#0f1a12', light: '#c8ffb0' },
  magic: { body: '#4a237a', belly: '#e6d8ff', accent: '#ffc94d', glow: '#c08cff', tip: '#ffd9ff', fx: ['magic', 'energy'], bg: '#120c1e', light: '#c08cff' },
  dark: { body: '#1c1a1f', belly: '#4a3a4a', accent: '#b01030', glow: '#ff2050', tip: '#8a2be2', fx: ['smoke', 'magic'], bg: '#08070a', light: '#8a6bff' },
  water: { body: '#1a6f8f', belly: '#d8f2f2', accent: '#d0d5dc', glow: '#4ee0ff', tip: '#b8ffff', fx: ['magic', 'snow'], bg: '#0a1820', light: '#6fe0ff' },
  none: { body: '#7a5a3a', belly: '#e8d8b8', accent: '#ffc94d', glow: null, tip: '#c8a070', fx: [], bg: '#1e232b', light: '#ffffff' },
};

// So'z boshidan moslik (masalan "qor" so'zi "qora" ichida topilmasin — uning uchun "qor ")
const has = (text, words) => words.find((w) => text.includes(' ' + w));

/** Matnni tahlil qiladi → spetsifikatsiya + "nimani tushundim" ro'yxati */
export function parseDescription(raw) {
  const text = ' ' + String(raw || '').toLowerCase().replace(/[ʻʼ‘’`]/g, "'").replace(/[.,!?;:()"]/g, ' ').replace(/\s+/g, ' ') + ' ';
  const understood = [];
  let type = null;
  for (const [k, words] of Object.entries(TYPES)) {
    const w = has(text, words);
    if (w) {
      type = k;
      understood.push([t('tg.type'), t('tg.t.' + k), w.trim()]);
      break;
    }
  }
  if (!type) {
    type = 'dragon';
    understood.push([t('tg.type'), t('tg.t.dragon'), '—']);
  }
  let element = null;
  for (const [k, words] of Object.entries(ELEMENTS)) {
    const w = has(text, words);
    if (w) {
      element = k;
      understood.push([t('tg.element'), t('tg.e.' + k), w.trim()]);
      break;
    }
  }
  // Ranglar matndagi tartibda
  const found = [];
  for (const [hex, words] of Object.entries(COLORS)) {
    for (const w of words) {
      const i = text.indexOf(' ' + w);
      if (i >= 0 && !(w === 'sariq' && text.includes("to'q sariq"))) {
        found.push({ hex, i, w });
        break;
      }
    }
  }
  found.sort((a, b) => a.i - b.i);
  if (!element) {
    // rangdan stixiyani taxmin qilamiz
    const first = found.find((f) => !METALS.includes(f.hex))?.hex;
    element = { '#b0121c': 'fire', '#ff7a1a': 'fire', '#1d5fd1': 'ice', '#2f8f4e': 'nature', '#6b2fb3': 'magic', '#1c1a1f': 'dark' }[first] || (type === 'phoenix' ? 'fire' : 'none');
    understood.push([t('tg.element'), t('tg.e.' + element), t('tg.guessed')]);
  }
  const d = ELEMENT_DEFAULTS[element] || ELEMENT_DEFAULTS.none;
  const spec = { type, element, body: d.body, belly: d.belly, accent: d.accent, glow: d.glow, tip: d.tip, fx: d.fx, bg: d.bg, light: d.light };
  const bellyWord = /qorni|qorin|belly|живот|брюх/.exec(text);
  for (const f of found) {
    if (METALS.includes(f.hex)) {
      spec.accent = f.hex;
      understood.push([t('tg.accent'), f.w, '']);
    } else if (bellyWord && f.i > bellyWord.index) {
      spec.belly = f.hex;
      understood.push([t('tg.belly'), f.w, '']);
    } else if (!spec._bodySet) {
      spec.body = f.hex;
      spec._bodySet = true;
      understood.push([t('tg.body'), f.w, '']);
    } else {
      spec.tip = f.hex;
      understood.push([t('tg.tip'), f.w, '']);
    }
  }
  delete spec._bodySet;
  // Qismlar
  spec.wings = ['dragon', 'phoenix', 'bird'].includes(type);
  if (has(text, ['qanotsiz', 'wingless', 'без крыл'])) spec.wings = false;
  else if (has(text, ['qanot', 'wing', 'крыл'])) spec.wings = true;
  understood.push([t('tg.wings'), spec.wings ? t('tg.yes') : t('tg.no'), '']);
  const hornWord = has(text, ['shox', 'horn', 'рог']);
  spec.horns = type === 'dragon' || type === 'loong' || type === 'beast' ? 2 : 0;
  if (hornWord) {
    spec.horns = 2;
    const before = text.slice(Math.max(0, text.indexOf(hornWord) - 12), text.indexOf(hornWord));
    for (const [w, nn] of Object.entries(NUMBERS)) if (before.includes(w)) spec.horns = nn;
    const dig = /(\d)\s*$/.exec(before.trim());
    if (dig) spec.horns = Math.min(6, +dig[1]);
  }
  if (has(text, ['shoxsiz', 'hornless', 'без рог'])) spec.horns = 0;
  if (spec.horns) understood.push([t('tg.horns'), String(spec.horns), '']);
  spec.scales = !has(text, ['tangachasiz', 'silliq teri', 'smooth skin']) && (type !== 'human' && type !== 'bird' || !!has(text, ['tangacha', 'scale', 'чешу']));
  spec.armor = !!has(text, ['zirh', 'armor', 'armour', 'брон', 'доспех', 'oltin', 'gold']);
  spec.size = has(text, ['ulkan', 'katta', 'giant', 'huge', 'огромн', 'гигант']) ? 1.3 : has(text, ['kichik', 'kichkina', 'small', 'tiny', 'мален']) ? 0.75 : 1;
  if (spec.size !== 1) understood.push([t('tg.size'), spec.size > 1 ? t('tg.big') : t('tg.small'), '']);
  if (spec.armor) understood.push([t('tg.armor'), t('tg.yes'), '']);
  return { spec, understood };
}

// ============================================================
//  Generator
// ============================================================
const V = (x, y, z) => new THREE.Vector3(x, y, z);

/** Har bir tur uchun skelet (shar/kapsula) va muhim nuqtalar */
function skeleton(E, root, spec) {
  const { add, group } = builder(E);
  const sk = group('Skelet', root);
  const B = spec.body, C = spec.belly;
  const D = '#' + new THREE.Color(B).multiplyScalar(0.65).getHexString();
  const ball = (p, r, color, scale) => add('sphere', { radius: r, widthSegments: 14, heightSegments: 9 }, { parent: sk, pos: p, scale, mat: { color } });
  const caps = (p, r, len, rot, color) => add('capsule', { radius: r, length: len, capSegments: 4, radialSegments: 10 }, { parent: sk, pos: p, rot, mat: { color } });
  const chain = (pts, r0, r1, color, n) => {
    const curve = new THREE.CatmullRomCurve3(pts.map((p) => V(...p)));
    for (let k = 0; k <= n; k++) ball(curve.getPoint(k / n).toArray(), r0 + (r1 - r0) * (k / n), color);
    return curve;
  };
  const a = { feet: [] };
  if (spec.type === 'loong') {
    const pts = [];
    for (let k = 0; k <= 10; k++) {
      const q = k / 10;
      pts.push([-2.6 + q * 6, 1.6 + Math.sin(q * Math.PI * 2.2) * 0.75 + q * 0.3, Math.cos(q * Math.PI * 1.6) * 0.8]);
    }
    const c = chain(pts, 0.36, 0.08, B, 56);
    // qorin chizig'i
    for (let k = 0; k <= 40; k++) {
      const q = k / 40;
      const p = c.getPoint(q);
      ball([p.x, p.y - 0.22 * (1 - q * 0.7), p.z], 0.2 * (1 - q * 0.75), C);
    }
    const h0 = c.getPoint(0);
    ball([h0.x - 0.25, h0.y + 0.15, h0.z], 0.42, B, [1.25, 0.85, 0.9]);
    ball([h0.x - 0.7, h0.y + 0.05, h0.z], 0.24, B, [1.5, 0.7, 0.85]);
    for (const q of [0.12, 0.5]) {
      const p = c.getPoint(q);
      for (const s of [-1, 1]) caps([p.x, p.y - 0.42, p.z + 0.25 * s], 0.1, 0.4, [0.35 * s, 0, 0.15], D);
      a.feet.push([p.x - 0.05, p.y - 0.75, p.z + 0.32], [p.x - 0.05, p.y - 0.75, p.z - 0.32]);
    }
    a.head = [h0.x - 0.3, h0.y + 0.35, h0.z];
    a.snout = [h0.x - 0.95, h0.y + 0.05, h0.z];
    a.tail = c.getPoint(1).toArray();
    a.curve = c;
    a.shoulders = null;
    return { sk, a };
  }
  if (spec.type === 'human') {
    ball([0, 3.35, 0], 0.26, C, [0.9, 1.1, 1]);
    ball([0, 2.55, 0], 0.42, B, [1.0, 0.85, 0.6]);
    ball([0, 2.15, 0], 0.32, B, [0.95, 0.9, 0.6]);
    ball([0, 1.82, 0], 0.36, D, [1.05, 0.7, 0.65]);
    caps([0, 3.0, 0], 0.09, 0.15, [0, 0, 0], C);
    for (const s of [-1, 1]) {
      ball([0.42 * s, 2.72, 0], 0.16, B);
      caps([0.55 * s, 2.4, 0], 0.11, 0.42, [0, 0, 0.15 * s], B);
      caps([0.65 * s, 1.92, 0.05], 0.09, 0.42, [-0.2, 0, 0.08 * s], C);
      caps([0.18 * s, 1.35, 0], 0.16, 0.55, [0, 0, 0.03 * s], D);
      caps([0.2 * s, 0.62, 0], 0.12, 0.55, [0, 0, 0], D);
      caps([0.2 * s, 0.08, 0.1], 0.08, 0.2, [Math.PI / 2, 0, 0], D);
    }
    a.head = [0, 3.62, 0];
    a.snout = [0, 3.35, 0.25];
    a.shoulders = [[0.35, 2.8, -0.2], [-0.35, 2.8, -0.2]];
    a.tail = null;
    a.human = true;
    return { sk, a };
  }
  if (spec.type === 'bird') {
    ball([0, 1.4, 0], 0.55, B, [1.25, 0.9, 0.85]);
    ball([-0.35, 1.35, 0], 0.4, C, [0.8, 0.9, 0.75]);
    chain([[-0.45, 1.7, 0], [-0.6, 2.0, 0], [-0.65, 2.25, 0]], 0.25, 0.2, B, 5);
    ball([-0.72, 2.4, 0], 0.24, B);
    for (const s of [-1, 1]) caps([0.05, 0.75, 0.22 * s], 0.06, 0.55, [0, 0, 0.1], spec.accent);
    a.head = [-0.72, 2.62, 0];
    a.snout = [-0.95, 2.38, 0];
    a.shoulders = [[-0.15, 1.75, 0.4], [-0.15, 1.75, -0.4]];
    a.tail = [0.75, 1.4, 0];
    a.feet = [[-0.12, 0.45, 0.22], [-0.12, 0.45, -0.22]];
    return { sk, a };
  }
  // dragon / phoenix / beast — to'rt oyoqli
  const beast = spec.type === 'beast';
  ball([0.2, 1.75, 0], 1, B, [1.3, beast ? 0.72 : 0.78, beast ? 0.7 : 0.76]);
  ball([-0.78, 1.7, 0], 1, C, [0.6, 0.8, 0.52]);
  ball([0.1, 1.33, 0], 1, C, [1.0, 0.42, 0.58]);
  ball([1.15, 1.62, 0], 1, B, [0.72, 0.68, 0.68]);
  const neck = beast ? [[-1.0, 2.15, 0.46], [-1.3, 2.4, 0.4], [-1.5, 2.6, 0.36]] : [[-1.0, 2.3, 0.46], [-1.22, 2.62, 0.4], [-1.38, 2.9, 0.35], [-1.48, 3.12, 0.32]];
  neck.forEach(([x, y, r]) => ball([x + 0.06, y, 0], r, B));
  neck.forEach(([x, y, r]) => ball([x - 0.2, y - 0.05, 0], r * 0.66, C, [1, 1, 0.7]));
  const hy = beast ? 2.85 : 3.32;
  ball([-1.62, hy, 0], beast ? 0.42 : 0.36, B, [1.15, 0.92, 0.9]);
  ball([-1.9, hy - 0.1, 0], beast ? 0.26 : 0.22, B, [1.25, 0.8, 0.85]);
  for (const z of [-1, 1]) ball([-1.72, hy - 0.16, 0.15 * z], 0.17, C);
  for (const [x, z] of [[-0.68, 0.55], [-0.68, -0.55], [1.12, 0.58], [1.12, -0.58]]) {
    const zs = Math.sign(z);
    caps([x, 1.12, z], 0.28, 0.5, [0.18 * zs, 0, x < 0 ? 0.15 : -0.2], B);
    caps([x + (x < 0 ? -0.05 : 0.12), 0.55, z * 1.12], 0.17, 0.48, [0.12 * zs, 0, x < 0 ? -0.1 : 0.35], D);
    caps([x - 0.1, 0.13, z * 1.18], 0.12, 0.3, [0, 0, Math.PI / 2], D);
    a.feet.push([x - 0.3, 0.1, z * 1.18]);
  }
  const tail = beast
    ? [[1.5, 1.7, 0], [2.1, 1.9, 0], [2.6, 2.3, 0], [2.9, 2.6, 0]]
    : [[1.5, 1.6, 0], [2.2, 1.3, 0.05], [2.85, 1.35, 0.1], [3.3, 1.85, 0.08], [3.45, 2.5, 0], [3.25, 3.05, -0.05], [2.95, 3.25, -0.05]];
  const tc = chain(tail, beast ? 0.2 : 0.42, beast ? 0.12 : 0.1, B, beast ? 14 : 34);
  a.head = [-1.6, hy + 0.3, 0];
  a.snout = [-2.0, hy - 0.1, 0];
  a.shoulders = [[-0.3, 2.35, 0.55], [-0.3, 2.35, -0.55]];
  a.tail = tc.getPoint(1).toArray();
  a.tailCurve = tc;
  return { sk, a };
}

/** Matn tavsifidan to'liq sahna yasaydi */
export function buildCreature(E, spec, { resolution = 100 } = {}) {
  const { add, group, fx, light } = builder(E);
  const root = group(t('tg.t.' + spec.type) + (spec.element !== 'none' ? ' · ' + t('tg.e.' + spec.element) : ''));
  root.userData.motion = { type: 'bob', axis: 'y', amount: 0.03, speed: 0.5, phase: 0 };
  const { sk, a } = skeleton(E, root, spec);

  // Organik tana + tangacha
  const geo = organicMesh(sk, { resolution, blend: spec.type === 'loong' ? 0.18 : 0.22 });
  const mat = new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.48, metalness: 0.15, envMapIntensity: 0.6 });
  if (spec.scales) applyPattern(mat, 'scales', { repeat: 1.3, glow: spec.glow, glowIntensity: spec.glow ? 0.8 : 0 });
  const skin = new THREE.Mesh(geo, mat);
  skin.name = t('tg.body');
  skin.userData = { prim: 'custom', sculpt: true };
  if (spec.type === 'loong') skin.userData.motion = { type: 'wave', axis: 'z', amount: 0.08, speed: 0.5, phase: 0 };
  skin.castShadow = skin.receiveShadow = true;
  E.addObject(skin, { parent: root, select: false, commit: false });
  sk.visible = false;

  const gold = { color: spec.accent, metal: 1, rough: 0.22 };
  const featherMat = (m, glow) => {
    m.material.color.set('#ffffff');
    m.material.side = THREE.DoubleSide;
    m.material.roughness = 0.55;
    applyPattern(m.material, 'feather', { repeat: 1, glow: spec.glow || null, glowIntensity: glow });
  };
  const feather = (parent, base, dir, normal, len, width, cBase, cTip, bend = 0.12, glow = 1.2) => {
    const m = add('feather', { length: len, width, bend, barbs: 6, thickness: 0.015, colorBase: cBase, colorTip: cTip }, { name: t('prim.feather'), parent, base, dir, len: 0, planeNormal: normal });
    m.position.set(...base);
    featherMat(m, glow);
    return m;
  };
  const horn = (parent, base, dir, normal, length, radius, bend, m = gold, name = t('prim.horn')) => {
    const o = add('horn', { length, radius, bend, tip: 0.04, segments: 14, sides: 10 }, { name, parent, base, dir, len: 0, planeNormal: normal, mat: m });
    o.position.set(...base);
    return o;
  };

  // Ko'zlar
  const eyeCol = spec.glow || '#ffd000';
  const hx = a.snout[0], hy = a.snout[1];
  if (!a.human) {
    for (const z of [-1, 1]) add('sphere', { radius: 0.065, widthSegments: 12, heightSegments: 8 }, { name: t('tg.eye'), parent: root, pos: [hx + 0.12, hy + 0.17, (a.snout[2] || 0) + 0.17 * z], scale: [0.7, 1, 1], mat: { color: '#fff1a0', emissive: eyeCol, ei: 4 } });
  }
  // Shoxlar
  const hornsG = group(t('tg.horns'), root);
  for (let k = 0; k < spec.horns; k++) {
    const s = k % 2 ? -1 : 1, row = Math.floor(k / 2);
    horn(hornsG, [a.head[0] + 0.05 + row * 0.15, a.head[1] - row * 0.05, (a.head[2] || 0) + 0.16 * s], [0.7, 1, 0.45 * s], [0, 0, 1], 0.75 - row * 0.2, 0.075, 70);
  }
  // Tumshuq / jag' bezagi
  if (spec.type === 'phoenix' || spec.type === 'bird') horn(root, a.snout, [-1, -0.2, 0], [0, 0, 1], 0.42, 0.1, -55, gold, t('tg.beak'));
  // Yol (bosh va bo'yin)
  if (spec.type === 'phoenix' || spec.type === 'loong' || spec.element === 'fire') {
    const mane = group(t('tg.mane'), root);
    const n = spec.type === 'loong' ? 9 : 11;
    for (let k = 0; k < n; k++) {
      const q = (k / (n - 1) - 0.5) * 1.6;
      feather(mane, [a.head[0] + Math.abs(q) * 0.1, a.head[1] - 0.05, (a.head[2] || 0) + q * 0.18], [0.55 + Math.abs(q) * 0.3, 1, q * 0.9], [-Math.sin(q), 0, Math.cos(q)], 0.7 + Math.cos(q) * 0.5, 0.25, spec.body, spec.tip, 0.2, 1.5);
    }
  }
  // Uzun ajdar: tana bo'ylab yol
  if (spec.type === 'loong' && a.curve) {
    const back = group(t('tg.mane'), root);
    for (let k = 1; k < 30; k++) {
      const q = k / 30, p = a.curve.getPoint(q), tan = a.curve.getTangent(q);
      feather(back, [p.x, p.y + 0.28 * (1 - q * 0.7), p.z], [tan.x * 0.6, 1, tan.z * 0.6], [-tan.z, 0, tan.x], 0.45 * (1 - q * 0.5), 0.2, spec.body, spec.tip, 0.3, 1.3);
    }
  }
  // Tirnoqlar
  for (const f of a.feet) for (const cz of [-0.1, 0, 0.1]) horn(root, [f[0], f[1], f[2] + cz], [-1, 0.25, cz * 2], [0, 0, 1], 0.26, 0.05, -80, gold, t('tg.claw'));
  // Zirh
  if (spec.armor && a.shoulders) {
    for (const sh of a.shoulders) for (let k = 0; k < 4; k++) horn(root, [sh[0] - 0.2 + k * 0.12, sh[1] - 0.2 - k * 0.1, sh[2] * 1.12], [0.7 + k * 0.2, 1 - k * 0.15, 0.55 * Math.sign(sh[2])], [0, 0, Math.sign(sh[2])], 0.5 - k * 0.07, 0.08, 45, gold, t('tg.armor'));
  }
  // Qanotlar
  if (spec.wings && a.shoulders) {
    for (const sh of a.shoulders) {
      const z = Math.sign(sh[2]);
      const wing = group(z > 0 ? t('tg.wingR') : t('tg.wingL'), root, sh);
      wing.userData.motion = { type: 'flap', axis: 'x', amount: 16, speed: 0.7, phase: z > 0 ? 0 : 180 };
      const U = V(0.45, 1, 0.75 * z).normalize(), W = V(1, -0.15, 0.45 * z).normalize();
      const N = new THREE.Vector3().crossVectors(U, W).normalize().multiplyScalar(z);
      const L = (a.human ? 1.5 : 2.1) * spec.size;
      horn(wing, [0, 0, 0], U.toArray(), N.toArray(), L * 0.9, 0.1, -25, { color: spec.body, rough: 0.45, metal: 0.2 }, t('tg.wingBone'));
      const rows = [
        { n: 16, k: 1, w: 0.34, cb: spec.body, ct: spec.tip, off: 0, glow: 1.5 },
        { n: 13, k: 0.62, w: 0.3, cb: spec.body, ct: spec.glow || spec.tip, off: 0.04, glow: 1 },
        { n: 10, k: 0.36, w: 0.26, cb: spec.body, ct: spec.accent, off: 0.08, glow: 0.6 },
      ];
      for (const r of rows) {
        for (let k = 0; k < r.n; k++) {
          const s = (k + 0.5) / r.n;
          const base = U.clone().multiplyScalar(s * L * 0.98).addScaledVector(N, r.off);
          const dir = W.clone().multiplyScalar(1 - s * 0.55).add(U.clone().multiplyScalar(s * 0.85 + 0.05)).normalize();
          feather(wing, base.toArray(), dir.toArray(), N.toArray(), (1 + 1.7 * s ** 1.2) * r.k * (L / 2.1), r.w, r.cb, r.ct, 0.1, r.glow);
        }
      }
      if (spec.fx[0]) fx(spec.fx[0], t('fx.' + spec.fx[0]), wing, U.clone().multiplyScalar(L).add(W.clone().multiplyScalar(1.3)).toArray(), fxParams(spec, spec.fx[0], 0.7));
    }
  }
  // Dum uchi
  if (a.tail && spec.type !== 'human') {
    const tg = group(t('tg.tail'), root, a.tail);
    tg.userData.motion = { type: 'sway', axis: 'y', amount: 10, speed: 0.45, phase: 0 };
    const n = spec.type === 'beast' ? 5 : 7;
    for (let k = 0; k < n; k++) {
      const q = (k / (n - 1) - 0.5) * 2.2;
      feather(tg, [0, 0, 0], [0.4 + Math.sin(q) * 0.4, 1, Math.cos(q) * 0.9], [Math.cos(q), 0, -Math.sin(q)], (spec.type === 'beast' ? 0.7 : 1.2) + Math.cos(q) * 0.4, 0.38, spec.body, spec.tip, 0.35, 1.6);
    }
    if (spec.fx[0]) fx(spec.fx[0], t('fx.' + spec.fx[0]), tg, [0, 0.1, 0], fxParams(spec, spec.fx[0], 1));
  }
  // Atrof
  if (spec.fx[1]) fx(spec.fx[1], t('fx.' + spec.fx[1]), root, [0.5, 0.3, 0], { ...fxParams(spec, spec.fx[1], 1), radius: 2.8 });
  const floor = add('plane', { width: 24, height: 24, segments: 1 }, { name: t('tg.floor'), mat: { color: '#060505', rough: 0.85, metal: 0 } });
  floor.material.envMapIntensity = 0.05;
  light('PointLight', t('tg.light'), [0.2, 3.4, 0], spec.light, 12);
  light('PointLight', t('ai.rim'), [2.5, 4, -2.5], spec.light, 18);
  light('PointLight', t('tg.keyLight'), [-3, 2.5, 2.5], '#fff0dc', 10);
  root.scale.setScalar(spec.size);

  Object.assign(E.sceneSettings, {
    background: spec.bg, grid: false, axes: false, studio: true, studioIntensity: 0.6, env: true, envIntensity: 0.7, exposure: 1.0,
    bloom: true, bloomStrength: 0.75, bloomRadius: 0.45, bloomThreshold: 1.0, fog: true, shadows: true, ground: false,
    anim: { duration: 6, fps: 30, loop: true },
  });
  E.applySceneSettings();
  return root;
}

function fxParams(spec, type, k) {
  const c = spec.glow || spec.tip;
  switch (type) {
    case 'fire': return { rate: 90 * k, size: 0.45 * k, speed: 1.2, radius: 0.3 * k, opacity: 0.7 };
    case 'sparks': return { rate: 30, size: 0.06, speed: 1, radius: 1.2, c1: c };
    case 'snow': return { radius: 4, rate: 50 };
    case 'smoke': return { radius: 2, size: 1.8, opacity: 0.25, rate: 8, speed: 0.25 };
    case 'energy': return { c1: c, radius: 0.5 * k, rate: 80 * k };
    case 'magic': return { c1: c, radius: 0.8 * k, rate: 60 * k };
    default: return {};
  }
}
