// ============================================================
//  ANIMATSIYA
//  1) Kalit kadrlar (keyframes): obyektning joyi/burilishi/o'lchami
//     vaqt bo'yicha yoziladi:  obj.userData.keys = [{t, p, q, s}]
//  2) Tayyor harakatlar (motion): qanot qoqish, suzish, aylanish,
//     nafas olish...           obj.userData.motion = {type, axis, ...}
//  3) GLB ga animatsiya bilan eksport, videoga (MP4/WebM) yozish.
//
//  Yangi harakat turi qo'shish: motions ga element qo'shing.
// ============================================================
import * as THREE from 'three';

const TAU = Math.PI * 2;
const D2R = Math.PI / 180;

export const motions = {
  flap: { label: 'motion.flap', icon: 'wing', defaults: { axis: 'x', amount: 35, speed: 1.2, phase: 0 }, unit: '°' },
  sway: { label: 'motion.sway', icon: 'rotate', defaults: { axis: 'z', amount: 12, speed: 0.6, phase: 0 }, unit: '°' },
  bob: { label: 'motion.bob', icon: 'up', defaults: { axis: 'y', amount: 0.15, speed: 0.8, phase: 0 }, unit: 'm' },
  spin: { label: 'motion.spin', icon: 'reset', defaults: { axis: 'y', amount: 1, speed: 0.25, phase: 0 }, unit: '×' },
  pulse: { label: 'motion.pulse', icon: 'heart', defaults: { axis: 'y', amount: 0.08, speed: 1, phase: 0 }, unit: '' },
  shake: { label: 'motion.shake', icon: 'zap', defaults: { axis: 'x', amount: 0.04, speed: 6, phase: 0 }, unit: 'm' },
  wave: { label: 'motion.wave', icon: 'smooth', defaults: { axis: 'z', amount: 0.12, speed: 0.6, phase: 0 }, unit: 'm' },
};

const axisIndex = { x: 0, y: 1, z: 2 };

/** Harakat ofsetini qo'llaydi: rest — asosiy holat */
function applyMotion(obj, rest, m, time) {
  const w = TAU * (m.speed || 0) * time + (m.phase || 0) * D2R;
  const ax = m.axis || 'y';
  switch (m.type) {
    case 'flap':
    case 'sway': {
      const e = new THREE.Euler(0, 0, 0);
      e[ax] = Math.sin(w) * (m.amount || 0) * D2R;
      obj.quaternion.copy(rest.q).multiply(new THREE.Quaternion().setFromEuler(e));
      break;
    }
    case 'spin': {
      const e = new THREE.Euler(0, 0, 0);
      e[ax] = TAU * (m.speed || 0) * time * Math.sign(m.amount || 1) + (m.phase || 0) * D2R;
      obj.quaternion.copy(rest.q).multiply(new THREE.Quaternion().setFromEuler(e));
      break;
    }
    case 'bob':
      obj.position.copy(rest.p);
      obj.position.setComponent(axisIndex[ax], rest.p.getComponent(axisIndex[ax]) + Math.sin(w) * (m.amount || 0));
      break;
    case 'pulse':
      obj.scale.copy(rest.s).multiplyScalar(1 + Math.sin(w) * (m.amount || 0));
      break;
    case 'shake': {
      const a = m.amount || 0;
      obj.position.set(rest.p.x + Math.sin(w * 1.3) * a, rest.p.y + Math.sin(w * 1.7 + 1) * a, rest.p.z + Math.sin(w * 2.1 + 2) * a);
      break;
    }
  }
}

const ease = (u) => u * u * (3 - 2 * u);

export class Animator {
  constructor(editor) {
    this.editor = editor;
    this.time = 0;
    this.playing = false;
    this.recording = false;
    this.rests = null; // Map(obj -> {p,q,s})
    this.waveBases = new Map(); // mesh -> asl uchlar (to'lqin deformatsiyasi uchun)
    editor.updaters.add((dt) => this.tick(dt));
  }

  get settings() {
    const s = this.editor.sceneSettings;
    if (!s.anim) s.anim = { duration: 4, fps: 30, loop: true };
    return s.anim;
  }

