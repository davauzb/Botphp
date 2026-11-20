'use strict';
/* Hujjat (mustaqil ish / referat) matn tahrirlovchisi. Barcha matn textContent/value orqali qo'yiladi (innerHTML yo'q). */
const $=(s,r=document)=>r.querySelector(s);
const TG=()=>window.Telegram&&window.Telegram.WebApp;

const S={sess:null,doc:null,title:'',student:{},sections:[],version:0,caps:{},dirty:false,saving:false,pending:false,timer:null,
  undo:[],expired:false,nid:0,listEl:null,statsEl:null,stateEl:null,undoBtn:null,mode:'edit',labels:{},navEl:null,ovEl:null};

/* ---------- yordamchilar ---------- */
function h(tag,props,...kids){
  const e=document.createElement(tag);
  if(props) for(const k in props){ const v=props[k];
    if(k==='class') e.className=v; else if(k.startsWith('on')) e.addEventListener(k.slice(2),v);
    else if(v===true) e.setAttribute(k,''); else if(v!==false&&v!=null) e.setAttribute(k,v); }
  for(const c of kids.flat(3)){ if(c==null||c===false) continue; e.append(c.nodeType?c:document.createTextNode(String(c))); }
  return e;
}
let toastT=null;
function toast(msg){ const t=$('#toast'); t.textContent=msg; t.classList.add('show'); clearTimeout(toastT); toastT=setTimeout(()=>t.classList.remove('show'),2400); }
const words=s=>((s||'').trim().match(/\S+/g)||[]).length;
const fmt=n=>String(n).replace(/\B(?=(\d{3})+(?!\d))/g,' ');
const clone=o=>JSON.parse(JSON.stringify(o));
function tgClose(){ const t=TG(); if(t&&t.close) t.close(); else window.close(); }
function getInitData(){
  const t=TG(); if(t&&t.initData) return t.initData;
  try{ const q=new URLSearchParams(location.hash.slice(1)); return q.get('tgWebAppData')||''; }catch(e){ return ''; }
}
function applyTheme(){
  const t=TG(); const dark=t&&t.colorScheme==='dark'; document.documentElement.dataset.theme=dark?'dark':'light';
  try{ if(t){ t.ready(); t.expand(); t.setHeaderColor&&t.setHeaderColor(dark?'#1c1d20':'#ffffff'); t.disableVerticalSwipes&&t.disableVerticalSwipes(); } }catch(e){}
}
function setVH(){ const vv=window.visualViewport; document.documentElement.style.setProperty('--vh',(vv?vv.height:window.innerHeight)+'px'); }
function closeConfirm(on){ const t=TG(); try{ if(t){ on?t.enableClosingConfirmation():t.disableClosingConfirmation(); } }catch(e){} }

function modal(o){
  const back=h('div',{class:'modal'}); const box=h('div',{class:'mbox'});
  const close=()=>back.remove();
  box.append(h('div',{class:'mtitle'},o.title||''));
  const body=h('div',{class:'mbody'}); if(typeof o.body==='string') body.textContent=o.body; else if(o.body) body.append(o.body); box.append(body);
  const bar=h('div',{class:'mbtns'});
  (o.buttons||[{t:'Yopish'}]).forEach(b=>{ const el=h('button',{class:'btn '+(b.cls||''),type:'button'},b.t);
    el.addEventListener('click',async()=>{ if(b.fn){ const r=await b.fn(el); if(r===false) return; } if(!b.keep) close(); }); bar.append(el); });
  box.append(bar); back.append(box);
  if(!o.locked) back.addEventListener('pointerdown',e=>{ if(e.target===back) close(); });
  document.body.append(back); return {close,box,body};
}

