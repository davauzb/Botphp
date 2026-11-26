// ============================================================
//  EFFEKTLAR: olov, uchqun, tutun, sehr, qor ...
//  Zarrachalar (particles) GPU shaderida chiziladi, harakat esa CPU da
//  hisoblanadi. Effekt sahnada oddiy "Guruh" sifatida saqlanadi:
//    group.userData.effect = { type, params }
//  shuning uchun saqlash, undo, nusxa, ierarxiya avtomatik ishlaydi.
//
//  Yangi effekt qo'shish: registerEffect({ id, label, icon, params,
//    blending, spawn(p, P, rnd), update(p, dt, P, t) })
// ============================================================
import * as THREE from 'three';
import { t } from './i18n.js';

export const effects = new Map();

export function registerEffect(def) {
  effects.set(def.id, { blending: 'add', ...def });
}

export function effectDefaults(id) {
  const out = {};
  for (const p of effects.get(id).params) out[p.key] = p.default;
  return out;
}

const n = (key, label, def, min, max, step = 0.05) => ({ key, label, type: 'number', default: def, min, max, step });
const c = (key, label, def) => ({ key, label, type: 'color', default: def });
const common = (o) => [
  n('rate', 'fx.rate', o.rate, 1, 600, 5),
  n('life', 'fx.life', o.life, 0.1, 10, 0.1),
  n('size', 'fx.size', o.size, 0.01, 5, 0.02),
  n('speed', 'fx.speed', o.speed, 0, 10, 0.1),
  n('radius', 'fx.radius', o.radius, 0, 20, 0.05),
  n('opacity', 'fx.opacity', o.opacity ?? 1, 0, 2, 0.05),
  c('c0', 'fx.c0', o.c0),
  c('c1', 'fx.c1', o.c1),
  c('c2', 'fx.c2', o.c2),
];

// ---------- Effekt turlari ----------
registerEffect({
  id: 'fire', label: 'fx.fire', icon: 'flame',
  params: [...common({ rate: 140, life: 0.9, size: 0.55, speed: 1.4, radius: 0.22, c0: '#fff2a8', c1: '#ff7a1a', c2: '#a10f06' }), n('turbulence', 'fx.turb', 0.8, 0, 5)],
  spawn(p, P, r) {
    const a = r() * Math.PI * 2, d = Math.sqrt(r()) * P.radius;
    p.x = Math.cos(a) * d; p.y = 0; p.z = Math.sin(a) * d;
    p.vx = -p.x * 0.6; p.vz = -p.z * 0.6; p.vy = P.speed * (0.6 + r() * 0.6);
    p.size = P.size * (0.7 + r() * 0.6);
  },
  update(p, dt, P, t) {
    p.vy += P.speed * 0.8 * dt;
    const k = P.turbulence * dt * 3;
    p.vx += Math.sin(t * 7 + p.seed * 40 + p.y * 3) * k;
    p.vz += Math.cos(t * 6 + p.seed * 30 + p.y * 3) * k;
    p.curSize = p.size * (1 - p.age * 0.65);
  },
});

registerEffect({
  id: 'sparks', label: 'fx.sparks', icon: 'sparkle',
  params: [...common({ rate: 35, life: 1.8, size: 0.09, speed: 1.8, radius: 0.4, c0: '#fffbe0', c1: '#ffb01f', c2: '#ff3a00' }), n('gravity', 'fx.gravity', 0.4, -5, 5, 0.1)],
  spawn(p, P, r) {
    const a = r() * Math.PI * 2, d = Math.sqrt(r()) * P.radius;
    p.x = Math.cos(a) * d; p.y = r() * 0.3; p.z = Math.sin(a) * d;
    const s = P.speed * (0.5 + r());
    p.vx = (r() - 0.5) * s; p.vy = s * (0.5 + r() * 0.8); p.vz = (r() - 0.5) * s;
    p.size = P.size * (0.6 + r() * 0.8);
  },
  update(p, dt, P, t) {
    p.vy += P.gravity * dt;
    p.vx += Math.sin(t * 3 + p.seed * 20) * dt * 0.6;
    const flick = 0.6 + 0.4 * Math.sin(t * 30 + p.seed * 100);
    p.curSize = p.size * flick * (1 - p.age * 0.5);
  },
});

