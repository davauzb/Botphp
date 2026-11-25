// ============================================================
//  MATERIALLAR: turlari va tayyor andozalar (presetlar)
//  Yangi andoza qo'shish uchun materialPresets ga element qo'shing.
// ============================================================
import * as THREE from 'three';

export const materialTypes = [
  { id: 'MeshStandardMaterial', label: 'mat.standard' },
  { id: 'MeshPhysicalMaterial', label: 'mat.physical' },
  { id: 'MeshLambertMaterial', label: 'mat.matte' },
  { id: 'MeshToonMaterial', label: 'mat.toon' },
  { id: 'MeshBasicMaterial', label: 'mat.basic' },
  { id: 'MeshNormalMaterial', label: 'mat.normal' },
];

export const materialPresets = [
  { id: 'plastic', label: 'preset.plastic', color: '#e5533d', metalness: 0, roughness: 0.45 },
  { id: 'rubber', label: 'preset.rubber', color: '#2b2b2b', metalness: 0, roughness: 0.95 },
  { id: 'gold', label: 'preset.gold', color: '#ffc94d', metalness: 1, roughness: 0.25 },
  { id: 'silver', label: 'preset.silver', color: '#e6e8ec', metalness: 1, roughness: 0.18 },
  { id: 'copper', label: 'preset.copper', color: '#d9824b', metalness: 1, roughness: 0.32 },
  { id: 'chrome', label: 'preset.chrome', color: '#ffffff', metalness: 1, roughness: 0.02 },
  { id: 'glass', label: 'preset.glass', color: '#bfe6ff', metalness: 0, roughness: 0.05, opacity: 0.35 },
  { id: 'wood', label: 'preset.wood', color: '#9a6a3c', metalness: 0, roughness: 0.75 },
  { id: 'stone', label: 'preset.stone', color: '#8d8d88', metalness: 0, roughness: 1 },
  { id: 'neon', label: 'preset.neon', color: '#28f0ff', metalness: 0, roughness: 0.4, emissive: '#28f0ff', emissiveIntensity: 1.6 },
  { id: 'clay', label: 'preset.clay', color: '#d8c3a5', metalness: 0, roughness: 0.85 },
  { id: 'jade', label: 'preset.jade', color: '#3fae7c', metalness: 0.1, roughness: 0.2 },
];

export const palette = ['#f2f2f2', '#9aa0a6', '#2b2b2b', '#e5533d', '#ff9f1c', '#ffd23f', '#3fae7c', '#2ec4b6', '#3a86ff', '#5e60ce', '#c77dff', '#ff70a6'];

export function createMaterial(type = 'MeshStandardMaterial', opts = {}) {
  const Ctor = THREE[type] || THREE.MeshStandardMaterial;
  const m = new Ctor();
  if ('color' in m) m.color.set(opts.color ?? '#d9d9d9');
  if ('metalness' in m) m.metalness = opts.metalness ?? 0;
  if ('roughness' in m) m.roughness = opts.roughness ?? 0.5;
  return m;
}

/** Bir materialdan ikkinchisiga umumiy xususiyatlarni ko'chiradi (turini almashtirganda). */
export function convertMaterial(old, type) {
  const m = createMaterial(type);
  for (const k of ['color', 'emissive']) if (old[k] && m[k]) m[k].copy(old[k]);
  for (const k of ['metalness', 'roughness', 'opacity', 'transparent', 'wireframe', 'flatShading', 'side', 'emissiveIntensity', 'map']) {
    if (k in old && k in m) m[k] = old[k];
  }
  m.name = old.name;
  m.needsUpdate = true;
  return m;
}

export function applyPreset(mat, preset) {
  if (mat.color) mat.color.set(preset.color);
  if ('metalness' in mat) mat.metalness = preset.metalness ?? 0;
  if ('roughness' in mat) mat.roughness = preset.roughness ?? 0.5;
  mat.opacity = preset.opacity ?? 1;
  mat.transparent = mat.opacity < 1;
  mat.depthWrite = mat.opacity >= 1;
  if (mat.emissive) {
    mat.emissive.set(preset.emissive ?? '#000000');
    mat.emissiveIntensity = preset.emissiveIntensity ?? 1;
  }
  mat.needsUpdate = true;
}
