(function () {
'use strict';
const TG = window.Telegram && window.Telegram.WebApp;
const TOKEN = document.body.dataset.t || '';
const NEED_PIN = document.body.dataset.pin === '1';
document.body.removeAttribute('data-t');                 // token DOMda qolmasin
// Telegram imzosini (hash ichida) manzil tozalanishidan OLDIN o'qib olamiz
let HASH_INIT = ''; try { HASH_INIT = new URLSearchParams(location.hash.slice(1)).get('tgWebAppData') || ''; } catch (e) {}
try { history.replaceState(null, '', location.pathname); } catch (e) {}   // token manzil satrida/tarixda qolmasin

/* ------------------------------------------------------------------ yordamchilar */
const NS = 'http://www.w3.org/2000/svg';
function add(el, kids) { for (const k of kids.flat(Infinity)) { if (k == null || k === false) continue; el.append(k.nodeType ? k : document.createTextNode(String(k))); } }
function h(tag, props, ...kids) {
  const el = document.createElement(tag);
  for (const k in (props || {})) {
    const v = props[k];
    if (v == null || v === false) continue;
    if (k === 'class') el.className = v;
    else if (k === 'style') Object.assign(el.style, v);
    else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
    else if (k === 'value') el.value = v;
    else el.setAttribute(k, v === true ? '' : String(v));
  }
  add(el, kids);
  return el;
}
function sv(tag, attrs) { const el = document.createElementNS(NS, tag); for (const k in attrs || {}) el.setAttribute(k, attrs[k]); return el; }
const ICONS = {
  home: 'M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
  wallet: 'M3 7a2 2 0 0 1 2-2h13v4M3 7v11a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-9a1 1 0 0 0-1-1H5a2 2 0 0 1-2-2zM16 14h2',
  users: 'M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-4A3.5 3.5 0 0 0 5 18.5V20M10.5 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM20 20v-1.5a3.5 3.5 0 0 0-2.5-3.3M15.5 4.2a3.5 3.5 0 0 1 0 6.6',
  box: 'M21 8l-9-5-9 5v8l9 5 9-5zM3 8l9 5 9-5M12 13v8',
  list: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
  x: 'M6 6l12 12M18 6L6 18',
  refresh: 'M20 11a8 8 0 0 0-14-4L4 9M4 4v5h5M4 13a8 8 0 0 0 14 4l2-2M20 20v-5h-5',
  logout: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
  lock: 'M6 11V8a6 6 0 0 1 12 0v3M5 11h14v10H5z',
  shield: 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z',
  search: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.3-4.3',
  tag: 'M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8zM7.5 7.5h.01',
  bell: 'M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0',
  alert: 'M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
  chart: 'M3 3v18h18M7 15l4-4 3 3 6-6',
  cpu: 'M9 3v2M15 3v2M9 19v2M15 19v2M3 9h2M3 15h2M19 9h2M19 15h2M7 5h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2zM9 9h6v6H9z',
  disk: 'M4 6c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3zM4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3',
  doc: 'M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8zM14 3v5h5M9 13h6M9 17h6',
  slides: 'M3 4h18v12H3zM12 16v4M8 20h8M7 8h6M7 11h10',
  file: 'M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8zM14 3v5h5',
  chev: 'M9 6l6 6-6 6',
  zap: 'M13 2L4 14h7l-1 8 9-12h-7z',
  star: 'M12 3l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.8 6.2 20.9l1.1-6.5L2.6 9.8l6.5-.9z',
  clock: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3 2',
  user: 'M20 21v-1a5 5 0 0 0-5-5H9a5 5 0 0 0-5 5v1M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
  layers: 'M12 3l9 5-9 5-9-5zM3 13l9 5 9-5',
  mega: 'M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1zM15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13',
  send: 'M22 2L11 13M22 2l-7 20-4-9-9-4z',
};
function icon(name) { const s = sv('svg', { viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }); s.append(sv('path', { d: ICONS[name] || '' })); return s; }
const fmt = n => String(Math.round(Number(n) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
const money = n => fmt(n) + " so'm";
const moneyS = n => { n = Number(n) || 0; return Math.abs(n) >= 1e6 ? (Math.round(n / 1e5) / 10).toString().replace('.', ',') + " mln so'm" : money(n); };
const p2 = n => String(n).padStart(2, '0');
function dt(ts) { if (!ts) return '—'; const d = new Date((ts + 18000) * 1000); return p2(d.getUTCDate()) + '.' + p2(d.getUTCMonth() + 1) + ' ' + p2(d.getUTCHours()) + ':' + p2(d.getUTCMinutes()); }
function ago(ts) { if (!ts) return '—'; const s = Math.max(0, Math.floor(Date.now() / 1000) - ts); if (s < 60) return 'hozir'; if (s < 3600) return Math.floor(s / 60) + ' daq oldin'; if (s < 86400) return Math.floor(s / 3600) + ' soat oldin'; return Math.floor(s / 86400) + ' kun oldin'; }
const nm = u => u.full_name || (u.username ? '@' + u.username : 'ID ' + u.telegram_id);
const initial = u => (nm(u).replace(/^@/, '')[0] || '?').toUpperCase();
function newKey() { const a = new Uint8Array(16); (window.crypto || {}).getRandomValues ? crypto.getRandomValues(a) : a.forEach((_, i) => a[i] = Math.random() * 256); return Array.from(a, b => p2(b.toString(16))).join(''); }
const L = {
  job: { pending: ['Navbatda', ''], processing: ['Jarayonda', 'acc'], done: ['Bajarildi', 'ok'], failed: ['Xato', 'bad'], cancelled: ['Bekor', 'warn'], awaiting_payment: ["To'lov kutilmoqda", 'warn'] },
  type: { mustaqil_ish: 'Mustaqil ish', referat: 'Referat', slayd: 'Taqdimot', kurs_ishi: 'Kurs ishi', maqola: 'Ilmiy maqola', tezis: 'Tezis' },
  topup: { pending: ['Kutilmoqda', 'warn'], approved: ['Tasdiqlangan', 'ok'], rejected: ['Rad etilgan', 'bad'], refunded: ['Qaytarilgan', 'warn'] },
  method: { click: 'Click', payme: 'Payme', manual: 'Chek', humo: 'Humo/Uzcard' },
  audit: { auth_ok: ['Kirish', 'ok'], auth_fail: ['Kirish urinishi (rad etildi)', 'bad'], pin_fail: ['PIN xato', 'bad'], balance_adjust: ["Balans o'zgartirildi", 'warn'], topup_approve: ["To'lov tasdiqlandi", 'ok'], topup_reject: ["To'lov rad etildi", 'bad'], job_cancel: ['Buyurtma bekor', 'warn'], ban: ['Bloklandi', 'bad'], unban: ['Blokdan chiqarildi', 'ok'], send_msg: ['Xabar yuborildi', ''], view_receipt: ["Chek ko'rildi", ''], link_issued: ['Havola berildi', ''], logout: ['Chiqish', ''], promo_create: ['Promokod yaratildi', 'ok'], promo_on: ['Promokod yoqildi', 'ok'], promo_off: ["Promokod o'chirildi", 'warn'], promo_delete: ["Promokod o'chirib tashlandi", 'bad'], broadcast: ['Rassilka boshlandi', 'acc'], broadcast_stop: ["Rassilka to'xtatildi", 'warn'], bad_link: ["Noto'g'ri havola", 'bad'], bad_origin: ['Begona manba', 'bad'], session_ua_mismatch: ['Sessiya brauzeri o\'zgargan', 'bad'] },
};
const badge = (t, c) => h('span', { class: 'badge ' + (c || '') }, t);
const haptic = (k) => { try { TG && TG.HapticFeedback && (k === 'ok' || k === 'bad' ? TG.HapticFeedback.notificationOccurred(k === 'ok' ? 'success' : 'error') : TG.HapticFeedback.impactOccurred(k || 'light')); } catch (e) {} };
const AV_COLORS = ['#4f6bff', '#8b5cf6', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#ec4899', '#14b8a6'];
function avStyle(seed) { let x = 0; String(seed).split('').forEach(c => { x = (x * 31 + c.charCodeAt(0)) >>> 0; }); const c = AV_COLORS[x % AV_COLORS.length]; return { color: c, background: c + '1f' }; }
const TYPE_IC = { mustaqil_ish: 'doc', referat: 'file', slayd: 'slides', kurs_ishi: 'layers', maqola: 'list', tezis: 'file' };
function emptyBox(text, ic) { return h('div', { class: 'empty' }, h('div', { class: 'big' }, icon(ic || 'box')), text); }
function skeleton(n, hgt) { return h('div', { class: 'list' }, Array.from({ length: n || 4 }, () => h('div', { class: 'sk', style: { height: (hgt || 66) + 'px' } }))); }
function pct(a, b) { if (!b) return a ? null : 0; return Math.round((a - b) / b * 100); }
function deltaEl(a, b) { const p = pct(a, b); if (p === null) return h('span', { class: 'delta' }, 'yangi'); return h('span', { class: 'delta' + (p > 0 ? '' : p < 0 ? ' down' : ' flat') }, (p > 0 ? '▲ ' : p < 0 ? '▼ ' : '') + Math.abs(p) + '%'); }
function bytes(n) { const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0; n = Number(n) || 0; while (n >= 1024 && i < 4) { n /= 1024; i++; } return (i ? n.toFixed(1) : n) + ' ' + u[i]; }

/* ------------------------------------------------------------------ tema */
function applyTheme() {
  if (!TG || !TG.themeParams) return;
  const t = TG.themeParams, r = document.documentElement.style;
  const set = (k, v) => { if (v) r.setProperty(k, v); };
  set('--bg', t.secondary_bg_color || t.bg_color); set('--card', t.section_bg_color || t.bg_color);
  set('--text', t.text_color); set('--hint', t.hint_color); set('--acc', t.button_color); set('--acc-t', t.button_text_color);
  try { TG.setHeaderColor && TG.setHeaderColor(t.secondary_bg_color || t.bg_color); TG.setBackgroundColor && TG.setBackgroundColor(t.secondary_bg_color || t.bg_color); } catch (e) {}
}

/* ------------------------------------------------------------------ API */
let SESS = null, CFG = {}, lastActive = 0;
async function api(action, data) {
  let r;
  try {
    r = await fetch('api.php?a=' + action, { method: 'POST', credentials: 'omit', cache: 'no-store', headers: Object.assign({ 'Content-Type': 'application/json' }, SESS ? { Authorization: 'Bearer ' + SESS } : {}), body: JSON.stringify(data || {}) });
  } catch (e) { return { ok: false, error: 'network', msg: 'Internet aloqasi yo\'q' }; }
  let j = null; try { j = await r.json(); } catch (e) {}
  if (!j) {
    if (r.status === 429) return { ok: false, error: 'rate', msg: 'Juda tez-tez so\'rov. Biroz kuting' };
    if (SESS && action !== 'auth') { expired(); return { ok: false, error: 'auth' }; }
    return { ok: false, error: 'auth' };
  }
  if (SESS && action !== 'auth') lastActive = Date.now();
  return j;
}
async function act(action, payload) {            // pul/holat o'zgartiruvchi amallar: idempotency + katta summada PIN
  let pin = null;
  for (let i = 0; i < 3; i++) {
    const r = await api(action, Object.assign({}, payload, { idem: newKey() }, pin ? { pin } : {}));
    if (r.error === 'stepup_required' || (r.error === 'bad_pin' && i < 2)) {
      if (r.error === 'bad_pin') toast(r.msg || 'PIN noto\'g\'ri', 'bad');
      pin = await askPin(r.msg || 'PIN kodni kiriting');
      if (!pin) return { ok: false, error: 'cancel' };
      continue;
    }
    return r;
  }
  return { ok: false, error: 'bad_pin' };
}

/* ------------------------------------------------------------------ UI qobig'i */
const root = document.getElementById('app');
function show(...nodes) { root.replaceChildren(...nodes); }
function loader() { return h('div', { class: 'spin' }); }
function fatal(title, text, ic) { show(h('div', { class: 'full' }, h('div', { class: 'big' }, icon(ic || 'lock')), h('h2', {}, title), h('p', {}, text))); }
function toast(msg, tone) { document.querySelectorAll('.toast').forEach(x => x.remove()); const t = h('div', { class: 'toast ' + (tone || ''), role: 'status' }, msg); document.body.append(t); if (tone) haptic(tone); setTimeout(() => t.remove(), 2600); }
const sheets = [];
function syncBack() { if (!TG || !TG.BackButton) return; sheets.length ? TG.BackButton.show() : TG.BackButton.hide(); }
if (TG && TG.BackButton) TG.BackButton.onClick(() => { const s = sheets[sheets.length - 1]; if (s) s.close(); });
function openSheet(title, content, onClose) {
  const veil = h('div', { class: 'veil', onclick: e => { if (e.target === veil) close(); } });
  const bd = h('div', { class: 'bd' }, content);
  veil.append(h('div', { class: 'sheet' }, h('div', { class: 'grab' }), h('div', { class: 'hd' }, h('h2', {}, title), h('button', { class: 'ibtn', onclick: () => close(), 'aria-label': 'Yopish' }, icon('x'))), bd));
  document.body.append(veil);
  const rec = { veil, close };
  sheets.push(rec); syncBack();
  function close() { veil.remove(); const i = sheets.indexOf(rec); if (i >= 0) sheets.splice(i, 1); syncBack(); if (onClose) onClose(); }
  return { close, bd };
}
function confirmBox(title, text, okLabel, tone) {
  return new Promise(res => {
    let done = false;
    const fin = v => { if (!done) { done = true; res(v); } };
    const s = openSheet(title, h('div', {}, h('p', { style: { margin: '4px 0 16px', whiteSpace: 'pre-line' } }, text),
      h('div', { class: 'btns' }, h('button', { class: 'btn ghost', onclick: () => { fin(false); s.close(); } }, 'Bekor qilish'), h('button', { class: 'btn ' + (tone || 'pri'), onclick: () => { fin(true); s.close(); } }, okLabel))), () => fin(false));
  });
}
function askPin(msg) {
  return new Promise(res => {
    let done = false; const fin = v => { if (!done) { done = true; res(v); } };
    const inp = h('input', { class: 'inp pin', type: 'password', inputmode: 'numeric', autocomplete: 'off', maxlength: '12', placeholder: '••••' });
    const go = () => { const v = inp.value.trim(); if (!/^[0-9]{4,12}$/.test(v)) { toast('PIN 4–12 ta raqam', 'bad'); return; } fin(v); s.close(); };
    inp.addEventListener('keydown', e => { if (e.key === 'Enter') go(); });
    const s = openSheet('🔑 PIN kod', h('div', {}, h('p', { style: { margin: '2px 0 12px', color: 'var(--hint)' } }, msg), h('div', { style: { textAlign: 'center' } }, inp), h('div', { class: 'btns', style: { marginTop: '16px' } }, h('button', { class: 'btn pri', onclick: go }, 'Tasdiqlash'))), () => fin(null));
    setTimeout(() => inp.focus(), 150);
  });
}

/* ------------------------------------------------------------------ ro'yxat (sahifalab) */
function paged(container, fetcher, renderItem, emptyText, emptyIcon) {
  let offset = 0, busy = false;
  const list = h('div', { class: 'list' }); const foot = h('div', {});
  container.replaceChildren(list, foot);
  async function more() {
    if (busy) return; busy = true;
    foot.replaceChildren(offset === 0 ? skeleton(5) : loader());
    const r = await fetcher(offset);
    busy = false;
    if (!r.ok) { foot.replaceChildren(emptyBox(r.msg || 'Xatolik', 'alert')); return; }
    r.items.forEach(it => list.append(renderItem(it)));
    offset += r.items.length;
    if (!list.children.length) foot.replaceChildren(emptyBox(emptyText || "Ma'lumot yo'q", emptyIcon));
    else foot.replaceChildren(r.more ? h('button', { class: 'more', onclick: more }, 'Yana yuklash') : '');
  }
  more();
  return { reload() { offset = 0; list.replaceChildren(); more(); } };
}

/* ------------------------------------------------------------------ bosh sahifa */
function dLabel(d) { return d.slice(8) + '.' + d.slice(5, 7); }
/** Silliq chiziqli (area) grafik: tegish/sichqoncha bilan kun qiymati ko'rinadi. */
function areaChart(title, series, key, isMoney, cmpPair) {
  const wide = window.matchMedia && matchMedia('(min-width:860px)').matches, cw = (mainBox && mainBox.clientWidth) || 380;
  const W = Math.round(Math.max(300, wide ? Math.min(1180, cw - 52) / 2 - 36 : cw - 58)), H = wide ? 170 : 128, PT = 8, PB = 18, n = series.length, vals = series.map(s => Number(s[key]) || 0);
  const max = Math.max(1, ...vals), total = vals.reduce((a, v) => a + v, 0);
  const X = i => n > 1 ? 6 + i * (W - 12) / (n - 1) : W / 2, Y = v => PT + (H - PT - PB) * (1 - v / max);
  const pts = vals.map((v, i) => [X(i), Y(v)]);
  let d = 'M' + pts[0][0] + ',' + pts[0][1];
  for (let i = 1; i < pts.length; i++) { const [x0, y0] = pts[i - 1], [x1, y1] = pts[i], cx = (x0 + x1) / 2; d += ' C' + cx + ',' + y0 + ' ' + cx + ',' + y1 + ' ' + x1 + ',' + y1; }
  const gid = 'g' + Math.random().toString(36).slice(2, 8);
  const svg = sv('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': title });
  const defs = sv('defs'); const lg = sv('linearGradient', { id: gid, x1: '0', y1: '0', x2: '0', y2: '1' });
  lg.append(sv('stop', { offset: '0%', 'stop-color': 'var(--acc)', 'stop-opacity': '.32' }), sv('stop', { offset: '100%', 'stop-color': 'var(--acc)', 'stop-opacity': '0' }));
  defs.append(lg); svg.append(defs);
  [0.25, 0.5, 0.75].forEach(f => svg.append(sv('line', { x1: 0, x2: W, y1: PT + (H - PT - PB) * f, y2: PT + (H - PT - PB) * f, class: 'gridl' })));
  svg.append(sv('path', { d: d + ' L' + pts[n - 1][0] + ',' + (H - PB) + ' L' + pts[0][0] + ',' + (H - PB) + 'Z', fill: 'url(#' + gid + ')' }));
  svg.append(sv('path', { d, class: 'cline' }));
  const step = Math.max(1, Math.ceil(n / 7));
  series.forEach((s, i) => { if ((i % step === 0 && (n - 1 - i >= step * 0.75 || i === 0)) || i === n - 1) { const t = sv('text', { x: X(i), y: H - 4, 'text-anchor': i === 0 ? 'start' : (i === n - 1 ? 'end' : 'middle'), class: 'axis' }); t.textContent = dLabel(s.d); svg.append(t); } });
  const guide = sv('line', { y1: PT, y2: H - PB, class: 'cguide', visibility: 'hidden' });
  const dot = sv('circle', { r: 4, class: 'cdot', visibility: 'hidden' });
  svg.append(guide, dot);
  const tip = h('div', { class: 'tip' }, ' ');
  const fmtV = v => isMoney ? money(v) : fmt(v);
  const pick = ev => {
    const r = svg.getBoundingClientRect(); const cx = ((ev.touches ? ev.touches[0].clientX : ev.clientX) - r.left) / r.width * W;
    let i = Math.round((cx - 6) / ((W - 12) / Math.max(1, n - 1))); i = Math.max(0, Math.min(n - 1, i));
    guide.setAttribute('x1', X(i)); guide.setAttribute('x2', X(i)); guide.setAttribute('visibility', 'visible');
    dot.setAttribute('cx', X(i)); dot.setAttribute('cy', Y(vals[i])); dot.setAttribute('visibility', 'visible');
    tip.textContent = dLabel(series[i].d) + ' — ' + fmtV(vals[i]);
  };
  ['mousemove', 'touchstart', 'touchmove', 'click'].forEach(e => svg.addEventListener(e, pick, { passive: true }));
  svg.addEventListener('mouseleave', () => { guide.setAttribute('visibility', 'hidden'); dot.setAttribute('visibility', 'hidden'); tip.textContent = ' '; });
  return h('div', { class: 'card chart' },
    h('div', { class: 'ct' }, h('div', {}, h('b', {}, title), h('div', { class: 'tv' }, fmtV(total), cmpPair ? ' ' : '', cmpPair ? deltaEl(cmpPair[0], cmpPair[1]) : '')), tip), svg);
}
function sparkline(vals) {
  const W = 120, H = 44, n = vals.length, max = Math.max(1, ...vals);
  const p = vals.map((v, i) => (n > 1 ? i * W / (n - 1) : 0).toFixed(1) + ',' + (H - 3 - (H - 6) * v / max).toFixed(1)).join(' ');
  const svg = sv('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'spark', preserveAspectRatio: 'none', 'aria-hidden': 'true' });
  svg.append(sv('polyline', { points: p, fill: 'none', stroke: 'rgba(255,255,255,.9)', 'stroke-width': '2', 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }));
  return svg;
}
function distCard(rows) {
  const tot = rows.reduce((a, r) => a + r[1], 0) || 1;
  if (!rows.length) return h('div', { class: 'card' }, emptyBox('Hali ma\'lumot yo\'q', 'chart'));
  return h('div', { class: 'card dist' }, rows.map(([l, v]) => {
    const i = h('i', { style: { width: '0%' } }); requestAnimationFrame(() => requestAnimationFrame(() => { i.style.width = Math.max(2, v / tot * 100) + '%'; }));
    return h('div', { class: 'it' }, h('div', { class: 'tl' }, h('b', {}, l), h('span', {}, fmt(v) + ' · ' + Math.round(v / tot * 100) + '%')), h('div', { class: 'bar' }, i));
  }));
}
function healthCard(hh) {
  const rows = [];
  const hr = (ic, title, sub, st, extra) => h('div', {}, h('div', { class: 'hrow' }, h('div', { class: 'ic' }, icon(ic)), h('div', { class: 'grow' }, h('div', { class: 't1' }, title), h('div', { class: 't2' }, sub)), st ? h('span', { class: 'st ' + st }) : ''), extra || '');
  if (hh.ai) {
    const a = hh.ai, st = a.total === 0 ? 'bad' : (a.ready > 0 ? (a.dead || a.cooling ? 'warn' : 'ok') : (a.cooling ? 'warn' : 'bad'));
    const routes = a.routes.length ? h('div', { class: 'routes' }, a.routes.map(r => h('span', { class: 'route', title: r.m }, h('span', { class: 'st ' + (r.st === 'ready' ? 'ok' : r.st === 'cooling' ? 'warn' : 'bad') }), r.p + ' ' + r.k + (r.wait ? ' · ' + Math.ceil(r.wait / 60) + ' daq' : '')))) : '';
    rows.push(hr('cpu', 'AI kalitlar', a.total === 0 ? 'Kalit sozlanmagan — config.php → AI_PROVIDERS' : 'Tayyor ' + a.ready + ' · limitda ' + a.cooling + ' · yaroqsiz ' + a.dead + ' (jami ' + a.total + ')', st, routes));
  }
  if (hh.queue) {
    const q = hh.queue, wait = q.oldest ? Math.floor(Date.now() / 1000) - q.oldest : 0;
    rows.push(hr('layers', 'Navbat', 'Kutmoqda ' + q.pending + ' · ishlanmoqda ' + q.processing + (q.oldest ? ' · eng eskisi ' + ago(q.oldest) : ''), wait > 1800 ? 'bad' : (wait > 600 ? 'warn' : 'ok')));
  }
  if (hh.disk) {
    const d = hh.disk, used = 1 - d.free / d.total, i = h('i', { style: { width: Math.round(used * 100) + '%' } });
    rows.push(hr('disk', 'Disk', 'Bo\'sh ' + bytes(d.free) + ' / ' + bytes(d.total) + ' · baza ' + bytes(d.db), d.free < 300 * 1048576 ? 'bad' : (d.free < 2 * 1073741824 ? 'warn' : 'ok'), h('div', { class: 'bar', style: { margin: '0 0 10px 46px' } }, i)));
  }
  if (hh.pdf != null) rows.push(hr('file', 'PDF eksport', hh.pdf === 'soffice' ? 'LibreOffice (eng sifatli)' : hh.pdf === 'gd' ? 'GD (zaxira rejim)' : 'Mavjud emas', hh.pdf === 'soffice' ? 'ok' : hh.pdf === 'gd' ? 'warn' : 'bad'));
  return h('div', { class: 'card health' }, rows);
}
async function viewHome(box) {
  const days = S.days || 14;
  box.replaceChildren(h('div', { class: 'wrap' }, h('div', { class: 'sk', style: { height: '170px', borderRadius: '20px' } }), h('div', { style: { height: '12px' } }), h('div', { class: 'grid' }, Array.from({ length: 4 }, () => h('div', { class: 'sk', style: { height: '112px' } })))));
  const r = await api('stats', { days });
  if (!r.ok) { box.replaceChildren(emptyBox(r.msg || 'Xatolik', 'alert')); return; }
  setPending(r.money.pending_manual);
  const kpi = (ic, tone, l, v, sub, on, alert) => h(on ? 'button' : 'div', { class: 'card kpi ' + (tone || '') + (alert ? ' alert' : ''), onclick: on ? () => { haptic(); on(); } : null },
    h('div', { class: 'ic' }, icon(ic)), on ? h('span', { class: 'go' }, icon('chev')) : '', h('div', { class: 'l' }, l), h('div', { class: 'v' }, v), h('div', { class: 's' }, sub));
  const seg = h('div', { class: 'seg sm' }, [7, 14, 30].map(d => h('button', { class: d === days ? 'on' : '', onclick: () => { S.days = d; haptic(); viewHome(box); } }, d + ' kun')));
  const cmp = r.cmp || {};
  const tdist = Object.keys(L.type).map(k => [L.type[k], r.types[k] || 0]).filter(x => x[1] > 0).sort((a, b) => b[1] - a[1]);
  const ddist = (r.designs || []).map(x => [x.label, x.c]);
  box.replaceChildren(h('div', { class: 'wrap' },
    h('div', { class: 'hero' },
      sparkline(r.series.map(x => x.revenue)),
      h('div', { class: 'l' }, 'Bugungi kirim'),
      h('div', { class: 'v' }, money(r.money.topup_today)),
      h('div', {}, cmp.revenue ? deltaEl(cmp.revenue[0], cmp.revenue[1]) : '', h('span', { style: { fontSize: '12px', opacity: '.85', marginLeft: '6px' } }, days + ' kun: ' + money(cmp.revenue ? cmp.revenue[0] : 0))),
      h('div', { class: 'row3' },
        h('div', { class: 'mini' }, h('b', {}, fmt(r.jobs.today)), h('span', {}, 'buyurtma bugun')),
        h('div', { class: 'mini' }, h('b', {}, '+' + fmt(r.users.today)), h('span', {}, 'yangi mijoz')),
        h('div', { class: 'mini' }, h('b', {}, money(r.money.sales_today).replace(" so'm", '')), h('span', {}, "sotuv (so'm)")))),
    h('div', { class: 'sec' }, 'Ko\'rsatkichlar', seg),
    h('div', { class: 'grid' },
      kpi('users', '', 'Foydalanuvchilar', fmt(r.users.total), '+' + r.users.week + ' shu hafta', () => go('users')),
      kpi('box', 'vio', 'Buyurtmalar', fmt(r.jobs.total), 'Jarayonda ' + r.jobs.active + ' · bajarilgan ' + fmt(r.jobs.done), () => go('jobs')),
      kpi('wallet', 'ok', 'Jami kirim', moneyS(r.money.topup_total), 'Sotuv: ' + moneyS(r.money.sales_total)),
      kpi('zap', '', 'Hamyonlarda', moneyS(r.users.balance_sum), 'Mijozlar balansi yig\'indisi'),
      kpi('clock', '', 'Kutilayotgan to\'lov', fmt(r.money.pending_manual), 'Chek tekshiruvi', () => go('topups'), r.money.pending_manual > 0),
      kpi('alert', 'bad', 'Xatolar (24 soat)', fmt(r.jobs.failed_24h), 'Jami xato: ' + r.jobs.failed, () => { S.jobFilter = 'failed'; go('jobs'); }, r.jobs.failed_24h > 0),
      kpi('star', '', 'O\'rtacha baho', r.jobs.rating == null ? '—' : r.jobs.rating + ' ★', 'Bajarilgan: ' + fmt(r.jobs.done)),
      kpi('shield', '', 'Bloklangan', fmt(r.users.banned || 0), 'Xavfsizlik jurnali', () => go('audit'))),
    h('div', { class: 'btns', style: { marginTop: '10px' } },
      h('button', { class: 'btn line', onclick: () => go('broadcast') }, icon('mega'), 'Rassilka'),
      h('button', { class: 'btn line', onclick: () => go('audit') }, icon('list'), 'Jurnal')),
    h('div', { class: 'sec' }, 'Dinamika · ' + days + ' kun'),
    h('div', { class: 'cols' },
      areaChart('Kirim', r.series, 'revenue', true, cmp.revenue),
      areaChart('Buyurtmalar', r.series, 'orders', false, cmp.orders),
      areaChart('Sotuv (bajarilgan)', r.series, 'sales', true),
      areaChart('Yangi foydalanuvchilar', r.series, 'users', false, cmp.users)),
    h('div', { class: 'cols' },
      h('div', {}, h('div', { class: 'sec' }, 'Tizim holati'), r.health ? healthCard(r.health) : emptyBox('—')),
      h('div', {}, h('div', { class: 'sec' }, 'Bajarilgan buyurtmalar turi'), distCard(tdist),
        ddist.length ? h('div', { class: 'sec' }, 'Mashhur dizaynlar') : '', ddist.length ? distCard(ddist) : ''))));
}

/* ------------------------------------------------------------------ foydalanuvchilar */
function userRow(u) {
  return h('button', { class: 'card row', onclick: () => openUser(u.telegram_id) },
    h('div', { class: 'av', style: avStyle(u.telegram_id) }, initial(u)),
    h('div', { class: 'grow' }, h('div', { class: 't1' }, nm(u), ' ', u.banned ? badge('BLOK', 'bad') : ''), h('div', { class: 't2' }, (u.username ? '@' + u.username + ' · ' : '') + 'ID ' + u.telegram_id + ' · ' + u.jobs + ' buyurtma')),
    h('div', { class: 'right' }, h('div', { class: 'amt' }, money(u.balance)), h('div', { class: 't2' }, ago(u.last_request_at))));
}
function viewUsers(box) {
  let timer = null, q = '';
  const inp = h('input', { class: 'inp', type: 'search', placeholder: 'ID, @username yoki ism bo\'yicha qidirish', autocomplete: 'off', maxlength: '60' });
  const res = h('div', {});
  let pg = null;
  const run = () => { pg = paged(res, off => api('users', { q, offset: off }), userRow, 'Hech narsa topilmadi', 'users'); S.reload = () => pg.reload(); };
  inp.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { q = inp.value.trim(); run(); }, 350); });
  box.replaceChildren(h('div', { class: 'wrap' }, h('div', { class: 'search' }, icon('search'), inp), res));
  run();
}
async function openUser(id) {
  const s = openSheet('Foydalanuvchi', loader());
  const r = await api('user', { id });
  if (!r.ok) { s.bd.replaceChildren(h('div', { class: 'empty' }, r.msg || 'Topilmadi')); return; }
  const u = r.user, kv = (a, b) => h('div', { class: 'kv' }, h('span', {}, a), h('span', {}, b));
  const refresh = () => { s.close(); openUser(id); if (S.tab === 'users' && S.reload) S.reload(); };
  s.bd.replaceChildren(
    h('div', { class: 'card row', style: { marginBottom: '10px' } }, h('div', { class: 'av', style: avStyle(u.telegram_id) }, initial(u)), h('div', { class: 'grow' }, h('div', { class: 't1' }, nm(u), ' ', u.banned ? badge('BLOK', 'bad') : ''), h('div', { class: 't2' }, (u.username ? '@' + u.username + ' · ' : '') + 'ID ' + u.telegram_id))),
    h('div', { class: 'card', style: { padding: '4px 14px' } },
      kv('Balans', money(u.balance)), kv('Jami to\'ldirgan', money(u.topped_up)), kv('Sarflagan', money(u.spent)),
      kv('Buyurtmalar', fmt(u.jobs_total)), kv('Bepul urinish', u.free_used ? 'Ishlatilgan' : 'Bor'),
      kv('Ism / Familya', [u.first_name, u.last_name].filter(Boolean).join(' ') || '—'), kv('Universitet', u.university || '—'), kv('Guruh', u.group_name || '—'),
      kv('Takliflar', u.referral_count + ' ta · ' + money(u.referral_earned)), kv('Ro\'yxatdan o\'tgan', dt(u.created_at)), kv('Oxirgi faollik', ago(u.last_request_at))),
    h('div', { class: 'btns', style: { margin: '14px 0' } },
      h('button', { class: 'btn pri', onclick: () => balanceSheet(u, refresh) }, '💰 Balans'),
      h('button', { class: 'btn', onclick: () => messageSheet(u) }, '✉️ Xabar'),
      h('button', { class: 'btn ' + (u.banned ? 'ok' : 'bad'), onclick: async () => {
        const ok = await confirmBox(u.banned ? 'Blokdan chiqarish' : 'Bloklash', u.banned ? 'Foydalanuvchi botdan yana foydalana oladi.' : 'Foydalanuvchi botdan foydalana olmaydi (buyurtma, to\'lov, hamma narsa).', u.banned ? 'Chiqarish' : 'Bloklash', u.banned ? 'ok' : 'bad');
        if (!ok) return; const rr = await act('ban', { tg: u.telegram_id, ban: !u.banned }); toast(rr.ok ? 'Bajarildi' : (rr.msg || 'Xatolik'), rr.ok ? 'ok' : 'bad'); if (rr.ok) refresh();
      } }, u.banned ? '✅ Blokdan chiqarish' : '🚫 Bloklash')),
    h('div', { class: 'sec' }, 'Oxirgi buyurtmalar'),
    h('div', { class: 'list' }, u.recent_jobs.length ? u.recent_jobs.map(j => jobRow(j, true)) : h('div', { class: 'empty' }, 'Yo\'q')),
    h('div', { class: 'sec' }, 'Oxirgi to\'ldirishlar'),
    h('div', { class: 'card' }, u.recent_topups.length ? u.recent_topups.map(t => h('div', { class: 'row' }, h('div', { class: 'grow' }, h('div', { class: 't1' }, money(t.amount)), h('div', { class: 't2' }, (L.method[t.method] || t.method) + ' · ' + dt(t.created_at))), badge(...(L.topup[t.status] || [t.status, ''])))) : h('div', { class: 'empty' }, 'Yo\'q')));
}
function balanceSheet(u, done) {
  let sign = 1;
  const amount = h('input', { class: 'inp', type: 'text', inputmode: 'numeric', placeholder: 'Summa (so\'m)', autocomplete: 'off', maxlength: '9' });
  const reason = h('input', { class: 'inp', type: 'text', placeholder: 'Sabab (majburiy)', maxlength: '200', autocomplete: 'off' });
  const notify = h('input', { type: 'checkbox' }); notify.checked = true;
  const plus = h('button', { class: 'on' }, '➕ Qo\'shish'), minus = h('button', {}, '➖ Ayirish');
  plus.addEventListener('click', () => { sign = 1; plus.className = 'on'; minus.className = ''; });
  minus.addEventListener('click', () => { sign = -1; minus.className = 'on'; plus.className = ''; });
  const quick = h('div', { class: 'btns', style: { marginTop: '8px' } }, [5000, 10000, 20000, 50000].map(v => h('button', { class: 'btn sm ghost', onclick: () => { amount.value = String(v); } }, fmt(v))));
  const sub = h('button', { class: 'btn pri', style: { width: '100%' } }, 'Davom etish');
  const s = openSheet('Balans — ' + nm(u), h('div', {},
    h('p', { style: { margin: '2px 0', color: 'var(--hint)' } }, 'Hozirgi balans: ' + money(u.balance)),
    h('div', { class: 'seg' }, plus, minus), h('div', { class: 'field' }, h('label', {}, 'Summa'), amount), quick,
    h('div', { class: 'field' }, h('label', {}, 'Sabab'), reason),
    h('label', { class: 'chk' }, notify, h('span', {}, 'Foydalanuvchiga xabar yuborish')), sub));
  sub.addEventListener('click', async () => {
    const v = parseInt(String(amount.value).replace(/\D/g, ''), 10);
    if (!v || v <= 0) { toast('Summani kiriting', 'bad'); return; }
    if (v > CFG.max_adjust) { toast('Eng ko\'pi bilan ' + money(CFG.max_adjust), 'bad'); return; }
    if (reason.value.trim().length < 3) { toast('Sababni yozing', 'bad'); return; }
    const amt = sign * v;
    const ok = await confirmBox('Tasdiqlang', (amt > 0 ? 'Qo\'shiladi: ' : 'Ayiriladi: ') + money(v) + '\n' + nm(u) + ' (ID ' + u.telegram_id + ')\nSabab: ' + reason.value.trim(), amt > 0 ? 'Qo\'shish' : 'Ayirish', amt > 0 ? 'ok' : 'bad');
    if (!ok) return;
    sub.disabled = true;
    const r = await act('adjust', { tg: u.telegram_id, amount: amt, reason: reason.value.trim(), notify: notify.checked });
    sub.disabled = false;
    if (r.ok) { toast('Yangi balans: ' + money(r.balance), 'ok'); s.close(); done(); } else if (r.error !== 'cancel') toast(r.msg || 'Xatolik', 'bad');
  });
}
function messageSheet(u) {
  const t = h('textarea', { class: 'inp', maxlength: '1000', placeholder: 'Xabar matni…' });
  const b = h('button', { class: 'btn pri', style: { width: '100%' } }, 'Yuborish');
  const s = openSheet('Xabar — ' + nm(u), h('div', {}, h('div', { class: 'field' }, t), b));
  b.addEventListener('click', async () => {
    if (!t.value.trim()) { toast('Xabarni yozing', 'bad'); return; }
    b.disabled = true; const r = await act('msg', { tg: u.telegram_id, text: t.value.trim() }); b.disabled = false;
    if (r.ok) { toast('Yuborildi', 'ok'); s.close(); } else toast(r.msg || 'Xatolik', 'bad');
  });
}

/* ------------------------------------------------------------------ to'lovlar */
function topupCard(t, reload) {
  const st = L.topup[t.status] || [t.status, ''];
  const canAct = t.status === 'pending' && t.method === 'manual' && t.has_receipt;
  const decide = async approve => {
    const ok = await confirmBox(approve ? 'To\'lovni tasdiqlash' : 'To\'lovni rad etish', '#' + t.id + ' · ' + money(t.amount) + '\n' + (t.full_name || t.username || 'ID ' + t.telegram_id), approve ? 'Tasdiqlash' : 'Rad etish', approve ? 'ok' : 'bad');
    if (!ok) return;
    const r = await act('topup_decide', { id: t.id, approve });
    if (r.ok) { toast(approve ? 'Tasdiqlandi' : 'Rad etildi', 'ok'); reload(); refreshBadge(); } else if (r.error !== 'cancel') toast(r.msg || 'Xatolik', 'bad');
  };
  return h('div', { class: 'card', style: { padding: '12px 13px' } },
    h('div', { class: 'row', style: { padding: 0 } },
      h('button', { class: 'av', style: avStyle(t.telegram_id), onclick: () => openUser(t.telegram_id) }, initial(t)),
      h('div', { class: 'grow' }, h('div', { class: 't1' }, t.full_name || (t.username ? '@' + t.username : 'ID ' + t.telegram_id)), h('div', { class: 't2' }, '#' + t.id + ' · ' + (L.method[t.method] || t.method) + ' · ' + dt(t.created_at))),
      h('div', { class: 'right' }, h('div', { class: 'amt' }, money(t.amount)), badge(st[0], st[1]))),
    canAct ? h('div', { class: 'btns', style: { marginTop: '11px' } },
      h('button', { class: 'btn sm', onclick: async () => { const r = await api('receipt', { id: t.id }); toast(r.ok ? 'Chek Telegram\'ga yuborildi' : (r.msg || 'Xatolik'), r.ok ? 'ok' : 'bad'); } }, '🧾 Chek'),
      h('button', { class: 'btn sm ok', onclick: () => decide(true) }, '✅ Tasdiqlash'),
      h('button', { class: 'btn sm bad', onclick: () => decide(false) }, '❌ Rad')) : '');
}
function viewTopups(box) {
  let filter = S.topupFilter || 'pending';
  const res = h('div', {}); let pg = null;
  const seg = h('div', { class: 'seg' });
  const run = () => { pg = paged(res, off => api('topups', { filter, offset: off }), t => topupCard(t, () => pg.reload()), filter === 'pending' ? 'Kutilayotgan to\'lov yo\'q 🎉' : 'To\'lovlar yo\'q', 'wallet'); S.reload = () => pg.reload(); };
  const draw = () => seg.replaceChildren(...[['pending', 'Kutilayotgan'], ['all', 'Hammasi']].map(([k, l]) => h('button', { class: filter === k ? 'on' : '', onclick: () => { filter = k; S.topupFilter = k; draw(); run(); } }, l)));
  draw(); box.replaceChildren(h('div', { class: 'wrap' }, seg, res)); run();
}

/* ------------------------------------------------------------------ buyurtmalar */
function jobRow(j, compact) {
  const st = L.job[j.status] || [j.status, ''];
  return h('button', { class: 'card row', onclick: () => openJob(j.id) },
    h('div', { class: 'av', style: j.type === 'slayd' ? { color: '#8b5cf6', background: '#8b5cf61f' } : (j.type === 'referat' ? { color: '#0ea5e9', background: '#0ea5e91f' } : {}) }, icon(TYPE_IC[j.type] || 'box')),
    h('div', { class: 'grow' }, h('div', { class: 't1' }, j.topic || '—'), h('div', { class: 't2' }, '#' + j.id + ' · ' + (L.type[j.type] || j.type) + ' · ' + j.volume_key + (compact ? '' : ' · ' + (j.username ? '@' + j.username : 'ID ' + j.telegram_id)))),
    h('div', { class: 'right' }, badge(st[0], st[1]), h('div', { class: 't2' }, j.is_free ? 'Bepul' : money(j.price))));
}
function viewJobs(box) {
  let filter = S.jobFilter || 'all', q = '', timer = null; S.jobFilter = null;
  const seg = h('div', { class: 'seg' }); const res = h('div', {}); let pg = null;
  const inp = h('input', { class: 'inp', type: 'search', placeholder: '№, user ID, mavzu yoki talaba (ism/guruh) bo\'yicha', maxlength: '60', autocomplete: 'off' });
  const run = () => { pg = paged(res, off => api('jobs', { filter, q, offset: off }), j => jobRow(j, false), 'Buyurtma topilmadi', 'box'); S.reload = () => pg.reload(); };
  const draw = () => seg.replaceChildren(...[['all', 'Hammasi'], ['active', 'Jarayonda'], ['failed', 'Xato'], ['done', 'Bajarilgan'], ['cancelled', 'Bekor']].map(([k, l]) => h('button', { class: filter === k ? 'on' : '', onclick: () => { filter = k; draw(); run(); } }, l)));
  inp.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { q = inp.value.trim(); run(); }, 350); });
  draw(); box.replaceChildren(h('div', { class: 'wrap' }, h('div', { class: 'search' }, icon('search'), inp), seg, res)); run();
}
async function openJob(id) {
  const s = openSheet('Buyurtma #' + id, loader());
  const r = await api('job', { id });
  if (!r.ok) { s.bd.replaceChildren(h('div', { class: 'empty' }, r.msg || 'Topilmadi')); return; }
  const j = r.job, st = L.job[j.status] || [j.status, ''], kv = (a, b) => h('div', { class: 'kv' }, h('span', {}, a), h('span', {}, b));
  const active = j.status === 'pending' || j.status === 'processing';
  s.bd.replaceChildren(
    h('div', { class: 'card', style: { padding: '12px 14px', marginBottom: '10px' } }, h('div', { class: 't1', style: { whiteSpace: 'normal' } }, j.topic), h('div', { style: { marginTop: '6px' } }, badge(st[0], st[1]))),
    h('div', { class: 'card', style: { padding: '4px 14px' } },
      kv('Turi', (L.type[j.type] || j.type) + ' · ' + j.volume_key), kv('Narx', j.is_free ? 'Bepul' : money(j.price)), kv('Dizayn', j.design_key || '—'),
      kv('Foydalanuvchi', (j.full_name || j.username || '') + ' (ID ' + j.telegram_id + ')'), kv('Yaratilgan', dt(j.created_at)), kv('Yangilangan', dt(j.updated_at)),
      kv('Baho', j.rating ? j.rating + ' ★' : '—'), kv('Qayta urinishlar', String(j.retries || 0))),
    j.student ? h('div', { class: 'sec' }, 'Talaba ma\'lumotlari') : '',
    j.student ? h('div', { class: 'card', style: { padding: '4px 14px' } },
      kv('Ism', j.student.first_name || '—'), kv('Familya', j.student.last_name || '—'), kv('Guruh', j.student.group_name || '—'), kv('Universitet', j.student.university || '—')) : '',
    j.error_message ? h('div', { class: 'card', style: { padding: '12px 14px', marginTop: '10px', borderColor: 'var(--bad)' } }, h('div', { class: 't2' }, 'Xato xabari'), h('div', { style: { whiteSpace: 'pre-wrap', wordBreak: 'break-word', marginTop: '4px' } }, j.error_message)) : '',
    h('div', { class: 'btns', style: { marginTop: '14px' } },
      h('button', { class: 'btn', onclick: () => { s.close(); openUser(j.telegram_id); } }, '👤 Foydalanuvchi'),
      active ? h('button', { class: 'btn bad', onclick: async () => {
        const ok = await confirmBox('Buyurtmani bekor qilish', '#' + j.id + ' bekor qilinadi, to\'lov foydalanuvchiga qaytariladi.', 'Bekor qilish', 'bad'); if (!ok) return;
        const rr = await act('job_cancel', { id: j.id }); toast(rr.ok ? (rr.msg || 'Bajarildi') : (rr.msg || 'Xatolik'), rr.ok ? 'ok' : 'bad'); if (rr.ok) { s.close(); if (S.reload) S.reload(); }
      } }, '🛑 Bekor qilish') : ''));
}