registerEffect({
  id: 'smoke', label: 'fx.smoke', icon: 'smoke', blending: 'normal',
  params: [...common({ rate: 18, life: 3.5, size: 1.0, speed: 0.6, radius: 0.25, opacity: 0.45, c0: '#9a9a9a', c1: '#5c5c5c', c2: '#2b2b2b' })],
  spawn(p, P, r) {
    const a = r() * Math.PI * 2, d = Math.sqrt(r()) * P.radius;
    p.x = Math.cos(a) * d; p.y = 0; p.z = Math.sin(a) * d;
    p.vx = (r() - 0.5) * 0.2; p.vy = P.speed * (0.7 + r() * 0.5); p.vz = (r() - 0.5) * 0.2;
    p.size = P.size * (0.6 + r() * 0.5);
  },
  update(p, dt, P, t) {
    p.vx += Math.sin(t * 0.7 + p.seed * 10) * dt * 0.15;
    p.curSize = p.size * (0.5 + p.age * 1.8);
  },
});

registerEffect({
  id: 'magic', label: 'fx.magic', icon: 'magic',
  params: [...common({ rate: 70, life: 2.2, size: 0.14, speed: 0.7, radius: 0.8, c0: '#ffffff', c1: '#b48cff', c2: '#3a3cff' }), n('swirl', 'fx.swirl', 2.5, -10, 10, 0.1)],
  spawn(p, P, r) {
    const a = r() * Math.PI * 2;
    p.x = Math.cos(a) * P.radius; p.y = r() * 0.2; p.z = Math.sin(a) * P.radius;
    p.vx = 0; p.vy = P.speed * (0.5 + r()); p.vz = 0;
    p.ang = a;
    p.size = P.size * (0.6 + r() * 0.8);
  },
  update(p, dt, P, t) {
    // aylanma harakat (lokal koordinatada hisoblanadi)
    p.ang += P.swirl * dt;
    const rr = P.radius * (1 - p.age * 0.5);
    p.lx = Math.cos(p.ang) * rr; p.lz = Math.sin(p.ang) * rr;
    p.curSize = p.size * (0.6 + 0.4 * Math.sin(t * 12 + p.seed * 50));
  },
  local: true,
});

registerEffect({
  id: 'snow', label: 'fx.snow', icon: 'snow', blending: 'normal',
  params: [...common({ rate: 60, life: 6, size: 0.07, speed: 0.6, radius: 5, opacity: 0.9, c0: '#ffffff', c1: '#eef6ff', c2: '#d8e8ff' })],
  spawn(p, P, r) {
    p.x = (r() - 0.5) * 2 * P.radius; p.y = 0; p.z = (r() - 0.5) * 2 * P.radius;
    p.vx = 0; p.vy = -P.speed * (0.6 + r() * 0.6); p.vz = 0;
    p.size = P.size * (0.6 + r() * 0.8);
  },
  update(p, dt, P, t) {
    p.vx = Math.sin(t * 1.3 + p.seed * 20) * 0.25;
    p.vz = Math.cos(t * 1.1 + p.seed * 15) * 0.25;
    p.curSize = p.size;
  },
});

registerEffect({
  id: 'energy', label: 'fx.energy', icon: 'zap',
  params: [...common({ rate: 120, life: 0.6, size: 0.25, speed: 0.2, radius: 0.5, c0: '#e8fbff', c1: '#3cc8ff', c2: '#1236ff' })],
  spawn(p, P, r) {
    // shar sirti bo'ylab
    const u = r() * 2 - 1, a = r() * Math.PI * 2, s = Math.sqrt(1 - u * u);
    p.x = s * Math.cos(a) * P.radius; p.y = u * P.radius; p.z = s * Math.sin(a) * P.radius;
    p.vx = p.x * P.speed; p.vy = p.y * P.speed; p.vz = p.z * P.speed;
    p.size = P.size * (0.5 + r());
  },
  update(p) {
    p.curSize = p.size * (1 - p.age);
  },
});

