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
/**
 * Gradio Space bilan "brauzer kabi" ishlaydi:
 *  1) /config — komponentlar va funksiyalar (Gradio 4 va 5)
 *  2) sahifa ochilganda ishlaydigan funksiyalar (masalan, start_session)
 *  3) rasm yuklash → navbatga qo'shilish (session_hash bilan) → natija (SSE)
 */
async function gradio(space, png, { token, onProgress }) {
  const base = `https://${space.replace('/', '-').toLowerCase().replace(/[^a-z0-9-]/g, '-')}.hf.space`;
  const auth = token ? { Authorization: `Bearer ${token}` } : {};
  onProgress?.(0.05, 'cloud-connect');
  const cfgRes = await http({ url: `${base}/config`, headers: auth, timeout: 60000 });
  if (cfgRes.status >= 400) throw new Error('Server javob bermadi (' + cfgRes.status + ') — Space uxlab yotgan boʻlishi mumkin, 1 daqiqadan keyin qayta urinib koʻring');
  const cfg = json(cfgRes);
  const prefix = cfg.api_prefix || (String(cfg.version || '').startsWith('4') ? '' : '/gradio_api');
  const comps = new Map(cfg.components.map((c) => [c.id, c]));
  const deps = cfg.dependencies.map((d, i) => ({ ...d, fn_index: d.id ?? i }));
  const type = (id) => (comps.get(id)?.type || '').toLowerCase();

  // Asosiy funksiyani tanlash: rasm kiradi, 3D chiqadi
  const score = (d) => {
    const ins = d.inputs.map(type), outs = d.outputs.map(type);
    if (!ins.includes('image') || !outs.some((t) => /model3d|file|downloadbutton/.test(t))) return -1;
    let sc = 1;
    if (/all|textur|extract_glb/i.test(d.api_name || '')) sc += 3;
    if (/shape|generat|image_to_3d/i.test(d.api_name || '')) sc += 2;
    if (outs.some((t) => /model3d/.test(t))) sc += 1;
    return sc;
  };
  const main = deps.map((d) => [d, score(d)]).filter(([, sc]) => sc > 0).sort((x, y) => y[1] - x[1])[0]?.[0];
  if (!main) throw new Error('Space API topilmadi');

  const session = 'm3d' + Math.random().toString(36).slice(2, 12);
  const runFn = async (dep, data, onStage) => {
    const join = json(await http({
      method: 'POST', url: `${base}${prefix}/queue/join`, headers: { ...auth, 'Content-Type': 'application/json' },
      body: JSON.stringify({ data, event_data: null, fn_index: dep.fn_index, trigger_id: null, session_hash: session }), timeout: 60000,
    }));
    if (!join.event_id) throw new Error(join.detail || 'Navbatga qoʻshilmadi');
    onStage?.();
    const sse = await http({ url: `${base}${prefix}/queue/data?session_hash=${session}`, headers: auth, timeout: 900000 });
    const msgs = sse.text.split('\n').filter((l) => l.startsWith('data:')).map((l) => {
      try { return JSON.parse(l.slice(5)); } catch { return null; }
    }).filter(Boolean);
    const done = msgs.find((m) => m.msg === 'process_completed' && m.event_id === join.event_id);
    if (!done) throw new Error('Server natija bermadi');
    if (!done.success) {
      const err = done.output?.error || done.title || 'xato';
      if (/quota/i.test(err)) throw new Error('quota:' + err);
      throw new Error(err);
    }
    return done.output?.data || [];
  };

  // Sahifa ochilganda ishlaydigan funksiyalar (sessiya papkasi va h.k.)
  for (const d of deps) {
    if ((d.targets || []).some((t) => t[1] === 'load') && !d.inputs.length && d.backend_fn !== false) {
      try { await runFn(d, []); } catch (e) { console.warn('load fn', e); }
    }
  }

  onProgress?.(0.1, 'cloud-upload');
  const mp = multipart('files', 'input.png', 'image/png', png);
  const up = json(await http({ method: 'POST', url: `${base}${prefix}/upload`, headers: { ...auth, 'Content-Type': mp.contentType }, body: mp.body, timeout: 120000 }));
  const path = Array.isArray(up) ? up[0] : up?.files?.[0];
  const imageArg = { path, url: `${base}${prefix}/file=${path}`, meta: { _type: 'gradio.FileData' }, orig_name: 'input.png', mime_type: 'image/png' };
  let imageUsed = false;
  const data = main.inputs.map((id) => {
    const c = comps.get(id) || {};
    const t = (c.type || '').toLowerCase();
    const label = (c.props?.label || '').toLowerCase();
    if (t === 'image' && !imageUsed && !/front|back|left|right|mv/.test(label)) {
      imageUsed = true;
      return imageArg;
    }
    if (t === 'state') return null;
    if (t === 'gallery') return [];
    if (t === 'checkbox' && /rembg|background/.test(label)) return true;
    const v = c.props?.value;
    return v === undefined ? null : v;
  });

  onProgress?.(0.15, 'cloud-queue');
  const out = await runFn(main, data, () => onProgress?.(0.2, 'cloud-generating'));
  const files = [];
  const walk = (v) => {
    if (!v) return;
    if (Array.isArray(v)) return v.forEach(walk);
    if (typeof v === 'object') {
      const p = v.path || v.url || '';
      if (/\.(glb|gltf|obj|ply|stl)(\?|$)/i.test(p)) files.push(v.url || `${base}${prefix}/file=${v.path}`);
      Object.values(v).forEach((x) => x && typeof x === 'object' && walk(x));
    }
  };
  walk(out);
  if (!files.length) throw new Error('3D fayl qaytmadi');
  const url = files[files.length - 1];
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
  { id: 'trellis', label: 'TRELLIS (Microsoft)', free: true, run: (png, o) => gradio('trellis-community/TRELLIS', png, o) },
  { id: 'hunyuan', label: 'Hunyuan3D-2 (Tencent)', free: true, run: (png, o) => gradio('tencent/Hunyuan3D-2', png, o) },
  { id: 'meshy', label: 'Meshy', free: false, keyName: 'meshyKey', run: (png, o) => meshy(png, o) },
  { id: 'tripo', label: 'Tripo3D', free: false, keyName: 'tripoKey', run: (png, o) => tripo(png, o) },
];
