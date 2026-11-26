// ============================================================
//  MUHARRIR YADROSI (Editor)
//  Sahna, kamera, boshqaruv, tanlash, gizmo, saqlash/yuklash.
//  UI faqat shu klassning metodlari va hodisalari orqali ishlaydi:
//    on('selectionChanged'), on('objectChanged'), on('sceneGraphChanged'),
//    on('historyChanged'), on('sceneSettingsChanged'), on('modeChanged')
// ============================================================
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { TransformControls } from 'three/examples/jsm/controls/TransformControls.js';
import { RoomEnvironment } from 'three/examples/jsm/environments/RoomEnvironment.js';
import { EffectComposer } from 'three/examples/jsm/postprocessing/EffectComposer.js';
import { RenderPass } from 'three/examples/jsm/postprocessing/RenderPass.js';
import { UnrealBloomPass } from 'three/examples/jsm/postprocessing/UnrealBloomPass.js';
import { OutputPass } from 'three/examples/jsm/postprocessing/OutputPass.js';
import { Emitter } from './events.js';
import { History } from './history.js';
import { buildGeometry, primitives, defaultParams } from './primitives.js';
import { createMaterial, palette } from './materials.js';
import { t } from './i18n.js';

// Tekstura rasmlarini har bir undo-suratda qayta kodlamaslik uchun kesh.
const _srcToJSON = THREE.Source.prototype.toJSON;
THREE.Source.prototype.toJSON = function (meta) {
  if (this._jsonCache && this._jsonCacheVersion === this.version) {
    if (meta && typeof meta === 'object' && meta.images) meta.images[this.uuid] = this._jsonCache;
    return this._jsonCache;
  }
  const out = _srcToJSON.call(this, meta);
  this._jsonCache = out;
  this._jsonCacheVersion = this.version;
  return out;
};

// ------------------------------------------------------------
// Ixcham suratlar (undo uchun): geometriya massivlari JSON massivga
// aylantirilmaydi, balki o'zgarmagan bo'lsa bir xil nusxa qayta ishlatiladi.
// Bu katta (sculpt qilingan) modellarda xotira va tezlikni keskin yaxshilaydi.
// ------------------------------------------------------------
let compactMode = false;
const _attrToJSON = THREE.BufferAttribute.prototype.toJSON;
THREE.BufferAttribute.prototype.toJSON = function () {
  if (!compactMode) return _attrToJSON.call(this);
  const c = this._snapCache;
  if (c && c.version === this.version && c.count === this.count) return c.json;
  const json = { itemSize: this.itemSize, type: this.array.constructor.name, array: this.array.slice(), normalized: this.normalized };
  this._snapCache = { version: this.version, count: this.count, json };
  return json;
};
export function withCompact(fn) {
  const prev = compactMode;
  compactMode = true;
  try {
    return fn();
  } finally {
    compactMode = prev;
  }
}

export const defaultSceneSettings = {
  background: '#1e232b',
  grid: true,
  axes: true,
  env: true,
  envIntensity: 1,
  studio: true,
  studioIntensity: 1,
  shadows: true,
  ground: true,
  fog: false,
  exposure: 1,
  bloom: false,
  bloomStrength: 0.7,
  bloomRadius: 0.35,
  bloomThreshold: 1.0,
  anim: { duration: 4, fps: 30, loop: true },
};

const LIGHT_TYPES = ['PointLight', 'SpotLight', 'DirectionalLight', 'HemisphereLight', 'AmbientLight'];

