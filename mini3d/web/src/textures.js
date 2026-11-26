// ============================================================
//  PROTSEDURAL TEKSTURALAR (rasm faylisiz, kod bilan chiziladi)
//  - scales: ajdar/baliq tangachalari (+ yonib turgan chetlar)
//  - lava:   yoriqlar (voronoi) — ichidan nur sochadi
//  - feather: pat tolalari
//  Har biri: { map (oq-kulrang naqsh), emissiveMap (yonuvchi chiziqlar), bumpMap }
//  Yangi naqsh: patterns ga funksiya qo'shing.
// ============================================================
import * as THREE from 'three';

const SIZE = 512;

function canvas() {
  const c = document.createElement('canvas');
  c.width = c.height = SIZE;
  return c;
}

function tex(c, srgb = true) {
  const t = new THREE.CanvasTexture(c);
  t.wrapS = t.wrapT = THREE.RepeatWrapping;
  if (srgb) t.colorSpace = THREE.SRGBColorSpace;
  t.anisotropy = 4;
  return t;
}

function rng(seed) {
  let s = seed || 1;
  return () => ((s = (s * 16807) % 2147483647) / 2147483647);
}

// ---------- Tangachalar ----------
function scales(opts = {}) {
  const rows = opts.rows || 8;
  const cols = rows;
  const w = SIZE / cols, h = SIZE / rows;
  const albedo = canvas(), glow = canvas(), bump = canvas();
  const A = albedo.getContext('2d'), G = glow.getContext('2d'), B = bump.getContext('2d');
  A.fillStyle = '#5a5a5a'; A.fillRect(0, 0, SIZE, SIZE);
  G.fillStyle = '#000'; G.fillRect(0, 0, SIZE, SIZE);
  B.fillStyle = '#000'; B.fillRect(0, 0, SIZE, SIZE);
  const r = rng(7);
  // Qatorlar aniq h oralig'ida — tekstura choksiz takrorlanadi (rows juft bo'lishi kerak)
  for (let row = -2; row <= rows + 1; row++) {
    for (let col = -1; col <= cols; col++) {
      const cx = (col + (((row % 2) + 2) % 2 ? 0.5 : 0)) * w;
      const cy = row * h;
      const rw = w * 0.56, rh = h * 1.35;
      const shape = (ctx) => {
        ctx.beginPath();
        ctx.moveTo(cx - rw, cy);
        ctx.quadraticCurveTo(cx - rw, cy + rh * 0.9, cx, cy + rh);
        ctx.quadraticCurveTo(cx + rw, cy + rh * 0.9, cx + rw, cy);
        ctx.closePath();
      };
      const jitter = 0.85 + r() * 0.15;
      const v = (k) => Math.round(k * jitter);
      let gr = A.createRadialGradient(cx, cy + rh * 0.35, 1, cx, cy + rh * 0.4, rh);
      gr.addColorStop(0, `rgb(${v(255)},${v(255)},${v(255)})`);
      gr.addColorStop(0.65, `rgb(${v(215)},${v(215)},${v(215)})`);
      gr.addColorStop(1, 'rgb(90,90,90)');
      shape(A); A.fillStyle = gr; A.fill();
      A.lineWidth = 2.5; A.strokeStyle = 'rgba(25,25,25,0.85)'; A.stroke();
      gr = B.createRadialGradient(cx, cy + rh * 0.3, 1, cx, cy + rh * 0.4, rh);
      gr.addColorStop(0, '#fff'); gr.addColorStop(0.8, '#999'); gr.addColorStop(1, '#222');
      shape(B); B.fillStyle = gr; B.fill();
      shape(G); G.fillStyle = '#000'; G.fill();
      G.lineWidth = 3; G.strokeStyle = `rgba(255,${Math.round(110 + r() * 70)},30,${0.5 + r() * 0.5})`; G.stroke();
    }
  }
  return { map: tex(albedo), emissiveMap: tex(glow), bumpMap: tex(bump, false), bumpScale: 2.5 };
}