/* ------------------------------------------------------------------ promokodlar */
function promoVal(p) { return p.kind === 'balance' ? money(p.value) : '+' + p.value + '% bonus'; }
function promoState(p) { if (!p.active) return ['O\'chiq', 'bad']; if (p.expired) return ['Muddati tugagan', 'warn']; if (p.full) return ['Limit tugagan', 'warn']; return ['Faol', 'ok']; }
function promoCard(p, reload) {
  const st = promoState(p);
  return h('button', { class: 'card row', onclick: () => openPromo(p, reload) },
    h('div', { class: 'av' }, '🎟'),
    h('div', { class: 'grow' }, h('div', { class: 't1' }, p.code), h('div', { class: 't2' }, promoVal(p) + ' · ' + p.used + '/' + (p.max_uses || '∞') + (p.expires_at ? ' · ' + dt(p.expires_at) + 'gacha' : ''))),
    h('div', { class: 'right' }, badge(st[0], st[1])));
}
function viewPromos(box) {
  const head = h('div', {}); const res = h('div', {}); let pg = null;
  const run = () => { pg = paged(res, async off => { const r = await api('promos', { offset: off }); if (r.ok && off === 0) head.replaceChildren(h('div', { class: 'card', style: { padding: '4px 14px', marginBottom: '10px' } }, h('div', { class: 'kv' }, h('span', {}, 'Jami ishlatilgan'), h('span', {}, fmt(r.uses_total))), h('div', { class: 'kv' }, h('span', {}, 'Berilgan bonus jami'), h('span', {}, money(r.given_total))))); return r; }, p => promoCard(p, () => pg.reload()), 'Hali promokod yo\'q — «➕ Yangi promokod» tugmasini bosing'); S.reload = () => pg.reload(); };
  box.replaceChildren(h('div', { class: 'wrap' }, h('button', { class: 'btn pri', style: { width: '100%', marginBottom: '10px' }, onclick: () => promoCreateSheet(() => pg.reload()) }, '➕ Yangi promokod'), head, res)); run();
}
function promoCreateSheet(done) {
  let kind = 'balance';
  const code = h('input', { class: 'inp', type: 'text', placeholder: 'Bo\'sh qolsa — avtomatik', maxlength: '32', autocomplete: 'off', autocapitalize: 'characters' });
  const value = h('input', { class: 'inp', type: 'text', inputmode: 'numeric', maxlength: '8', autocomplete: 'off', placeholder: 'So\'m' });
  const max = h('input', { class: 'inp', type: 'text', inputmode: 'numeric', maxlength: '7', autocomplete: 'off', placeholder: '0 = cheksiz' });
  const days = h('input', { class: 'inp', type: 'text', inputmode: 'numeric', maxlength: '4', autocomplete: 'off', placeholder: '0 = muddatsiz' });
  const note = h('input', { class: 'inp', type: 'text', maxlength: '120', autocomplete: 'off', placeholder: 'Izoh (ixtiyoriy, faqat sizga ko\'rinadi)' });
  const b1 = h('button', { class: 'on' }, '💰 Balansga pul'), b2 = h('button', {}, '➕ To\'ldirishga %');
  const lab = h('label', {}, 'Summa (so\'m)');
  const setKind = k => { kind = k; b1.className = k === 'balance' ? 'on' : ''; b2.className = k === 'balance' ? '' : 'on'; lab.textContent = k === 'balance' ? 'Summa (so\'m)' : 'Bonus foizi (1–100)'; value.placeholder = k === 'balance' ? 'So\'m' : 'Foiz'; };
  b1.addEventListener('click', () => setKind('balance')); b2.addEventListener('click', () => setKind('topup_pct'));
  const sub = h('button', { class: 'btn pri', style: { width: '100%' } }, 'Yaratish');
  const s = openSheet('Yangi promokod', h('div', {}, h('div', { class: 'seg' }, b1, b2),
    h('div', { class: 'field' }, h('label', {}, 'Kod'), code), h('div', { class: 'field' }, lab, value),
    h('div', { class: 'field' }, h('label', {}, 'Necha kishi ishlata oladi'), max), h('div', { class: 'field' }, h('label', {}, 'Necha kun amal qiladi'), days),
    h('div', { class: 'field' }, h('label', {}, 'Izoh'), note), sub));
  const num = el => parseInt(String(el.value).replace(/\D/g, ''), 10) || 0;
  sub.addEventListener('click', async () => {
    const v = num(value);
    if (v < 1 || (kind === 'topup_pct' && v > 100)) { toast(kind === 'balance' ? 'Summani kiriting' : 'Foiz 1–100 bo\'lsin', 'bad'); return; }
    if (code.value.trim() && !/^[A-Za-z0-9_\-]{3,32}$/.test(code.value.trim())) { toast('Kod: 3–32 belgi, harf/raqam/-/_', 'bad'); return; }
    const info = (kind === 'balance' ? money(v) + ' beradi' : '+' + v + '% bonus') + '\nLimit: ' + (num(max) || 'cheksiz') + ' · Muddat: ' + (num(days) ? num(days) + ' kun' : 'cheksiz');
    const ok = await confirmBox('Promokod yaratish', info, 'Yaratish', 'ok'); if (!ok) return;
    sub.disabled = true;
    const r = await act('promo_save', { code: code.value.trim(), kind, value: v, max: num(max), days: num(days), note: note.value.trim() });
    sub.disabled = false;
    if (r.ok) { toast(r.msg || 'Yaratildi', 'ok'); s.close(); done(); } else if (r.error !== 'cancel') toast(r.msg || 'Xatolik', 'bad');
  });
}
function openPromo(p, reload) {
  const st = promoState(p), kv = (a, b) => h('div', { class: 'kv' }, h('span', {}, a), h('span', {}, b));
  const uses = h('div', {});
  const s = openSheet('🎟 ' + p.code, h('div', {},
    h('div', { class: 'card', style: { padding: '4px 14px' } }, kv('Holat', st[0]), kv('Turi', p.kind === 'balance' ? 'Balansga pul' : 'To\'ldirishga bonus'), kv('Qiymat', promoVal(p)),
      kv('Ishlatilgan', p.used + ' / ' + (p.max_uses || '∞')), kv('Muddat', p.expires_at ? dt(p.expires_at) + 'gacha' : 'Cheksiz'), kv('Yaratilgan', dt(p.created_at)), p.note ? kv('Izoh', p.note) : ''),
    h('div', { class: 'btns', style: { margin: '14px 0' } },
      h('button', { class: 'btn ' + (p.active ? 'bad' : 'ok'), onclick: async () => {
        const ok = await confirmBox(p.active ? 'O\'chirib qo\'yish' : 'Yoqish', p.active ? p.code + ' kodi endi ishlamaydi (statistika saqlanadi).' : p.code + ' kodi yana ishlaydi.', p.active ? 'O\'chirish' : 'Yoqish', p.active ? 'bad' : 'ok'); if (!ok) return;
        const r = await act('promo_toggle', { code: p.code, active: !p.active }); toast(r.ok ? r.msg : (r.msg || 'Xatolik'), r.ok ? 'ok' : 'bad'); if (r.ok) { s.close(); reload(); }
      } }, p.active ? '⛔ O\'chirish' : '✅ Yoqish'),
      p.used === 0 ? h('button', { class: 'btn ghost', onclick: async () => {
        const ok = await confirmBox('Butunlay o\'chirish', p.code + ' kodi bazadan o\'chiriladi.', 'O\'chirish', 'bad'); if (!ok) return;
        const r = await act('promo_delete', { code: p.code }); toast(r.ok ? r.msg : (r.msg || 'Xatolik'), r.ok ? 'ok' : 'bad'); if (r.ok) { s.close(); reload(); }
      } }, '🗑 Butunlay') : ''),
    h('div', { class: 'sec' }, 'Ishlatganlar'), uses));
  paged(uses, off => api('promo_uses', { code: p.code, offset: off }), u => h('button', { class: 'card row', onclick: () => { s.close(); openUser(u.telegram_id); } },
    h('div', { class: 'av' }, initial(u)), h('div', { class: 'grow' }, h('div', { class: 't1' }, nm(u)), h('div', { class: 't2' }, dt(u.created_at) + ' · ' + (u.kind === 'balance' ? money(u.amount) : (u.status === 'pending' ? 'bonus kutmoqda' : 'bonus ' + money(u.amount))))),
    h('div', { class: 'right' }, badge(u.status === 'pending' ? 'Kutmoqda' : 'Berildi', u.status === 'pending' ? 'warn' : 'ok'))), 'Hali ishlatilmagan');
}