export class Editor extends Emitter {
  constructor(container, settings) {
    super();
    this.container = container;
    this.settings = settings;
    this.selected = null;
    this.mode = 'translate';
    this.sceneSettings = { ...defaultSceneSettings };
    this._needsRender = true;
    this._colorIndex = 3;
    this._counters = {};

    // ---- Renderer ----
    const r = (this.renderer = new THREE.WebGLRenderer({ antialias: true, powerPreference: 'high-performance' }));
    r.setPixelRatio(this.pixelRatio());
    r.shadowMap.enabled = true;
    r.shadowMap.type = THREE.PCFSoftShadowMap;
    r.toneMapping = THREE.ACESFilmicToneMapping;
    r.outputColorSpace = THREE.SRGBColorSpace;
    r.domElement.className = 'viewport-canvas';
    container.appendChild(r.domElement);

    // ---- Sahna ----
    this.scene = new THREE.Scene();
    this.camera = new THREE.PerspectiveCamera(50, 1, 0.01, 5000);
    this.camera.position.set(4.5, 3.5, 6);

    this.content = new THREE.Group();
    this.content.name = 'Scene';
    this.scene.add(this.content);

    this.helpers = new THREE.Group();
    this.helpers.name = '__helpers';
    this.scene.add(this.helpers);

    // Studiya yorug'ligi (loyihaga kirmaydi, faqat ko'rish uchun)
    this.studio = new THREE.Group();
    this.studioHemi = new THREE.HemisphereLight(0xffffff, 0x445566, 0.6);
    this.studioKey = new THREE.DirectionalLight(0xffffff, 1.6);
    this.studioKey.position.set(5, 9, 6);
    this.studioKey.castShadow = true;
    const sc = this.studioKey.shadow.camera;
    sc.left = sc.bottom = -12;
    sc.right = sc.top = 12;
    sc.near = 0.5;
    sc.far = 40;
    this.studioKey.shadow.bias = -0.0005;
    this.studioKey.shadow.normalBias = 0.02;
    this.studio.add(this.studioHemi, this.studioKey);
    this.scene.add(this.studio);

    this.grid = new THREE.GridHelper(40, 40, 0x5b6573, 0x363d48);
    this.grid.material.transparent = true;
    this.grid.material.opacity = 0.8;
    this.grid.material.depthWrite = false;
    this.helpers.add(this.grid);
    this.axes = new THREE.AxesHelper(1.2);
    this.axes.material.depthTest = false;
    this.axes.renderOrder = 1;
    this.helpers.add(this.axes);

    this.ground = new THREE.Mesh(new THREE.PlaneGeometry(200, 200), new THREE.ShadowMaterial({ opacity: 0.28 }));
    this.ground.rotation.x = -Math.PI / 2;
    this.ground.position.y = -0.001;
    this.ground.receiveShadow = true;
    this.helpers.add(this.ground);

    this.lightProxies = new THREE.Group();
    this.helpers.add(this.lightProxies);

    this.selectionBox = new THREE.BoxHelper(undefined, 0xffc233);
    this.selectionBox.material.depthTest = false;
    this.selectionBox.material.transparent = true;
    this.selectionBox.renderOrder = 2;
    this.selectionBox.visible = false;
    this.helpers.add(this.selectionBox);

    const pmrem = new THREE.PMREMGenerator(r);
    this.envMap = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
    pmrem.dispose();

    // ---- Boshqaruv ----
    this.orbit = new OrbitControls(this.camera, r.domElement);
    this.orbit.enableDamping = true;
    this.orbit.dampingFactor = 0.12;
    this.orbit.screenSpacePanning = true;
    this.orbit.target.set(0, 0.5, 0);
    this.orbit.maxDistance = 1500;
    this.orbit.autoRotateSpeed = 1.5;
    this.orbit.addEventListener('change', () => this.requestRender());

    this.transform = new TransformControls(this.camera, r.domElement);
    this.transform.setSize(1.1);
    this.scene.add(this.transform.getHelper());
    this._dragStart = new THREE.Matrix4();
    this.transform.addEventListener('dragging-changed', (e) => {
      this.orbit.enabled = !e.value;
      if (e.value) {
        this._dragStart.copy(this.transform.object.matrix);
      } else if (this.transform.object && !this._dragStart.equals(this.transform.object.matrix)) {
        this.commit('transform');
      }
    });
    this.transform.addEventListener('objectChange', () => {
      this.emit('objectChanged', this.selected, 'transform');
      this.requestRender();
    });
    this.transform.addEventListener('change', () => this.requestRender());

    // ---- Post-processing (Bloom — nur taralishi) ----
    this.composer = new EffectComposer(r);
    this.composer.addPass(new RenderPass(this.scene, this.camera));
    this.bloomPass = new UnrealBloomPass(new THREE.Vector2(256, 256), 0.8, 0.4, 0.75);
    this.composer.addPass(this.bloomPass);
    this.composer.addPass(new OutputPass());

    // Har kadrda chaqiriladigan funksiyalar (effektlar, animatsiya, sculpt):
    // fn(dt, time) => true qaytarsa, kadr qayta chiziladi.
    this.updaters = new Set();
    // Kursor hodisalarini ushlab qoluvchi (masalan, sculpt rejimi)
    this.pointerInterceptor = null;
    // Tanlanadigan "proksi"lar (yorug'lik va effektlar uchun belgi)
    this.proxyTypes = [];

    this.history = new History(this);
    this.raycaster = new THREE.Raycaster();
    this._initPointer();
    this.applySnap();
    this.applySceneSettings();

    this._resizeObserver = new ResizeObserver(() => this.resize());
    this._resizeObserver.observe(container);
    this.resize();
    this.history.reset();

    this.clock = new THREE.Clock();
    this.time = 0;
    const loop = () => {
      requestAnimationFrame(loop);
      const dt = Math.min(this.clock.getDelta(), 0.1);
      this.time += dt;
      let dirty = false;
      for (const fn of this.updaters) {
        try {
          if (fn(dt, this.time)) dirty = true;
        } catch (e) {
          console.error(e);
        }
      }
      const moved = this.orbit.update();
      if (moved || dirty || this._needsRender || this.orbit.autoRotate) this.render();
    };
    loop();
  }

