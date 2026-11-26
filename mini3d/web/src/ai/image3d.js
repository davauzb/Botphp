// ============================================================
//  RASMDAN 3D (telefonda, AI chuqurlik bilan)
//  1) Depth Anything V2 — rasmdagi har bir nuqtaning chuqurligi
//  2) Chuqurlikdan obyektni ajratish (fon va polni olib tashlash)
//  3) Old yuza — chuqurlik relyefi, orqa yuza — silliq "puflangan" shakl
//  4) Rasmning o'zi tekstura sifatida; yorqin joylar nur sochadi
// ============================================================
import * as THREE from 'three';
import { estimateDepth } from './runtime.js';

/** Faylni canvasga o'qiydi (uzun tomoni maxSize gacha kichraytiriladi) */
export async function loadImageCanvas(file, maxSize = 1024) {
  const url = typeof file === 'string' ? file : URL.createObjectURL(file);
  const img = await new Promise((res, rej) => {
    const im = new Image();
    im.onload = () => res(im);
    im.onerror = rej;
    im.src = url;
  });
  const k = Math.min(1, maxSize / Math.max(img.width, img.height));
  const c = document.createElement('canvas');
  c.width = Math.round(img.width * k);
  c.height = Math.round(img.height * k);
  c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
  if (typeof file !== 'string') URL.revokeObjectURL(url);
  return c;
}

/** Chuqurlik xaritasini kerakli o'lchamga keltiradi (bilinear) */
function resample(src, w, h) {
  const out = new Float32Array(w * h);
  for (let y = 0; y < h; y++) {
    const sy = (y / (h - 1)) * (src.h - 1);
    const y0 = Math.floor(sy), y1 = Math.min(src.h - 1, y0 + 1), fy = sy - y0;
    for (let x = 0; x < w; x++) {
      const sx = (x / (w - 1)) * (src.w - 1);
      const x0 = Math.floor(sx), x1 = Math.min(src.w - 1, x0 + 1), fx = sx - x0;
      const a = src.data[y0 * src.w + x0], b = src.data[y0 * src.w + x1], c = src.data[y1 * src.w + x0], d = src.data[y1 * src.w + x1];
      out[y * w + x] = (a * (1 - fx) + b * fx) * (1 - fy) + (c * (1 - fx) + d * fx) * fy;
    }
  }
  return out;
}

/** Otsu chegarasi (0..1 qiymatlar uchun) */
function otsu(values) {
  const hist = new Float64Array(256);
  for (const v of values) hist[Math.max(0, Math.min(255, Math.round(v * 255)))]++;
  const total = values.length;
  let sum = 0;
  for (let i = 0; i < 256; i++) sum += i * hist[i];
  let sumB = 0, wB = 0, best = 0, th = 0;
  for (let i = 0; i < 256; i++) {
    wB += hist[i];
    if (!wB) continue;
    const wF = total - wB;
    if (!wF) break;
    sumB += i * hist[i];
    const mB = sumB / wB, mF = (sum - sumB) / wF;
    const between = wB * wF * (mB - mF) * (mB - mF);
    if (between > best) {
      best = between;
      th = i;
    }
  }
  return th / 255;
}

/**
 * Chuqurlikdan obyekt niqobini yasaydi.
 * Fon: har bir qatorda chap/o'ng chetlarning eng kichik chuqurligi (pol ham shu yo'l bilan ayiriladi).
 */
export function maskFromDepth(depth, w, h, { sensitivity = 0.5 } = {}) {
  const rel = new Float32Array(w * h);
  const edgeW = Math.max(2, Math.round(w * 0.04));
  for (let y = 0; y < h; y++) {
    const row = y * w;
    const med = (from, to) => {
      const arr = [];
      for (let x = from; x < to; x++) arr.push(depth[row + x]);
      arr.sort((a, b) => a - b);
      return arr[arr.length >> 1];
    };
    const bg = Math.min(med(0, edgeW), med(w - edgeW, w));
    for (let x = 0; x < w; x++) rel[row + x] = Math.max(0, depth[row + x] - bg);
  }
  // normallashtirish
  let mx = 0;
  for (const v of rel) mx = Math.max(mx, v);
  if (mx > 0) for (let i = 0; i < rel.length; i++) rel[i] /= mx;
  let th = otsu(rel);
  th = th * (1.3 - sensitivity * 0.8);
  const mask = new Uint8Array(w * h);
  for (let i = 0; i < rel.length; i++) mask[i] = rel[i] > th ? 1 : 0;
  keepLargest(mask, w, h);
  fillHoles(mask, w, h);
  return { mask, rel };
}

