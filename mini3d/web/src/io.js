// ============================================================
//  IMPORT / EKSPORT
//  Eksport: GLB, glTF, OBJ, STL, PLY, PNG, loyiha (.m3d.json)
//  Import:  GLB, glTF, OBJ, STL, PLY, loyiha (.m3d.json / .json)
//  Android'da fayllar "Download/Mini3D" papkasiga saqlanadi.
// ============================================================
import * as THREE from 'three';
import { GLTFExporter } from 'three/examples/jsm/exporters/GLTFExporter.js';
import { OBJExporter } from 'three/examples/jsm/exporters/OBJExporter.js';
import { STLExporter } from 'three/examples/jsm/exporters/STLExporter.js';
import { PLYExporter } from 'three/examples/jsm/exporters/PLYExporter.js';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { OBJLoader } from 'three/examples/jsm/loaders/OBJLoader.js';
import { STLLoader } from 'three/examples/jsm/loaders/STLLoader.js';
import { PLYLoader } from 'three/examples/jsm/loaders/PLYLoader.js';
import { createMaterial } from './materials.js';

const bridge = () => window.AndroidBridge;
export const isAndroid = () => !!bridge();

// ---------- Fayl saqlash ----------
function arrayBufferToBase64(buf) {
  const bytes = new Uint8Array(buf);
  let bin = '';
  const CH = 0x8000;
  for (let k = 0; k < bytes.length; k += CH) bin += String.fromCharCode.apply(null, bytes.subarray(k, k + CH));
  return btoa(bin);
}

/**
 * Faylni saqlaydi. data: string | ArrayBuffer | Blob
 * @returns {Promise<string>} saqlangan joy (Android'da) yoki fayl nomi
 */
export async function saveFile(name, data, mime = 'application/octet-stream', { share = false } = {}) {
  let buf;
  if (typeof data === 'string') buf = new TextEncoder().encode(data).buffer;
  else if (data instanceof Blob) buf = await data.arrayBuffer();
  else buf = data;

  if (bridge()) {
    const b64 = arrayBufferToBase64(buf);
    const res = share && bridge().shareFile ? bridge().shareFile(name, mime, b64) : bridge().saveFile(name, mime, b64);
    if (res && res.startsWith('ERR:')) throw new Error(res.slice(4));
    return res;
  }
  const url = URL.createObjectURL(new Blob([buf], { type: mime }));
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 4000);
  return name;
}

export function dataURLToArrayBuffer(url) {
  const b64 = url.split(',')[1];
  const bin = atob(b64);
  const out = new Uint8Array(bin.length);
  for (let k = 0; k < bin.length; k++) out[k] = bin.charCodeAt(k);
  return out.buffer;
}

// ---------- Eksport ----------
/** Eksport uchun sahna nusxasi: yashirin obyektlar olib tashlanadi. */
function exportRoot(editor, onlySelected) {
  const src = onlySelected && editor.selected ? editor.selected : editor.content;
  const root = src === editor.content ? src.clone(false) : new THREE.Group();
  root.name = src === editor.content ? 'Scene' : src.name;
  const addVisible = (from, to) => {
    for (const c of from.children) {
      if (!c.visible) continue;
      const copy = c.clone(false);
      to.add(copy);
      addVisible(c, copy);
    }
  };
  if (src === editor.content) addVisible(src, root);
  else {
    src.updateWorldMatrix(true, false);
    const copy = src.clone(false);
    src.matrixWorld.decompose(copy.position, copy.quaternion, copy.scale);
    root.add(copy);
    addVisible(src, copy);
  }
  return root;
}

export const exportFormats = [
  { id: 'glb', label: 'GLB', desc: 'export.glb', ext: 'glb', mime: 'model/gltf-binary' },
  { id: 'gltf', label: 'glTF', desc: 'export.gltf', ext: 'gltf', mime: 'model/gltf+json' },
  { id: 'obj', label: 'OBJ', desc: 'export.obj', ext: 'obj', mime: 'text/plain' },
  { id: 'stl', label: 'STL', desc: 'export.stl', ext: 'stl', mime: 'model/stl' },
  { id: 'ply', label: 'PLY', desc: 'export.ply', ext: 'ply', mime: 'application/octet-stream' },
];

export async function exportModel(editor, format, { onlySelected = false } = {}) {
  const root = exportRoot(editor, onlySelected);
  root.updateMatrixWorld(true);
  switch (format) {
    case 'glb':
    case 'gltf': {
      const res = await new GLTFExporter().parseAsync(root, { binary: format === 'glb', onlyVisible: true });
      return format === 'glb' ? res : JSON.stringify(res);
    }
    case 'obj':
      return new OBJExporter().parse(root);
    case 'stl':
      return new STLExporter().parse(root, { binary: true }).buffer;
    case 'ply':
      return await new Promise((res) => new PLYExporter().parse(root, res, { binary: true }));
  }
  throw new Error('Unknown format ' + format);
}