  // ===================== Render =====================
  pixelRatio() {
    const dpr = window.devicePixelRatio || 1;
    switch (this.settings.quality) {
      case 'low': return 1;
      case 'medium': return Math.min(dpr, 1.5);
      case 'high': return Math.min(dpr, 3);
      default: return Math.min(dpr, 2);
    }
  }
  applyQuality() {
    this.renderer.setPixelRatio(this.pixelRatio());
    const size = this.settings.quality === 'low' ? 1024 : this.settings.quality === 'high' ? 4096 : 2048;
    this.studioKey.shadow.mapSize.set(size, size);
    this.studioKey.shadow.map?.dispose();
    this.studioKey.shadow.map = null;
    this.resize();
  }
  requestRender() {
    this._needsRender = true;
  }
  resize() {
    const w = this.container.clientWidth || 1;
    const h = this.container.clientHeight || 1;
    this.camera.aspect = w / h;
    this.camera.updateProjectionMatrix();
    this.renderer.setSize(w, h, false);
    const pr = this.renderer.getPixelRatio();
    this.composer.setPixelRatio(pr);
    this.composer.setSize(w, h);
    // Bloom yarim o'lchamda — telefonlarda tezroq
    this.bloomPass.resolution.set(Math.max(1, (w * pr) / 2), Math.max(1, (h * pr) / 2));
    this.requestRender();
  }
  render() {
    this._needsRender = false;
    this.selectionBox.visible = !!this.selected && this.mode !== 'sculpt';
    if (this.selected) {
      const proxy = this._proxyFor(this.selected);
      this.selectionBox.setFromObject(proxy || this.selected);
    }
    for (const p of this.lightProxies.children) {
      const L = p.userData.light || p.userData.target;
      L.updateWorldMatrix(true, false);
      p.position.setFromMatrixPosition(L.matrixWorld);
      p.visible = this._visibleChain(L);
      if (p.userData.line) {
        const pos = p.userData.line.geometry.attributes.position;
        pos.setXYZ(0, p.position.x, p.position.y, p.position.z);
        const tgt = L.target ? L.target.getWorldPosition(new THREE.Vector3()) : new THREE.Vector3();
        pos.setXYZ(1, tgt.x, tgt.y, tgt.z);
        pos.needsUpdate = true;
        p.userData.line.visible = p.visible;
      }
    }
    this.emit('beforeRender');
    if (this.sceneSettings.bloom) this.composer.render();
    else this.renderer.render(this.scene, this.camera);
  }

