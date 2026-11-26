// ============================================================
//  BULUTDA RASMDAN 3D (internet kerak)
//  - Hunyuan3D-2 (Tencent, HuggingFace Space — bepul, navbat bo'lishi mumkin)
//    Gradio API avtomatik aniqlanadi (/gradio_api/info), shuning uchun
//    Space o'zgarsa ham ishlashga harakat qiladi.
//  - Meshy, Tripo — pullik, API kalit kerak (Sozlamalarda)
//  Android'da so'rovlar Java orqali yuboriladi (CORS muammosi yo'q).
// ============================================================

// ---------------- HTTP qatlami ----------------
let reqId = 0;
const pending = new Map();
window.__httpDone = (id, status, body, err) => {
  const p = pending.get(id);
  if (!p) return;
  pending.delete(id);
  if (err) p.reject(new Error(err));
  else p.resolve({ status, body });
};

function b64ToBytes(b64) {
  const bin = atob(b64);
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return out;
}
function bytesToB64(bytes) {
  let s = '';
  for (let i = 0; i < bytes.length; i += 0x8000) s += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
  return btoa(s);
}

/**
 * so'rov: { method, url, headers, body: Uint8Array|string|null, as: 'text'|'bytes', timeout }
 * Javob: { status, text?, bytes? }
 */
export async function http({ method = 'GET', url, headers = {}, body = null, as = 'text', timeout = 300000 }) {
  const B = window.AndroidBridge;
  const bodyBytes = body == null ? null : typeof body === 'string' ? new TextEncoder().encode(body) : body;
  if (B?.httpRequest) {
    const id = String(++reqId);
    const res = await new Promise((resolve, reject) => {
      pending.set(id, { resolve, reject });
      B.httpRequest(id, method, url, JSON.stringify(headers), bodyBytes ? bytesToB64(bodyBytes) : '', as === 'bytes' ? 'file' : 'text', timeout);
    });
    if (as === 'bytes') {
      // Katta javob vaqtinchalik faylga yozilgan: virtual manzil orqali olamiz
      const r = await fetch(res.body);
      return { status: res.status, bytes: new Uint8Array(await r.arrayBuffer()) };
    }
    return { status: res.status, text: new TextDecoder().decode(b64ToBytes(res.body)) };
  }
  const ctrl = new AbortController();
  const tm = setTimeout(() => ctrl.abort(), timeout);
  try {
    const r = await fetch(url, { method, headers, body: bodyBytes, signal: ctrl.signal });
    if (as === 'bytes') return { status: r.status, bytes: new Uint8Array(await r.arrayBuffer()) };
    return { status: r.status, text: await r.text() };
  } finally {
    clearTimeout(tm);
  }
}

function multipart(field, filename, mime, bytes) {
  const boundary = '----mini3d' + Math.random().toString(16).slice(2);
  const enc = new TextEncoder();
  const head = enc.encode(`--${boundary}\r\nContent-Disposition: form-data; name="${field}"; filename="${filename}"\r\nContent-Type: ${mime}\r\n\r\n`);
  const tail = enc.encode(`\r\n--${boundary}--\r\n`);
  const body = new Uint8Array(head.length + bytes.length + tail.length);
  body.set(head, 0);
  body.set(bytes, head.length);
  body.set(tail, head.length + bytes.length);
  return { body, contentType: `multipart/form-data; boundary=${boundary}` };
}