/* ---------- API ---------- */
async function api(action,body,keepalive){
  const init={method:'POST',headers:{'authorization':'Bearer '+S.sess,'content-type':'application/json'},body:JSON.stringify(body||{}),keepalive:!!keepalive};
  let r; try{ r=await fetch('api.php?a='+action,init); }catch(e){ return {ok:false,error:'network',status:0}; }
  let j=null; try{ j=await r.json(); }catch(e){}
  if(r.status===404||r.status===401){ sessionExpired(); return {ok:false,error:'expired',status:r.status}; }
  if(r.status===429) return {ok:false,error:'rate',status:429};
  return Object.assign({status:r.status},j||{ok:false,error:'server'});
}
function sessionExpired(){
  if(S.expired) return; S.expired=true;
  modal({title:'Sessiya tugadi',body:'Xavfsizlik uchun tahrirlash seansi yopildi. Telegram’dagi fayl ostidan «✏️ Tahrirlash» tugmasini qayta bosing. Saqlangan o‘zgarishlar yo‘qolmaydi.',buttons:[{t:'Yopish',cls:'pri',fn:()=>tgClose()}],locked:true});
}

/* ---------- saqlash ---------- */
function setState(kind){
  const el=S.stateEl; if(!el) return;
  const m={saved:['✓ Saqlandi','ok'],saving:['Saqlanmoqda…','busy'],dirty:['● O‘zgartirildi','busy'],error:['⚠ Saqlanmadi — qayta uriniladi','err']}[kind];
  el.textContent=m[0]; el.className='st '+m[1];
}
function markDirty(){ S.dirty=true; setState('dirty'); closeConfirm(true); clearTimeout(S.timer); S.timer=setTimeout(()=>doSave(),1300); updateStats(); }
function payload(){ return {version:S.version,title:S.title,student:S.student,sections:S.sections.map(s=>({heading:s.heading,text:s.text}))}; }
async function doSave(leaving){
  if(S.expired||!S.dirty) return true;
  if(S.saving){ S.pending=true; return false; }
  clearTimeout(S.timer); S.saving=true; setState('saving');
  const sentRev=S.rev=(S.rev||0);
  const r=await api('save',payload(),!!leaving); S.saving=false;
  if(r.ok){
    S.version=r.version; if(S.rev===sentRev){ S.dirty=false; closeConfirm(false); setState('saved'); } else { setState('dirty'); }
    if(S.pending||S.dirty){ S.pending=false; if(S.dirty) S.timer=setTimeout(()=>doSave(),300); }
    return true;
  }
  if(r.error==='conflict'){
    modal({title:'Boshqa joyda o‘zgartirilgan',body:'Bu hujjat boshqa qurilmada yoki oynada tahrirlangan. Oxirgi saqlangan holatini qayta yuklang (hozirgi o‘zgarishlaringiz yo‘qoladi).',
      buttons:[{t:'Qayta yuklash',cls:'pri',fn:async()=>{ const l=await api('load',{}); if(l.ok){ applyLoad(l); render(); toast('Qayta yuklandi'); } else toast('Yuklab bo‘lmadi'); }}],locked:true});
    return false;
  }
  if(r.error==='empty'){ setState('dirty'); toast('Kamida bitta bo‘lim bo‘lishi kerak'); return false; }
  if(r.error!=='expired'){ setState('error'); S.timer=setTimeout(()=>doSave(),5000); }
  return false;
}
function touch(){ S.rev=(S.rev||0)+1; markDirty(); }

/* ---------- tarix (qaytarish) ---------- */
function snap(){ S.undo.push(clone(S.sections)); if(S.undo.length>30) S.undo.shift(); if(S.undoBtn) S.undoBtn.disabled=false; }
function undo(){
  if(!S.undo.length) return; S.sections=S.undo.pop(); if(S.undoBtn) S.undoBtn.disabled=!S.undo.length;
  renderList(); touch(); toast('Qaytarildi');
}