  // ===================== Sahna sozlamalari =====================
  setSceneSetting(key, value, commit = true) {
    this.sceneSettings[key] = value;
    this.applySceneSettings();
    if (commit) this.commit('scene');
  }
  applySceneSettings() {
    const s = this.sceneSettings;
    this.scene.background = new THREE.Color(s.background);
    this.grid.visible = !!s.grid;
    this.axes.visible = !!s.axes;
    this.scene.environment = s.env ? this.envMap : null;
    this.scene.environmentIntensity = s.envIntensity;
    this.studio.visible = !!s.studio;
    this.studioHemi.intensity = 0.6 * s.studioIntensity;
    this.studioKey.intensity = 1.6 * s.studioIntensity;
    const shadowsChanged = this.renderer.shadowMap.enabled !== !!s.shadows;
    this.renderer.shadowMap.enabled = !!s.shadows;
    this.bloomPass.strength = s.bloomStrength ?? 0.7;
    this.bloomPass.radius = s.bloomRadius ?? 0.4;
    this.bloomPass.threshold = s.bloomThreshold ?? 1.0;
    if (!s.anim) s.anim = { ...defaultSceneSettings.anim };
    this.ground.visible = !!s.ground && !!s.shadows;
    this.renderer.toneMappingExposure = s.exposure;
    this.scene.fog = s.fog ? new THREE.Fog(s.background, 8, 60) : null;
    // shadowMap yoqilganda/o'chirilganda materiallarni qayta kompilyatsiya qilish kerak
    if (shadowsChanged) {
      this.scene.traverse((o) => {
        if (o.material) [].concat(o.material).forEach((m) => (m.needsUpdate = true));
      });
    }
    this.emit('sceneSettingsChanged');
    this.requestRender();
  }

  // ===================== Tanlash =====================
  _initPointer() {
    const el = this.renderer.domElement;
    const active = new Map();
    let down = null;
    el.addEventListener(
      'pointerdown',
      (e) => {
        if (this.pointerInterceptor && this.pointerInterceptor.down(e, active.size)) {
          e.stopImmediatePropagation();
          this._intercepting = e.pointerId;
          el.setPointerCapture?.(e.pointerId);
        }
      },
      { capture: true }
    );
    el.addEventListener('pointermove', (e) => {
      if (this._intercepting === e.pointerId) this.pointerInterceptor?.move(e);
    });
    el.addEventListener('pointerdown', (e) => {
      active.set(e.pointerId, true);
      if (this._intercepting === e.pointerId) return (down = null);
      if (active.size > 1) down = null;
      else down = { x: e.clientX, y: e.clientY, t: performance.now(), gizmo: this.transform.dragging };
    });
    const up = (e) => {
      if (this._intercepting === e.pointerId) {
        this._intercepting = null;
        active.delete(e.pointerId);
        this.pointerInterceptor?.up(e);
        return (down = null);
      }
      const wasMulti = active.size > 1;
      active.delete(e.pointerId);
      if (!down || wasMulti || e.type === 'pointercancel') return (down = null);
      const d = Math.hypot(e.clientX - down.x, e.clientY - down.y);
      const dt = performance.now() - down.t;
      if (!down.gizmo && d < 8 && dt < 500 && this.mode !== 'sculpt') this.pickAt(e.clientX, e.clientY);
      down = null;
    };
    el.addEventListener('pointerup', up);
    el.addEventListener('pointercancel', up);
  }

  _visibleChain(o) {
    while (o) {
      if (!o.visible) return false;
      o = o.parent;
    }
    return true;
  }

  pickAt(clientX, clientY) {
    const rect = this.renderer.domElement.getBoundingClientRect();
    const ndc = new THREE.Vector2(((clientX - rect.left) / rect.width) * 2 - 1, -((clientY - rect.top) / rect.height) * 2 + 1);
    this.raycaster.setFromCamera(ndc, this.camera);
    const hits = this.raycaster.intersectObjects([this.content, this.lightProxies], true);
    for (const h of hits) {
      if (!this._visibleChain(h.object)) continue;
      if (h.object.isLine || h.object.isPoints) continue;
      let obj = h.object.userData.light || h.object.userData.target || this.selectableFor(h.object);
      if (!obj || this.isLocked(obj)) continue;
      this.select(obj);
      return obj;
    }
    this.select(null);
    return null;
  }

  isLocked(obj) {
    for (let o = obj; o && o !== this.content; o = o.parent) if (o.userData.locked) return true;
    return false;
  }

  /** Import qilingan model ichidagi bo'lak bosilsa, butun model tanlanadi. */
  selectableFor(obj) {
    let top = null;
    for (let o = obj; o && o !== this.content; o = o.parent) if (o.userData.imported) top = o;
    return top || obj;
  }