/* ------------------------------------------------------------------ xabarlar */
const EV_ICON = { order: '🆕', order_fail: '❌', promo: '🎟', user: '👤', topup: '💰', receipt: '🧾' };
async function refreshEvents() { const r = await api('events', { offset: 0 }); if (r.ok) { unreadN = r.unread | 0; drawTabs(); } }
function viewEvents(box) {
  const res = h('div', {}); let pg = null;
  const markBtn = h('button', { class: 'btn ghost', style: { width: '100%', marginBottom: '10px' }, onclick: async () => { await api('events_read', {}); unreadN = 0; drawTabs(); pg.reload(); } }, '✓ Hammasini o\'qilgan qilish');
  pg = paged(res, off => api('events', { offset: off }), e => h('button', { class: 'card row', style: e.is_read ? { opacity: '.7' } : {}, onclick: () => { if (e.kind === 'order' || e.kind === 'order_fail') { if (e.ref_id) openJob(e.ref_id); } else if (e.kind === 'receipt') { go('topups'); } else if (e.tg_id) openUser(e.tg_id); } },
    h('div', { class: 'av' }, EV_ICON[e.kind] || '🔔'),
    h('div', { class: 'grow' }, h('div', { class: 't1', style: { whiteSpace: 'normal' } }, e.title, ' ', e.is_read ? '' : badge('YANGI', 'acc')), h('div', { class: 't2', style: { whiteSpace: 'pre-line', marginTop: '3px' } }, e.body)),
    h('div', { class: 'right' }, h('div', { class: 't2' }, ago(e.ts)))), 'Xabarlar yo\'q');
  S.reload = () => pg.reload();
  box.replaceChildren(h('div', { class: 'wrap' }, markBtn, res));
}

