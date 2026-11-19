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
};
function icon(name) { const s = sv('svg', { viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }); s.append(sv('path', { d: ICONS[name] || '' })); return s; }
const fmt = n => String(Math.round(Number(n) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
const money = n => fmt(n) + " so'm";
const p2 = n => String(n).padStart(2, '0');
function dt(ts) { if (!ts) return '—'; const d = new Date((ts + 18000) * 1000); return p2(d.getUTCDate()) + '.' + p2(d.getUTCMonth() + 1) + ' ' + p2(d.getUTCHours()) + ':' + p2(d.getUTCMinutes()); }
function ago(ts) { if (!ts) return '—'; const s = Math.max(0, Math.floor(Date.now() / 1000) - ts); if (s < 60) return 'hozir'; if (s < 3600) return Math.floor(s / 60) + ' daq oldin'; if (s < 86400) return Math.floor(s / 3600) + ' soat oldin'; return Math.floor(s / 86400) + ' kun oldin'; }
const nm = u => u.full_name || (u.username ? '@' + u.username : 'ID ' + u.telegram_id);
const initial = u => (nm(u).replace(/^@/, '')[0] || '?').toUpperCase();
function newKey() { const a = new Uint8Array(16); (window.crypto || {}).getRandomValues ? crypto.getRandomValues(a) : a.forEach((_, i) => a[i] = Math.random() * 256); return Array.from(a, b => p2(b.toString(16))).join(''); }
const L = {
  job: { pending: ['Navbatda', ''], processing: ['Jarayonda', 'acc'], done: ['Bajarildi', 'ok'], failed: ['Xato', 'bad'], cancelled: ['Bekor', 'warn'], awaiting_payment: ["To'lov kutilmoqda", 'warn'] },
  type: { mustaqil_ish: 'Mustaqil ish', referat: 'Referat', slayd: 'Taqdimot' },
  topup: { pending: ['Kutilmoqda', 'warn'], approved: ['Tasdiqlangan', 'ok'], rejected: ['Rad etilgan', 'bad'], refunded: ['Qaytarilgan', 'warn'] },
  method: { click: 'Click', payme: 'Payme', manual: 'Chek', humo: 'Humo/Uzcard' },
  audit: { auth_ok: ['Kirish', 'ok'], auth_fail: ['Kirish urinishi (rad etildi)', 'bad'], pin_fail: ['PIN xato', 'bad'], balance_adjust: ["Balans o'zgartirildi", 'warn'], topup_approve: ["To'lov tasdiqlandi", 'ok'], topup_reject: ["To'lov rad etildi", 'bad'], job_cancel: ['Buyurtma bekor', 'warn'], ban: ['Bloklandi', 'bad'], unban: ['Blokdan chiqarildi', 'ok'], send_msg: ['Xabar yuborildi', ''], view_receipt: ["Chek ko'rildi", ''], link_issued: ['Havola berildi', ''], logout: ['Chiqish', ''], promo_create: ['Promokod yaratildi', 'ok'], promo_on: ['Promokod yoqildi', 'ok'], promo_off: ["Promokod o'chirildi", 'warn'], promo_delete: ["Promokod o'chirib tashlandi", 'bad'], bad_link: ["Noto'g'ri havola", 'bad'], bad_origin: ['Begona manba', 'bad'], session_ua_mismatch: ['Sessiya brauzeri o\'zgargan', 'bad'] },
};
const badge = (t, c) => h('span', { class: 'badge ' + (c || '') }, t);

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
function toast(msg, tone) { const t = h('div', { class: 'toast ' + (tone || '') }, msg); document.body.append(t); setTimeout(() => t.remove(), 2600); }
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
function paged(container, fetcher, renderItem, emptyText) {
  let offset = 0, busy = false;
  const list = h('div', { class: 'list' }); const foot = h('div', {});
  container.replaceChildren(list, foot);
  async function more() {
    if (busy) return; busy = true;
    foot.replaceChildren(loader());
    const r = await fetcher(offset);
    busy = false;
    if (!r.ok) { foot.replaceChildren(h('div', { class: 'empty' }, r.msg || 'Xatolik')); return; }
    r.items.forEach(it => list.append(renderItem(it)));
    offset += r.items.length;
    if (!list.children.length) foot.replaceChildren(h('div', { class: 'empty' }, emptyText || "Ma'lumot yo'q"));
    else foot.replaceChildren(r.more ? h('button', { class: 'more', onclick: more }, 'Yana yuklash') : '');
  }
  more();
  return { reload() { offset = 0; list.replaceChildren(); more(); } };
}

/* ------------------------------------------------------------------ bosh sahifa */
function chart(title, series, key, isMoney) {
  const W = 320, H = 108, n = series.length, bw = (W - 8) / n, max = Math.max(1, ...series.map(s => s[key]));
  const total = series.reduce((a, s) => a + s[key], 0);
  const sub = h('span', {}, '14 kun: ' + (isMoney ? money(total) : total));
  const svg = sv('svg', { viewBox: '0 0 ' + W + ' ' + (H + 16) });
  series.forEach((s, i) => {
    const bh = Math.max(s[key] > 0 ? 3 : 1, (s[key] / max) * (H - 14)), x = 4 + i * bw;
    const r = sv('rect', { x: x + 1.5, y: H - bh, width: bw - 3, height: bh, rx: 3, class: 'bar' });
    r.addEventListener('click', () => { svg.querySelectorAll('.bar').forEach(b => b.classList.remove('sel')); r.classList.add('sel'); sub.textContent = s.d.slice(8) + '.' + s.d.slice(5, 7) + ': ' + (isMoney ? money(s[key]) : s[key]); });
    svg.append(r);
    if (i % 2 === 0) { const t = sv('text', { x: x + bw / 2, y: H + 12, 'text-anchor': 'middle', class: 'axis' }); t.textContent = s.d.slice(8); svg.append(t); }
  });
  return h('div', { class: 'card chart' }, h('div', { class: 'ct' }, h('b', {}, title), sub), svg);
}
async function viewHome(box) {
  box.replaceChildren(loader());
  const r = await api('stats');
  if (!r.ok) { box.replaceChildren(h('div', { class: 'empty' }, r.msg || 'Xatolik')); return; }
  setPending(r.money.pending_manual);
  const kpi = (l, v, s, on, alert) => h(on ? 'button' : 'div', { class: 'card kpi' + (alert ? ' alert' : ''), onclick: on }, h('div', { class: 'l' }, l), h('div', { class: 'v' }, v), h('div', { class: 's' }, s));
  box.replaceChildren(
    h('div', { class: 'grid' },
      kpi('Foydalanuvchilar', fmt(r.users.total), '+' + r.users.today + ' bugun · +' + r.users.week + ' hafta', () => go('users')),
      kpi('Buyurtmalar bugun', fmt(r.jobs.today), 'Jami ' + fmt(r.jobs.total) + ' · jarayonda ' + r.jobs.active, () => go('jobs')),
      kpi('Kirim bugun', money(r.money.topup_today), '7 kun: ' + money(r.money.topup_week)),
      kpi('Jami kirim', money(r.money.topup_total), 'Sotuv: ' + money(r.money.sales_total)),
      kpi('Kutilayotgan to\'lovlar', fmt(r.money.pending_manual), 'Chek tekshiruvi', () => go('topups'), r.money.pending_manual > 0),
      kpi('Xatolar (24 soat)', fmt(r.jobs.failed_24h), 'Jami xato: ' + r.jobs.failed, () => { S.jobFilter = 'failed'; go('jobs'); }, r.jobs.failed_24h > 0),
      kpi('Balanslar yig\'indisi', money(r.users.balance_sum), 'Mijozlar hamyonida'),
      kpi('O\'rtacha baho', r.jobs.rating == null ? '—' : r.jobs.rating + ' ★', 'Bajarilgan: ' + fmt(r.jobs.done))),
    h('div', { class: 'sec' }, 'Oxirgi 14 kun'),
    chart('Buyurtmalar', r.series, 'orders', false),
    h('div', { style: { height: '10px' } }),
    chart('Kirim (so\'m)', r.series, 'revenue', true),
    h('div', { style: { height: '10px' } }),
    chart('Yangi foydalanuvchilar', r.series, 'users', false),
    h('button', { class: 'btn ghost', style: { width: '100%', marginTop: '14px' }, onclick: () => go('audit') }, '📜 Xavfsizlik jurnali'),
    h('div', { class: 'sec' }, 'Bajarilgan buyurtmalar turi'),
    h('div', { class: 'card' }, Object.keys(L.type).map(k => h('div', { class: 'row' }, h('div', { class: 'grow t1' }, L.type[k]), h('div', { class: 'amt' }, fmt(r.types[k] || 0))))));
}

/* ------------------------------------------------------------------ foydalanuvchilar */
function userRow(u) {
  return h('button', { class: 'card row', onclick: () => openUser(u.telegram_id) },
    h('div', { class: 'av' }, initial(u)),
    h('div', { class: 'grow' }, h('div', { class: 't1' }, nm(u), ' ', u.banned ? badge('BLOK', 'bad') : ''), h('div', { class: 't2' }, (u.username ? '@' + u.username + ' · ' : '') + 'ID ' + u.telegram_id + ' · ' + u.jobs + ' buyurtma')),
    h('div', { class: 'right' }, h('div', { class: 'amt' }, money(u.balance)), h('div', { class: 't2' }, ago(u.last_request_at))));
}
function viewUsers(box) {
  let timer = null, q = '';
  const inp = h('input', { class: 'inp', type: 'search', placeholder: 'ID, @username yoki ism bo\'yicha qidirish', autocomplete: 'off', maxlength: '60' });
  const res = h('div', {});
  let pg = null;
  const run = () => { pg = paged(res, off => api('users', { q, offset: off }), userRow, 'Hech narsa topilmadi'); };
  inp.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { q = inp.value.trim(); run(); }, 350); });
  box.replaceChildren(h('div', { class: 'search' }, inp), res);
  run();
}
async function openUser(id) {
  const s = openSheet('Foydalanuvchi', loader());
  const r = await api('user', { id });
  if (!r.ok) { s.bd.replaceChildren(h('div', { class: 'empty' }, r.msg || 'Topilmadi')); return; }
  const u = r.user, kv = (a, b) => h('div', { class: 'kv' }, h('span', {}, a), h('span', {}, b));
  const refresh = () => { s.close(); openUser(id); if (S.tab === 'users' && S.reload) S.reload(); };
  s.bd.replaceChildren(
    h('div', { class: 'card row', style: { marginBottom: '10px' } }, h('div', { class: 'av' }, initial(u)), h('div', { class: 'grow' }, h('div', { class: 't1' }, nm(u), ' ', u.banned ? badge('BLOK', 'bad') : ''), h('div', { class: 't2' }, (u.username ? '@' + u.username + ' · ' : '') + 'ID ' + u.telegram_id))),
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
      h('button', { class: 'av', onclick: () => openUser(t.telegram_id) }, initial(t)),
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
  const run = () => { pg = paged(res, off => api('topups', { filter, offset: off }), t => topupCard(t, () => pg.reload()), filter === 'pending' ? 'Kutilayotgan to\'lov yo\'q 🎉' : 'To\'lovlar yo\'q'); S.reload = () => pg.reload(); };
  const draw = () => seg.replaceChildren(...[['pending', 'Kutilayotgan'], ['all', 'Hammasi']].map(([k, l]) => h('button', { class: filter === k ? 'on' : '', onclick: () => { filter = k; S.topupFilter = k; draw(); run(); } }, l)));
  draw(); box.replaceChildren(seg, res); run();
}

/* ------------------------------------------------------------------ buyurtmalar */
function jobRow(j, compact) {
  const st = L.job[j.status] || [j.status, ''];
  return h('button', { class: 'card row', onclick: () => openJob(j.id) },
    h('div', { class: 'av' }, '#'),
    h('div', { class: 'grow' }, h('div', { class: 't1' }, j.topic || '—'), h('div', { class: 't2' }, '#' + j.id + ' · ' + (L.type[j.type] || j.type) + ' · ' + j.volume_key + (compact ? '' : ' · ' + (j.username ? '@' + j.username : 'ID ' + j.telegram_id)))),
    h('div', { class: 'right' }, badge(st[0], st[1]), h('div', { class: 't2' }, j.is_free ? 'Bepul' : money(j.price))));
}
function viewJobs(box) {
  let filter = S.jobFilter || 'all', q = '', timer = null; S.jobFilter = null;
  const seg = h('div', { class: 'seg' }); const res = h('div', {}); let pg = null;
  const inp = h('input', { class: 'inp', type: 'search', placeholder: '№, user ID, mavzu yoki talaba (ism/guruh) bo\'yicha', maxlength: '60', autocomplete: 'off' });
  const run = () => { pg = paged(res, off => api('jobs', { filter, q, offset: off }), j => jobRow(j, false), 'Buyurtma topilmadi'); S.reload = () => pg.reload(); };
  const draw = () => seg.replaceChildren(...[['all', 'Hammasi'], ['active', 'Jarayonda'], ['failed', 'Xato'], ['done', 'Bajarilgan'], ['cancelled', 'Bekor']].map(([k, l]) => h('button', { class: filter === k ? 'on' : '', onclick: () => { filter = k; draw(); run(); } }, l)));
  inp.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { q = inp.value.trim(); run(); }, 350); });
  draw(); box.replaceChildren(h('div', { class: 'search' }, inp), seg, res); run();
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
  box.replaceChildren(h('button', { class: 'btn pri', style: { width: '100%', marginBottom: '10px' }, onclick: () => promoCreateSheet(() => pg.reload()) }, '➕ Yangi promokod'), head, res); run();
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
  box.replaceChildren(markBtn, res);
}