  select(obj) {
    if (obj === this.selected) return;
    this.selected = obj || null;
    this.selectionBox.visible = !!obj;
    this._attachGizmo();
    this.emit('selectionChanged', this.selected);
    this.requestRender();
  }

  _attachGizmo() {
    const obj = this.selected;
    if (!obj || this.mode === 'select' || this.mode === 'sculpt' || this.isLocked(obj) || obj.isHemisphereLight || obj.isAmbientLight) {
      this.transform.detach();
    } else {
      this.transform.attach(obj);
      this.transform.setMode(this.mode);
    }
  }

  setMode(mode) {
    this.mode = mode;
    this._attachGizmo();
    this.emit('modeChanged', mode);
    this.requestRender();
  }

  applySnap() {
    const s = this.settings;
    this.transform.setTranslationSnap(s.snap ? s.snapMove : null);
    this.transform.setRotationSnap(s.snap ? THREE.MathUtils.degToRad(s.snapRotate) : null);
    this.transform.setScaleSnap(s.snap ? s.snapScale : null);
    this.transform.setSpace(s.space);
  }

  // ===================== Obyektlar =====================
  nextColor() {
    const c = palette[this._colorIndex % palette.length];
    this._colorIndex++;
    if (this._colorIndex % palette.length < 3) this._colorIndex = 3;
    return c;
  }

  uniqueName(base) {
    const names = new Set();
    this.content.traverse((o) => names.add(o.name));
    if (!names.has(base)) return base;
    for (let k = 2; ; k++) if (!names.has(`${base} ${k}`)) return `${base} ${k}`;
  }

  createPrimitive(id, params = {}) {
    const def = primitives.get(id);
    const p = { ...defaultParams(id), ...params };
    const mesh = new THREE.Mesh(buildGeometry(id, p), createMaterial('MeshStandardMaterial', { color: this.nextColor(), roughness: 0.45 }));
    mesh.name = this.uniqueName(t(def.label));
    mesh.userData = { prim: id, params: p };
    mesh.castShadow = mesh.receiveShadow = true;
    if (def.vertexColors) {
      mesh.material.vertexColors = true;
      mesh.material.color.set('#ffffff');
      mesh.material.side = THREE.DoubleSide;
    }
    const bb = mesh.geometry.boundingBox;
    mesh.position.set(this.orbit.target.x, def.lift ? -bb.min.y : 0, this.orbit.target.z);
    return mesh;
  }

  createLight(type) {
    let L;
    switch (type) {
      case 'SpotLight':
        L = new THREE.SpotLight(0xffffff, 40, 0, Math.PI / 6, 0.35, 2);
        L.position.set(2.5, 4, 2.5);
        L.castShadow = true;
        break;
      case 'DirectionalLight':
        L = new THREE.DirectionalLight(0xffffff, 2);
        L.position.set(4, 7, 3);
        L.castShadow = true;
        Object.assign(L.shadow.camera, { left: -10, right: 10, top: 10, bottom: -10, far: 50 });
        break;
      case 'HemisphereLight':
        L = new THREE.HemisphereLight(0xbfdcff, 0x4a3b2a, 1);
        L.position.set(0, 5, 0);
        break;
      case 'AmbientLight':
        L = new THREE.AmbientLight(0xffffff, 0.5);
        L.position.set(0, 4.5, 0);
        break;
      default:
        L = new THREE.PointLight(0xffd8a8, 20, 0, 2);
        L.position.set(1.5, 2.5, 1.5);
        L.castShadow = true;
    }
    if (L.shadow) {
      L.shadow.bias = -0.0005;
      L.shadow.normalBias = 0.02;
      L.shadow.mapSize.set(1024, 1024);
    }
    L.name = this.uniqueName(t('light.' + type));
    return L;
  }

  createGroup() {
    const g = new THREE.Group();
    g.name = this.uniqueName(t('obj.group'));
    g.position.set(this.orbit.target.x, 0, this.orbit.target.z);
    return g;
  }

  addObject(obj, { parent = this.content, select = true, commit = true, label = 'add' } = {}) {
    parent.add(obj);
    this.syncLightProxies();
    this.emit('sceneGraphChanged');
    if (select) this.select(obj);
    if (commit) this.commit(label);
    this.requestRender();
    return obj;
  }

