// DOM yordamchilari: element yaratish, oynalar (sheet), toast, tasdiqlash.
import { icon } from '../icons.js';
import { t } from '../i18n.js';

/**
 * h('div.cls#id', {attrs, on:{click}}, ...children)
 */
export function h(sel, attrs = {}, ...children) {
  const m = sel.match(/^([a-z0-9-]+)?((?:[.#][\w-]+)*)$/i);
  const el = document.createElement(m?.[1] || 'div');
  for (const part of (m?.[2] || '').match(/[.#][\w-]+/g) || []) {
    if (part[0] === '.') el.classList.add(part.slice(1));
    else el.id = part.slice(1);
  }
  if (attrs instanceof Node || typeof attrs === 'string' || Array.isArray(attrs)) {
    children.unshift(attrs);
    attrs = {};
  }
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v == null || v === false) continue;
    if (k === 'on') for (const [ev, fn] of Object.entries(v)) el.addEventListener(ev, fn);
    else if (k === 'html') el.innerHTML = v;
    else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
    else if (k in el && typeof v !== 'string') el[k] = v;
    else el.setAttribute(k, v === true ? '' : v);
  }
  for (const c of children.flat(Infinity)) {
    if (c == null || c === false) continue;
    el.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  return el;
}

export function iconBtn(name, title, onClick, cls = '') {
  return h('button.icon-btn' + (cls ? '.' + cls.split(' ').join('.') : ''), { type: 'button', title, 'aria-label': title, html: icon(name), on: { click: onClick } });
}

// ---------- Toast ----------
let toastTimer;
export function toast(msg, kind = '') {
  let el = document.getElementById('toast');
  if (!el) {
    el = h('div#toast');
    document.body.append(el);
  }
  el.textContent = msg;
  el.className = 'show ' + kind;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => (el.className = ''), 2600);
}

// ---------- Sheet (pastdan chiqadigan oyna) ----------
const sheetStack = [];

export function openSheet(title, body, { wide = false, onClose } = {}) {
  const overlay = h('div.sheet-overlay');
  const close = () => {
    const i = sheetStack.indexOf(api);
    if (i >= 0) sheetStack.splice(i, 1);
    overlay.classList.remove('open');
    setTimeout(() => overlay.remove(), 200);
    onClose?.();
  };
  const sheet = h(
    'div.sheet' + (wide ? '.wide' : ''),
    h('div.sheet-head', h('div.sheet-title', title), iconBtn('close', t('close'), close)),
    h('div.sheet-body', body)
  );
  overlay.append(sheet);
  overlay.addEventListener('pointerdown', (e) => {
    if (e.target === overlay) close();
  });
  document.body.append(overlay);
  requestAnimationFrame(() => overlay.classList.add('open'));
  const api = { close, el: sheet, overlay };
  sheetStack.push(api);
  return api;
}

/** Ochiq oynani yopadi (Android "orqaga" tugmasi uchun). */
export function closeTopSheet() {
  const s = sheetStack[sheetStack.length - 1];
  if (s) {
    s.close();
    return true;
  }
  return false;
}

export function confirmDialog(message, { ok = t('ok'), danger = false } = {}) {
  return new Promise((resolve) => {
    let done = false;
    const finish = (v) => {
      if (done) return;
      done = true;
      resolve(v);
      s.close();
    };
    const body = h(
      'div.dialog',
      h('p', message),
      h(
        'div.dialog-actions',
        h('button.btn', { type: 'button', on: { click: () => finish(false) } }, t('cancel')),
        h('button.btn.primary' + (danger ? '.danger' : ''), { type: 'button', on: { click: () => finish(true) } }, ok)
      )
    );
    const s = openSheet(t('confirm'), body, { onClose: () => !done && ((done = true), resolve(false)) });
  });
}

export function promptDialog(message, value = '') {
  return new Promise((resolve) => {
    let done = false;
    const input = h('input.input', { type: 'text', value });
    const finish = (v) => {
      if (done) return;
      done = true;
      resolve(v);
      s.close();
    };
    input.addEventListener('keydown', (e) => e.key === 'Enter' && finish(input.value.trim()));
    const body = h(
      'div.dialog',
      h('label.field-label', message),
      input,
      h(
        'div.dialog-actions',
        h('button.btn', { type: 'button', on: { click: () => finish(null) } }, t('cancel')),
        h('button.btn.primary', { type: 'button', on: { click: () => finish(input.value.trim()) } }, t('ok'))
      )
    );
    const s = openSheet(message, body, { onClose: () => !done && ((done = true), resolve(null)) });
    setTimeout(() => {
      input.focus();
      input.select();
    }, 250);
  });
}

// ---------- Forma elementlari ----------
const collapsedSections = new Set();
export function section(title, ...children) {
  const key = title.split(' · ')[0];
  const body = h('div.section-body', ...children);
  const head = h('button.section-head', { type: 'button' }, h('span', title), h('span.chev', { html: icon('chevronDown', 18) }));
  const sec = h('section.section' + (collapsedSections.has(key) ? '.collapsed' : ''), head, body);
  head.addEventListener('click', () => {
    sec.classList.toggle('collapsed');
    if (sec.classList.contains('collapsed')) collapsedSections.add(key);
    else collapsedSections.delete(key);
  });
  return sec;
}

export function row(label, ...controls) {
  return h('div.row', h('label.row-label', label), h('div.row-controls', ...controls));
}

/** Raqam kiritish. onInput — jonli, onChange — yakuniy (tarixga yoziladi). */
export function numberInput(value, { step = 0.1, min, max, onInput, onChange, decimals = 3 } = {}) {
  const el = h('input.input.num', { type: 'number', inputmode: 'decimal', step: String(step) });
  if (min != null) el.min = min;
  if (max != null) el.max = max;
  const fmt = (v) => (Number.isFinite(+v) ? String(+(+v).toFixed(decimals)) : '0');
  el.value = fmt(value);
  el.addEventListener('input', () => {
    const v = parseFloat(el.value);
    if (Number.isFinite(v)) onInput?.(v);
  });
  el.addEventListener('change', () => {
    const v = parseFloat(el.value);
    if (Number.isFinite(v)) onChange?.(v);
  });
  el.setValue = (v) => {
    if (document.activeElement !== el) el.value = fmt(v);
  };
  return el;
}

export function slider(value, { min = 0, max = 1, step = 0.01, onInput, onChange } = {}) {
  const out = h('span.slider-val', String(value));
  const el = h('input.slider', { type: 'range', min: String(min), max: String(max), step: String(step) });
  el.value = String(value);
  el.addEventListener('input', () => {
    out.textContent = (+el.value).toFixed(2).replace(/\.?0+$/, '') || '0';
    onInput?.(+el.value);
  });
  el.addEventListener('change', () => onChange?.(+el.value));
  const wrap = h('div.slider-wrap', el, out);
  wrap.setValue = (v) => {
    el.value = String(v);
    out.textContent = (+v).toFixed(2).replace(/\.?0+$/, '') || '0';
  };
  return wrap;
}

export function toggle(checked, onChange) {
  const input = h('input', { type: 'checkbox' });
  input.checked = !!checked;
  input.addEventListener('change', () => onChange?.(input.checked));
  const el = h('label.toggle', input, h('span.knob'));
  el.setValue = (v) => (input.checked = !!v);
  return el;
}

export function colorInput(value, { onInput, onChange } = {}) {
  const el = h('input.color', { type: 'color' });
  el.value = value;
  el.addEventListener('input', () => onInput?.(el.value));
  el.addEventListener('change', () => onChange?.(el.value));
  return el;
}

export function select(options, value, onChange) {
  const el = h('select.input', ...options.map((o) => h('option', { value: o.value }, o.label)));
  el.value = value;
  el.addEventListener('change', () => onChange?.(el.value));
  return el;
}

export function textInput(value, { onInput, onChange, placeholder } = {}) {
  const el = h('input.input', { type: 'text', placeholder });
  el.value = value ?? '';
  el.addEventListener('input', () => onInput?.(el.value));
  el.addEventListener('change', () => onChange?.(el.value));
  return el;
}

export function vec3Inputs(vec, opts) {
  const els = ['x', 'y', 'z'].map((axis) =>
    h(
      'div.vec-cell.' + axis,
      h('span.axis', axis.toUpperCase()),
      numberInput(vec[axis], {
        ...opts,
        onInput: (v) => opts.onInput?.(axis, v),
        onChange: (v) => opts.onChange?.(axis, v),
      })
    )
  );
  const wrap = h('div.vec3', ...els);
  wrap.setValue = (v) => els.forEach((c, k) => c.querySelector('input').setValue(v[['x', 'y', 'z'][k]]));
  return wrap;
}

export function gridButtons(items) {
  return h(
    'div.grid-buttons',
    ...items.map((it) =>
      h(
        'button.grid-btn' + (it.active ? '.active' : ''),
        { type: 'button', on: { click: it.onClick }, title: it.title || it.label },
        h('span.gb-ic', { html: icon(it.icon, 26) }),
        h('span.gb-label', it.label)
      )
    )
  );
}

export function listButton(iconName, label, sub, onClick, extra) {
  return h(
    'button.list-btn',
    { type: 'button', on: { click: onClick } },
    h('span.lb-ic', { html: icon(iconName) }),
    h('span.lb-text', h('span.lb-label', label), sub ? h('span.lb-sub', sub) : null),
    extra || null
  );
}