function keepLargest(mask, w, h) {
  const label = new Int32Array(w * h).fill(-1);
  let best = -1, bestSize = 0, id = 0;
  const stack = [];
  for (let i = 0; i < mask.length; i++) {
    if (!mask[i] || label[i] >= 0) continue;
    let size = 0;
    stack.push(i);
    label[i] = id;
    while (stack.length) {
      const p = stack.pop();
      size++;
      const x = p % w, y = (p / w) | 0;
      for (const q of [x > 0 ? p - 1 : -1, x < w - 1 ? p + 1 : -1, y > 0 ? p - w : -1, y < h - 1 ? p + w : -1]) {
        if (q >= 0 && mask[q] && label[q] < 0) {
          label[q] = id;
          stack.push(q);
        }
      }
    }
    if (size > bestSize) {
      bestSize = size;
      best = id;
    }
    id++;
  }
  for (let i = 0; i < mask.length; i++) mask[i] = label[i] === best ? 1 : 0;
}

function fillHoles(mask, w, h) {
  // chetdan to'ldirilmagan "teshik"lar obyektga qo'shiladi
  const outside = new Uint8Array(w * h);
  const stack = [];
  const push = (p) => {
    if (!mask[p] && !outside[p]) {
      outside[p] = 1;
      stack.push(p);
    }
  };
  for (let x = 0; x < w; x++) {
    push(x);
    push((h - 1) * w + x);
  }
  for (let y = 0; y < h; y++) {
    push(y * w);
    push(y * w + w - 1);
  }
  while (stack.length) {
    const p = stack.pop();
    const x = p % w, y = (p / w) | 0;
    if (x > 0) push(p - 1);
    if (x < w - 1) push(p + 1);
    if (y > 0) push(p - w);
    if (y < h - 1) push(p + w);
  }
  for (let i = 0; i < mask.length; i++) if (!mask[i] && !outside[i]) mask[i] = 1;
}

/** Ichki masofa (chegaragacha, piksel) — 2 o'tishli chamfer */
function distanceInside(mask, w, h) {
  const INF = 1e9;
  const d = new Float32Array(w * h);
  for (let i = 0; i < d.length; i++) d[i] = mask[i] ? INF : 0;
  const a = 1, b = Math.SQRT2;
  for (let y = 0; y < h; y++)
    for (let x = 0; x < w; x++) {
      const i = y * w + x;
      if (!d[i]) continue;
      let v = d[i];
      if (x > 0) v = Math.min(v, d[i - 1] + a);
      if (y > 0) v = Math.min(v, d[i - w] + a);
      if (x > 0 && y > 0) v = Math.min(v, d[i - w - 1] + b);
      if (x < w - 1 && y > 0) v = Math.min(v, d[i - w + 1] + b);
      if (x === 0 || y === 0 || x === w - 1 || y === h - 1) v = Math.min(v, 1);
      d[i] = v;
    }
  for (let y = h - 1; y >= 0; y--)
    for (let x = w - 1; x >= 0; x--) {
      const i = y * w + x;
      if (!d[i]) continue;
      let v = d[i];
      if (x < w - 1) v = Math.min(v, d[i + 1] + a);
      if (y < h - 1) v = Math.min(v, d[i + w] + a);
      if (x < w - 1 && y < h - 1) v = Math.min(v, d[i + w + 1] + b);
      if (x > 0 && y < h - 1) v = Math.min(v, d[i + w - 1] + b);
      d[i] = v;
    }
  return d;
}

/** Niqobni biroz silliqlash (chegara "zinapoya" bo'lmasin) */
function blurField(f, w, h, r = 1) {
  const out = new Float32Array(f.length);
  for (let y = 0; y < h; y++)
    for (let x = 0; x < w; x++) {
      let s = 0, c = 0;
      for (let dy = -r; dy <= r; dy++)
        for (let dx = -r; dx <= r; dx++) {
          const xx = x + dx, yy = y + dy;
          if (xx < 0 || yy < 0 || xx >= w || yy >= h) continue;
          s += f[yy * w + xx];
          c++;
        }
      out[y * w + x] = s / c;
    }
  return out;
}

/**
 * Asosiy funksiya: rasm (canvas) → { geometry, texture, mask, info }
 * opts: { resolution=200, depthScale=0.35, thickness=0.25, onProgress }
 */