  removeObject(obj, { commit = true } = {}) {
    if (!obj || obj === this.content) return;
    if (this.selected && (this.selected === obj || isDescendant(this.selected, obj))) this.select(null);
    obj.removeFromParent();
    disposeObject(obj);
    this.syncLightProxies();
    this.emit('sceneGraphChanged');
    if (commit) this.commit('remove');
    this.requestRender();
  }

  duplicate(obj) {
    if (!obj) return null;
    const copy = deepClone(obj);
    copy.name = this.uniqueName(obj.name.replace(/ \d+$/, ''));
    copy.position.x += 0.4;
    copy.position.z += 0.4;
    return this.addObject(copy, { parent: obj.parent || this.content, label: 'duplicate' });
  }

  /** Shakl parametrlari o'zgarganda geometriyani qayta quradi. */
  updatePrimitive(mesh, params, commit = true) {
    const id = mesh.userData.prim;
    if (!primitives.has(id)) return;
    const p = { ...mesh.userData.params, ...params };
    const g = buildGeometry(id, p);
    mesh.geometry.dispose();
    mesh.geometry = g;
    mesh.userData.params = p;
    this.emit('objectChanged', mesh, 'geometry');
    this.emit('sceneGraphChanged');
    if (commit) this.commit('geometry');
    this.requestRender();
  }

  /** Ota obyektni almashtirish (dunyo holatini saqlagan holda). */
  setParent(obj, parent) {
    parent = parent || this.content;
    if (obj === parent || isDescendant(parent, obj)) return false;
    parent.attach(obj);
    this.emit('sceneGraphChanged');
    this.commit('parent');
    return true;
  }

  moveInOrder(obj, dir) {
    const p = obj.parent;
    const i = p.children.indexOf(obj);
    const j = i + dir;
    if (j < 0 || j >= p.children.length) return;
    p.children.splice(i, 1);
    p.children.splice(j, 0, obj);
    this.emit('sceneGraphChanged');
    this.commit('order');
  }

  // ===================== Yorug'lik belgilari =====================
  syncLightProxies() {
    for (const p of [...this.lightProxies.children]) {
      p.removeFromParent();
      p.geometry?.dispose();
      p.material?.dispose();
      if (p.userData.line) {
        p.userData.line.removeFromParent();
        p.userData.line.geometry.dispose();
        p.userData.line.material.dispose();
      }
    }
    this.content.traverse((o) => {
      for (const pt of this.proxyTypes) {
        if (!pt.match(o)) continue;
        const proxy = pt.create(o);
        proxy.userData.target = o;
        proxy.renderOrder = 3;
        this.lightProxies.add(proxy);
      }
      if (!o.isLight) return;
      const proxy = new THREE.Mesh(
        new THREE.OctahedronGeometry(0.16, 0),
        new THREE.MeshBasicMaterial({ color: o.color, wireframe: false, depthTest: true })
      );
      proxy.userData.light = o;
      proxy.renderOrder = 3;
      if (o.isDirectionalLight || o.isSpotLight) {
        const g = new THREE.BufferGeometry().setAttribute('position', new THREE.Float32BufferAttribute(6, 3));
        const line = new THREE.Line(g, new THREE.LineDashedMaterial({ color: 0xffe08a, dashSize: 0.15, gapSize: 0.1, transparent: true, opacity: 0.7 }));
        line.frustumCulled = false;
        proxy.userData.line = line;
        this.helpers.add(line);
      }
      this.lightProxies.add(proxy);
    });
    this.requestRender();
  }

  _proxyFor(obj) {
    if (!obj) return null;
    return this.lightProxies.children.find((p) => p.userData.light === obj || p.userData.target === obj) || null;
  }

  // ===================== Kamera =====================
  setView(name) {
    const target = this.orbit.target.clone();
    const dist = Math.max(this.camera.position.distanceTo(target), 2);
    const dirs = {
      front: [0, 0, 1], back: [0, 0, -1], right: [1, 0, 0], left: [-1, 0, 0],
      top: [0, 1, 0.0001], bottom: [0, -1, 0.0001], persp: [0.6, 0.45, 0.75],
    };
    const d = new THREE.Vector3(...(dirs[name] || dirs.persp)).normalize();
    this.animateCamera(target.clone().addScaledVector(d, dist), target);
  }