/* ---------- ko'rinish ---------- */
function totalWords(){ let w=0; for(const s of S.sections) w+=words(s.text); return w; }
function updateStats(){
  const w=totalWords(), pages=Math.max(1,Math.ceil(w/250))+2;
  if(S.statsEl) S.statsEl.textContent='≈ '+pages+' bet · '+fmt(w)+' so‘z · '+S.sections.length+' bo‘lim';
  if(S.ovEl){ const maxW=Math.max(1,...S.sections.map(x=>words(x.text)));
    S.ovEl.textContent=''; [[pages,'bet (≈)'],[fmt(w),'so‘z'],[S.sections.length,'bo‘lim'],[fmt(Math.round(w/Math.max(1,S.sections.length))),'so‘z/bo‘lim'],].forEach(([v,l])=>S.ovEl.append(h('div',null,h('b',null,v),h('span',null,l))));
    S.listEl&&S.listEl.querySelectorAll('.sec').forEach((c,i)=>{ const b=c.querySelector('.bar i'); if(b&&S.sections[i]) b.style.width=Math.round(words(S.sections[i].text)/maxW*100)+'%'; }); }
  clearTimeout(S.navT); S.navT=setTimeout(drawNav,250);
}
function drawNav(){
  const n=S.navEl; if(!n) return; n.textContent='';
  S.sections.forEach((sec,i)=>n.append(h('button',{type:'button',title:sec.heading,onclick:()=>jumpTo(i)},h('b',null,i+1),sec.heading||'Bo‘lim')));
}
function jumpTo(i){
  if(S.mode!=='edit'){ setMode('edit'); }
  requestAnimationFrame(()=>{ const c=S.listEl&&S.listEl.querySelectorAll('.sec')[i]; if(c){ c.scrollIntoView({block:'start'}); const t=c.querySelector('textarea'); if(t) t.focus({preventScroll:true}); } });
}
function fit(t){ t.style.height='auto'; t.style.height=Math.min(t.scrollHeight+2,4000)+'px'; }

function sectionCard(sec,i){
  const cnt=h('span',{class:'cnt'},fmt(words(sec.text))+' so‘z');
  const hd=h('input',{type:'text',class:'hd',maxlength:S.caps.max_heading||200,placeholder:'Bo‘lim sarlavhasi',value:sec.heading});
  hd.addEventListener('input',()=>{ sec.heading=hd.value; touch(); });
  const tx=h('textarea',{maxlength:S.caps.max_text||60000,placeholder:'Matn…',rows:4}); tx.value=sec.text;
  tx.addEventListener('input',()=>{ sec.text=tx.value; cnt.textContent=fmt(words(sec.text))+' so‘z'; fit(tx); touch(); });
  const last=S.sections.length-1;
  const tools=h('div',{class:'tools'},
    h('button',{type:'button',class:'ib sm',title:'Yuqoriga',disabled:i===0,onclick:()=>move(i,-1)},'▲'),
    h('button',{type:'button',class:'ib sm',title:'Pastga',disabled:i===last,onclick:()=>move(i,1)},'▼'),
    h('button',{type:'button',class:'ib sm',title:'Pastiga yangi bo‘lim',onclick:()=>addAfter(i)},'＋'),
    h('button',{type:'button',class:'ib sm',title:'O‘chirish',onclick:()=>remove(i)},'🗑'),
    S.caps.ai?h('button',{type:'button',class:'btn sm',onclick:()=>openAi(i)},'✨ AI'):null,
    cnt);
  const card=h('section',{class:'card sec'},h('div',{class:'chead'},h('span',{class:'num'},i+1),hd),h('div',{class:'bar'},h('i',{style:'width:0%'})),h('div',{class:'tx'},tx),tools);
  requestAnimationFrame(()=>fit(tx));
  return card;
}
function renderList(keepScroll){
  const m=$('#main'); const top=m.scrollTop; const l=S.listEl; l.textContent='';
  if(!S.sections.length) l.append(h('div',{class:'empty'},'Hozircha bo‘lim yo‘q. Pastdagi «＋ Bo‘lim» tugmasini bosing.'));
  S.sections.forEach((s,i)=>l.append(sectionCard(s,i)));
  updateStats(); if(keepScroll!==false) requestAnimationFrame(()=>{ m.scrollTop=top; });
}
function move(i,d){ const j=i+d; if(j<0||j>=S.sections.length) return; snap(); [S.sections[i],S.sections[j]]=[S.sections[j],S.sections[i]]; renderList(); touch(); }
function remove(i){
  if(S.sections.length<=1){ toast('Kamida bitta bo‘lim qolishi kerak'); return; }
  const s=S.sections[i]; const name=(s.heading||'Bo‘lim')+(words(s.text)>20?' ('+words(s.text)+' so‘z)':'');
  modal({title:'Bo‘limni o‘chirish',body:'«'+name+'» o‘chirilsin? Keyin ↶ tugmasi bilan qaytarishingiz mumkin.',buttons:[{t:'Bekor qilish'},{t:'O‘chirish',cls:'pri',fn:()=>{ snap(); S.sections.splice(i,1); renderList(); touch(); }}]});
}
function addAfter(i){
  if(S.sections.length>=(S.caps.max_sections||40)){ toast('Bo‘limlar soni chegaraga yetdi'); return; }
  snap(); S.sections.splice(i+1,0,{id:++S.nid,heading:'Yangi bo‘lim',text:''}); renderList(); touch();
  requestAnimationFrame(()=>{ const c=S.listEl.children[i+1]; if(c){ c.scrollIntoView({block:'center'}); const hd=$('.hd',c); if(hd){ hd.focus(); hd.select(); } } });
}

