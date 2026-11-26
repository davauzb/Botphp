// ============================================================
//  AI ISHGA TUSHIRISH MUHITI (Transformers.js + ONNX Runtime WASM)
//  - Modellar birinchi marta HuggingFace'dan yuklanadi va IndexedDB da
//    saqlanadi → keyin INTERNETSIZ ishlaydi.
//  - ONNX runtime (wasm) ilova ichida keladi (Android: appassets host).
// ============================================================
import { env, pipeline, RawImage } from '@huggingface/transformers';

const DB = 'mini3d-ai-models';
const STORE = 'files';

function idb() {
  return new Promise((res, rej) => {
    const r = indexedDB.open(DB, 1);
    r.onupgradeneeded = () => r.result.createObjectStore(STORE);
    r.onsuccess = () => res(r.result);
    r.onerror = () => rej(r.error);
  });
}

/** Transformers.js uchun o'z keshimiz (Cache API bo'lmasa ham ishlaydi) */
const idbCache = {
  async match(req) {
    const key = typeof req === 'string' ? req : req.url;
    try {
      const db = await idb();
      const val = await new Promise((res, rej) => {
        const t = db.transaction(STORE, 'readonly').objectStore(STORE).get(key);
        t.onsuccess = () => res(t.result);
        t.onerror = () => rej(t.error);
      });
      if (!val) return undefined;
      return new Response(val.body, { headers: val.headers });
    } catch {
      return undefined;
    }
  },
  async put(req, resp) {
    const key = typeof req === 'string' ? req : req.url;
    try {
      const body = await resp.arrayBuffer();
      const headers = {};
      resp.headers?.forEach?.((v, k) => (headers[k] = v));
      const db = await idb();
      await new Promise((res, rej) => {
        const t = db.transaction(STORE, 'readwrite');
        t.objectStore(STORE).put({ body, headers }, key);
        t.oncomplete = res;
        t.onerror = () => rej(t.error);
      });
    } catch (e) {
      console.warn('model cache put failed', e);
    }
  },
};

let configured = false;
function configure() {
  if (configured) return;
  configured = true;
  env.allowLocalModels = false;
  env.useBrowserCache = false;
  env.useCustomCache = true;
  env.customCache = idbCache;
  // file:// sahifada wasm ni Android "virtual host" orqali olamiz
  const base = location.protocol === 'file:' ? 'https://appassets.androidplatform.net/ort/' : new URL('ort/', location.href).href;
  env.backends.onnx.wasm.wasmPaths = { mjs: base + 'ort-wasm-simd-threaded.mjs', wasm: base + 'ort-wasm-simd-threaded.wasm' };
  env.backends.onnx.wasm.numThreads = 1;
}

export const MODELS = {
  depth: { id: 'onnx-community/depth-anything-v2-small', task: 'depth-estimation', dtype: 'q8', sizeMB: 27 },
};

const pipes = new Map();
/** Modelni yuklaydi (keshdan yoki internetdan). onProgress(0..1, matn) */
export async function getPipeline(name, onProgress) {
  configure();
  if (pipes.has(name)) return pipes.get(name);
  const m = MODELS[name];
  const files = new Map();
  const p = pipeline(m.task, m.id, {
    dtype: m.dtype,
    device: 'wasm',
    progress_callback: (e) => {
      if (e.status === 'progress' && e.total) {
        files.set(e.file, [e.loaded, e.total]);
        let l = 0, t = 0;
        for (const [a, b] of files.values()) {
          l += a;
          t += b;
        }
        onProgress?.(t ? l / t : 0, e.file);
      }
    },
  });
  pipes.set(name, p);
  try {
    return await p;
  } catch (e) {
    pipes.delete(name);
    throw e;
  }
}

/** Model telefonda saqlanganmi (internetsiz ishlay oladimi)? */
export async function isModelCached(name) {
  const m = MODELS[name];
  const url = `https://huggingface.co/${m.id}/resolve/main/onnx/model_quantized.onnx`;
  return !!(await idbCache.match(url));
}

/** Canvas → chuqurlik xaritasi (Float32, 0..1, katta = yaqin) */
export async function estimateDepth(canvas, onProgress) {
  const depth = await getPipeline('depth', onProgress);
  const img = RawImage.fromCanvas(canvas);
  const out = await depth(img);
  const d = out.depth; // RawImage (1 kanal, 0..255)
  const f = new Float32Array(d.width * d.height);
  for (let i = 0; i < f.length; i++) f[i] = d.data[i] / 255;
  // asl o'lchamda (pipeline natijani rasm o'lchamiga moslaydi)
  return { w: d.width, h: d.height, data: f };
}