  focus(obj = this.selected) {
    const box = new THREE.Box3();
    if (obj) box.setFromObject(this._proxyFor(obj) || obj);
    else box.setFromObject(this.content);
    if (box.isEmpty()) box.set(new THREE.Vector3(-1, 0, -1), new THREE.Vector3(1, 1, 1));
    const sphere = box.getBoundingSphere(new THREE.Sphere());
    const r = Math.max(sphere.radius, 0.3);
    const dist = r / Math.sin(THREE.MathUtils.degToRad(this.camera.fov) / 2) * 1.15;
    const dir = this.camera.position.clone().sub(this.orbit.target).normalize();
    this.animateCamera(sphere.center.clone().addScaledVector(dir, dist), sphere.center);
  }

  animateCamera(toPos, toTarget, ms = 380) {
    const fromPos = this.camera.position.clone();
    const fromTarget = this.orbit.target.clone();
    const start = performance.now();
    const step = () => {
      const k = Math.min(1, (performance.now() - start) / ms);
      const e = 1 - Math.pow(1 - k, 3);
      this.camera.position.lerpVectors(fromPos, toPos, e);
      this.orbit.target.lerpVectors(fromTarget, toTarget, e);
      this.orbit.update();
      this.requestRender();
      if (k < 1) requestAnimationFrame(step);
    };
    step();
  }

  setAutoRotate(on) {
    this.orbit.autoRotate = on;
    this.requestRender();
  }

  // ===================== Saqlash / yuklash =====================
  /**
   * Sahna surati. Parametrli shakllarning geometriyasi saqlanmaydi —
   * yuklashda parametrlardan qayta quriladi (fayl va xotira ancha kichik).
   */
  snapshot({ compact = true } = {}) {
    // toJSON obyekt matritsasini saqlaydi — avval yangilab olamiz
    this.content.updateMatrixWorld(true);
    const json = compact ? withCompact(() => this.content.toJSON()) : this.content.toJSON();
    const stub = new Set();
    const walk = (o) => {
      if (o.geometry && o.userData?.params && primitives.has(o.userData.prim)) stub.add(o.geometry);
      (o.children || []).forEach(walk);
    };
    const noNormals = new Set();
    const walk2 = (o) => {
      if (o.geometry && o.userData?.sculpt) noNormals.add(o.geometry);
      (o.children || []).forEach(walk2);
    };
    walk(json.object);
    walk2(json.object);
    if (json.geometries) {
      json.geometries = json.geometries.map((g) => {
        if (stub.has(g.uuid)) return { uuid: g.uuid, type: 'BufferGeometry', data: { attributes: {} } };
        // Sculpt modellarining normallari saqlanmaydi — yuklashda qayta hisoblanadi
        if (noNormals.has(g.uuid) && g.data?.attributes?.normal) {
          const { normal, ...attributes } = g.data.attributes;
          return { ...g, data: { ...g.data, attributes } };
        }
        return g;
      });
    }
    return { scene: JSON.parse(JSON.stringify(this.sceneSettings)), content: json };
  }

  _rebuildPrimitives(root) {
    root.traverse((o) => {
      if (o.isMesh && o.geometry?.attributes.position && !o.geometry.attributes.normal) o.geometry.computeVertexNormals();
      if (o.isMesh && o.userData.params && primitives.has(o.userData.prim) && !o.geometry.attributes.position) {
        o.geometry.dispose();
        o.geometry = buildGeometry(o.userData.prim, o.userData.params);
      }
    });
  }

  async restoreSnapshot(snap) {
    const loader = new THREE.ObjectLoader();
    const group = await loader.parseAsync(snap.content);
    this._rebuildPrimitives(group);
    const selUuid = this.selected?.uuid;
    this.select(null);
    for (const c of [...this.content.children]) {
      c.removeFromParent();
      disposeObject(c);
    }
    for (const c of [...group.children]) this.content.add(c);
    this.sceneSettings = { ...defaultSceneSettings, ...(snap.scene || {}) };
    this.applySceneSettings();
    this.syncLightProxies();
    this.emit('sceneGraphChanged');
    if (selUuid) {
      const o = this.content.getObjectByProperty('uuid', selUuid);
      if (o) this.select(o);
    }
    this.requestRender();
  }