/* ---------- AI yordamchi ---------- */
const MODES=[{v:'rewrite',l:'Qayta yozish'},{v:'shorter',l:'Qisqartirish'},{v:'longer',l:'Kengaytirish'},{v:'simpler',l:'Soddalashtirish'},{v:'formal',l:'Rasmiy uslub'},{v:'fix',l:'Xatolarni to‘g‘rilash'}];
const AI_ERR={rate:'AI so‘rovlari limiti tugadi — keyinroq urining',busy:'AI hozir band — birozdan keyin urining',bad_ai:'AI javobi yaroqsiz — qayta urining',too_long:'Bo‘lim juda uzun — uni ikkiga bo‘lib, bittadan o‘zgartiring',empty:'Avval bo‘limga matn yozing',off:'AI yoqilmagan',network:'Tarmoq xatosi — qayta urining'};
function openAi(i){
  const sec=S.sections[i]; if(!sec) return;
  if(!sec.text.trim()){ toast(AI_ERR.empty); return; }
  if(sec.text.length>(S.caps.ai_max_input||12000)){ toast(AI_ERR.too_long); return; }
  let mode='rewrite', note='', result=null;
  const body=h('div'); const segBox=h('div',{class:'seg'});
  const ni=h('input',{type:'text',maxlength:200,placeholder:'Ixtiyoriy ko‘rsatma (masalan: «misollar qo‘shing»)'}); ni.addEventListener('input',()=>{ note=ni.value; });
  const out=h('div'); const info=h('div',{class:'hint'},'Natija avval shu yerda ko‘rinadi; «Qo‘llash» bosmaguncha bo‘lim o‘zgarmaydi.');
  const drawSeg=()=>{ segBox.textContent=''; MODES.forEach(m=>segBox.append(h('button',{type:'button',class:m.v===mode?'on':'',onclick:()=>{ mode=m.v; drawSeg(); }},m.l))); };
  drawSeg(); body.append(segBox,h('label',{class:'f'},'Ko‘rsatma',ni),info,out);
  const run=async(btn)=>{
    btn.disabled=true; const old=btn.textContent; btn.textContent='AI yozmoqda…'; out.textContent='';
    const r=await api('dai',{heading:sec.heading,text:sec.text,mode,note});
    btn.disabled=false; btn.textContent=old;
    if(!r.ok){ if(r.error!=='expired') toast(AI_ERR[r.error]||'AI javob bermadi — qayta urining'); return false; }
    result=r.text; out.textContent=''; out.append(h('div',{class:'hint'},'Yangi matn ('+fmt(words(result))+' so‘z):'),h('div',{class:'prev'},result)); applyBtn.disabled=false; return false;
  };
  const m=modal({title:'✨ AI yordamchi — '+(sec.heading||'bo‘lim'),body,buttons:[{t:'Yopish'},{t:'Natijani ko‘rish',keep:true,fn:run},{t:'Qo‘llash',cls:'pri',keep:true,fn:()=>{
    if(result==null) return false; snap(); sec.text=result; renderList(); touch(); toast('Qo‘llandi — ↶ bilan qaytarish mumkin'); m.close(); return false; }}]});
  const applyBtn=m.box.querySelectorAll('.mbtns .btn')[2]; applyBtn.disabled=true;
}