// ============================================================
//  Zarrachalar shaderi
// ============================================================
const vert = /* glsl */ `
attribute float aLife;
attribute float aSize;
uniform float uScale;
varying float vLife;
void main() {
  vLife = aLife;
  vec4 mv = modelViewMatrix * vec4(position, 1.0);
  gl_Position = projectionMatrix * mv;
  gl_PointSize = max(aSize * uScale / max(-mv.z, 0.01), 0.0);
}`;
const frag = /* glsl */ `
uniform vec3 uC0; uniform vec3 uC1; uniform vec3 uC2;
uniform float uOpacity;
varying float vLife;
void main() {
  vec2 c = gl_PointCoord - 0.5;
  float d = length(c) * 2.0;
  if (d > 1.0) discard;
  float a = pow(1.0 - d, 1.6);
  vec3 col = vLife < 0.5 ? mix(uC0, uC1, vLife * 2.0) : mix(uC1, uC2, (vLife - 0.5) * 2.0);
  float fade = smoothstep(0.0, 0.08, vLife) * (1.0 - smoothstep(0.55, 1.0, vLife));
  gl_FragColor = vec4(col, a * fade * uOpacity);
  #include <tonemapping_fragment>
  #include <colorspace_fragment>
}`;

class ParticleSystem {
  constructor(type) {
    this.type = type;
    this.def = effects.get(type);
    this.max = 0;
    this.parts = [];
    this.acc = 0;
    this.seed = Math.random() * 1000;
    this.geometry = new THREE.BufferGeometry();
    this.material = new THREE.ShaderMaterial({
      vertexShader: vert,
      fragmentShader: frag,
      uniforms: {
        uScale: { value: 400 },
        uC0: { value: new THREE.Color() },
        uC1: { value: new THREE.Color() },
        uC2: { value: new THREE.Color() },
        uOpacity: { value: 1 },
      },
      transparent: true,
      depthWrite: false,
      blending: this.def.blending === 'normal' ? THREE.NormalBlending : THREE.AdditiveBlending,
    });
    this.points = new THREE.Points(this.geometry, this.material);
    this.points.frustumCulled = false;
    this.points.renderOrder = 5;
  }

  _ensure(max) {
    if (max <= this.max) return;
    this.max = max;
    this.pos = new Float32Array(max * 3);
    this.life = new Float32Array(max);
    this.size = new Float32Array(max);
    this.geometry.setAttribute('position', new THREE.BufferAttribute(this.pos, 3).setUsage(THREE.DynamicDrawUsage));
    this.geometry.setAttribute('aLife', new THREE.BufferAttribute(this.life, 1).setUsage(THREE.DynamicDrawUsage));
    this.geometry.setAttribute('aSize', new THREE.BufferAttribute(this.size, 1).setUsage(THREE.DynamicDrawUsage));
  }

  rnd() {
    // tez psevdo-tasodifiy
    this.seed = (this.seed * 16807 + 0.5) % 2147483647;
    return (this.seed % 100000) / 100000;
  }

  update(dt, time, group, camera, viewH) {
    const P = { ...effectDefaults(this.type), ...(group.userData.effect.params || {}) };
    const def = this.def;
    const m = this.material.uniforms;
    m.uC0.value.set(P.c0);
    m.uC1.value.set(P.c1);
    m.uC2.value.set(P.c2);
    m.uOpacity.value = P.opacity;
    m.uScale.value = viewH / (2 * Math.tan(THREE.MathUtils.degToRad(camera.fov) / 2));

    group.updateWorldMatrix(true, false);
    const mw = group.matrixWorld;
    const ws = new THREE.Vector3().setFromMatrixScale(mw);
    const sc = (Math.abs(ws.x) + Math.abs(ws.y) + Math.abs(ws.z)) / 3 || 1;
    const q = new THREE.Quaternion();
    mw.decompose(new THREE.Vector3(), q, new THREE.Vector3());

    const cap = Math.min(3000, Math.ceil(P.rate * P.life * 1.3) + 4);
    this._ensure(cap);

    // Yangi zarrachalar
    this.acc += P.rate * dt;
    const v = new THREE.Vector3();
    while (this.acc >= 1) {
      this.acc -= 1;
      if (this.parts.length >= cap) break;
      const p = { age: 0, seed: this.rnd(), x: 0, y: 0, z: 0, vx: 0, vy: 0, vz: 0, size: P.size, curSize: P.size };
      def.spawn(p, P, () => this.rnd());
      p.lifeSpan = P.life * (0.75 + this.rnd() * 0.5);
      if (!def.local) {
        v.set(p.x, p.y, p.z).applyMatrix4(mw);
        p.x = v.x; p.y = v.y; p.z = v.z;
        v.set(p.vx, p.vy, p.vz).applyQuaternion(q).multiplyScalar(sc);
        p.vx = v.x; p.vy = v.y; p.vz = v.z;
      } else {
        p.ly = p.y;
      }
      this.parts.push(p);
    }

    // Harakat
    let w = 0;
    const out = this.parts;
    for (let i = 0; i < out.length; i++) {
      const p = out[i];
      p.age += dt / p.lifeSpan;
      if (p.age >= 1) continue;
      if (def.local) {
        p.ly += p.vy * dt;
        def.update(p, dt, P, time);
        v.set(p.lx, p.ly, p.lz).applyMatrix4(mw);
        p.x = v.x; p.y = v.y; p.z = v.z;
      } else {
        // tezlanishlar lokal fazoda emas, dunyo fazosida (oddiyroq)
        const vy0 = p.vy;
        def.update(p, dt, P, time);
        if (p.vy !== vy0) p.vy = vy0 + (p.vy - vy0) * sc;
        p.x += p.vx * dt;
        p.y += p.vy * dt;
        p.z += p.vz * dt;
      }
      out[w++] = p;
      const k = w - 1;
      this.pos[k * 3] = p.x;
      this.pos[k * 3 + 1] = p.y;
      this.pos[k * 3 + 2] = p.z;
      this.life[k] = p.age;
      this.size[k] = Math.max(0, p.curSize) * sc;
    }
    out.length = w;
    this.geometry.setDrawRange(0, w);
    this.geometry.attributes.position.needsUpdate = true;
    this.geometry.attributes.aLife.needsUpdate = true;
    this.geometry.attributes.aSize.needsUpdate = true;
  }

