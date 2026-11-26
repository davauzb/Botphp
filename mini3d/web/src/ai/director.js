// ============================================================
//  AI REJISSYOR
//  Rasmni tahlil qiladi va sahnani o'zi sozlaydi:
//   - Asosiy ranglar (k-means klasterlash)
//   - Mavzuni aniqlash: olov, muz, tabiat, sehr, elektr, qorong'ulik, metall
//   - "Issiq nuqtalar": rasmdagi eng yorqin joylar → shu joylarga effektlar
//   - Fon, yorug'lik, nur taratish (bloom), animatsiya, kamera
//  Har bir qaror sababi bilan yoziladi (tushuntiriladigan AI).
// ============================================================
import * as THREE from 'three';
import { effectDefaults } from '../effects.js';
import { t } from '../i18n.js';

function rgbToHsl(r, g, b) {
  const mx = Math.max(r, g, b), mn = Math.min(r, g, b);
  let h = 0, s = 0;
  const l = (mx + mn) / 2;
  if (mx !== mn) {
    const d = mx - mn;
    s = l > 0.5 ? d / (2 - mx - mn) : d / (mx + mn);
    if (mx === r) h = (g - b) / d + (g < b ? 6 : 0);
    else if (mx === g) h = (b - r) / d + 2;
    else h = (r - g) / d + 4;
    h *= 60;
  }
  return [h, s, l];
}

/** k-means (k ta asosiy rang) */
function kmeans(pixels, k = 5, iters = 8) {
  const n = pixels.length / 3;
  const cent = [];
  for (let i = 0; i < k; i++) {
    const j = Math.floor(((i + 0.5) / k) * n) * 3;
    cent.push([pixels[j], pixels[j + 1], pixels[j + 2]]);
  }
  const assign = new Uint8Array(n);
  for (let it = 0; it < iters; it++) {
    const sum = cent.map(() => [0, 0, 0, 0]);
    for (let p = 0; p < n; p++) {
      let best = 0, bd = 1e9;
      for (let c = 0; c < k; c++) {
        const dr = pixels[p * 3] - cent[c][0], dg = pixels[p * 3 + 1] - cent[c][1], db = pixels[p * 3 + 2] - cent[c][2];
        const d = dr * dr + dg * dg + db * db;
        if (d < bd) {
          bd = d;
          best = c;
        }
      }
      assign[p] = best;
      const s = sum[best];
      s[0] += pixels[p * 3]; s[1] += pixels[p * 3 + 1]; s[2] += pixels[p * 3 + 2]; s[3]++;
    }
    for (let c = 0; c < k; c++) if (sum[c][3]) cent[c] = [sum[c][0] / sum[c][3], sum[c][1] / sum[c][3], sum[c][2] / sum[c][3]];
  }
  const counts = new Array(k).fill(0);
  for (let p = 0; p < n; p++) counts[assign[p]]++;
  return cent.map((c, i) => ({ rgb: c, share: counts[i] / n })).sort((a, b) => b.share - a.share);
}

const hex = (rgb) => '#' + rgb.map((v) => Math.round(Math.max(0, Math.min(1, v)) * 255).toString(16).padStart(2, '0')).join('');

/**
 * Rasmni tahlil qiladi.
 * @param canvas  rasm
 * @param maskInfo { mask, gw, gh } — obyekt niqobi (ixtiyoriy)
 */