/* ---------- yuborish ---------- */
function openSend(anchor){
  const old=$('.menu'); if(old){ old.remove(); return; }
  const mk=(label,fn)=>h('button',{type:'button',onclick:()=>{ menu.remove(); fn(); }},label);
  const menu=h('div',{class:'menu'},mk('📄 DOCX sifatida yuborish',()=>sendOut('docx')),S.caps.pdf?mk('📕 PDF sifatida yuborish',()=>sendOut('pdf')):null);
  document.body.append(menu); const r=anchor.getBoundingClientRect();
  menu.style.top=(r.bottom+6)+'px'; menu.style.right=Math.max(8,window.innerWidth-r.right)+'px';
  setTimeout(()=>document.addEventListener('pointerdown',function f(e){ if(!menu.contains(e.target)){ menu.remove(); } document.removeEventListener('pointerdown',f,true); },true),0);
}
async function sendOut(format){
  const btn=$('#bSend'); if(btn.disabled) return; btn.disabled=true; const old=btn.textContent; btn.textContent=format==='pdf'?'PDF tayyorlanmoqda…':'Yuborilmoqda…';
  try{
    if(S.dirty){ const ok=await doSave(); if(!ok&&S.dirty){ toast('Avval saqlab bo‘lmadi — qayta urining'); return; } }
    const r=await api('export',{format});
    if(r.ok){ modal({title:'Yuborildi ✅',body:(format==='pdf'?'PDF':'DOCX')+' fayl Telegram chatiga yuborildi. Fayl ostidagi «✏️ Qayta tahrirlash» tugmasi orqali yana tahrirlashingiz mumkin.',buttons:[{t:'Davom ettirish'},{t:'Yopish',cls:'pri',fn:()=>tgClose()}]}); }
    else if(r.error!=='expired') toast({rate:'Juda tez-tez yuborildi — biroz kuting',no_pdf:'Bu serverda PDF yaratish yoqilmagan',build:'Fayl yaratib bo‘lmadi — qayta urining',telegram:'Telegram’ga yuborib bo‘lmadi — qayta urining'}[r.error]||'Yuborib bo‘lmadi — qayta urining');
  } finally { btn.disabled=false; btn.textContent=old; }
}