  /** compact=true: IndexedDB uchun (tez); false: faylga yozish uchun (oddiy JSON) */
  serializeProject({ compact = false } = {}) {
    return {
      format: 'mini3d-project',
      version: 2,
      ...this.snapshot({ compact }),
      camera: { position: this.camera.position.toArray(), target: this.orbit.target.toArray() },
    };
  }


  async loadProject(data) {
    if (!data || data.format !== 'mini3d-project') throw new Error('bad project');
    await this.restoreSnapshot(data);
    if (data.camera) {
      this.camera.position.fromArray(data.camera.position);
      this.orbit.target.fromArray(data.camera.target);
      this.orbit.update();
    }
    this.history.reset();
    this.emit('projectLoaded');
  }

  clear() {
    this.select(null);
    for (const c of [...this.content.children]) {
      c.removeFromParent();
      disposeObject(c);
    }
    this.sceneSettings = { ...defaultSceneSettings };
    this.applySceneSettings();
    this.syncLightProxies();
    this.camera.position.set(4.5, 3.5, 6);
    this.orbit.target.set(0, 0.5, 0);
    this.orbit.update();
    this.emit('sceneGraphChanged');
    this.history.reset();
  }

  /** Har bir o'zgarishdan keyin: tarixga yozish + avtosaqlash signali */
  commit(label) {
    this.history.push(label);
    this.emit('changed', label);
    this.requestRender();
  }

  /** Yordamchi chiziqlar, gizmo va belgilarni vaqtincha yashiradi (rasm/video uchun). */
  hideHelpers() {
    const hidden = [];
    for (const c of this.helpers.children) {
      if (c !== this.ground && c.visible) {
        c.visible = false;
        hidden.push(c);
      }
    }
    const gizmo = this.transform.getHelper();
    if (gizmo.visible) {
      gizmo.visible = false;
      hidden.push(gizmo);
    }
    this.emit('helpersHidden', true);
    return () => {
      hidden.forEach((c) => (c.visible = true));
      this.emit('helpersHidden', false);
      this.requestRender();
    };
  }

  /** Ekran rasmi (PNG, dataURL). Yordamchi chiziqlar va gizmo yashiriladi. */
  screenshot(scale = 2) {
    const restore = this.hideHelpers();
    const prevRatio = this.renderer.getPixelRatio();
    this.renderer.setPixelRatio(Math.min(prevRatio * scale, 4));
    this.resize();
    this._needsRender = false;
    if (this.sceneSettings.bloom) this.composer.render();
    else this.renderer.render(this.scene, this.camera);
    const url = this.renderer.domElement.toDataURL('image/png');
    this.renderer.setPixelRatio(prevRatio);
    restore();
    this.resize();
    this.render();
    return url;
  }

  stats() {
    let objects = 0, tris = 0, verts = 0, lights = 0;
    this.content.traverse((o) => {
      if (o.isLight) lights++;
      if (o.isMesh) {
        objects++;
        const g = o.geometry;
        const pos = g.attributes.position;
        if (!pos) return;
        verts += pos.count;
        tris += (g.index ? g.index.count : pos.count) / 3;
      }
    });
    return { objects, tris: Math.round(tris), verts, lights };
  }

  isLight(o) {
    return !!o && LIGHT_TYPES.includes(o.type);
  }
}

// ===================== Yordamchi funksiyalar =====================
export function isDescendant(obj, ancestor) {
  for (let o = obj?.parent; o; o = o.parent) if (o === ancestor) return true;
  return false;
}

export function disposeObject(root) {
  root.traverse((o) => {
    if (o.geometry) o.geometry.dispose();
    if (o.material) {
      for (const m of [].concat(o.material)) {
        for (const k in m) if (m[k] && m[k].isTexture) m[k].dispose();
        m.dispose();
      }
    }
  });
}

/** Geometriya va materiallarni ham nusxalaydigan chuqur klon. */
export function deepClone(obj) {
  const c = obj.clone(true);
  c.traverse((o) => {
    if (o.isMesh || o.isLine || o.isPoints) {
      o.geometry = o.geometry.clone();
      o.material = Array.isArray(o.material) ? o.material.map((m) => m.clone()) : o.material.clone();
    }
  });
  return c;
}