  animated() {
    const out = [];
    this.editor.content.traverse((o) => {
      if (o.userData?.keys?.length || o.userData?.motion?.type) out.push(o);
    });
    return out;
  }

  // ---------- Ijro ----------
  _captureRests() {
    this.rests = new Map();
    for (const o of this.animated()) this.rests.set(o, { p: o.position.clone(), q: o.quaternion.clone(), s: o.scale.clone() });
  }

  /**
   * To'lqin: modelning chetlari (qanot, dum) markazga nisbatan ko'proq tebranadi.
   * Uchlar har kadrda asl holatdan qayta hisoblanadi.
   */
  _wave(obj, m, time) {
    const axis = axisIndex[m.axis || 'z'];
    const along = axis === 0 ? 1 : 0; // x bo'ylab (z/y siljiganda), aks holda y
    obj.traverse((o) => {
      if (!o.isMesh || !o.geometry?.attributes.position) return;
      const pos = o.geometry.attributes.position;
      let base = this.waveBases.get(o);
      if (!base || base.length !== pos.array.length) {
        base = pos.array.slice();
        this.waveBases.set(o, base);
        o.geometry.computeBoundingBox();
        o.userData.__waveBox = o.geometry.boundingBox.clone();
      }
      const bb = o.userData.__waveBox;
      const lo = bb.min.getComponent(along), hi = bb.max.getComponent(along);
      const mid = (lo + hi) / 2, half = Math.max(1e-6, (hi - lo) / 2);
      const ylo = bb.min.y, yh = Math.max(1e-6, bb.max.y - bb.min.y);
      const w0 = TAU * (m.speed || 0) * time + (m.phase || 0) * D2R;
      const A = (m.amount || 0) * half;
      const arr = pos.array;
      for (let i = 0; i < arr.length; i += 3) {
        const s = (base[i + along] - mid) / half; // -1..1
        const up = (base[i + 1] - ylo) / yh; // 0..1 (oyoqlar qimirlamasin)
        const w = Math.min(1, Math.abs(s)) ** 1.5 * Math.min(1, up * 1.6);
        arr[i] = base[i];
        arr[i + 1] = base[i + 1];
        arr[i + 2] = base[i + 2];
        arr[i + axis] += Math.sin(w0 + Math.abs(s) * 2.2) * A * w;
        if (axis !== 1) arr[i + 1] += Math.cos(w0 + Math.abs(s) * 2.2) * A * 0.35 * w;
      }
      pos.needsUpdate = true;
    });
  }

  _restoreWave() {
    for (const [o, base] of this.waveBases) {
      const pos = o.geometry?.attributes.position;
      if (pos && pos.array.length === base.length) {
        pos.array.set(base);
        pos.needsUpdate = true;
      }
    }
    this.waveBases.clear();
  }

  _restoreRests() {
    this._restoreWave();
    if (!this.rests) return;
    for (const [o, r] of this.rests) {
      if (o.userData?.keys?.length) continue;
      o.position.copy(r.p);
      o.quaternion.copy(r.q);
      o.scale.copy(r.s);
    }
    this.rests = null;
  }

  play() {
    if (this.playing) return;
    if (!this.rests) this._captureRests();
    if (this.time >= this.settings.duration - 1e-3) this.time = 0;
    this.playing = true;
    this.editor.transform.enabled = false;
    this.editor.emit('animState', true);
  }

  pause() {
    if (!this.playing) return;
    this.playing = false;
    this._restoreRests();
    this.applyAt(this.time, false);
    this.editor.transform.enabled = true;
    this.editor.emit('animState', false);
    this.editor.emit('objectChanged', this.editor.selected, 'transform');
    this.editor.requestRender();
  }

  stop() {
    this.pause();
    this.setTime(0);
  }

  toggle() {
    this.playing ? this.pause() : this.play();
  }

  setTime(t) {
    this.time = Math.max(0, Math.min(t, this.settings.duration));
    if (!this.playing) this.applyAt(this.time, false);
    this.editor.emit('animTime', this.time);
    this.editor.emit('objectChanged', this.editor.selected, 'transform');
    this.editor.requestRender();
  }