/* ---------- sahifa ---------- */
function field(label,key,max,ph){
  const i=h('input',{type:'text',maxlength:max,placeholder:ph||'',value:(key==='title'?S.title:S.student[key])||''});
  i.addEventListener('input',()=>{ if(key==='title') S.title=i.value; else S.student[key]=i.value; touch(); if(key==='title'){ const t=$('#ttl'); if(t) t.textContent=i.value||'Hujjat'; } });
  return h('label',{class:'f'},label,i);
}
function render(){
  const top=$('#top'); top.textContent='';
  S.undoBtn=h('button',{type:'button',class:'ib',title:'Oxirgi o‘zgarishni qaytarish',disabled:!S.undo.length,onclick:undo},'↶');
  S.stateEl=h('span',{class:'st ok'},'✓ Saqlandi');
  const send=h('button',{id:'bSend',type:'button',class:'btn pri',onclick:e=>openSend(e.currentTarget)},'Yuborish ▾');
  const modes=h('div',{class:'modes',role:'tablist'},
    h('button',{type:'button',class:S.mode==='edit'?'on':'','data-m':'edit',onclick:()=>setMode('edit')},'✏️ Matn'),
    h('button',{type:'button',class:S.mode==='preview'?'on':'','data-m':'preview',onclick:()=>setMode('preview')},'👁 Ko‘rinish'));
  top.append(S.undoBtn,h('div',{class:'ttl',id:'ttl'},S.title||'Hujjat',h('small',null,(S.labels.type||'Hujjat'))),modes,S.stateEl,send);
  if(!S.navEl){ S.navEl=h('nav',{class:'nav','aria-label':'Bo‘limlar'}); top.after(S.navEl); }
  const m=$('#main'); m.textContent='';
  if(S.mode==='preview'){ m.append(h('div',{class:'inner'},preview())); const bar=$('#bar'); bar.textContent=''; S.statsEl=h('div',{class:'meta'}); bar.append(S.statsEl,h('button',{type:'button',class:'btn',onclick:()=>setMode('edit')},'✏️ Tahrirlash')); updateStats(); return; }
  const inner=h('div',{class:'inner'}); m.append(inner);
  S.ovEl=h('div',{class:'overview'}); inner.append(S.ovEl);
  inner.append(h('details',{class:'card'},h('summary',null,'📝 Titul varag‘i ma’lumotlari'),h('div',{class:'body'},
    field('Mavzu','title',300,'Ish mavzusi'),field('F.I.Sh','full_name',120,'Aliyev Vali Sobirovich'),field('Universitet','university',160,''),field('Guruh','group_name',60,''),
    h('div',{class:'hint'},'«Reja» sahifasi bo‘limlar sarlavhalaridan avtomatik tuziladi.'))));
  S.listEl=h('div'); inner.append(S.listEl);
  const bar=$('#bar'); bar.textContent=''; S.statsEl=h('div',{class:'meta'});
  bar.append(S.statsEl,h('button',{type:'button',class:'btn',onclick:()=>addAfter(S.sections.length-1)},'＋ Bo‘lim'));
  renderList(false);
}
function setMode(mode){
  if(S.mode===mode) return; S.mode=mode; render(); $('#main').scrollTop=0;
  const t=TG(); try{ t&&t.HapticFeedback&&t.HapticFeedback.selectionChanged(); }catch(e){}
}
/* A4 ko'rinish: DOCX andozasiga yaqin (titul, reja, bo'limlar har biri yangi betdan). Taxminiy sahifalash. */
function preview(){
  const L=S.labels||{}, st=S.student||{}, wrap=h('div',{class:'pv'});
  const page=(cls,kids,pn)=>h('div',{class:'page '+(cls||'')},h('div',{class:'in'},kids),pn?h('div',{class:'pn'},pn):null);
  wrap.append(page('title',[
    L.ministry?h('div',{class:'min'},L.ministry.toUpperCase()):null,
    h('div',{class:'uni'},st.university||'________________'),h('div',{class:'rule'}),
    h('div',{class:'type'},(L.type||'MUSTAQIL ISH').toUpperCase()),
    h('div',{class:'tl'},(L.topic||'Mavzu')+':'),h('div',{class:'topic'},'«'+(S.title||'')+'»'),
    h('div',{class:'who'},h('div',null,h('b',null,(L.executor||'Bajaruvchi')+': '),st.full_name||'________'),h('div',null,h('b',null,(L.group||'Guruh')+': '),st.group_name||'____'),h('div',null,h('b',null,(L.checked||'Qabul qildi')+': '),'__________')),
    h('div',{class:'yr'},L.year||'')]));
  const PER=1850; // bir betga sig'adigan taxminiy belgilar soni (14pt, 1.5 interval)
  // Matnni bloklarga: abzats, ro'yxat, band (1.1), jadval (| a | b |), rasm ([RASM n: izoh])
  const blocks=text=>{ const out=[]; let rows=null; const fl=()=>{ if(rows){ out.push({t:'table',rows,len:rows.length*90}); rows=null; } };
    (text||'').split(/\n/).map(x=>x.trim()).forEach(l=>{
      if(!l){ fl(); return; }
      if(l.startsWith('|')){ if(/^\|[\s:\-|]+\|?$/.test(l)) return; (rows=rows||[]).push(l.replace(/^\||\|$/g,'').split('|').map(c=>c.trim())); return; }
      fl();
      let m;
      if((m=l.match(/^\[(?:RASM|РИСУНОК|FIGURE)\s*(\d+)\s*:\s*(.*)\]$/i))) out.push({t:'img',cap:m[2],len:700});
      else if((m=l.match(/^(?:JADVAL|ТАБЛИЦА|TABLE)\s*\d*\s*[:.]\s*(.+)$/i))) out.push({t:'tcap',text:m[1],len:60});
      else if(/^\d+\.\d+\.?\s+\S/.test(l)&&!/[.!?…:;]$/.test(l)) out.push({t:'h4',text:l,len:120});
      else if(/^[•\-–]\s+/.test(l)) out.push({t:'li',text:l.replace(/^[•\-–]\s+/,''),len:l.length+60});
      else out.push({t:'p',text:l,len:l.length+70});
    }); fl(); return out; };
  let figN=0, tabN=0;
  const el=b=>{ if(b.t==='p') return h('p',null,b.text); if(b.t==='li') return h('ul',null,h('li',null,b.text)); if(b.t==='h4') return h('h4',{class:'h4'},b.text);
    if(b.t==='tcap') return h('div',{class:'tcap'},b.text);
    if(b.t==='img'){ figN++; return h('div',{class:'fig'},h('div',{class:'ph'},'🖼'),h('div',{class:'cap'},figN+'-rasm. '+b.cap)); }
    if(b.t==='table'){ tabN++; return h('div',null,h('div',{class:'tno'},tabN+'-jadval'),h('table',{class:'tb'},b.rows.map((r,i)=>h('tr',null,r.map(c=>h(i?'td':'th',null,c)))))); }
    return null; };
  const pages=[]; let pn=3;
  if(L.article){
    let cur=[h('h2',{class:'atitle'},(S.title||'').toUpperCase()),h('div',{class:'aauth'},h('b',null,st.full_name||''),h('div',null,[st.university,st.group_name&&((L.group||'Guruh')+': '+st.group_name)].filter(Boolean).join(', ')))], used=400; pn=1;
    S.sections.forEach(sec=>{
      const hd=(sec.heading||'').trim(), low=hd.toLowerCase();
      if(low==='annotatsiya'||low==='аннотация'||low==='abstract'||/kalit so|ключевые|keywords/.test(low)){ cur.push(h('div',{class:'abs'},h('b',null,hd+(/kalit|ключ|key/.test(low)?': ':'. ')),h('i',null,sec.text))); used+=sec.text.length+80; return; }
      if(hd&&hd!=='—'){ cur.push(h('h3',{class:'ah'},/^\s*\d/.test(hd)?hd:hd.toUpperCase())); used+=120; }
      blocks(sec.text).forEach(b=>{ if(used+b.len>PER*1.15&&cur.length>1){ pages.push([cur,pn++]); cur=[]; used=0; } const e=el(b); if(e) cur.push(e); used+=b.len; });
    });
    pages.push([cur,pn++]);
    const wrapA=h('div',{class:'pv'}); pages.forEach(([kids,n])=>wrapA.append(page('',kids,String(n))));
    wrapA.append(h('div',{class:'pvnote'},'Bu taxminiy ko‘rinish. Yakuniy fayl jurnal andozasida (Times New Roman 14, 1.15 interval) yaratiladi.'));
    return wrapA;
  }
  S.sections.forEach(sec=>{
    const chapter=/^\s*\d+\s*[.)]/.test(sec.heading); const title=chapter?sec.heading:(sec.heading||'').toUpperCase();
    let cur=[h('h3',null,title)], used=180; const start=pn;
    blocks(sec.text).forEach(b=>{ if(used+b.len>PER&&cur.length>1){ pages.push([cur,pn++]); cur=[]; used=0; } const e=el(b); if(e) cur.push(e); used+=b.len; });
    pages.push([cur,pn++]); sec._page=start;
  });
  wrap.append(page('',[h('h3',null,L.plan||'REJA'),h('div',{class:'toc'},S.sections.map(sec=>h('div',null,h('span',{style:/^\s*\d/.test(sec.heading)?'':'font-weight:700'},sec.heading),h('i'),h('span',null,String(sec._page)))))],'2'));
  pages.forEach(([kids,n])=>wrap.append(page('',kids,String(n))));
  wrap.append(h('div',{class:'pvnote'},'Bu taxminiy ko‘rinish. Yakuniy fayl (DOCX/PDF) Word andozasida — Times New Roman 14, 1.5 interval, titul ramkasi va bet raqamlari bilan yaratiladi.'));
  return wrap;
}
function applyLoad(r){
  const d=r.doc; S.doc=d; S.title=d.title||''; S.student=Object.assign({full_name:'',university:'',group_name:''},d.student||{}); S.version=d.version; S.caps=r.caps||{};
  S.sections=(d.sections||[]).map(s=>({id:++S.nid,heading:s.heading||'',text:s.text||''})); S.undo=[]; S.dirty=false; S.rev=0; closeConfirm(false);
  S.labels=r.labels||{};
}
function fail(msg){ const b=$('#boot'); b.classList.add('err'); b.textContent=msg; }
async function authenticate(){
  const initData=getInitData(); if(!initData) return {ok:false,error:'no_tg'};
  try{ const res=await fetch('api.php?a=auth',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({t:TOKEN,initData})}); const r=await res.json().catch(()=>null); if(!res.ok) return {ok:false,error:res.status}; return r||{ok:false}; }
  catch(e){ return {ok:false,error:'network'}; }
}
async function boot(){
  applyTheme(); setVH(); window.addEventListener('resize',setVH); if(window.visualViewport) window.visualViewport.addEventListener('resize',setVH);
  const a=await authenticate();
  if(!a.ok||!a.session){ fail(a.error==='no_tg'?'Bu sahifa faqat Telegram ichida ochiladi.':'Havola eskirgan yoki yaroqsiz. Telegram’da «✏️ Tahrirlash» tugmasini qayta bosing.'); return; }
  S.sess=a.session; try{ history.replaceState(null,'',location.pathname); }catch(e){}
  const r=await api('load',{}); if(!r.ok||!r.doc){ fail('Hujjatni yuklab bo‘lmadi.'); return; }
  applyLoad(r); render(); $('#boot').remove();
  setInterval(()=>{ if(!document.hidden&&!S.expired) api('ping',{}); },240000);
  document.addEventListener('visibilitychange',()=>{ if(document.hidden&&S.dirty) doSave(true); });
  window.addEventListener('pagehide',()=>{ if(S.dirty) doSave(true); });
  document.addEventListener('keydown',e=>{
    const mod=e.ctrlKey||e.metaKey; if(!mod) return; const k=e.key.toLowerCase();
    if(k==='s'){ e.preventDefault(); if(S.dirty) doSave(); else toast('Hammasi saqlangan'); }
    else if(k==='z'&&!e.shiftKey&&!/^(TEXTAREA|INPUT)$/.test((document.activeElement||{}).tagName||'')){ e.preventDefault(); undo(); }
  });
}
boot();