export async function imageTo3D(canvas, opts = {}) {
  const prog = opts.onProgress || (() => {});
  prog(0.02, 'depth-load');
  const dep = await estimateDepth(canvas, (p) => prog(0.02 + p * 0.55, 'depth-download'));
  prog(0.6, 'depth-done');

  // Grid o'lchami
  const R = opts.resolution || 200;
  const aspect = canvas.width / canvas.height;
  const gw = aspect >= 1 ? R : Math.round(R * aspect);
  const gh = aspect >= 1 ? Math.round(R / aspect) : R;
  const depth = resample(dep, gw, gh);
  const { mask, rel } = maskFromDepth(depth, gw, gh, { sensitivity: opts.sensitivity ?? 0.5 });
  prog(0.7, 'mask');

  const dist = distanceInside(mask, gw, gh);
  let maxD = 1;
  for (const v of dist) maxD = Math.max(maxD, v);
  const relS = blurField(blurField(rel, gw, gh, 2), gw, gh, 1);

  // Dunyo o'lchami: balandligi 3 birlik
  const H = 3, W = H * aspect;
  const cell = W / (gw - 1);
  const depthScale = (opts.depthScale ?? 0.3) * 0.4 * W;
  const thick = (opts.thickness ?? 0.18) * Math.min(W, H);

  // Uchlar: old (front) va orqa (back); chegaradagi uchlar umumiy
  const idxF = new Int32Array(gw * gh).fill(-1);
  const idxB = new Int32Array(gw * gh).fill(-1);
  const pos = [], uv = [];
  let minRel = 1;
  for (let i = 0; i < mask.length; i++) if (mask[i]) minRel = Math.min(minRel, relS[i]);
  for (let y = 0; y < gh; y++) {
    for (let x = 0; x < gw; x++) {
      const i = y * gw + x;
      if (!mask[i]) continue;
      const px = x * cell - W / 2;
      const py = H - y * cell; // yuqoridan pastga
      const t = Math.min(1, dist[i] / Math.max(3, maxD * 0.35)); // chegaradan uzoqlik
      const edge = Math.sqrt(t); // yumaloq qirralar
      const relief = (relS[i] - minRel) * depthScale * edge;
      const zf = edge * thick * 0.5 + relief;
      const zb = -edge * thick * 0.5;
      idxF[i] = pos.length / 3;
      pos.push(px, py, zf);
      uv.push(x / (gw - 1), 1 - y / (gh - 1));
      if (dist[i] <= 1.01) {
        idxB[i] = idxF[i]; // chegara — ikkala yuza bir nuqtada tutashadi
      } else {
        idxB[i] = pos.length / 3;
        pos.push(px, py, zb);
        uv.push(x / (gw - 1), 1 - y / (gh - 1));
      }
    }
  }
  const index = [];
  const tri = (a, b, c, back) => {
    if (a < 0 || b < 0 || c < 0) return;
    if (back) index.push(a, c, b);
    else index.push(a, b, c);
  };
  for (let y = 0; y < gh - 1; y++) {
    for (let x = 0; x < gw - 1; x++) {
      const i00 = y * gw + x, i10 = i00 + 1, i01 = i00 + gw, i11 = i01 + 1;
      const m = mask[i00] + mask[i10] + mask[i01] + mask[i11];
      if (m < 3) continue;
      for (const back of [false, true]) {
        const I = back ? idxB : idxF;
        if (m === 4) {
          tri(I[i00], I[i01], I[i10], back);
          tri(I[i10], I[i01], I[i11], back);
        } else {
          const c = [i00, i10, i11, i01].filter((k) => mask[k]);
          // uch nuqta — bitta uchburchak (aylanish tartibini saqlab)
          const order = [i00, i01, i11, i10].filter((k) => mask[k]);
          tri(I[order[0]], I[order[1]], I[order[2]], back);
          void c;
        }
      }
    }
  }
  const g = new THREE.BufferGeometry();
  g.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
  g.setAttribute('uv', new THREE.Float32BufferAttribute(uv, 2));
  g.setIndex(index);
  g.computeVertexNormals();
  g.computeBoundingBox();
  g.computeBoundingSphere();
  prog(0.85, 'mesh');

  // Teksturalar: rasm va yorug' joylardan "nur" xaritasi
  const tex = new THREE.CanvasTexture(canvas);
  tex.colorSpace = THREE.SRGBColorSpace;
  tex.anisotropy = 4;
  return { geometry: g, texture: tex, mask, gw, gh, W, H, cell, relS };
}

/** Rasmdagi yorqin, issiq ranglarni (olov, nur) emissive xarita sifatida ajratadi */
export function glowMapFromCanvas(canvas, strength = 1) {
  const c = document.createElement('canvas');
  c.width = canvas.width;
  c.height = canvas.height;
  const src = canvas.getContext('2d').getImageData(0, 0, c.width, c.height);
  const ctx = c.getContext('2d');
  const out = ctx.createImageData(c.width, c.height);
  for (let i = 0; i < src.data.length; i += 4) {
    const r = src.data[i] / 255, g = src.data[i + 1] / 255, b = src.data[i + 2] / 255;
    const lum = 0.2126 * r + 0.7152 * g + 0.0722 * b;
    const k = Math.max(0, Math.min(1, (lum - 0.55) / 0.35)) * strength;
    out.data[i] = Math.round(r * 255 * k);
    out.data[i + 1] = Math.round(g * 255 * k);
    out.data[i + 2] = Math.round(b * 255 * k);
    out.data[i + 3] = 255;
  }
  ctx.putImageData(out, 0, 0);
  const t = new THREE.CanvasTexture(c);
  t.colorSpace = THREE.SRGBColorSpace;
  return t;
}