/* ------------------------------------------------------------------ jurnal */
function viewAudit(box) {
  box.replaceChildren(h('div', { class: 'sec', style: { marginTop: '6px' } }, 'Xavfsizlik va amallar jurnali'), h('div', {}));
  const res = box.lastChild;
  paged(res, off => api('audit', { offset: off }), a => {
    const l = L.audit[a.event] || [a.event, ''];
    return h('div', { class: 'card logrow' }, h('div', { style: { display: 'flex', justifyContent: 'space-between', gap: '8px' } }, badge(l[0], l[1]), h('span', { class: 't2' }, dt(a.ts))),
      h('div', { class: 't2', style: { marginTop: '6px' } }, [a.tg_id ? 'Admin ' + a.tg_id : '', a.target, a.ip].filter(Boolean).join(' · ')), a.detail ? h('div', { class: 't2', style: { marginTop: '2px' } }, a.detail) : '');
  }, 'Jurnal bo\'sh');
}

/* ------------------------------------------------------------------ asosiy oyna */
const S = { tab: 'home', reload: null, jobFilter: null, topupFilter: null };
let mainBox, tabsBox, chipEl, pendingN = 0, unreadN = 0;
const TABS = [['home', 'Bosh', 'home', viewHome], ['topups', "To'lov", 'wallet', viewTopups], ['users', 'Mijoz', 'users', viewUsers], ['jobs', 'Buyurtma', 'box', viewJobs], ['promos', 'Promo', 'tag', viewPromos], ['events', 'Xabar', 'bell', viewEvents], ['audit', 'Jurnal', 'list', viewAudit, true]];
function setPending(n) { pendingN = n | 0; drawTabs(); }
async function refreshBadge() { const r = await api('topups', { filter: 'pending', offset: 0 }); if (r.ok) setPending(r.more ? 30 : r.items.length); }
function drawTabs() {
  const dotN = k => k === 'topups' ? pendingN : (k === 'events' ? unreadN : 0);
  tabsBox.replaceChildren(...TABS.filter(t => !t[4]).map(([k, label, ic]) => h('button', { class: S.tab === k ? 'on' : '', onclick: () => go(k) }, icon(ic), label, dotN(k) > 0 ? h('span', { class: 'dot' }, dotN(k) > 29 ? '30+' : dotN(k)) : '')));
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
  show(h('div', { class: 'top' }, h('h1', {}, '🛡 Admin'), chipEl,
    h('button', { class: 'ibtn', onclick: () => go(S.tab), 'aria-label': 'Yangilash' }, icon('refresh')),
    h('button', { class: 'ibtn', onclick: logout, 'aria-label': 'Chiqish' }, icon('logout'))), mainBox, tabsBox);
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