  dispose() {
    this.geometry.dispose();
    this.material.dispose();
  }
}

// ============================================================
//  Effektlar tizimi: sahnadagi effekt guruhlarini kuzatadi
// ============================================================
export class EffectSystem {
  constructor(editor) {
    this.editor = editor;
    this.layer = new THREE.Group();
    this.layer.name = '__effects';
    editor.scene.add(this.layer);
    this.systems = new Map(); // group.uuid -> ParticleSystem
    this.list = [];
    this.enabled = true;
    const refresh = () => this.refresh();
    editor.on('sceneGraphChanged', refresh);
    editor.on('projectLoaded', refresh);
    editor.updaters.add((dt, t) => this.update(dt, t));
    // Tanlash uchun belgi
    editor.proxyTypes.push({
      match: (o) => !!o.userData?.effect,
      create: (o) => {
        const col = new THREE.Color(o.userData.effect.params?.c1 || '#ff7a1a');
        const m = new THREE.Mesh(new THREE.TetrahedronGeometry(0.16), new THREE.MeshBasicMaterial({ color: col, wireframe: true }));
        return m;
      },
    });
  }

  refresh() {
    const found = [];
    this.editor.content.traverse((o) => o.userData?.effect && effects.has(o.userData.effect.type) && found.push(o));
    this.list = found;
    const keep = new Set(found.map((o) => o.uuid));
    for (const [id, sys] of this.systems) {
      const o = found.find((f) => f.uuid === id);
      if (!keep.has(id) || (o && o.userData.effect.type !== sys.type)) {
        this.layer.remove(sys.points);
        sys.dispose();
        this.systems.delete(id);
      }
    }
    for (const o of found) {
      if (this.systems.has(o.uuid)) continue;
      const sys = new ParticleSystem(o.userData.effect.type);
      this.systems.set(o.uuid, sys);
      this.layer.add(sys.points);
    }
  }

  update(dt, time) {
    if (!this.list.length) return false;
    this.layer.visible = this.enabled;
    if (!this.enabled) return false;
    const E = this.editor;
    const viewH = E.renderer.domElement.height;
    for (const o of this.list) {
      const sys = this.systems.get(o.uuid);
      if (!sys) continue;
      const vis = E._visibleChain(o) && o.parent;
      sys.points.visible = !!vis;
      if (vis) sys.update(dt, time, o, E.camera, viewH);
    }
    return true;
  }

  /** Effekt obyektini yaratadi (sahnaga qo'shish uchun). */
  create(type) {
    const g = new THREE.Group();
    g.name = this.editor.uniqueName(t(effects.get(type).label));
    g.userData.effect = { type, params: effectDefaults(type) };
    const tgt = this.editor.orbit.target;
    g.position.set(tgt.x, 0, tgt.z);
    return g;
  }
}