/* ------------------------------------------------------------------ rassilka */
const BC_ST = { running: ['Yuborilmoqda', 'acc'], done: ['Tugadi', 'ok'], cancelled: ["To'xtatilgan", 'warn'] };
function bcCard(b, reload) {
  const st = BC_ST[b.status] || [b.status, ''];
  const pctDone = b.total ? Math.round((b.sent + b.failed + b.blocked) / b.total * 100) : 100;
  const bar = h('div', { class: 'bar', style: { margin: '10px 0 6px' } }, h('i', { style: { width: pctDone + '%' } }));
  return h('div', { class: 'card', style: { padding: '12px 14px' } },
    h('div', { style: { display: 'flex', justifyContent: 'space-between', gap: '8px', alignItems: 'center' } }, h('b', {}, '#' + b.id + ' · ' + b.segment_label), badge(st[0], st[1])),
    h('div', { class: 't2', style: { whiteSpace: 'pre-line', marginTop: '6px', maxHeight: '4.4em', overflow: 'hidden' } }, b.text),
    bar,
    h('div', { class: 't2' }, '✅ ' + fmt(b.sent) + ' · 🚫 ' + fmt(b.blocked) + ' · ⚠️ ' + fmt(b.failed) + ' / ' + fmt(b.total) + ' · ' + dt(b.created_at)),
    b.status === 'running' ? h('button', { class: 'btn sm bad', style: { marginTop: '10px' }, onclick: async () => {
      const ok = await confirmBox("Rassilkani to'xtatish", 'Qolgan ' + fmt(b.total - b.sent - b.failed - b.blocked) + ' kishiga yuborilmaydi.', "To'xtatish", 'bad'); if (!ok) return;
      const r = await act('bc_cancel', { id: b.id }); toast(r.ok ? r.msg : (r.msg || 'Xatolik'), r.ok ? 'ok' : 'bad'); reload();
    } }, "⏹ To'xtatish") : '');
}
function viewBroadcast(box) {
  let seg = 'all', segs = {};
  const text = h('textarea', { class: 'inp', maxlength: '3500', placeholder: "Xabar matni…\n\n**qalin** va __kursiv__ yozish mumkin." });
  const bt = h('input', { class: 'inp', type: 'text', maxlength: '40', placeholder: 'Tugma matni (ixtiyoriy), masalan: Kanalimiz' });
  const bu = h('input', { class: 'inp', type: 'url', maxlength: '300', placeholder: 'https://t.me/...' });
  const cnt = h('span', { class: 'badge acc' }, '…');
  const segBox = h('div', { class: 'seg', style: { flexWrap: 'wrap' } });
  const counter = h('div', { class: 't2', style: { textAlign: 'right', marginTop: '4px' } }, '0 / 3500');
  text.addEventListener('input', () => { counter.textContent = text.value.length + ' / 3500'; });
  const loadCount = async () => { cnt.textContent = '…'; const r = await api('bc_count', { segment: seg }); if (r.ok) { segs = r.segments; drawSeg(); cnt.textContent = fmt(r.count) + ' kishi'; } };
  const drawSeg = () => segBox.replaceChildren(...Object.keys(segs).map(k => h('button', { class: k === seg ? 'on' : '', onclick: () => { seg = k; drawSeg(); loadCount(); } }, segs[k])));
  const payload = () => ({ text: text.value.trim(), btn_text: bt.value.trim(), btn_url: bu.value.trim(), segment: seg });
  const test = h('button', { class: 'btn line', onclick: async () => {
    if (!text.value.trim()) { toast('Matnni yozing', 'bad'); return; }
    test.disabled = true; const r = await api('bc_test', payload()); test.disabled = false; toast(r.msg || (r.ok ? 'Yuborildi' : 'Xatolik'), r.ok ? 'ok' : 'bad');
  } }, icon('send'), 'Menga sinov');
  const go1 = h('button', { class: 'btn pri', onclick: async () => {
    if (!text.value.trim()) { toast('Matnni yozing', 'bad'); return; }
    const ok = await confirmBox('Rassilkani boshlash', (segs[seg] || seg) + ' — ' + cnt.textContent + '\n\nYuborilgan xabarni qaytarib bo\'lmaydi. Avval «Menga sinov» bilan tekshirganingizga ishonch hosil qiling.', 'Yuborish', 'ok'); if (!ok) return;
    go1.disabled = true; const r = await act('bc_create', payload()); go1.disabled = false;
    if (r.ok) { toast(r.msg, 'ok'); text.value = ''; counter.textContent = '0 / 3500'; pg.reload(); } else if (r.error !== 'cancel') toast(r.msg || 'Xatolik', 'bad');
  } }, icon('mega'), 'Yuborish');
  const res = h('div', {}); let pg = null;
  box.replaceChildren(h('div', { class: 'wrap' },
    h('div', { class: 'cols c3' },
      h('div', {}, h('div', { class: 'sec', style: { marginTop: '6px' } }, 'Yangi xabar', cnt),
        h('div', { class: 'card', style: { padding: '12px 14px' } },
          h('div', { class: 'field' }, h('label', {}, 'Kimga'), segBox),
          h('div', { class: 'field' }, h('label', {}, 'Matn'), text, counter),
          h('div', { class: 'grid', style: { gridTemplateColumns: '1fr 1fr' } }, h('div', { class: 'field' }, h('label', {}, 'Tugma'), bt), h('div', { class: 'field' }, h('label', {}, 'Havola'), bu)),
          h('div', { class: 'btns', style: { marginTop: '6px' } }, test, go1))),
      h('div', {}, h('div', { class: 'sec', style: { marginTop: '6px' } }, 'Maslahat'),
        h('div', { class: 'card', style: { padding: '12px 14px' } }, h('div', { class: 't2', style: { whiteSpace: 'normal', lineHeight: '1.55' } },
          'Xabarlar navbat bilan, soniyasiga ~15 tadan yuboriladi (Telegram cheklovi). Panelni yopsangiz ham yuborish davom etadi, tugagach Telegram\'ingizga hisobot keladi. Botni bloklagan mijozlar alohida sanaladi.')))),
    h('div', { class: 'sec' }, 'Oldingi rassilkalar'), res));
  pg = paged(res, off => api('bc_list', { offset: off }), b => bcCard(b, () => pg.reload()), "Hali rassilka yo'q", 'mega');
  S.reload = () => pg.reload();
  loadCount();
  const t = setInterval(() => { if (S.tab !== 'broadcast') { clearInterval(t); return; } if (!document.hidden && res.querySelector('.badge.acc')) pg.reload(); }, 8000);
}