  tick(dt) {
    if (!this.playing) return false;
    const s = this.settings;
    this.time += dt;
    if (this.time > s.duration) {
      if (s.loop && !this.recording) this.time %= s.duration;
      else {
        this.time = s.duration;
        this.applyAt(this.time, true);
        this.editor.emit('animTime', this.time);
        this._onEnd?.();
        if (!this.recording) this.pause();
        return true;
      }
    }
    this.applyAt(this.time, true);
    this.editor.emit('animTime', this.time);
    return true;
  }

  /** Berilgan vaqtdagi holatni qo'llaydi. withMotion — tayyor harakatlarni ham */
  applyAt(time, withMotion) {
    for (const o of this.animated()) {
      const keys = o.userData.keys;
      if (keys?.length) evalKeys(o, keys, time);
      if (withMotion && o.userData.motion?.type === 'wave') {
        this._wave(o, o.userData.motion, time);
        continue;
      }
      if (withMotion && o.userData.motion?.type) {
        const rest = keys?.length ? { p: o.position.clone(), q: o.quaternion.clone(), s: o.scale.clone() } : this.rests?.get(o);
        if (rest) applyMotion(o, rest, o.userData.motion, time);
      }
    }
  }

  // ---------- Kalit kadrlar ----------
  addKey(obj) {
    if (!obj) return;
    const keys = (obj.userData.keys ||= []);
    const fps = this.settings.fps;
    const t = Math.round(this.time * fps) / fps;
    const k = { t, p: obj.position.toArray(), q: obj.quaternion.toArray(), s: obj.scale.toArray() };
    const i = keys.findIndex((x) => Math.abs(x.t - t) < 0.5 / fps);
    if (i >= 0) keys[i] = k;
    else keys.push(k);
    keys.sort((a, b) => a.t - b.t);
    this.editor.commit('keyframe');
    this.editor.emit('keysChanged', obj);
  }

  removeKey(obj) {
    const keys = obj?.userData?.keys;
    if (!keys?.length) return false;
    const fps = this.settings.fps;
    const i = keys.findIndex((x) => Math.abs(x.t - this.time) < 0.5 / fps);
    if (i < 0) return false;
    keys.splice(i, 1);
    if (!keys.length) delete obj.userData.keys;
    this.editor.commit('keyframe');
    this.editor.emit('keysChanged', obj);
    return true;
  }

  clearKeys(obj) {
    if (!obj?.userData?.keys) return;
    delete obj.userData.keys;
    this.editor.commit('keyframe');
    this.editor.emit('keysChanged', obj);
  }

  jumpKey(obj, dir) {
    const keys = obj?.userData?.keys;
    if (!keys?.length) return;
    const eps = 1e-4;
    const k = dir > 0 ? keys.find((x) => x.t > this.time + eps) : [...keys].reverse().find((x) => x.t < this.time - eps);
    if (k) this.setTime(k.t);
  }

  setMotion(obj, type) {
    if (!type) delete obj.userData.motion;
    else obj.userData.motion = { type, ...motions[type].defaults, ...(obj.userData.motion?.type === type ? obj.userData.motion : {}) };
    this.editor.commit('motion');
  }

  // ---------- Eksport: AnimationClip ----------
  /** Barcha animatsiyalarni fps bo'yicha namunalab, glTF uchun klip yasaydi. */
  bakeClip(name = 'Animation') {
    const objs = this.animated();
    if (!objs.length) return null;
    const { duration, fps } = this.settings;
    const frames = Math.max(2, Math.round(duration * fps) + 1);
    const saved = objs.map((o) => ({ o, p: o.position.clone(), q: o.quaternion.clone(), s: o.scale.clone() }));
    const wasPlaying = this.playing;
    if (!wasPlaying) this._captureRests();
    const times = new Float32Array(frames);
    const data = objs.map(() => ({ p: new Float32Array(frames * 3), q: new Float32Array(frames * 4), s: new Float32Array(frames * 3) }));
    for (let f = 0; f < frames; f++) {
      const t = Math.min(duration, f / fps);
      times[f] = t;
      // har kadr uchun asosiy holatdan boshlaymiz
      for (const sv of saved) {
        const r = this.rests?.get(sv.o);
        if (r && !sv.o.userData.keys?.length) {
          sv.o.position.copy(r.p);
          sv.o.quaternion.copy(r.q);
          sv.o.scale.copy(r.s);
        }
      }
      this.applyAt(t, true);
      objs.forEach((o, i) => {
        o.position.toArray(data[i].p, f * 3);
        o.quaternion.toArray(data[i].q, f * 4);
        o.scale.toArray(data[i].s, f * 3);
      });
    }
    for (const sv of saved) {
      sv.o.position.copy(sv.p);
      sv.o.quaternion.copy(sv.q);
      sv.o.scale.copy(sv.s);
    }
    if (!wasPlaying) this.rests = null;
    const tracks = [];
    objs.forEach((o, i) => {
      tracks.push(new THREE.VectorKeyframeTrack(`${o.uuid}.position`, times, data[i].p));
      tracks.push(new THREE.QuaternionKeyframeTrack(`${o.uuid}.quaternion`, times, data[i].q));
      tracks.push(new THREE.VectorKeyframeTrack(`${o.uuid}.scale`, times, data[i].s));
    });
    return new THREE.AnimationClip(name, duration, tracks);
  }