// ---------- Import ----------
export const importAccept = '.glb,.gltf,.obj,.stl,.ply,.json,.m3d';

function readFile(file, as = 'arrayBuffer') {
  return new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onload = () => resolve(r.result);
    r.onerror = () => reject(r.error);
    if (as === 'text') r.readAsText(file);
    else if (as === 'dataURL') r.readAsDataURL(file);
    else r.readAsArrayBuffer(file);
  });
}

/**
 * Faylni o'qib, natija qaytaradi:
 *  { kind: 'project', data }  yoki  { kind: 'model', object }
 */
export async function importFile(file) {
  const name = file.name || 'model';
  const ext = name.split('.').pop().toLowerCase();
  const base = name.replace(/\.[^.]+$/, '');

  if (ext === 'json' || ext === 'm3d') {
    const data = JSON.parse(await readFile(file, 'text'));
    if (data.format === 'mini3d-project') return { kind: 'project', data };
    // three.js JSON obyekt formati
    const obj = await new THREE.ObjectLoader().parseAsync(data);
    return { kind: 'model', object: wrap(obj, base) };
  }

  if (ext === 'glb' || ext === 'gltf') {
    const buf = await readFile(file);
    const gltf = await new GLTFLoader().parseAsync(buf, '');
    const obj = gltf.scene || gltf.scenes[0];
    return { kind: 'model', object: wrap(obj, base) };
  }

  if (ext === 'obj') {
    const obj = new OBJLoader().parse(await readFile(file, 'text'));
    obj.traverse((o) => {
      if (o.isMesh) {
        const mats = [].concat(o.material).map(() => createMaterial('MeshStandardMaterial', { color: '#cfcfcf' }));
        o.material = Array.isArray(o.material) ? mats : mats[0];
      }
    });
    return { kind: 'model', object: wrap(obj, base) };
  }

  if (ext === 'stl' || ext === 'ply') {
    const buf = await readFile(file);
    const geo = ext === 'stl' ? new STLLoader().parse(buf) : new PLYLoader().parse(buf);
    if (!geo.attributes.normal) geo.computeVertexNormals();
    const hasColor = !!geo.attributes.color;
    const mat = createMaterial('MeshStandardMaterial', { color: hasColor ? '#ffffff' : '#cfcfcf' });
    mat.vertexColors = hasColor;
    const mesh = new THREE.Mesh(geo, mat);
    // STL fayllar ko'pincha Z-yuqoriga yo'naltirilgan
    if (ext === 'stl') mesh.rotation.x = -Math.PI / 2;
    return { kind: 'model', object: wrap(mesh, base) };
  }

  throw new Error('unsupported');
}

/** Modelni guruhga o'raydi, o'lchamini moslaydi va yerga qo'yadi. */
function wrap(obj, name) {
  const g = new THREE.Group();
  g.name = name;
  g.userData.imported = true;
  g.add(obj);
  obj.traverse((o) => {
    if (o.isMesh) {
      o.castShadow = o.receiveShadow = true;
    }
  });
  g.updateMatrixWorld(true);
  const box = new THREE.Box3().setFromObject(g);
  if (!box.isEmpty()) {
    const size = box.getSize(new THREE.Vector3());
    const maxDim = Math.max(size.x, size.y, size.z);
    // Juda katta yoki juda kichik modellarni qulay o'lchamga keltiramiz
    if (maxDim > 20 || maxDim < 0.05) {
      const s = 2 / maxDim;
      obj.scale.multiplyScalar(s);
      g.updateMatrixWorld(true);
      box.setFromObject(g);
    }
    const c = box.getCenter(new THREE.Vector3());
    obj.position.x -= c.x;
    obj.position.z -= c.z;
    obj.position.y -= box.min.y;
  }
  return g;
}

/** Rasmni teksturaga aylantiradi (maks. 1024px, xotirani tejash uchun). */
export async function imageFileToTexture(file, maxSize = 1024) {
  const url = await readFile(file, 'dataURL');
  const img = await new Promise((res, rej) => {
    const im = new Image();
    im.onload = () => res(im);
    im.onerror = rej;
    im.src = url;
  });
  const k = Math.min(1, maxSize / Math.max(img.width, img.height));
  const canvas = document.createElement('canvas');
  canvas.width = Math.max(1, Math.round(img.width * k));
  canvas.height = Math.max(1, Math.round(img.height * k));
  canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
  const tex = new THREE.CanvasTexture(canvas);
  tex.colorSpace = THREE.SRGBColorSpace;
  tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
  tex.name = file.name;
  return tex;
}

/** Fayl tanlash oynasini ochadi. */
export function pickFile(accept) {
  return new Promise((resolve) => {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = accept;
    input.style.display = 'none';
    document.body.appendChild(input);
    input.addEventListener('change', () => {
      resolve(input.files?.[0] || null);
      input.remove();
    });
    input.click();
  });
}