/* ------------------------------------------------------------------ jurnal */
function viewAudit(box) {
  const res = h('div', {});
  box.replaceChildren(h('div', { class: 'wrap' }, h('div', { class: 'sec', style: { marginTop: '6px' } }, 'Xavfsizlik va amallar jurnali'), res));
  paged(res, off => api('audit', { offset: off }), a => {
    const l = L.audit[a.event] || [a.event, ''];
    return h('div', { class: 'card logrow' }, h('div', { style: { display: 'flex', justifyContent: 'space-between', gap: '8px' } }, badge(l[0], l[1]), h('span', { class: 't2' }, dt(a.ts))),
      h('div', { class: 't2', style: { marginTop: '6px' } }, [a.tg_id ? 'Admin ' + a.tg_id : '', a.target, a.ip].filter(Boolean).join(' · ')), a.detail ? h('div', { class: 't2', style: { marginTop: '2px' } }, a.detail) : '');
  }, 'Jurnal bo\'sh');
}

/* ------------------------------------------------------------------ asosiy oyna */
const S = { tab: 'home', reload: null, jobFilter: null, topupFilter: null, days: 14 };
let mainBox, tabsBox, chipEl, pendingN = 0, unreadN = 0;
const TABS = [['home', 'Bosh', 'home', viewHome], ['topups', "To'lov", 'wallet', viewTopups], ['users', 'Mijoz', 'users', viewUsers], ['jobs', 'Buyurtma', 'box', viewJobs], ['promos', 'Promo', 'tag', viewPromos], ['events', 'Xabar', 'bell', viewEvents], ['broadcast', 'Rassilka', 'mega', viewBroadcast, true], ['audit', 'Jurnal', 'list', viewAudit, true]];
function setPending(n) { pendingN = n | 0; drawTabs(); }
async function refreshBadge() { const r = await api('topups', { filter: 'pending', offset: 0 }); if (r.ok) setPending(r.more ? 30 : r.items.length); }
function drawTabs() {
  const dotN = k => k === 'topups' ? pendingN : (k === 'events' ? unreadN : 0);
  const wide = window.matchMedia && matchMedia('(min-width:860px)').matches;
  const LONG = { home: 'Bosh sahifa', topups: "To'lovlar", users: 'Mijozlar', jobs: 'Buyurtmalar', promos: 'Promokodlar', events: 'Xabarlar', broadcast: 'Rassilka', audit: 'Jurnal' };
  tabsBox.replaceChildren(...TABS.filter(t => wide || !t[4]).map(([k, label, ic]) => h('button', { class: S.tab === k ? 'on' : '', 'aria-label': LONG[k], onclick: () => { if (S.tab !== k) haptic('light'); go(k); } }, icon(ic), wide ? LONG[k] : label, dotN(k) > 0 ? h('span', { class: 'dot' }, dotN(k) > 29 ? '30+' : dotN(k)) : '')));
}
function go(tab) {
  S.tab = tab; S.reload = null; drawTabs(); mainBox.scrollTop = 0;
  const v = TABS.find(t => t[0] === tab); v[3](mainBox);
}
function tickChip() {
  if (!SESS) return;
  const left = CFG.idle * 1000 - (Date.now() - lastActive);
  if (left <= 0) { expired(); return; }
  const m = Math.ceil(left / 60000);
  chipEl.textContent = '🔒 ' + m + ' daq'; chipEl.className = 'chip' + (m <= 2 ? ' warn' : '');
}
function start() {
  chipEl = h('span', { class: 'chip' }, '🔒');
  mainBox = h('div', { class: 'main' }); tabsBox = h('div', { class: 'tabs' });
  const rb = h('button', { class: 'ibtn', onclick: () => { haptic(); rb.classList.add('spin-on'); setTimeout(() => rb.classList.remove('spin-on'), 700); go(S.tab); }, 'aria-label': 'Yangilash', title: 'Yangilash' }, icon('refresh'));
  show(h('div', { class: 'top' }, h('div', { class: 'brand' }, h('div', { class: 'logo' }, icon('shield')), h('h1', {}, 'Admin panel', h('small', {}, 'StudyBot · boshqaruv'))), chipEl, rb,
    h('button', { class: 'ibtn', onclick: logout, 'aria-label': 'Chiqish', title: 'Chiqish' }, icon('logout'))), h('div', { class: 'shell' }, tabsBox, mainBox));
  try { matchMedia('(min-width:860px)').addEventListener('change', drawTabs); } catch (e) {}
  lastActive = Date.now(); setInterval(tickChip, 15000); tickChip();
  go('home'); refreshEvents(); setInterval(() => { if (SESS && !document.hidden) refreshEvents(); }, 60000);
}
function wipe() { SESS = null; sheets.slice().forEach(s => s.veil.remove()); sheets.length = 0; syncBack(); }
function expired() {
  if (!SESS) return;
  wipe();
  fatal('Sessiya tugadi', 'Xavfsizlik uchun panel yopildi. Botda /admin buyrug\'ini qayta yuboring.', 'lock');
}
async function logout() {
  const ok = await confirmBox('Chiqish', 'Sessiya yopiladi. Qayta kirish uchun botda /admin yuboring.', 'Chiqish', 'bad'); if (!ok) return;
  await api('logout'); wipe(); fatal('Chiqildi', 'Panel yopildi. Qayta kirish uchun botda /admin yuboring.', 'shield');
  setTimeout(() => { try { TG && TG.close(); } catch (e) {} }, 900);
}