export function analyzeImage(canvas, maskInfo) {
  const W = 96, H = Math.max(1, Math.round((96 * canvas.height) / canvas.width));
  const c = document.createElement('canvas');
  c.width = W;
  c.height = H;
  c.getContext('2d').drawImage(canvas, 0, 0, W, H);
  const data = c.getContext('2d').getImageData(0, 0, W, H).data;
  const inMask = (x, y) => {
    if (!maskInfo) return true;
    const mx = Math.min(maskInfo.gw - 1, Math.round((x / (W - 1)) * (maskInfo.gw - 1)));
    const my = Math.min(maskInfo.gh - 1, Math.round((y / (H - 1)) * (maskInfo.gh - 1)));
    return !!maskInfo.mask[my * maskInfo.gw + mx];
  };
  const fg = [], bg = [];
  const hues = { fire: 0, ice: 0, nature: 0, magic: 0, electric: 0, dark: 0, metal: 0 };
  let total = 0, lumSum = 0, satSum = 0;
  const hot = []; // yorqin nuqtalar
  for (let y = 0; y < H; y++) {
    for (let x = 0; x < W; x++) {
      const i = (y * W + x) * 4;
      const r = data[i] / 255, g = data[i + 1] / 255, b = data[i + 2] / 255;
      const target = inMask(x, y) ? fg : bg;
      target.push(r, g, b);
      if (target !== fg) continue;
      const [h, s, l] = rgbToHsl(r, g, b);
      total++;
      lumSum += l;
      satSum += s;
      if (l < 0.16) hues.dark++;
      else if (s < 0.15) hues.metal++;
      else if (h < 50 || h >= 340) hues.fire += l > 0.35 ? 1.2 : 0.6;
      else if (h < 75) hues.fire += 0.8; // sariq
      else if (h < 165) hues.nature++;
      else if (h < 200) hues.ice++;
      else if (h < 250) (l > 0.55 ? (hues.electric += 1) : (hues.ice += 0.8));
      else hues.magic++;
      if (l > 0.62 && s > 0.35) hot.push({ x: x / (W - 1), y: y / (H - 1), l });
    }
  }
  const palette = kmeans(new Float32Array(fg.length ? fg : bg), 5);
  const bgPal = bg.length > 30 ? kmeans(new Float32Array(bg), 3) : [{ rgb: [0.1, 0.1, 0.12], share: 1 }];
  const hsum = Object.values(hues).reduce((a, b) => a + b, 0) || 1;
  const scores = Object.fromEntries(Object.entries(hues).map(([k, v]) => [k, v / hsum]));
  const theme = Object.entries(scores).sort((a, b) => b[1] - a[1])[0][0];
  const hotSpots = clusterHot(hot, 5);
  return {
    palette: palette.map((p) => ({ color: hex(p.rgb), share: p.share })),
    background: hex(bgPal[0].rgb.map((v) => v * 0.6)),
    brightness: lumSum / Math.max(1, total),
    saturation: satSum / Math.max(1, total),
    scores,
    theme,
    hotSpots,
    hotRatio: hot.length / Math.max(1, total),
  };
}

/** Yorqin nuqtalarni guruhlash (oddiy grid-klaster) */
function clusterHot(points, max) {
  const cells = new Map();
  for (const p of points) {
    const key = `${Math.floor(p.x * 6)},${Math.floor(p.y * 6)}`;
    const c = cells.get(key) || { x: 0, y: 0, n: 0 };
    c.x += p.x;
    c.y += p.y;
    c.n++;
    cells.set(key, c);
  }
  return [...cells.values()]
    .filter((c) => c.n >= 3)
    .sort((a, b) => b.n - a.n)
    .slice(0, max)
    .map((c) => ({ x: c.x / c.n, y: c.y / c.n, weight: c.n }));
}

// Mavzuga mos sozlamalar
const THEMES = {
  fire: { fx: ['fire', 'sparks'], light: '#ff7a2a', bloom: 0.9, glow: 1.4, ambient: 'smoke', motion: 'wave', emoji: '🔥' },
  ice: { fx: ['snow', 'magic'], light: '#9fd8ff', bloom: 0.6, glow: 0.8, ambient: 'snow', motion: 'bob', emoji: '❄️' },
  nature: { fx: ['magic'], light: '#c8ffb0', bloom: 0.4, glow: 0.5, ambient: null, motion: 'wave', emoji: '🌿' },
  magic: { fx: ['magic', 'energy'], light: '#c08cff', bloom: 0.9, glow: 1.1, ambient: null, motion: 'bob', emoji: '✨' },
  electric: { fx: ['energy', 'sparks'], light: '#7cc8ff', bloom: 1.0, glow: 1.2, ambient: null, motion: 'wave', emoji: '⚡' },
  dark: { fx: ['smoke', 'magic'], light: '#8a6bff', bloom: 0.6, glow: 0.9, ambient: 'smoke', motion: 'bob', emoji: '🌑' },
  metal: { fx: ['sparks'], light: '#ffffff', bloom: 0.4, glow: 0.5, ambient: null, motion: 'bob', emoji: '⚙️' },
};

/**
 * Tahlil asosida reja tuzadi.
 * @returns {{ theme, steps: string[], scene, effects: [], motion, glow }}
 */