export async function canvasToPng(canvas) {
  const blob = await new Promise((r) => canvas.toBlob(r, 'image/png'));
  return new Uint8Array(await blob.arrayBuffer());
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const json = (res) => {
  try {
    return JSON.parse(res.text);
  } catch {
    throw new Error('HTTP ' + res.status + ': ' + (res.text || '').slice(0, 200));
  }
};

// ---------------- Gradio (HuggingFace Space) ----------------
async function gradio(space, png, { token, onProgress }) {
  const base = `https://${space.replace('/', '-').toLowerCase().replace(/[^a-z0-9-]/g, '-')}.hf.space`;
  const auth = token ? { Authorization: `Bearer ${token}` } : {};
  onProgress?.(0.05, 'cloud-connect');
  const info = json(await http({ url: `${base}/gradio_api/info`, headers: auth, timeout: 60000 }));
  const eps = Object.entries(info.named_endpoints || {});
  const score = ([name, ep]) => {
    const hasImage = ep.parameters.some((p) => /image/i.test(p.component || '') && !/mv_/i.test(p.parameter_name || ''));
    const has3d = ep.returns.some((r) => /model3d|file/i.test(r.component || ''));
    if (!hasImage || !has3d) return -1;
    let s = 1;
    if (/all|textur/i.test(name)) s += 3;
    if (/shape|generat|image_to_3d|i23d/i.test(name)) s += 2;
    return s;
  };
  const pick = eps.map((e) => [e, score(e)]).filter(([, s]) => s > 0).sort((a, b) => b[1] - a[1])[0]?.[0];
  if (!pick) throw new Error('Space API topilmadi');
  const [epName, ep] = pick;

  // Rasmni yuklash
  onProgress?.(0.1, 'cloud-upload');
  const mp = multipart('files', 'input.png', 'image/png', png);
  const up = json(await http({ method: 'POST', url: `${base}/gradio_api/upload`, headers: { ...auth, 'Content-Type': mp.contentType }, body: mp.body, timeout: 120000 }));
  const path = Array.isArray(up) ? up[0] : up?.files?.[0];
  const imageArg = { path, meta: { _type: 'gradio.FileData' }, orig_name: 'input.png' };
  let imageUsed = false;
  const data = ep.parameters.map((p) => {
    if (!imageUsed && /image/i.test(p.component || '') && !/mv_/i.test(p.parameter_name || '')) {
      imageUsed = true;
      return imageArg;
    }
    if (/rembg|remove.?bg/i.test(p.parameter_name || p.label || '')) return true;
    return p.parameter_has_default ? p.parameter_default : null;
  });

  onProgress?.(0.15, 'cloud-queue');
  const call = json(await http({ method: 'POST', url: `${base}/gradio_api/call${epName}`, headers: { ...auth, 'Content-Type': 'application/json' }, body: JSON.stringify({ data }), timeout: 60000 }));
  const eventId = call.event_id;
  if (!eventId) throw new Error('Navbatga qo\'shilmadi');
  // Natija kelguncha (SSE) — server ulanishni ochiq ushlab turadi
  onProgress?.(0.2, 'cloud-generating');
  const sse = await http({ url: `${base}/gradio_api/call${epName}/${eventId}`, headers: auth, timeout: 900000 });
  const events = sse.text.split(/\n\n/).map((blk) => {
    const ev = /event:\s*(\w+)/.exec(blk)?.[1];
    const d = /data:\s*([\s\S]*)/.exec(blk)?.[1];
    return { ev, d };
  });
  const err = events.find((e) => e.ev === 'error');
  const done = events.reverse().find((e) => e.ev === 'complete');
  if (!done) throw new Error(err?.d && err.d !== 'null' ? err.d : 'GPU navbati band yoki limit tugadi — keyinroq urinib ko\'ring');
  const out = JSON.parse(done.d);
  const files = [];
  const walk = (v) => {
    if (!v) return;
    if (Array.isArray(v)) return v.forEach(walk);
    if (typeof v === 'object') {
      const u = v.url || (v.path ? `${base}/gradio_api/file=${v.path}` : null);
      if (u && /\.(glb|gltf|obj|ply|stl)(\?|$)/i.test(v.path || v.url || '')) files.push(u);
      Object.values(v).forEach((x) => typeof x === 'object' && walk(x));
    }
  };
  walk(out);
  if (!files.length) throw new Error('3D fayl qaytmadi');
  const url = files[files.length - 1]; // odatda oxirgisi — teksturali model
  onProgress?.(0.9, 'cloud-download');
  const res = await http({ url, headers: auth, as: 'bytes', timeout: 300000 });
  const ext = (/\.(glb|gltf|obj|ply|stl)/i.exec(url)?.[1] || 'glb').toLowerCase();
  return { bytes: res.bytes, ext };
}

// ---------------- Meshy ----------------
async function meshy(png, { key, onProgress }) {
  if (!key) throw new Error('Meshy API kalit kiritilmagan');
  const H = { Authorization: `Bearer ${key}`, 'Content-Type': 'application/json' };
  const dataUri = 'data:image/png;base64,' + bytesToB64(png);
  const r = json(await http({ method: 'POST', url: 'https://api.meshy.ai/openapi/v1/image-to-3d', headers: H, body: JSON.stringify({ image_url: dataUri, enable_pbr: true, should_texture: true, should_remesh: true }) }));
  const id = r.result;
  if (!id) throw new Error(r.message || 'Meshy xato');
  for (let i = 0; i < 400; i++) {
    await sleep(4000);
    const s = json(await http({ url: `https://api.meshy.ai/openapi/v1/image-to-3d/${id}`, headers: H }));
    onProgress?.(0.1 + (s.progress || 0) / 125, 'cloud-generating');
    if (s.status === 'SUCCEEDED') {
      const res = await http({ url: s.model_urls.glb, as: 'bytes' });
      return { bytes: res.bytes, ext: 'glb' };
    }
    if (s.status === 'FAILED' || s.status === 'CANCELED') throw new Error(s.task_error?.message || 'Meshy: ' + s.status);
  }
  throw new Error('Meshy: vaqt tugadi');
}

// ---------------- Tripo ----------------
async function tripo(png, { key, onProgress }) {
  if (!key) throw new Error('Tripo API kalit kiritilmagan');
  const A = { Authorization: `Bearer ${key}` };
  const mp = multipart('file', 'input.png', 'image/png', png);
  const up = json(await http({ method: 'POST', url: 'https://api.tripo3d.ai/v2/openapi/upload', headers: { ...A, 'Content-Type': mp.contentType }, body: mp.body }));
  const token = up.data?.image_token || up.data?.file_token;
  if (!token) throw new Error(up.message || 'Tripo yuklash xatosi');
  const task = json(await http({ method: 'POST', url: 'https://api.tripo3d.ai/v2/openapi/task', headers: { ...A, 'Content-Type': 'application/json' }, body: JSON.stringify({ type: 'image_to_model', file: { type: 'png', file_token: token } }) }));
  const id = task.data?.task_id;
  if (!id) throw new Error(task.message || 'Tripo vazifa xatosi');
  for (let i = 0; i < 400; i++) {
    await sleep(4000);
    const s = json(await http({ url: `https://api.tripo3d.ai/v2/openapi/task/${id}`, headers: A }));
    const d = s.data || {};
    onProgress?.(0.1 + (d.progress || 0) / 125, 'cloud-generating');
    if (d.status === 'success') {
      const o = d.output || d.result || {};
      const url = o.pbr_model?.url || o.pbr_model || o.model?.url || o.model || o.base_model;
      if (!url) throw new Error('Tripo: model manzili topilmadi');
      const res = await http({ url, as: 'bytes' });
      return { bytes: res.bytes, ext: 'glb' };
    }
    if (['failed', 'cancelled', 'banned', 'expired'].includes(d.status)) throw new Error('Tripo: ' + d.status);
  }
  throw new Error('Tripo: vaqt tugadi');
}

export const cloudProviders = [
  { id: 'hunyuan-turbo', label: 'Hunyuan3D-2 mini Turbo', free: true, run: (png, o) => gradio('tencent/Hunyuan3D-2mini-Turbo', png, o) },
  { id: 'hunyuan', label: 'Hunyuan3D-2', free: true, run: (png, o) => gradio('tencent/Hunyuan3D-2', png, o) },
  { id: 'meshy', label: 'Meshy', free: false, keyName: 'meshyKey', run: (png, o) => meshy(png, o) },
  { id: 'tripo', label: 'Tripo3D', free: false, keyName: 'tripoKey', run: (png, o) => tripo(png, o) },
];