/* ------------------------------------------------------------------ kirish */
function pinScreen(initData, msg) {
  const inp = h('input', { class: 'inp pin', type: 'password', inputmode: 'numeric', autocomplete: 'off', maxlength: '12', placeholder: '••••' });
  const err = h('div', { class: 'err' }, msg || '');
  const go1 = async () => {
    const v = inp.value.trim(); if (!/^[0-9]{4,12}$/.test(v)) { err.textContent = 'PIN 4–12 ta raqam'; return; }
    btn.disabled = true; await login(initData, v);
  };
  const btn = h('button', { class: 'btn pri', onclick: go1 }, 'Kirish');
  inp.addEventListener('keydown', e => { if (e.key === 'Enter') go1(); });
  show(h('div', { class: 'full' }, h('div', { class: 'big' }, icon('lock')), h('h2', {}, 'PIN kod'), h('p', {}, 'Admin panelga kirish uchun PIN kodni kiriting.'), inp, err, btn));
  setTimeout(() => inp.focus(), 200);
}
async function login(initData, pin) {
  if (!pin) show(h('div', { class: 'full' }, loader()));
  const r = await api('auth', { t: TOKEN, initData, pin: pin || undefined });
  if (r.ok) { SESS = r.session; CFG = { idle: r.idle, max_adjust: r.max_adjust, stepup: r.stepup }; start(); return; }
  if (r.error === 'pin_required') return pinScreen(initData, '');
  if (r.error === 'bad_pin') return pinScreen(initData, r.msg || 'PIN noto\'g\'ri');
  if (r.error === 'locked') return fatal('Bloklangan', r.msg || 'Biroz kutib qayta urinib ko\'ring.', 'lock');
  if (r.error === 'network') return fatal('Aloqa yo\'q', r.msg || 'Internetni tekshiring.', 'alert');
  fatal('Havola eskirgan yoki yaroqsiz', 'Botda /admin buyrug\'ini qayta yuboring va yangi tugmani bosing.', 'lock');
}
(function boot() {
  if (TG) { try { TG.ready(); TG.expand(); applyTheme(); } catch (e) {} }
  const initData = (TG && TG.initData) || HASH_INIT;
  if (!initData) { fatal('Telegram ichida oching', 'Bu sahifa faqat botdagi «🛡 Panelni ochish» tugmasi orqali ochiladi.', 'shield'); return; }
  login(initData, null);
  // Ilova fonga o'tganda (boshqa ilovaga o'tilsa) sessiya vaqti baribir hisoblanadi; qaytganda tekshiramiz
  document.addEventListener('visibilitychange', () => { if (!document.hidden) tickChip(); });
})();
})();