  // ---------- Video yozish ----------
  static videoMime() {
    if (typeof MediaRecorder === 'undefined') return null;
    for (const m of ['video/mp4;codecs=avc1', 'video/mp4', 'video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm']) {
      try {
        if (MediaRecorder.isTypeSupported(m)) return m;
      } catch {
        /* */
      }
    }
    return null;
  }

  /**
   * Animatsiyani boshidan oxirigacha videoga yozadi.
   * @returns {Promise<{blob: Blob, ext: string}>}
   */
  record({ duration, onProgress } = {}) {
    const E = this.editor;
    const mime = Animator.videoMime();
    const canvas = E.renderer.domElement;
    if (!mime || !canvas.captureStream) return Promise.reject(new Error('unsupported'));
    const len = duration || this.settings.duration;
    return new Promise((resolve, reject) => {
      const stream = canvas.captureStream(this.settings.fps || 30);
      const rec = new MediaRecorder(stream, { mimeType: mime, videoBitsPerSecond: 8_000_000 });
      const chunks = [];
      rec.ondataavailable = (e) => e.data.size && chunks.push(e.data);
      const restoreHelpers = E.hideHelpers();
      const sel = E.selected;
      E.select(null);
      const prevDur = this.settings.duration;
      this.settings.duration = len;
      this.pause();
      this.time = 0;
      this.recording = true;
      let finished = false;
      const finish = () => {
        if (finished) return;
        finished = true;
        clearInterval(prog);
        this._onEnd = null;
        setTimeout(() => rec.state !== 'inactive' && rec.stop(), 120);
      };
      rec.onstop = () => {
        this.recording = false;
        this.settings.duration = prevDur;
        this.pause();
        restoreHelpers();
        if (sel) E.select(sel);
        stream.getTracks().forEach((tr) => tr.stop());
        resolve({ blob: new Blob(chunks, { type: mime.split(';')[0] }), ext: mime.includes('mp4') ? 'mp4' : 'webm' });
      };
      rec.onerror = (e) => {
        this.recording = false;
        restoreHelpers();
        reject(e.error || new Error('recorder'));
      };
      this._onEnd = finish;
      const prog = setInterval(() => onProgress?.(Math.min(1, this.time / len)), 200);
      rec.start(250);
      this.play();
    });
  }
}

/** Kalit kadrlar orasida silliq interpolyatsiya */
function evalKeys(obj, keys, t) {
  let a = keys[0], b = keys[keys.length - 1];
  if (t <= a.t) b = a;
  else if (t >= b.t) a = b;
  else {
    for (let i = 0; i < keys.length - 1; i++) {
      if (t >= keys[i].t && t <= keys[i + 1].t) {
        a = keys[i];
        b = keys[i + 1];
        break;
      }
    }
  }
  const u = a === b ? 0 : ease((t - a.t) / (b.t - a.t));
  obj.position.fromArray(a.p).lerp(_v1.fromArray(b.p), u);
  obj.quaternion.fromArray(a.q).slerp(_q1.fromArray(b.q), u);
  obj.scale.fromArray(a.s).lerp(_v2.fromArray(b.s), u);
}
const _v1 = new THREE.Vector3();
const _v2 = new THREE.Vector3();
const _q1 = new THREE.Quaternion();