// ---------- Lava yoriqlari (Voronoi) ----------
function lava(opts = {}) {
  const n = opts.cells || 40;
  const r = rng(13);
  const pts = [];
  for (let i = 0; i < n; i++) pts.push([r() * SIZE, r() * SIZE]);
  const albedo = canvas(), glow = canvas(), bump = canvas();
  const A = albedo.getContext('2d'), G = glow.getContext('2d'), B = bump.getContext('2d');
  const ia = A.createImageData(SIZE, SIZE), ig = G.createImageData(SIZE, SIZE), ib = B.createImageData(SIZE, SIZE);
  for (let y = 0; y < SIZE; y++) {
    for (let x = 0; x < SIZE; x++) {
      let d1 = 1e9, d2 = 1e9;
      for (const [px, py] of pts) {
        for (const ox of [-SIZE, 0, SIZE]) {
          for (const oy of [-SIZE, 0, SIZE]) {
            const dx = x - px - ox, dy = y - py - oy;
            const d = dx * dx + dy * dy;
            if (d < d1) { d2 = d1; d1 = d; } else if (d < d2) d2 = d;
          }
        }
      }
      const edge = Math.sqrt(d2) - Math.sqrt(d1); // chegaraga masofa
      const k = (y * SIZE + x) * 4;
      const crack = Math.max(0, 1 - edge / 6);
      const v = Math.round(90 + Math.min(1, edge / 40) * 140 - crack * 80);
      ia.data[k] = ia.data[k + 1] = ia.data[k + 2] = v; ia.data[k + 3] = 255;
      ig.data[k] = Math.round(255 * crack); ig.data[k + 1] = Math.round(140 * crack * crack); ig.data[k + 2] = Math.round(30 * crack); ig.data[k + 3] = 255;
      const hgt = Math.round(255 * Math.min(1, edge / 25));
      ib.data[k] = ib.data[k + 1] = ib.data[k + 2] = hgt; ib.data[k + 3] = 255;
    }
  }
  A.putImageData(ia, 0, 0); G.putImageData(ig, 0, 0); B.putImageData(ib, 0, 0);
  return { map: tex(albedo), emissiveMap: tex(glow), bumpMap: tex(bump, false), bumpScale: 3 };
}

// ---------- Pat tolalari ----------
function feather() {
  const albedo = canvas(), glow = canvas(), bump = canvas();
  const A = albedo.getContext('2d'), G = glow.getContext('2d'), B = bump.getContext('2d');
  // Pat: v — uzunlik bo'ylab (pastdan yuqoriga), u — ko'ndalang
  const g = A.createLinearGradient(0, SIZE, 0, 0);
  g.addColorStop(0, '#9a9a9a'); g.addColorStop(1, '#ffffff');
  A.fillStyle = g; A.fillRect(0, 0, SIZE, SIZE);
  G.fillStyle = '#000'; G.fillRect(0, 0, SIZE, SIZE);
  B.fillStyle = '#808080'; B.fillRect(0, 0, SIZE, SIZE);
  const r = rng(3);
  for (let i = 0; i < 160; i++) {
    const y = (i / 160) * SIZE * 1.3 - SIZE * 0.15;
    for (const side of [-1, 1]) {
      A.strokeStyle = `rgba(40,40,40,${0.25 + r() * 0.2})`; A.lineWidth = 1.5;
      A.beginPath(); A.moveTo(SIZE / 2, y); A.quadraticCurveTo(SIZE / 2 + side * SIZE * 0.25, y - 20, SIZE / 2 + side * SIZE * 0.5, y - 60); A.stroke();
      B.strokeStyle = 'rgba(255,255,255,0.35)'; B.lineWidth = 2;
      B.beginPath(); B.moveTo(SIZE / 2, y); B.quadraticCurveTo(SIZE / 2 + side * SIZE * 0.25, y - 20, SIZE / 2 + side * SIZE * 0.5, y - 60); B.stroke();
    }
  }
  // o'zak (rachis)
  A.fillStyle = 'rgba(255,255,255,0.9)'; A.fillRect(SIZE / 2 - 3, 0, 6, SIZE);
  // uchiga yaqin nur
  const gg = G.createLinearGradient(0, SIZE, 0, 0);
  gg.addColorStop(0, '#000'); gg.addColorStop(0.55, '#200800'); gg.addColorStop(1, '#ffb040');
  G.fillStyle = gg; G.fillRect(0, 0, SIZE, SIZE);
  return { map: tex(albedo), emissiveMap: tex(glow), bumpMap: tex(bump, false), bumpScale: 1.5 };
}

export const patterns = {
  scales: { label: 'pat.scales', make: scales },
  lava: { label: 'pat.lava', make: lava },
  feather: { label: 'pat.feather', make: feather },
};

const cache = new Map();
/** Naqshni materialga qo'llaydi. glow — chiziqlar rangi (yoki null) */
export function applyPattern(mat, id, { repeat = 4, glow = '#ff5a10', glowIntensity = 1.5 } = {}) {
  for (const k of ['map', 'emissiveMap', 'bumpMap']) {
    if (mat[k] && mat[k].userData?.pattern) mat[k] = null;
  }
  if (!id) {
    mat.needsUpdate = true;
    return;
  }
  let set = cache.get(id);
  if (!set) {
    set = patterns[id].make();
    cache.set(id, set);
  }
  const clone = (t) => {
    const c = t.clone();
    c.repeat.set(repeat, repeat);
    c.userData = { pattern: id };
    c.needsUpdate = true;
    return c;
  };
  if ('map' in mat) mat.map = clone(set.map);
  if ('bumpMap' in mat) {
    mat.bumpMap = clone(set.bumpMap);
    mat.bumpScale = set.bumpScale;
  }
  if ('emissiveMap' in mat && glow) {
    mat.emissiveMap = clone(set.emissiveMap);
    mat.emissive.set(glow);
    mat.emissiveIntensity = glowIntensity;
  }
  mat.needsUpdate = true;
}