export function makePlan(analysis) {
  const th = THEMES[analysis.theme] || THEMES.metal;
  const steps = [];
  const pct = (v) => Math.round(v * 100) + '%';
  steps.push(`${th.emoji} ${t('ai.themeFound')}: <b>${t('ai.theme.' + analysis.theme)}</b> (${pct(analysis.scores[analysis.theme])})`);
  steps.push(`🎨 ${t('ai.palette')}: ${analysis.palette.map((p) => `<span class="ai-sw" style="background:${p.color}"></span>`).join('')}`);
  const main = analysis.palette[0].color, accent = analysis.palette.find((p) => p.color !== main)?.color || main;
  const effects = [];
  const hs = analysis.hotSpots.length ? analysis.hotSpots : [{ x: 0.5, y: 0.3, weight: 1 }];
  if (analysis.hotRatio > 0.04 || analysis.theme === 'fire') {
    for (const p of hs.slice(0, 4)) effects.push({ type: th.fx[0], at: p, scale: 0.6 + Math.min(1, p.weight / 40) * 0.6 });
    steps.push(`📍 ${t('ai.hotSpots')}: ${hs.length} → ${t('fx.' + th.fx[0])}`);
  } else {
    effects.push({ type: th.fx[0], at: { x: 0.5, y: 0.85 }, scale: 1 });
  }
  if (th.fx[1]) {
    effects.push({ type: th.fx[1], at: { x: 0.5, y: 0.6 }, scale: 1.4, wide: true });
    steps.push(`✨ ${t('ai.extraFx')}: ${t('fx.' + th.fx[1])}`);
  }
  if (th.ambient) {
    effects.push({ type: th.ambient, at: { x: 0.5, y: 1 }, scale: 2, wide: true, ambient: true });
    steps.push(`🌫 ${t('ai.ambient')}: ${t('fx.' + th.ambient)}`);
  }
  const glow = th.glow * (analysis.brightness > 0.5 ? 0.7 : 1);
  steps.push(`💡 ${t('ai.lightPlan')}: ${t('ai.rim')} <span class="ai-sw" style="background:${th.light}"></span>, bloom ${th.bloom}`);
  steps.push(`🎬 ${t('ai.motionPlan')}: ${t('motion.' + th.motion)}`);
  return {
    theme: analysis.theme,
    steps,
    glow,
    motion: th.motion,
    light: th.light,
    main,
    accent,
    effects,
    scene: {
      background: analysis.background,
      grid: false,
      axes: false,
      bloom: true,
      bloomStrength: th.bloom,
      bloomThreshold: 0.9,
      bloomRadius: 0.45,
      studio: true,
      studioIntensity: 0.9,
      env: true,
      envIntensity: 0.6,
      shadows: true,
      ground: true,
      fog: !!th.ambient,
      anim: { duration: 6, fps: 30, loop: true },
    },
  };
}

/**
 * Rejani sahnaga qo'llaydi.
 * @param root  yaratilgan model guruhi (lokal: x ∈ [-W/2, W/2], y ∈ [0, H])
 * @param box   { W, H, depth } — modelning o'lchami
 */
export function applyPlan(editor, root, plan, box) {
  const E = editor;
  Object.assign(E.sceneSettings, plan.scene);
  E.applySceneSettings();
  for (const f of plan.effects) {
    const g = E.effects.create(f.type);
    g.name = E.uniqueName(t('fx.' + f.type) + ' (AI)');
    const p = { ...effectDefaults(f.type) };
    if (f.type === 'fire') Object.assign(p, { c0: '#fff2b0', c1: plan.light, c2: '#8a0e04', size: 0.22 * f.scale, radius: 0.12 * f.scale, rate: 45 * f.scale, opacity: 0.55, speed: 1.1 });
    if (f.type === 'sparks') Object.assign(p, { radius: box.W * 0.35, rate: 25, size: 0.06, c1: plan.light });
    if (f.type === 'magic' || f.type === 'energy') Object.assign(p, { c1: plan.accent, radius: f.wide ? box.W * 0.35 : 0.4 });
    if (f.type === 'snow') Object.assign(p, { radius: box.W * 0.8 });
    if (f.type === 'smoke') Object.assign(p, { radius: box.W * 0.4, size: 1.8, opacity: 0.18, rate: 8, speed: 0.25 });
    g.userData.effect.params = p;
    const x = (f.at.x - 0.5) * box.W;
    const y = f.ambient ? 0 : (1 - f.at.y) * box.H;
    g.position.set(x, y, f.ambient ? 0 : box.depth * 0.3);
    E.addObject(g, { parent: f.ambient ? E.content : root, select: false, commit: false });
  }
  // Yorug'lik: orqadan rang (rim), oldidan yumshoq
  const rim = E.createLight('PointLight');
  rim.name = 'AI orqa nur';
  rim.color.set(plan.light);
  rim.intensity = 25;
  rim.position.set(box.W * 0.3, box.H * 0.9, -box.depth - 1.5);
  rim.castShadow = false;
  E.addObject(rim, { select: false, commit: false });
  const key = E.createLight('PointLight');
  key.name = 'AI old nur';
  key.color.set('#fff1e0');
  key.intensity = 12;
  key.position.set(-box.W * 0.4, box.H * 0.8, box.W * 0.7);
  key.castShadow = false;
  E.addObject(key, { select: false, commit: false });
  // Harakat
  root.userData.motion =
    plan.motion === 'wave'
      ? { type: 'wave', axis: 'z', amount: 0.12, speed: 0.6, phase: 0 }
      : { type: 'bob', axis: 'y', amount: 0.08, speed: 0.5, phase: 0 };
}

export { THREE };
